//! Logical fingerprint of a stopped PHP source database.
//!
//! The digest covers the qualified source catalog and every row of every table
//! in primary-key order, with each value rendered by the database itself in one
//! fixed text form. A stopped source and its backup restored into a separate
//! database give equal digests only when the restore reproduces every row, and
//! takeover compares it again before writing anything. Only the digest and the
//! per-table row counts leave the database. The fingerprint grants nothing:
//! every compatibility, backup, quiescence and takeover flag stays false.
use super::{
    Result, close, expired, mysql, open_sqlite_source, postgres, readonly_source, refuse, schema,
};
use axum::http::StatusCode;
use futures_util::TryStreamExt;
use serde::Serialize;
use serde_json::{Value, json};
use sha2::{Digest as _, Sha256};
use sqlx::{
    AssertSqlSafe, Connection, MySqlConnection, PgConnection, Row, mysql::MySqlConnectOptions,
    postgres::PgConnectOptions,
};
use std::{collections::BTreeMap, time::Duration};
use tokio::time::{Instant, timeout, timeout_at};

/// A fingerprint reads every row, so it gets far longer than an inspection.
const BUDGET: Duration = Duration::from_secs(3600);

struct Digest {
    hasher: Sha256,
    rows: BTreeMap<String, i64>,
    started: Instant,
}

impl Digest {
    fn new(backend: &str, catalog: &impl Serialize) -> Result<Self> {
        let mut digest = Self {
            hasher: Sha256::new(),
            rows: BTreeMap::new(),
            started: Instant::now(),
        };
        digest.frame(b"durable-workflow.rust-upgrade-source-fingerprint.v1");
        digest.frame(backend.as_bytes());
        digest.frame(&serde_json::to_vec(catalog)?);
        Ok(digest)
    }

    /// Length-prefixed, so no value can be mistaken for a boundary.
    fn frame(&mut self, bytes: &[u8]) {
        self.hasher.update((bytes.len() as u64).to_be_bytes());
        self.hasher.update(bytes);
    }

    fn table(&mut self, name: &str, columns: &[String]) {
        self.frame(b"table");
        self.frame(name.as_bytes());
        self.frame(&(columns.len() as u64).to_be_bytes());
        for column in columns {
            self.frame(column.as_bytes());
        }
    }

    fn report(self, backend: &str) -> Value {
        let hex: String = self
            .hasher
            .finalize()
            .iter()
            .map(|byte| format!("{byte:02x}"))
            .collect();
        json!({
            "schema":"durable-workflow.rust-upgrade-source-fingerprint.v1",
            "status":"fingerprinted", "backend":backend, "fingerprint":format!("sha256:{hex}"),
            "table_rows":self.rows, "elapsed_ms":self.started.elapsed().as_millis() as u64,
            "source_mutated":false, "takeover_allowed":false, "backup_verified":false,
            "php_quiescence_verified":false, "stored_representation_compatibility_verified":false
        })
    }
}

/// Names come from a catalog equal to a qualified PHP layout. Anything else is
/// refused rather than quoted.
fn identifier(name: &str) -> Result<&str> {
    if name.is_empty() || !name.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'_') {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_catalog_unqualified",
        ));
    }
    Ok(name)
}

/// Primary-key order, or every rendered column when a table has no key.
fn order(key: &[String], width: usize, quote: char) -> Result<String> {
    if key.is_empty() {
        return Ok((1..=width).map(|n| n.to_string()).collect::<Vec<_>>().join(","));
    }
    Ok(key
        .iter()
        .map(|column| identifier(column).map(|column| format!("{quote}{column}{quote}")))
        .collect::<Result<Vec<_>>>()?
        .join(","))
}

// Every rendered value is text, framed in column order.
macro_rules! hash_rows {
    ($tx:ident, $digest:ident, $table:expr, $sql:expr, $width:expr) => {{
        let mut count = 0_i64;
        let mut rows = sqlx::query(AssertSqlSafe($sql)).fetch(&mut *$tx);
        while let Some(row) = rows.try_next().await? {
            $digest.frame(b"row");
            for index in 0..$width {
                let value: String = row.try_get(index)?;
                $digest.frame(value.as_bytes());
            }
            count += 1;
        }
        drop(rows);
        $digest.rows.insert($table.to_owned(), count);
    }};
}

