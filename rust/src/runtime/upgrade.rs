//! Read-only PHP source inventory. This command never grants takeover authority.
pub mod external_reference;
pub mod fingerprint;
mod payload_scan;
pub mod queue;
pub mod queue_binding;
mod queue_scan;
use super::{Result, mysql, postgres, refuse, schema};
use axum::http::StatusCode;
use queue_binding::QueueSourceConfig;
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

/// Published PHP layouts after the frozen one, oldest first. Each backend
/// records one delta per layout, applied to the layout before it.
pub(super) const SOURCE_LAYOUTS: [&str; 2] = ["php-2.8.0", "php-2.9.7"];

/// Names the qualified layout `actual` matches exactly: the frozen layout, or
/// one reached by applying the recorded deltas in order. Rows are compared in
/// `order`, so only presentation order, never a definition, is normalized.
pub(super) fn source_layout<T, K: Ord>(
    actual: &[T],
    frozen: Vec<T>,
    deltas: [&str; 2],
    order: impl Fn(&T) -> K,
) -> Result<&'static str>
where
    T: Clone + PartialEq + DeserializeOwned,
{
    let sorted = |rows: &[T]| {
        let mut rows = rows.to_vec();
        rows.sort_by_key(&order);
        rows
    };
    let actual = sorted(actual);
    let mut layout = sorted(&frozen);
    if actual == layout {
        return Ok("frozen");
    }
    for (name, delta) in SOURCE_LAYOUTS.into_iter().zip(deltas) {
        layout = sorted(&source_catalog_variant(&layout, delta)?);
        if actual == layout {
            return Ok(name);
        }
    }
    Err(refuse(
        StatusCode::CONFLICT,
        "upgrade_source_catalog_unqualified",
    ))
}

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

fn report(
    backend: &str,
    catalog: &impl Serialize,
    layout: &str,
    counts: BTreeMap<String, i64>,
    queue_inspection: Option<Value>,
    payload_inspection: Option<Value>,
) -> Result<Value> {
    let digest = Sha256::digest(serde_json::to_vec(catalog)?)
        .iter()
        .map(|b| format!("{b:02x}"))
        .collect::<String>();
    let mut report = json!({
        "schema":"durable-workflow.rust-upgrade-source-inspection.v1",
        "status":"inspected", "backend":backend, "catalog_visibility":"configured_role",
        "source_layout":layout,
        "catalog_sha256":digest, "table_rows":counts,
        "source_mutated":false, "takeover_allowed":false, "backup_verified":false,
        "php_quiescence_verified":false, "stored_representation_compatibility_verified":false
    });
    if let Some(queues) = queue_inspection {
        report["queue_inspection"] = queues;
    }
    if let Some(payloads) = payload_inspection {
        if payloads["references_metadata_supported"] != true
            || payloads["inline_values_verified"] != true
        {
            report["status"] = json!("unqualified");
        }
        report["payload_inspection"] = payloads;
    }
    Ok(report)
}

fn queue_config(config: Option<&QueueSourceConfig<'_>>) -> Result<()> {
    if config.is_some_and(|config| !queue_binding::valid_config(config)) {
        return Err(refuse(
            StatusCode::BAD_REQUEST,
            "upgrade_source_queue_config_required",
        ));
    }
    Ok(())
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
    inspect_sqlite_inner(path, None, false).await
}

pub async fn inspect_sqlite_queues(path: &str, config: &QueueSourceConfig<'_>) -> Result<Value> {
    inspect_sqlite_inner(path, Some(config), false).await
}

pub async fn inspect_sqlite_payloads(path: &str) -> Result<Value> {
    inspect_sqlite_inner(path, None, true).await
}

/// The operator CLI requires the documented kernel read-only mount. SQLite's
/// READONLY flag alone does not prohibit creating WAL/shared-memory sidecars
/// if another process replaces a checked path before SQLite opens it.
pub async fn inspect_sqlite_cli(
    path: &str,
    queues: Option<&QueueSourceConfig<'_>>,
    payloads: bool,
) -> Result<Value> {
    timeout(BUDGET, async {
        queue_config(queues)?;
        let resolved = readonly_source(path).await?;
        inspect_sqlite_inner(&resolved, queues, payloads).await
    })
    .await
    .map_err(|_| expired())?
}

