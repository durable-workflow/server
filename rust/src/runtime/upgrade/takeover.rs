//! Backup-first, one-way takeover of a stopped PHP database, following
//! docs/rust-server-upgrade-takeover.md.
//!
//! On SQLite and PostgreSQL everything happens in one transaction that no
//! other writer can interleave with: the inspections, the fingerprint check
//! against the operator's verified backup, the layout conversion, the native
//! relations, the migration ledger and native's own startup verification. An
//! interrupted or refused takeover therefore leaves the PHP database exactly
//! as it was, and a committed one is a native database that PHP 2.5.14 and
//! later refuse to open.
use super::{
    Result, bounded, fingerprint, open_sqlite_file, postgres, postgres_inventory,
    queue_binding::QueueSourceConfig, queue_config, refuse, schema, sqlite_inventory,
};
use crate::runtime::backend::Backend;
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{
    AssertSqlSafe, Connection, Encode, Executor, IntoArguments, PgConnection, Postgres, Sqlite,
    SqliteConnection, Transaction, Type,
    migrate::{Migrate, Migration},
    postgres::PgConnectOptions,
};
use std::time::Duration;
use tokio::time::{Instant, timeout_at};

mod mysql_journal;
pub use mysql_journal::take_over_mysql;

/// Takeover reads every row twice (inspection and fingerprint).
const BUDGET: Duration = Duration::from_secs(3600);
/// Native's PostgreSQL bootstrap key, so a native node cannot open the
/// database while it is taken over.
const POSTGRES_BOOTSTRAP_KEY: i64 = 4924501473315676162;

pub async fn take_over_sqlite(
    path: &str,
    backup_fingerprint: &str,
    queues: &QueueSourceConfig<'_>,
) -> Result<Value> {
    preconditions(backup_fingerprint, queues)?;
    let deadline = Instant::now() + BUDGET;
    let connection = open_sqlite_file(path, deadline, true).await?;
    bounded(connection, deadline, async |connection| {
        let mut tx = connection.begin_with("BEGIN EXCLUSIVE").await?;
        let outcome = take_over_sqlite_in(&mut tx, backup_fingerprint, queues).await;
        settle(tx, outcome).await
    })
    .await
}

/// Takes over with the owner's credentials. The connection keeps native's
/// short lock wait, so a PHP process still holding a table refuses the
/// takeover instead of stalling it.
pub async fn take_over_postgres(
    options: PgConnectOptions,
    backup_fingerprint: &str,
    queues: &QueueSourceConfig<'_>,
) -> Result<Value> {
    preconditions(backup_fingerprint, queues)?;
    let deadline = Instant::now() + BUDGET;
    let connection = timeout_at(
        deadline,
        PgConnection::connect_with(&postgres::source_options(options)),
    )
    .await
    .map_err(|_| super::expired())??;
    bounded(connection, deadline, async |connection| {
        // Read committed: once every table is locked, each statement reads
        // the latest committed state and nothing else can change it.
        let mut tx = connection.begin().await?;
        let outcome = take_over_postgres_in(&mut tx, backup_fingerprint, queues).await;
        settle(tx, outcome).await
    })
    .await
}

fn preconditions(backup_fingerprint: &str, queues: &QueueSourceConfig<'_>) -> Result<()> {
    queue_config(Some(queues))?;
    if !backup_fingerprint.starts_with("sha256:") || backup_fingerprint.len() != 71 {
        return Err(refuse(
            StatusCode::BAD_REQUEST,
            "upgrade_backup_fingerprint_required",
        ));
    }
    Ok(())
}

/// Commits a completed takeover, and rolls back anything else.
async fn settle<DB: sqlx::Database>(tx: Transaction<'_, DB>, outcome: Result<Value>) -> Result<Value> {
    match outcome {
        Ok(report) => {
            tx.commit().await?;
            Ok(report)
        }
        Err(error) => {
            tx.rollback().await?;
            Err(error)
        }
    }
}

