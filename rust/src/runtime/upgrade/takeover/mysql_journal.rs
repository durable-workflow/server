//! MySQL and MariaDB takeover. Their DDL commits implicitly, and `LOCK
//! TABLES` refuses to create or drop the tables it does not hold, so the
//! takeover cannot be one transaction. It writes native's marker first, with
//! a `rust-takeover:<layout>` row whose version counts the completed steps
//! and whose checksum holds the backup fingerprint's digest. From then on PHP
//! refuses the database and native refuses it until the last step. Every step
//! is idempotent, so a rerun with the same fingerprint resumes after the last
//! recorded one.
use super::{
    BUDGET, DELIVERY_REFERENCES, Result, adopt_incarnations, count, matches_backup,
    php_incarnations, preconditions, qualified, report,
};
use crate::runtime::{
    mysql::{self, Flavor, LEDGER_SQL, MARKER_SQL, TAKEOVER_ENGINE},
    upgrade::{
        bounded, fingerprint, mysql_inventory, mysql_source, queue_binding::QueueSourceConfig,
        refuse,
    },
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{Connection, Executor, MySql, MySqlConnection, mysql::MySqlConnectOptions};
use tokio::time::Instant;

/// Completed-step counts recorded in the marker row.
const MARKED: i64 = 0;
/// Sessions rechecked and the source fingerprinted again after the marker:
/// nothing PHP wrote can be missing from the backup.
const FENCED: i64 = 1;
const LAST: i64 = 11;

/// One takeover run: what its steps need and what its report says.
struct Run<'a> {
    backend: &'static str,
    flavor: Flavor,
    digest: Vec<u8>,
    layout: String,
    fingerprint: &'a str,
    queues: &'a QueueSourceConfig<'a>,
    inventory: Value,
    converted: serde_json::Map<String, Value>,
}

enum Journal {
    /// No marker: a PHP database.
    Absent,
    /// The marker table without its row: a takeover killed between the two.
    Empty,
    /// A takeover in progress, with its layout, step count and digest.
    Takeover(String, i64, Vec<u8>),
    /// Native's own database, or one native is still initializing.
    Owned,
}

pub async fn take_over_mysql(
    options: MySqlConnectOptions,
    backup_fingerprint: &str,
    queues: &QueueSourceConfig<'_>,
) -> Result<Value> {
    preconditions(backup_fingerprint, queues)?;
    let digest = fingerprint_digest(backup_fingerprint)?;
    let deadline = Instant::now() + BUDGET;
    // TIMESTAMP values render in UTC for the fingerprint, and lock waits stay
    // short; the client deadline bounds whole-table work.
    let (connection, backend) = mysql_source(options, deadline, true, 0).await?;
    bounded(connection, deadline, async |connection| {
        // The same session lock as native bootstrap, so neither a native node
        // nor a second takeover can run on this database meanwhile.
        let locked: Option<i64> = sqlx::query_scalar(
            "SELECT GET_LOCK(CONCAT('dw-native-bootstrap:',SHA1(DATABASE())),5)",
        )
        .fetch_one(&mut *connection)
        .await?;
        if locked != Some(1) {
            return Err(refuse(
                StatusCode::SERVICE_UNAVAILABLE,
                "native_initialization_busy",
            ));
        }
        let mut run = Run {
            backend,
            flavor: Flavor::detect(connection).await?,
            digest,
            layout: String::new(),
            fingerprint: backup_fingerprint,
            queues,
            inventory: Value::Null,
            converted: serde_json::Map::new(),
        };
        let completed = match journal(connection).await? {
            Journal::Absent => {
                mark(connection, &mut run).await?;
                MARKED
            }
            Journal::Empty => {
                // Only a takeover adds the marker to a PHP database. Its layout
                // is read again with the marker set aside.
                let mut tx = connection
                    .begin_with("START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY")
                    .await?;
                let layout = mysql_inventory(&mut tx, backend, Some(queues), true, true).await;
                tx.rollback().await?;
                run.layout = qualified(&layout?)?.to_owned();
                record(connection, &run).await?;
                MARKED
            }
            Journal::Takeover(layout, completed, digest) => {
                if digest != run.digest {
                    return Err(refuse(
                        StatusCode::CONFLICT,
                        "upgrade_backup_fingerprint_mismatch",
                    ));
                }
                php_incarnations(&layout)?;
                run.layout = layout;
                run.converted
                    .insert("resumed_after_step".into(), json!(completed));
                completed
            }
            Journal::Owned => {
                return Err(refuse(StatusCode::CONFLICT, "upgrade_source_already_owned"));
            }
        };
        for step in completed + 1..=LAST {
            perform(connection, &mut run, step).await?;
            #[cfg(test)]
            super::interruption_point(&format!("after-step-{step}"));
        }
        finish(connection, &run).await?;
        mysql::verify(connection, run.flavor).await?;
        let digest = json!({"fingerprint": run.fingerprint});
        Ok(report(
            run.backend,
            &run.layout,
            &digest,
            &run.inventory,
            Value::Object(run.converted),
        ))
    })
    .await
}

