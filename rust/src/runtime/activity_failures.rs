//! Reported application failure followed by a durable activity retry.
//! Terminal/non-retryable failure and timeout policies remain explicit gates.
use super::{
    Result,
    backend::Backend,
    refuse,
    store::{Store, now, reject_fields},
    text,
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};
use std::sync::Arc;
use tokio::sync::Semaphore;

pub(super) fn retry_policy(command: &Value) -> Result<Option<Value>> {
    let Some(policy) = command.get("retry_policy").filter(|v| !v.is_null()) else {
        return Ok(None);
    };
    reject_fields(
        policy,
        &[
            "max_attempts",
            "backoff_seconds",
            "non_retryable_error_types",
        ],
    )?;
    let max = match policy.get("max_attempts").filter(|v| !v.is_null()) {
        Some(value) => value
            .as_i64()
            .filter(|n| *n > 0 && *n <= i32::MAX as i64)
            .ok_or_else(|| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_activity_retry_policy",
                )
            })?,
        None => 1,
    };
    let backoff = match policy.get("backoff_seconds").filter(|v| !v.is_null()) {
        Some(value) => value
            .as_array()
            .filter(|values| values.len() <= 1000)
            .ok_or_else(|| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_activity_retry_policy",
                )
            })?
            .clone(),
        None => vec![],
    };
    for value in &backoff {
        value
            .as_i64()
            .filter(|n| *n >= 0)
            .and_then(chrono::Duration::try_seconds)
            .and_then(|duration| now().checked_add_signed(duration))
            .ok_or_else(|| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_activity_retry_policy",
                )
            })?;
    }
    // Error filters require the terminal failure transition, not just retries.
    let filters = policy
        .get("non_retryable_error_types")
        .filter(|v| !v.is_null());
    if filters.is_some_and(|value| value.as_array().is_none_or(|list| !list.is_empty())) {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "terminal_activity_failure_not_available",
        ));
    }
    Ok(Some(
        json!({"snapshot_version":1,"max_attempts":max,"backoff_seconds":backoff,
        "non_retryable_error_types":[],"start_to_close_timeout":null,"schedule_to_start_timeout":null,
        "schedule_to_close_timeout":null,"heartbeat_timeout":null}),
    ))
}