async fn take_over_sqlite_in(
    tx: &mut SqliteConnection,
    backup_fingerprint: &str,
    queues: &QueueSourceConfig<'_>,
) -> Result<Value> {
    let inventory = sqlite_inventory(&mut *tx, Some(queues), true).await?;
    let layout = qualified(&inventory)?;
    // The same digest the operator compared with the restored backup. Any
    // write after that backup changes it, so nothing below can run.
    let digest = fingerprint::sqlite_digest(&mut *tx).await?;
    matches_backup(&digest, backup_fingerprint)?;
    sqlx::raw_sql(AssertSqlSafe(schema::native_relations_sql()?))
        .execute(&mut *tx)
        .await?;
    let converted = convert::<Sqlite>(&mut *tx, layout).await?;
    record_migration::<Sqlite>(&mut *tx, schema::migration()).await?;
    // Exactly the check native startup makes: marker, ledger and catalog.
    schema::verify(&mut *tx).await?;
    #[cfg(test)]
    interruption_point("before-commit");
    Ok(report("sqlite", layout, &digest, &inventory, converted))
}

async fn take_over_postgres_in(
    tx: &mut PgConnection,
    backup_fingerprint: &str,
    queues: &QueueSourceConfig<'_>,
) -> Result<Value> {
    // Whole-table reads and index builds are expected; the client deadline
    // bounds them, while lock waits stay short.
    sqlx::raw_sql("SET LOCAL statement_timeout=0")
        .execute(&mut *tx)
        .await?;
    sqlx::query("SELECT pg_advisory_xact_lock($1)")
        .bind(POSTGRES_BOOTSTRAP_KEY)
        .execute(&mut *tx)
        .await?;
    // The later DDL needs ACCESS EXCLUSIVE anyway; taking it first means no
    // lock is ever upgraded while another session waits.
    let tables: Vec<String> = sqlx::query_scalar("SELECT c.relname::text FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname='public' AND c.relkind IN ('r','p') ORDER BY c.relname")
        .fetch_all(&mut *tx)
        .await?;
    if tables.is_empty() {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_catalog_unqualified",
        ));
    }
    let tables = tables
        .iter()
        .map(|table| fingerprint::identifier(table).map(|table| format!("public.\"{table}\"")))
        .collect::<Result<Vec<_>>>()?
        .join(",");
    sqlx::raw_sql(AssertSqlSafe(format!(
        "LOCK TABLE {tables} IN ACCESS EXCLUSIVE MODE"
    )))
    .execute(&mut *tx)
    .await
    .map_err(|error| match &error {
        sqlx::Error::Database(database) if database.code().as_deref() == Some("55P03") => {
            refuse(StatusCode::CONFLICT, "upgrade_source_in_use")
        }
        _ => error.into(),
    })?;
    let inventory = postgres_inventory(&mut *tx, Some(queues), true).await?;
    let layout = qualified(&inventory)?;
    let digest = fingerprint::postgres_digest(&mut *tx).await?;
    matches_backup(&digest, backup_fingerprint)?;
    sqlx::raw_sql(AssertSqlSafe(postgres::native_relations_sql()?))
        .execute(&mut *tx)
        .await?;
    let converted = convert::<Postgres>(&mut *tx, layout).await?;
    record_migration::<Postgres>(&mut *tx, postgres::migration()).await?;
    postgres::verify(&mut *tx).await?;
    #[cfg(test)]
    interruption_point("before-commit");
    Ok(report("pgsql", layout, &digest, &inventory, converted))
}

/// Lets the interruption qualification kill a real takeover process at a
/// named boundary: `before-commit` unless `DW_TAKEOVER_TEST_BOUNDARY` names
/// another.
#[cfg(test)]
fn interruption_point(boundary: &str) {
    let wanted = std::env::var("DW_TAKEOVER_TEST_BOUNDARY");
    if let Ok(receipt) = std::env::var("DW_TAKEOVER_TEST_RECEIPT")
        && wanted.as_deref().unwrap_or("before-commit") == boundary
    {
        std::fs::write(format!("{receipt}.partial"), boundary).unwrap();
        std::fs::rename(format!("{receipt}.partial"), &receipt).unwrap();
        loop {
            std::thread::park();
        }
    }
}

fn matches_backup(digest: &Value, backup_fingerprint: &str) -> Result<()> {
    if digest["fingerprint"] != backup_fingerprint {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_backup_fingerprint_mismatch",
        ));
    }
    Ok(())
}

fn report(backend: &str, layout: &str, digest: &Value, inventory: &Value, converted: Value) -> Value {
    json!({
        "schema":"durable-workflow.rust-upgrade-takeover.v1",
        "status":"taken_over", "backend":backend, "source_layout":layout,
        "fingerprint":digest["fingerprint"], "table_rows":inventory["table_rows"],
        "converted":converted, "backup_fingerprint_matched":true,
        // What the inspections cannot yet establish stays explicit.
        "nested_payloads_verified":false, "other_root_payload_columns_verified":false,
        "stored_representation_compatibility_verified":false
    })
}

