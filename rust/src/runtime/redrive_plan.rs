//! `FailedRunRedrivePlan::forRun` and `ActivityRetryHistory::valid` as
//! embedded Workflow 2.33.0 decides them, over a source run's stored history
//! (server#980).
//!
//! The plan accepts and refuses the same histories as Core, with the same
//! reason names. It keeps the steps the successor records: completed
//! activities (including ones whose attempts were retried), side effects,
//! version markers, memo and search-attribute upserts, and fired plain
//! timers. Reused signal waits, completed child calls and applied updates
//! are validated as Core validates them and then only reported, because no
//! service SDK is qualified to replay them yet and every native run is a
//! service run (`FailedRunRedriveEligibility::reusesServiceUnqualifiedSteps`).
use chrono::{DateTime, Utc};
use serde_json::Value;
use std::collections::{BTreeMap, BTreeSet};

/// One stored history event of the source run, in history order.
#[derive(Clone, Debug, PartialEq)]
pub(super) struct Event {
    pub sequence: i64,
    pub kind: String,
    pub payload: Value,
    pub recorded_at: DateTime<Utc>,
    pub task_id: Option<String>,
    pub command_id: Option<String>,
}

/// The source run's identity, as the plan's provenance checks read it.
pub(super) struct SourceRun<'a> {
    pub id: &'a str,
    pub instance_id: &'a str,
    pub workflow_class: &'a str,
    pub workflow_type: &'a str,
    pub status: &'a str,
    pub payload_codec: Option<&'a str>,
    /// Earlier runs of the instance. Read only when the source is itself a
    /// redrive successor (`FailedRunRedrivePlan::provenance`).
    pub earlier_runs: &'a BTreeSet<String>,
}

/// One step the new run reuses: an `ActivityCompleted`, a marker step
/// (`SideEffectRecorded`, `VersionMarkerRecorded`, `MemoUpserted`,
/// `SearchAttributesUpserted`), or a fired plain timer without its source
/// identity, task and command. Timers retain both halves of their history pair.
pub(super) struct Reused {
    pub kind: String,
    pub payload: Value,
    pub recorded_at: DateTime<Utc>,
    pub scheduled_payload: Option<Value>,
}

/// An accepted plan.
pub(super) struct Plan {
    pub resume: i64,
    pub failed_step_kind: String,
    pub reused: Vec<Reused>,
    /// A reused signal wait, completed child call, applied update, or memo
    /// or search-attribute upsert: steps no service SDK replays yet.
    pub service_unqualified: bool,
}

/// Whether an inline payload decodes with the named codec
/// (`Serializer::unserializeWithCodec`). Injected so the pure plan can be
/// tested without a codec.
pub(super) type Decodes<'a> = &'a dyn Fn(&str, &str) -> bool;

/// The marker steps `FailedRunRedrivePlan::forRun` reuses as recorded.
const MARKERS: [&str; 4] = ["SideEffectRecorded", "VersionMarkerRecorded", "MemoUpserted", "SearchAttributesUpserted"];
const UPDATES: [&str; 4] = ["UpdateAccepted", "UpdateRejected", "UpdateApplied", "UpdateCompleted"];
const SIGNALS: [&str; 3] = ["SignalWaitOpened", "SignalReceived", "SignalApplied"];
const CHILDREN: [&str; 8] = ["ChildWorkflowScheduled", "ChildRunStarted", "ChildRunCompleted", "ChildRunFailed",
    "ChildRunCancelled", "ChildRunTerminated", "ChildCancellationRequested", "ChildCancellationResolved"];
const ACTIVITIES: [&str; 6] = ["ActivityScheduled", "ActivityStarted", "ActivityRetryScheduled",
    "ActivityHeartbeatRecorded", "ActivityCompleted", "ActivityFailed"];

fn stored_reference(value: &str) -> bool {
    value.starts_with(super::external_resolution::STORED_PREFIX)
}

/// `FailedRunRedrivePlan::isExternalResult`, in native's stored forms too.
pub(super) fn external(result: &Value) -> bool {
    ["external_payload", "external_storage"].iter().any(|key| result.get(key).is_some_and(|value| !value.is_null()))
        || result.as_str().is_some_and(stored_reference)
}

/// `holdsExternalPayload`: any external-storage shape, including a key whose
/// value is null.
fn holds_external(value: &Value) -> bool {
    value.get("external_storage").is_some() || value.get("external_payload").is_some()
        || value.as_str().is_some_and(stored_reference)
}

/// `ExternalPayloads::payloadBlob` for an inline payload: a blob string, or
/// an envelope carrying one.
fn inline_blob(value: &Value) -> Option<&str> {
    match value {
        Value::String(blob) if !stored_reference(blob) => Some(blob),
        Value::Object(fields) => fields.get("blob").and_then(Value::as_str),
        _ => None,
    }
}

/// Core's `isPlainTimer`: group, scope, cleanup and timeout timers have their
/// own outstanding dependencies and cannot be copied as an ordinary sleep.
fn plain_timer(payload: &Value) -> bool {
    payload.is_object() && ["timer_kind", "cancellation_cleanup", "cancellation_scope_id", "parallel_group_id",
        "condition_wait_id", "signal_wait_id"].iter().all(|field| payload[*field].is_null())
}

fn step_sequence(payload: &Value) -> Option<i64> {
    payload["sequence"].as_i64().filter(|sequence| *sequence >= 1)
}

fn nonempty(value: &Value) -> Option<&str> {
    value.as_str().filter(|value| !value.is_empty())
}

/// `FailedRunRedrivePlan::provenance`.
struct Provenance<'a> {
    immediate_source: Option<&'a str>,
    earlier_runs: &'a BTreeSet<String>,
}

impl Provenance<'_> {
    /// `hasReuseProvenance`: both reuse keys, or neither. Both name the
    /// immediate redrive source and a parseable first-recorded time.
    fn has_reuse(&self, payload: &Value) -> bool {
        let source = payload.get("reused_from_run_id").is_some();
        if source != payload.get("reused_recorded_at").is_some() {
            return false;
        }
        if !source {
            return true;
        }
        let Some(immediate) = self.immediate_source else {
            return false;
        };
        payload["reused_from_run_id"] == immediate
            && payload["reused_recorded_at"].as_str().is_some_and(|at| !at.trim().is_empty() && parses(at))
    }

    /// `boundToRun`: recorded by this run, or reused by it from an earlier
    /// run of the instance.
    fn bound(&self, payload: &Value, run: &SourceRun) -> bool {
        if payload["workflow_instance_id"] != run.instance_id || !self.has_reuse(payload) {
            return false;
        }
        if payload.get("reused_from_run_id").is_none() {
            return payload["workflow_run_id"] == run.id;
        }
        payload["workflow_run_id"].as_str().is_some_and(|original| self.earlier_runs.contains(original))
    }
}

/// PHP's `strtotime` for the timestamps redrive itself writes.
fn parses(at: &str) -> bool {
    DateTime::parse_from_rfc3339(at).is_ok()
        || chrono::NaiveDateTime::parse_from_str(at, "%Y-%m-%d %H:%M:%S%.f").is_ok()
        || chrono::NaiveDateTime::parse_from_str(at, "%Y-%m-%dT%H:%M:%S%.f").is_ok()
}

