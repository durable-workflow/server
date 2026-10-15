//! Read-only PHP source inventory. This command never grants takeover authority.
use super::{Result, mysql, postgres, refuse, schema};
use axum::http::StatusCode;
use serde::{Deserialize, Serialize, de::DeserializeOwned};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use sqlx::{
    AssertSqlSafe, Connection, MySqlConnection, PgConnection, SqliteConnection,
    mysql::MySqlConnectOptions, postgres::PgConnectOptions, sqlite::SqliteConnectOptions,
};
use std::{collections::BTreeMap, future::Future, time::Duration};
use tokio::io::AsyncReadExt;
use tokio::time::{Instant, timeout, timeout_at};

const BUDGET: Duration = Duration::from_secs(30);

/// Exact published-catalog additions/replacements, applied only in memory.
/// Neither unknown objects nor unmatched old definitions are discarded.
pub(super) fn source_catalog_variant<T>(baseline: &[T], delta: &str) -> Result<Vec<T>>
where
    T: Clone + PartialEq + DeserializeOwned,
{
    #[derive(Deserialize)]
    struct Delta<T> {
        remove: Vec<T>,
        add: Vec<T>,
    }
    let delta: Delta<T> = serde_json::from_str(delta)?;
    let mut result = baseline.to_vec();
    for row in delta.remove {
        let Some(position) = result.iter().position(|existing| existing == &row) else {
            return Err(refuse(
                StatusCode::CONFLICT,
                "upgrade_source_catalog_unqualified",
            ));
        };
        result.remove(position);
    }
    for row in delta.add {
        if result.contains(&row) {
            return Err(refuse(
                StatusCode::CONFLICT,
                "upgrade_source_catalog_unqualified",
            ));
        }
        result.push(row);
    }
    Ok(result)
}

pub(super) fn native_relation(name: &str) -> bool {
    matches!(
        name,
        "_sqlx_migrations"
            | "dw_server_schema"
            | "dw_task_completions"
            | "dw_poll_receipts"
            | "dw_query_cache"
            | "dw_worker_registration_incarnations"
    )
}

pub(super) fn native_object(name: &str) -> bool {
    native_relation(name)
        || matches!(
            name,
            "_sqlx_migrations_pkey"
                | "dw_task_completions_pkey"
                | "dw_poll_receipts_pkey"
                | "dw_query_cache_pkey"
                | "dw_workflow_tasks_poll"
                | "dw_worker_registration_incarnations_pkey"
                | "dw_worker_incarnation_authority"
                | "dw_worker_incarnation_retention"
        )
}

fn expired() -> super::RuntimeError {
    refuse(
        StatusCode::SERVICE_UNAVAILABLE,
        "upgrade_inspection_timeout",
    )
}

async fn close(future: impl Future<Output = std::result::Result<(), sqlx::Error>>) -> Result<()> {
    // Dedicated connections never return to a pool. Dropping a timed-out close
    // disposes of its socket, including the audited MySQL/Rustls hard-close path.
    timeout(Duration::from_secs(1), future)
        .await
        .map_err(|_| expired())??;
    Ok(())
}

fn report(backend: &str, catalog: &impl Serialize, counts: BTreeMap<String, i64>) -> Result<Value> {
    let digest = Sha256::digest(serde_json::to_vec(catalog)?)
        .iter()
        .map(|b| format!("{b:02x}"))
        .collect::<String>();
    Ok(json!({
        "schema":"durable-workflow.rust-upgrade-source-inspection.v1",
        "status":"inspected", "backend":backend, "catalog_visibility":"configured_role",
        "catalog_sha256":digest, "table_rows":counts,
        "source_mutated":false, "takeover_allowed":false, "backup_verified":false,
        "php_quiescence_verified":false, "stored_representation_compatibility_verified":false
    }))
}

// Names come only from a catalog equal to the compiled physical PHP layout.
// All values stay in SQL; the inventory contains counts, never source payloads.
macro_rules! count_tables {
    ($tx:ident, $tables:expr, $quote:literal) => {{
        let mut counts = BTreeMap::<String, i64>::new();
        for table in $tables {
            if table.is_empty()
                || !table
                    .bytes()
                    .all(|b| b.is_ascii_alphanumeric() || b == b'_')
            {
                return Err(refuse(
                    StatusCode::CONFLICT,
                    "upgrade_source_catalog_unqualified",
                ));
            }
            let count: i64 = sqlx::query_scalar(AssertSqlSafe(format!(
                "SELECT COUNT(*) FROM {}{}{}",
                $quote, table, $quote
            )))
            .fetch_one(&mut *$tx)
            .await?;
            counts.insert(table, count);
        }
        counts
    }};
}