/// Published PHP features native does not implement yet. A source holding any
/// of their rows is refused, rather than taken over without them. Derived
/// projections and PHP's own queue and poll bookkeeping are left in place.
const UNSUPPORTED_FEATURES: [(&str, &[&str]); 1] = [
    // Workflow-to-workflow messages, which native does not deliver yet.
    ("upgrade_source_workflow_messages_unsupported", &["workflow_messages"]),
];

/// Takeover needs every inspection result it relies on, and refuses what
/// native cannot represent yet: external payloads, service calls and the
/// other unsupported features above. Returns the qualified source layout.
fn qualified(inventory: &Value) -> Result<&str> {
    for (reason, tables) in UNSUPPORTED_FEATURES {
        if tables
            .iter()
            .any(|table| inventory["table_rows"][*table].as_i64().unwrap_or(0) != 0)
        {
            return Err(refuse(StatusCode::CONFLICT, reason));
        }
    }
    // A namespace storage policy native cannot serve would refuse every
    // upload in that namespace after takeover.
    if inventory["storage_inspection"]["unsupported"] != 0 {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_external_payload_storage_unsupported",
        ));
    }
    // A service call PHP 2.11.3 scheduled but has not dispatched would never
    // run under native.
    if inventory["service_call_dispatch_inspection"]["pending"] != 0 {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_service_call_dispatches_pending",
        ));
    }
    // An open call bound to a workflow run would never close under native,
    // which serves no workflow-bound handler (Server #794).
    if inventory["service_call_dispatch_inspection"]["workflow_bound_open"] != 0 {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_service_calls_workflow_bound_open",
        ));
    }
    // A pending deletion of PHP 2.11.8's that native could never finish
    // would leave its object forever.
    if inventory["external_payload_deletion_inspection"]["unsupported"] != 0 {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_external_payload_deletions_unsupported",
        ));
    }
    // A schedule native cannot run would stop firing after takeover.
    if inventory["schedule_inspection"]["unsupported"] != 0 {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_schedules_unsupported",
        ));
    }
    if inventory["queue_inspection"]["status"] != "known_subset_bound" {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_queue_binding_unqualified",
        ));
    }
    let payloads = &inventory["payload_inspection"];
    if payloads["references_metadata_supported"] != true || payloads["inline_values_verified"] != true
    {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_payload_metadata_unqualified",
        ));
    }
    let total = |field: &str| {
        payloads["columns"].as_array().map_or(0, |columns| {
            columns
                .iter()
                .map(|column| column["counts"][field].as_i64().unwrap_or(0))
                .sum::<i64>()
        })
    };
    if total("php_v1_references") != 0
        || total("unqualified_references") != 0
        || total("other_unvalidated") != 0
        || inventory["table_rows"]["runtime_external_payloads"] != 0
        || inventory["stream_reference_inspection"]["references"] != 0
    {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_external_payloads_unsupported",
        ));
    }
    inventory["source_layout"]
        .as_str()
        .ok_or_else(|| refuse(StatusCode::CONFLICT, "upgrade_source_catalog_unqualified"))
}

fn query<DB: Backend>(sql: &'static str) -> sqlx::query::Query<'static, DB, DB::Arguments>
where
    DB::Arguments: IntoArguments<DB>,
{
    sqlx::query(AssertSqlSafe(DB::statement(sql)))
}

/// Records the native migration as applied, for a schema that came from
/// conversion. Native verification then checks this ledger like any other.
async fn record_migration<DB: Backend>(
    tx: &mut DB::Connection,
    migration: &'static Migration,
) -> Result<()>
where
    DB::Connection: Migrate,
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> &'q [u8]: Encode<'q, DB> + Type<DB>,
{
    tx.ensure_migrations_table("_sqlx_migrations").await?;
    query::<DB>("INSERT INTO _sqlx_migrations(version,description,success,checksum,execution_time) VALUES ($1,$2,TRUE,$3,0)")
        .bind(migration.version)
        .bind(migration.description.as_ref())
        .bind(migration.checksum.as_ref())
        .execute(&mut *tx)
        .await?;
    Ok(())
}

/// Whether a recorded PHP release layout has PHP's own registration
/// incarnations. Layouts without a recipe are refused.
fn php_incarnations(layout: &str) -> Result<bool> {
    match layout.trim_end_matches("+mariadb-driver") {
        "frozen" => Ok(false),
        layout if !super::layout_lineage(layout).is_empty() => Ok(true),
        _ => Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_layout_unconverted",
        )),
    }
}

