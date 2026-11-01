//! Read-only PHP source inventory. This command never grants takeover authority.
pub mod external_reference;
pub mod fingerprint;
pub mod takeover;
mod dispatch_scan;
mod stream_scan;
mod payload_scan;
mod schedule_scan;
mod storage_scan;
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

/// Published PHP layouts after the frozen one, each with the layout its
/// recorded delta applies to. A parent comes before its children. Each backend
/// records one delta per layout, in this order.
///
/// A release can have several layouts: core migrations run in file order, so
/// a database an older release created can order columns differently from a
/// fresh one. Each such variant descends from the matching older variant.
pub(super) const SOURCE_LAYOUTS: [(&str, &str); 6] = [
    ("php-2.8.0", "frozen"),
    ("php-2.9.7", "php-2.8.0"),
    ("php-2.11.1", "php-2.9.7"),
    ("php-2.11.1-from-2.9.7", "php-2.11.1"),
    ("php-2.11.3", "php-2.11.1"),
    ("php-2.11.3-from-2.9.7", "php-2.11.1-from-2.9.7"),
];

/// The number of recorded layouts after the frozen one.
pub(super) const LAYOUTS: usize = SOURCE_LAYOUTS.len();

/// The recorded layouts from the oldest ancestor down to `name`, as indexes
/// into `SOURCE_LAYOUTS`; empty for the frozen layout or an unknown name.
pub(super) fn layout_lineage(name: &str) -> Vec<usize> {
    let mut lineage = Vec::new();
    let mut current = name;
    while let Some(index) = SOURCE_LAYOUTS.iter().position(|(layout, _)| *layout == current) {
        lineage.insert(0, index);
        current = SOURCE_LAYOUTS[index].1;
    }
    lineage
}

/// Whether `layout` (a recorded layout, with or without the MariaDB driver
/// suffix) includes `release`'s schema changes: its lineage passes through
/// that release or one of its variants.
pub(super) fn has_release(layout: &str, release: &str) -> bool {
    layout_lineage(layout.trim_end_matches("+mariadb-driver")).into_iter().any(|index| {
        let name = SOURCE_LAYOUTS[index].0;
        name == release || name.strip_prefix(release).is_some_and(|rest| rest.starts_with("-from-"))
    })
}

