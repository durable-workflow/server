//! Original authored activity deadlines, shared by claiming and outcome fences.
use super::store::{Store, id};
use super::{Result, backend::Backend, refuse, store::now};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

pub(super) const TIMEOUT_FIELDS: [&str; 4] = [
    "start_to_close_timeout",
    "schedule_to_start_timeout",
    "schedule_to_close_timeout",
    "heartbeat_timeout",
];

pub(super) fn timeout_options(command: &Value) -> Result<[Option<i64>; 4]> {
    let mut values = [None; 4];
    for (index, field) in TIMEOUT_FIELDS.iter().enumerate() {
        if let Some(value) = command.get(*field).filter(|value| !value.is_null()) {
            let seconds = value
                .as_i64()
                .filter(|seconds| *seconds > 0)
                .filter(|seconds| {
                    chrono::Duration::try_seconds(*seconds)
                        .and_then(|duration| now().checked_add_signed(duration))
                        .is_some()
                })
                .ok_or_else(|| {
                    refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_activity_timeout")
                })?;
            values[index] = Some(seconds);
        }
    }
    Ok(values)
}

pub(super) fn deadline(
    policy: &Value,
    field: &str,
    at: DateTime<Utc>,
) -> Result<Option<DateTime<Utc>>> {
    match policy.get(field).filter(|value| !value.is_null()) {
        None => Ok(None),
        Some(value) => value
            .as_i64()
            .filter(|seconds| *seconds > 0)
            .and_then(chrono::Duration::try_seconds)
            .and_then(|duration| at.checked_add_signed(duration))
            .map(Some)
            .ok_or_else(|| refuse(StatusCode::CONFLICT, "invalid_stored_activity_retry_policy")),
    }
}

pub(super) fn validate_policy(policy: &Value) -> Result<()> {
    if policy.is_null() {
        return Ok(());
    }
    let mut command = json!({"retry_policy":{"max_attempts":policy["max_attempts"], "backoff_seconds":policy["backoff_seconds"], "non_retryable_error_types":policy["non_retryable_error_types"]}});
    for field in TIMEOUT_FIELDS {
        command[field] = policy[field].clone();
    }
    let normalized = super::activity_failures::retry_policy(&command)?;
    if normalized.as_ref() != Some(policy) {
        return Err(refuse(
            StatusCode::CONFLICT,
            "invalid_stored_activity_retry_policy",
        ));
    }
    Ok(())
}

pub(super) fn expired_kind<DB: Backend>(
    activity: &DB::Row,
    at: DateTime<Utc>,
) -> Result<Option<&'static str>> {
    let status = DB::string(activity, "status")?;
    if !matches!(status.as_str(), "pending" | "running") {
        return Ok(None);
    }
    for (kind, field, applies) in [
        ("schedule_to_close", "schedule_to_close_deadline_at", true),
        (
            "schedule_to_start",
            "schedule_deadline_at",
            status == "pending",
        ),
        ("heartbeat", "heartbeat_deadline_at", status == "running"),
        ("start_to_close", "close_deadline_at", status == "running"),
    ] {
        if applies && DB::optional_instant(activity, field)?.is_some_and(|deadline| deadline <= at)
        {
            return Ok(Some(kind));
        }
    }
    Ok(None)
}

pub(super) fn fence<DB: Backend>(activity: &DB::Row) -> Result<()> {
    if let Some(kind) = expired_kind::<DB>(activity, now())? {
        return Err(refuse(StatusCode::CONFLICT, timeout_reason(kind)));
    }
    Ok(())
}

pub(super) fn timeout_reason(kind: &str) -> &'static str {
    match kind {
        "schedule_to_close" => "schedule_to_close_timeout",
        "schedule_to_start" => "schedule_to_start_timeout",
        "heartbeat" => "heartbeat_timeout",
        _ => "start_to_close_timeout",
    }
}

