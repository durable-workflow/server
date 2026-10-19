//! Inspect every database-queue row inside the caller's read-only snapshot.
//! Unknown jobs refuse. No queue row is removed and no task is claimed.
use super::queue::QueueTaskKind;
use super::queue_binding::{
    QueueSourceConfig, SourceQueueJob, SourceRun, SourceTask, bind_queue_job, referenced_task_id,
    valid_config,
};
use crate::runtime::{Result, backend::Backend, refuse};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{AssertSqlSafe, Encode, Executor, IntoArguments, Type};

fn refused(reason: &'static str) -> crate::runtime::RuntimeError {
    refuse(StatusCode::CONFLICT, reason)
}

fn query<DB: Backend>(sql: &'static str) -> sqlx::query::Query<'static, DB, DB::Arguments>
where
    DB::Arguments: IntoArguments<DB>,
{
    sqlx::query(AssertSqlSafe(DB::statement(sql)))
}

pub(super) async fn inspect<DB: Backend>(
    connection: &mut DB::Connection,
    config: &QueueSourceConfig<'_>,
    expected_jobs: i64,
) -> Result<Value>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
{
    if !valid_config(config) {
        return Err(refused("upgrade_source_queue_config_required"));
    }
    // Bound transferred bodies before driver allocation. Each page has at
    // most 32 * 128KiB payload bytes. The enclosing observation deadline caps
    // total work; the primary-key cursor never changes source row order.
    let page_sql = if DB::NAME == "SQLite" {
        "SELECT id,CASE WHEN length(CAST(queue AS BLOB))<=4096 THEN queue ELSE NULL END AS queue,CASE WHEN length(CAST(payload AS BLOB))<=131072 THEN payload ELSE NULL END AS payload,attempts,reserved_at,available_at,created_at FROM jobs WHERE id>$1 ORDER BY id LIMIT 32"
    } else {
        "SELECT id,queue,CASE WHEN octet_length(payload)<=131072 THEN payload ELSE NULL END AS payload,attempts,reserved_at,available_at,created_at FROM jobs WHERE id>$1 ORDER BY id LIMIT 32"
    };
    let (mut cursor, mut jobs, mut workflow, mut activity, mut timer) = (0, 0_i64, 0, 0, 0);
    loop {
        let page = query::<DB>(page_sql)
            .bind(cursor)
            .fetch_all(&mut *connection)
            .await?;
        if page.is_empty() {
            break;
        }
        for row in page {
            let job = SourceQueueJob {
                id: DB::number(&row, "id")?,
                queue: DB::optional_string(&row, "queue")?
                    .ok_or_else(|| refused("upgrade_source_queue_representation_unqualified"))?,
                payload: DB::optional_string(&row, "payload")?
                    .ok_or_else(|| refused("upgrade_source_queue_representation_unqualified"))?,
                attempts: DB::number(&row, "attempts")?,
                reserved_at: DB::optional_number(&row, "reserved_at")?,
                available_at: DB::number(&row, "available_at")?,
                created_at: DB::number(&row, "created_at")?,
            };
            let task_id = referenced_task_id(&job)
                .map_err(|_| refused("upgrade_source_queue_representation_unqualified"))?;
            oversized::<DB>(connection, TASK_TEXT_BYTES, task_id.as_str()).await?;
            let task = query::<DB>("SELECT id,workflow_run_id,namespace,task_type,status,connection,queue,available_at,lease_owner,lease_expires_at,attempt_count FROM workflow_tasks WHERE id=$1")
                .bind(task_id.as_str()).fetch_optional(&mut *connection).await?
                .ok_or_else(|| refused("upgrade_source_queue_task_missing"))?;
            let task = SourceTask {
                id: DB::string(&task, "id")?,
                workflow_run_id: DB::string(&task, "workflow_run_id")?,
                namespace: DB::optional_string(&task, "namespace")?,
                task_type: DB::string(&task, "task_type")?,
                status: DB::string(&task, "status")?,
                connection: DB::optional_string(&task, "connection")?,
                queue: DB::optional_string(&task, "queue")?,
                available_at: original_time::<DB>(&task, "available_at")?,
                lease_owner: DB::optional_string(&task, "lease_owner")?,
                lease_expires_at: original_time::<DB>(&task, "lease_expires_at")?,
                attempt_count: DB::number(&task, "attempt_count")?,
            };
            oversized::<DB>(connection, RUN_TEXT_BYTES, task.workflow_run_id.as_str()).await?;
            let run = query::<DB>("SELECT id,workflow_instance_id,workflow_class,workflow_type,namespace,queue,status FROM workflow_runs WHERE id=$1")
                .bind(task.workflow_run_id.as_str()).fetch_optional(&mut *connection).await?
                .ok_or_else(|| refused("upgrade_source_queue_run_missing"))?;
            let run = SourceRun {
                id: DB::string(&run, "id")?,
                workflow_instance_id: DB::string(&run, "workflow_instance_id")?,
                workflow_class: DB::string(&run, "workflow_class")?,
                workflow_type: DB::string(&run, "workflow_type")?,
                namespace: DB::optional_string(&run, "namespace")?,
                queue: DB::optional_string(&run, "queue")?,
                status: DB::string(&run, "status")?,
            };
            let bound = bind_queue_job(&job, &task, &run, config)
                .map_err(|_| refused("upgrade_source_queue_binding_unqualified"))?;
            match bound.kind {
                QueueTaskKind::Workflow => workflow += 1,
                QueueTaskKind::Activity => activity += 1,
                QueueTaskKind::Timer => timer += 1,
            }
            cursor = job.id;
            jobs += 1;
        }
    }
    // A malformed zero/negative ID must not disappear behind the cursor.
    if jobs != expected_jobs {
        return Err(refused("upgrade_source_queue_binding_unqualified"));
    }
    Ok(json!({
        "status":"known_subset_bound", "jobs":jobs,
        "workflow_jobs":workflow, "activity_jobs":activity, "timer_jobs":timer,
        "source_mutated":false, "takeover_allowed":false
    }))
}

fn original_time<DB: Backend>(row: &DB::Row, field: &str) -> Result<Option<String>> {
    // SQLite text remains byte-for-byte original. Network timestamp columns
    // are decoded by their actual SQL type, with original UTC microseconds.
    // Neither form is emitted or written back to the source database.
    if DB::NAME == "SQLite" {
        return DB::optional_string(row, field);
    }
    Ok(DB::optional_instant(row, field)?
        .map(|time| time.format("%Y-%m-%d %H:%M:%S%.6f").to_string()))
}

// SQLite does not enforce varchar lengths, so a matching-catalog file can hold
// arbitrarily large text. Measure every selected value before transferring it.
// PostgreSQL and MySQL enforce the frozen catalog's lengths themselves.
const TASK_TEXT_BYTES: &str = "SELECT max(coalesce(length(CAST(id AS BLOB)),0),coalesce(length(CAST(workflow_run_id AS BLOB)),0),coalesce(length(CAST(namespace AS BLOB)),0),coalesce(length(CAST(task_type AS BLOB)),0),coalesce(length(CAST(status AS BLOB)),0),coalesce(length(CAST(connection AS BLOB)),0),coalesce(length(CAST(queue AS BLOB)),0),coalesce(length(CAST(available_at AS BLOB)),0),coalesce(length(CAST(lease_owner AS BLOB)),0),coalesce(length(CAST(lease_expires_at AS BLOB)),0)) AS bytes FROM workflow_tasks WHERE id=$1";
const RUN_TEXT_BYTES: &str = "SELECT max(coalesce(length(CAST(id AS BLOB)),0),coalesce(length(CAST(workflow_instance_id AS BLOB)),0),coalesce(length(CAST(workflow_class AS BLOB)),0),coalesce(length(CAST(workflow_type AS BLOB)),0),coalesce(length(CAST(namespace AS BLOB)),0),coalesce(length(CAST(queue AS BLOB)),0),coalesce(length(CAST(status AS BLOB)),0)) AS bytes FROM workflow_runs WHERE id=$1";
const MAX_TEXT_BYTES: i64 = 4096;

async fn oversized<DB: Backend>(
    connection: &mut DB::Connection,
    sql: &'static str,
    id: &str,
) -> Result<()>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
{
    if DB::NAME != "SQLite" {
        return Ok(());
    }
    if let Some(row) = query::<DB>(sql).bind(id).fetch_optional(&mut *connection).await?
        && DB::number(&row, "bytes")? > MAX_TEXT_BYTES
    {
        return Err(refused("upgrade_source_queue_representation_unqualified"));
    }
    Ok(())
}