/// `isActiveStartAdoption`: a native start under `use-existing` records an
/// accepted command receipt on the active run, not a second start.
fn active_start_adoption(run: &SourceRun, event: &Event) -> bool {
    let payload = &event.payload;
    let command = &payload["command"];
    if !command.is_object() || payload["outcome"] != "returned_existing_active" || !payload["rejection_reason"].is_null() {
        return false;
    }
    for (key, expected) in [("workflow_instance_id", run.instance_id), ("workflow_run_id", run.id),
        ("workflow_class", run.workflow_class), ("workflow_type", run.workflow_type)] {
        if expected.is_empty() || payload[key] != expected {
            return false;
        }
    }
    let Some(command_id) = nonempty(&payload["workflow_command_id"]) else {
        return false;
    };
    event.command_id.as_deref() == Some(command_id)
        && command["id"] == command_id
        && command["type"] == "start"
        && command["target_scope"] == "instance"
        && command["resolved_run_id"] == run.id
        && command["status"] == "accepted"
        && command["outcome"] == "returned_existing_active"
        && command["rejection_reason"].is_null()
}

/// `ActivityRetryHistory::valid`: a retried step is one execution whose
/// attempts start, retry and close in order, each on its own task.
pub(super) fn valid_retry_history(events: &[&Event]) -> bool {
    if !events.iter().any(|event| event.kind == "ActivityRetryScheduled") {
        return true;
    }
    let mut phase = "schedule";
    let mut execution: Option<&str> = None;
    let (mut attempt, mut task): (Option<&str>, Option<&str>) = (None, None);
    let mut number = 0;
    let mut next_task: Option<&str> = None;
    let (mut attempts, mut tasks) = (BTreeSet::new(), BTreeSet::new());
    for event in events {
        let payload = &event.payload;
        let Some(id) = nonempty(&payload["activity_execution_id"]) else {
            return false;
        };
        if execution.is_some_and(|known| known != id) || phase == "terminal" {
            return false;
        }
        execution = Some(id);
        // An event's task: its stored column, or the payload's.
        let matches_task = |task: Option<&str>| {
            event.task_id.as_deref().is_none_or(|stored| Some(stored) == task)
                && payload.get("workflow_task_id").is_none_or(|recorded| recorded.as_str().is_some() && recorded.as_str() == task)
        };
        match event.kind.as_str() {
            "ActivityScheduled" => {
                if phase != "schedule" {
                    return false;
                }
                phase = "start";
            }
            "ActivityStarted" => {
                let current_attempt = nonempty(&payload["activity_attempt_id"]);
                let current_task = event.task_id.as_deref().or_else(|| payload["workflow_task_id"].as_str()).filter(|task| !task.is_empty());
                let (Some(current_attempt), Some(current_task)) = (current_attempt, current_task) else {
                    return false;
                };
                if !matches!(phase, "start" | "retry") || attempts.contains(current_attempt) || tasks.contains(current_task)
                    || payload["attempt_number"].as_i64() != Some(number + 1)
                    || (number > 0 && next_task != Some(current_task))
                    || !matches_task(Some(current_task)) {
                    return false;
                }
                attempt = Some(current_attempt);
                task = Some(current_task);
                attempts.insert(current_attempt);
                tasks.insert(current_task);
                number += 1;
                next_task = None;
                phase = "active";
            }
            "ActivityRetryScheduled" => {
                let retry = nonempty(&payload["retry_task_id"]);
                let maximum = &payload["max_attempts"];
                if phase != "active"
                    || payload["activity_attempt_id"].as_str() != attempt
                    || payload["retry_after_attempt_id"].as_str() != attempt
                    || payload["retry_after_attempt"].as_i64() != Some(number)
                    || payload["retry_of_task_id"].as_str() != task
                    || retry.is_none_or(|retry| tasks.contains(retry))
                    || (!maximum.is_null() && maximum.as_i64().is_none_or(|maximum| maximum <= number))
                    || !matches_task(task) {
                    return false;
                }
                next_task = retry;
                phase = "retry";
            }
            "ActivityHeartbeatRecorded" | "ActivityCompleted" | "ActivityFailed" => {
                if phase != "active" || payload["activity_attempt_id"].as_str() != attempt
                    || payload["attempt_number"].as_i64() != Some(number) || !matches_task(task) {
                    return false;
                }
                if event.kind != "ActivityHeartbeatRecorded" {
                    phase = "terminal";
                }
            }
            _ => return false,
        }
        if matches!(phase, "active" | "retry" | "terminal") {
            // `matchesAttemptSnapshot`: a recorded attempt snapshot agrees.
            if let Some(snapshot) = payload.get("activity_attempt") {
                let Some(fields) = snapshot.as_object() else {
                    return false;
                };
                let expected = [("id", attempt.map(Value::from)), ("activity_execution_id", execution.map(Value::from)),
                    ("attempt_number", Some(Value::from(number))), ("task_id", task.map(Value::from))];
                if expected.iter().any(|(field, value)| fields.get(*field).is_some_and(|recorded| Some(recorded) != value.as_ref())) {
                    return false;
                }
            }
        }
    }
    phase == "terminal"
}

/// `signalSteps`: completed plain signal waits by step sequence. Every
/// received signal must have been applied to one wait.
fn signal_steps<'e>(events: &[&'e Event], run: &SourceRun, provenance: &Provenance) -> Result<BTreeMap<i64, &'e Event>, &'static str> {
    let mut opened: BTreeMap<i64, &'e Event> = BTreeMap::new();
    let mut applied: BTreeMap<i64, &'e Event> = BTreeMap::new();
    let mut received: BTreeMap<&'e str, &'e Event> = BTreeMap::new();
    for event in events {
        let payload = &event.payload;
        if event.kind == "SignalReceived" {
            let Some(signal) = nonempty(&payload["signal_id"]) else {
                return Err("unsupported_signal_wait");
            };
            if received.insert(signal, event).is_some() {
                return Err("unsupported_signal_wait");
            }
            continue;
        }
        let Some(sequence) = step_sequence(payload) else {
            return Err("invalid_step_sequence");
        };
        if nonempty(&payload["signal_wait_id"]).is_none() || !payload["signal_name"].is_string() {
            return Err("invalid_step_sequence");
        }
        if ["timeout_seconds", "cancellation_scope_id", "parallel_group_id"].iter().any(|key| !payload[*key].is_null()) {
            return Err("unsupported_signal_wait");
        }
        let into = if event.kind == "SignalWaitOpened" { &mut opened } else { &mut applied };
        if into.insert(sequence, event).is_some() {
            return Err("duplicate_step_sequence");
        }
    }
    let mut steps = BTreeMap::new();
    let mut applied_signals = BTreeSet::new();
    for (sequence, wait) in &opened {
        let application = applied.get(sequence);
        let signal = application.and_then(|event| event.payload["signal_id"].as_str());
        let receipt = signal.and_then(|signal| received.get(signal));
        let (Some(application), Some(receipt), Some(signal)) = (application, receipt, signal) else {
            return Err("incomplete_signal_step");
        };
        let (wait_payload, applied_payload, receipt_payload) = (&wait.payload, &application.payload, &receipt.payload);
        if applied_payload["signal_wait_id"] != wait_payload["signal_wait_id"] || applied_payload["signal_name"] != wait_payload["signal_name"] {
            return Err("incomplete_signal_step");
        }
        let receipt_wait = &receipt_payload["signal_wait_id"];
        let command = nonempty(&applied_payload["workflow_command_id"]);
        if receipt_payload["signal_name"] != wait_payload["signal_name"]
            || (!receipt_wait.is_null() && *receipt_wait != wait_payload["signal_wait_id"])
            || command.is_none_or(|command| receipt_payload["workflow_command_id"] != command)
            || !provenance.bound(receipt_payload, run) {
            return Err("mismatched_signal_receipt");
        }
        for event in [wait, application, receipt] {
            if !provenance.has_reuse(&event.payload) || event.payload["reused_from_run_id"] != receipt_payload["reused_from_run_id"] {
                return Err("mismatched_signal_receipt");
            }
        }
        if !applied_signals.insert(signal) {
            return Err("mismatched_signal_receipt");
        }
        if applied_payload.get("value").is_none() {
            return Err("incomplete_signal_step");
        }
        if holds_external(&receipt_payload["arguments"]) || holds_external(&applied_payload["value"]) {
            return Err("external_signal_payload_not_supported");
        }
        steps.insert(*sequence, *application);
    }
    if applied.len() != opened.len() {
        return Err("incomplete_signal_step");
    }
    if received.keys().any(|signal| !applied_signals.contains(signal)) {
        return Err("unsupported_pending_signal");
    }
    Ok(steps)
}