impl<DB: Backend> Store<DB>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> Option<&'q str>: Encode<'q, DB>,
    for<'q> Option<DB::EncodedDocument>: Encode<'q, DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
    for<'r> i64: Decode<'r, DB>,
    usize: ColumnIndex<DB::Row>,
{
    pub(super) async fn expire_activity_deadlines(&self) -> Result<()> {
        const DUE: &str = "SELECT a.id FROM activity_executions a JOIN workflow_runs r ON r.id=a.workflow_run_id WHERE r.status IN ('pending','running','waiting') AND a.status IN ('pending','running') AND (a.schedule_to_close_deadline_at<=$1 OR (a.status='pending' AND a.schedule_deadline_at<=$2) OR (a.status='running' AND (a.close_deadline_at<=$3 OR a.heartbeat_deadline_at<=$4))) ORDER BY a.id LIMIT 50";
        let boundary = now();
        let candidates = Self::query(DUE)
            .bind(DB::bind_time(boundary))
            .bind(DB::bind_time(boundary))
            .bind(DB::bind_time(boundary))
            .bind(DB::bind_time(boundary))
            .fetch_all(&self.pool)
            .await?;
        for candidate in candidates {
            let mut tx = self.begin().await?;
            let activity_id = DB::string(&candidate, "id")?;
            let Some(activity) = Self::query("SELECT * FROM activity_executions WHERE id=$1")
                .bind(&activity_id)
                .fetch_optional(&mut *tx)
                .await?
            else {
                continue;
            };
            let Some(kind) = expired_kind::<DB>(&activity, now())? else {
                continue;
            };
            let run_id = DB::string(&activity, "workflow_run_id")?;
            let run = match Self::active_run(&mut tx, &run_id).await {
                Ok(run) => run,
                Err(super::RuntimeError::Refused {
                    reason: "run_closed" | "cancellation_deadline_expired",
                    ..
                }) => continue,
                Err(error) => return Err(error),
            };
            let policy =
                DB::optional_document_row(&activity, "retry_policy")?.unwrap_or(Value::Null);
            validate_policy(&policy)?;
            let count = DB::number(&activity, "attempt_count")?.max(1);
            let max = policy["max_attempts"].as_i64().unwrap_or(1);
            let current = DB::optional_string(&activity, "current_attempt_id")?;
            if DB::string(&activity, "status")? == "running" {
                let Some(attempt_id) = current.as_deref() else {
                    continue;
                };
                let Some(attempt) = Self::query(
                    "SELECT * FROM activity_attempts WHERE id=$1 AND activity_execution_id=$2",
                )
                .bind(attempt_id)
                .bind(&activity_id)
                .fetch_optional(&mut *tx)
                .await?
                else {
                    continue;
                };
                if DB::number(&attempt, "attempt_number")?
                    != DB::number(&activity, "attempt_count")?
                    || !matches!(
                        DB::string(&attempt, "status")?.as_str(),
                        "running" | "expired"
                    )
                {
                    continue;
                }
            }
            let task = Self::query(DB::ACTIVITY_OPEN_TASK_SQL)
                .bind(&run_id)
                .bind(&activity_id)
                .fetch_optional(&mut *tx)
                .await?;
            let task_id = task
                .as_ref()
                .map(|task| DB::string(task, "id"))
                .transpose()?;
            let at = now();
            if let Some(attempt_id) = current.as_deref() {
                Self::query("UPDATE activity_attempts SET status='failed',lease_expires_at=NULL,closed_at=$1 WHERE id=$2 AND status='running'")
                    .bind(DB::bind_time(at)).bind(attempt_id).execute(&mut *tx).await?;
            }
            if let Some(task_id) = task_id.as_deref() {
                Self::query("UPDATE workflow_tasks SET status='cancelled',lease_expires_at=NULL,last_error=NULL WHERE id=$1")
                    .bind(task_id).execute(&mut *tx).await?;
            }
            let deadline_field = match kind {
                "schedule_to_close" => "schedule_to_close_deadline_at",
                "schedule_to_start" => "schedule_deadline_at",
                "heartbeat" => "heartbeat_deadline_at",
                _ => "close_deadline_at",
            };
            let expired_at = DB::instant(&activity, deadline_field)?
                .to_rfc3339_opts(chrono::SecondsFormat::Secs, false);
            let label = DB::string(&activity, "activity_type")?;
            let message = if kind == "heartbeat" {
                format!(
                    "Activity {label} heartbeat deadline expired at {expired_at} (last heartbeat: {}).",
                    DB::optional_instant(&activity, "last_heartbeat_at")?
                        .map(|at| at.to_rfc3339_opts(chrono::SecondsFormat::Secs, false))
                        .unwrap_or("never".into())
                )
            } else {
                format!(
                    "Activity {label} {} deadline expired at {expired_at}.",
                    kind.replace('_', "-")
                )
            };
            if count < max && kind != "schedule_to_close" {
                let backoff = policy["backoff_seconds"]
                    .as_array()
                    .and_then(|values| values.get((count - 1) as usize).or_else(|| values.last()))
                    .and_then(Value::as_i64)
                    .unwrap_or(0);
                let available = chrono::Duration::try_seconds(backoff)
                    .and_then(|duration| at.checked_add_signed(duration))
                    .ok_or_else(|| {
                        refuse(StatusCode::CONFLICT, "invalid_stored_activity_retry_policy")
                    })?;
                Self::query("UPDATE activity_executions SET status='pending',last_heartbeat_at=NULL,close_deadline_at=NULL,heartbeat_deadline_at=NULL,schedule_deadline_at=$1 WHERE id=$2")
                    .bind(DB::optional_time(deadline(&policy,"schedule_to_start_timeout",available)?)).bind(&activity_id).execute(&mut *tx).await?;
                let retry = Self::create_task(&mut tx,&run_id,&DB::string(&activity,"queue")?,"activity",json!({"activity_execution_id":activity_id,"retry_of_task_id":task_id,"retry_after_attempt_id":current,"retry_after_attempt":count,"retry_backoff_seconds":backoff,"max_attempts":max,"retry_policy":policy,"timeout_kind":kind})).await?;
                Self::query(
                    "UPDATE workflow_tasks SET available_at=$1,attempt_count=$2 WHERE id=$3",
                )
                .bind(DB::bind_time(available))
                .bind(count)
                .bind(&retry)
                .execute(&mut *tx)
                .await?;
                let updated = Self::query("SELECT * FROM activity_executions WHERE id=$1")
                    .bind(&activity_id)
                    .fetch_one(&mut *tx)
                    .await?;
                let mut payload = Self::activity_payload(&updated)?;
                for (key,value) in json!({"activity_attempt_id":current,"retry_task_id":retry,"retry_of_task_id":task_id,"retry_available_at":available,"retry_backoff_seconds":backoff,"retry_after_attempt_id":current,"retry_after_attempt":count,"max_attempts":max,"retry_policy":policy,"timeout_kind":kind,"message":message}).as_object().unwrap() { if !value.is_null() {payload[key]=value.clone();} }
                Self::append(
                    &mut tx,
                    &run_id,
                    "ActivityRetryScheduled",
                    payload,
                    task_id.as_deref(),
                    None,
                )
                .await?;
            } else {
                let failure_id = id();
                const CLASS: &str = "Workflow\\V2\\Exceptions\\ActivityTimeoutException";
                Self::query(
                    "UPDATE activity_executions SET status='failed',closed_at=$1 WHERE id=$2",
                )
                .bind(DB::bind_time(at))
                .bind(&activity_id)
                .execute(&mut *tx)
                .await?;
                Self::query("INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,failure_category,exception_class,message,non_retryable,handled,file,line,created_at,updated_at) VALUES ($1,$2,'activity_execution',$3,'timeout','timeout',$4,$5,FALSE,FALSE,'',0,$6,$7)")
                    .bind(&failure_id).bind(&run_id).bind(&activity_id).bind(CLASS).bind(&message).bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut *tx).await?;
                let updated = Self::query("SELECT * FROM activity_executions WHERE id=$1")
                    .bind(&activity_id)
                    .fetch_one(&mut *tx)
                    .await?;
                let mut payload = Self::activity_payload(&updated)?;
                for (key,value) in json!({"activity_attempt_id":current,"attempt_number":count,"failure_id":failure_id,"failure_category":"timeout","timeout_kind":kind,"message":message,"exception_class":CLASS}).as_object().unwrap() {payload[key]=value.clone();}
                for field in [
                    "schedule_deadline_at",
                    "close_deadline_at",
                    "schedule_to_close_deadline_at",
                    "heartbeat_deadline_at",
                ] {
                    payload[field] = json!(
                        DB::optional_instant(&activity, field)?
                            .map(|at| at.to_rfc3339_opts(chrono::SecondsFormat::Secs, false))
                    );
                }
                Self::append(
                    &mut tx,
                    &run_id,
                    "ActivityTimedOut",
                    payload,
                    task_id.as_deref(),
                    None,
                )
                .await?;
                let metadata = super::selections::activity_metadata(DB::optional_document_row(
                    &activity,
                    "parallel_group_path",
                )?)?;
                Self::resolve_selection(
                    &mut tx,
                    &run_id,
                    &metadata,
                    "activity",
                    &activity_id,
                    "ActivityTimedOut",
                )
                .await?;
                Self::selection_resume(
                    &mut tx,
                    &run_id,
                    &DB::string(&run, "queue")?,
                    json!({"activity_execution_id":activity_id}),
                    &metadata,
                )
                .await?;
            }
            tx.commit().await?;
            self.wake.notify_waiters();
        }
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn original_deadline_boundary_is_inclusive_and_precedence_is_stable() {
        let pool = sqlx::SqlitePool::connect("sqlite::memory:").await.unwrap();
        let at = DateTime::parse_from_rfc3339("2020-01-02T03:04:05.123456Z")
            .unwrap()
            .with_timezone(&Utc);
        let row = sqlx::query("SELECT 'running' AS status, '2020-01-02 03:04:05.123456' AS schedule_to_close_deadline_at, NULL AS schedule_deadline_at, '2020-01-02 03:04:05.123456' AS heartbeat_deadline_at, '2020-01-02 03:04:05.123456' AS close_deadline_at").fetch_one(&pool).await.unwrap();
        assert_eq!(
            expired_kind::<sqlx::Sqlite>(&row, at - chrono::Duration::microseconds(1)).unwrap(),
            None
        );
        assert_eq!(
            expired_kind::<sqlx::Sqlite>(&row, at).unwrap(),
            Some("schedule_to_close")
        );
        let row = sqlx::query("SELECT 'running' AS status, NULL AS schedule_to_close_deadline_at, NULL AS schedule_deadline_at, '2020-01-02 03:04:05.123456' AS heartbeat_deadline_at, '2020-01-02 03:04:05.123456' AS close_deadline_at").fetch_one(&pool).await.unwrap();
        assert_eq!(
            expired_kind::<sqlx::Sqlite>(&row, at).unwrap(),
            Some("heartbeat")
        );
        pool.close().await;
    }

    #[test]
    fn supplied_timeouts_preserve_the_full_policy_and_refuse_invalid_bounds() {
        let command = json!({"retry_policy":{"max_attempts":1,"backoff_seconds":[1,2,5,10,15,30,60,120]},"start_to_close_timeout":120,"schedule_to_close_timeout":600});
        let policy = super::super::activity_failures::retry_policy(&command)
            .unwrap()
            .unwrap();
        assert_eq!(
            policy["backoff_seconds"],
            command["retry_policy"]["backoff_seconds"]
        );
        assert_eq!(policy["start_to_close_timeout"], 120);
        assert_eq!(policy["schedule_to_close_timeout"], 600);
        validate_policy(&policy).unwrap();
        for field in TIMEOUT_FIELDS {
            for invalid in [json!(0), json!(-1), json!(1.5), json!("1"), json!(i64::MAX)] {
                let mut bad = command.clone();
                bad[field] = invalid;
                assert!(super::super::activity_failures::retry_policy(&bad).is_err());
            }
        }
        assert!(
            super::super::activity_failures::retry_policy(&json!({}))
                .unwrap()
                .is_none()
        );
        let policy = super::super::activity_failures::retry_policy(&json!({"heartbeat_timeout":1}))
            .unwrap()
            .unwrap();
        assert_eq!(policy["max_attempts"], 1);
        assert_eq!(policy["heartbeat_timeout"], 1);
    }
}
