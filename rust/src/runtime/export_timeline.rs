//! `HistoryTimeline::fromHistory` (Workflow 2.15.7): one timeline point per
//! history event, with the command, task, activity, timer, child and failure
//! each point names. Native keeps no timeline projection, so a history export
//! maps the run's events when it is built, as PHP rebuilds a missing one.
use super::external_resolution::stored_envelope;
use super::history_export::Rows;
use crate::codec::ValueCodec;
use serde_json::{Map, Value, json};
use std::collections::HashMap;

const MAX_SUMMARY_BYTES: usize = 65_535;
const SUMMARY_TRUNCATION_MARKER: &str = " [truncated]";
const CHILD_EVENTS: &[&str] = &[
    "ChildWorkflowScheduled",
    "ChildRunStarted",
    "ChildRunCompleted",
    "ChildRunFailed",
    "ChildRunCancelled",
    "ChildRunTerminated",
];
const SCOPE_REQUEST_EVENTS: &[&str] = &[
    "CancellationScopeRequested",
    "CancellationScopeDeliveryPrepared",
    "CancellationScopeDelivered",
    "CancellationScopeRequestConflicted",
];
const SCOPE_REQUEST_FIELDS: &[&str] = &[
    "schema", "scope_id", "parent_scope_id", "request_id", "cancellation", "reason", "accepted_cancellation",
    "incoming_cancellation", "sequence", "call_kind", "sequence_span", "operation_sequence", "operation_sequence_span",
    "authority_deadline_at", "activity_members", "timer_members", "wait_members", "child_members",
    "preparation_history_event_id",
];
const PARENT_CLOSE_EVENTS: &[&str] =
    &["ParentCloseCancellationRequested", "ParentClosePolicyApplied", "ParentClosePolicyFailed"];

/// `stringValue`: a non-empty string.
fn string(value: &Value) -> Option<&str> {
    value.as_str().filter(|text| !text.is_empty())
}

fn string_value(value: &Value) -> Value {
    string(value).map_or(Value::Null, |text| json!(text))
}

/// `intValue`: an integer, or a numeric string cast as PHP casts it.
fn int(value: &Value) -> Option<i64> {
    match value {
        Value::Number(number) => number.as_i64(),
        Value::String(text) => text
            .trim()
            .parse::<i64>()
            .ok()
            .or_else(|| text.trim().parse::<f64>().ok().filter(|number| number.is_finite()).map(|number| number as i64)),
        _ => None,
    }
}

fn int_value(value: &Value) -> Value {
    int(value).map_or(Value::Null, |number| json!(number))
}

fn first(values: &[&Value]) -> Value {
    values.iter().find_map(|value| string(value)).map_or(Value::Null, |text| json!(text))
}

fn object(value: &Value) -> Option<&Map<String, Value>> {
    value.as_object()
}

/// `class_basename` for a class-like label.
fn display_label(value: &str) -> String {
    value.rsplit('\\').next().unwrap_or(value).to_owned()
}

fn duration_label(seconds: i64) -> String {
    if seconds == 1 { "1 second".to_owned() } else { format!("{seconds} seconds") }
}

/// `ParallelChildGroup::singleMetadataFromPayload`.
fn single_parallel_metadata(payload: &Value) -> Option<Value> {
    let group_id = payload["parallel_group_id"].as_str();
    let kind = payload["parallel_group_kind"]
        .as_str()
        .map(str::to_owned)
        .or_else(|| group_id.and_then(kind_from_group_id));
    let mode = string(&payload["parallel_group_mode"])
        .map(str::to_owned)
        .or_else(|| group_id.and_then(mode_from_group_id));
    let base = payload["parallel_group_base_sequence"].as_i64();
    let size = payload["parallel_group_size"].as_i64();
    let index = payload["parallel_group_index"].as_i64();
    let (Some(group_id), Some(kind), Some(base), Some(size), Some(index)) = (group_id, kind, base, size, index) else {
        return None;
    };
    if size < 1 {
        return None;
    }
    let select = mode.as_deref() == Some("select");
    let member_key = &payload["selection_member_key"];
    let valid_key = member_key.as_str().is_some_and(|key| !key.is_empty()) || member_key.as_i64().is_some_and(|key| key >= 0);
    if select && !valid_key {
        return None;
    }
    let mut metadata = Map::new();
    metadata.insert("parallel_group_id".into(), json!(group_id));
    metadata.insert("parallel_group_kind".into(), json!(kind));
    if select {
        metadata.insert("parallel_group_mode".into(), json!("select"));
    }
    metadata.insert("parallel_group_base_sequence".into(), json!(base));
    metadata.insert("parallel_group_size".into(), json!(size));
    metadata.insert("parallel_group_index".into(), json!(index));
    if select {
        metadata.insert("selection_member_key".into(), member_key.clone());
    }
    for field in ["selection_member_index", "selection_member_base_sequence", "selection_member_size"] {
        if let Some(number) = int(&payload[field]) {
            metadata.insert(field.into(), json!(number));
        }
    }
    if let Some(kind) = string(&payload["selection_member_kind"]) {
        metadata.insert("selection_member_kind".into(), json!(kind));
    }
    Some(Value::Object(metadata))
}

fn kind_from_group_id(group_id: &str) -> Option<String> {
    [("parallel-activities:", "activity"), ("parallel-calls:", "mixed"), ("parallel-timers:", "timer"),
        ("parallel-children:", "child"), ("select-calls:", "mixed")]
    .iter()
    .find(|(prefix, _)| group_id.starts_with(prefix))
    .map(|(_, kind)| (*kind).to_owned())
}

fn mode_from_group_id(group_id: &str) -> Option<String> {
    group_id.starts_with("select-calls:").then(|| "select".to_owned())
}

/// `ParallelChildGroup::metadataPathFromPayload`.
pub(super) fn parallel_path(payload: &Value) -> Vec<Value> {
    let path: Vec<Value> = payload["parallel_group_path"]
        .as_array()
        .map(|entries| entries.iter().filter(|entry| entry.is_object()).filter_map(single_parallel_metadata).collect())
        .unwrap_or_default();
    if !path.is_empty() {
        return path;
    }
    single_parallel_metadata(payload).into_iter().collect()
}

/// The rows a timeline point resolves against, keyed by id.
struct Context<'a> {
    run_id: &'a Value,
    commands: HashMap<&'a str, &'a Value>,
    tasks: HashMap<&'a str, &'a Value>,
    activities: HashMap<&'a str, &'a Value>,
    timers: HashMap<String, Value>,
    failures: HashMap<String, Value>,
    codec: Option<&'a ValueCodec>,
    project: fn(crate::codec::Value) -> Value,
}

fn index<'a>(rows: &'a [Value]) -> HashMap<&'a str, &'a Value> {
    rows.iter().filter_map(|row| row["id"].as_str().map(|id| (id, row))).collect()
}

/// `HistoryTimeline::fromHistory` over the export's own rows. `timers` and
/// `failures` are the export's rebuilt timer and failure snapshots.
pub(super) fn timeline(
    rows: &Rows,
    timers: &[Value],
    failures: &[Value],
    codec: Option<&ValueCodec>,
    project: fn(crate::codec::Value) -> Value,
) -> Vec<Value> {
    let mut failures: HashMap<String, Value> = failures
        .iter()
        .filter_map(|failure| failure["id"].as_str().map(|id| (id.to_owned(), failure.clone())))
        .collect();
    for event in &rows.events {
        if event["event_type"] == "FailureHandled"
            && let Some(failure) = string(&event["payload"]["failure_id"]).and_then(|id| failures.get_mut(id))
        {
            failure["handled"] = json!(true);
        }
    }
    let context = Context {
        run_id: &rows.run["id"],
        commands: index(&rows.commands),
        tasks: index(&rows.tasks),
        activities: index(&rows.executions),
        timers: timers
            .iter()
            .filter_map(|timer| timer["id"].as_str().map(|id| (id.to_owned(), timer.clone())))
            .collect(),
        failures,
        codec,
        project,
    };
    let mut events: Vec<&Value> = rows.events.iter().collect();
    events.sort_by_key(|event| event["sequence"].as_i64().unwrap_or(i64::MAX));
    events.into_iter().map(|event| map_event(event, &context)).collect()
}

