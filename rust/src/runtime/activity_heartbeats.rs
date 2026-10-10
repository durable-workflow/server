//! Fenced heartbeat progress used by real cleanup activities.
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
    pub(crate) async fn activity_status(
        &self,
        task_id: &str,
        body: Value,
        protocol: &str,
    ) -> Result<Value> {
        if !super::cooperative::supports(&json!(["cooperative_cancellation"]), protocol) {
            return Err(super::RuntimeError::Protocol {
                status: StatusCode::UNPROCESSABLE_ENTITY,
                response: json!({"reason":"activity_attempt_status_unavailable","minimum_protocol_version":"1.20"}),
            });
        }
        reject_fields(&body, &["activity_attempt_id", "lease_owner"])?;
        let attempt_id = text(&body, "activity_attempt_id")?;
        let owner = text(&body, "lease_owner")?;
        let mut tx = self.begin().await?;
        let task = Self::task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "activity" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        let attempt = Self::query("SELECT a.* FROM activity_attempts a JOIN workflow_tasks t ON t.id=a.workflow_task_id WHERE a.id=$1 AND t.namespace='default'")
            .bind(attempt_id).fetch_optional(&mut *tx).await?
            .ok_or_else(|| refuse(StatusCode::NOT_FOUND,"attempt_not_found"))?;
        if DB::string(&attempt, "workflow_task_id")? != task_id {
            return Err(refuse(StatusCode::CONFLICT, "task_mismatch"));
        }
        if DB::optional_string(&attempt, "lease_owner")?.as_deref() != Some(owner) {
            return Err(refuse(StatusCode::CONFLICT, "lease_owner_mismatch"));
        }
        let run_id = DB::string(&task, "workflow_run_id")?;
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1")
            .bind(&run_id)
            .fetch_one(&mut *tx)
            .await?;
        let activity =
            Self::query("SELECT * FROM activity_executions WHERE id=$1 AND workflow_run_id=$2")
                .bind(DB::string(&attempt, "activity_execution_id")?)
                .bind(&run_id)
                .fetch_one(&mut *tx)
                .await?;
        let run_status = DB::string(&run, "status")?;
        let activity_status = DB::string(&activity, "status")?;
        let attempt_status = DB::string(&attempt, "status")?;
        let task_status = DB::string(&task, "status")?;
        let expires = DB::optional_instant(&attempt, "lease_expires_at")?
            .or(DB::optional_instant(&task, "lease_expires_at")?);
        let (can_continue, cancel_requested, reason) = if run_status == "cancelled" {
            (false, true, Some("run_cancelled"))
        } else if run_status == "terminated" {
            (false, true, Some("run_terminated"))
        } else if attempt_status != "running" {
            (false, attempt_status == "cancelled", Some("attempt_closed"))
        } else if activity_status == "cancelled" {
            (false, true, Some("activity_cancelled"))
        } else if task_status == "cancelled" {
            (false, true, Some("task_cancelled"))
        } else if !matches!(run_status.as_str(), "pending" | "running" | "waiting") {
            (false, false, Some("run_closed"))
        } else if activity_status != "running" {
            (false, false, Some("activity_not_running"))
        } else if DB::optional_string(&activity, "current_attempt_id")?.as_deref()
            != Some(attempt_id)
            || DB::number(&activity, "attempt_count")? != DB::number(&attempt, "attempt_number")?
        {
            (false, false, Some("stale_attempt"))
        } else if DB::number(&task, "attempt_count")? != DB::number(&attempt, "attempt_number")? {
            (false, false, Some("stale_task"))
        } else if task_status != "leased" {
            (false, false, Some("task_not_leased"))
        } else if expires.is_none_or(|expires| expires <= now())
            || DB::optional_instant(&task, "lease_expires_at")?
                .is_none_or(|expires| expires <= now())
        {
            (false, false, Some("lease_expired"))
        } else {
            (true, false, None)
        };
        // Observation preserves the existing lease and never records progress.
        let response = json!({"task_id":task_id,"activity_attempt_id":attempt_id,"lease_owner":owner,
            "can_continue":can_continue,"cancel_requested":cancel_requested,"reason":reason,"heartbeat_recorded":false,
            "lease_expires_at":expires,"last_heartbeat_at":DB::optional_instant(&attempt,"last_heartbeat_at")?.or(DB::optional_instant(&activity,"last_heartbeat_at")?),
            "run_status":run_status,"run_closed_reason":DB::optional_string(&run,"closed_reason")?,"run_closed_at":DB::optional_instant(&run,"closed_at")?,
            "activity_status":activity_status,"attempt_status":attempt_status,"task_status":task_status,
            "deadlines":null,"worker_session":null,"protocol_version":protocol,"server_capabilities":super::store::capabilities()});
        tx.commit().await?;
        Ok(response)
    }

    pub(crate) async fn heartbeat_activity(&self, task_id: &str, body: Value) -> Result<Value> {
        reject_fields(
            &body,
            &[
                "activity_attempt_id",
                "lease_owner",
                "message",
                "current",
                "total",
                "unit",
                "details",
            ],
        )?;
        let attempt_id = text(&body, "activity_attempt_id")?;
        let mut progress = json!({});
        for field in ["message", "current", "total", "unit", "details"] {
            if let Some(value) = body.get(field).filter(|value| !value.is_null()) {
                let valid = match field {
                    "message" | "unit" => value.is_string(),
                    "current" | "total" => value
                        .as_f64()
                        .or_else(|| value.as_str().and_then(|value| value.parse::<f64>().ok()))
                        .is_some_and(f64::is_finite),
                    _ => value.is_object() || value.is_array(),
                };
                if !valid {
                    return Err(refuse(
                        StatusCode::UNPROCESSABLE_ENTITY,
                        "invalid_heartbeat_progress",
                    ));
                }
                progress[field] = value.clone();
            }
        }
        let mut tx = self.begin().await?;
        let task = Self::task_row(&mut tx, task_id).await?;
        if DB::string(&task, "task_type")? != "activity" {
            return Err(refuse(StatusCode::NOT_FOUND, "task_not_found"));
        }
        Self::cancelled_activity_outcome(&mut tx, &task, &body).await?;
        Self::fence(&task, &body)?;
        let attempt =
            Self::query("SELECT * FROM activity_attempts WHERE id=$1 AND workflow_task_id=$2")
                .bind(attempt_id)
                .bind(task_id)
                .fetch_optional(&mut *tx)
                .await?
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "stale_attempt"))?;
        if DB::string(&attempt, "status")? != "running"
            || DB::number(&attempt, "attempt_number")? != DB::number(&task, "attempt_count")?
        {
            return Err(refuse(StatusCode::CONFLICT, "stale_attempt"));
        }
        let run_id = DB::string(&task, "workflow_run_id")?;
        let run = Self::active_run(&mut tx, &run_id).await?;
        let activity_id = DB::string(&attempt, "activity_execution_id")?;
        let activity =
            Self::query("SELECT * FROM activity_executions WHERE id=$1 AND workflow_run_id=$2")
                .bind(&activity_id)
                .bind(&run_id)
                .fetch_one(&mut *tx)
                .await?;
        if DB::string(&activity, "status")? != "running"
            || DB::optional_string(&activity, "current_attempt_id")?.as_deref() != Some(attempt_id)
        {
            return Err(refuse(StatusCode::CONFLICT, "stale_attempt"));
        }
        let at = now();
        let expires = Self::cancellation_lease_expiry(&run)?;
        Self::query(
            "UPDATE activity_attempts SET last_heartbeat_at=$1,lease_expires_at=$2 WHERE id=$3",
        )
        .bind(DB::bind_time(at))
        .bind(DB::bind_time(expires))
        .bind(attempt_id)
        .execute(&mut *tx)
        .await?;
        Self::query("UPDATE activity_executions SET last_heartbeat_at=$1 WHERE id=$2")
            .bind(DB::bind_time(at))
            .bind(&activity_id)
            .execute(&mut *tx)
            .await?;
        Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2")
            .bind(DB::bind_time(expires))
            .bind(task_id)
            .execute(&mut *tx)
            .await?;
        Self::query("UPDATE workflow_runs SET last_progress_at=$1 WHERE id=$2")
            .bind(DB::bind_time(at))
            .bind(&run_id)
            .execute(&mut *tx)
            .await?;
        let mut payload = Self::activity_payload(&activity)?;
        payload["activity_attempt_id"] = json!(attempt_id);
        payload["attempt_number"] = json!(DB::number(&attempt, "attempt_number")?);
        payload["progress"] = progress;
        payload["heartbeat_at"] = json!(at);
        payload["lease_expires_at"] = json!(expires);
        payload["activity_attempt"] = json!({"id":attempt_id,"attempt_number":DB::number(&attempt,"attempt_number")?,
            "status":"running","task_id":task_id,"lease_owner":body["lease_owner"],"last_heartbeat_at":at,
            "lease_expires_at":expires,"started_at":DB::instant(&attempt,"started_at")?});
        Self::append(
            &mut tx,
            &run_id,
            "ActivityHeartbeatRecorded",
            payload,
            Some(task_id),
            None,
        )
        .await?;
        tx.commit().await?;
        Ok(
            json!({"task_id":task_id,"activity_attempt_id":attempt_id,"lease_owner":body["lease_owner"],
            "heartbeat_recorded":true,"last_heartbeat_at":at,"lease_expires_at":expires,"cancel_requested":false,"can_continue":true}),
        )
    }
}