/// Fingerprints a SQLite source the operator CLI found on a read-only mount.
pub async fn fingerprint_sqlite_cli(path: &str) -> Result<Value> {
    timeout(BUDGET, async {
        let resolved = readonly_source(path).await?;
        fingerprint_sqlite(&resolved).await
    })
    .await
    .map_err(|_| expired())?
}

pub async fn fingerprint_sqlite(path: &str) -> Result<Value> {
    let deadline = Instant::now() + BUDGET;
    let mut connection = open_sqlite_source(path, deadline).await?;
    let result = timeout_at(deadline, async {
        // One deferred read transaction keeps one snapshot of the file.
        let mut tx = connection.begin().await?;
        let catalog = schema::php_source_catalog(&mut tx).await?;
        let mut digest = Digest::new("sqlite", &catalog)?;
        for (_, table, _, _) in catalog.iter().filter(|(kind, _, _, _)| kind == "table") {
            let table = identifier(table)?;
            let columns: Vec<String> =
                sqlx::query_scalar("SELECT name FROM pragma_table_info(?1) ORDER BY cid")
                    .bind(table)
                    .fetch_all(&mut *tx)
                    .await?;
            let key: Vec<String> =
                sqlx::query_scalar("SELECT name FROM pragma_table_info(?1) WHERE pk>0 ORDER BY pk")
                    .bind(table)
                    .fetch_all(&mut *tx)
                    .await?;
            digest.table(table, &columns);
            // quote() keeps the storage class: integers, text and blobs render
            // exactly. Reals use 17 significant digits, which round-trip.
            let select = columns
                .iter()
                .map(|column| {
                    identifier(column).map(|c| {
                        format!("CASE typeof(\"{c}\") WHEN 'real' THEN printf('%!.17g',\"{c}\") ELSE quote(\"{c}\") END")
                    })
                })
                .collect::<Result<Vec<_>>>()?
                .join(",");
            let sql = format!(
                "SELECT {select} FROM \"{table}\" ORDER BY {}",
                order(&key, columns.len(), '"')?
            );
            hash_rows!(tx, digest, table, sql, columns.len());
        }
        tx.rollback().await?;
        Ok(digest.report("sqlite"))
    })
    .await
    .map_err(|_| expired())
    .and_then(|result| result);
    close(connection.close()).await?;
    result
}

pub async fn fingerprint_postgres(options: PgConnectOptions) -> Result<Value> {
    let deadline = Instant::now() + BUDGET;
    let mut connection = timeout_at(
        deadline,
        PgConnection::connect_with(&postgres::source_options(options)),
    )
    .await
    .map_err(|_| expired())??;
    let result = timeout_at(deadline, async {
        let mut tx = connection
            .begin_with("BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY")
            .await?;
        // Fixed text forms for timestamps, intervals, floats and bytes.
        sqlx::raw_sql("SET LOCAL TimeZone='UTC'; SET LOCAL DateStyle='ISO, YMD'; SET LOCAL IntervalStyle='postgres'; SET LOCAL extra_float_digits=1; SET LOCAL bytea_output='hex'")
            .execute(&mut *tx)
            .await?;
        let catalog = postgres::php_source_catalog(&mut tx).await?;
        let mut digest = Digest::new("pgsql", &catalog)?;
        let tables: Vec<String> = catalog
            .iter()
            .filter(|(kind, _, data)| kind == "relation" && data.0["kind"] == "r")
            .map(|(_, name, _)| name.clone())
            .collect();
        for table in &tables {
            let table = identifier(table)?;
            let columns: Vec<String> = sqlx::query_scalar("SELECT column_name::text FROM information_schema.columns WHERE table_schema='public' AND table_name=$1 ORDER BY ordinal_position")
                .bind(table).fetch_all(&mut *tx).await?;
            let key: Vec<String> = sqlx::query_scalar("SELECT a.attname::text FROM pg_index i CROSS JOIN LATERAL unnest(i.indkey::int2[]) WITH ORDINALITY AS k(attnum, position) JOIN pg_attribute a ON a.attrelid=i.indrelid AND a.attnum=k.attnum WHERE i.indrelid=('public.'||quote_ident($1))::regclass AND i.indisprimary ORDER BY k.position")
                .bind(table).fetch_all(&mut *tx).await?;
            digest.table(table, &columns);
            let select = columns
                .iter()
                .map(|column| identifier(column).map(|c| format!("quote_nullable(\"{c}\")")))
                .collect::<Result<Vec<_>>>()?
                .join(",");
            let sql = format!(
                "SELECT {select} FROM public.\"{table}\" ORDER BY {}",
                order(&key, columns.len(), '"')?
            );
            hash_rows!(tx, digest, table, sql, columns.len());
        }
        tx.rollback().await?;
        Ok(digest.report("pgsql"))
    })
    .await
    .map_err(|_| expired())
    .and_then(|result| result);
    close(connection.close()).await?;
    result
}