fn map_event(event: &Value, context: &Context<'_>) -> Value {
    let kind = event["event_type"].as_str().unwrap_or_default();
    let empty = json!({});
    let payload = if event["payload"].is_object() { &event["payload"] } else { &empty };
    if kind == "ActivityOutcomeRejected" {
        return outcome_rejection(event, payload);
    }
    let command_id = string(&event["workflow_command_id"]).or_else(|| string(&payload["workflow_command_id"]));
    let task_id = string(&event["workflow_task_id"]);
    let activity_id = string(&payload["activity_execution_id"]);
    let timer_id = string(&payload["timer_id"]);
    let failure_id = string(&payload["failure_id"]);
    let command_row = command_id.and_then(|id| context.commands.get(id).copied());
    let task_row = task_id.and_then(|id| context.tasks.get(id).copied());
    let activity_row = activity_id.and_then(|id| context.activities.get(id).copied());
    let timer_row = timer_id.and_then(|id| context.timers.get(id));
    let failure = failure_id.and_then(|id| context.failures.get(id));
    let command = command_metadata(kind, command_row, payload, command_id, context);
    let task = task_metadata(kind, task_row, payload, task_id);
    let activity = activity_metadata(event, kind, activity_row, payload, activity_id, failure);
    let timer = timer_metadata(kind, timer_row, payload, timer_id);
    let child = child_metadata(kind, payload);
    let failure = failure_metadata(kind, failure, payload, failure_id);
    let path = parallel_path(payload);
    let parallel = path.last().cloned().unwrap_or(Value::Null);
    let source_kind = source_kind(kind);
    let source_id = source_id(source_kind, context.run_id, payload, &command, &task, &activity, &timer, &child, &failure);
    let summary = summary(kind, payload, &command, &task, &activity, &timer, &child, &failure);
    let field = |metadata: &Value, name: &str| metadata.get(name).cloned().unwrap_or(Value::Null);
    let mut entry = Map::new();
    let mut put = |name: &str, value: Value| {
        entry.insert(name.to_owned(), value);
    };
    put("id", event["id"].clone());
    put("sequence", event["sequence"].clone());
    put("type", json!(kind));
    put("kind", json!(kind_for(kind)));
    put("entry_kind", json!("point"));
    put("source_kind", json!(source_kind));
    put("source_id", source_id);
    put("summary", json!(summary));
    put("recorded_at", string_value(&event["recorded_at"]));
    put("command_id", command_id.map_or(Value::Null, |id| json!(id)));
    put("command_sequence", field(&command, "sequence"));
    put("requested_run_id", field(&command, "requested_run_id"));
    put("resolved_run_id", field(&command, "resolved_run_id"));
    put("task_id", task_id.map_or(Value::Null, |id| json!(id)));
    put("command_type", field(&command, "type"));
    put("command_status", field(&command, "status"));
    put("command_outcome", field(&command, "outcome"));
    put("command_rejection_reason", field(&command, "rejection_reason"));
    put("workflow_sequence", int_value(&payload["sequence"]));
    if kind == "CancellationScopeOpened" {
        put("cancellation_scope", json!({"schema": string_value(&payload["schema"]),
            "scope_id": string_value(&payload["scope_id"]), "parent_scope_id": string_value(&payload["parent_scope_id"]),
            "shield_parent": if payload["shield_parent"].is_boolean() { payload["shield_parent"].clone() } else { Value::Null }}));
    }
    if SCOPE_REQUEST_EVENTS.contains(&kind) {
        let mut scope = Map::new();
        for name in SCOPE_REQUEST_FIELDS {
            if let Some(value) = payload.get(*name) {
                scope.insert((*name).to_owned(), value.clone());
            }
        }
        put("cancellation_scope", Value::Object(scope));
    }
    put("service_call_id", string_value(&payload["service_call_id"]));
    if matches!(kind, "SignalWaitCancelled" | "ConditionWaitCancelled") {
        put("cancellation_scope", payload["cancellation_scope"].clone());
    }
    put("signal_id", string_value(&payload["signal_id"]));
    put("signal_wait_id", string_value(&payload["signal_wait_id"]));
    put("condition_wait_id", string_value(&payload["condition_wait_id"]));
    put("condition_key", string_value(&payload["condition_key"]));
    put("condition_definition_fingerprint", string_value(&payload["condition_definition_fingerprint"]));
    let target = string(&command["target_name"]);
    put("signal_name", target.map_or_else(|| string_value(&payload["signal_name"]), |name| json!(name)));
    put("update_name", target.map_or_else(|| string_value(&payload["update_name"]), |name| json!(name)));
    put("version_change_id", string_value(&payload["change_id"]));
    put("version", int_value(&payload["version"]));
    put("version_min_supported", int_value(&payload["min_supported"]));
    put("version_max_supported", int_value(&payload["max_supported"]));
    put("activity_execution_id", field(&activity, "id"));
    put("activity_type", field(&activity, "type"));
    put("activity_class", field(&activity, "class"));
    put("activity_status", field(&activity, "status"));
    put("retry_task_id", string_value(&payload["retry_task_id"]));
    put("retry_available_at", string_value(&payload["retry_available_at"]));
    put("retry_backoff_seconds", int_value(&payload["retry_backoff_seconds"]));
    put("retry_max_attempts", int_value(&payload["max_attempts"]));
    put("retry_policy", if payload["retry_policy"].is_object() || payload["retry_policy"].is_array() {
        payload["retry_policy"].clone()
    } else {
        Value::Null
    });
    put("retry_after_attempt_id", string_value(&payload["retry_after_attempt_id"]));
    put("retry_after_attempt", int_value(&payload["retry_after_attempt"]));
    put("timer_id", field(&timer, "id"));
    put("delay_seconds", int(&payload["timeout_seconds"]).map_or_else(|| field(&timer, "delay_seconds"), |seconds| json!(seconds)));
    put("child_call_id", field(&child, "child_call_id"));
    put("child_workflow_instance_id", field(&child, "instance_id"));
    put("child_workflow_run_id", field(&child, "run_id"));
    put("child_workflow_type", field(&child, "type"));
    put("child_workflow_class", field(&child, "class"));
    put("child_status", field(&child, "status"));
    for name in ["parallel_group_kind", "parallel_group_id", "parallel_group_base_sequence", "parallel_group_size",
        "parallel_group_index"]
    {
        put(name, field(&parallel, name));
    }
    put("parallel_group_path", json!(path));
    put("failure_id", field(&failure, "id"));
    for name in ["exception_type", "exception_class", "exception_resolved_class", "exception_resolution_source",
        "exception_resolution_error", "message"]
    {
        put(name, field(&failure, name));
    }
    put("closed_reason", payload["closed_reason"].clone());
    put("command", command);
    put("task", task);
    put("activity", activity);
    put("timer", timer);
    put("child", child);
    if let Some(recovery) = object(&payload["local_recovery"]) {
        let get = |name: &str| recovery.get(name).unwrap_or(&Value::Null);
        put("local_recovery", json!({
            "original_workflow_task_id": string_value(get("original_workflow_task_id")),
            "original_workflow_task_attempt": int_value(get("original_workflow_task_attempt")),
            "original_lease_owner": string_value(get("original_lease_owner")),
            "original_lease_expires_at": string_value(get("original_lease_expires_at")),
            "workflow_task_id": string_value(get("workflow_task_id")),
            "workflow_task_attempt": int_value(get("workflow_task_attempt")),
            "lease_owner": string_value(get("lease_owner")),
            "callback_stop_state": string_value(get("callback_stop_state")),
        }));
    }
    if kind == "ActivityCancellationAcknowledged" {
        put("cancellation_acknowledgement", json!({
            "activity_attempt_id": string_value(&payload["activity_attempt_id"]),
            "cancellation_history_event_id": string_value(&payload["cancellation_history_event_id"]),
            "request_id": string_value(&payload["request_id"]),
            "root_request_id": string_value(&payload["root_request_id"]),
            "cleanup_deadline_at": string_value(&payload["cleanup_deadline_at"]),
            "callback_state": string_value(&payload["callback_state"]),
            "evidence_source": string_value(&payload["evidence_source"]),
            "acknowledged_at": string_value(&payload["acknowledged_at"]),
            "received_after_deadline": payload["received_after_deadline"].clone(),
        }));
    }
    if PARENT_CLOSE_EVENTS.contains(&kind) {
        put("parent_close", json!({
            "policy": string_value(&payload["policy"]),
            "child_instance_id": string_value(&payload["child_instance_id"]),
            "child_run_id": string_value(&payload["child_run_id"]),
            "request_id": string_value(&payload["request_id"]),
            "cancellation": payload["cancellation"].clone(),
            "error": string_value(&payload["error"]),
            "request_diagnostics": payload["request_diagnostics"].clone(),
            "parent_terminal_history_event_id": string_value(&payload["parent_terminal_history_event_id"]),
        }));
    }
    if kind == "WorkflowCancelled" && payload["cancellation_cleanup"].is_object() {
        put("cancellation_cleanup", payload["cancellation_cleanup"].clone());
    }
    put("failure", failure);
    Value::Object(entry)
}

