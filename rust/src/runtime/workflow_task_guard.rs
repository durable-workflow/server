//! Refusal bodies of PHP's workflow task ownership guard
//! (WorkflowTaskOwnership::guard, rendered by WorkerController). A worker
//! reads these fields, not just the reason, to tell that its claim was lost:
//! the task's status, whether the run can continue, and who holds the lease
//! now. `Store::workflow_task_guard` decides which refusal applies.
use super::RuntimeError;
use axum::http::StatusCode;
use serde_json::{Value, json};

pub(super) fn refusal(
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
