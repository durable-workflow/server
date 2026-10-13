//! Additional public Rust author for the unchanged PHP range checkpoints.
//! Policy input is the original recorded snapshot; no default reconstruction.
use std::sync::{Arc, Mutex};
use std::time::Duration;

use durable_workflow::{
    ActivityOptions, ActivityRetryPolicy, Error, ParallelOperation, ParallelResult, Result,
    WorkflowContext,
};
use serde_json::{json, Value};

pub fn workflow_type(case: &str) -> Option<&'static str> {
    match case {
        "range_completed" => Some("parity.v1.range_repair"),
        "range_pending" => Some("parity.v1.range_pending_repair"),
        _ => None,
    }
}

fn invalid(field: &str) -> Error {
    Error::WorkerLoop(format!("Original range snapshot has unsupported {field}"))
}

fn original_options(queue: &str, snapshot: &Value) -> Result<ActivityOptions> {
    if snapshot["snapshot_version"].as_u64() != Some(1) {
        return Err(invalid("snapshot_version"));
    }
    let attempts = snapshot["max_attempts"]
        .as_u64()
        .filter(|n| *n > 0 && *n <= u32::MAX as u64)
        .ok_or_else(|| invalid("max_attempts"))?;
    let intervals: Vec<_> = snapshot["backoff_seconds"]
        .as_array()
        .ok_or_else(|| invalid("backoff_seconds"))?
        .iter()
        .map(|value| {
            value
                .as_u64()
                .map(Duration::from_secs)
                .ok_or_else(|| invalid("backoff_seconds element"))
        })
        .collect::<Result<_>>()?;
    let types: Vec<String> = snapshot["non_retryable_error_types"]
        .as_array()
        .ok_or_else(|| invalid("non_retryable_error_types"))?
        .iter()
        .map(|value| {
            value
                .as_str()
                .map(str::to_owned)
                .ok_or_else(|| invalid("non_retryable_error_types element"))
        })
        .collect::<Result<_>>()?;
    let mut options = ActivityOptions::new().task_queue(queue).retry_policy(
        ActivityRetryPolicy::new(attempts as u32)
            .backoff_intervals(intervals)
            .non_retryable_error_types(types),
    );
    for field in [
        "start_to_close_timeout",
        "schedule_to_start_timeout",
        "schedule_to_close_timeout",
        "heartbeat_timeout",
    ] {
        let value = snapshot.get(field).ok_or_else(|| invalid(field))?;
        if value.is_null() {
            continue;
        }
        let duration = Duration::from_secs(value.as_u64().ok_or_else(|| invalid(field))?);
        options = match field {
            "start_to_close_timeout" => options.start_to_close_timeout(duration),
            "schedule_to_start_timeout" => options.schedule_to_start_timeout(duration),
            "schedule_to_close_timeout" => options.schedule_to_close_timeout(duration),
            "heartbeat_timeout" => options.heartbeat_timeout(duration),
            _ => unreachable!(),
        };
    }
    Ok(options)
}

pub async fn execute(
    ctx: WorkflowContext,
    value: Value,
    case: &str,
    profile: &str,
    queue: &str,
    policy: &str,
    decisions: Arc<Mutex<Vec<Value>>>,
) -> Result<Value> {
    let change_id = if case == "range_pending" {
        "parity.range-pending-repair"
    } else {
        "parity.range-repair"
    };
    let range = match profile {
        "original" | "restored" | "repaired-wait" => (1, 1),
        "excluding" => (2, 2),
        _ => return Err(Error::WorkerLoop("Unknown original range profile".into())),
    };
    let version = match ctx.get_version(change_id, range.0, range.1) {
        Ok(version) => version,
        Err(error) => {
            decisions
                .lock()
                .map_err(|_| Error::WorkflowStatePoisoned)?
                .push(json!({"range_refusal":error.to_string(),"change_id":change_id}));
            return Err(error);
        }
    };
    decisions
        .lock()
        .map_err(|_| Error::WorkflowStatePoisoned)?
        .push(json!([version]));
    let snapshot: Value = serde_json::from_str(policy)
        .map_err(|error| Error::WorkerLoop(format!("Invalid original policy JSON: {error}")))?;
    let options = original_options(queue, &snapshot)?;
    if case == "range_completed" {
        return ctx
            .activity_typed_with_options::<Value, Value>("parity.v1.echo_activity", options, value)
            .await;
    }
    let selected = ctx
        .select_keyed(vec![
            (
                "activity",
                ParallelOperation::activity_with_options(
                    "parity.v1.echo_activity",
                    options,
                    json!([value]),
                ),
            ),
            ("timer", ParallelOperation::timer(Duration::from_secs(3))),
        ])
        .await?;
    if selected.kind != "timer" || !selected.succeeded() {
        return Err(Error::WorkerLoop(
            "Original retained timer did not win".into(),
        ));
    }
    decisions
        .lock()
        .map_err(|_| Error::WorkflowStatePoisoned)?
        .push(json!({"winner":selected.kind,"key":selected.key}));
    match selected.handles[0].await_result().await? {
        ParallelResult::Activity(value) => Ok(value),
        _ => Err(Error::WorkerLoop(
            "Original pending activity handle changed kind".into(),
        )),
    }
}