/// Resolves a CLI source file and requires that it and its directory are on a
/// kernel read-only mount.
pub(super) async fn readonly_source(path: &str) -> Result<String> {
    if path.is_empty() || path.contains(['?', ':']) {
        return Err(refuse(
            StatusCode::BAD_REQUEST,
            "upgrade_source_file_required",
        ));
    }
    #[cfg(unix)]
    {
        use std::os::fd::AsRawFd;
        // SQLite resolves symlinks before locating its sidecars. Check the
        // resolved parent as well: a file-only read-only bind mount can
        // still have a writable directory beside it.
        let resolved = tokio::fs::canonicalize(path).await?;
        let mut options = tokio::fs::OpenOptions::new();
        options.read(true).custom_flags(libc::O_NONBLOCK);
        let file = options.open(&resolved).await?;
        if !file.metadata().await?.is_file() {
            return Err(refuse(
                StatusCode::BAD_REQUEST,
                "upgrade_source_regular_file_required",
            ));
        }
        let parent = options
            .open(resolved.parent().ok_or_else(|| {
                refuse(StatusCode::BAD_REQUEST, "upgrade_source_file_required")
            })?)
            .await?;
        for descriptor in [&file, &parent] {
            let mut filesystem = std::mem::MaybeUninit::<libc::statvfs>::uninit();
            // fstatvfs uses this live descriptor's mount. Only read the
            // returned structure after success.
            if unsafe { libc::fstatvfs(descriptor.as_raw_fd(), filesystem.as_mut_ptr()) } != 0 {
                return Err(std::io::Error::last_os_error().into());
            }
            let filesystem = unsafe { filesystem.assume_init() };
            if filesystem.f_flag & libc::ST_RDONLY == 0 {
                return Err(refuse(
                    StatusCode::CONFLICT,
                    "upgrade_source_readonly_filesystem_required",
                ));
            }
        }
        return Ok(resolved
            .to_str()
            .ok_or_else(|| refuse(StatusCode::BAD_REQUEST, "upgrade_source_file_required"))?
            .to_owned());
    }
    #[cfg(not(unix))]
    return Err(refuse(
        StatusCode::CONFLICT,
        "upgrade_source_readonly_filesystem_unavailable",
    ));
}

async fn inspect_sqlite_inner(
    path: &str,
    queues: Option<&QueueSourceConfig<'_>>,
    payloads: bool,
) -> Result<Value> {
    queue_config(queues)?;
    let deadline = Instant::now() + BUDGET;
    let mut connection = open_sqlite_source(path, deadline).await?;
    let result = timeout_at(deadline, async {
        let mut tx = connection.begin().await?;
        let (catalog, layout) = schema::php_source_catalog(&mut tx).await?;
        let tables: Vec<String> = catalog
            .iter()
            .filter(|(kind, _, _, _)| kind == "table")
            .map(|(_, name, _, _)| name.clone())
            .collect();
        let counts = count_tables!(tx, tables, '"');
        let queue_inspection = match queues {
            Some(config) => {
                Some(queue_scan::inspect::<sqlx::Sqlite>(&mut *tx, config, counts["jobs"]).await?)
            }
            None => None,
        };
        let payload_inspection = if payloads {
            Some(payload_scan::inspect::<sqlx::Sqlite>(&mut *tx, &counts).await?)
        } else {
            None
        };
        tx.rollback().await?;
        report(
            "sqlite",
            &catalog,
            layout,
            counts,
            queue_inspection,
            payload_inspection,
        )
    })
    .await
    .map_err(|_| expired())
    .and_then(|result| result);
    close(connection.close()).await?;
    result
}