/// `fromActivityOutcomeRejection`.
fn outcome_rejection(event: &Value, payload: &Value) -> Value {
    let activity_id = string_value(&payload["activity_execution_id"]);
    let attempt = int(&payload["attempt_number"]);
    let summary = format!(
        "Rejected {} outcome from activity attempt {}: {}.",
        string(&payload["outcome"]).unwrap_or("unknown"),
        attempt.map_or_else(|| "unknown".to_owned(), |number| number.to_string()),
        string(&payload["reason"]).unwrap_or("unknown"),
    );
    json!({"id": event["id"], "sequence": event["sequence"], "type": event["event_type"], "kind": "activity",
        "entry_kind": "point", "source_kind": "activity_execution", "source_id": activity_id,
        "summary": bounded_summary(&summary, ""), "recorded_at": string_value(&event["recorded_at"]),
        "command_id": null, "command_sequence": null, "task_id": event["workflow_task_id"],
        "activity_execution_id": activity_id, "timer_id": null, "failure_id": null,
        "activity": {"id": activity_id, "attempt_id": string_value(&payload["activity_attempt_id"]),
            "attempt_count": attempt}})
}

fn kind_for(kind: &str) -> &'static str {
    match kind {
        "StartAccepted" | "StartRejected" | "SignalReceived" | "UpdateAccepted" | "UpdateRejected" | "RepairRequested"
        | "CancelRequested" | "CooperativeCancellationRequested" | "TerminateRequested" | "ArchiveRequested" => "command",
        "SignalWaitOpened" | "SignalWaitCancelled" | "SignalRejected" | "SignalApplied" => "signal",
        "UpdateApplied" | "UpdateCompleted" => "update",
        "ChildWorkflowScheduled" | "ChildRunStarted" | "ChildRunCompleted" | "ChildRunFailed" | "ChildRunCancelled"
        | "ChildCancellationRequested" | "ChildCancellationResolved" | "ChildRunTerminated" => "child",
        "ServiceOperationScheduled" | "ServiceCallStarted" | "ServiceCallCompleted" | "ServiceCallFailed"
        | "ServiceCallCancelled" => "service_call",
        "ConditionWaitOpened" | "ConditionWaitSatisfied" | "ConditionWaitTimedOut" | "ConditionWaitCancelled" => "condition",
        "ActivityScheduled" | "ActivityStarted" | "ActivityHeartbeatRecorded" | "ActivityRetryScheduled"
        | "ActivityCompleted" | "ActivityFailed" | "ActivityCancelled" | "ActivityCancellationAcknowledged"
        | "ActivityTimedOut" => "activity",
        "FailureHandled" => "failure",
        "SideEffectRecorded" => "side_effect",
        "VersionMarkerRecorded" => "version",
        "SelectionResolved" | "SelectionOperationCancelled" => "selection",
        "CancellationScopeOpened" | "CancellationScopeRequested" | "CancellationScopeDeliveryPrepared"
        | "CancellationScopeDelivered" | "CancellationScopeRequestConflicted" => "cancellation_scope",
        "TimerScheduled" | "TimerFired" | "TimerCancelled" => "timer",
        _ => "workflow",
    }
}

fn source_kind(kind: &str) -> &'static str {
    match kind {
        "StartAccepted" | "StartRejected" | "SignalReceived" | "SignalRejected" | "UpdateAccepted" | "UpdateRejected"
        | "UpdateApplied" | "UpdateCompleted" | "RepairRequested" | "CancelRequested"
        | "CooperativeCancellationRequested" | "TerminateRequested" | "ArchiveRequested" => "workflow_command",
        "SignalWaitOpened" | "SignalApplied" => "signal_wait",
        "ChildWorkflowScheduled" | "ChildRunStarted" | "ChildRunCompleted" | "ChildRunFailed" | "ChildRunCancelled"
        | "ChildRunTerminated" => "child_workflow_run",
        "ServiceOperationScheduled" | "ServiceCallStarted" | "ServiceCallCompleted" | "ServiceCallFailed"
        | "ServiceCallCancelled" => "workflow_service_call",
        "ConditionWaitOpened" | "ConditionWaitSatisfied" | "ConditionWaitTimedOut" => "condition_wait",
        "ActivityScheduled" | "ActivityStarted" | "ActivityHeartbeatRecorded" | "ActivityRetryScheduled"
        | "ActivityCompleted" | "ActivityFailed" | "ActivityCancelled" | "ActivityCancellationAcknowledged"
        | "ActivityTimedOut" => "activity_execution",
        "FailureHandled" => "workflow_failure",
        "VersionMarkerRecorded" => "version_marker",
        "TimerScheduled" | "TimerFired" | "TimerCancelled" => "timer",
        _ => "workflow_run",
    }
}

#[allow(clippy::too_many_arguments)]
fn source_id(
    source_kind: &str,
    run_id: &Value,
    payload: &Value,
    command: &Value,
    task: &Value,
    activity: &Value,
    timer: &Value,
    child: &Value,
    failure: &Value,
) -> Value {
    match source_kind {
        "workflow_command" => string_value(&command["id"]),
        "workflow_service_call" => string_value(&payload["service_call_id"]),
        "signal_wait" => string_value(&payload["signal_wait_id"]),
        "condition_wait" => string_value(&payload["condition_wait_id"]),
        "version_marker" => string_value(&payload["change_id"]),
        "child_workflow_run" => first(&[&child["run_id"], &child["instance_id"]]),
        "activity_execution" => string(&activity["id"]).map_or_else(
            || if failure["source_kind"] == "activity_execution" { string_value(&failure["source_id"]) } else { Value::Null },
            |id| json!(id),
        ),
        "workflow_failure" => first(&[&failure["id"], &payload["failure_id"]]),
        "timer" => string_value(&timer["id"]),
        "workflow_run" => string_value(run_id),
        _ => string_value(&task["id"]),
    }
}