/// `sha256:<hex>` as the 32 bytes the marker row keeps.
fn fingerprint_digest(fingerprint: &str) -> Result<Vec<u8>> {
    fingerprint["sha256:".len()..]
        .as_bytes()
        .chunks(2)
        .map(|pair| {
            std::str::from_utf8(pair)
                .ok()
                .and_then(|pair| u8::from_str_radix(pair, 16).ok())
        })
        .collect::<Option<Vec<_>>>()
        .ok_or_else(|| {
            refuse(
                StatusCode::BAD_REQUEST,
                "upgrade_backup_fingerprint_required",
            )
        })
}

async fn journal(connection: &mut MySqlConnection) -> Result<Journal> {
    let marked: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND LOWER(TABLE_NAME)='dw_server_schema'")
        .fetch_one(&mut *connection)
        .await?;
    if marked == 0 {
        return Ok(Journal::Absent);
    }
    let mut rows: Vec<(String, i64, Vec<u8>)> =
        sqlx::query_as("SELECT engine,version,bootstrap_checksum FROM dw_server_schema")
            .fetch_all(&mut *connection)
            .await?;
    Ok(match rows.pop() {
        None => Journal::Empty,
        Some((engine, completed, digest)) if rows.is_empty() => {
            match engine.strip_prefix(TAKEOVER_ENGINE) {
                Some(layout) => Journal::Takeover(layout.to_owned(), completed, digest),
                None => Journal::Owned,
            }
        }
        Some(_) => Journal::Owned,
    })
}

/// No other session may use the database. That needs the PROCESS privilege,
/// since without it MySQL shows only the account's own sessions.
async fn sessions_clear(connection: &mut MySqlConnection) -> Result<()> {
    let visible: bool = sqlx::query_scalar(
        "SELECT EXISTS(SELECT 1 FROM information_schema.USER_PRIVILEGES WHERE PRIVILEGE_TYPE='PROCESS')",
    )
    .fetch_one(&mut *connection)
    .await?;
    if !visible {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_source_sessions_unverifiable",
        ));
    }
    let others: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.PROCESSLIST WHERE DB=DATABASE() AND ID<>CONNECTION_ID()")
        .fetch_one(&mut *connection)
        .await?;
    if others != 0 {
        return Err(refuse(StatusCode::CONFLICT, "upgrade_source_in_use"));
    }
    Ok(())
}

/// The inventory and fingerprint from one consistent snapshot, refused
/// unless the source is qualified and matches the backup.
async fn verified_snapshot(
    connection: &mut MySqlConnection,
    run: &Run<'_>,
    fenced: bool,
) -> Result<(Value, String)> {
    sessions_clear(connection).await?;
    let mut tx = connection
        .begin_with("START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY")
        .await?;
    let read = async {
        let inventory =
            mysql_inventory(&mut tx, run.backend, Some(run.queues), true, fenced).await?;
        let digest = fingerprint::mysql_digest(&mut tx, run.backend, fenced).await?;
        Ok::<_, crate::runtime::RuntimeError>((inventory, digest))
    }
    .await;
    tx.rollback().await?;
    let (inventory, digest) = read?;
    let layout = qualified(&inventory)?.to_owned();
    matches_backup(&digest, run.fingerprint)?;
    php_incarnations(&layout)?;
    Ok((inventory, layout))
}

/// The first write: native's marker with the takeover row, once the source
/// is verified. PHP refuses the database from here on.
async fn mark(connection: &mut MySqlConnection, run: &mut Run<'_>) -> Result<()> {
    let (inventory, layout) = verified_snapshot(connection, run, false).await?;
    run.inventory = inventory;
    run.layout = layout;
    connection.execute(MARKER_SQL).await?;
    #[cfg(test)]
    super::interruption_point("before-marker-row");
    record(connection, run).await
}

async fn record(connection: &mut MySqlConnection, run: &Run<'_>) -> Result<()> {
    sqlx::query("INSERT INTO dw_server_schema(engine,version,bootstrap_checksum) VALUES (?,?,?)")
        .bind(format!("{TAKEOVER_ENGINE}{}", run.layout))
        .bind(MARKED)
        .bind(&run.digest[..])
        .execute(&mut *connection)
        .await?;
    Ok(())
}