/// Names the qualified layout `actual` matches exactly: the frozen layout, or
/// one reached by applying its lineage's recorded deltas. Rows are compared in
/// `order`, so only presentation order, never a definition, is normalized.
pub(super) fn source_layout<T, K: Ord>(
    actual: &[T],
    frozen: Vec<T>,
    deltas: [&str; LAYOUTS],
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
    let mut layouts = vec![sorted(&frozen)];
    if actual == layouts[0] {
        return Ok("frozen");
    }
    for ((name, parent), delta) in SOURCE_LAYOUTS.into_iter().zip(deltas) {
        let base = match SOURCE_LAYOUTS.iter().position(|(layout, _)| *layout == parent) {
            Some(index) => &layouts[index + 1],
            None => &layouts[0],
        };
        let layout = sorted(&source_catalog_variant(base, delta)?);
        if actual == layout {
            return Ok(name);
        }
        layouts.push(layout);
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
            | "dw_worker_session_holders"
            | "dw_stream_reclaims"
            | "dw_service_call_deadlines"
            | "dw_service_admission_counters"
            | "dw_external_payload_deletions"
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
                | "dw_worker_session_holders_pkey"
                | "dw_stream_reclaims_pkey"
                | "dw_stream_reclaims_due"
                | "dw_service_call_deadlines_pkey"
                | "dw_service_call_deadlines_due"
                | "dw_service_admission_counters_pkey"
                | "dw_service_admission_counters_expiry"
                | "dw_external_payload_deletions_pkey"
                | "dw_external_payload_deletions_uri"
                | "dw_external_payload_deletions_due"
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

/// A dedicated MySQL or MariaDB source connection, with its backend name.
/// Lock waits stay short and transactions are repeatable reads. The client
/// deadline drops the socket, but the server would keep a running statement,
/// its metadata locks and its snapshot, so `statement_seconds` bounds each
/// statement server-side; zero leaves that to the client deadline. With `utc`,
/// TIMESTAMP values render in UTC.
async fn mysql_source(
    options: MySqlConnectOptions,
    deadline: Instant,
    utc: bool,
    statement_seconds: u32,
) -> Result<(MySqlConnection, &'static str)> {
    let mut connection = timeout_at(
        deadline,
        MySqlConnection::connect_with(&mysql::source_options(options)),
    )
    .await
    .map_err(|_| expired())??;
    let setup = timeout_at(deadline, async {
        let version: String = sqlx::query_scalar("SELECT VERSION()")
            .fetch_one(&mut connection)
            .await?;
        let (backend, limit) = if version.contains("MariaDB") {
            ("mariadb", format!("max_statement_time={statement_seconds}"))
        } else {
            let milliseconds = u64::from(statement_seconds) * 1000;
            ("mysql", format!("max_execution_time={milliseconds}"))
        };
        let zone = if utc { "time_zone='+00:00'," } else { "" };
        sqlx::query(AssertSqlSafe(format!(
            "SET {zone}sql_quote_show_create=1,lock_wait_timeout=5,innodb_lock_wait_timeout=5,{limit}"
        )))
        .execute(&mut connection)
        .await?;
        sqlx::query("SET TRANSACTION ISOLATION LEVEL REPEATABLE READ")
            .execute(&mut connection)
            .await?;
        Ok(backend)
    })
    .await
    .map_err(|_| expired())
    .and_then(|result| result);
    match setup {
        Ok(backend) => Ok((connection, backend)),
        Err(error) => {
            // The setup failure explains more than a failed close would.
            let _ = close(connection.close()).await;
            Err(error)
        }
    }
}

fn report(
    backend: &str,
    catalog: &impl Serialize,
    layout: &str,
    counts: BTreeMap<String, i64>,
    queue_inspection: Option<Value>,
    payload_inspection: Option<Value>,
    schedule_inspection: Value,
    storage_inspection: Value,
    dispatch_inspection: Value,
    stream_inspection: Value,
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
    if schedule_inspection["unsupported"] != 0 {
        report["status"] = json!("unqualified");
    }
    report["schedule_inspection"] = schedule_inspection;
    if storage_inspection["unsupported"] != 0 {
        report["status"] = json!("unqualified");
    }
    report["storage_inspection"] = storage_inspection;
    if dispatch_inspection["pending"] != 0 || dispatch_inspection["workflow_bound_open"] != 0 {
        report["status"] = json!("unqualified");
    }
    report["service_call_dispatch_inspection"] = dispatch_inspection;
    if stream_inspection["references"] != 0 {
        report["status"] = json!("unqualified");
    }
    report["stream_reference_inspection"] = stream_inspection;
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
        let report = sqlite_inventory(&mut tx, queues, payloads).await;
        tx.rollback().await?;
        report
    })
    .await
    .map_err(|_| expired())
    .and_then(|result| result);
    close(connection.close()).await?;
    result
}

/// The SQLite inspection report, read inside the caller's transaction.
pub(super) async fn sqlite_inventory(
    tx: &mut SqliteConnection,
    queues: Option<&QueueSourceConfig<'_>>,
    payloads: bool,
) -> Result<Value> {
    let (catalog, layout) = schema::php_source_catalog(&mut *tx).await?;
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
    let schedule_inspection = schedule_scan::inspect::<sqlx::Sqlite>(&mut *tx).await?;
    let storage_inspection = storage_scan::inspect::<sqlx::Sqlite>(&mut *tx).await?;
    let dispatch_inspection = dispatch_scan::inspect::<sqlx::Sqlite>(&mut *tx, &counts).await?;
    let stream_inspection = stream_scan::inspect::<sqlx::Sqlite>(&mut *tx).await?;
    report(
        "sqlite",
        &catalog,
        layout,
        counts,
        queue_inspection,
        payload_inspection,
        schedule_inspection,
        storage_inspection,
        dispatch_inspection,
        stream_inspection,
    )
}

/// Opens a qualified rollback-journal source file read-only.
pub(super) async fn open_sqlite_source(path: &str, deadline: Instant) -> Result<SqliteConnection> {
    open_sqlite_file(path, deadline, false).await
}

/// The file format version bytes of a SQLite header: rollback journal or WAL.
const ROLLBACK_JOURNAL: [u8; 2] = [1, 1];
const WRITE_AHEAD_LOG: [u8; 2] = [2, 2];

/// The journal format bytes of a regular SQLite file, read without SQLite.
async fn journal_format(path: &str) -> Result<[u8; 2]> {
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
    if &header[..16] != b"SQLite format 3\0" {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_sqlite_journal_unqualified",
        ));
    }
    Ok([header[18], header[19]])
}