trait OrNull {
    fn or_null_then(self, fallback: impl FnOnce() -> Value) -> Value;
}

impl OrNull for Value {
    fn or_null_then(self, fallback: impl FnOnce() -> Value) -> Value {
        if self.is_null() { fallback() } else { self }
    }
}

#[allow(clippy::too_many_arguments)]
fn summary(
    kind: &str,
    payload: &Value,
    command: &Value,
    task: &Value,
    activity: &Value,
    timer: &Value,
    child: &Value,
    failure: &Value,
) -> String {
    let activity_label =
        display_label(first(&[&activity["type"], &activity["class"]]).as_str().unwrap_or("activity"));
    let delay = timer["delay_seconds"].as_i64();
    let message = string(&failure["message"]);
    let outcome = string(&command["outcome"]);
    let rejection = string(&command["rejection_reason"]);
    let signal_name = string(&command["target_name"]).or_else(|| string(&payload["signal_name"]));
    let update_name = string(&command["target_name"]).or_else(|| string(&payload["update_name"]));
    let change_id = string(&payload["change_id"]);
    let version = int(&payload["version"]);
    let timer_kind = string(&payload["timer_kind"]);
    let child_label = display_label(
        first(&[&child["type"], &child["class"], &child["run_id"]]).as_str().unwrap_or("child workflow"),
    );
    let text = |name: &str| string(&payload[name]).unwrap_or("unknown").to_owned();
    let timeout = int(&payload["timeout_seconds"]);
    let condition_label = string(&payload["condition_key"]).map(|key| format!(" {key}")).unwrap_or_default();
    let member = || {
        string(&payload["member_key"]).map_or_else(|| int(&payload["member_index"]).unwrap_or(0).to_string(), str::to_owned)
    };
    let summary = match kind {
        "StartAccepted" => outcome.map_or_else(|| "Start accepted.".to_owned(), |outcome| format!("Start accepted as {outcome}.")),
        "StartRejected" => rejection.map_or_else(|| "Start rejected.".to_owned(), |reason| format!("Start rejected: {reason}.")),
        "WorkflowStarted" => "Workflow run started.".to_owned(),
        "CancellationScopeRequested" => "Cooperative operation scope cancellation requested.".to_owned(),
        "CancellationScopeDeliveryPrepared" => "Operation scope cancellation preparation retained.".to_owned(),
        "CancellationScopeDelivered" => "Operation scope cancellation delivery boundary recorded.".to_owned(),
        "CancellationScopeRequestConflicted" => {
            "Operation scope cancellation root conflicts with its accepted request.".to_owned()
        }
        "CancellationScopeOpened" => format!(
            "Cancellation scope {} opened under {}{}.",
            text("scope_id"),
            text("parent_scope_id"),
            if payload["shield_parent"] == true { " with parent shielding" } else { "" }
        ),
        "SignalWaitCancelled" => "Signal wait cancelled by its operation scope.".to_owned(),
        "ConditionWaitCancelled" => "Condition wait cancelled by its operation scope.".to_owned(),
        "WorkflowContinuedAsNew" => format!("Continued as new on run {}.", text("continued_to_run_id")),
        "SelectionResolved" => format!(
            "Selected {} member {}.",
            string(&payload["operation_kind"]).unwrap_or("durable operation"),
            member()
        ),
        "SelectionOperationCancelled" => format!(
            "Cancelled selected-group {} member {}.",
            string(&payload["operation_kind"]).unwrap_or("durable operation"),
            member()
        ),
        "ChildWorkflowScheduled" => format!("Scheduled child workflow {child_label}."),
        "ChildRunStarted" => format!("Child workflow {child_label} started."),
        "ChildRunCompleted" => format!("Child workflow {child_label} completed."),
        "ChildRunFailed" => message.map_or_else(
            || format!("Child workflow {child_label} failed."),
            |message| format!("Child workflow {child_label} failed: {message}."),
        ),
        "ChildRunCancelled" => format!("Child workflow {child_label} cancelled."),
        "ChildRunTerminated" => format!("Child workflow {child_label} terminated."),
        "ServiceOperationScheduled" => format!("Service operation {} scheduled.", text("operation_name")),
        "ServiceCallStarted" => format!("Service operation {} started.", text("operation_name")),
        "ServiceCallCompleted" => format!("Service operation {} completed.", text("operation_name")),
        "ServiceCallFailed" => format!("Service operation {} failed.", text("operation_name")),
        "ServiceCallCancelled" => format!("Service operation {} cancelled.", text("operation_name")),
        "ConditionWaitOpened" => {
            if payload["timeout_seconds"].is_null() {
                format!("Waiting for condition{condition_label}.")
            } else {
                format!("Waiting for condition{condition_label} or timeout after {}.", duration_label(timeout.unwrap_or(0)))
            }
        }
        "ConditionWaitSatisfied" => "Condition satisfied.".to_owned(),
        "ConditionWaitTimedOut" => format!("Condition timed out after {}.", duration_label(timeout.or(delay).unwrap_or(0))),
        "SignalWaitOpened" => match signal_name {
            None => "Waiting for signal.".to_owned(),
            Some(name) if payload["timeout_seconds"].is_null() => format!("Waiting for signal {name}."),
            Some(name) => format!("Waiting for signal {name} or timeout after {}.", duration_label(timeout.unwrap_or(0))),
        },
        "SignalReceived" => signal_name.map_or_else(|| "Signal received.".to_owned(), |name| format!("Signal {name} received.")),
        "SignalApplied" => signal_name.map_or_else(|| "Signal applied.".to_owned(), |name| format!("Applied signal {name}.")),
        "SignalRejected" => signal_name.map_or_else(
            || "Signal closed without application.".to_owned(),
            |name| format!("Signal {name} closed without application: {}.", rejection.unwrap_or("run closed")),
        ),
        "UpdateAccepted" => update_name.map_or_else(|| "Update accepted.".to_owned(), |name| format!("Accepted update {name}.")),
        "UpdateRejected" => match (update_name, rejection) {
            (Some(name), Some(reason)) => format!("Rejected update {name}: {reason}."),
            (Some(name), None) => format!("Rejected update {name}."),
            (None, Some(reason)) => format!("Update rejected: {reason}."),
            (None, None) => "Update rejected.".to_owned(),
        },
        "UpdateApplied" => update_name.map_or_else(|| "Update applied.".to_owned(), |name| format!("Applied update {name}.")),
        "UpdateCompleted" => match (message, update_name) {
            (None, None) => "Update completed.".to_owned(),
            (None, Some(name)) => format!("Completed update {name}."),
            (Some(message), None) => format!("Update failed: {message}."),
            (Some(message), Some(name)) => format!("Update {name} failed: {message}."),
        },
        "RepairRequested" => match outcome {
            Some("repair_dispatched") => format!(
                "Repair recreated {} task.",
                display_label(string(&task["type"]).unwrap_or("workflow"))
            ),
            Some("repair_not_needed") => "Repair accepted; the run already had a durable resume source.".to_owned(),
            _ => "Repair accepted.".to_owned(),
        },
        "CancelRequested" => "Cancel requested.".to_owned(),
        "CooperativeCancellationRequested" => "Cooperative cancellation requested.".to_owned(),
        "CooperativeCancellationDelivered" => "Cancellation delivered to workflow code.".to_owned(),
        "WorkflowCancelled" => match payload["cancellation_cleanup"]["outcome"].as_str() {
            Some("completed") => "Workflow cancelled after cleanup completed.".to_owned(),
            Some("deadline_expired") => "Workflow cancelled at the cleanup deadline.".to_owned(),
            Some("not_delivered") => "Workflow cancelled without cancellation delivery to workflow code.".to_owned(),
            Some("unavailable") => "Workflow cancelled. Cleanup delivery evidence is unavailable.".to_owned(),
            _ => "Workflow cancelled.".to_owned(),
        },
        "TerminateRequested" => "Terminate requested.".to_owned(),
        "WorkflowTerminated" => "Workflow terminated.".to_owned(),
        "ArchiveRequested" => match outcome {
            Some("archive_not_needed") => "Archive accepted; the run was already archived.".to_owned(),
            Some("archived") => "Archive requested.".to_owned(),
            _ => "Archive accepted.".to_owned(),
        },
        "WorkflowArchived" => "Workflow archived.".to_owned(),
        "ActivityScheduled" => format!("Scheduled {activity_label}."),
        "ActivityStarted" => format!("Started {activity_label}."),
        "ActivityHeartbeatRecorded" => format!("Recorded heartbeat for {activity_label}."),
        "ActivityRetryScheduled" => format!(
            "Scheduled retry {} for {activity_label}.",
            int(&payload["retry_after_attempt"]).unwrap_or(0) + 1
        ),
        "ActivityCompleted" => format!("Completed {activity_label}."),
        "ActivityFailed" => message.map_or_else(
            || format!("Failed {activity_label}."),
            |message| format!("Failed {activity_label}: {message}."),
        ),
        "ActivityCancelled" => format!("Cancelled {activity_label}."),
        "ActivityCancellationAcknowledged" => format!(
            "Worker reported stopped callback for {activity_label}{}.",
            if payload["received_after_deadline"] == true { " after the cleanup deadline" } else { "" }
        ),
        "ActivityTimedOut" => message.map_or_else(
            || format!("Timed out {activity_label}."),
            |message| format!("Timed out {activity_label}: {message}."),
        ),
        "FailureHandled" => message.map_or_else(|| "Failure handled.".to_owned(), |message| format!("Handled failure: {message}.")),
        "SideEffectRecorded" => "Recorded side effect.".to_owned(),
        "SearchAttributesUpserted" => "Search attributes upserted.".to_owned(),
        "MemoUpserted" => "Memo upserted.".to_owned(),
        "VersionMarkerRecorded" => match (change_id, version) {
            (Some(change), Some(version)) => format!("Recorded version marker {change} = {version}."),
            (Some(change), None) => format!("Recorded version marker {change}."),
            (None, Some(version)) => format!("Recorded version marker {version}."),
            (None, None) => "Recorded version marker.".to_owned(),
        },
        "TimerScheduled" => {
            let noun = match timer_kind {
                Some("condition_timeout") => "condition timeout",
                Some("signal_timeout") => "signal timeout",
                _ => "timer",
            };
            delay.map_or_else(|| format!("Scheduled {noun}."), |seconds| format!("Scheduled {noun} for {}.", duration_label(seconds)))
        }
        "TimerFired" => {
            let noun = match timer_kind {
                Some("condition_timeout") => "Condition timeout",
                Some("signal_timeout") => "Signal timeout",
                _ => "Timer",
            };
            delay.map_or_else(|| format!("{noun} fired."), |seconds| format!("{noun} fired after {}.", duration_label(seconds)))
        }
        "TimerCancelled" => match timer_kind {
            Some("condition_timeout") => "Condition timeout cancelled.".to_owned(),
            Some("signal_timeout") => "Signal timeout cancelled.".to_owned(),
            _ => "Timer cancelled.".to_owned(),
        },
        "ChildCancellationRequested" => format!(
            "Child cancellation {} under policy {}{}.",
            string(&payload["request_outcome"]).unwrap_or("requested"),
            text("policy"),
            string(&payload["rejection_reason"]).map(|reason| format!(": {reason}")).unwrap_or_default()
        ),
        "ChildCancellationResolved" => {
            format!("Child cancellation resolved with durable outcome {}.", text("child_status"))
        }
        "ParentCloseCancellationRequested" => format!(
            "Parent-close policy requested cooperative child cleanup, deadline {}.",
            string(&payload["cancellation"]["cleanup_deadline_at"]).unwrap_or("unknown")
        ),
        "ParentClosePolicyApplied" => {
            format!("Applied parent-close policy {} to child {}.", text("policy"), text("child_instance_id"))
        }
        "ParentClosePolicyFailed" => format!(
            "Failed to apply parent-close policy {} to child {}: {}",
            text("policy"),
            text("child_instance_id"),
            string(&payload["error"]).unwrap_or("unknown error")
        ),
        "MessageCursorAdvanced" => format!("Message cursor advanced to {}.", int(&payload["new_position"]).unwrap_or(0)),
        "WorkflowCompleted" => "Workflow completed.".to_owned(),
        "WorkflowTimedOut" => match payload["timeout_kind"].as_str() {
            Some("execution_timeout") => "Workflow execution deadline expired.".to_owned(),
            Some("run_timeout") => "Workflow run deadline expired.".to_owned(),
            _ => message.map_or_else(|| "Workflow timed out.".to_owned(), |message| format!("Workflow timed out: {message}.")),
        },
        "WorkflowFailed" => message.map_or_else(|| "Workflow failed.".to_owned(), |message| format!("Workflow failed: {message}.")),
        "ScheduleCreated" => format!("Schedule {} created.", text("schedule_id")),
        "SchedulePaused" => format!("Schedule {} paused.", text("schedule_id")),
        "ScheduleResumed" => format!("Schedule {} resumed.", text("schedule_id")),
        "ScheduleUpdated" => format!("Schedule {} updated.", text("schedule_id")),
        "ScheduleTriggered" => format!(
            "Triggered by schedule {} (trigger #{}).",
            text("schedule_id"),
            int(&payload["trigger_number"]).unwrap_or(0)
        ),
        "ScheduleDeleted" => format!("Schedule {} deleted.", text("schedule_id")),
        "ScheduleTriggerSkipped" => format!(
            "Schedule {} trigger skipped: {}.",
            text("schedule_id"),
            string(&payload["reason"]).unwrap_or("unknown reason")
        ),
        // A history event type the published enum does not name never maps.
        other => format!("{other}."),
    };
    let notice = if payload["local_recovery"]["callback_stop_state"] == "unknown" {
        " The previous attempt lost authority. Callback stop is unknown."
    } else {
        ""
    };
    bounded_summary(&summary, notice)
}