pub(super) async fn prepare_failure(
    body: &Value,
    semaphore: Arc<Semaphore>,
) -> Result<(Value, String)> {
    reject_fields(body, &["lease_owner", "activity_attempt_id", "failure"])?;
    text(body, "lease_owner")?;
    text(body, "activity_attempt_id")?;
    let failure = &body["failure"];
    reject_fields(failure, &["type", "message", "code", "non_retryable"])?;
    let kind = text(failure, "type")?.to_owned();
    let message = failure["message"]
        .as_str()
        .filter(|s| !s.is_empty() && s.len() <= 64 * 1024)
        .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_activity_failure"))?
        .to_owned();
    let code = match failure.get("code") {
        Some(value) => value
            .as_i64()
            .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_activity_failure"))?,
        None => 0,
    };
    let non_retryable = match failure.get("non_retryable") {
        Some(value) => value
            .as_bool()
            .ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_activity_failure"))?,
        None => false,
    };
    let payload = json!({"type":kind,"message":message,"code":code,"non_retryable":non_retryable});
    let permit = semaphore
        .acquire_owned()
        .await
        .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "failure_codec_unavailable"))?;
    let blob = tokio::task::spawn_blocking(move || {
        let _permit = permit;
        use crate::codec::{Value as Datum, ValueCodec};
        let value = Datum::Map(std::collections::BTreeMap::from([
            ("type".into(), Datum::String(kind)),
            ("message".into(), Datum::String(message)),
            ("code".into(), Datum::Long(code)),
            ("non_retryable".into(), Datum::Boolean(non_retryable)),
        ]));
        ValueCodec::new()
            .and_then(|codec| codec.encode(&value))
            .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_activity_failure"))
    })
    .await
    .map_err(|_| refuse(StatusCode::SERVICE_UNAVAILABLE, "failure_codec_unavailable"))??;
    Ok((payload, blob))
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
    pub(crate) async fn fail_activity(
        &self,
        task_id: &str,
        body: Value,
        failure: Value,
        blob: String,
    ) -> Result<Value> {
        let attempt_id = text(&body, "activity_attempt_id")?;
        let mut tx = self.begin().await?;
        let task = Self::task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "activity" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        if Self::duplicate_receipt(&task, &body)? {
            return Ok(
                json!({"task_id":task_id,"activity_attempt_id":attempt_id,"outcome":"failed","recorded":false,"reason":"already_completed"}),
            );
        }
        Self::fence(&task, &body)?;
        let attempt =
            Self::query("SELECT * FROM activity_attempts WHERE id=$1 AND workflow_task_id=$2")
                .bind(attempt_id)
                .bind(task_id)
                .fetch_optional(&mut *tx)
                .await?
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "stale_attempt"))?;
        let count = DB::number(&attempt, "attempt_number")?;
        let run_id = DB::string(&task, "workflow_run_id")?;
        Self::active_run(&mut tx, &run_id).await?;
        let activity_id = DB::string(&attempt, "activity_execution_id")?;
        let activity =
            Self::query("SELECT * FROM activity_executions WHERE id=$1 AND workflow_run_id=$2")
                .bind(&activity_id)
                .bind(&run_id)
                .fetch_one(&mut *tx)
                .await?;
        if DB::string(&attempt, "status")? != "running"
            || count < 1
            || count != DB::number(&task, "attempt_count")?
            || DB::string(&attempt, "workflow_run_id")? != run_id
            || DB::optional_string(&activity, "current_attempt_id")?.as_deref() != Some(attempt_id)
            || DB::number(&activity, "attempt_count")? != count
            || DB::string(&activity, "status")? != "running"
        {
            return Err(refuse(StatusCode::CONFLICT, "stale_attempt"));
        }
        let policy = DB::optional_document_row(&activity, "retry_policy")?.unwrap_or(Value::Null);
        if !policy.is_null() {
            let normalized = retry_policy(
                &json!({"retry_policy":{"max_attempts":policy["max_attempts"],
                "backoff_seconds":policy["backoff_seconds"],"non_retryable_error_types":policy["non_retryable_error_types"]}}),
            )?;
            if normalized.as_ref() != Some(&policy) {
                return Err(refuse(
                    StatusCode::CONFLICT,
                    "invalid_stored_activity_retry_policy",
                ));
            }
        }
        let max = policy["max_attempts"].as_i64().unwrap_or(1);
        if failure["non_retryable"] == true || count >= max {
            return Err(refuse(
                StatusCode::UNPROCESSABLE_ENTITY,
                "terminal_activity_failure_not_available",
            ));
        }
        let backoff = policy["backoff_seconds"]
            .as_array()
            .and_then(|values| values.get((count - 1) as usize).or_else(|| values.last()))
            .and_then(Value::as_i64)
            .unwrap_or(0);
        let closed_at = now();
        let available = chrono::Duration::try_seconds(backoff)
            .and_then(|duration| closed_at.checked_add_signed(duration))
            .ok_or_else(|| {
                refuse(
                    StatusCode::UNPROCESSABLE_ENTITY,
                    "invalid_activity_retry_policy",
                )
            })?;
        Self::query("UPDATE activity_attempts SET status='failed',closed_at=$1 WHERE id=$2")
            .bind(DB::bind_time(closed_at))
            .bind(attempt_id)
            .execute(&mut *tx)
            .await?;
        Self::query("UPDATE activity_executions SET status='pending',exception=$1,last_heartbeat_at=NULL WHERE id=$2")
            .bind(blob).bind(&activity_id).execute(&mut *tx).await?;
        Self::query(
            "UPDATE workflow_tasks SET status='completed',lease_expires_at=NULL WHERE id=$1",
        )
        .bind(task_id)
        .execute(&mut *tx)
        .await?;
        Self::record_completion(&mut tx, task_id, &body).await?;
        let retry_task = Self::create_task(&mut tx,&run_id,&DB::string(&activity,"queue")?,"activity",
            json!({"activity_execution_id":activity_id,"retry_of_task_id":task_id,"retry_after_attempt_id":attempt_id,
                "retry_after_attempt":count,"retry_backoff_seconds":backoff,"max_attempts":max,"retry_policy":policy})).await?;
        Self::query("UPDATE workflow_tasks SET available_at=$1,attempt_count=$2 WHERE id=$3")
            .bind(DB::bind_time(available))
            .bind(count)
            .bind(&retry_task)
            .execute(&mut *tx)
            .await?;
        let updated = Self::query("SELECT * FROM activity_executions WHERE id=$1")
            .bind(&activity_id)
            .fetch_one(&mut *tx)
            .await?;
        let mut payload = Self::activity_payload(&updated)?;
        payload["activity_attempt_id"] = json!(attempt_id);
        payload["retry_task_id"] = json!(retry_task);
        payload["retry_of_task_id"] = json!(task_id);
        payload["retry_available_at"] = json!(available);
        payload["retry_backoff_seconds"] = json!(backoff);
        payload["retry_after_attempt_id"] = json!(attempt_id);
        payload["retry_after_attempt"] = json!(count);
        payload["max_attempts"] = json!(max);
        payload["retry_policy"] = policy;
        payload["exception_type"] = failure["type"].clone();
        payload["exception_class"] = failure["type"].clone();
        payload["message"] = failure["message"].clone();
        payload["code"] = failure["code"].clone();
        payload["exception"] = failure;
        payload["activity_attempt"] = json!({"id":attempt_id,"activity_execution_id":activity_id,"task_id":task_id,
            "status":"failed","lease_owner":DB::optional_string(&task,"lease_owner")?,"closed_at":closed_at});
        Self::append(
            &mut tx,
            &run_id,
            "ActivityRetryScheduled",
            payload,
            Some(task_id),
            None,
        )
        .await?;
        // No workflow resumption until the activity has a terminal result.
        tx.commit().await?;
        self.wake.notify_waiters();
        Ok(
            json!({"task_id":task_id,"activity_attempt_id":attempt_id,"outcome":"failed","recorded":true,"reason":null,"next_task_id":retry_task}),
        )
    }
}
