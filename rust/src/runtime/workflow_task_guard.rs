//! PHP's workflow task ownership guard (WorkflowTaskOwnership::guard) and the
//! refusal bodies WorkerController renders from it. A worker reads these
//! fields, not just the reason, to tell that its claim was lost: the task's
//! status, whether the run can continue, and who holds the lease now.
use super::{
    Result, RuntimeError,
    backend::Backend,
    query_authority::wire,
    store::{Store, now},
    transaction::Transaction,
};
use axum::http::StatusCode;
use serde_json::{Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

fn refused(
    task_id: &str,
    attempt: &Value,
    error: &str,
    reason: &str,
    fields: Value,
) -> RuntimeError {
    let mut response = json!({"task_id":task_id,"workflow_task_attempt":attempt,"error":error,"reason":reason,
        "protocol_version":"1.20","server_capabilities":super::store::capabilities()});
    if let (Some(response), Value::Object(fields)) = (response.as_object_mut(), fields) {
        response.extend(fields);
    }
    RuntimeError::Protocol {
        status: StatusCode::CONFLICT,
        response,
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
    /// The checks run in PHP's order: a task that is no longer leased first
    /// (run_closed when its run has closed), then expiry, owner and attempt.
    pub(super) async fn workflow_task_guard(
        tx: &mut Transaction<'_, DB>,
        task: &DB::Row,
        body: &Value,
    ) -> Result<()> {
        let lease_owner = super::text(body, "lease_owner")?;
        let task_id = DB::string(task, "id")?;
        let attempt = body["workflow_task_attempt"]
            .as_i64()
            .map_or(Value::Null, Value::from);
        let status = DB::string(task, "status")?;
        let owner = DB::optional_string(task, "lease_owner")?
            .map(|owner| owner.trim().to_owned())
            .filter(|owner| !owner.is_empty());
        let expires = DB::optional_instant(task, "lease_expires_at")?;
        let expires_wire = expires.map(wire);
        if status != "leased" {
            let run =
                Self::query("SELECT status,closed_reason,closed_at FROM workflow_runs WHERE id=$1")
                    .bind(DB::string(task, "workflow_run_id")?)
                    .fetch_one(&mut **tx)
                    .await?;
            let run_status = DB::string(&run, "status")?;
            if super::run_observation_store::terminal(&run_status) {
                let stop_reason = match run_status.as_str() {
                    "cancelled" => "run_cancelled",
                    "terminated" => "run_terminated",
                    "completed" => "run_completed",
                    _ => "run_failed",
                };
                return Err(refused(
                    &task_id,
                    &attempt,
                    "Workflow run is already closed.",
                    "run_closed",
                    json!({"stop_reason":stop_reason,"cancel_requested":matches!(run_status.as_str(),"cancelled"|"terminated"),
                        "can_continue":false,"run_status":run_status,
                        "run_closed_reason":DB::optional_string(&run,"closed_reason")?,
                        "run_closed_at":DB::optional_instant(&run,"closed_at")?.map(wire),
                        "task_status":status,"lease_owner":owner,"lease_expires_at":expires_wire}),
                ));
            }
            return Err(refused(
                &task_id,
                &attempt,
                "Workflow task is not currently leased.",
                "task_not_leased",
                json!({"task_status":status}),
            ));
        }
        let (Some(owner), Some(expires)) = (owner, expires) else {
            return Err(refused(
                &task_id,
                &attempt,
                "Workflow task is not currently leased.",
                "task_not_leased",
                json!({"task_status":status}),
            ));
        };
        // An expired lease is claimed again by the next poll.
        if expires <= now() {
            return Err(refused(
                &task_id,
                &attempt,
                "Workflow task lease has expired and is waiting for recovery.",
                "lease_expired",
                json!({"task_status":"leased","lease_owner":owner,"lease_expires_at":expires_wire}),
            ));
        }
        if lease_owner != owner {
            return Err(refused(
                &task_id,
                &attempt,
                "Workflow task lease is owned by another worker.",
                "lease_owner_mismatch",
                json!({"lease_owner":owner}),
            ));
        }
        let current = DB::number(task, "attempt_count")?;
        if current > 0 && attempt.as_i64() != Some(current) {
            return Err(refused(
                &task_id,
                &attempt,
                "Workflow task lease attempt does not match the current claim.",
                "workflow_task_attempt_mismatch",
                json!({"current_attempt":current}),
            ));
        }
        Ok(())
    }
}
