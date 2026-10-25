//! Runtime activity lease renewal, as published Server 2.11 serves it
//! (`ActivityTaskController::renewLease` over Workflow 2.10's
//! `ActivityLeaseRenewal`). Renewal keeps a live attempt's lease for its
//! original owner. It never records progress, moves a business deadline or
//! adds history, and an expired lease cannot be revived.
use super::laravel_rules::{self, Rule};
use super::transaction::Transaction;
use super::{
    Result, RuntimeError,
    store::{capabilities, now},
};
use axum::http::StatusCode;
use chrono::{DateTime, SecondsFormat, Utc};
use serde_json::{Value, json};

/// Published PHP's validation rules for the request, in their order.
const RULES: &laravel_rules::Rules = &[
    ("activity_attempt_id", &[Rule::Required, Rule::String]),
    ("lease_owner", &[Rule::Required, Rule::String]),
];

/// A worker-protocol response with PHP's protocol fields.
pub(super) fn worker(status: StatusCode, mut response: Value) -> RuntimeError {
    response["protocol_version"] = json!(super::WORKER_PROTOCOL_VERSION);
    response["server_capabilities"] = capabilities();
    RuntimeError::Protocol { status, response }
}

/// PHP's `outcomeStatus`.
fn outcome_status(reason: Option<&str>) -> StatusCode {
    match reason {
        None => StatusCode::OK,
        Some("attempt_not_found") => StatusCode::NOT_FOUND,
        Some(_) => StatusCode::CONFLICT,
    }
}

pub(super) fn micros(at: DateTime<Utc>) -> String {
    at.to_rfc3339_opts(SecondsFormat::Micros, true)
}

/// The rows an attempt's authority is read from.
pub(super) struct Rows<R> {
    pub(super) attempt: R,
    pub(super) activity: Option<R>,
    pub(super) run: Option<R>,
    pub(super) task: Option<R>,
}