pub async fn fingerprint_mysql(options: MySqlConnectOptions) -> Result<Value> {
    let deadline = Instant::now() + BUDGET;
    let mut connection = timeout_at(
        deadline,
        MySqlConnection::connect_with(&mysql::source_options(options)),
    )
    .await
    .map_err(|_| expired())??;
    let result = timeout_at(deadline, async {
        let version: String = sqlx::query_scalar("SELECT VERSION()")
            .fetch_one(&mut connection)
            .await?;
        let backend = if version.contains("MariaDB") {
            "mariadb"
        } else {
            "mysql"
        };
        // TIMESTAMP values render in UTC. Long table reads are expected, but
        // lock waits stay short; the client deadline bounds the whole read.
        sqlx::query(if backend == "mariadb" {
            "SET time_zone='+00:00',sql_quote_show_create=1,lock_wait_timeout=5,innodb_lock_wait_timeout=5,max_statement_time=0"
        } else {
            "SET time_zone='+00:00',sql_quote_show_create=1,lock_wait_timeout=5,innodb_lock_wait_timeout=5,max_execution_time=0"
        })
        .execute(&mut connection)
        .await?;
        sqlx::query("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ")
            .execute(&mut connection)
            .await?;
        let mut tx = connection
            .begin_with("START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY")
            .await?;
        let catalog = mysql::php_source_catalog(&mut tx).await?;
        let mut digest = Digest::new(backend, &catalog)?;
        for (table, _) in &catalog {
            let table = identifier(table)?;
            let columns: Vec<(String, String)> = sqlx::query_as("SELECT CAST(COLUMN_NAME AS CHAR),CAST(DATA_TYPE AS CHAR) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION")
                .bind(table).fetch_all(&mut *tx).await?;
            let key: Vec<String> = sqlx::query_scalar("SELECT CAST(COLUMN_NAME AS CHAR) FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND CONSTRAINT_NAME='PRIMARY' ORDER BY ORDINAL_POSITION")
                .bind(table).fetch_all(&mut *tx).await?;
            let names: Vec<String> = columns.iter().map(|(name, _)| name.clone()).collect();
            digest.table(table, &names);
            // Binary values render as hex literals; everything else as its
            // quoted string form.
            let select = columns
                .iter()
                .map(|(column, kind)| {
                    identifier(column).map(|c| match kind.to_ascii_lowercase().as_str() {
                        "binary" | "varbinary" | "tinyblob" | "blob" | "mediumblob" | "longblob" | "bit" => format!(
                            "CAST(IF(`{c}` IS NULL,'NULL',CONCAT('X''',HEX(`{c}`),'''')) AS CHAR CHARACTER SET utf8mb4)"
                        ),
                        _ => format!("CAST(QUOTE(`{c}`) AS CHAR CHARACTER SET utf8mb4)"),
                    })
                })
                .collect::<Result<Vec<_>>>()?
                .join(",");
            let sql = format!(
                "SELECT {select} FROM `{table}` ORDER BY {}",
                order(&key, columns.len(), '`')?
            );
            hash_rows!(tx, digest, table, sql, columns.len());
        }
        // MySQL's metadata is not an MVCC snapshot. Refuse a catalog that
        // changed while the rows were read.
        if mysql::php_source_catalog(&mut tx).await? != catalog {
            return Err(refuse(
                StatusCode::CONFLICT,
                "upgrade_source_catalog_changed",
            ));
        }
        tx.rollback().await?;
        Ok(digest.report(backend))
    })
    .await
    .map_err(|_| expired())
    .and_then(|result| result);
    close(connection.close()).await?;
    result
}