/// Native keeps a service call's deadline in `dw_service_call_deadlines`,
/// not in PHP 2.8.0+'s `workflow_service_calls.call_deadline_at`. Copies each
/// one not copied yet, before that column is dropped; returns how many.
pub(super) const SERVICE_CALL_DEADLINES: &str = "INSERT INTO dw_service_call_deadlines(service_call_id,namespace,call_deadline_at) SELECT c.id,COALESCE(c.namespace,c.target_namespace,''),c.call_deadline_at FROM workflow_service_calls c WHERE c.call_deadline_at IS NOT NULL AND NOT EXISTS (SELECT 1 FROM dw_service_call_deadlines d WHERE d.service_call_id=c.id)";

/// Converts a recorded PHP release layout to native's (the design's recipes).
async fn convert<DB: Backend>(tx: &mut DB::Connection, layout: &str) -> Result<Value>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> Option<String>: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
{
    let php_incarnations = php_incarnations(layout)?;
    let (copied, created) = adopt_incarnations::<DB>(tx, php_incarnations).await?;
    let holders = adopt_session_holders::<DB>(tx, super::has_release(layout, "php-2.11.3")).await?;
    // The frozen layout has no call deadlines; every later one does.
    let deadlines = if php_incarnations {
        DB::affected(query::<DB>(SERVICE_CALL_DEADLINES).execute(&mut *tx).await?)
    } else {
        0
    };
    if php_incarnations {
        sqlx::raw_sql(
            "DROP TABLE workflow_worker_registration_incarnations; \
             ALTER TABLE workflow_worker_registrations DROP COLUMN registration_token; \
             DROP INDEX wf_service_calls_deadline_idx; \
             ALTER TABLE workflow_service_calls DROP COLUMN call_deadline_at;",
        )
        .execute(&mut *tx)
        .await?;
    }
    let delivery_references = if super::has_release(layout, "php-2.9.7") {
        // PHP's own queue delivery lookup metadata; native does not use PHP's
        // queue, and the queue inspection bound every job to its task row.
        let references = count::<DB>(tx, DELIVERY_REFERENCES).await?;
        sqlx::raw_sql("ALTER TABLE workflow_tasks DROP COLUMN queue_delivery_reference")
            .execute(&mut *tx)
            .await?;
        references
    } else {
        0
    };
    let observability_contexts = if super::has_release(layout, "php-2.11.1") {
        // Core 2.10's trace carriers on runs, commands and tasks (decided on
        // #325). Native has no carrier; they are not business state or
        // history, and the verified backup keeps them.
        let mut contexts = 0;
        for (table, sql) in OBSERVABILITY_CONTEXTS {
            contexts += count::<DB>(tx, sql).await?;
            sqlx::raw_sql(sqlx::AssertSqlSafe(format!("ALTER TABLE {table} DROP COLUMN observability_context")))
                .execute(&mut *tx)
                .await?;
        }
        contexts
    } else {
        0
    };
    let reclaims = adopt_stream_reclaims::<DB>(tx, super::has_release(layout, "php-2.11.3")).await?;
    let release_2113 = if super::has_release(layout, "php-2.11.3") {
        let messages = count::<DB>(tx, FULL_FAILURE_MESSAGES).await?;
        let dispatches = count::<DB>(tx, COMPLETED_DISPATCHES).await?;
        sqlx::raw_sql(RELEASE_2113_SQL).execute(&mut *tx).await?;
        json!({"full_failure_messages_removed": messages, "service_call_dispatches_removed": dispatches})
    } else {
        json!({})
    };
    let release_2118 = if super::has_release(layout, "php-2.11.8") {
        let deletions = adopt_payload_deletions::<DB>(tx, PAYLOAD_DELETIONS).await?;
        sqlx::raw_sql("DROP TABLE runtime_external_payload_deletions")
            .execute(&mut *tx)
            .await?;
        json!({"external_payload_deletions_copied": deletions})
    } else {
        json!({})
    };
    let mut converted = json!({
        "incarnations_copied":copied, "incarnations_created":created,
        "session_holders_recorded":holders,
        "service_call_deadlines_copied":deadlines,
        "queue_delivery_references_removed":delivery_references,
        "observability_contexts_removed":observability_contexts,
        "stream_reclaims_recorded":reclaims
    });
    for release in [release_2113, release_2118] {
        if let (Value::Object(converted), Value::Object(release)) = (&mut converted, release) {
            converted.extend(release);
        }
    }
    Ok(converted)
}