/// `updateBundles`: whether any applied update is reused. A rejection, or an
/// acceptance the run's failure closed before it ran, is not a step.
fn update_bundles(events: &[&Event], run: &SourceRun, boundary: i64, provenance: &Provenance, decodes: Decodes)
    -> Result<bool, &'static str> {
    let mut by_update: BTreeMap<&str, Vec<&Event>> = BTreeMap::new();
    let mut order = Vec::new();
    for event in events {
        let Some(update) = nonempty(&event.payload["update_id"]) else {
            return Err("mismatched_update_history");
        };
        if !by_update.contains_key(update) {
            order.push(update);
        }
        by_update.entry(update).or_default().push(event);
    }
    let same_update = |events: &[&Event]| {
        let first = &events[0].payload;
        events.iter().all(|event| {
            let payload = &event.payload;
            ["update_id", "workflow_command_id", "update_name"].iter()
                .all(|key| nonempty(&payload[*key]).is_some() && payload[*key] == first[*key])
                && provenance.bound(payload, run)
                && payload["workflow_run_id"] == first["workflow_run_id"]
                && payload["reused_from_run_id"] == first["reused_from_run_id"]
        })
    };
    let codec = run.payload_codec.filter(|codec| !codec.is_empty()).unwrap_or("avro");
    let mut reused = false;
    for update in order {
        let mut events = by_update.remove(update).unwrap_or_default();
        events.sort_by_key(|event| event.sequence);
        let shape: Vec<&str> = events.iter().map(|event| event.kind.as_str()).collect();
        match shape.as_slice() {
            ["UpdateRejected"] => {
                if !same_update(&events) {
                    return Err("mismatched_update_history");
                }
                continue;
            }
            ["UpdateAccepted", "UpdateCompleted"] => {
                let closed = &events[1].payload;
                let filled = |key: &str| closed[key].as_str().is_some_and(|value| !value.trim().is_empty());
                if !same_update(&events) || !filled("terminal_reason") || !filled("failure_id")
                    || closed.get("result").is_some() || closed.get("sequence").is_some() {
                    return Err("mismatched_update_history");
                }
                continue;
            }
            ["UpdateAccepted"] => return Err("unsupported_pending_update"),
            ["UpdateAccepted", "UpdateApplied", "UpdateCompleted"] => {}
            _ => return Err("mismatched_update_history"),
        }
        let (accepted, applied, completed) = (&events[0].payload, &events[1].payload, &events[2].payload);
        if !same_update(&events) {
            return Err("mismatched_update_history");
        }
        if accepted.get("arguments").is_none() || applied.get("arguments").is_none() || accepted["arguments"] != applied["arguments"] {
            return Err("mismatched_update_history");
        }
        if completed.get("result").is_none() || completed.get("failure_id").is_some() || completed.get("terminal_reason").is_some() {
            return Err("incomplete_update_step");
        }
        let sequence = applied["sequence"].as_i64();
        if sequence.is_none_or(|sequence| sequence < 1 || sequence > boundary) || completed["sequence"].as_i64() != sequence {
            return Err("invalid_step_sequence");
        }
        for value in [&accepted["arguments"], &completed["result"]] {
            if holds_external(value) {
                return Err("external_update_payload_not_supported");
            }
            if !inline_blob(value).is_some_and(|blob| decodes(codec, blob)) {
                return Err("unavailable_update_payload");
            }
        }
        reused = true;
    }
    Ok(reused)
}

/// `completedChildStep`: a plain child call that completed before the
/// failure, with exactly its scheduled, started and completed events.
fn completed_child_step(events: &[&Event], run: &SourceRun, provenance: &Provenance, decodes: Decodes) -> Result<(), &'static str> {
    let kinds: Vec<&str> = events.iter().map(|event| event.kind.as_str()).collect();
    if kinds.iter().any(|kind| matches!(*kind, "ChildRunFailed" | "ChildRunCancelled" | "ChildRunTerminated")) {
        return Err("unsupported_child_outcome");
    }
    if !kinds.contains(&"ChildRunCompleted") {
        return Err(if kinds.contains(&"ChildCancellationRequested") || kinds.contains(&"ChildCancellationResolved") {
            "unsupported_child_workflow"
        } else {
            "unsupported_open_child"
        });
    }
    if kinds != ["ChildWorkflowScheduled", "ChildRunStarted", "ChildRunCompleted"] {
        return Err("unsupported_child_workflow");
    }
    let first = &events[0].payload;
    for event in events {
        let payload = &event.payload;
        if ["child_workflow_instance_id", "child_workflow_run_id", "child_workflow_type", "workflow_link_id"].iter()
            .any(|key| nonempty(&payload[*key]).is_none() || payload[*key] != first[*key])
            || payload["child_call_id"] != first["child_call_id"]
            || ["cancellation_scope_id", "parallel_group_id", "parallel_group_path", "retry_attempt", "retry_of_child_workflow_run_id"]
                .iter().any(|key| !payload[*key].is_null())
            || !provenance.has_reuse(payload)
            || payload["reused_from_run_id"] != first["reused_from_run_id"] {
            return Err("unsupported_child_workflow");
        }
    }
    let completed = &events[2].payload;
    if !completed["child_status"].is_null() && completed["child_status"] != "completed" {
        return Err("unsupported_child_outcome");
    }
    let Some(output) = completed.get("output") else {
        return Err("unavailable_child_result");
    };
    if holds_external(output) {
        return Err("external_child_result_not_supported");
    }
    let codec = nonempty(&completed["payload_codec"]).or(run.payload_codec.filter(|codec| !codec.is_empty())).unwrap_or("avro");
    if !inline_blob(output).is_some_and(|blob| decodes(codec, blob)) {
        return Err("unavailable_child_result");
    }
    Ok(())
}

