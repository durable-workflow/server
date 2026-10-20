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
const UNSUPPORTED_FEATURES: [(&str, &[&str]); 7] = [
    ("upgrade_source_runtime_credentials_unsupported", &["runtime_credentials"]),
    (
        "upgrade_source_message_streams_unsupported",
        &[
            "workflow_messages",
            "workflow_durable_streams",
            "workflow_durable_stream_items",
            "workflow_inbound_streams",
            "workflow_inbound_stream_items",
        ],
    ),
    (
        "upgrade_source_services_unsupported",
        &["workflow_services", "workflow_service_endpoints", "workflow_service_operations"],
    ),
    ("upgrade_source_update_validation_unsupported", &["workflow_update_validation_tasks"]),
    ("upgrade_source_worker_sessions_unsupported", &["workflow_worker_sessions"]),
    ("upgrade_source_build_id_rollouts_unsupported", &["workflow_worker_build_id_rollouts"]),
    (
        "upgrade_source_search_attribute_definitions_unsupported",
        &["search_attribute_definitions"],
    ),
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
        "php-2.8.0" | "php-2.9.7" => Ok(true),
        _ => Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_layout_unconverted",
        )),
    }
}

/// Service calls are not part of native yet.
async fn service_calls_absent<DB: Backend>(tx: &mut DB::Connection) -> Result<()>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
{
    if count::<DB>(tx, "SELECT COUNT(*) AS n FROM workflow_service_calls").await? != 0 {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_service_calls_unsupported",
        ));
    }
    Ok(())
}

/// Converts a recorded PHP release layout to native's (the design's recipes).
async fn convert<DB: Backend>(tx: &mut DB::Connection, layout: &str) -> Result<Value>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
{
    let php_incarnations = php_incarnations(layout)?;
    service_calls_absent::<DB>(tx).await?;
    let (copied, created) = adopt_incarnations::<DB>(tx, php_incarnations).await?;
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
    let delivery_references = if layout == "php-2.9.7" {
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
    Ok(json!({
        "incarnations_copied":copied, "incarnations_created":created,
        "queue_delivery_references_removed":delivery_references
    }))
}

const DELIVERY_REFERENCES: &str =
    "SELECT COUNT(*) AS n FROM workflow_tasks WHERE queue_delivery_reference IS NOT NULL";

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