pub async fn inspect_sqlite(path: &str) -> Result<Value> {
    let deadline = Instant::now() + BUDGET;
    if path.is_empty() || path.contains(['?', ':']) {
        return Err(refuse(
            StatusCode::BAD_REQUEST,
            "upgrade_source_file_required",
        ));
    }
    // SQLITE_OPEN_READONLY can still create WAL/shared-memory sidecars. Do
    // not open an unqualified WAL source or attempt journal recovery. Unlike
    // immutable=1 this preserves normal SQLite locking/change detection for
    // the supported rollback-journal source, rather than ignoring live WAL.
    timeout_at(deadline, async {
        let mut file = tokio::fs::File::open(path).await?;
        let mut header = [0_u8; 20];
        file.read_exact(&mut header).await?;
        if &header[..16] != b"SQLite format 3\0" || header[18..20] != [1, 1] {
            return Err(refuse(
                StatusCode::CONFLICT,
                "upgrade_source_sqlite_journal_unqualified",
            ));
        }
        for suffix in ["-wal", "-shm", "-journal"] {
            if tokio::fs::try_exists(format!("{path}{suffix}")).await? {
                return Err(refuse(
                    StatusCode::CONFLICT,
                    "upgrade_source_sqlite_journal_unqualified",
                ));
            }
        }
        Ok::<_, super::RuntimeError>(())
    })
    .await
    .map_err(|_| expired())??;
    let mut connection = timeout_at(
        deadline,
        SqliteConnection::connect_with(
            &SqliteConnectOptions::new()
                .filename(path)
                .read_only(true)
                .create_if_missing(false)
                .foreign_keys(false),
        ),
    )
    .await
    .map_err(|_| expired())??;
    let result = timeout_at(deadline, async {
        let mut tx = connection.begin().await?;
        let catalog = schema::php_source_catalog(&mut tx).await?;
        let tables: Vec<String> = catalog
            .iter()
            .filter(|(kind, _, _, _)| kind == "table")
            .map(|(_, name, _, _)| name.clone())
            .collect();
        let counts = count_tables!(tx, tables, '"');
        tx.rollback().await?;
        report("sqlite", &catalog, counts)
    })
    .await
    .map_err(|_| expired())
    .and_then(|result| result);
    close(connection.close()).await?;
    result
}

pub async fn inspect_mysql(options: MySqlConnectOptions) -> Result<Value> {
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
        sqlx::query("SET sql_quote_show_create=1")
            .execute(&mut connection)
            .await?;
        sqlx::query("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ")
            .execute(&mut connection)
            .await?;
        let mut tx = connection.begin_with("START TRANSACTION READ ONLY").await?;
        let catalog = mysql::php_source_catalog(&mut tx).await?;
        let tables: Vec<String> = catalog.iter().map(|(name, _)| name.clone()).collect();
        let counts = count_tables!(tx, tables, '`');
        // MySQL's metadata is not an MVCC snapshot. Refuse a changed visible
        // catalog rather than joining counts to a different schema.
        if mysql::php_source_catalog(&mut tx).await? != catalog {
            return Err(refuse(
                StatusCode::CONFLICT,
                "upgrade_source_catalog_changed",
            ));
        }
        tx.rollback().await?;
        report(backend, &catalog, counts)
    })
    .await
    .map_err(|_| expired())
    .and_then(|result| result);
    close(connection.close()).await?;
    result
}

pub async fn inspect_postgres(options: PgConnectOptions) -> Result<Value> {
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
        let catalog = postgres::php_source_catalog(&mut tx).await?;
        let tables: Vec<String> = catalog
            .iter()
            .filter(|(kind, _, data)| kind == "relation" && data.0["kind"] == "r")
            .map(|(_, name, _)| name.clone())
            .collect();
        let counts = count_tables!(tx, tables, '"');
        tx.rollback().await?;
        report("pgsql", &catalog, counts)
    })
    .await
    .map_err(|_| expired())
    .and_then(|result| result);
    close(connection.close()).await?;
    result
}

#[cfg(test)]
#[path = "upgrade_tests.rs"]
mod tests;