/// Opens a qualified rollback-journal source file read-only.
pub(super) async fn open_sqlite_source(path: &str, deadline: Instant) -> Result<SqliteConnection> {
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
        let regular_file_required = || {
            refuse(
                StatusCode::BAD_REQUEST,
                "upgrade_source_regular_file_required",
            )
        };
        if !tokio::fs::metadata(path).await?.is_file() {
            return Err(regular_file_required());
        }
        let mut options = tokio::fs::OpenOptions::new();
        options.read(true);
        // Tokio filesystem opens use blocking workers. A replaced FIFO must
        // not strand one beyond the observation budget or prevent CLI exit.
        #[cfg(unix)]
        options.custom_flags(libc::O_NONBLOCK);
        let mut file = options.open(path).await?;
        if !file.metadata().await?.is_file() {
            return Err(regular_file_required());
        }
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
    let connection = timeout_at(
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
    Ok(connection)
}

pub async fn inspect_mysql(options: MySqlConnectOptions) -> Result<Value> {
    inspect_mysql_inner(options, None, false).await
}

pub async fn inspect_mysql_queues(
    options: MySqlConnectOptions,
    config: &QueueSourceConfig<'_>,
) -> Result<Value> {
    inspect_mysql_inner(options, Some(config), false).await
}

pub async fn inspect_mysql_payloads(options: MySqlConnectOptions) -> Result<Value> {
    inspect_mysql_inner(options, None, true).await
}

async fn inspect_mysql_inner(
    options: MySqlConnectOptions,
    queues: Option<&QueueSourceConfig<'_>>,
    payloads: bool,
) -> Result<Value> {
    queue_config(queues)?;
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
        // The client deadline drops the socket, but the server would keep a running
        // statement, its metadata locks and its snapshot. Bound them server-side.
        sqlx::query(if backend == "mariadb" {
            "SET sql_quote_show_create=1,lock_wait_timeout=5,innodb_lock_wait_timeout=5,max_statement_time=30"
        } else {
            "SET sql_quote_show_create=1,lock_wait_timeout=5,innodb_lock_wait_timeout=5,max_execution_time=30000"
        })
        .execute(&mut connection)
        .await?;
        sqlx::query("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ")
            .execute(&mut connection)
            .await?;
        let mut tx = connection.begin_with("START TRANSACTION READ ONLY").await?;
        let (catalog, layout) = mysql::php_source_catalog(&mut tx).await?;
        let tables: Vec<String> = catalog.iter().map(|(name, _)| name.clone()).collect();
        let counts = count_tables!(tx, tables, '`');
        let queue_inspection = match queues {
            Some(config) => {
                Some(queue_scan::inspect::<sqlx::MySql>(&mut *tx, config, counts["jobs"]).await?)
            }
            None => None,
        };
        let payload_inspection = if payloads {
            Some(payload_scan::inspect::<sqlx::MySql>(&mut *tx, &counts).await?)
        } else {
            None
        };
        // MySQL's metadata is not an MVCC snapshot. Refuse a changed visible
        // catalog rather than joining counts to a different schema.
        if mysql::php_source_catalog(&mut tx).await?.0 != catalog {
            return Err(refuse(
                StatusCode::CONFLICT,
                "upgrade_source_catalog_changed",
            ));
        }
        tx.rollback().await?;
        report(
            backend,
            &catalog,
            layout,
            counts,
            queue_inspection,
            payload_inspection,
        )
    })
    .await
    .map_err(|_| expired())
    .and_then(|result| result);
    close(connection.close()).await?;
    result
}

pub async fn inspect_postgres(options: PgConnectOptions) -> Result<Value> {
    inspect_postgres_inner(options, None, false).await
}

pub async fn inspect_postgres_queues(
    options: PgConnectOptions,
    config: &QueueSourceConfig<'_>,
) -> Result<Value> {
    inspect_postgres_inner(options, Some(config), false).await
}

pub async fn inspect_postgres_payloads(options: PgConnectOptions) -> Result<Value> {
    inspect_postgres_inner(options, None, true).await
}

async fn inspect_postgres_inner(
    options: PgConnectOptions,
    queues: Option<&QueueSourceConfig<'_>>,
    payloads: bool,
) -> Result<Value> {
    queue_config(queues)?;
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
        let (catalog, layout) = postgres::php_source_catalog(&mut tx).await?;
        let tables: Vec<String> = catalog
            .iter()
            .filter(|(kind, _, data)| kind == "relation" && data.0["kind"] == "r")
            .map(|(_, name, _)| name.clone())
            .collect();
        let counts = count_tables!(tx, tables, '"');
        let queue_inspection = match queues {
            Some(config) => {
                Some(queue_scan::inspect::<sqlx::Postgres>(&mut *tx, config, counts["jobs"]).await?)
            }
            None => None,
        };
        let payload_inspection = if payloads {
            Some(payload_scan::inspect::<sqlx::Postgres>(&mut *tx, &counts).await?)
        } else {
            None
        };
        tx.rollback().await?;
        report(
            "pgsql",
            &catalog,
            layout,
            counts,
            queue_inspection,
            payload_inspection,
        )
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
