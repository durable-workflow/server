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
        "protocol_version":super::WORKER_PROTOCOL_VERSION,"server_capabilities":super::store::capabilities()});
    if let (Some(response), Value::Object(fields)) = (response.as_object_mut(), fields) {
        response.extend(fields);
    }
    RuntimeError::Protocol {
        status: StatusCode::CONFLICT,
        response,
    }
}

/// The refusals that settle a workflow task report as not recorded: the task
/// is no longer leased, or its run has closed. Every other refusal (lease
/// owner or attempt mismatch, expired lease, stale registration, conflicting
/// completion, invalid commands) stays fatal to a worker and gains nothing.
const NOT_RECORDED: [&str; 2] = ["task_not_leased", "run_closed"];

/// The full not-recorded receipt (Server #994, Security's contract in issue
/// comment 6613395001) on a workflow task completion, failure or scope
/// checkpoint refused with 409 `task_not_leased` or `run_closed` before
/// anything was written: `recorded: false` with the request's exact
/// `task_id` and `workflow_task_attempt`, the task row's own `run_id`, and
/// the task's status where the refusal has none. A guard refusal keeps every
/// field it already has; a bare refusal keeps its `reason` and `message`. No
/// payload or command data is added. Other errors pass through unchanged.
pub(super) fn not_recorded(
    error: RuntimeError,
    task_id: &str,
    attempt: &Value,
    run_id: &str,
    task_status: &str,
) -> RuntimeError {
    let mut response = match error {
        RuntimeError::Protocol { status, response }
            if status == StatusCode::CONFLICT
                && response["reason"].as_str().is_some_and(|reason| NOT_RECORDED.contains(&reason)) =>
        {
            response
        }
        RuntimeError::Refused { status, reason } if status == StatusCode::CONFLICT && NOT_RECORDED.contains(&reason) => {
            json!({"reason":reason,"message":reason,"protocol_version":super::WORKER_PROTOCOL_VERSION,
                "server_capabilities":super::store::capabilities()})
        }
        other => return other,
    };
    receipt(&mut response, task_id, attempt, run_id, task_status);
    RuntimeError::Protocol {
        status: StatusCode::CONFLICT,
        response,
    }
}

/// The same receipt on a refused answer the checkpoint returns as its body
/// (Core's refused checkpoint, `checkpointed: false`, rendered as 409): a
/// `task_not_leased` or `run_closed` checkpoint refusal gains the receipt;
/// any other answer is returned unchanged.
pub(super) fn not_recorded_answer(
    mut answer: Value,
    task_id: &str,
    attempt: &Value,
    run_id: &str,
    task_status: &str,
) -> Value {
    let refused = answer["checkpointed"] != json!(true)
        && answer["reason"].as_str().is_some_and(|reason| NOT_RECORDED.contains(&reason));
    if refused {
        receipt(&mut answer, task_id, attempt, run_id, task_status);
    }
    answer
}