/// `FailedRunRedrivePlan::forRun` over the source run's history.
pub(super) fn plan(run: &SourceRun, events: &[Event], decodes: Decodes) -> Result<Plan, &'static str> {
    if run.status != "failed" {
        return Err("run_not_failed");
    }
    let mut started: Option<&Event> = None;
    let mut failure: Option<&Event> = None;
    let mut activities: BTreeMap<i64, Vec<&Event>> = BTreeMap::new();
    let mut markers: BTreeMap<i64, &Event> = BTreeMap::new();
    let mut timers: BTreeMap<i64, Vec<&Event>> = BTreeMap::new();
    let mut signals: Vec<&Event> = Vec::new();
    let mut updates: Vec<&Event> = Vec::new();
    let mut children: BTreeMap<i64, Vec<&Event>> = BTreeMap::new();
    let mut last_activity: Option<&Event> = None;
    for event in events {
        if failure.is_some() {
            return Err("events_after_failure");
        }
        let kind = event.kind.as_str();
        let payload = &event.payload;
        match kind {
            "WorkflowStarted" if started.is_some() => return Err("duplicate_start"),
            "WorkflowStarted" => started = Some(event),
            "WorkflowFailed" if started.is_none() => return Err("missing_start"),
            "WorkflowFailed" => failure = Some(event),
            "StartAccepted" => {
                if started.is_some() && !active_start_adoption(run, event) {
                    return Err("unexpected_start");
                }
            }
            kind if MARKERS.contains(&kind) => {
                let Some(sequence) = step_sequence(payload).filter(|_| started.is_some()) else {
                    return Err("invalid_step_sequence");
                };
                if markers.contains_key(&sequence) {
                    return Err("duplicate_step_sequence");
                }
                if kind == "SideEffectRecorded" && external(&payload["result"]) {
                    return Err("external_side_effect_result_not_supported");
                }
                markers.insert(sequence, event);
            }
            kind if UPDATES.contains(&kind) => {
                if started.is_none() {
                    return Err("invalid_step_sequence");
                }
                updates.push(event);
            }
            // Message stream bookkeeping, not a step.
            "MessageCursorAdvanced" => {}
            kind if SIGNALS.contains(&kind) => {
                if started.is_none() {
                    return Err("invalid_step_sequence");
                }
                signals.push(event);
            }
            kind if CHILDREN.contains(&kind) => {
                let Some(sequence) = step_sequence(payload).filter(|_| started.is_some()) else {
                    return Err("invalid_step_sequence");
                };
                children.entry(sequence).or_default().push(event);
            }
            "TimerScheduled" | "TimerFired" => {
                let Some(sequence) = step_sequence(payload).filter(|_| started.is_some()) else {
                    return Err("invalid_step_sequence");
                };
                if !plain_timer(payload) {
                    return Err("unsupported_timer");
                }
                timers.entry(sequence).or_default().push(event);
            }
            kind if ACTIVITIES.contains(&kind) => {
                if payload["execution_mode"] == "local" || payload["local_activity"] == true {
                    return Err("unsupported_local_activity");
                }
                let Some(sequence) = step_sequence(payload).filter(|_| started.is_some()) else {
                    return Err("invalid_activity_sequence");
                };
                activities.entry(sequence).or_default().push(event);
                last_activity = Some(event);
            }
            _ => return Err("unsupported_history"),
        }
    }
    let (Some(started), Some(failure)) = (started, failure) else {
        return Err("missing_terminal_history");
    };
    let boundary = failure.payload["failed_step_sequence"].as_i64().filter(|boundary| *boundary >= 1);
    let failed_step_kind = failure.payload["failed_step_kind"].as_str()
        .filter(|kind| matches!(*kind, "activity" | "workflow_code" | "structural_limit"));
    let (Some(boundary), Some(failed_step_kind)) = (boundary, failed_step_kind) else {
        return Err("unrecorded_failure_boundary");
    };
    // An escaped activity failure owns its step, which runs again. When
    // workflow code failed, the boundary is the step it never took.
    let failed_activity = failed_step_kind == "activity";
    let expected = if failed_activity { boundary } else { boundary - 1 };
    if !failed_activity && activities.keys().any(|sequence| *sequence >= boundary) {
        return Err("invalid_failed_step");
    }
    let immediate_source = (started.payload["recovery_kind"] == "redrive")
        .then(|| nonempty(&started.payload["continued_from_run_id"])).flatten();
    let provenance = Provenance { immediate_source, earlier_runs: run.earlier_runs };
    let signal_events = signal_steps(&signals, run, &provenance)?;
    let signal_steps: BTreeSet<i64> = signal_events.keys().copied().collect();
    let applied_updates = update_bundles(&updates, run, boundary, &provenance, decodes)?;
    for events in children.values() {
        completed_child_step(events, run, &provenance, decodes)?;
    }
    if markers.keys().chain(timers.keys()).chain(signal_steps.iter()).chain(children.keys()).any(|sequence| *sequence >= boundary) {
        return Err("invalid_failed_step");
    }
    let taken = |sequence: &i64, by: &[&dyn Fn(&i64) -> bool]| by.iter().any(|check| check(sequence));
    let activity = |sequence: &i64| activities.contains_key(sequence);
    let marker = |sequence: &i64| markers.contains_key(sequence);
    let timer = |sequence: &i64| timers.contains_key(sequence);
    let signal = |sequence: &i64| signal_steps.contains(sequence);
    if timers.keys().any(|sequence| taken(sequence, &[&activity, &marker]))
        || markers.keys().any(|sequence| taken(sequence, &[&activity]))
        || signal_steps.iter().any(|sequence| taken(sequence, &[&activity, &marker, &timer]))
        || children.keys().any(|sequence| taken(sequence, &[&activity, &marker, &timer, &signal])) {
        return Err("duplicate_step_sequence");
    }
    let steps = activities.len() + markers.len() + timers.len() + signal_steps.len() + children.len();
    if steps as i64 != expected {
        return Err("activity_sequence_gap");
    }
    let mut reused = Vec::new();
    let mut service_unqualified = applied_updates || !signal_steps.is_empty() || !children.is_empty();
    for sequence in 1..=expected {
        if let Some(event) = markers.get(&sequence) {
            // The task and command are the source run's recording context,
            // not part of the reused step.
            let mut payload = event.payload.clone();
            if let Some(fields) = payload.as_object_mut() {
                fields.remove("task");
                fields.remove("command");
            }
            service_unqualified |= matches!(event.kind.as_str(), "MemoUpserted" | "SearchAttributesUpserted");
            reused.push(Reused { kind: event.kind.clone(), payload, recorded_at: event.recorded_at, scheduled_payload: None });
            continue;
        }
        // A completed signal wait or child call is a reused step in Core's
        // plan. Its events are not copied here: the decision refuses it.
        let reported = if signal_steps.contains(&sequence) {
            signal_events.get(&sequence).map(|event| ("SignalApplied", *event))
        } else {
            children.get(&sequence).and_then(|events| events.last()).map(|event| ("ChildRunCompleted", *event))
        };
        if let Some((kind, event)) = reported {
            reused.push(Reused { kind: kind.to_owned(), payload: event.payload.clone(), recorded_at: event.recorded_at, scheduled_payload: None });
            continue;
        }
        if let Some(events) = timers.get(&sequence) {
            let [scheduled, fired] = events.as_slice() else {
                return Err("incomplete_timer_step");
            };
            if scheduled.kind != "TimerScheduled" || fired.kind != "TimerFired"
                || !scheduled.payload["timer_id"].is_string() || scheduled.payload["timer_id"] != fired.payload["timer_id"] {
                return Err("incomplete_timer_step");
            }
            let (mut scheduled_payload, mut fired_payload) = (scheduled.payload.clone(), fired.payload.clone());
            for payload in [&mut scheduled_payload, &mut fired_payload] {
                if let Some(fields) = payload.as_object_mut() {
                    for key in ["task", "command", "timer_id"] {
                        fields.remove(key);
                    }
                }
            }
            reused.push(Reused { kind: "TimerFired".to_owned(), payload: fired_payload, recorded_at: fired.recorded_at,
                scheduled_payload: Some(scheduled_payload) });
            continue;
        }
        let Some(events) = activities.get(&sequence) else {
            return Err("activity_sequence_gap");
        };
        if !valid_retry_history(events) {
            return Err("invalid_activity_retry_history");
        }
        let mut activity_type: Option<&str> = None;
        let mut completion: Option<&Event> = None;
        let mut failures = 0;
        for event in events {
            let Some(recorded) = nonempty(&event.payload["activity_type"]) else {
                return Err("missing_activity_type");
            };
            if activity_type.is_some_and(|known| known != recorded) {
                return Err("activity_type_mismatch");
            }
            activity_type = Some(recorded);
            if event.kind == "ActivityCompleted" {
                if completion.is_some() {
                    return Err("duplicate_activity_completion");
                }
                completion = Some(event);
            }
            if event.kind == "ActivityFailed" {
                failures += 1;
            }
        }
        if sequence == boundary {
            if completion.is_some() || failures != 1 {
                return Err("invalid_failed_step");
            }
            continue;
        }
        let Some(completion) = completion.filter(|_| failures == 0) else {
            return Err("incomplete_activity_prefix");
        };
        let payload = &completion.payload;
        let (Some(result), Some(codec)) = (payload.get("result"), payload["payload_codec"].as_str()) else {
            return Err("missing_activity_result");
        };
        if external(result) {
            return Err("external_activity_result_not_supported");
        }
        let Some(blob) = inline_blob(result) else {
            return Err("missing_activity_result");
        };
        if !decodes(codec, blob) {
            return Err("unavailable_activity_result");
        }
        reused.push(Reused { kind: "ActivityCompleted".to_owned(), payload: payload.clone(), recorded_at: completion.recorded_at,
            scheduled_payload: None });
    }
    if failed_activity && last_activity.is_none_or(|event| event.kind != "ActivityFailed" || event.payload["sequence"].as_i64() != Some(boundary)) {
        return Err("unrecorded_failure_boundary");
    }
    Ok(Plan { resume: boundary, failed_step_kind: failed_step_kind.to_owned(), reused, service_unqualified })
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn event(kind: &str, payload: Value) -> Event {
        Event { sequence: 0, kind: kind.into(), payload, recorded_at: Utc::now(), task_id: None, command_id: None }
    }

    /// The plan for a test history, numbered in order, with every payload
    /// decodable.
    fn plan_of(status: &str, history: &[Event]) -> std::result::Result<(i64, Vec<Reused>), &'static str> {
        let earlier = BTreeSet::new();
        let run = SourceRun { id: "source-run", instance_id: "source-instance", workflow_class: "wf", workflow_type: "wf",
            status, payload_codec: Some("avro"), earlier_runs: &earlier };
        let numbered: Vec<Event> = history.iter().enumerate().map(|(index, event)| Event { sequence: index as i64 + 1, ..event.clone() }).collect();
        plan(&run, &numbered, &|_, _| true).map(|plan| (plan.resume, plan.reused))
    }

    #[test]
    fn plan_follows_php_s_failed_run_rules() {
        let done = json!({"sequence":1,"activity_type":"a","result":{"codec":"avro","blob":"x"},"payload_codec":"avro"});
        let failed = json!({"sequence":2,"activity_type":"b"});
        let history = vec![event("StartAccepted", json!({})), event("WorkflowStarted", json!({})),
            event("ActivityScheduled", json!({"sequence":1,"activity_type":"a"})), event("ActivityCompleted", done.clone()),
            event("ActivityScheduled", json!({"sequence":2,"activity_type":"b"})), event("ActivityFailed", failed.clone()),
            event("WorkflowFailed", json!({"failed_step_sequence":2,"failed_step_kind":"activity"}))];
        let (resume, reused) = plan_of("failed", &history).unwrap();
        assert_eq!((resume, reused.len()), (2, 1));
        assert_eq!(plan_of("completed", &history).err(), Some("run_not_failed"));
        let mut unbounded = history.clone();
        unbounded[6] = event("WorkflowFailed", json!({}));
        assert_eq!(plan_of("failed", &unbounded).err(), Some("unrecorded_failure_boundary"));
        let mut timer = history.clone();
        timer.insert(2, event("TimerScheduled", json!({"sequence":1})));
        assert_eq!(plan_of("failed", &timer).err(), Some("duplicate_step_sequence"));
        let mut external = history.clone();
        external[3] = event("ActivityCompleted", json!({"sequence":1,"activity_type":"a","result":{"codec":"avro","external_payload":{}},"payload_codec":"avro"}));
        assert_eq!(plan_of("failed", &external).err(), Some("external_activity_result_not_supported"));
        let mut gap = history.clone();
        gap[6] = event("WorkflowFailed", json!({"failed_step_sequence":3,"failed_step_kind":"activity"}));
        assert_eq!(plan_of("failed", &gap).err(), Some("activity_sequence_gap"));
    }

    // Core workflow#991: side effects, version markers and upserts before the
    // failed step are reused in step order, without their task and command.
    #[test]
    fn plan_reuses_marker_steps_like_core() {
        let done = json!({"sequence":3,"activity_type":"a","result":{"codec":"avro","blob":"x"},"payload_codec":"avro"});
        let history = vec![event("StartAccepted", json!({})), event("WorkflowStarted", json!({})),
            event("SideEffectRecorded", json!({"sequence":1,"result":{"codec":"avro","blob":"s"},"task":{"id":"t"}})),
            event("VersionMarkerRecorded", json!({"sequence":2,"change_id":"c","version":1,"command":{"id":"c"}})),
            event("ActivityScheduled", json!({"sequence":3,"activity_type":"a"})), event("ActivityCompleted", done),
            event("ActivityScheduled", json!({"sequence":4,"activity_type":"b"})), event("ActivityFailed", json!({"sequence":4,"activity_type":"b"})),
            event("WorkflowFailed", json!({"failed_step_sequence":4,"failed_step_kind":"activity"}))];
        let (resume, reused) = plan_of("failed", &history).unwrap();
        assert_eq!(resume, 4);
        let steps: Vec<(&str, Value)> = reused.iter().map(|step| (step.kind.as_str(), step.payload["sequence"].clone())).collect();
        assert_eq!(steps, [("SideEffectRecorded", json!(1)), ("VersionMarkerRecorded", json!(2)), ("ActivityCompleted", json!(3))]);
        assert!(reused[0].payload.get("task").is_none() && reused[1].payload.get("command").is_none());
        let refused = |at: usize, kind: &str, payload: Value| {
            let mut changed = history.clone();
            changed[at] = event(kind, payload);
            plan_of("failed", &changed).err()
        };
        assert_eq!(refused(2, "SideEffectRecorded", json!({"sequence":0})), Some("invalid_step_sequence"));
        assert_eq!(refused(3, "VersionMarkerRecorded", json!({"sequence":1})), Some("duplicate_step_sequence"));
        assert_eq!(refused(2, "SideEffectRecorded", json!({"sequence":1,"result":{"codec":"avro","external_payload":{}}})),
            Some("external_side_effect_result_not_supported"));
        assert_eq!(refused(2, "MemoUpserted", json!({"sequence":4})), Some("invalid_failed_step"));
        assert_eq!(refused(2, "SearchAttributesUpserted", json!({"sequence":3})), Some("duplicate_step_sequence"));
        assert_eq!(refused(2, "SideEffectRecorded", json!({"sequence":5})), Some("invalid_failed_step"));
        let mut missing = history.clone();
        missing.remove(3);
        assert_eq!(plan_of("failed", &missing).err(), Some("activity_sequence_gap"));
    }

    fn fired_timer_history() -> Vec<Event> {
        vec![event("WorkflowStarted", json!({})),
            event("ActivityCompleted", json!({"sequence":1,"activity_type":"a","result":{"blob":"x"},"payload_codec":"avro"})),
            event("TimerScheduled", json!({"sequence":2,"timer_id":"source-timer","delay_seconds":5,
                "task":{"id":"scheduled-task"},"command":{"id":"scheduled-command"}})),
            event("TimerFired", json!({"sequence":2,"timer_id":"source-timer","delay_seconds":5,
                "task":{"id":"fired-task"},"command":{"id":"fired-command"}})),
            event("ActivityFailed", json!({"sequence":3,"activity_type":"b"})),
            event("WorkflowFailed", json!({"failed_step_sequence":3,"failed_step_kind":"activity"}))]
    }

    #[test]
    fn plan_reuses_a_complete_plain_timer_in_step_order() {
        let history = fired_timer_history();
        let original = history.clone();
        let (resume, reused) = plan_of("failed", &history).unwrap();
        assert_eq!((resume, reused.len()), (3, 2));
        assert_eq!(reused[0].kind, "ActivityCompleted");
        assert_eq!(reused[1].kind, "TimerFired");
        assert_eq!(reused[1].payload, json!({"sequence":2,"delay_seconds":5}));
        assert_eq!(reused[1].scheduled_payload, Some(json!({"sequence":2,"delay_seconds":5})));
        assert_eq!(reused[1].recorded_at, history[3].recorded_at);
        assert_eq!(history, original);
    }

    #[test]
    fn plan_refuses_non_plain_or_incomplete_timers_like_core() {
        let history = fired_timer_history();
        for index in [2, 3] {
            for field in ["timer_kind", "cancellation_cleanup", "cancellation_scope_id", "parallel_group_id",
                "condition_wait_id", "signal_wait_id"] {
                for value in [json!(false), json!(""), json!("condition")] {
                    let mut changed = history.clone();
                    changed[index].payload[field] = value;
                    assert_eq!(plan_of("failed", &changed).err(), Some("unsupported_timer"), "{field}");
                }
                let mut changed = history.clone();
                changed[index].payload[field] = Value::Null;
                assert!(plan_of("failed", &changed).is_ok(), "null {field}");
            }
            let mut missing = history.clone();
            missing.remove(index);
            assert_eq!(plan_of("failed", &missing).err(), Some("incomplete_timer_step"));
            let mut duplicate = history.clone();
            duplicate.insert(index, history[index].clone());
            assert_eq!(plan_of("failed", &duplicate).err(), Some("incomplete_timer_step"));
            for value in [Value::Null, json!(5), json!("another-timer")] {
                let mut changed = history.clone();
                changed[index].payload["timer_id"] = value;
                assert_eq!(plan_of("failed", &changed).err(), Some("incomplete_timer_step"));
            }
            for value in [Value::Null, json!(0), json!("2")] {
                let mut changed = history.clone();
                changed[index].payload["sequence"] = value;
                assert_eq!(plan_of("failed", &changed).err(), Some("invalid_step_sequence"));
            }
        }
        let mut reversed = history.clone();
        reversed.swap(2, 3);
        assert_eq!(plan_of("failed", &reversed).err(), Some("incomplete_timer_step"));
        for (sequence, expected) in [(1, "duplicate_step_sequence"), (3, "invalid_failed_step")] {
            let mut changed = history.clone();
            changed[2].payload["sequence"] = json!(sequence);
            changed[3].payload["sequence"] = json!(sequence);
            assert_eq!(plan_of("failed", &changed).err(), Some(expected));
        }
        let mut collision = history.clone();
        collision.insert(2, event("VersionMarkerRecorded", json!({"sequence":2})));
        assert_eq!(plan_of("failed", &collision).err(), Some("duplicate_step_sequence"));
        let mut gap = history.clone();
        gap.remove(1);
        assert_eq!(plan_of("failed", &gap).err(), Some("activity_sequence_gap"));
    }

    /// A stored event as Core's corpus records it.
    fn corpus_event(value: &Value) -> Event {
        Event {
            sequence: value["sequence"].as_i64().unwrap(),
            kind: value["event_type"].as_str().unwrap().to_owned(),
            payload: value["payload"].clone(),
            recorded_at: DateTime::parse_from_rfc3339(value["recorded_at"].as_str().unwrap()).unwrap().with_timezone(&Utc),
            task_id: value["workflow_task_id"].as_str().map(str::to_owned),
            command_id: value["workflow_command_id"].as_str().map(str::to_owned),
        }
    }

    fn avro(codec: &str, blob: &str) -> bool {
        codec == "avro" && crate::codec::ValueCodec::new().unwrap().decode(blob).is_ok()
    }

    fn corpus() -> Value {
        serde_json::from_str(include_str!("../../tests/fixtures/redrive-core-2.33.0.json")).unwrap()
    }

    // Server #980 parity: every `redrive_plan` scenario in Workflow 2.33.0's
    // replay-regression corpus (`ReplayRegressionCorpusTest::
    // assertRetriedActivityRedrivePlan`) reaches Core's decision natively,
    // with the same reused steps and the same reason for each refusal.
    #[test]
    fn plan_matches_core_2_33_corpus_redrive_plans() {
        let corpus = corpus();
        let plans = corpus["plans"].as_array().unwrap();
        assert_eq!(plans.len(), 4);
        let earlier = BTreeSet::new();
        for fixture in plans {
            let id = fixture["id"].as_str().unwrap();
            let scenario = &fixture["redrive_plan"];
            let source = &scenario["source_run"];
            let field = |key: &str| source[key].as_str().unwrap_or("");
            let run = SourceRun { id: field("id"), instance_id: field("workflow_instance_id"), workflow_class: field("workflow_class"),
                workflow_type: field("workflow_type"), status: "failed", payload_codec: Some("avro"), earlier_runs: &earlier };
            let history = |events: &Value| -> Vec<Event> {
                events.as_array().unwrap().iter().map(|event| {
                    let mut event = corpus_event(event);
                    // A start receipt names the run it was recorded on.
                    if event.kind == "StartAccepted" && fixture["redrive_plan"]["source_history"].as_array().unwrap().iter()
                        .any(|stored| stored["sequence"] == event.sequence && stored["workflow_run_id"] != Value::from(run.id)) {
                        event.command_id = None;
                    }
                    event
                }).collect()
            };
            let accepted = plan(&run, &history(&scenario["source_history"]), &avro).unwrap_or_else(|reason| panic!("{id}: {reason}"));
            let expected = &scenario["expected"];
            assert_eq!(expected["eligible"], true, "{id}");
            assert_eq!(accepted.resume, expected["resume_step_sequence"].as_i64().unwrap(), "{id}");
            let kinds: Vec<&str> = accepted.reused.iter().map(|step| step.kind.as_str()).collect();
            let expected_kinds: Vec<&str> = expected["reused_event_types"].as_array().unwrap().iter().map(|kind| kind.as_str().unwrap()).collect();
            assert_eq!(kinds, expected_kinds, "{id}");
            let completed = accepted.reused.iter().filter(|step| step.kind == "ActivityCompleted").count();
            assert_eq!(completed as u64, expected["completed_count"].as_u64().unwrap(), "{id}");
            // A reused child call is valid in Core's plan, and not yet
            // replayable by a service worker.
            assert_eq!(accepted.service_unqualified, kinds.contains(&"ChildRunCompleted"), "{id}");
            for case in scenario["refusals"].as_array().unwrap() {
                let mut source_history = scenario["source_history"].clone();
                let index = case["event_index"].as_u64().unwrap() as usize;
                source_history[index]["payload"][case["field"].as_str().unwrap()] = case["value"].clone();
                let refused = plan(&run, &history(&source_history), &avro).err();
                assert_eq!(refused, case["reason"].as_str(), "{id}: {case}");
            }
        }
    }

    /// A Core successor history, failed again at its next step, so it is
    /// the source of a second redrive. Its own run is new; the run it reused
    /// from is earlier in the instance.
    fn failed_successor(fixture: &Value) -> (Vec<Event>, String) {
        let mut events: Vec<Event> = fixture["history"].as_array().unwrap().iter().map(corpus_event).collect();
        let source = events.iter().find_map(|event| event.payload["reused_from_run_id"].as_str().map(str::to_owned)).unwrap();
        events[0].payload["recovery_kind"] = json!("redrive");
        events[0].payload["continued_from_run_id"] = json!(source);
        let last = events.iter().filter_map(|event| event.payload["sequence"].as_i64()).max().unwrap();
        let mut next = events.last().unwrap().sequence;
        let at = events.last().unwrap().recorded_at;
        let mut push = |kind: &str, payload: Value| {
            next += 1;
            events.push(Event { sequence: next, kind: kind.to_owned(), payload, recorded_at: at, task_id: None, command_id: None });
        };
        push("ActivityScheduled", json!({"sequence":last + 1,"activity_type":"retry-me"}));
        push("ActivityFailed", json!({"sequence":last + 1,"activity_type":"retry-me"}));
        push("WorkflowFailed", json!({"failed_step_sequence":last + 1,"failed_step_kind":"activity"}));
        (events, source)
    }

    // Server #980 parity: the steps Core 2.33.0 reused into a successor are
    // reusable again from it, with Core's provenance rules. Signal waits and
    // child calls are recognised as completed steps and reported as not yet
    // replayable by a service worker; markers, activities and timers are reused.
    #[test]
    fn core_2_33_successor_steps_are_reused_or_reported_like_core() {
        let corpus = corpus();
        for fixture in corpus["successor_histories"].as_array().unwrap() {
            let id = fixture["id"].as_str().unwrap();
            let (events, source) = failed_successor(fixture);
            let instance = events.iter().find_map(|event| event.payload["workflow_instance_id"].as_str()).unwrap_or("instance").to_owned();
            let earlier = BTreeSet::from([source.clone()]);
            let run = SourceRun { id: "successor-run", instance_id: &instance, workflow_class: "wf", workflow_type: "wf",
                status: "failed", payload_codec: Some("avro"), earlier_runs: &earlier };
            let accepted = plan(&run, &events, &avro).unwrap_or_else(|reason| panic!("{id}: {reason}"));
            let kinds: Vec<&str> = accepted.reused.iter().map(|step| step.kind.as_str()).collect();
            match id {
                "redrive-reused-signal-wait-clock" => {
                    assert!(accepted.service_unqualified, "{id}");
                    assert_eq!(kinds, ["SignalApplied"], "{id}");
                }
                "redrive-reused-child-workflow" => {
                    assert!(accepted.service_unqualified, "{id}");
                    assert_eq!(kinds, ["ChildRunCompleted"], "{id}");
                }
                "redrive-reused-markers-clock" => assert_eq!(kinds, ["SideEffectRecorded", "VersionMarkerRecorded"], "{id}"),
                "redrive-reused-timer-clock" => assert_eq!(kinds, ["ActivityCompleted", "TimerFired"], "{id}"),
                _ => panic!("unexpected fixture {id}"),
            }
            // A step reused again keeps the time it first ran.
            for step in &accepted.reused {
                assert!(step.payload["reused_recorded_at"].is_string(), "{id}: {}", step.kind);
            }
            // Provenance: a reused message must name the immediate source.
            if id == "redrive-reused-signal-wait-clock" {
                let mut changed = events.clone();
                changed[2].payload["reused_from_run_id"] = json!("another-run");
                assert_eq!(plan(&run, &changed, &avro).err(), Some("mismatched_signal_receipt"));
                let mut unapplied = events.clone();
                unapplied.remove(3);
                assert_eq!(plan(&run, &unapplied, &avro).err(), Some("incomplete_signal_step"));
                let mut pending = events.clone();
                pending.remove(3);
                pending.remove(1);
                assert_eq!(plan(&run, &pending, &avro).err(), Some("unsupported_pending_signal"));
            }
            if id == "redrive-reused-child-workflow" {
                let mut failed = events.clone();
                failed[3].kind = "ChildRunFailed".into();
                assert_eq!(plan(&run, &failed, &avro).err(), Some("unsupported_child_outcome"));
                let mut open = events.clone();
                open.remove(3);
                assert_eq!(plan(&run, &open, &avro).err(), Some("unsupported_open_child"));
                let mut undecodable = events.clone();
                undecodable[3].payload["output"] = json!("not avro");
                assert_eq!(plan(&run, &undecodable, &avro).err(), Some("unavailable_child_result"));
            }
        }
    }

    fn retried_history() -> Vec<Event> {
        let attempt = |kind: &str, attempt: &str, number: i64, task: &str| {
            let mut event = event(kind, json!({"sequence":1,"activity_type":"a","activity_execution_id":"e1","activity_attempt_id":attempt,
                "attempt_number":number}));
            event.task_id = Some(task.into());
            event
        };
        let mut retry = attempt("ActivityRetryScheduled", "a1", 1, "t1");
        for (key, value) in [("retry_after_attempt_id", json!("a1")), ("retry_after_attempt", json!(1)), ("retry_of_task_id", json!("t1")),
            ("retry_task_id", json!("t2")), ("max_attempts", json!(3))] {
            retry.payload[key] = value;
        }
        let mut completed = attempt("ActivityCompleted", "a2", 2, "t2");
        completed.payload["result"] = json!({"codec":"avro","blob":"x"});
        completed.payload["payload_codec"] = json!("avro");
        vec![event("WorkflowStarted", json!({})),
            event("ActivityScheduled", json!({"sequence":1,"activity_type":"a","activity_execution_id":"e1"})),
            attempt("ActivityStarted", "a1", 1, "t1"), retry, attempt("ActivityStarted", "a2", 2, "t2"), completed,
            event("ActivityScheduled", json!({"sequence":2,"activity_type":"b"})), event("ActivityFailed", json!({"sequence":2,"activity_type":"b"})),
            event("WorkflowFailed", json!({"failed_step_sequence":2,"failed_step_kind":"activity"}))]
    }

    // Core workflow#1131: a retried activity is reused (or failed) when its
    // attempts form one valid lineage.
    #[test]
    fn plan_reuses_a_retried_activity_with_a_valid_lineage() {
        let history = retried_history();
        let (resume, reused) = plan_of("failed", &history).unwrap();
        assert_eq!((resume, reused.len(), reused[0].kind.as_str()), (2, 1, "ActivityCompleted"));
        for (index, key, value) in [(3, "retry_task_id", json!("t1")), (3, "retry_after_attempt", json!(2)), (3, "max_attempts", json!(1)),
            (4, "attempt_number", json!(3)), (4, "activity_execution_id", json!("e2")), (5, "activity_attempt_id", json!("a1"))] {
            let mut changed = history.clone();
            changed[index].payload[key] = value;
            assert_eq!(plan_of("failed", &changed).err(), Some("invalid_activity_retry_history"), "{key}");
        }
        let mut other_task = history.clone();
        other_task[4].task_id = Some("t3".into());
        assert_eq!(plan_of("failed", &other_task).err(), Some("invalid_activity_retry_history"));
        let mut snapshot = history.clone();
        snapshot[5].payload["activity_attempt"] = json!({"id":"a2","task_id":"t1"});
        assert_eq!(plan_of("failed", &snapshot).err(), Some("invalid_activity_retry_history"));
    }

    fn update(kind: &str, sequence: Option<i64>, extra: Value) -> Event {
        let mut payload = json!({"update_id":"u1","workflow_command_id":"c1","update_name":"set","workflow_instance_id":"source-instance",
            "workflow_run_id":"source-run","arguments":"args"});
        if let Some(sequence) = sequence {
            payload["sequence"] = json!(sequence);
        }
        for (key, value) in extra.as_object().unwrap() {
            payload[key] = value.clone();
        }
        event(kind, payload)
    }

    // Core workflow#864 updates: a rejection or an acceptance closed with the
    // run is not a step; an applied update is, and is not yet replayable by a
    // service worker; anything else is refused with Core's reason.
    #[test]
    fn plan_classifies_update_lifecycles_like_core() {
        let base = vec![event("WorkflowStarted", json!({})),
            event("ActivityScheduled", json!({"sequence":1,"activity_type":"b"})), event("ActivityFailed", json!({"sequence":1,"activity_type":"b"})),
            event("WorkflowFailed", json!({"failed_step_sequence":1,"failed_step_kind":"activity"}))];
        let with = |updates: Vec<Event>| {
            let mut history = base.clone();
            for (offset, update) in updates.into_iter().enumerate() {
                history.insert(1 + offset, update);
            }
            let earlier = BTreeSet::new();
            let run = SourceRun { id: "source-run", instance_id: "source-instance", workflow_class: "wf", workflow_type: "wf",
                status: "failed", payload_codec: Some("avro"), earlier_runs: &earlier };
            let numbered: Vec<Event> = history.iter().enumerate().map(|(index, event)| Event { sequence: index as i64 + 1, ..event.clone() }).collect();
            plan(&run, &numbered, &|_, blob| blob != "bad").map(|plan| plan.service_unqualified)
        };
        assert_eq!(with(vec![update("UpdateRejected", None, json!({}))]), Ok(false));
        assert_eq!(with(vec![update("UpdateAccepted", None, json!({})),
            update("UpdateCompleted", None, json!({"terminal_reason":"run_failed","failure_id":"f1"}))]), Ok(false));
        let applied = || vec![update("UpdateAccepted", None, json!({})), update("UpdateApplied", Some(1), json!({})),
            update("UpdateCompleted", Some(1), json!({"result":"done"}))];
        assert_eq!(with(applied()), Ok(true));
        assert_eq!(with(vec![update("UpdateAccepted", None, json!({}))]), Err("unsupported_pending_update"));
        let mut changed = applied();
        changed[1].payload["arguments"] = json!("other");
        assert_eq!(with(changed), Err("mismatched_update_history"));
        let mut changed = applied();
        changed[2].payload["failure_id"] = json!("f1");
        assert_eq!(with(changed), Err("incomplete_update_step"));
        let mut changed = applied();
        changed[2].payload["sequence"] = json!(2);
        assert_eq!(with(changed), Err("invalid_step_sequence"));
        let mut changed = applied();
        changed[2].payload["result"] = json!({"codec":"avro","external_storage":null});
        assert_eq!(with(changed), Err("external_update_payload_not_supported"));
        let mut changed = applied();
        changed[2].payload["result"] = json!("bad");
        assert_eq!(with(changed), Err("unavailable_update_payload"));
        let mut changed = applied();
        changed[0].payload["workflow_run_id"] = json!("another-run");
        assert_eq!(with(changed), Err("mismatched_update_history"));
    }

    // Core: a failure in workflow code or at a structural limit has its
    // boundary at the step it never took; message cursor bookkeeping is not
    // a step; a start adopted under `use-existing` is not a second start.
    #[test]
    fn plan_reads_failure_kinds_cursor_events_and_start_adoption_like_core() {
        let done = json!({"sequence":1,"activity_type":"a","result":{"codec":"avro","blob":"x"},"payload_codec":"avro"});
        let history = vec![event("WorkflowStarted", json!({})), event("ActivityScheduled", json!({"sequence":1,"activity_type":"a"})),
            event("ActivityCompleted", done), event("MessageCursorAdvanced", json!({"new_position":1})),
            event("WorkflowFailed", json!({"failed_step_sequence":2,"failed_step_kind":"workflow_code"}))];
        let earlier = BTreeSet::new();
        let run = SourceRun { id: "source-run", instance_id: "source-instance", workflow_class: "wf", workflow_type: "wf",
            status: "failed", payload_codec: Some("avro"), earlier_runs: &earlier };
        let accepted = plan(&run, &history, &|_, _| true).unwrap();
        assert_eq!((accepted.resume, accepted.failed_step_kind.as_str(), accepted.reused.len()), (2, "workflow_code", 1));
        let mut limit = history.clone();
        limit[4].payload["failed_step_kind"] = json!("structural_limit");
        assert_eq!(plan(&run, &limit, &|_, _| true).map(|plan| plan.failed_step_kind).as_deref(), Ok("structural_limit"));
        let mut past = history.clone();
        past[4].payload["failed_step_sequence"] = json!(1);
        assert_eq!(plan(&run, &past, &|_, _| true).err(), Some("invalid_failed_step"));
        let mut unknown = history.clone();
        unknown[4].payload["failed_step_kind"] = json!("signal");
        assert_eq!(plan(&run, &unknown, &|_, _| true).err(), Some("unrecorded_failure_boundary"));
        let mut undecodable = history.clone();
        undecodable[4].payload["failed_step_kind"] = json!("structural_limit");
        assert_eq!(plan(&run, &undecodable, &|_, _| false).err(), Some("unavailable_activity_result"));
        let mut adopted = history.clone();
        let mut receipt = event("StartAccepted", json!({"workflow_instance_id":"source-instance","workflow_run_id":"source-run",
            "workflow_class":"wf","workflow_type":"wf","workflow_command_id":"c1","outcome":"returned_existing_active",
            "rejection_reason":null,"command":{"id":"c1","type":"start","target_scope":"instance","resolved_run_id":"source-run",
            "status":"accepted","outcome":"returned_existing_active","rejection_reason":null}}));
        receipt.command_id = Some("c1".into());
        adopted.insert(1, receipt.clone());
        assert!(plan(&run, &adopted, &|_, _| true).is_ok());
        receipt.command_id = Some("c2".into());
        adopted[1] = receipt;
        assert_eq!(plan(&run, &adopted, &|_, _| true).err(), Some("unexpected_start"));
    }
}