/// Records one more completed step, in the caller's transaction or alone.
async fn advance<'c, E>(executor: E, run: &Run<'_>, step: i64) -> Result<()>
where
    E: Executor<'c, Database = MySql>,
{
    let advanced = sqlx::query("UPDATE dw_server_schema SET version=? WHERE engine=? AND version=?")
        .bind(step)
        .bind(format!("{TAKEOVER_ENGINE}{}", run.layout))
        .bind(step - 1)
        .execute(executor)
        .await?
        .rows_affected();
    if advanced != 1 {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_takeover_journal_changed",
        ));
    }
    Ok(())
}

async fn column_exists(connection: &mut MySqlConnection, table: &str, column: &str) -> Result<bool> {
    let found: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?")
        .bind(table)
        .bind(column)
        .fetch_one(&mut *connection)
        .await?;
    Ok(found != 0)
}

/// Each step checks what it has to do, so repeating an interrupted one is
/// harmless, and then records itself.
async fn perform(connection: &mut MySqlConnection, run: &mut Run<'_>, step: i64) -> Result<()> {
    let php_incarnations = php_incarnations(&run.layout)?;
    match step {
        FENCED => {
            // A session that was still open when the marker appeared could
            // have written; none is left, and the source is still the backup.
            let (inventory, layout) = verified_snapshot(connection, run, true).await?;
            if layout != run.layout {
                return Err(refuse(
                    StatusCode::CONFLICT,
                    "upgrade_source_catalog_unqualified",
                ));
            }
            run.inventory = inventory;
        }
        2 => {
            sqlx::raw_sql(sqlx::AssertSqlSafe(run.flavor.native_relations_sql()?))
                .execute(&mut *connection)
                .await?;
            let indexed: i64 = sqlx::query_scalar("SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='workflow_tasks' AND INDEX_NAME='dw_workflow_tasks_poll'")
                .fetch_one(&mut *connection)
                .await?;
            if indexed == 0 {
                connection.execute("ALTER TABLE workflow_tasks ADD KEY dw_workflow_tasks_poll (namespace(64),queue(64),task_type(16),status(16),available_at)").await?;
            }
        }
        3 => {
            // Rows, unlike DDL, commit with the step that records them.
            let mut tx = connection.begin().await?;
            let (copied, created) = adopt_incarnations::<MySql>(&mut tx, php_incarnations).await?;
            let holders = super::adopt_session_holders::<MySql>(&mut tx, super::super::has_release(&run.layout, "php-2.11.3")).await?;
            advance(&mut *tx, run, step).await?;
            tx.commit().await?;
            run.converted
                .insert("incarnations_copied".into(), json!(copied));
            run.converted
                .insert("incarnations_created".into(), json!(created));
            run.converted
                .insert("session_holders_recorded".into(), json!(holders));
            return Ok(());
        }
        4 => {
            if php_incarnations {
                connection
                    .execute("DROP TABLE IF EXISTS workflow_worker_registration_incarnations")
                    .await?;
            }
        }
        5 => {
            if column_exists(connection, "workflow_worker_registrations", "registration_token").await? {
                connection
                    .execute("ALTER TABLE workflow_worker_registrations DROP COLUMN registration_token")
                    .await?;
            }
        }
        6 => {
            if column_exists(connection, "workflow_service_calls", "call_deadline_at").await? {
                // Copy the deadlines native keeps first; a repeated step
                // copies only those not copied yet.
                let copied = sqlx::query(super::SERVICE_CALL_DEADLINES).execute(&mut *connection).await?.rows_affected();
                run.converted.insert("service_call_deadlines_copied".into(), json!(copied));
                // Dropping only the column would leave its index on status alone.
                connection.execute("ALTER TABLE workflow_service_calls DROP INDEX wf_service_calls_deadline_idx, DROP COLUMN call_deadline_at").await?;
            }
        }
        7 => {
            if column_exists(connection, "workflow_tasks", "queue_delivery_reference").await? {
                let references = count::<MySql>(connection, DELIVERY_REFERENCES).await?;
                run.converted
                    .insert("queue_delivery_references_removed".into(), json!(references));
                connection
                    .execute("ALTER TABLE workflow_tasks DROP COLUMN queue_delivery_reference")
                    .await?;
            }
        }
        8 => {
            if run.layout.ends_with("+mariadb-driver") {
                // Laravel's mariadb connection made the backup hold owner a uuid.
                connection
                    .execute("ALTER TABLE runtime_external_payload_backup_hold MODIFY owner char(36) DEFAULT NULL")
                    .await?;
            }
        }
        9 => {
            // Count every carrier before dropping any column, so a step
            // repeated after an interruption still drops each one.
            let mut contexts = 0;
            let mut carriers = Vec::new();
            for (table, sql) in super::OBSERVABILITY_CONTEXTS {
                if column_exists(connection, table, "observability_context").await? {
                    contexts += count::<MySql>(connection, sql).await?;
                    carriers.push(table);
                }
            }
            if !carriers.is_empty() {
                run.converted
                    .insert("observability_contexts_removed".into(), json!(contexts));
            }
            for table in carriers {
                sqlx::raw_sql(sqlx::AssertSqlSafe(format!("ALTER TABLE {table} DROP COLUMN observability_context")))
                    .execute(&mut *connection)
                    .await?;
            }
        }
        10 => {
            // Durable stream reclaim deadlines: 2.11.3 recorded them in
            // `reclaim_at`; earlier layouts get each closed stream's
            // retention. The layout decides, not the column: a resumed
            // 2.11.3 takeover whose column is already dropped copied every
            // deadline before the drop (each insert commits), so it adopts
            // nothing more.
            let recorded = super::super::has_release(&run.layout, "php-2.11.3");
            if !recorded || column_exists(connection, "workflow_durable_streams", "reclaim_at").await? {
                let reclaims = super::adopt_stream_reclaims::<MySql>(connection, recorded).await?;
                run.converted.insert("stream_reclaims_recorded".into(), json!(reclaims));
            }
            // PHP 2.11.3's additions. Count before dropping, as step 9 does.
            if column_exists(connection, "workflow_failures", "message_full").await? {
                let messages = count::<MySql>(connection, super::FULL_FAILURE_MESSAGES).await?;
                run.converted.insert("full_failure_messages_removed".into(), json!(messages));
                connection.execute("ALTER TABLE workflow_failures DROP COLUMN message_full").await?;
            }
            if column_exists(connection, "workflow_service_calls", "idempotency_scope_hash").await? {
                connection
                    .execute("ALTER TABLE workflow_service_calls DROP INDEX wf_service_calls_idem_scope_unique, DROP COLUMN idempotency_scope_hash")
                    .await?;
            }
            if column_exists(connection, "workflow_service_call_dispatches", "state").await? {
                let dispatches = count::<MySql>(connection, super::COMPLETED_DISPATCHES).await?;
                run.converted.insert("service_call_dispatches_removed".into(), json!(dispatches));
                connection.execute("DROP TABLE workflow_service_call_dispatches").await?;
            }
            if column_exists(connection, "workflow_worker_sessions", "lease_owner_incarnation").await? {
                connection
                    .execute("ALTER TABLE workflow_worker_sessions DROP COLUMN lease_owner_incarnation")
                    .await?;
            }
            if column_exists(connection, "workflow_durable_streams", "reclaim_at").await? {
                connection
                    .execute("ALTER TABLE workflow_durable_streams DROP INDEX wf_durable_streams_ns_reclaim_idx, DROP COLUMN reclaim_at")
                    .await?;
            }
        }
        11 => {
            // PHP 2.11.8's pending deletions: copied in one transaction
            // (a repeat copies only rows not copied yet), then PHP's table
            // goes. A resumed step whose table is gone copied them already.
            if super::super::has_release(&run.layout, "php-2.11.8")
                && column_exists(connection, "runtime_external_payload_deletions", "id").await?
            {
                let mut tx = connection.begin().await?;
                let deletions =
                    super::adopt_payload_deletions::<MySql>(&mut tx, super::MYSQL_PAYLOAD_DELETIONS).await?;
                tx.commit().await?;
                run.converted
                    .insert("external_payload_deletions_copied".into(), json!(deletions));
                connection
                    .execute("DROP TABLE runtime_external_payload_deletions")
                    .await?;
            }
        }
        _ => {}
    }
    advance(&mut *connection, run, step).await
}

/// The ledger row and native's final marker commit together.
async fn finish(connection: &mut MySqlConnection, run: &Run<'_>) -> Result<()> {
    connection.execute(LEDGER_SQL).await?;
    let migration = run.flavor.migration();
    let mut tx = connection.begin().await?;
    sqlx::query("INSERT INTO _sqlx_migrations(version,description,success,checksum,execution_time) VALUES (?,?,TRUE,?,0)")
        .bind(migration.version)
        .bind(migration.description.as_ref())
        .bind(migration.checksum.as_ref())
        .execute(&mut *tx)
        .await?;
    let marked = sqlx::query("UPDATE dw_server_schema SET engine='rust-development',version=?,bootstrap_checksum=? WHERE engine=? AND version=?")
        .bind(mysql::VERSION)
        .bind(run.flavor.checksum())
        .bind(format!("{TAKEOVER_ENGINE}{}", run.layout))
        .bind(LAST)
        .execute(&mut *tx)
        .await?
        .rows_affected();
    if marked != 1 {
        return Err(refuse(
            StatusCode::CONFLICT,
            "upgrade_takeover_journal_changed",
        ));
    }
    tx.commit().await?;
    Ok(())
}