/// `boundedSummary`: at most 65,535 bytes, cut on a character boundary.
fn bounded_summary(summary: &str, notice: &str) -> String {
    if summary.len() + notice.len() <= MAX_SUMMARY_BYTES {
        return format!("{summary}{notice}");
    }
    let mut end = MAX_SUMMARY_BYTES - SUMMARY_TRUNCATION_MARKER.len() - notice.len();
    while !summary.is_char_boundary(end) {
        end -= 1;
    }
    format!("{}{SUMMARY_TRUNCATION_MARKER}{notice}", &summary[..end])
}

/// `commandMetadata`. Native records no caller, principal or request
/// context, so those fields stay null.
fn command_metadata(
    kind: &str,
    command: Option<&Value>,
    payload: &Value,
    command_id: Option<&str>,
    context: &Context<'_>,
) -> Value {
    let snapshot = object(&payload["command"]);
    let snap = |name: &str| snapshot.and_then(|snapshot| snapshot.get(name)).unwrap_or(&Value::Null);
    let row = |name: &str| command.map_or(&Value::Null, |command| &command[name]);
    let resolved_id = first(&[row("id")]).or_null_then(|| command_id.map_or(Value::Null, |id| json!(id)));
    let resolved_id = resolved_id.or_null_then(|| string_value(snap("id")));
    if command.is_none()
        && resolved_id.is_null()
        && snapshot.is_none()
        && payload.get("outcome").is_none()
        && payload.get("rejection_reason").is_none()
        && payload.get("command_type").is_none()
    {
        return Value::Null;
    }
    let codec = first(&[snap("payload_codec"), row("payload_codec")]);
    let snapshot_envelope = external_envelope(snap("payload"));
    let blob = string(snap("payload")).or_else(|| string(row("payload")));
    let stored = snapshot_envelope.is_some() || blob.is_some_and(|blob| stored_envelope(blob).is_some());
    let target = string_value(snap("target_name"))
        .or_null_then(|| if stored { Value::Null } else { string_value(row("target_name")) })
        .or_null_then(|| first(&[&payload["signal_name"], &payload["update_name"]]));
    let preview = snapshot_envelope.clone().unwrap_or_else(|| preview(blob, codec.as_str(), context));
    json!({
        "id": resolved_id,
        "sequence": int(snap("sequence")).map_or_else(|| row("command_sequence").clone(), |sequence| json!(sequence)),
        "type": first(&[snap("type"), row("command_type"), &payload["command_type"]]),
        "target_scope": first(&[snap("target_scope"), row("target_scope")]),
        "requested_run_id": first(&[snap("requested_run_id"), row("requested_workflow_run_id")]),
        "resolved_run_id": first(&[snap("resolved_run_id"), row("resolved_workflow_run_id")]),
        "target_name": target,
        "payload_codec": codec,
        "payload_available": snapshot_envelope.is_some() || blob.is_some(),
        "payload": preview,
        "source": first(&[snap("source"), row("source")]),
        "context": if snap("context").is_object() || snap("context").is_array() { snap("context").clone() } else { Value::Null },
        "caller_label": string_value(snap("caller_label")),
        "principal_type": string_value(snap("principal_type")),
        "principal_id": string_value(snap("principal_id")),
        "principal_label": string_value(snap("principal_label")),
        "auth_status": string_value(snap("auth_status")),
        "auth_method": string_value(snap("auth_method")),
        "request_method": string_value(snap("request_method")),
        "request_path": string_value(snap("request_path")),
        "request_route_name": string_value(snap("request_route_name")),
        "request_fingerprint": string_value(snap("request_fingerprint")),
        "status": command_status(kind, command),
        "outcome": command_outcome(kind, command, payload),
        "rejection_reason": first(&[snap("rejection_reason"), row("rejection_reason"), &payload["rejection_reason"]]),
        "accepted_at": first(&[snap("accepted_at"), row("accepted_at")]),
        "applied_at": first(&[snap("applied_at"), row("applied_at")]),
        "rejected_at": first(&[snap("rejected_at"), row("rejected_at")]),
    })
}