/// Whether a SQLite file has a WAL, shared-memory or rollback journal beside it.
async fn sidecars(path: &str) -> Result<bool> {
    for suffix in ["-wal", "-shm", "-journal"] {
        if tokio::fs::try_exists(format!("{path}{suffix}")).await? {
            return Ok(true);
        }
    }
    Ok(false)
}

/// Prepares a stopped PHP SQLite source for the upgrade commands, which need
/// the rollback journal. PHP Server's default is WAL: switching it makes
/// SQLite checkpoint every committed WAL frame into the file first, so the
/// stored rows are unchanged. A journal left by a crashed writer is rolled
/// back the same way. Refuses while another process has the file open.
pub async fn prepare_sqlite_source(path: &str) -> Result<Value> {
    if path.is_empty() || path.contains(['?', ':']) {
        return Err(refuse(
            StatusCode::BAD_REQUEST,
            "upgrade_source_file_required",
        ));
    }
    let deadline = Instant::now() + BUDGET;
    let previous = match timeout_at(deadline, journal_format(path))
        .await
        .map_err(|_| expired())??
    {
        WRITE_AHEAD_LOG => "wal",
        ROLLBACK_JOURNAL => "delete",
        _ => {
            return Err(refuse(
                StatusCode::CONFLICT,
                "upgrade_source_sqlite_journal_unqualified",
            ));
        }
    };
    let switched = previous == "wal" || sidecars(path).await?;
    if switched {
        let in_use = || refuse(StatusCode::CONFLICT, "upgrade_source_in_use");
        let opened = timeout_at(
            deadline,
            SqliteConnection::connect_with(
                &SqliteConnectOptions::new()
                    .filename(path)
                    .create_if_missing(false)
                    .foreign_keys(false)
                    .busy_timeout(Duration::from_secs(2))
                    .journal_mode(sqlx::sqlite::SqliteJournalMode::Delete),
            ),
        )
        .await
        .map_err(|_| expired())?;
        // Leaving WAL needs the only connection. SQLite answers busy when another
        // still has the file after a short wait, which also covers a writer
        // that is just closing.
        let mut connection = match opened {
            Ok(connection) => connection,
            Err(sqlx::Error::Database(error))
                if error
                    .code()
                    .and_then(|code| code.parse::<i32>().ok())
                    .is_some_and(|code| code & 0xff == 5) =>
            {
                return Err(in_use());
            }
            Err(error) => return Err(error.into()),
        };
        let mode: String = sqlx::query_scalar("PRAGMA journal_mode")
            .fetch_one(&mut connection)
            .await?;
        close(connection.close()).await?;
        if mode != "delete" {
            return Err(in_use());
        }
    }
    if journal_format(path).await? != ROLLBACK_JOURNAL || sidecars(path).await? {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_sqlite_journal_unqualified",
        ));
    }
    Ok(json!({
        "schema":"durable-workflow.rust-upgrade-sqlite-prepare.v1",
        "status":"rollback_journal", "previous_journal_mode":previous,
        "source_mutated":switched, "takeover_allowed":false, "backup_verified":false
    }))
}