/// Native keeps each closed or errored durable stream's reclaim deadline in
/// `dw_stream_reclaims`, which its retention pass reads. PHP 2.11.3 records it
/// as `workflow_durable_streams.reclaim_at` (null once the stream's items are
/// gone). Earlier releases recorded none, so the deadline is `closed_at` plus
/// the stream's retention, an hour unless set, as 2.11.3's own migration
/// backfills it. A repeated call records only streams not recorded yet.
/// Returns how many it recorded.
pub(super) async fn adopt_stream_reclaims<DB: Backend>(tx: &mut DB::Connection, recorded: bool) -> Result<usize>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
{
    let rows = query::<DB>(if recorded {
        "SELECT id,namespace,workflow_run_id,reclaim_at FROM workflow_durable_streams WHERE reclaim_at IS NOT NULL AND id NOT IN (SELECT stream_id FROM dw_stream_reclaims) ORDER BY id"
    } else {
        "SELECT id,namespace,workflow_run_id,closed_at,retention_seconds FROM workflow_durable_streams WHERE status IN ('closed','errored') AND closed_at IS NOT NULL AND id NOT IN (SELECT stream_id FROM dw_stream_reclaims) ORDER BY id"
    })
    .fetch_all(&mut *tx)
    .await?;
    for row in &rows {
        let reclaim_at = if recorded {
            DB::instant(row, "reclaim_at")?
        } else {
            DB::instant(row, "closed_at")?
                + chrono::Duration::seconds(DB::optional_number(row, "retention_seconds")?.unwrap_or(3600))
        };
        query::<DB>("INSERT INTO dw_stream_reclaims(stream_id,namespace,workflow_run_id,reclaim_at) VALUES ($1,$2,$3,$4)")
            .bind(DB::optional_integer(Some(DB::number(row, "id")?)))
            .bind(DB::string(row, "namespace")?)
            .bind(DB::string(row, "workflow_run_id")?)
            .bind(DB::bind_time(reclaim_at))
            .execute(&mut *tx)
            .await?;
    }
    Ok(rows.len())
}

/// PHP 2.11.8's pending deletions not copied yet, oldest first.
pub(super) const PAYLOAD_DELETIONS: &str = "SELECT id,namespace,storage_uri,storage_uri_sha256,storage_policy,attempts,failure_code,created_at,updated_at FROM runtime_external_payload_deletions WHERE id NOT IN (SELECT id FROM dw_external_payload_deletions) ORDER BY id";
/// The same rows on MySQL and MariaDB, where `attempts` is `INT UNSIGNED`.
pub(super) const MYSQL_PAYLOAD_DELETIONS: &str = "SELECT id,namespace,storage_uri,storage_uri_sha256,storage_policy,CAST(attempts AS SIGNED) AS attempts,failure_code,created_at,updated_at FROM runtime_external_payload_deletions WHERE id NOT IN (SELECT id FROM dw_external_payload_deletions) ORDER BY id";

/// Carries PHP 2.11.8's pending external payload deletions over to native's
/// `dw_external_payload_deletions`, which native's cleanup and retention
/// passes retry exactly as PHP's do: the same id, object, saved storage
/// policy, attempt count and recorded failure. Inspection already refused a
/// deletion native could never finish. No object is deleted here. A repeated
/// call copies only rows not copied yet; returns how many it copied.
pub(super) async fn adopt_payload_deletions<DB: Backend>(
    tx: &mut DB::Connection,
    select: &'static str,
) -> Result<usize>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> Option<String>: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
{
    let rows = query::<DB>(select).fetch_all(&mut *tx).await?;
    let now = chrono::Utc::now();
    for row in &rows {
        let updated_at = DB::optional_instant(row, "updated_at")?;
        let created_at = DB::optional_instant(row, "created_at")?.or(updated_at).unwrap_or(now);
        query::<DB>("INSERT INTO dw_external_payload_deletions(id,namespace,storage_uri,storage_uri_sha256,storage_policy,attempts,failure_code,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9)")
            .bind(DB::string(row, "id")?)
            .bind(DB::string(row, "namespace")?)
            .bind(DB::string(row, "storage_uri")?)
            .bind(DB::string(row, "storage_uri_sha256")?)
            .bind(DB::document_row(row, "storage_policy")?.to_string())
            .bind(DB::number(row, "attempts")?)
            .bind(DB::optional_string(row, "failure_code")?)
            .bind(DB::bind_time(created_at))
            .bind(DB::bind_time(updated_at.unwrap_or(created_at)))
            .execute(&mut *tx)
            .await?;
    }
    Ok(rows.len())
}