fn external_envelope(value: &Value) -> Option<Value> {
    value.get("external_storage").filter(|storage| storage.is_object()).map(|_| value.clone())
}

/// `CommandPayloadPreview::previewWithCodec`: the decoded value, the stored
/// envelope of an external payload, or the blob itself when it does not decode.
fn preview(blob: Option<&str>, codec: Option<&str>, context: &Context<'_>) -> Value {
    let Some(blob) = blob else {
        return Value::Null;
    };
    if let Some(envelope) = stored_envelope(blob) {
        return envelope;
    }
    if !matches!(codec, None | Some("avro")) {
        return json!(blob);
    }
    context
        .codec
        .and_then(|codec| codec.decode(blob).ok())
        .map_or_else(|| json!(blob), context.project)
}

fn command_status(kind: &str, command: Option<&Value>) -> Value {
    match kind {
        "StartRejected" | "UpdateRejected" => json!("rejected"),
        "StartAccepted" | "SignalReceived" | "UpdateAccepted" | "UpdateApplied" | "UpdateCompleted" | "RepairRequested"
        | "CancelRequested" | "CooperativeCancellationRequested" | "TerminateRequested" | "ArchiveRequested" => {
            json!("accepted")
        }
        _ => command.map_or(Value::Null, |command| string_value(&command["status"])),
    }
}

fn command_outcome(kind: &str, command: Option<&Value>, payload: &Value) -> Value {
    if let Some(outcome) = string(&payload["outcome"]) {
        return json!(outcome);
    }
    match kind {
        "SignalReceived" => json!("signal_received"),
        "UpdateAccepted" | "UpdateApplied" => Value::Null,
        "UpdateCompleted" => json!(if payload.get("failure_id").is_some() { "update_failed" } else { "update_completed" }),
        "CancelRequested" => json!("cancelled"),
        "CooperativeCancellationRequested" => json!("cancellation_requested"),
        "TerminateRequested" => json!("terminated"),
        "ArchiveRequested" => json!("archived"),
        _ => command.map_or(Value::Null, |command| string_value(&command["outcome"])),
    }
}

fn local_activity(payload: &Value) -> bool {
    payload["local_activity"] == true || payload["execution_mode"] == "local"
}

/// `taskMetadata`.
fn task_metadata(kind: &str, task: Option<&Value>, payload: &Value, task_id: Option<&str>) -> Value {
    let snapshot = object(&payload["task"]);
    let snap = |name: &str| snapshot.and_then(|snapshot| snapshot.get(name)).unwrap_or(&Value::Null);
    let row = |name: &str| task.map_or(&Value::Null, |task| &task[name]);
    let resolved = first(&[row("id")])
        .or_null_then(|| task_id.map_or(Value::Null, |id| json!(id)))
        .or_null_then(|| string_value(snap("id")));
    if task.is_none() && resolved.is_null() && snapshot.is_none() {
        return Value::Null;
    }
    let times = |name: &str| first(&[snap(name), row(name)]);
    if kind == "RepairRequested" {
        return json!({"id": resolved, "type": first(&[&payload["task_type"], snap("type"), row("task_type")]),
            "status": if resolved.is_null() { Value::Null } else { json!("ready") },
            "available_at": times("available_at"), "leased_at": times("leased_at"),
            "lease_expires_at": times("lease_expires_at"), "attempt_count": null});
    }
    let has_id = !resolved.is_null();
    let historical_type = has_id.then(|| {
        if local_activity(payload) {
            "workflow"
        } else {
            match kind {
                "ActivityStarted" | "ActivityHeartbeatRecorded" | "ActivityRetryScheduled" | "ActivityCompleted"
                | "ActivityFailed" | "ActivityCancelled" | "ActivityCancellationAcknowledged" | "ActivityTimedOut" => {
                    "activity"
                }
                "TimerFired" => "timer",
                _ => "workflow",
            }
        }
    });
    let historical_status = if !has_id {
        None
    } else if local_activity(payload) {
        string(&payload["task"]["status"])
    } else {
        Some(match kind {
            "ActivityStarted" | "ActivityHeartbeatRecorded" => "leased",
            "ActivityCancelled" | "ActivityCancellationAcknowledged" => "cancelled",
            "WorkflowFailed" => "failed",
            _ => "completed",
        })
    };
    json!({
        "id": resolved,
        "type": historical_type.map_or_else(|| first(&[snap("type"), row("task_type")]), |kind| json!(kind)),
        "status": historical_status.map_or_else(|| first(&[snap("status"), row("status")]), |status| json!(status)),
        "available_at": times("available_at"),
        "leased_at": times("leased_at"),
        "lease_expires_at": times("lease_expires_at"),
        "attempt_count": if snapshot.is_some_and(|snapshot| snapshot.contains_key("attempt_count")) {
            int_value(snap("attempt_count"))
        } else {
            row("attempt_count").clone()
        },
    })
}