store_methods! {
    pub(crate) async fn renew_activity_lease(&self, task_id: &str, body: Value) -> Result<Value> {
        let body = laravel_rules::normalize(&body);
        if let Err(RuntimeError::Protocol { status, response }) = laravel_rules::validate(&body, RULES) {
            return Err(worker(status, response));
        }
        let attempt_id = body["activity_attempt_id"].as_str().unwrap_or_default();
        let owner = body["lease_owner"].as_str().unwrap_or_default();
        let refusal = |status, error: &str, reason: &str| {
            worker(status, json!({"task_id":task_id,"activity_attempt_id":attempt_id,"error":error,"reason":reason}))
        };
        let mut tx = self.begin().await?;
        // PHP's ownership guard, in its order: the task in this namespace,
        // then the attempt by its identity, its task and its owner.
        let task = Self::query("SELECT id FROM workflow_tasks WHERE id=$1 AND namespace=$2")
            .bind(task_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&mut *tx)
            .await?
            .ok_or_else(|| refusal(StatusCode::NOT_FOUND, "Activity task not found.", "task_not_found"))?;
        let Some(rows) = Self::attempt_rows(&mut tx, attempt_id).await? else {
            return Err(refusal(StatusCode::NOT_FOUND, "Activity attempt not found.", "attempt_not_found"));
        };
        let observed = Self::attempt_status(attempt_id, &rows, None)?;
        if observed["workflow_task_id"].as_str() != Some(DB::string(&task, "id")?.as_str()) {
            return Err(refusal(StatusCode::CONFLICT, "Activity attempt is not leased for this task.", "task_mismatch"));
        }
        if observed["lease_owner"].as_str() != Some(owner) {
            let mut error = json!({"task_id":task_id,"activity_attempt_id":attempt_id,
                "error":"Activity attempt lease is owned by another worker.","reason":"lease_owner_mismatch"});
            error["lease_owner"] = observed["lease_owner"].clone();
            return Err(worker(StatusCode::CONFLICT, error));
        }
        if observed["can_continue"] != true {
            let mut response = observed;
            response["lease_renewed"] = json!(false);
            return Err(worker(outcome_status(response["reason"].as_str()), response));
        }
        // The renewal itself requires the owner of both the attempt and its
        // task, as PHP's observation with an owner does.
        let leased_by_owner = DB::optional_string(&rows.attempt, "lease_owner")?.as_deref() == Some(owner)
            && match &rows.task {
                Some(task) => DB::optional_string(task, "lease_owner")?.as_deref() == Some(owner),
                None => false,
            };
        let at = now();
        let mut status = if leased_by_owner {
            let task = rows.task.as_ref().expect("task checked above");
            let mut expires = at + chrono::Duration::seconds(self.activity_task_lease_seconds);
            if let Some(current) = DB::optional_instant(task, "lease_expires_at")?.filter(|current| *current > expires) {
                expires = current;
            }
            Self::query("UPDATE activity_attempts SET lease_expires_at=$1 WHERE id=$2")
                .bind(DB::bind_time(expires))
                .bind(attempt_id)
                .execute(&mut *tx)
                .await?;
            Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2")
                .bind(DB::bind_time(expires))
                .bind(DB::string(task, "id")?)
                .execute(&mut *tx)
                .await?;
            let renewed = Self::attempt_rows(&mut tx, attempt_id).await?.expect("attempt read above");
            Self::attempt_status(attempt_id, &renewed, None)?
        } else {
            Self::attempt_status(attempt_id, &rows, Some("lease_owner_mismatch"))?
        };
        tx.commit().await?;
        status["lease_renewed"] = status["can_continue"].clone();
        status["server_time"] = json!(micros(at));
        status["task_id"] = json!(task_id);
        status["worker_session"] = Value::Null;
        let code = outcome_status(status["reason"].as_str());
        if code != StatusCode::OK {
            return Err(worker(code, status));
        }
        status["protocol_version"] = json!(super::WORKER_PROTOCOL_VERSION);
        status["server_capabilities"] = capabilities();
        Ok(status)
    }

    /// `RuntimePayloadCompletionLease::mayResume` for an activity: its task
    /// is still leased to the owner and its attempt can continue on that task
    /// for that owner. An expired lease alone does not end it, since the
    /// activity may still renew before lease recovery.
    pub(super) async fn activity_completion_may_resume(
        &self,
        tx: &mut Transaction<'_, DB>,
        task_id: &str,
        attempt_id: &str,
        owner: &str,
    ) -> Result<bool> {
        let Some(task) = Self::query("SELECT status,lease_owner FROM workflow_tasks WHERE id=$1 AND namespace=$2")
            .bind(task_id)
            .bind(self.namespace.as_ref())
            .fetch_optional(&mut **tx)
            .await?
        else {
            return Ok(false);
        };
        if DB::string(&task, "status")? != "leased" || DB::optional_string(&task, "lease_owner")?.as_deref() != Some(owner) {
            return Ok(false);
        }
        let Some(rows) = Self::attempt_rows(tx, attempt_id).await? else {
            return Ok(false);
        };
        let status = Self::attempt_status(attempt_id, &rows, None)?;
        Ok(status["can_continue"] == true && status["workflow_task_id"] == task_id && status["lease_owner"] == owner)
    }

    pub(super) async fn attempt_rows(tx: &mut Transaction<'_, DB>, attempt_id: &str) -> Result<Option<Rows<DB::Row>>> {
        let Some(attempt) = Self::query("SELECT * FROM activity_attempts WHERE id=$1")
            .bind(attempt_id)
            .fetch_optional(&mut **tx)
            .await?
        else {
            return Ok(None);
        };
        let activity = Self::query("SELECT * FROM activity_executions WHERE id=$1")
            .bind(DB::string(&attempt, "activity_execution_id")?)
            .fetch_optional(&mut **tx)
            .await?;
        let run = match &activity {
            Some(activity) => Self::query("SELECT * FROM workflow_runs WHERE id=$1")
                .bind(DB::string(activity, "workflow_run_id")?)
                .fetch_optional(&mut **tx)
                .await?,
            None => None,
        };
        let task = match DB::optional_string(&attempt, "workflow_task_id")?.filter(|id| !id.is_empty()) {
            Some(id) => Self::query("SELECT * FROM workflow_tasks WHERE id=$1")
                .bind(id)
                .fetch_optional(&mut **tx)
                .await?,
            None => None,
        };
        Ok(Some(Rows { attempt, activity, run, task }))
    }

    /// Workflow's `attemptStatus` over its continuation state. `refused`
    /// replaces the state with a refusal that cannot continue.
    pub(super) fn attempt_status(attempt_id: &str, rows: &Rows<DB::Row>, refused: Option<&'static str>) -> Result<Value> {
        let (can_continue, cancel_requested, reason) = match refused {
            Some(reason) => (false, false, Some(reason)),
            None => Self::continuation(rows, now())?,
        };
        let text = |row: Option<&DB::Row>, column: &str| -> Result<Option<String>> {
            Ok(match row {
                Some(row) => DB::optional_string(row, column)?.filter(|value| !value.is_empty()),
                None => None,
            })
        };
        let instant = |row: Option<&DB::Row>, column: &str| -> Result<Option<DateTime<Utc>>> {
            Ok(match row {
                Some(row) => DB::optional_instant(row, column)?,
                None => None,
            })
        };
        let attempt = Some(&rows.attempt);
        let (activity, run, task) = (rows.activity.as_ref(), rows.run.as_ref(), rows.task.as_ref());
        let first = |values: [Option<String>; 2]| values.into_iter().flatten().next();
        let time = |values: [Option<DateTime<Utc>>; 2]| values.into_iter().flatten().next().map(micros);
        Ok(json!({
            "can_continue": can_continue,
            "cancel_requested": cancel_requested,
            "reason": reason,
            "heartbeat_recorded": false,
            "workflow_instance_id": text(run, "workflow_instance_id")?,
            "workflow_run_id": first([text(run, "id")?, first([text(activity, "workflow_run_id")?, text(attempt, "workflow_run_id")?])]),
            "workflow_task_id": first([text(task, "id")?, text(attempt, "workflow_task_id")?]),
            "activity_execution_id": first([text(activity, "id")?, text(attempt, "activity_execution_id")?]),
            "activity_attempt_id": attempt_id,
            "attempt_number": DB::number(&rows.attempt, "attempt_number").ok(),
            "run_status": text(run, "status")?,
            "run_closed_reason": text(run, "closed_reason")?,
            "run_closed_at": time([instant(run, "closed_at")?, None]),
            "activity_status": text(activity, "status")?,
            "attempt_status": text(attempt, "status")?,
            "task_status": text(task, "status")?,
            "lease_owner": first([text(attempt, "lease_owner")?, text(task, "lease_owner")?]),
            "lease_expires_at": time([instant(attempt, "lease_expires_at")?, instant(task, "lease_expires_at")?]),
            "last_heartbeat_at": time([instant(attempt, "last_heartbeat_at")?, instant(activity, "last_heartbeat_at")?]),
            "heartbeat_deadline_at": time([instant(activity, "heartbeat_deadline_at")?, None]),
        }))
    }

    /// Workflow's `attemptContinuationState`: whether the attempt may keep
    /// running, whether it was asked to stop, and why not.
    fn continuation(rows: &Rows<DB::Row>, at: DateTime<Utc>) -> Result<(bool, bool, Option<&'static str>)> {
        let attempt_status = DB::string(&rows.attempt, "status")?;
        let run_status = match &rows.run {
            Some(run) => Some(DB::string(run, "status")?),
            None => None,
        };
        if attempt_status != "running" {
            return Ok(match run_status.as_deref() {
                Some("cancelled") => (false, true, Some("run_cancelled")),
                Some("terminated") => (false, true, Some("run_terminated")),
                _ => (false, attempt_status == "cancelled", Some("attempt_closed")),
            });
        }
        let Some(activity) = &rows.activity else {
            return Ok((false, false, Some("activity_execution_missing")));
        };
        let (Some(run), Some(run_status)) = (&rows.run, run_status) else {
            return Ok((false, false, Some("workflow_run_missing")));
        };
        let activity_status = DB::string(activity, "status")?;
        let task_status = match &rows.task {
            Some(task) => Some(DB::string(task, "status")?),
            None => None,
        };
        match run_status.as_str() {
            "cancelled" => return Ok((false, true, Some("run_cancelled"))),
            "terminated" => return Ok((false, true, Some("run_terminated"))),
            _ => {}
        }
        if activity_status == "cancelled" {
            return Ok((false, true, Some("activity_cancelled")));
        }
        if task_status.as_deref() == Some("cancelled") {
            return Ok((false, true, Some("task_cancelled")));
        }
        if !matches!(run_status.as_str(), "pending" | "running" | "waiting") {
            return Ok((false, false, Some("run_closed")));
        }
        if activity_status != "running" {
            return Ok((false, false, Some("activity_not_running")));
        }
        if DB::optional_string(activity, "current_attempt_id")?.as_deref() != Some(DB::string(&rows.attempt, "id")?.as_str())
            || DB::number(activity, "attempt_count")? != DB::number(&rows.attempt, "attempt_number")?
        {
            return Ok((false, false, Some("stale_attempt")));
        }
        let Some(task) = &rows.task else {
            return Ok((false, false, Some("task_not_found")));
        };
        let payload = DB::optional_document_row(task, "payload")?.unwrap_or(Value::Null);
        if DB::string(task, "workflow_run_id")? != DB::string(activity, "workflow_run_id")?
            || payload["activity_execution_id"].as_str() != Some(DB::string(activity, "id")?.as_str())
            || DB::number(task, "attempt_count")? != DB::number(&rows.attempt, "attempt_number")?
        {
            return Ok((false, false, Some("stale_task")));
        }
        if task_status.as_deref() != Some("leased") {
            return Ok((false, false, Some("task_not_leased")));
        }
        // `ActivityAttemptAuthority::expiredReason`: the task's lease, then
        // the activity's business deadlines, then the run's.
        if DB::optional_instant(task, "lease_expires_at")?.is_none_or(|expires| at >= expires) {
            return Ok((false, false, Some("lease_expired")));
        }
        if let Some(kind) = super::activity_deadlines::expired_kind::<DB>(activity, at)? {
            return Ok((false, false, Some(super::activity_deadlines::timeout_reason(kind))));
        }
        for (reason, column) in [("run_timeout", "run_deadline_at"), ("execution_timeout", "execution_deadline_at")] {
            if DB::optional_instant(run, column)?.is_some_and(|deadline| at >= deadline) {
                return Ok((false, false, Some(reason)));
            }
        }
        Ok((true, false, None))
    }
}