/// The receipt's identity is authoritative: the task row's id and run and
/// the request's normalized attempt replace whatever the refusal carried.
fn receipt(response: &mut Value, task_id: &str, attempt: &Value, run_id: &str, task_status: &str) {
    if let Some(fields) = response.as_object_mut() {
        let attempt = attempt.as_i64().map_or(Value::Null, Value::from);
        fields.insert("task_id".to_owned(), json!(task_id));
        fields.insert("workflow_task_attempt".to_owned(), attempt);
        fields.insert("recorded".to_owned(), json!(false));
        fields.insert("run_id".to_owned(), json!(run_id));
        fields.entry("task_status").or_insert_with(|| json!(task_status));
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn a_guard_refusal_keeps_its_fields_and_gains_the_receipt() {
        let guarded = refusal("task-1", &json!(2), "Workflow task is not currently leased.", "task_not_leased",
            json!({"task_status":"completed"}));
        let RuntimeError::Protocol { status, mut response } = not_recorded(guarded, "task-1", &json!(2), "run-1", "leased") else {
            panic!("a protocol refusal");
        };
        assert_eq!(status, StatusCode::CONFLICT);
        assert!(response.as_object_mut().unwrap().remove("server_capabilities").is_some());
        assert_eq!(
            response,
            json!({"task_id":"task-1","workflow_task_attempt":2,"error":"Workflow task is not currently leased.",
                "reason":"task_not_leased","protocol_version":super::super::WORKER_PROTOCOL_VERSION,
                "task_status":"completed","recorded":false,"run_id":"run-1"})
        );
    }

    #[test]
    fn the_receipt_identity_is_authoritative_over_a_conflicting_body() {
        let conflicting = refusal("other-task", &json!(9), "Workflow run is already closed.", "run_closed",
            json!({"run_id":"other-run","recorded":true}));
        let RuntimeError::Protocol { response, .. } = not_recorded(conflicting, "task-1", &json!(2), "run-1", "completed") else {
            panic!("a protocol refusal");
        };
        assert_eq!(
            (&response["task_id"], &response["workflow_task_attempt"], &response["run_id"], &response["recorded"]),
            (&json!("task-1"), &json!(2), &json!("run-1"), &json!(false))
        );
        let answer = not_recorded_answer(json!({"checkpointed":false,"duplicate":false,"task_id":"other-task",
            "workflow_task_attempt":9,"reason":"run_closed"}), "task-1", &json!(2), "run-1", "leased");
        assert_eq!(
            answer,
            json!({"checkpointed":false,"duplicate":false,"task_id":"task-1","workflow_task_attempt":2,
                "reason":"run_closed","recorded":false,"run_id":"run-1","task_status":"leased"})
        );
    }

    #[test]
    fn other_checkpoint_answers_are_unchanged() {
        for answer in [
            json!({"checkpointed":false,"duplicate":false,"task_id":"task-1","reason":"workflow_claim_mismatch"}),
            json!({"checkpointed":false,"reason":"active_claim_cancellation_not_supported"}),
            json!({"checkpointed":true,"duplicate":true,"task_id":"task-1","reason":null}),
        ] {
            assert_eq!(not_recorded_answer(answer.clone(), "task-1", &json!(1), "run-1", "leased"), answer);
        }
    }

    #[test]
    fn a_bare_run_closed_refusal_becomes_the_full_receipt() {
        let RuntimeError::Protocol { status, mut response } =
            not_recorded(super::super::refuse(StatusCode::CONFLICT, "run_closed"), "task-1", &json!(1), "run-1", "leased")
        else {
            panic!("a protocol refusal");
        };
        assert_eq!(status, StatusCode::CONFLICT);
        assert!(response.as_object_mut().unwrap().remove("server_capabilities").is_some_and(|c| c.is_object()));
        assert_eq!(
            response,
            json!({"reason":"run_closed","message":"run_closed","protocol_version":super::super::WORKER_PROTOCOL_VERSION,
                "task_id":"task-1","workflow_task_attempt":1,"recorded":false,"run_id":"run-1","task_status":"leased"})
        );
    }

    #[test]
    fn fatal_refusals_and_other_errors_pass_through_unchanged() {
        for reason in ["lease_owner_mismatch", "workflow_task_attempt_mismatch", "lease_expired", "stale_worker_registration"] {
            let guarded = refusal("task-1", &json!(1), "refused", reason, json!({}));
            let RuntimeError::Protocol { response: expected, .. } = refusal("task-1", &json!(1), "refused", reason, json!({})) else {
                unreachable!()
            };
            let RuntimeError::Protocol { status, response } = not_recorded(guarded, "task-1", &json!(1), "run-1", "leased") else {
                panic!("{reason}: unchanged");
            };
            assert_eq!((status, response), (StatusCode::CONFLICT, expected), "{reason}");
        }
        for (status, reason) in [
            (StatusCode::CONFLICT, "conflicting_completion"),
            (StatusCode::CONFLICT, "cancellation_deadline_expired"),
            (StatusCode::UNPROCESSABLE_ENTITY, "invalid_request"),
            (StatusCode::NOT_FOUND, "task_not_found"),
        ] {
            let RuntimeError::Refused { status: kept, reason: kept_reason } =
                not_recorded(super::super::refuse(status, reason), "task-1", &json!(1), "run-1", "leased")
            else {
                panic!("{reason}: unchanged");
            };
            assert_eq!((kept, kept_reason), (status, reason));
        }
    }
}