/// `ActivitySnapshot::fromEvent`, the fields the timeline reads.
fn activity_snapshot(event: &Value, kind: &str, payload: &Value) -> Option<Map<String, Value>> {
    let snapshot = object(&payload["activity"]);
    let snap = |name: &str| snapshot.and_then(|snapshot| snapshot.get(name)).unwrap_or(&Value::Null);
    let activity_id = first(&[snap("id"), &payload["activity_execution_id"]]);
    if activity_id.is_null()
        && snapshot.is_none_or(Map::is_empty)
        && payload.get("activity_type").is_none()
        && payload.get("activity_class").is_none()
    {
        return None;
    }
    let mut merged = Map::new();
    merged.insert("id".into(), activity_id);
    merged.insert("idempotency_key".into(), string_value(&payload["idempotency_key"]));
    merged.insert("sequence".into(), int_value(&payload["sequence"]));
    merged.insert("type".into(), string_value(&payload["activity_type"]));
    merged.insert("class".into(), string_value(&payload["activity_class"]));
    merged.insert("attempt_count".into(), int_value(&payload["attempt_number"]));
    merged.insert("retry_policy".into(), if payload["retry_policy"].is_object() || payload["retry_policy"].is_array() {
        payload["retry_policy"].clone()
    } else {
        Value::Null
    });
    merged.insert("started_at".into(), if kind == "ActivityStarted" { string_value(&event["recorded_at"]) } else { Value::Null });
    let closing = matches!(kind, "ActivityCompleted" | "ActivityFailed" | "ActivityCancelled" | "ActivityTimedOut");
    merged.insert("closed_at".into(), if closing { string_value(&event["recorded_at"]) } else { Value::Null });
    if let Some(snapshot) = snapshot {
        for (name, value) in snapshot {
            if !value.is_null() {
                merged.insert(name.clone(), value.clone());
            }
        }
    }
    Some(merged)
}

/// `activityMetadata`.
fn activity_metadata(
    event: &Value,
    kind: &str,
    activity: Option<&Value>,
    payload: &Value,
    activity_id: Option<&str>,
    failure: Option<&Value>,
) -> Value {
    let snapshot = activity_snapshot(event, kind, payload);
    if snapshot.is_none()
        && activity.is_none()
        && activity_id.is_none()
        && payload.get("activity_type").is_none()
        && payload.get("activity_class").is_none()
    {
        return Value::Null;
    }
    let snap = |name: &str| snapshot.as_ref().and_then(|snapshot| snapshot.get(name)).unwrap_or(&Value::Null);
    let row = |name: &str| activity.map_or(&Value::Null, |activity| &activity[name]);
    let resolved = first(&[row("id")])
        .or_null_then(|| activity_id.map_or(Value::Null, |id| json!(id)))
        .or_null_then(|| match failure {
            Some(failure) if failure["source_kind"] == "activity_execution" => string_value(&failure["source_id"]),
            _ => Value::Null,
        });
    let status = string_value(snap("status")).or_null_then(|| match kind {
        "ActivityScheduled" | "ActivityRetryScheduled" => json!("pending"),
        "ActivityStarted" | "ActivityHeartbeatRecorded" => json!("running"),
        "ActivityCompleted" => json!("completed"),
        "ActivityFailed" | "ActivityTimedOut" => json!("failed"),
        "ActivityCancelled" | "ActivityCancellationAcknowledged" => json!("cancelled"),
        _ => string_value(row("status")),
    });
    let attempt_count = int(snap("attempt_count")).map_or_else(
        || {
            if kind == "ActivityScheduled" {
                if row("status") == "pending" && row("started_at").is_null() {
                    json!(0)
                } else {
                    json!(row("attempt_count").as_i64().unwrap_or(1))
                }
            } else {
                row("attempt_count").clone()
            }
        },
        |count| json!(count),
    );
    let attempt_id = string_value(snap("attempt_id"))
        .or_null_then(|| {
            if kind == "ActivityCancellationAcknowledged" { string_value(&payload["activity_attempt_id"]) } else { Value::Null }
        })
        .or_null_then(|| if kind == "ActivityScheduled" { Value::Null } else { string_value(row("current_attempt_id")) });
    let retry_policy = if snap("retry_policy").is_object() || snap("retry_policy").is_array() {
        snap("retry_policy").clone()
    } else {
        row("retry_policy").clone()
    };
    let closing = matches!(kind, "ActivityCompleted" | "ActivityFailed" | "ActivityTimedOut" | "ActivityCancelled");
    let started_at = if kind == "ActivityScheduled" {
        Value::Null
    } else {
        first(&[snap("started_at"), row("started_at")])
            .or_null_then(|| if kind == "ActivityStarted" { string_value(&event["recorded_at"]) } else { Value::Null })
    };
    json!({
        "id": string_value(snap("id")).or_null_then(|| resolved.clone()),
        "idempotency_key": string_value(snap("idempotency_key")).or_null_then(|| resolved.clone()),
        "sequence": int(snap("sequence")).map_or_else(
            || if row("sequence").is_null() { int_value(&payload["sequence"]) } else { row("sequence").clone() },
            |sequence| json!(sequence)),
        "type": first(&[snap("type"), row("activity_type"), &payload["activity_type"]]),
        "class": first(&[snap("class"), row("activity_class"), &payload["activity_class"]]),
        "parallel_group_path": parallel_path(payload),
        "attempt_id": attempt_id,
        "status": status,
        "attempt_count": attempt_count,
        "retry_policy": retry_policy,
        "connection": first(&[snap("connection"), row("connection")]),
        "queue": first(&[snap("queue"), row("queue")]),
        "started_at": started_at,
        "closed_at": if closing { first(&[snap("closed_at"), row("closed_at")]) } else { Value::Null },
        "last_heartbeat_at": first(&[snap("last_heartbeat_at"), row("last_heartbeat_at")]),
        "last_heartbeat_progress": heartbeat_progress(if payload["progress"].is_null() {
            snap("last_heartbeat_progress")
        } else {
            &payload["progress"]
        }),
    })
}

/// `HeartbeatProgress::fromStored`: a non-empty progress object, else null.
fn heartbeat_progress(progress: &Value) -> Value {
    match progress {
        Value::Object(fields) if !fields.is_empty() => progress.clone(),
        _ => Value::Null,
    }
}

/// `timerMetadata`, with the export's rebuilt timers as the timer rows.
fn timer_metadata(kind: &str, timer: Option<&Value>, payload: &Value, timer_id: Option<&str>) -> Value {
    if timer.is_none()
        && timer_id.is_none()
        && ["delay_seconds", "fire_at", "fired_at", "cancelled_at"].iter().all(|name| payload.get(*name).is_none())
    {
        return Value::Null;
    }
    let row = |name: &str| timer.map_or(&Value::Null, |timer| &timer[name]);
    json!({
        "id": timer_id.map_or_else(|| row("id").clone(), |id| json!(id)),
        "sequence": int(&payload["sequence"]).map_or_else(|| row("sequence").clone(), |sequence| json!(sequence)),
        "status": match kind {
            "TimerScheduled" => json!("pending"),
            "TimerFired" => json!("fired"),
            "TimerCancelled" => json!("cancelled"),
            _ => row("status").clone(),
        },
        "delay_seconds": int(&payload["delay_seconds"]).map_or_else(|| row("delay_seconds").clone(), |seconds| json!(seconds)),
        "fire_at": first(&[&payload["fire_at"], row("fire_at")]),
        "fired_at": if kind == "TimerScheduled" { Value::Null } else { first(&[&payload["fired_at"], row("fired_at")]) },
        "cancelled_at": if kind == "TimerCancelled" {
            first(&[&payload["cancelled_at"], row("cancelled_at")])
        } else {
            Value::Null
        },
    })
}