/// Core 2.14's full failure messages: a message beyond the old TEXT capacity,
/// or with a NUL, keeps a labelled preview in `message` and its full bytes in
/// `message_full`. Native has no second column; it reads the preview, as
/// PHP's own older readers do, and retained history keeps the full failure.
pub(super) const FULL_FAILURE_MESSAGES: &str =
    "SELECT COUNT(*) AS n FROM workflow_failures WHERE message_full IS NOT NULL";

/// Dispatches PHP 2.11.3 completed; takeover refused any still pending.
pub(super) const COMPLETED_DISPATCHES: &str =
    "SELECT COUNT(*) AS n FROM workflow_service_call_dispatches";

/// The rest of PHP 2.11.3's additions, which native does not keep:
/// - the service-call idempotency scope digest and its unique index, derived
///   from the call's own target namespace, names and key;
/// - the completed dispatch outbox;
/// - each session's holder incarnation, copied to native's holders first;
/// - each durable stream's reclaim deadline, which only a stream has, and
///   takeover refuses any stream.
const RELEASE_2113_SQL: &str = "ALTER TABLE workflow_failures DROP COLUMN message_full; \
     DROP INDEX wf_service_calls_idem_scope_unique; \
     ALTER TABLE workflow_service_calls DROP COLUMN idempotency_scope_hash; \
     DROP TABLE workflow_service_call_dispatches; \
     ALTER TABLE workflow_worker_sessions DROP COLUMN lease_owner_incarnation; \
     DROP INDEX wf_durable_streams_ns_reclaim_idx; \
     ALTER TABLE workflow_durable_streams DROP COLUMN reclaim_at;";

/// The tables Core 2.10 gave a trace carrier, with the count of carried values.
pub(super) const OBSERVABILITY_CONTEXTS: [(&str, &str); 3] = [
    ("workflow_runs", "SELECT COUNT(*) AS n FROM workflow_runs WHERE observability_context IS NOT NULL"),
    ("workflow_commands", "SELECT COUNT(*) AS n FROM workflow_commands WHERE observability_context IS NOT NULL"),
    ("workflow_tasks", "SELECT COUNT(*) AS n FROM workflow_tasks WHERE observability_context IS NOT NULL"),
];

const DELIVERY_REFERENCES: &str =
    "SELECT COUNT(*) AS n FROM workflow_tasks WHERE queue_delivery_reference IS NOT NULL";

/// Records each active PHP worker session's holder as native records one,
/// which native compares on every read. Without it native would read the
/// session as held by a replaced registration and orphan it. PHP 2.11.3
/// records the holder itself (`lease_owner_incarnation`, the same SHA-256 of
/// the registration token), so a session PHP already orphaned stays orphaned.
/// Earlier releases record none: the lease owner's current registration
/// holds it. Returns how many were recorded.
pub(super) async fn adopt_session_holders<DB: Backend>(tx: &mut DB::Connection, recorded: bool) -> Result<usize>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
{
    if recorded {
        let copied = query::<DB>("INSERT INTO dw_worker_session_holders(namespace,session_id,incarnation,updated_at) SELECT namespace,session_id,lease_owner_incarnation,$1 FROM workflow_worker_sessions WHERE status='active' AND lease_owner IS NOT NULL AND lease_owner_incarnation IS NOT NULL")
            .bind(DB::bind_time(super::super::store::now()))
            .execute(&mut *tx)
            .await?;
        return Ok(DB::affected(copied) as usize);
    }
    // Newest active incarnation first, as native's current-token read picks.
    let rows = query::<DB>("SELECT s.namespace,s.session_id,i.token FROM workflow_worker_sessions s JOIN dw_worker_registration_incarnations i ON i.namespace=s.namespace AND i.worker_id=s.lease_owner AND i.status='active' WHERE s.status='active' AND s.lease_owner IS NOT NULL ORDER BY s.namespace,s.session_id,i.created_at DESC")
        .fetch_all(&mut *tx)
        .await?;
    let now = super::super::store::now();
    let mut recorded = std::collections::BTreeSet::new();
    for row in &rows {
        let key = (DB::string(row, "namespace")?, DB::string(row, "session_id")?);
        if recorded.contains(&key) {
            continue;
        }
        let incarnation = super::super::worker_sessions::incarnation_digest(&DB::string(row, "token")?);
        query::<DB>("INSERT INTO dw_worker_session_holders(namespace,session_id,incarnation,updated_at) VALUES ($1,$2,$3,$4)")
            .bind(key.0.clone())
            .bind(key.1.clone())
            .bind(incarnation)
            .bind(DB::bind_time(now))
            .execute(&mut *tx)
            .await?;
        recorded.insert(key);
    }
    Ok(recorded.len())
}