/// Opens a qualified rollback-journal source file, writable only for takeover.
/// It keeps the rollback journal: native enables WAL itself after takeover.
pub(super) async fn open_sqlite_file(
    path: &str,
    deadline: Instant,
    writable: bool,
) -> Result<SqliteConnection> {
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
    // A WAL source is first switched with upgrade-prepare-sqlite.
    timeout_at(deadline, async {
        if journal_format(path).await? != ROLLBACK_JOURNAL {
            return Err(refuse(
                StatusCode::CONFLICT,
                "upgrade_source_sqlite_journal_unqualified",
            ));
        }
        // A killed takeover leaves its rollback journal. Takeover opens the
        // file writable, so SQLite rolls that journal back before anything is
        // read, and the fingerprint then proves the result is the backed-up
        // PHP database. Read-only opens never attempt that recovery.
        let sidecars: &[&str] = if writable {
            &["-wal", "-shm"]
        } else {
            &["-wal", "-shm", "-journal"]
        };
        for suffix in sidecars {
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
                .read_only(!writable)
                .journal_mode(sqlx::sqlite::SqliteJournalMode::Delete)
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
    let (connection, backend) = mysql_source(options, deadline, false, 30).await?;
    bounded(connection, deadline, async |connection| {
        let mut tx = connection.begin_with("START TRANSACTION READ ONLY").await?;
        let report = mysql_inventory(&mut tx, backend, queues, payloads, false).await;
        tx.rollback().await?;
        report
    })
    .await
}

/// The PHP source catalog, or with `fenced` the catalog of a source whose
/// takeover marker is all that has been written, with that marker set aside.
pub(super) async fn mysql_source_catalog(
    connection: &mut MySqlConnection,
    fenced: bool,
) -> Result<(Vec<(String, String)>, &'static str)> {
    if fenced {
        mysql::fenced_source_catalog(connection).await
    } else {
        mysql::php_source_catalog(connection).await
    }
}

/// The MySQL or MariaDB inspection report, read inside the caller's
/// transaction.
pub(super) async fn mysql_inventory(
    tx: &mut MySqlConnection,
    backend: &str,
    queues: Option<&QueueSourceConfig<'_>>,
    payloads: bool,
    fenced: bool,
) -> Result<Value> {
    let (catalog, layout) = mysql_source_catalog(&mut *tx, fenced).await?;
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
    let schedule_inspection = schedule_scan::inspect::<sqlx::MySql>(&mut *tx).await?;
    let storage_inspection = storage_scan::inspect::<sqlx::MySql>(&mut *tx).await?;
    let dispatch_inspection = dispatch_scan::inspect::<sqlx::MySql>(&mut *tx, &counts).await?;
    let stream_inspection = stream_scan::inspect::<sqlx::MySql>(&mut *tx).await?;
    // MySQL's metadata is not an MVCC snapshot. Refuse a changed visible
    // catalog rather than joining counts to a different schema.
    if mysql_source_catalog(&mut *tx, fenced).await?.0 != catalog {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_catalog_changed",
        ));
    }
    report(
        backend,
        &catalog,
        layout,
        counts,
        queue_inspection,
        payload_inspection,
        schedule_inspection,
        storage_inspection,
        dispatch_inspection,
        stream_inspection,
    )
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
    let connection = timeout_at(
        deadline,
        PgConnection::connect_with(&postgres::source_options(options)),
    )
    .await
    .map_err(|_| expired())??;
    bounded(connection, deadline, async |connection| {
        let mut tx = connection
            .begin_with("BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY")
            .await?;
        let report = postgres_inventory(&mut tx, queues, payloads).await;
        tx.rollback().await?;
        report
    })
    .await
}

/// The PostgreSQL inspection report, read inside the caller's transaction.
pub(super) async fn postgres_inventory(
    tx: &mut PgConnection,
    queues: Option<&QueueSourceConfig<'_>>,
    payloads: bool,
) -> Result<Value> {
    let (catalog, layout) = postgres::php_source_catalog(&mut *tx).await?;
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
    let schedule_inspection = schedule_scan::inspect::<sqlx::Postgres>(&mut *tx).await?;
    let storage_inspection = storage_scan::inspect::<sqlx::Postgres>(&mut *tx).await?;
    let dispatch_inspection = dispatch_scan::inspect::<sqlx::Postgres>(&mut *tx, &counts).await?;
    let stream_inspection = stream_scan::inspect::<sqlx::Postgres>(&mut *tx).await?;
    report(
        "pgsql",
        &catalog,
        layout,
        counts,
        queue_inspection,
        payload_inspection,
        schedule_inspection,
        storage_inspection,
        dispatch_inspection,
        stream_inspection,
    )
}

/// The frozen migration from its ownership marker on, then the incarnation
/// migration: what a fresh native bootstrap adds after the frozen PHP layout.
pub(super) fn native_relations(frozen: &str, marker: &str, incarnations: &str) -> Result<String> {
    let start = frozen
        .find(marker)
        .ok_or_else(|| refuse(StatusCode::CONFLICT, "native_schema_catalog_mismatch"))?;
    Ok(format!("{}\n{incarnations}", &frozen[start..]))
}

/// Runs work on a dedicated source connection within the deadline, then
/// closes the connection whatever the work returned.
pub(super) async fn bounded<C: Connection>(
    mut connection: C,
    deadline: Instant,
    work: impl AsyncFnOnce(&mut C) -> Result<Value>,
) -> Result<Value> {
    let result = timeout_at(deadline, work(&mut connection))
        .await
        .map_err(|_| expired())
        .and_then(|result| result);
    close(connection.close()).await?;
    result
}

#[cfg(test)]
#[path = "upgrade_tests.rs"]
mod tests;