/// `childMetadata`.
fn child_metadata(kind: &str, payload: &Value) -> Value {
    let named = ["child_call_id", "child_workflow_instance_id", "child_workflow_run_id", "child_workflow_type",
        "child_workflow_class"]
    .iter()
    .any(|name| payload.get(*name).is_some());
    if !named && !CHILD_EVENTS.contains(&kind) {
        return Value::Null;
    }
    let legacy_call = if matches!(kind, "ChildWorkflowScheduled" | "ChildRunStarted") {
        string_value(&payload["workflow_link_id"])
    } else {
        Value::Null
    };
    let mut child = json!({
        "child_call_id": string_value(&payload["child_call_id"]).or_null_then(|| legacy_call),
        "instance_id": string_value(&payload["child_workflow_instance_id"]),
        "run_id": string_value(&payload["child_workflow_run_id"]),
        "type": string_value(&payload["child_workflow_type"]),
        "class": string_value(&payload["child_workflow_class"]),
        "status": string_value(&payload["child_status"]).or_null_then(|| match kind {
            "ChildRunCompleted" => json!("completed"),
            "ChildRunFailed" => json!("failed"),
            "ChildRunCancelled" => json!("cancelled"),
            "ChildRunTerminated" => json!("terminated"),
            _ => Value::Null,
        }),
        "run_number": int_value(&payload["child_run_number"]),
        "parallel_group_path": parallel_path(payload),
    });
    if matches!(kind, "ChildCancellationRequested" | "ChildCancellationResolved") {
        child["cancellation"] = json!({
            "policy": string_value(&payload["policy"]),
            "parent_request_id": string_value(&payload["parent_request_id"]),
            "root_request_id": string_value(&payload["root_request_id"]),
            "cleanup_deadline_at": string_value(&payload["cleanup_deadline_at"]),
            "child_request_id": string_value(&payload["child_request_id"]),
            "child_root_request_id": string_value(&payload["child_root_request_id"]),
            "child_cleanup_deadline_at": string_value(&payload["child_cleanup_deadline_at"]),
            "request_outcome": string_value(&payload["request_outcome"]),
            "rejection_reason": string_value(&payload["rejection_reason"]),
            "terminal_history_event_id": string_value(&payload["child_terminal_history_event_id"]),
            "terminal_event_type": string_value(&payload["child_terminal_event_type"]),
        });
    }
    child
}

/// `failureMetadata`, with the export's failure snapshot.
fn failure_metadata(kind: &str, failure: Option<&Value>, payload: &Value, failure_id: Option<&str>) -> Value {
    if failure.is_none()
        && failure_id.is_none()
        && ["message", "exception_class", "source_kind", "source_id"].iter().all(|name| payload.get(*name).is_none())
    {
        return Value::Null;
    }
    let row = |name: &str| failure.map_or(&Value::Null, |failure| &failure[name]);
    let id = first(&[row("id")]).or_null_then(|| failure_id.map_or(Value::Null, |id| json!(id)));
    let known = !id.is_null();
    let propagation = match kind {
        "ActivityFailed" => json!("activity"),
        "ActivityTimedOut" | "WorkflowTimedOut" => json!("timeout"),
        "ChildRunFailed" => json!("child"),
        "ChildRunCancelled" | "WorkflowCancelled" => json!("cancelled"),
        "ChildRunTerminated" | "WorkflowTerminated" => json!("terminated"),
        "WorkflowFailed" => json!("terminal"),
        "FailureHandled" => first(&[&payload["propagation_kind"], row("propagation_kind")]),
        "UpdateCompleted" => if known { json!("update") } else { Value::Null },
        _ => string_value(row("propagation_kind")),
    };
    let category = first(&[&payload["failure_category"], row("failure_category")]).or_null_then(|| match kind {
        "ActivityFailed" => json!("activity"),
        "ActivityTimedOut" | "WorkflowTimedOut" => json!("timeout"),
        "ChildRunFailed" => json!("child_workflow"),
        "ChildRunCancelled" | "WorkflowCancelled" => json!("cancelled"),
        "ChildRunTerminated" | "WorkflowTerminated" => json!("terminated"),
        "WorkflowFailed" => json!("application"),
        "UpdateCompleted" if known => json!("application"),
        _ => Value::Null,
    });
    let handled = match kind {
        "FailureHandled" => json!(true),
        "ActivityFailed" | "ActivityTimedOut" | "WorkflowTimedOut" | "WorkflowFailed" => json!(false),
        "UpdateCompleted" => if known { json!(false) } else { Value::Null },
        _ => if row("handled").is_boolean() { row("handled").clone() } else { Value::Null },
    };
    let non_retryable = if payload.get("non_retryable").is_some_and(|value| !value.is_null()) {
        truthy(&payload["non_retryable"])
    } else {
        truthy(row("non_retryable"))
    };
    json!({
        "id": id,
        "source_kind": first(&[&payload["source_kind"], row("source_kind")]),
        "source_id": first(&[&payload["source_id"], row("source_id")]),
        "propagation_kind": propagation,
        "failure_category": category,
        "non_retryable": non_retryable,
        "handled": handled,
        "exception_type": first(&[&payload["exception_type"], row("exception_type")]),
        "exception_class": first(&[&payload["exception_class"], row("exception_class")]),
        "exception_resolved_class": string_value(row("exception_resolved_class")),
        "exception_resolution_source": string_value(row("exception_resolution_source")),
        "exception_resolution_error": string_value(row("exception_resolution_error")),
        "exception_replay_blocked": truthy(row("exception_replay_blocked")),
        "message": first(&[&payload["message"], row("message")]),
        "file": string_value(row("file")),
        "line": int_value(row("line")),
    })
}

/// PHP's `(bool)` cast.
fn truthy(value: &Value) -> bool {
    match value {
        Value::Null => false,
        Value::Bool(value) => *value,
        Value::Number(number) => number.as_f64().is_some_and(|number| number != 0.0),
        Value::String(text) => !text.is_empty() && text != "0",
        Value::Array(values) => !values.is_empty(),
        Value::Object(fields) => !fields.is_empty(),
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn summaries_follow_php() {
        assert_eq!(duration_label(1), "1 second");
        assert_eq!(duration_label(3), "3 seconds");
        assert_eq!(display_label("App\\Workflows\\Order"), "Order");
        let long = "é".repeat(40_000);
        let bounded = bounded_summary(&long, "");
        assert!(bounded.len() <= MAX_SUMMARY_BYTES);
        assert!(bounded.ends_with(SUMMARY_TRUNCATION_MARKER));
    }

    #[test]
    fn parallel_metadata_requires_a_complete_group() {
        let payload = json!({"parallel_group_id": "parallel-activities:3:2", "parallel_group_base_sequence": 3,
            "parallel_group_size": 2, "parallel_group_index": 1});
        assert_eq!(parallel_path(&payload), vec![json!({"parallel_group_id": "parallel-activities:3:2",
            "parallel_group_kind": "activity", "parallel_group_base_sequence": 3, "parallel_group_size": 2,
            "parallel_group_index": 1})]);
        assert!(parallel_path(&json!({"parallel_group_id": "x"})).is_empty());
    }
}