/// Gives every registration the incarnation native expects. PHP's own
/// incarnations and tokens are copied, so a running SDK worker's fenced
/// deregistration still matches; an active registration without one gets a
/// new incarnation. Returns how many were copied and created.
async fn adopt_incarnations<DB: Backend>(
    tx: &mut DB::Connection,
    php_incarnations: bool,
) -> Result<(u64, usize)>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
{
    let now = super::super::store::now();
    let mut copied = 0;
    if php_incarnations {
        // Same statuses as native. Native keeps the recovered count only on
        // deregistration and a finish time on every retired incarnation.
        copied = DB::affected(
            query::<DB>("INSERT INTO dw_worker_registration_incarnations(token,namespace,worker_id,status,recovered_workflow_task_count,finished_at,created_at,updated_at) SELECT token,namespace,worker_id,status,CASE WHEN status='deregistered' THEN recovered_workflow_task_count END,CASE WHEN status='active' THEN NULL ELSE COALESCE(finished_at,updated_at,created_at,$1) END,COALESCE(created_at,$2),COALESCE(updated_at,created_at,$3) FROM workflow_worker_registration_incarnations WHERE status IN ('active','superseded','deregistered')")
                .bind(DB::bind_time(now))
                .bind(DB::bind_time(now))
                .bind(DB::bind_time(now))
                .execute(&mut *tx)
                .await?,
        );
        let total = count::<DB>(
            tx,
            "SELECT COUNT(*) AS n FROM workflow_worker_registration_incarnations",
        )
        .await?;
        if copied != total as u64 {
            return Err(refuse(
                StatusCode::CONFLICT,
                "upgrade_source_incarnation_unqualified",
            ));
        }
    }
    // A PHP 2.8.0+ worker keeps the token it was given; an older one gets a
    // new token.
    let missing = query::<DB>(if php_incarnations {
        "SELECT r.namespace,r.worker_id,r.registration_token AS token FROM workflow_worker_registrations r WHERE r.status='active' AND NOT EXISTS (SELECT 1 FROM dw_worker_registration_incarnations i WHERE i.namespace=r.namespace AND i.worker_id=r.worker_id AND i.status='active') ORDER BY r.namespace,r.worker_id"
    } else {
        "SELECT r.namespace,r.worker_id,NULL AS token FROM workflow_worker_registrations r WHERE r.status='active' AND NOT EXISTS (SELECT 1 FROM dw_worker_registration_incarnations i WHERE i.namespace=r.namespace AND i.worker_id=r.worker_id AND i.status='active') ORDER BY r.namespace,r.worker_id"
    })
    .fetch_all(&mut *tx)
    .await?;
    for row in &missing {
        let token = match DB::optional_string(row, "token")? {
            Some(token) => token,
            None => super::super::worker_incarnations::token().await?,
        };
        query::<DB>("INSERT INTO dw_worker_registration_incarnations(token,namespace,worker_id,status,created_at,updated_at) VALUES ($1,$2,$3,'active',$4,$5)")
            .bind(token)
            .bind(DB::string(row, "namespace")?)
            .bind(DB::string(row, "worker_id")?)
            .bind(DB::bind_time(now))
            .bind(DB::bind_time(now))
            .execute(&mut *tx)
            .await?;
    }
    Ok((copied, missing.len()))
}

async fn count<DB: Backend>(tx: &mut DB::Connection, sql: &'static str) -> Result<i64>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
{
    DB::number(&query::<DB>(sql).fetch_one(&mut *tx).await?, "n")
}
