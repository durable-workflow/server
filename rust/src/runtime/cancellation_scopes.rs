//! Workflow-task cancellation scopes, as published Server 2.11 serves them
//! (`CancellationScopeController` over Workflow 2.10's portable scope opening
//! and delivery, `CancellationScopeRequests` and `CancellationScopeDelivery`).
//!
//! A scope is a node of a per-run tree rooted at the implicit `root` scope,
//! kept only in history: `CancellationScopeOpened` records it at a durable
//! command sequence, completion commands join it by `cancellation_scope_id`,
//! a run cancellation request records `CancellationScopeRequested` for every
//! unshielded descendant of root, and a worker holding a claim prepares a
//! scope's delivery (freezing its subtree inventory) and delivers it (fencing
//! every member, then recording the barrier). Scope delivery never closes the
//! run, releases the claim or creates a task.
//!
//! Native delivers activity and timer members. Scoped waits and children
//! remain refused at completion, and a scope that holds one (only possible in
//! history written by PHP before a takeover) is refused explicitly with
//! `cancellation_scope_delivery_unavailable`, as are parallel and selection
//! boundaries below the next sequence.
use super::store::{capabilities, id, now};
use super::transaction::Transaction;
use super::{RuntimeError, refuse};
use axum::http::StatusCode;
use chrono::{DateTime, SecondsFormat, Utc};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};
use std::collections::BTreeSet;

pub(super) const ROOT: &str = "root";
const SCOPE_SCHEMA: &str = "durable-workflow.cancellation-scope/v1";
const REQUEST_SCHEMA: &str = "durable-workflow.cancellation-scope-request/v1";
const PREPARATION_SCHEMA: &str = "durable-workflow.cancellation-scope-preparation/v5";
const DELIVERY_SCHEMA: &str = "durable-workflow.cancellation-scope-delivery/v1";
const SCOPED_CONTEXT_SCHEMA: &str = "durable-workflow.scoped-cancellation-context/v1";
const ACTIVITY_SCOPE_SCHEMA: &str = "durable-workflow.activity-scope-cancellation/v1";
const TIMER_SCOPE_SCHEMA: &str = "durable-workflow.scoped-timer-cancellation/v1";
/// PHP's default `command_batch_size` structural limit.
const SPAN_LIMIT: i64 = 1000;
/// The command types whose `cancellation_scope_id` records membership.
const MEMBER_COMMANDS: &[&str] = &["schedule_activity", "start_timer", "start_child_workflow", "open_signal_wait", "open_condition_wait"];
/// The command types the admission guard lets carry a scope.
const SCOPED_COMMANDS: &[&str] =
    &["schedule_activity", "start_timer", "start_child_workflow", "prepare_local_activity", "open_signal_wait", "open_condition_wait"];
/// `WorkflowStepHistory::WORKFLOW_STEP_EVENT_TYPES`.
const STEP_EVENTS: &[&str] = &[
    "CancellationScopeOpened", "ActivityScheduled", "ActivityStarted", "ActivityHeartbeatRecorded", "ActivityRetryScheduled",
    "ActivityCompleted", "ActivityFailed", "ActivityCancelled", "ActivityTimedOut", "ChildWorkflowScheduled", "ChildRunStarted",
    "ChildRunCompleted", "ChildRunFailed", "ChildRunCancelled", "ChildRunTerminated", "ServiceCallStarted", "ServiceCallCompleted",
    "ServiceCallFailed", "ServiceCallCancelled", "WorkflowContinuedAsNew", "ConditionWaitOpened", "ConditionWaitSatisfied",
    "ConditionWaitTimedOut", "SignalWaitOpened", "SignalApplied", "MemoUpserted", "SearchAttributesUpserted", "SideEffectRecorded",
    "VersionMarkerRecorded", "TimerScheduled", "TimerFired", "TimerCancelled",
];
/// `CooperativeCancellationDelivery::RESOLUTION_TYPES`.
const RESOLUTION_EVENTS: &[&str] = &[
    "ActivityCompleted", "ActivityFailed", "ActivityCancelled", "ActivityTimedOut", "TimerFired", "TimerCancelled",
    "ConditionWaitSatisfied", "ConditionWaitTimedOut", "SignalApplied", "ChildRunCompleted", "ChildRunFailed", "ChildRunCancelled",
    "ChildRunTerminated", "ServiceCallCompleted", "ServiceCallFailed", "ServiceCallCancelled", "SelectionResolved",
    "SelectionOperationCancelled",
];
const OPERATION_EVENTS: &[&str] =
    &["ActivityScheduled", "TimerScheduled", "ChildWorkflowScheduled", "SignalWaitOpened", "ConditionWaitOpened"];

type Result<T> = std::result::Result<T, RuntimeError>;

/// A scope engine refusal; the routes answer it with their refusal shape.
fn no(reason: &'static str) -> RuntimeError {
    refuse(StatusCode::CONFLICT, reason)
}

/// PHP's `toISOString`: UTC with microseconds.
pub(super) fn iso(at: DateTime<Utc>) -> String {
    at.to_rfc3339_opts(SecondsFormat::Micros, true)
}

fn instant(value: &Value) -> Option<DateTime<Utc>> {
    DateTime::parse_from_rfc3339(value.as_str()?).ok().map(|at| at.with_timezone(&Utc))
}

/// PHP's default `json_encode`, as its descriptor hashes read it: `/` and
/// every non-ASCII character escaped. Objects keep the order given.
fn php_json(value: &Value, out: &mut String) {
    use std::fmt::Write;
    match value {
        Value::String(text) => {
            out.push('"');
            for character in text.chars() {
                match character {
                    '"' => out.push_str("\\\""),
                    '\\' => out.push_str("\\\\"),
                    '/' => out.push_str("\\/"),
                    '\u{8}' => out.push_str("\\b"),
                    '\u{c}' => out.push_str("\\f"),
                    '\n' => out.push_str("\\n"),
                    '\r' => out.push_str("\\r"),
                    '\t' => out.push_str("\\t"),
                    character if character.is_ascii() && (character as u32) >= 0x20 => out.push(character),
                    character => {
                        for unit in character.encode_utf16(&mut [0; 2]) {
                            write!(out, "\\u{unit:04x}").expect("string write");
                        }
                    }
                }
            }
            out.push('"');
        }
        Value::Array(items) => {
            out.push('[');
            for (index, item) in items.iter().enumerate() {
                if index > 0 {
                    out.push(',');
                }
                php_json(item, out);
            }
            out.push(']');
        }
        other => out.push_str(&other.to_string()),
    }
}

/// `hash('sha256', json_encode($facts))` over a list of facts. Parallel
/// metadata objects arrive pre-encoded in PHP's key order.
fn descriptor_hash(facts: &[Value], encoded_tail: Option<&str>) -> String {
    let mut encoded = String::from("[");
    for (index, fact) in facts.iter().enumerate() {
        if index > 0 {
            encoded.push(',');
        }
        php_json(fact, &mut encoded);
    }
    if let Some(tail) = encoded_tail {
        encoded.push(',');
        encoded.push_str(tail);
    }
    encoded.push(']');
    Sha256::digest(encoded.as_bytes()).iter().map(|byte| format!("{byte:02x}")).collect()
}

/// `ParallelChildGroup::metadataPathFromPayload`, encoded as PHP's
/// `json_encode` writes it (each entry's keys in PHP's order).
fn parallel_path_json(payload: &Value) -> String {
    fn single(payload: &Value) -> Option<Vec<(&'static str, Value)>> {
        let group = payload["parallel_group_id"].as_str()?;
        let kind = payload["parallel_group_kind"].as_str().map(str::to_owned).or_else(|| {
            [("parallel-activities:", "activity"), ("parallel-calls:", "mixed"), ("parallel-timers:", "timer"),
                ("parallel-children:", "child"), ("select-calls:", "mixed")]
                .iter()
                .find(|(prefix, _)| group.starts_with(prefix))
                .map(|(_, kind)| (*kind).to_owned())
        })?;
        let mode = payload["parallel_group_mode"]
            .as_str()
            .filter(|mode| !mode.is_empty())
            .map(str::to_owned)
            .or_else(|| group.starts_with("select-calls:").then(|| "select".to_owned()));
        let base = payload["parallel_group_base_sequence"].as_i64()?;
        let size = payload["parallel_group_size"].as_i64().filter(|size| *size >= 1)?;
        let index = payload["parallel_group_index"].as_i64()?;
        let select = mode.as_deref() == Some("select");
        let key = &payload["selection_member_key"];
        if select && !(key.is_string() && key.as_str() != Some("") || key.is_i64()) {
            return None;
        }
        let mut fields = vec![("parallel_group_id", json!(group)), ("parallel_group_kind", json!(kind))];
        if select {
            fields.push(("parallel_group_mode", json!("select")));
        }
        fields.extend([("parallel_group_base_sequence", json!(base)), ("parallel_group_size", json!(size)),
            ("parallel_group_index", json!(index))]);
        if select {
            fields.push(("selection_member_key", key.clone()));
        }
        for field in ["selection_member_index", "selection_member_base_sequence", "selection_member_size"] {
            if let Some(value) = payload[field].as_i64() {
                fields.push((field, json!(value)));
            }
        }
        if let Some(kind) = payload["selection_member_kind"].as_str().filter(|kind| !kind.is_empty()) {
            fields.push(("selection_member_kind", json!(kind)));
        }
        Some(fields)
    }
    let mut path: Vec<Vec<(&'static str, Value)>> =
        payload["parallel_group_path"].as_array().into_iter().flatten().filter_map(single).collect();
    if path.is_empty() {
        path.extend(single(payload));
    }
    let mut encoded = String::from("[");
    for (index, entry) in path.iter().enumerate() {
        if index > 0 {
            encoded.push(',');
        }
        encoded.push('{');
        for (position, (key, value)) in entry.iter().enumerate() {
            if position > 0 {
                encoded.push(',');
            }
            php_json(&json!(key), &mut encoded);
            encoded.push(':');
            php_json(value, &mut encoded);
        }
        encoded.push('}');
    }
    encoded.push(']');
    encoded
}

/// One history event of a run, as the scope engine reads it.
#[derive(Clone, Debug)]
pub(super) struct Event {
    pub(super) id: String,
    pub(super) sequence: i64,
    pub(super) kind: String,
    pub(super) payload: Value,
    pub(super) command_id: Option<String>,
}

/// A recorded scope, from its `CancellationScopeOpened` event.
#[derive(Clone, Debug)]
pub(super) struct Scope {
    pub(super) id: String,
    pub(super) parent: String,
    pub(super) shield: bool,
    pub(super) sequence: i64,
    pub(super) event_id: String,
}

/// The run columns the engine reads.
#[derive(Clone, Debug)]
pub(super) struct RunFacts {
    pub(super) id: String,
    pub(super) instance: String,
    pub(super) status: String,
    pub(super) request_id: Option<String>,
    pub(super) execution_deadline: Option<DateTime<Utc>>,
    pub(super) run_deadline: Option<DateTime<Utc>>,
}

impl RunFacts {
    fn terminal(&self) -> bool {
        !matches!(self.status.as_str(), "pending" | "running" | "waiting")
    }
}

/// `CancellationScopeHistory::forRun`: the run's scopes in sequence order.
/// A parent is recorded before its child, so the tree has no cycles.
pub(super) fn scopes(events: &[Event], run_id: &str) -> Result<Vec<Scope>> {
    let mut found: Vec<Scope> = Vec::new();
    for event in events.iter().filter(|event| event.kind == "CancellationScopeOpened") {
        let payload = &event.payload;
        let (Some(id), Some(parent), Some(shield), Some(sequence)) = (
            payload["scope_id"].as_str(),
            payload["parent_scope_id"].as_str(),
            payload["shield_parent"].as_bool(),
            payload["sequence"].as_i64(),
        ) else {
            return Err(no("cancellation_scope_history_invalid"));
        };
        let parent_scope = found.iter().find(|scope| scope.id == parent);
        if payload["schema"] != SCOPE_SCHEMA
            || payload["workflow_run_id"] != run_id
            || id.is_empty()
            || id == ROOT
            || found.iter().any(|scope| scope.id == id || scope.sequence == sequence)
            || parent.is_empty()
            || sequence < 1
            || (parent != ROOT && parent_scope.is_none_or(|scope| scope.sequence >= sequence))
        {
            return Err(no("cancellation_scope_history_invalid"));
        }
        found.push(Scope { id: id.to_owned(), parent: parent.to_owned(), shield, sequence, event_id: event.id.clone() });
    }
    Ok(found)
}

fn find<'a>(scopes: &'a [Scope], id: &str) -> Option<&'a Scope> {
    scopes.iter().find(|scope| scope.id == id)
}

/// `CancellationScopeHistory::ancestry`: root first.
fn ancestry(scopes: &[Scope], scope_id: &str) -> Result<Vec<String>> {
    let mut chain = Vec::new();
    let mut current = scope_id.to_owned();
    while current != ROOT {
        let scope = find(scopes, &current).ok_or_else(|| no("cancellation_scope_not_recorded"))?;
        chain.push(current.clone());
        current = scope.parent.clone();
    }
    chain.push(ROOT.to_owned());
    chain.reverse();
    Ok(chain)
}

/// `CancellationScopeHistory::isRecordedBefore`: a scope owns only later
/// operations; omission means root.
pub(super) fn recorded_before(scopes: &[Scope], scope_id: &str, sequence: i64) -> bool {
    sequence >= 1 && (scope_id == ROOT || find(scopes, scope_id).is_some_and(|scope| scope.sequence < sequence))
}

fn assert_scope(scopes: &[Scope], scope_id: &str) -> Result<()> {
    if scope_id == ROOT {
        return Err(no("root_scope_requires_run_cancellation"));
    }
    find(scopes, scope_id).map(|_| ()).ok_or_else(|| no("cancellation_scope_not_recorded"))
}

/// The scope's accepted `CancellationScopeRequested` event.
fn accepted<'a>(events: &'a [Event], scope_id: &str) -> Result<Option<&'a Event>> {
    let mut matching = events.iter().filter(|event| event.kind == "CancellationScopeRequested" && event.payload["scope_id"] == scope_id);
    let first = matching.next();
    if matching.next().is_some() {
        return Err(no("cancellation_scope_request_history_invalid"));
    }
    Ok(first)
}

/// `CancellationScopeRequests::context`: the accepted scoped context.
fn context(events: &[Event], scopes: &[Scope], run_id: &str, scope_id: &str) -> Result<Option<Value>> {
    assert_scope(scopes, scope_id)?;
    let Some(event) = accepted(events, scope_id)? else {
        return Ok(None);
    };
    let payload = &event.payload;
    let context = &payload["cancellation"];
    if payload["schema"] != REQUEST_SCHEMA
        || payload["workflow_run_id"] != run_id
        || !context.is_object()
        || payload.get("parent_scope_id").is_none()
        || context_request(context) != payload["request_id"].as_str()
        || last_lineage(context)["scope_id"] != scope_id
    {
        return Err(no("cancellation_scope_request_history_invalid"));
    }
    Ok(Some(context.clone()))
}

fn last_lineage(context: &Value) -> &Value {
    context["lineage"].as_array().and_then(|lineage| lineage.last()).unwrap_or(&Value::Null)
}

fn context_request(context: &Value) -> Option<&str> {
    last_lineage(context)["request_id"].as_str()
}

fn context_deadline(context: &Value) -> Option<DateTime<Utc>> {
    instant(&last_lineage(context)["cleanup_deadline_at"])
}

/// The run request's `CancellationContext`, from its requested event.
fn run_context<'a>(events: &'a [Event], run: &RunFacts) -> Option<&'a Value> {
    let request = run.request_id.as_deref()?;
    events
        .iter()
        .find(|event| event.kind == "CooperativeCancellationRequested" && event.command_id.as_deref() == Some(request))
        .map(|event| &event.payload["cancellation"])
        .filter(|context| context.is_object())
}

/// `ScopedCancellationContext::fromRunContext` for a root request.
fn scoped_from_run(context: &Value) -> Value {
    let deadline = context["cleanup_deadline_at"].clone();
    let mut root = context.clone();
    root["request_id"] = context["root_request_id"].clone();
    root["parent_request_id"] = Value::Null;
    root["lineage"] = json!([context["lineage"][0].clone()]);
    let lineage: Vec<Value> = context["lineage"]
        .as_array()
        .into_iter()
        .flatten()
        .map(|entry| {
            let mut entry = entry.clone();
            entry["scope_id"] = json!(ROOT);
            entry["cleanup_deadline_at"] = deadline.clone();
            entry
        })
        .collect();
    json!({"schema": SCOPED_CONTEXT_SCHEMA, "root_context": root, "lineage": lineage})
}

/// `ScopedCancellationContext::forDescendant`.
fn for_descendant(parent: &Value, request_id: &str, run: &RunFacts, scope_id: &str) -> Value {
    let mut context = parent.clone();
    let deadline = last_lineage(parent)["cleanup_deadline_at"].clone();
    if let Some(lineage) = context["lineage"].as_array_mut() {
        lineage.push(json!({"request_id": request_id, "workflow_instance_id": run.instance, "workflow_run_id": run.id,
            "scope_id": scope_id, "cleanup_deadline_at": deadline}));
    }
    context
}

/// `CancellationScopeRequests::authority`: the earliest of the run's
/// deadlines, its request's budget and every requested ancestor's budget.
#[derive(Clone, Copy, Debug)]
pub(super) struct Authority {
    active: bool,
    deadline: Option<DateTime<Utc>>,
}

impl Authority {
    fn deadline_at(&self) -> Option<String> {
        self.deadline.map(iso)
    }
    fn usable(&self) -> Result<String> {
        match (self.active, self.deadline_at()) {
            (true, Some(deadline)) => Ok(deadline),
            _ => Err(no("cancellation_scope_authority_expired")),
        }
    }
}

fn authority(run: &RunFacts, events: &[Event], scopes: &[Scope], scope_id: &str) -> Result<Authority> {
    let chain = ancestry(scopes, scope_id)?;
    assert_scope(scopes, scope_id)?;
    let mut ceilings = vec![run.execution_deadline, run.run_deadline, run_context(events, run).and_then(|context| instant(&context["cleanup_deadline_at"]))];
    for ancestor in chain.iter().filter(|ancestor| *ancestor != ROOT) {
        ceilings.push(context(events, scopes, &run.id, ancestor)?.as_ref().and_then(context_deadline));
    }
    let deadline = ceilings.into_iter().flatten().min();
    Ok(Authority { active: !run.terminal() && deadline.is_none_or(|deadline| now() < deadline), deadline })
}

/// The unshielded transitive descendants of a scope, in sequence order.
fn descendants<'a>(scopes: &'a [Scope], scope_id: &str) -> Result<Vec<&'a Scope>> {
    if scope_id != ROOT && find(scopes, scope_id).is_none() {
        return Err(no("cancellation_scope_not_recorded"));
    }
    let mut included: BTreeSet<&str> = BTreeSet::from([scope_id]);
    let mut found = Vec::new();
    for scope in scopes {
        if scope.id == scope_id || scope.shield || !included.contains(scope.parent.as_str()) {
            continue;
        }
        included.insert(scope.id.as_str());
        found.push(scope);
    }
    Ok(found)
}

/// An operation event's scope: the flat key, else its descriptor's, else
/// root. `None` when the two disagree or are not strings.
fn membership(event: &Event) -> Option<String> {
    let nested = match event.kind.as_str() {
        "ActivityScheduled" => Some("activity"),
        "TimerScheduled" => Some("timer"),
        "ChildWorkflowScheduled" => Some("child_workflow"),
        _ => None,
    };
    let descriptor = nested.and_then(|key| event.payload.get(key)).cloned().unwrap_or_else(|| json!({}));
    let flat = event.payload.get("cancellation_scope_id");
    let inner = descriptor.get("cancellation_scope_id");
    let address = match (flat, inner) {
        (Some(flat), _) => flat.as_str()?.to_owned(),
        (None, Some(inner)) => inner.as_str()?.to_owned(),
        (None, None) => ROOT.to_owned(),
    };
    if inner.is_some_and(|inner| inner != address.as_str()) {
        return None;
    }
    Some(address)
}

/// `CancellationScopeDelivery::activityMembers`: each scheduled activity of
/// the scope itself, with its cancellation descriptor hash.
fn activity_members(events: &[Event], scope_id: &str) -> Result<Vec<Value>> {
    let mut members = Vec::new();
    let mut ids = BTreeSet::new();
    let mut sequences = BTreeSet::new();
    for event in events.iter().filter(|event| event.kind == "ActivityScheduled") {
        let payload = &event.payload;
        let activity = &payload["activity"];
        let nested = activity["cancellation_scope_id"].as_str().unwrap_or(ROOT);
        let flat = payload["cancellation_scope_id"].as_str().unwrap_or(nested);
        if flat != scope_id && nested != scope_id {
            continue;
        }
        if flat != scope_id || nested != scope_id {
            return Err(no("cancellation_scope_delivery_membership_mismatch"));
        }
        let (Some(id), Some(sequence)) = (payload["activity_execution_id"].as_str(), payload["sequence"].as_i64()) else {
            return Err(no("cancellation_scope_activity_history_invalid"));
        };
        if id.is_empty() || activity["id"] != id || sequence < 1 || !ids.insert(id.to_owned()) || !sequences.insert(sequence) {
            return Err(no("cancellation_scope_activity_history_invalid"));
        }
        let hash = descriptor_hash(
            &[json!(scope_id), json!(event.id), activity.get("cancellation_policy").cloned().unwrap_or(json!("try_cancel")),
                payload.get("local_activity").cloned().unwrap_or(json!(false)), payload.get("execution_mode").cloned().unwrap_or(Value::Null),
                activity.get("schedule_to_close_deadline_at").cloned().unwrap_or(Value::Null)],
            None,
        );
        members.push(json!({"sequence": sequence, "activity_execution_id": id, "descriptor_hash": hash}));
    }
    Ok(members)
}

/// `ScopedTimerCancellation::members`: each timer of the scope, fired or
/// cancelled ones included, without shielded cleanup timers.
fn timer_members(events: &[Event], scope_id: &str) -> Result<Vec<Value>> {
    let mut members = Vec::new();
    let mut ids = BTreeSet::new();
    let mut sequences: Vec<(i64, Option<String>)> = Vec::new();
    for event in events.iter().filter(|event| event.kind == "TimerScheduled") {
        let payload = &event.payload;
        let descriptor = payload.get("timer").cloned().unwrap_or_else(|| json!({}));
        if !descriptor.is_object() {
            return Err(no("cancellation_scope_timer_history_invalid"));
        }
        let nested = descriptor["cancellation_scope_id"].as_str().unwrap_or(ROOT);
        let address = payload["cancellation_scope_id"].as_str().unwrap_or(nested);
        if address != scope_id && nested != scope_id {
            continue;
        }
        if address != scope_id || (descriptor.get("cancellation_scope_id").is_some() && nested != scope_id) {
            return Err(no("cancellation_scope_delivery_membership_mismatch"));
        }
        let kind = payload["timer_kind"].as_str().map(str::to_owned);
        let (Some(id), Some(sequence), Some(delay), Some(fire_at)) = (
            payload["timer_id"].as_str(),
            payload["sequence"].as_i64(),
            payload["delay_seconds"].as_i64(),
            payload["fire_at"].as_str(),
        ) else {
            return Err(no("cancellation_scope_timer_history_invalid"));
        };
        let repeated = sequences.iter().find(|(seen, _)| *seen == sequence);
        if id.is_empty() || sequence < 1 || delay < 0 || fire_at.is_empty() || !ids.insert(id.to_owned())
            || repeated.is_some_and(|(_, seen)| !matches!(kind.as_deref(), Some("signal_timeout" | "condition_timeout")) || seen != &kind)
            || instant(&payload["fire_at"]).is_none_or(|at| iso(at) != fire_at)
        {
            return Err(no("cancellation_scope_timer_history_invalid"));
        }
        sequences.push((sequence, kind));
        if payload.get("cancellation_cleanup").is_some() {
            continue;
        }
        let field = |key: &str| payload.get(key).cloned().unwrap_or(Value::Null);
        let hash = descriptor_hash(
            &[json!(scope_id), json!(event.id), json!(sequence), json!(id), json!(delay), json!(fire_at), field("timer_kind"),
                field("condition_wait_id"), field("condition_wait_occurrence_id"), field("signal_wait_id")],
            Some(&parallel_path_json(payload)),
        );
        members.push(json!({"sequence": sequence, "timer_id": id, "descriptor_hash": hash}));
    }
    Ok(members)
}

/// Native delivers activity and timer members only.
fn refuse_other_members(events: &[Event], scope_id: &str) -> Result<()> {
    let other = events.iter().any(|event| {
        matches!(event.kind.as_str(), "SignalWaitOpened" | "ConditionWaitOpened" | "ChildWorkflowScheduled")
            && membership(event).as_deref() == Some(scope_id)
    });
    if other { Err(no("cancellation_scope_delivery_unavailable")) } else { Ok(()) }
}

/// `CancellationScopeDescendants::members`: each unshielded descendant's
/// request and own members, frozen with the preparation.
fn descendant_members(events: &[Event], scopes: &[Scope], run: &RunFacts, scope_id: &str, authority_deadline: DateTime<Utc>) -> Result<Vec<Value>> {
    let mut members = Vec::new();
    for scope in descendants(scopes, scope_id)? {
        let request = accepted(events, &scope.id)?;
        let context = context(events, scopes, &run.id, &scope.id)?;
        let parent = context_parent(events, scopes, run, &scope.parent)?;
        let (Some(request), Some(context), Some(parent)) = (request, context, parent) else {
            return Err(no("cancellation_scope_descendant_request_history_invalid"));
        };
        if context["root_context"]["root_request_id"] != parent["root_context"]["root_request_id"]
            || request.payload["parent_scope_id"] != scope.parent.as_str()
        {
            return Err(no("cancellation_scope_descendant_request_history_invalid"));
        }
        let mut deadline = authority_deadline;
        for ancestor in ancestry(scopes, &scope.id)? {
            if let Some(ceiling) = accepted(events, &ancestor)?.and_then(|event| context_deadline(&event.payload["cancellation"])) {
                deadline = deadline.min(ceiling);
            }
        }
        refuse_other_members(events, &scope.id)?;
        members.push(json!({"scope_id": scope.id, "parent_scope_id": scope.parent, "scope_history_event_id": scope.event_id,
            "request_history_event_id": request.id, "request_id": context_request(&context),
            "propagation_history_event_id": request.id, "authority_deadline_at": iso(deadline), "cancellation": context,
            "activity_members": activity_members(events, &scope.id)?, "timer_members": timer_members(events, &scope.id)?,
            "wait_members": [], "child_members": []}));
    }
    Ok(members)
}

/// A descendant's parent context; a root parent has none here, since a
/// descendant of a prepared scope always has a scoped parent.
fn context_parent(events: &[Event], scopes: &[Scope], run: &RunFacts, parent: &str) -> Result<Option<Value>> {
    context(events, scopes, &run.id, parent)
}

/// `CancellationScopeDelivery::prepared` and `recorded`: the scope's one
/// preparation or delivery marker.
fn marker<'a>(events: &'a [Event], scopes: &[Scope], scope_id: &str, kind: &str) -> Result<Option<&'a Event>> {
    assert_scope(scopes, scope_id)?;
    let mut matching = events.iter().filter(|event| event.kind == kind && event.payload["scope_id"] == scope_id);
    let first = matching.next();
    if matching.next().is_some() || first.is_some() && accepted(events, scope_id)?.is_none() {
        return Err(no("cancellation_scope_delivery_history_invalid"));
    }
    Ok(first)
}

fn prepared<'a>(events: &'a [Event], scopes: &[Scope], scope_id: &str) -> Result<Option<&'a Event>> {
    marker(events, scopes, scope_id, "CancellationScopeDeliveryPrepared")
}

fn recorded<'a>(events: &'a [Event], scopes: &[Scope], scope_id: &str) -> Result<Option<&'a Event>> {
    marker(events, scopes, scope_id, "CancellationScopeDelivered")
}

fn same_boundary(payload: &Value, boundary: &Boundary) -> bool {
    payload["sequence"] == boundary.sequence
        && payload["call_kind"] == boundary.kind.as_str()
        && payload["sequence_span"] == boundary.span
        && payload["operation_sequence"] == json!(boundary.operation_sequence)
        && payload["operation_sequence_span"] == boundary.operation_span
}

/// `replaysOriginalOperation`: the command at this sequence was recorded in
/// this scope before the preparation.
fn replays_original(events: &[Event], preparation: &Event, scope_id: &str, sequence: i64) -> bool {
    events.iter().any(|event| {
        event.sequence < preparation.sequence
            && OPERATION_EVENTS.contains(&event.kind.as_str())
            && event.payload["sequence"] == sequence
            && membership(event).as_deref() == Some(scope_id)
    })
}

/// `positionReserved`: an undelivered preparation owns its exact boundary.
fn position_reserved(events: &[Event], scopes: &[Scope], sequence: i64, scope_id: Option<&str>) -> Result<bool> {
    for event in events.iter().filter(|event| event.kind == "CancellationScopeDeliveryPrepared") {
        let scope = event.payload["scope_id"].as_str().filter(|scope| !scope.is_empty())
            .ok_or_else(|| no("cancellation_scope_preparation_history_invalid"))?;
        let preparation = prepared(events, scopes, scope)?.ok_or_else(|| no("cancellation_scope_preparation_history_invalid"))?;
        if preparation.payload["sequence"] == sequence
            && recorded(events, scopes, scope)?.is_none()
            && scope_id.is_none_or(|scope_id| !replays_original(events, preparation, scope_id, sequence))
        {
            return Ok(true);
        }
    }
    Ok(false)
}

/// `CancellationScopeDelivery::admissionRefusal`: a prepared scope, or an
/// unshielded scope under one, admits no new member; an undelivered
/// preparation reserves its boundary for every command.
pub(super) fn admission_refusal(events: &[Event], scopes: &[Scope], scope_id: &str, sequence: i64) -> Result<Option<&'static str>> {
    if scope_id != ROOT {
        let mut address = scope_id.to_owned();
        while let Some(scope) = find(scopes, &address) {
            if let Some(preparation) = prepared(events, scopes, &address)? {
                return Ok((!replays_original(events, preparation, scope_id, sequence)).then_some("operation_scope_cancellation_prepared"));
            }
            if scope.shield {
                break;
            }
            address = scope.parent.clone();
        }
    }
    Ok(position_reserved(events, scopes, sequence, Some(scope_id))?.then_some("operation_cancellation_delivery_reserved"))
}

/// `validateCancellationScopeMembership` over a batch starting at `start`.
pub(super) fn membership_refusal(events: &[Event], scopes: &[Scope], commands: &[&Value], start: i64) -> Result<Option<&'static str>> {
    for command in commands {
        if let Some(scope) = command["cancellation_scope_id"].as_str()
            && command["type"].as_str().is_some_and(|kind| MEMBER_COMMANDS.contains(&kind))
            && !recorded_before(scopes, scope, start)
        {
            return Ok(Some("operation_scope_not_recorded"));
        }
    }
    for (offset, command) in commands.iter().enumerate() {
        let scope = command["cancellation_scope_id"].as_str().unwrap_or(ROOT);
        if let Some(refusal) = admission_refusal(events, scopes, scope, start + offset as i64)? {
            return Ok(Some(refusal));
        }
    }
    Ok(None)
}

/// Whether a command carries a scope the admission guard reads.
pub(super) fn guard_reason(commands: &[Value], protocol_supported: bool) -> (Option<&'static str>, Vec<&'static str>) {
    let mut reason = None;
    let mut unavailable = Vec::new();
    if !protocol_supported {
        reason = Some("cancellation_scope_membership_unavailable");
        unavailable.push("request_protocol");
    }
    for command in commands {
        if command.get("cancellation_scope_id").is_some()
            && !command["type"].as_str().is_some_and(|kind| SCOPED_COMMANDS.contains(&kind))
        {
            reason = Some("invalid_cancellation_scope_command");
        }
    }
    (reason, unavailable)
}

/// A delivery boundary named by a worker.
#[derive(Clone, Debug)]
pub(super) struct Boundary {
    sequence: i64,
    kind: String,
    span: i64,
    operation_sequence: Option<i64>,
    operation_span: i64,
}

/// `CooperativeCancellationDelivery::validateBoundarySyntax`.
fn boundary_syntax(boundary: &Boundary) -> Option<&'static str> {
    let shaped = matches!(
        boundary.kind.as_str(),
        "activity" | "local_activity" | "timer" | "condition" | "signal" | "child" | "service_operation" | "parallel" | "selection_handle"
    );
    (boundary.sequence < 1
        || boundary.span < 1
        || boundary.operation_span < 1
        || boundary.sequence > i64::MAX - boundary.span
        || boundary.span > SPAN_LIMIT
        || boundary.operation_span > SPAN_LIMIT
        || !shaped
        || (boundary.kind != "parallel" && boundary.span != 1)
        || ((boundary.kind == "selection_handle") != boundary.operation_sequence.is_some())
        || (boundary.kind != "selection_handle" && boundary.operation_span != 1))
        .then_some("invalid_cancellation_delivery")
}

fn local_activity(event: &Event) -> bool {
    event.payload["local_activity"] == true || event.payload["execution_mode"] == "local"
}

/// `WorkflowStepHistory::eventMatchesShape` for a call kind.
fn matches_shape(event: &Event, kind: &str) -> bool {
    let timer_kind = event.payload["timer_kind"].as_str();
    let timer = matches!(event.kind.as_str(), "TimerScheduled" | "TimerFired" | "TimerCancelled");
    match kind {
        "activity" | "local_activity" => {
            event.kind.starts_with("Activity")
                && STEP_EVENTS.contains(&event.kind.as_str())
                && local_activity(event) == (kind == "local_activity")
        }
        "timer" => timer && !matches!(timer_kind, Some("condition_timeout" | "signal_timeout")),
        "condition" => {
            matches!(event.kind.as_str(), "ConditionWaitOpened" | "ConditionWaitSatisfied" | "ConditionWaitTimedOut")
                || timer && timer_kind == Some("condition_timeout")
        }
        "signal" => matches!(event.kind.as_str(), "SignalWaitOpened" | "SignalApplied") || timer && timer_kind == Some("signal_timeout"),
        "child" => event.kind.starts_with("Child") && STEP_EVENTS.contains(&event.kind.as_str()),
        "service_operation" => event.kind.starts_with("ServiceCall"),
        _ => false,
    }
}

/// `validateCallBoundary`: the boundary is at most the next sequence, its
/// typed history matches the call, and it did not resolve before the
/// request.
fn call_boundary(events: &[Event], next: i64, request: &Event, boundary: &Boundary) -> Option<&'static str> {
    if boundary.sequence > next {
        return Some("cancellation_delivery_sequence_mismatch");
    }
    match boundary.kind.as_str() {
        "parallel" if boundary.sequence == next => return None,
        "parallel" | "selection_handle" => return Some("cancellation_scope_delivery_unavailable"),
        _ => {}
    }
    let typed: Vec<&Event> = events
        .iter()
        .filter(|event| STEP_EVENTS.contains(&event.kind.as_str()) && event.payload["sequence"] == boundary.sequence)
        .collect();
    if typed.iter().any(|event| !matches_shape(event, &boundary.kind)) || (boundary.sequence < next && typed.is_empty()) {
        return Some("cancellation_delivery_shape_mismatch");
    }
    resolved_before(events, boundary.sequence, request.sequence).then_some("cancellation_delivery_not_eligible")
}

fn resolved_before(events: &[Event], sequence: i64, request_sequence: i64) -> bool {
    events.iter().any(|event| {
        event.sequence < request_sequence
            && RESOLUTION_EVENTS.contains(&event.kind.as_str())
            && (event.payload["sequence"] == sequence
                || event.kind == "SelectionOperationCancelled"
                    && event.payload["member_base_sequence"].as_i64().zip(event.payload["member_size"].as_i64())
                        .is_some_and(|(base, size)| base >= 1 && base <= sequence && size > sequence - base))
    })
}

/// `matchesMembership`: an unscheduled boundary keeps its address; a
/// scheduled one must touch the scope's unshielded subtree.
fn matches_membership(events: &[Event], scopes: &[Scope], scope_id: &str, start: i64, span: i64) -> bool {
    let mut included: BTreeSet<&str> = BTreeSet::from([scope_id]);
    for scope in scopes {
        if !scope.shield && included.contains(scope.parent.as_str()) {
            included.insert(scope.id.as_str());
        }
    }
    let mut scheduled = false;
    let mut affected = false;
    for event in events.iter().filter(|event| OPERATION_EVENTS.contains(&event.kind.as_str())) {
        let Some(sequence) = event.payload["sequence"].as_i64() else {
            continue;
        };
        if sequence < start || sequence - start >= span {
            continue;
        }
        let Some(address) = membership(event) else {
            return false;
        };
        if address != ROOT {
            let Some(member) = find(scopes, &address) else {
                return false;
            };
            let opened = events.iter().find(|candidate| candidate.id == member.event_id);
            if member.sequence >= sequence || opened.is_none_or(|opened| opened.sequence >= event.sequence) {
                return false;
            }
        }
        scheduled = true;
        affected |= included.contains(address.as_str());
    }
    !scheduled || affected
}

/// `ScopedCancellationPreparation`: a preparation, read for its own scope or
/// one of its frozen descendants.
#[derive(Clone, Debug, PartialEq)]
struct Reference {
    event_id: String,
    history_sequence: i64,
    scope_id: String,
    request_id: String,
    request_event_id: String,
    context: Value,
    authority_deadline_at: String,
    activity_members: Vec<Value>,
    timer_members: Vec<Value>,
}

fn reference(events: &[Event], scopes: &[Scope], run: &RunFacts, scope_id: &str, preparation_id: Option<&str>) -> Result<Option<Reference>> {
    let event = match preparation_id {
        None => match prepared(events, scopes, scope_id)? {
            Some(event) => event,
            None => return Ok(None),
        },
        Some(preparation_id) => {
            let invalid = || no("cancellation_scope_preparation_reference_invalid");
            let original = events
                .iter()
                .find(|event| event.id == preparation_id && event.kind == "CancellationScopeDeliveryPrepared")
                .ok_or_else(invalid)?;
            let prepared_scope = original.payload["scope_id"].as_str().filter(|scope| !scope.is_empty()).ok_or_else(invalid)?;
            let event = prepared(events, scopes, prepared_scope)?.ok_or_else(invalid)?;
            if event.id != preparation_id {
                return Err(invalid());
            }
            event
        }
    };
    let payload = &event.payload;
    let snapshot = if payload["scope_id"] == scope_id {
        payload.clone()
    } else {
        let mut matching = payload["descendant_members"].as_array().into_iter().flatten().filter(|member| member["scope_id"] == scope_id);
        match (matching.next(), matching.next()) {
            (Some(member), None) => member.clone(),
            _ => return Err(no("cancellation_scope_descendant_not_prepared")),
        }
    };
    let request = accepted(events, scope_id)?.ok_or_else(|| no("cancellation_scope_preparation_reference_invalid"))?;
    let context = snapshot["cancellation"].clone();
    if last_lineage(&context)["scope_id"] != scope_id
        || last_lineage(&context)["workflow_run_id"] != run.id.as_str()
        || context_request(&context) != snapshot["request_id"].as_str()
        || request.payload["request_id"] != snapshot["request_id"]
        || request.sequence >= event.sequence
    {
        return Err(no("cancellation_scope_preparation_reference_invalid"));
    }
    let list = |field: &str| snapshot[field].as_array().cloned().unwrap_or_default();
    Ok(Some(Reference {
        event_id: event.id.clone(),
        history_sequence: event.sequence,
        scope_id: scope_id.to_owned(),
        request_id: snapshot["request_id"].as_str().unwrap_or_default().to_owned(),
        request_event_id: request.id.clone(),
        context,
        authority_deadline_at: snapshot["authority_deadline_at"].as_str().unwrap_or_default().to_owned(),
        activity_members: list("activity_members"),
        timer_members: list("timer_members"),
    }))
}

/// `ScopedCancellationReconciliation::earlier`: strictly earlier
/// preparations that froze the same scope.
fn earlier(events: &[Event], scopes: &[Scope], run: &RunFacts, current: &Reference) -> Result<Vec<(String, Reference)>> {
    let mut candidates = Vec::new();
    for event in events.iter().filter(|event| event.kind == "CancellationScopeDeliveryPrepared" && event.sequence < current.history_sequence) {
        let scope = event.payload["scope_id"].as_str().ok_or_else(|| no("cancellation_scope_preparation_history_invalid"))?;
        let covers = scope == current.scope_id
            || event.payload["descendant_members"].as_array().into_iter().flatten().any(|member| member["scope_id"] == current.scope_id.as_str());
        if !covers {
            continue;
        }
        if prepared(events, scopes, scope)?.is_none_or(|prepared| prepared.id != event.id) {
            return Err(no("cancellation_scope_preparation_history_invalid"));
        }
        let original = reference(events, scopes, run, &current.scope_id, Some(&event.id))?
            .ok_or_else(|| no("cancellation_scope_descendant_delivery_membership_mismatch"))?;
        if original.request_event_id != current.request_event_id
            || original.context != current.context
            || original.activity_members != current.activity_members
            || original.timer_members != current.timer_members
        {
            return Err(no("cancellation_scope_descendant_delivery_membership_mismatch"));
        }
        candidates.push((scope.to_owned(), original));
    }
    Ok(candidates)
}

/// The earlier candidate whose scope was delivered (before `before`, when
/// given), with its delivery marker.
fn completed<'a>(events: &'a [Event], candidates: &[(String, Reference)], before: Option<i64>) -> Option<(Reference, &'a Event)> {
    for (scope, reference) in candidates {
        let delivery = events
            .iter()
            .find(|event| event.kind == "CancellationScopeDelivered" && event.payload["scope_id"] == scope.as_str());
        if let Some(delivery) = delivery.filter(|delivery| before.is_none_or(|before| delivery.sequence < before)) {
            return Some((reference.clone(), delivery));
        }
    }
    None
}

fn pending(events: &[Event], scopes: &[Scope], run: &RunFacts, current: &Reference, before: Option<i64>) -> Result<bool> {
    let candidates = earlier(events, scopes, run, current)?;
    Ok(!candidates.is_empty() && completed(events, &candidates, before).is_none())
}

/// `ScopedTimerCancellation::terminal`: the timer's fire or cancellation.
fn timer_terminal<'a>(events: &'a [Event], timer_id: &str, sequence: i64) -> Result<Option<&'a Event>> {
    let invalid = || no("cancellation_scope_timer_history_invalid");
    let mut scheduled = events.iter().filter(|event| event.kind == "TimerScheduled" && event.payload["timer_id"] == timer_id);
    let (Some(scheduled), None) = (scheduled.next(), scheduled.next()) else {
        return Err(invalid());
    };
    if scheduled.payload["sequence"] != sequence {
        return Err(invalid());
    }
    let mut terminals = events
        .iter()
        .filter(|event| matches!(event.kind.as_str(), "TimerCancelled" | "TimerFired") && event.payload["timer_id"] == timer_id);
    let terminal = terminals.next();
    if terminals.next().is_some() {
        return Err(invalid());
    }
    if let Some(terminal) = terminal
        && (terminal.payload["sequence"] != sequence
            || terminal.sequence <= scheduled.sequence
            || terminal.payload["delay_seconds"] != scheduled.payload["delay_seconds"]
            || terminal.payload["fire_at"] != scheduled.payload["fire_at"])
    {
        return Err(invalid());
    }
    Ok(terminal)
}

/// `assertReceipt`: a scoped timer cancellation recorded for this reference.
fn timer_receipt(events: &[Event], run: &RunFacts, reference: &Reference, terminal: &Event) -> Result<()> {
    let snapshot = &terminal.payload["cancellation_scope"];
    if snapshot["schema"] != TIMER_SCOPE_SCHEMA
        || snapshot["workflow_run_id"] != run.id.as_str()
        || snapshot["scope_id"] != reference.scope_id.as_str()
        || snapshot["request_id"] != reference.request_id.as_str()
        || snapshot["request_history_event_id"] != reference.request_event_id.as_str()
        || snapshot["preparation_history_event_id"] != reference.event_id.as_str()
        || snapshot["authority_deadline_at"] != reference.authority_deadline_at.as_str()
        || snapshot["cancellation"] != reference.context
        || terminal.sequence <= reference.history_sequence
        || accepted(events, &reference.scope_id)?.is_none_or(|request| request.id != reference.request_event_id)
    {
        return Err(no("cancellation_scope_timer_fence_not_established"));
    }
    Ok(())
}

/// The activity receipt fields of a fenced activity.
fn fenced_activity(event: &Event, context: &Value) -> Value {
    let snapshot = &event.payload["cancellation_scope"];
    json!({"fenced": true, "abandoned": false, "history_event_id": event.id, "request_id": context_request(context),
        "root_request_id": context["root_context"]["root_request_id"], "scope_id": last_lineage(context)["scope_id"],
        "cleanup_deadline_at": last_lineage(context)["cleanup_deadline_at"],
        "cancellation_scope": {"schema": snapshot["schema"], "workflow_run_id": snapshot["workflow_run_id"],
            "scope_id": snapshot["scope_id"], "request_id": snapshot["request_id"],
            "request_history_event_id": snapshot["request_history_event_id"], "cancellation": snapshot["cancellation"],
            "authority_deadline_at": snapshot["authority_deadline_at"]},
        "waiting_for_stop": false})
}

/// `ScopedCancellationReconciliation::receipts`: a delivered reference's
/// receipts, rebuilt from history before its marker.
fn receipts(events: &[Event], reference: &Reference, delivery: &Event) -> Result<Receipts> {
    let prefix: Vec<Event> = events.iter().filter(|event| event.sequence < delivery.sequence).cloned().collect();
    let mut result = Receipts::default();
    for member in &reference.timer_members {
        let timer_id = member["timer_id"].as_str().unwrap_or_default();
        let terminal = timer_terminal(&prefix, timer_id, member["sequence"].as_i64().unwrap_or_default())?
            .ok_or_else(|| no("cancellation_scope_timer_fence_not_established"))?;
        let cancelled = terminal.kind == "TimerCancelled";
        result.timers.push(json!({"fenced": cancelled, "history_event_id": if cancelled { json!(terminal.id) } else { Value::Null },
            "timer_id": timer_id, "sequence": member["sequence"]}));
    }
    for member in &reference.activity_members {
        let execution = &member["activity_execution_id"];
        let scheduled = prefix.iter().find(|event| event.kind == "ActivityScheduled" && &event.payload["activity_execution_id"] == execution);
        let cancelled = prefix.iter().find(|event| event.kind == "ActivityCancelled" && &event.payload["activity_execution_id"] == execution);
        let mut receipt = match cancelled {
            Some(cancelled) => fenced_activity(cancelled, &reference.context),
            None => json!({"fenced": false,
                "abandoned": scheduled.is_some_and(|scheduled| scheduled.payload["activity"]["cancellation_policy"] == "abandon"),
                "waiting_for_stop": false, "history_event_id": null}),
        };
        receipt["sequence"] = member["sequence"].clone();
        receipt["activity_execution_id"] = execution.clone();
        result.activities.push(receipt);
    }
    Ok(result)
}

#[derive(Default)]
struct Receipts {
    activities: Vec<Value>,
    timers: Vec<Value>,
}

/// The refusal shapes of the open and delivery routes.
fn open_refusal(task_id: &str, reason: &str) -> Value {
    json!({"opened": false, "duplicate": false, "claim_released": false, "task_id": task_id, "created_task_ids": [], "reason": reason})
}

fn delivery_refusal(task_id: &str, reason: &str) -> Value {
    json!({"prepared": false, "delivered": false, "claim_released": false, "task_id": task_id, "created_task_ids": [], "reason": reason})
}

/// `WorkerProtocol::json`'s envelope.
pub(super) fn enveloped(mut response: Value) -> Value {
    response["protocol_version"] = json!(super::WORKER_PROTOCOL_VERSION);
    response["server_capabilities"] = capabilities();
    response
}

/// The reasons `PortableCancellationScopeDelivery` answers itself. Any other
/// engine exception escapes it in PHP; `root_scope_requires_run_cancellation`
/// is one (a published 500), which native answers as a refusal instead.
fn delivery_reason(reason: &str) -> bool {
    reason.starts_with("cancellation_scope_")
        || reason.starts_with("cancellation_delivery_")
        || matches!(
            reason,
            "invalid_cancellation_scope_delivery" | "invalid_cancellation_delivery" | "activity_cancellation_owned_by_another_request"
                | "root_scope_requires_run_cancellation"
        )
}

/// The reasons `PortableCancellationScopeOpening` answers itself.
fn open_reason(reason: &str) -> bool {
    matches!(
        reason,
        "cancellation_scope_workflow_claim_mismatch" | "cancellation_scope_run_not_active" | "cancellation_scope_parent_not_recorded"
            | "cancellation_scope_sequence_mismatch" | "cancellation_scope_history_invalid" | "cancellation_scope_replay_mismatch"
            | "cancellation_scope_command_sequence_reserved" | "cancellation_scope_parent_delivery_prepared"
    )
}


use super::laravel_rules::{self, Rule};

/// Published PHP's validation rules for opening a scope, in their order.
const OPEN_RULES: &laravel_rules::Rules = &[
    ("lease_owner", &[Rule::Required, Rule::String, Rule::Max(255)]),
    ("workflow_task_attempt", &[Rule::Required, Rule::Integer, Rule::Min(1)]),
    ("sequence", &[Rule::Required, Rule::Integer, Rule::Min(1)]),
    ("parent_scope_id", &[Rule::Sometimes, Rule::Required, Rule::String, Rule::Max(255)]),
    ("shield_parent", &[Rule::Sometimes, Rule::Required, Rule::Boolean]),
];

/// Published PHP's validation rules for preparing or delivering a scope.
const DELIVERY_RULES: &laravel_rules::Rules = &[
    ("lease_owner", &[Rule::Required, Rule::String, Rule::Max(255)]),
    ("workflow_task_attempt", &[Rule::Required, Rule::Integer, Rule::Min(1)]),
    ("scope_id", &[Rule::Required, Rule::String, Rule::Max(255)]),
    ("request_id", &[Rule::Required, Rule::String, Rule::Max(255)]),
    ("sequence", &[Rule::Required, Rule::Integer, Rule::Min(1)]),
    ("call_kind", &[Rule::Required,
        Rule::In(&["activity", "local_activity", "timer", "condition", "signal", "child", "parallel", "selection_handle"])]),
    ("sequence_span", &[Rule::Sometimes, Rule::Required, Rule::Integer, Rule::Min(1)]),
    ("operation_sequence", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
    ("operation_sequence_span", &[Rule::Sometimes, Rule::Required, Rule::Integer, Rule::Min(1)]),
];

fn protocol_supported(protocol: &str) -> bool {
    super::cooperative::supports(&json!(["cooperative_cancellation"]), protocol)
}

/// A validated integer field, as PHP's `(int)` casts it.
fn integer(value: &Value) -> Option<i64> {
    match value {
        Value::Number(number) => number.as_i64().or_else(|| number.as_f64().map(|float| float as i64)),
        Value::String(text) => text.trim().parse().ok(),
        Value::Bool(flag) => Some(i64::from(*flag)),
        _ => None,
    }
}

/// A validated boolean field, as PHP's `(bool)` casts it.
fn boolean(value: &Value) -> Option<bool> {
    match value {
        Value::Bool(flag) => Some(*flag),
        Value::Number(number) => Some(number.as_i64() != Some(0)),
        Value::String(text) => Some(text != "0"),
        _ => None,
    }
}

/// The delivery response's preparation frame (`PortableCancellationScopeDelivery::frame`).
fn frame(response: &mut Value, run_id: &str, payload: &Value, preparation_id: &str) {
    response["prepared"] = json!(true);
    response["workflow_run_id"] = json!(run_id);
    response["preparation_history_event_id"] = json!(preparation_id);
    for field in ["scope_id", "request_id", "cancellation", "sequence", "call_kind", "sequence_span", "operation_sequence",
        "operation_sequence_span", "authority_deadline_at"]
    {
        response[field] = payload[field].clone();
    }
    for field in ["activity_members", "timer_members", "wait_members", "child_members"] {
        if let Some(members) = payload.get(field) {
            response[field] = members.clone();
        }
    }
}

store_methods! {
    /// The run's history, as the scope engine reads it.
    pub(super) async fn scope_events(tx: &mut Transaction<'_, DB>, run_id: &str) -> Result<Vec<Event>> {
        let rows = Self::query("SELECT id,sequence,event_type,payload,workflow_command_id FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence LIMIT 10001")
            .bind(run_id)
            .fetch_all(&mut **tx)
            .await?;
        if rows.len() > 10000 {
            return Err(refuse(StatusCode::SERVICE_UNAVAILABLE, "cancellation_inventory_exceeds_budget"));
        }
        rows.iter()
            .map(|row| {
                Ok(Event {
                    id: DB::string(row, "id")?,
                    sequence: DB::number(row, "sequence")?,
                    kind: DB::string(row, "event_type")?,
                    payload: DB::document_row(row, "payload")?,
                    command_id: DB::optional_string(row, "workflow_command_id")?,
                })
            })
            .collect()
    }

    pub(super) async fn scope_run(tx: &mut Transaction<'_, DB>, run_id: &str) -> Result<(DB::Row, RunFacts)> {
        let run = Self::query("SELECT * FROM workflow_runs WHERE id=$1").bind(run_id).fetch_one(&mut **tx).await?;
        let facts = RunFacts {
            id: DB::string(&run, "id")?,
            instance: DB::string(&run, "workflow_instance_id")?,
            status: DB::string(&run, "status")?,
            request_id: DB::optional_string(&run, "cancellation_request_command_id")?,
            execution_deadline: DB::optional_instant(&run, "execution_deadline_at")?,
            run_deadline: DB::optional_instant(&run, "run_deadline_at")?,
        };
        Ok((run, facts))
    }

    /// The live claim, when it is still this worker's leased workflow task
    /// of the run (`matchesClaim`).
    async fn scope_claim(tx: &mut Transaction<'_, DB>, task_id: &str, run_id: &str, owner: &str, attempt: i64) -> Result<DB::Row> {
        let claim = Self::query("SELECT * FROM workflow_tasks WHERE id=$1").bind(task_id).fetch_optional(&mut **tx).await?;
        let run = Self::query("SELECT namespace FROM workflow_runs WHERE id=$1").bind(run_id).fetch_one(&mut **tx).await?;
        let Some(claim) = claim else {
            return Err(no("cancellation_scope_workflow_claim_mismatch"));
        };
        if DB::string(&claim, "workflow_run_id")? != run_id
            || DB::string(&claim, "namespace")? != DB::string(&run, "namespace")?
            || DB::string(&claim, "task_type")? != "workflow"
            || DB::string(&claim, "status")? != "leased"
            || DB::optional_string(&claim, "lease_owner")?.as_deref().is_none_or(|lease| lease.is_empty() || lease != owner)
            || DB::number(&claim, "attempt_count")? != attempt
            || attempt < 1
            || DB::optional_instant(&claim, "lease_expires_at")?.is_none_or(|expires| now() >= expires)
        {
            return Err(no("cancellation_scope_workflow_claim_mismatch"));
        }
        Ok(claim)
    }

    /// The scope controller's checks before the engine, in PHP's order: the
    /// task and its run in this namespace, the claim's owner, attempt and
    /// issued capability, and a fresh worker registration.
    async fn scope_route_gate(&self, tx: &mut Transaction<'_, DB>, task_id: &str, owner: &str, attempt: i64)
        -> Result<std::result::Result<(DB::Row, DB::Row), (StatusCode, &'static str)>>
    {
        let not_found = Ok(Err((StatusCode::NOT_FOUND, "task_not_found")));
        let Some(task) = Self::query("SELECT * FROM workflow_tasks WHERE id=$1 AND namespace=$2")
            .bind(task_id).bind(self.namespace.as_ref()).fetch_optional(&mut **tx).await?
        else {
            return not_found;
        };
        let Some(run) = Self::query("SELECT * FROM workflow_runs WHERE id=$1 AND namespace=$2")
            .bind(DB::string(&task, "workflow_run_id")?).bind(self.namespace.as_ref()).fetch_optional(&mut **tx).await?
        else {
            return not_found;
        };
        if Self::scalar("SELECT COUNT(*) FROM workflow_instances WHERE id=$1 AND namespace=$2")
            .bind(DB::string(&run, "workflow_instance_id")?).bind(self.namespace.as_ref()).fetch_one(&mut **tx).await? == 0
        {
            return not_found;
        }
        if DB::optional_string(&task, "lease_owner")?.as_deref() != Some(owner) {
            return Ok(Err((StatusCode::CONFLICT, "lease_owner_mismatch")));
        }
        if DB::number(&task, "attempt_count")? != attempt {
            return Ok(Err((StatusCode::CONFLICT, "workflow_task_attempt_mismatch")));
        }
        if !Self::claim_supports_cancellation(&task)? {
            return Ok(Err((StatusCode::CONFLICT, "active_claim_cancellation_not_supported")));
        }
        let worker = Self::query("SELECT status,last_heartbeat_at FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
            .bind(self.namespace.as_ref()).bind(owner).fetch_optional(&mut **tx).await?;
        let fresh = match &worker {
            Some(worker) => DB::string(worker, "status")? == "active"
                && DB::optional_instant(worker, "last_heartbeat_at")?
                    .is_some_and(|heartbeat| heartbeat >= now() - chrono::Duration::seconds(self.worker_stale_after_seconds)),
            None => false,
        };
        if !fresh {
            return Ok(Err((StatusCode::CONFLICT, "stale_worker_registration")));
        }
        Ok(Ok((task, run)))
    }

    /// POST …/cancellation-scopes/open.
    pub(crate) async fn open_cancellation_scope(&self, task_id: &str, body: Value, protocol: &str) -> Result<(StatusCode, Value)> {
        let short = |status: StatusCode, reason: &str| Ok((status, enveloped(json!({"opened": false, "reason": reason}))));
        if !protocol_supported(protocol) {
            return short(StatusCode::CONFLICT, "cancellation_scope_requires_protocol_1_20");
        }
        let body = laravel_rules::normalize(&body);
        if let Err(RuntimeError::Protocol { status, response }) = laravel_rules::validate(&body, OPEN_RULES) {
            return Ok((status, enveloped(response)));
        }
        let owner = body["lease_owner"].as_str().unwrap_or_default().to_owned();
        let attempt = integer(&body["workflow_task_attempt"]).unwrap_or_default();
        let sequence = integer(&body["sequence"]).unwrap_or_default();
        let parent = body["parent_scope_id"].as_str().unwrap_or(ROOT).to_owned();
        let shield = boolean(&body["shield_parent"]).unwrap_or(false);
        let mut tx = self.begin().await?;
        let run = match self.scope_route_gate(&mut tx, task_id, &owner, attempt).await? {
            Ok((_, run)) => run,
            Err((status, reason)) => return short(status, reason),
        };
        let run_id = DB::string(&run, "id")?;
        match Self::open_scope(&mut tx, task_id, &run_id, &owner, attempt, sequence, &parent, shield).await {
            Ok(mut response) => {
                tx.commit().await?;
                response["lease_owner"] = json!(owner);
                response["workflow_task_attempt"] = json!(attempt);
                response["history_refresh_page_token"] = json!("MA==");
                Ok((StatusCode::OK, enveloped(response)))
            }
            Err(RuntimeError::Refused { reason, .. }) if open_reason(reason) => {
                tx.rollback().await?;
                Ok((StatusCode::CONFLICT, enveloped(open_refusal(task_id, reason))))
            }
            Err(error) => Err(error),
        }
    }

    /// `PortableCancellationScopeOpening::open` and `CancellationScopeHistory::open`.
    #[allow(clippy::too_many_arguments)]
    async fn open_scope(tx: &mut Transaction<'_, DB>, task_id: &str, run_id: &str, owner: &str, attempt: i64,
        sequence: i64, parent: &str, shield: bool) -> Result<Value>
    {
        let claim = Self::scope_claim(tx, task_id, run_id, owner, attempt).await?;
        let events = Self::scope_events(tx, run_id).await?;
        let duplicate = events.iter().any(|event| event.kind == "CancellationScopeOpened" && event.payload["sequence"] == sequence);
        let (_, facts) = Self::scope_run(tx, run_id).await?;
        if facts.terminal() {
            return Err(no("cancellation_scope_run_not_active"));
        }
        let scopes = scopes(&events, run_id)?;
        let opened = |event_id: &str, scope_id: &str, parent: &str, shield: bool, sequence: i64| json!({"opened": true,
            "duplicate": duplicate, "claim_released": false, "task_id": task_id, "workflow_run_id": run_id, "history_event_id": event_id,
            "scope_id": scope_id, "parent_scope_id": parent, "shield_parent": shield, "sequence": sequence, "created_task_ids": [],
            "reason": null});
        // A replay returns the recorded scope, even after a request.
        if let Some(scope) = scopes.iter().find(|scope| scope.sequence == sequence) {
            if scope.parent != parent || scope.shield != shield {
                return Err(no("cancellation_scope_replay_mismatch"));
            }
            return Ok(opened(&scope.event_id, &scope.id, &scope.parent, scope.shield, scope.sequence));
        }
        if facts.request_id.is_some()
            || [facts.execution_deadline, facts.run_deadline].into_iter().flatten().any(|deadline| now() >= deadline)
        {
            return Err(no("cancellation_scope_run_not_active"));
        }
        if parent != ROOT && find(&scopes, parent).is_none() {
            return Err(no("cancellation_scope_parent_not_recorded"));
        }
        if let Some(refusal) = admission_refusal(&events, &scopes, parent, sequence)? {
            return Err(no(if refusal == "operation_cancellation_delivery_reserved" {
                "cancellation_scope_command_sequence_reserved"
            } else {
                "cancellation_scope_parent_delivery_prepared"
            }));
        }
        // `assertCompatible`: no other workflow step owns the sequence.
        if events.iter().any(|event| {
            STEP_EVENTS.contains(&event.kind.as_str()) && event.kind != "CancellationScopeOpened" && event.payload["sequence"] == sequence
        }) {
            return Err(no("cancellation_scope_replay_mismatch"));
        }
        if sequence != Self::authored_sequence(tx, run_id).await? + 1 {
            return Err(no("cancellation_scope_sequence_mismatch"));
        }
        let scope_id = id();
        let payload = json!({"schema": SCOPE_SCHEMA, "workflow_run_id": run_id, "sequence": sequence, "scope_id": scope_id,
            "parent_scope_id": parent, "shield_parent": shield, "task": Self::task_snapshot(&claim)?});
        let event_id = Self::append_returning(tx, run_id, "CancellationScopeOpened", payload, Some(task_id), None).await?;
        Self::scope_progress(tx, run_id).await?;
        Ok(opened(&event_id, &scope_id, parent, shield, sequence))
    }

    /// `WorkflowHistoryEvent::record` marks the run's progress.
    async fn scope_progress(tx: &mut Transaction<'_, DB>, run_id: &str) -> Result<()> {
        Self::query("UPDATE workflow_runs SET last_progress_at=$1 WHERE id=$2")
            .bind(DB::bind_time(now())).bind(run_id).execute(&mut **tx).await?;
        Ok(())
    }

    /// POST …/cancellation-scopes/prepare.
    pub(crate) async fn prepare_cancellation_scope(&self, task_id: &str, body: Value, protocol: &str) -> Result<(StatusCode, Value)> {
        self.scope_delivery(task_id, body, protocol, true).await
    }

    /// POST …/cancellation-scopes/deliver.
    pub(crate) async fn deliver_cancellation_scope(&self, task_id: &str, body: Value, protocol: &str) -> Result<(StatusCode, Value)> {
        self.scope_delivery(task_id, body, protocol, false).await
    }

    async fn scope_delivery(&self, task_id: &str, body: Value, protocol: &str, preparing: bool) -> Result<(StatusCode, Value)> {
        let refused = |status: StatusCode, reason: &str| Ok((status, enveloped(delivery_refusal(task_id, reason))));
        if !protocol_supported(protocol) {
            return refused(StatusCode::CONFLICT, "cancellation_scope_requires_protocol_1_20");
        }
        let body = laravel_rules::normalize(&body);
        if let Err(RuntimeError::Protocol { status, response }) = laravel_rules::validate(&body, DELIVERY_RULES) {
            return Ok((status, enveloped(response)));
        }
        let owner = body["lease_owner"].as_str().unwrap_or_default().to_owned();
        let attempt = integer(&body["workflow_task_attempt"]).unwrap_or_default();
        let scope_id = body["scope_id"].as_str().unwrap_or_default().to_owned();
        let request_id = body["request_id"].as_str().unwrap_or_default().to_owned();
        let boundary = Boundary {
            sequence: integer(&body["sequence"]).unwrap_or_default(),
            kind: body["call_kind"].as_str().unwrap_or_default().to_owned(),
            span: integer(&body["sequence_span"]).unwrap_or(1),
            operation_sequence: integer(&body["operation_sequence"]),
            operation_span: integer(&body["operation_sequence_span"]).unwrap_or(1),
        };
        let mut tx = self.begin().await?;
        let run = match self.scope_route_gate(&mut tx, task_id, &owner, attempt).await? {
            Ok((_, run)) => run,
            Err((status, reason)) => return refused(status, reason),
        };
        let run_id = DB::string(&run, "id")?;
        let mut response = match self.scope_delivery_engine(&mut tx, task_id, &run_id, &owner, attempt, &scope_id, &request_id, &boundary, preparing).await {
            Ok(response) => {
                tx.commit().await?;
                response
            }
            Err(RuntimeError::Refused { reason, .. }) if delivery_reason(reason) => {
                tx.rollback().await?;
                delivery_refusal(task_id, reason)
            }
            Err(error) => return Err(error),
        };
        self.wake.notify_waiters();
        let prepared = response["prepared"] == true;
        if prepared {
            response["lease_owner"] = json!(owner);
            response["workflow_task_attempt"] = json!(attempt);
            response["history_refresh_page_token"] = json!("MA==");
        }
        let accepted = if preparing { prepared } else { response["delivered"] == true };
        let pending = !preparing && prepared && response["reason"] == "cancellation_scope_activity_stop_not_acknowledged";
        let status = if (accepted && response["reason"].is_null()) || pending {
            StatusCode::OK
        } else if response["reason"] == "task_not_found" {
            StatusCode::NOT_FOUND
        } else {
            StatusCode::CONFLICT
        };
        Ok((status, enveloped(response)))
    }

    /// `PortableCancellationScopeDelivery::mutate`. A refusal before any
    /// effect is an error, which rolls back; once the preparation replayed,
    /// actor effects stay and a later refusal is answered with the receipts
    /// so far.
    #[allow(clippy::too_many_arguments)]
    async fn scope_delivery_engine(&self, tx: &mut Transaction<'_, DB>, task_id: &str, run_id: &str, owner: &str, attempt: i64,
        scope_id: &str, request_id: &str, boundary: &Boundary, preparing: bool) -> Result<Value>
    {
        let mut response = json!({"prepared": false, "delivered": false, "claim_released": false, "task_id": task_id,
            "created_task_ids": [], "reason": null});
        Self::scope_claim(tx, task_id, run_id, owner, attempt).await?;
        {
            let events = Self::scope_events(tx, run_id).await?;
            let scopes = scopes(&events, run_id)?;
            if !preparing && prepared(&events, &scopes, scope_id)?.is_none() {
                return Err(no("cancellation_scope_delivery_not_prepared"));
            }
        }
        let preparation = self.write_boundary(tx, task_id, run_id, owner, attempt, scope_id, request_id, boundary, true).await?;
        frame(&mut response, run_id, &preparation.payload, &preparation.id);
        if preparing {
            response["delivered"] = json!(false);
            response["history_event_id"] = json!(preparation.id);
            response["reason"] = Value::Null;
            return Ok(response);
        }
        let (_, facts) = Self::scope_run(tx, run_id).await?;
        let events = Self::scope_events(tx, run_id).await?;
        let scopes = scopes(&events, run_id)?;
        if let Err(error) = operation_addresses(&events, &scopes) {
            return Self::scope_partial(response, error);
        }
        let mut references = Vec::new();
        let mut built = || -> Result<()> {
            references.push(reference(&events, &scopes, &facts, scope_id, Some(&preparation.id))?
                .ok_or_else(|| no("cancellation_scope_preparation_reference_invalid"))?);
            for descendant in preparation.payload["descendant_members"].as_array().into_iter().flatten() {
                let descendant = descendant["scope_id"].as_str().unwrap_or_default();
                references.push(reference(&events, &scopes, &facts, descendant, Some(&preparation.id))?
                    .ok_or_else(|| no("cancellation_scope_descendant_not_prepared"))?);
            }
            Ok(())
        };
        if let Err(error) = built() {
            return Self::scope_partial(response, error);
        }
        // The whole subtree is checked before even the parent's first actor.
        for reference in &references {
            match pending(&events, &scopes, &facts, reference, None) {
                Ok(true) => {
                    response["reason"] = json!("cancellation_scope_descendant_delivery_pending");
                    return Ok(response);
                }
                Ok(false) => {}
                Err(error) => return Self::scope_partial(response, error),
            }
        }
        for field in ["activity_cancellations", "timer_cancellations", "wait_cancellations", "child_cancellations"] {
            response[field] = json!([]);
        }
        for reference in &references {
            let events = Self::scope_events(tx, run_id).await?;
            let earlier = match earlier(&events, &scopes, &facts, reference) {
                Ok(earlier) => earlier,
                Err(error) => return Self::scope_partial(response, error),
            };
            if let Some((original, delivery)) = completed(&events, &earlier, None) {
                match receipts(&events, &original, delivery) {
                    Ok(receipts) => {
                        Self::scope_extend(&mut response, "timer_cancellations", receipts.timers);
                        Self::scope_extend(&mut response, "activity_cancellations", receipts.activities);
                    }
                    Err(error) => return Self::scope_partial(response, error),
                }
                continue;
            }
            for member in &reference.timer_members {
                let timer_id = member["timer_id"].as_str().unwrap_or_default();
                match self.scoped_timer_fence(tx, task_id, run_id, owner, attempt, timer_id, reference).await {
                    Ok(receipt) => Self::scope_extend(&mut response, "timer_cancellations", vec![receipt]),
                    Err(error) => return Self::scope_partial(response, error),
                }
            }
            for member in &reference.activity_members {
                let execution = member["activity_execution_id"].as_str().unwrap_or_default();
                match self.scoped_activity_fence(tx, task_id, run_id, owner, attempt, execution, reference).await {
                    Ok(mut receipt) => {
                        receipt["sequence"] = member["sequence"].clone();
                        receipt["activity_execution_id"] = member["activity_execution_id"].clone();
                        Self::scope_extend(&mut response, "activity_cancellations", vec![receipt]);
                    }
                    Err(error) => return Self::scope_partial(response, error),
                }
            }
        }
        match self.write_boundary(tx, task_id, run_id, owner, attempt, scope_id, request_id, boundary, false).await {
            Ok(delivery) => {
                frame(&mut response, run_id, &delivery.payload, &preparation.id);
                response["delivered"] = json!(true);
                response["history_event_id"] = json!(delivery.id);
                response["reason"] = Value::Null;
                Ok(response)
            }
            Err(error) => Self::scope_partial(response, error),
        }
    }

    fn scope_extend(response: &mut Value, field: &str, receipts: Vec<Value>) {
        if let Some(list) = response[field].as_array_mut() {
            list.extend(receipts);
        }
    }

    /// A refusal after effects: the response so far, with its reason.
    fn scope_partial(mut response: Value, error: RuntimeError) -> Result<Value> {
        match error {
            RuntimeError::Refused { reason, .. } if delivery_reason(reason) => {
                response["reason"] = json!(reason);
                Ok(response)
            }
            error => Err(error),
        }
    }

    /// `CancellationScopeDelivery::writeBoundary`: records a preparation or
    /// a delivery marker, or returns the recorded one for the same boundary.
    #[allow(clippy::too_many_arguments)]
    async fn write_boundary(&self, tx: &mut Transaction<'_, DB>, task_id: &str, run_id: &str, owner: &str, attempt: i64, scope_id: &str,
        request_id: &str, boundary: &Boundary, preparing: bool) -> Result<Event>
    {
        if let Some(invalid) = boundary_syntax(boundary) {
            return Err(no(invalid));
        }
        Self::scope_claim(tx, task_id, run_id, owner, attempt).await?;
        let (_, facts) = Self::scope_run(tx, run_id).await?;
        let mut events = Self::scope_events(tx, run_id).await?;
        let mut scopes = scopes(&events, run_id)?;
        let context = context(&events, &scopes, run_id, scope_id)?.filter(|context| context_request(context) == Some(request_id))
            .ok_or_else(|| no("cancellation_scope_request_mismatch"))?;
        let mut deadline = authority(&facts, &events, &scopes, scope_id)?.usable()?;
        let existing = if preparing { prepared(&events, &scopes, scope_id)? } else { recorded(&events, &scopes, scope_id)? };
        if let Some(existing) = existing.cloned() {
            if !same_boundary(&existing.payload, boundary) {
                return Err(no("cancellation_scope_delivery_mismatch"));
            }
            if preparing && recorded(&events, &scopes, scope_id)?.is_none() {
                let request = accepted(&events, scope_id)?.ok_or_else(|| no("cancellation_scope_request_history_invalid"))?;
                let next = Self::authored_sequence(tx, run_id).await? + 1;
                if let Some(invalid) = call_boundary(&events, next, request, boundary) {
                    return Err(no(invalid));
                }
            }
            self.renew_hosting(tx, run_id, task_id, &existing.payload["authority_deadline_at"]).await?;
            return Ok(existing);
        }
        if preparing && position_reserved(&events, &scopes, boundary.sequence, None)? {
            return Err(no("cancellation_scope_command_sequence_reserved"));
        }
        let request = accepted(&events, scope_id)?.ok_or_else(|| no("cancellation_scope_request_history_invalid"))?;
        if find(&scopes, scope_id).is_some_and(|scope| scope.shield) && !request.payload["parent_scope_id"].is_null() {
            return Err(no("cancellation_scope_parent_shielded"));
        }
        let next = Self::authored_sequence(tx, run_id).await? + 1;
        if let Some(invalid) = call_boundary(&events, next, request, boundary) {
            return Err(no(invalid));
        }
        let operation_start = boundary.operation_sequence.unwrap_or(boundary.sequence);
        let operation_span = if boundary.operation_sequence.is_none() { boundary.span } else { boundary.operation_span };
        if !recorded_before(&scopes, scope_id, operation_start) || !matches_membership(&events, &scopes, scope_id, operation_start, operation_span) {
            return Err(no("cancellation_scope_delivery_membership_mismatch"));
        }
        let preparation = if preparing { None } else { prepared(&events, &scopes, scope_id)?.cloned() };
        if !preparing {
            match &preparation {
                None => return Err(no("cancellation_scope_delivery_not_prepared")),
                Some(preparation) if !same_boundary(&preparation.payload, boundary) => return Err(no("cancellation_scope_delivery_mismatch")),
                _ => {}
            }
        }
        let expired = |preparation: &Option<Event>| {
            preparation.as_ref().is_some_and(|preparation| instant(&preparation.payload["authority_deadline_at"]).is_none_or(|at| now() >= at))
        };
        if expired(&preparation) {
            return Err(no("cancellation_scope_authority_expired"));
        }
        if preparing {
            // Requests and preparation commit together.
            self.request_scope_descendants(tx, run_id, scope_id).await?;
            Self::scope_claim(tx, task_id, run_id, owner, attempt).await?;
            events = Self::scope_events(tx, run_id).await?;
            scopes = self::scopes(&events, run_id)?;
            deadline = authority(&facts, &events, &scopes, scope_id)?.usable()?;
        }
        let mut payload = json!({"schema": if preparing { PREPARATION_SCHEMA } else { DELIVERY_SCHEMA }, "workflow_run_id": run_id,
            "scope_id": scope_id, "request_id": context_request(&context), "cancellation": context, "sequence": boundary.sequence,
            "call_kind": boundary.kind, "sequence_span": boundary.span, "operation_sequence": boundary.operation_sequence,
            "operation_sequence_span": boundary.operation_span,
            "authority_deadline_at": preparation.as_ref().map_or_else(|| json!(deadline), |preparation| preparation.payload["authority_deadline_at"].clone())});
        if preparing {
            refuse_other_members(&events, scope_id)?;
            let authority_deadline = instant(&json!(deadline)).ok_or_else(|| no("cancellation_scope_authority_expired"))?;
            payload["activity_members"] = json!(activity_members(&events, scope_id)?);
            payload["timer_members"] = json!(timer_members(&events, scope_id)?);
            payload["wait_members"] = json!([]);
            payload["child_members"] = json!([]);
            payload["descendant_members"] = json!(descendant_members(&events, &scopes, &facts, scope_id, authority_deadline)?);
            if events.iter().any(|event| {
                matches!(event.kind.as_str(), "ActivityCancelled" | "TimerCancelled" | "SignalWaitCancelled" | "ConditionWaitCancelled")
                    && event.payload["cancellation_scope"]["scope_id"] == scope_id
            }) {
                return Err(no("cancellation_scope_preparation_after_effect"));
            }
        } else if let Some(preparation) = &preparation {
            self.assert_scope_ready(tx, &events, &scopes, &facts, &context, preparation).await?;
            payload["preparation_history_event_id"] = json!(preparation.id);
        }
        let claim = Self::scope_claim(tx, task_id, run_id, owner, attempt).await?;
        let current = authority(&facts, &events, &scopes, scope_id)?.usable()?;
        if expired(&preparation) {
            return Err(no("cancellation_scope_authority_expired"));
        }
        if current != deadline {
            return Err(no("cancellation_scope_authority_changed"));
        }
        payload["task"] = Self::task_snapshot(&claim)?;
        let kind = if preparing { "CancellationScopeDeliveryPrepared" } else { "CancellationScopeDelivered" };
        let event_id = Self::append_returning(tx, run_id, kind, payload.clone(), Some(task_id), None).await?;
        Self::scope_progress(tx, run_id).await?;
        self.renew_hosting(tx, run_id, task_id, &payload["authority_deadline_at"]).await?;
        let sequence = Self::scope_events(tx, run_id).await?.iter().find(|event| event.id == event_id).map_or(0, |event| event.sequence);
        Ok(Event { id: event_id, sequence, kind: kind.to_owned(), payload, command_id: None })
    }

    /// `renewHosting`: raises the run's scope recovery horizon to the
    /// boundary's authority and renews the claim's cleanup lease.
    async fn renew_hosting(&self, tx: &mut Transaction<'_, DB>, run_id: &str, task_id: &str, authority_deadline: &Value) -> Result<()> {
        let (run, _) = Self::scope_run(tx, run_id).await?;
        if let Some(captured) = instant(authority_deadline)
            && DB::optional_instant(&run, "cancellation_scope_recovery_until")?.is_none_or(|until| captured > until)
        {
            Self::query("UPDATE workflow_runs SET cancellation_scope_recovery_until=$1 WHERE id=$2")
                .bind(DB::bind_time(captured)).bind(run_id).execute(&mut **tx).await?;
        }
        let expires = self.cancellation_lease_expiry(&run)?;
        Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2").bind(DB::bind_time(expires)).bind(task_id).execute(&mut **tx).await?;
        tx.summary_changed(run_id);
        Ok(())
    }

    /// The delivery barrier (`writeBoundary` while recording): every frozen
    /// member and descendant has its fence.
    async fn assert_scope_ready(&self, tx: &mut Transaction<'_, DB>, events: &[Event], scopes: &[Scope], facts: &RunFacts, context: &Value,
        preparation: &Event) -> Result<()>
    {
        for member in preparation.payload["activity_members"].as_array().into_iter().flatten() {
            Self::activity_ready(tx, events, facts, context, member["sequence"].as_i64().unwrap_or_default()).await?;
        }
        let own = reference(events, scopes, facts, preparation.payload["scope_id"].as_str().unwrap_or_default(), Some(&preparation.id))?
            .ok_or_else(|| no("cancellation_scope_preparation_reference_invalid"))?;
        Self::timers_ready(tx, events, facts, &own).await?;
        for member in preparation.payload["descendant_members"].as_array().into_iter().flatten() {
            let scope_id = member["scope_id"].as_str().unwrap_or_default();
            let descendant = reference(events, scopes, facts, scope_id, Some(&preparation.id))?
                .ok_or_else(|| no("cancellation_scope_descendant_delivery_not_prepared"))?;
            if member["request_id"] != descendant.request_id.as_str()
                || member["cancellation"] != descendant.context
                || member["authority_deadline_at"] != descendant.authority_deadline_at.as_str()
                || member["activity_members"].as_array() != Some(&descendant.activity_members)
                || member["timer_members"].as_array() != Some(&descendant.timer_members)
            {
                return Err(no("cancellation_scope_descendant_delivery_membership_mismatch"));
            }
            let candidates = earlier(events, scopes, facts, &descendant)?;
            if completed(events, &candidates, None).is_some() {
                continue;
            }
            if !candidates.is_empty() {
                return Err(no("cancellation_scope_descendant_delivery_pending"));
            }
            for activity in &descendant.activity_members {
                Self::activity_ready(tx, events, facts, &descendant.context, activity["sequence"].as_i64().unwrap_or_default()).await?;
            }
            Self::timers_ready(tx, events, facts, &descendant).await?;
        }
        Ok(())
    }

    /// `ScopedActivityDeliveryPolicy::assertReady` for one sequence.
    async fn activity_ready(tx: &mut Transaction<'_, DB>, events: &[Event], facts: &RunFacts, context: &Value, sequence: i64) -> Result<()> {
        let invalid = || no("cancellation_scope_activity_history_invalid");
        for scheduled in events.iter().filter(|event| event.kind == "ActivityScheduled" && event.payload["sequence"] == sequence) {
            let execution = scheduled.payload["activity_execution_id"].as_str().filter(|id| !id.is_empty()).ok_or_else(invalid)?;
            let duplicates = events.iter().filter(|event| {
                event.kind == "ActivityScheduled" && (event.payload["activity_execution_id"] == execution || event.payload["sequence"] == sequence)
            }).count();
            if scheduled.payload["activity"]["id"] != execution || duplicates != 1 {
                return Err(invalid());
            }
            let policy = scheduled.payload["activity"].get("cancellation_policy").cloned().unwrap_or(json!("try_cancel"));
            let policy = policy.as_str().filter(|policy| matches!(*policy, "try_cancel" | "wait_cancellation_completed" | "abandon")).ok_or_else(invalid)?;
            if policy == "abandon" {
                return Err(no("cancellation_scope_activity_abandon_not_supported"));
            }
            let cancelled = events.iter().find(|event| event.kind == "ActivityCancelled" && event.payload["activity_execution_id"] == execution);
            if cancelled.is_none() && activity_resolved(events, facts, execution) {
                continue;
            }
            let Some(cancelled) = cancelled.filter(|cancelled| cancelled.payload["cancellation_scope"]["cancellation"] == *context) else {
                return Err(no("cancellation_scope_activity_fence_not_established"));
            };
            let row = Self::query("SELECT workflow_run_id,status,current_attempt_id FROM activity_executions WHERE id=$1")
                .bind(execution).fetch_optional(&mut **tx).await?;
            let established = match &row {
                Some(row) => DB::string(row, "workflow_run_id")? == facts.id
                    && DB::string(row, "status")? == "cancelled"
                    && DB::optional_string(row, "current_attempt_id")?.as_deref() == cancelled.payload["activity_attempt_id"].as_str(),
                None => false,
            };
            if !established {
                return Err(no("cancellation_scope_activity_fence_not_established"));
            }
            if policy == "wait_cancellation_completed" && !activity_resolved(events, facts, execution) {
                return Err(no("cancellation_scope_activity_stop_not_acknowledged"));
            }
        }
        Ok(())
    }

    /// `ScopedTimerCancellation::assertReady` for a reference's timers.
    async fn timers_ready(tx: &mut Transaction<'_, DB>, events: &[Event], facts: &RunFacts, reference: &Reference) -> Result<()> {
        for member in &reference.timer_members {
            let timer_id = member["timer_id"].as_str().unwrap_or_default();
            let sequence = member["sequence"].as_i64().unwrap_or_default();
            let terminal = timer_terminal(events, timer_id, sequence)?.ok_or_else(|| no("cancellation_scope_timer_fence_not_established"))?;
            if terminal.kind == "TimerFired" {
                continue;
            }
            timer_receipt(events, facts, reference, terminal)?;
            let timer = Self::query("SELECT workflow_run_id,sequence,status FROM workflow_run_timers WHERE id=$1")
                .bind(timer_id).fetch_optional(&mut **tx).await?;
            let tasks = Self::timer_tasks(tx, &facts.id, timer_id).await?;
            let established = match (&timer, tasks.as_slice()) {
                (Some(timer), [task]) => DB::string(timer, "workflow_run_id")? == facts.id
                    && DB::number(timer, "sequence")? == sequence
                    && DB::string(timer, "status")? == "cancelled"
                    && DB::string(task, "status")? == "cancelled"
                    && DB::optional_instant(task, "lease_expires_at")?.is_none(),
                _ => false,
            };
            if !established {
                return Err(no("cancellation_scope_timer_fence_not_established"));
            }
        }
        Ok(())
    }

    /// The timer tasks of a run's timer.
    async fn timer_tasks(tx: &mut Transaction<'_, DB>, run_id: &str, timer_id: &str) -> Result<Vec<DB::Row>> {
        let mut tasks = Vec::new();
        for task in Self::query("SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='timer'")
            .bind(run_id).fetch_all(&mut **tx).await?
        {
            if DB::document_row(&task, "payload")?["timer_id"] == timer_id {
                tasks.push(task);
            }
        }
        Ok(tasks)
    }

    /// `ScopedTimerCancellation::fence`.
    #[allow(clippy::too_many_arguments)]
    async fn scoped_timer_fence(&self, tx: &mut Transaction<'_, DB>, task_id: &str, run_id: &str, owner: &str, attempt: i64, timer_id: &str,
        reference: &Reference) -> Result<Value>
    {
        let tasks = Self::timer_tasks(tx, run_id, timer_id).await?;
        let [timer_task] = tasks.as_slice() else {
            return Err(no("cancellation_scope_timer_task_mismatch"));
        };
        let claim = Self::scope_claim(tx, task_id, run_id, owner, attempt).await?;
        let (_, facts) = Self::scope_run(tx, run_id).await?;
        let events = Self::scope_events(tx, run_id).await?;
        let scopes = scopes(&events, run_id)?;
        let context = context(&events, &scopes, run_id, &reference.scope_id)?
            .filter(|context| context_request(context) == Some(reference.request_id.as_str()))
            .ok_or_else(|| no("cancellation_scope_request_mismatch"))?;
        let current = authority(&facts, &events, &scopes, &reference.scope_id)?;
        let preparation = self::reference(&events, &scopes, &facts, &reference.scope_id, Some(&reference.event_id))?
            .ok_or_else(|| no("cancellation_scope_delivery_not_prepared"))?;
        if pending(&events, &scopes, &facts, &preparation, None)? {
            return Err(no("cancellation_scope_descendant_delivery_pending"));
        }
        current.usable()?;
        if instant(&json!(preparation.authority_deadline_at)).is_none_or(|at| now() >= at) {
            return Err(no("cancellation_scope_authority_expired"));
        }
        let member = preparation.timer_members.iter().find(|member| member["timer_id"] == timer_id)
            .ok_or_else(|| no("cancellation_scope_timer_not_prepared"))?;
        let sequence = member["sequence"].as_i64().unwrap_or_default();
        let terminal = timer_terminal(&events, timer_id, sequence)?.cloned();
        let timer = Self::query("SELECT * FROM workflow_run_timers WHERE id=$1").bind(timer_id).fetch_optional(&mut **tx).await?;
        let timer = match timer {
            Some(timer) if DB::string(&timer, "workflow_run_id")? == run_id && DB::number(&timer, "sequence")? == sequence => timer,
            _ => return Err(no("cancellation_scope_timer_projection_mismatch")),
        };
        let receipt = |fenced: bool, event: Option<&str>| json!({"fenced": fenced, "history_event_id": event, "timer_id": timer_id, "sequence": sequence});
        let event_id = match &terminal {
            Some(terminal) if terminal.kind == "TimerFired" => return Ok(receipt(false, None)),
            Some(terminal) => {
                timer_receipt(&events, &facts, &preparation, terminal)?;
                terminal.id.clone()
            }
            None => {
                if DB::string(&timer, "status")? != "pending" || !matches!(DB::string(timer_task, "status")?.as_str(), "ready" | "leased") {
                    return Err(no("cancellation_scope_timer_terminal_history_not_recorded"));
                }
                let request = accepted(&events, &reference.scope_id)?.ok_or_else(|| no("cancellation_scope_request_history_invalid"))?;
                let at = now();
                let mut payload = Self::timer_cancellation_payload(tx, run_id, &timer, at).await?;
                payload["cancelled_at"] = json!(iso(at));
                payload["cancellation_scope"] = json!({"schema": TIMER_SCOPE_SCHEMA, "workflow_run_id": run_id,
                    "scope_id": reference.scope_id, "request_id": reference.request_id, "request_history_event_id": request.id,
                    "preparation_history_event_id": preparation.event_id, "cancellation": context,
                    "authority_deadline_at": preparation.authority_deadline_at});
                payload["task"] = Self::task_snapshot(&claim)?;
                let event_id = Self::append_returning(tx, run_id, "TimerCancelled", payload, Some(task_id), None).await?;
                Self::scope_progress(tx, run_id).await?;
                event_id
            }
        };
        Self::query("UPDATE workflow_run_timers SET status='cancelled',fired_at=NULL WHERE id=$1").bind(timer_id).execute(&mut **tx).await?;
        Self::query("UPDATE workflow_tasks SET status='cancelled',lease_owner=NULL,lease_expires_at=NULL WHERE id=$1")
            .bind(DB::string(timer_task, "id")?).execute(&mut **tx).await?;
        tx.summary_changed(run_id);
        Ok(receipt(true, Some(&event_id)))
    }

    /// `ScopedActivityCancellation::fence`.
    #[allow(clippy::too_many_arguments)]
    async fn scoped_activity_fence(&self, tx: &mut Transaction<'_, DB>, task_id: &str, run_id: &str, owner: &str, attempt: i64,
        execution_id: &str, reference: &Reference) -> Result<Value>
    {
        let execution = Self::query("SELECT * FROM activity_executions WHERE id=$1").bind(execution_id).fetch_optional(&mut **tx).await?
            .filter(|execution| DB::string(execution, "workflow_run_id").is_ok_and(|run| run == run_id))
            .ok_or_else(|| no("cancellation_scope_activity_not_found"))?;
        let status = DB::string(&execution, "status")?;
        let current_attempt = DB::optional_string(&execution, "current_attempt_id")?;
        let attempt_row = match &current_attempt {
            Some(attempt_id) => Self::query("SELECT * FROM activity_attempts WHERE id=$1").bind(attempt_id).fetch_optional(&mut **tx).await?,
            None => None,
        };
        let attempt_changed = match (&current_attempt, &attempt_row) {
            (Some(_), None) => true,
            (_, Some(row)) => DB::string(row, "workflow_run_id")? != run_id || DB::string(row, "activity_execution_id")? != execution_id,
            (None, None) => status == "running",
        };
        if attempt_changed {
            return Err(no("cancellation_scope_activity_attempt_changed"));
        }
        Self::scope_claim(tx, task_id, run_id, owner, attempt).await?;
        let (_, facts) = Self::scope_run(tx, run_id).await?;
        let events = Self::scope_events(tx, run_id).await?;
        let scopes = scopes(&events, run_id)?;
        let scope_id = reference.scope_id.as_str();
        let context = context(&events, &scopes, run_id, scope_id)?
            .filter(|context| context_request(context) == Some(reference.request_id.as_str()))
            .ok_or_else(|| no("cancellation_scope_request_mismatch"))?;
        authority(&facts, &events, &scopes, scope_id)?.usable()?;
        let request = accepted(&events, scope_id)?.ok_or_else(|| no("cancellation_scope_request_history_invalid"))?;
        if find(&scopes, scope_id).is_some_and(|scope| scope.shield) && !request.payload["parent_scope_id"].is_null() {
            return Err(no("cancellation_scope_parent_shielded"));
        }
        let options = DB::optional_document_row(&execution, "activity_options")?.unwrap_or(Value::Null);
        if options["cancellation_scope_id"].as_str().unwrap_or(ROOT) != scope_id
            || !recorded_before(&scopes, scope_id, DB::number(&execution, "sequence")?)
        {
            return Err(no("cancellation_scope_activity_membership_mismatch"));
        }
        let preparation = self::reference(&events, &scopes, &facts, scope_id, Some(&reference.event_id))?;
        if let Some(preparation) = &preparation
            && pending(&events, &scopes, &facts, preparation, None)?
        {
            return Err(no("cancellation_scope_descendant_delivery_pending"));
        }
        let scheduled = events.iter().find(|event| event.kind == "ActivityScheduled" && event.payload["activity_execution_id"] == execution_id);
        let policy = scheduled.and_then(|event| event.payload["activity"]["cancellation_policy"].as_str()).unwrap_or("try_cancel").to_owned();
        let unfenced = json!({"fenced": false, "abandoned": false, "waiting_for_stop": false, "history_event_id": null});
        if policy == "abandon" {
            return Err(no("cancellation_scope_activity_abandon_not_supported"));
        }
        if matches!(status.as_str(), "completed" | "failed") {
            if !activity_resolved(&events, &facts, execution_id) {
                return Err(no("cancellation_scope_activity_terminal_history_not_recorded"));
            }
            return Ok(unfenced);
        }
        let preparation = preparation.ok_or_else(|| no("cancellation_scope_delivery_not_prepared"))?;
        if instant(&json!(preparation.authority_deadline_at)).is_none_or(|at| now() >= at) {
            return Err(no("cancellation_scope_authority_expired"));
        }
        let sequence = DB::number(&execution, "sequence")?;
        if !preparation.activity_members.iter().any(|member| member["activity_execution_id"] == execution_id && member["sequence"] == sequence) {
            return Err(no("cancellation_scope_activity_not_prepared"));
        }
        let metadata = json!({"schema": ACTIVITY_SCOPE_SCHEMA, "workflow_run_id": run_id, "scope_id": scope_id,
            "request_id": reference.request_id, "request_history_event_id": request.id, "cancellation": context,
            "authority_deadline_at": preparation.authority_deadline_at});
        let existing = events.iter().find(|event| event.kind == "ActivityCancelled" && event.payload["activity_execution_id"] == execution_id).cloned();
        let event = match existing {
            Some(existing) => {
                let original = existing.payload["cancellation_scope"]["request_id"].as_str()
                    .or_else(|| existing.payload.get("cancellation_scope").is_none().then_some(existing.command_id.as_deref()).flatten());
                if original != Some(reference.request_id.as_str()) {
                    return Err(no("activity_cancellation_owned_by_another_request"));
                }
                existing
            }
            None if matches!(status.as_str(), "pending" | "running") => {
                self.record_scoped_activity_cancellation(tx, run_id, &execution, attempt_row.as_ref(), &reference.request_id, metadata).await?
            }
            None => return Err(no("cancellation_scope_activity_terminal_history_not_recorded")),
        };
        let mut receipt = fenced_activity(&event, &context);
        if policy == "wait_cancellation_completed" {
            let events = Self::scope_events(tx, run_id).await?;
            receipt["waiting_for_stop"] = json!(!activity_resolved(&events, &facts, execution_id));
        }
        Ok(receipt)
    }

    /// `ActivityCancellation::record` with a scope snapshot: the execution,
    /// its live attempt and that attempt's task are cancelled, recorded on
    /// the activity task under the scope's request.
    async fn record_scoped_activity_cancellation(&self, tx: &mut Transaction<'_, DB>, run_id: &str, execution: &DB::Row,
        attempt: Option<&DB::Row>, request_id: &str, metadata: Value) -> Result<Event>
    {
        let at = now();
        let execution_id = DB::string(execution, "id")?;
        let running = DB::string(execution, "status")? == "running";
        let activity_task = if let (true, Some(attempt)) = (running, attempt) {
            DB::string(attempt, "workflow_task_id")?
        } else {
            let mut open = Vec::new();
            for task in Self::query("SELECT id,status,payload FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='activity' AND status IN ('ready','leased')")
                .bind(run_id).fetch_all(&mut **tx).await?
            {
                if DB::document_row(&task, "payload")?["activity_execution_id"] == execution_id.as_str() {
                    open.push(DB::string(&task, "id")?);
                }
            }
            let [task] = open.as_slice() else {
                return Err(no("cancellation_scope_activity_fence_not_recorded"));
            };
            task.clone()
        };
        Self::query("UPDATE activity_executions SET status='cancelled',closed_at=COALESCE(closed_at,$1) WHERE id=$2")
            .bind(DB::bind_time(at)).bind(&execution_id).execute(&mut **tx).await?;
        let mut attempt_snapshot = Value::Null;
        if let Some(attempt) = attempt {
            let attempt_id = DB::string(attempt, "id")?;
            if !matches!(DB::string(attempt, "status")?.as_str(), "cancelled" | "completed" | "failed") {
                Self::query("UPDATE activity_attempts SET status='cancelled',lease_expires_at=NULL,closed_at=COALESCE(closed_at,$1) WHERE id=$2")
                    .bind(DB::bind_time(at)).bind(&attempt_id).execute(&mut **tx).await?;
            }
            let attempt = Self::query("SELECT * FROM activity_attempts WHERE id=$1").bind(&attempt_id).fetch_one(&mut **tx).await?;
            attempt_snapshot = json!({"id": attempt_id, "activity_execution_id": execution_id, "task_id": DB::string(&attempt, "workflow_task_id")?,
                "attempt_number": DB::number(&attempt, "attempt_number")?, "status": DB::string(&attempt, "status")?,
                "lease_owner": DB::optional_string(&attempt, "lease_owner")?,
                "started_at": DB::optional_instant(&attempt, "started_at")?.map(iso), "closed_at": DB::optional_instant(&attempt, "closed_at")?.map(iso)});
        }
        Self::query("UPDATE workflow_tasks SET status='cancelled',lease_expires_at=NULL,last_error=NULL WHERE id=$1")
            .bind(&activity_task).execute(&mut **tx).await?;
        let cancelled = Self::query("SELECT * FROM activity_executions WHERE id=$1").bind(&execution_id).fetch_one(&mut **tx).await?;
        let mut payload = Self::activity_payload(&cancelled)?;
        payload["workflow_command_id"] = json!(request_id);
        payload["activity_attempt_id"] = attempt.map(|attempt| DB::string(attempt, "id")).transpose()?.map_or(Value::Null, Value::from);
        payload["attempt_number"] = json!(DB::number(&cancelled, "attempt_count")?);
        payload["cancelled_at"] = json!(DB::optional_instant(&cancelled, "closed_at")?.map_or_else(|| iso(at), iso));
        payload["activity_attempt"] = attempt_snapshot;
        payload["cancellation_scope"] = metadata;
        let task = Self::query("SELECT * FROM workflow_tasks WHERE id=$1").bind(&activity_task).fetch_one(&mut **tx).await?;
        payload["task"] = Self::task_snapshot(&task)?;
        let event_id = Self::append_returning(tx, run_id, "ActivityCancelled", payload.clone(), Some(&activity_task), Some(request_id)).await?;
        Self::scope_progress(tx, run_id).await?;
        tx.summary_changed(run_id);
        Ok(Event { id: event_id, sequence: 0, kind: "ActivityCancelled".into(), payload, command_id: Some(request_id.to_owned()) })
    }

    /// `CancellationScopeDescendants::request`: records a request for every
    /// unshielded descendant not yet requested, with its parent's context.
    pub(super) async fn request_scope_descendants(&self, tx: &mut Transaction<'_, DB>, run_id: &str, scope_id: &str) -> Result<()> {
        let events = Self::scope_events(tx, run_id).await?;
        let scopes = scopes(&events, run_id)?;
        for scope in descendants(&scopes, scope_id)? {
            self.request_scope(tx, run_id, &scope.id, &scope.parent).await?;
        }
        Ok(())
    }

    /// `CancellationScopeRequests::request` with a parent: the scope's
    /// accepted request, bounding live claims to the cleanup lease and
    /// leaving a workflow task when the run has none open.
    async fn request_scope(&self, tx: &mut Transaction<'_, DB>, run_id: &str, scope_id: &str, parent_scope_id: &str) -> Result<()> {
        let open = Self::query("SELECT id,status,lease_expires_at FROM workflow_tasks WHERE workflow_run_id=$1 AND task_type='workflow' AND status IN ('ready','leased') ORDER BY id")
            .bind(run_id).fetch_all(&mut **tx).await?;
        let (run, facts) = Self::scope_run(tx, run_id).await?;
        let events = Self::scope_events(tx, run_id).await?;
        let scopes = scopes(&events, run_id)?;
        assert_scope(&scopes, scope_id)?;
        if find(&scopes, scope_id).is_none_or(|scope| scope.parent != parent_scope_id) {
            return Err(no("cancellation_scope_parent_mismatch"));
        }
        let parent = if parent_scope_id == ROOT {
            run_context(&events, &facts).map(scoped_from_run)
        } else {
            context(&events, &scopes, run_id, parent_scope_id)?
        }
        .ok_or_else(|| no("cancellation_scope_parent_request_unavailable"))?;
        if let Some(existing) = accepted(&events, scope_id)? {
            if existing.payload["cancellation"]["root_context"]["root_request_id"] == parent["root_context"]["root_request_id"] {
                return Ok(());
            }
            // A run takes one request, so a competing root cannot arrive.
            return Err(no("cancellation_scope_request_conflict_unavailable"));
        }
        let request_id = id();
        let incoming = for_descendant(&parent, &request_id, &facts, scope_id);
        let current = authority(&facts, &events, &scopes, scope_id)?;
        let incoming_deadline = context_deadline(&incoming);
        if !current.active || incoming_deadline.is_none_or(|deadline| now() >= deadline) {
            return Err(no("cancellation_scope_authority_expired"));
        }
        let payload = json!({"schema": REQUEST_SCHEMA, "workflow_run_id": run_id, "scope_id": scope_id, "parent_scope_id": parent_scope_id,
            "request_id": request_id, "cancellation": incoming});
        let event_id = Self::append_returning(tx, run_id, "CancellationScopeRequested", payload, None, None).await?;
        Self::scope_progress(tx, run_id).await?;
        let ceiling = [incoming_deadline, current.deadline].into_iter().flatten().min();
        if let Some(ceiling) = ceiling
            && DB::optional_instant(&run, "cancellation_scope_recovery_until")?.is_none_or(|until| ceiling > until)
        {
            Self::query("UPDATE workflow_runs SET cancellation_scope_recovery_until=$1 WHERE id=$2")
                .bind(DB::bind_time(ceiling)).bind(run_id).execute(&mut **tx).await?;
        }
        let (run, _) = Self::scope_run(tx, run_id).await?;
        let expiry = self.cancellation_lease_expiry(&run)?;
        for task in &open {
            if DB::string(task, "status")? == "leased"
                && DB::optional_instant(task, "lease_expires_at")?.is_some_and(|lease| now() < lease && expiry < lease)
            {
                Self::query("UPDATE workflow_tasks SET lease_expires_at=$1 WHERE id=$2").bind(DB::bind_time(expiry)).bind(DB::string(task, "id")?)
                    .execute(&mut **tx).await?;
            }
        }
        if open.is_empty() {
            Self::create_task(tx, run_id, &DB::string(&run, "queue")?, "workflow", json!({"resume_source_kind": "cancellation_scope_request",
                "resume_source_id": event_id, "workflow_command_id": request_id, "scope_id": scope_id})).await?;
        }
        tx.summary_changed(run_id);
        Ok(())
    }

    /// `guardCancellationScopeAdmission`: a completion naming a scope needs
    /// protocol 1.20, a member command type and recorded, unprepared scopes.
    pub(super) async fn cancellation_scope_admission(tx: &mut Transaction<'_, DB>, task: &DB::Row, body: &Value, commands: &[Value],
        protocol: Option<&str>) -> Result<()>
    {
        if !commands.iter().any(|command| command.get("cancellation_scope_id").is_some()) {
            return Ok(());
        }
        let (mut reason, unavailable) = guard_reason(commands, protocol.is_some_and(protocol_supported));
        if reason.is_none() {
            let run_id = DB::string(task, "workflow_run_id")?;
            let events = Self::scope_events(tx, &run_id).await?;
            let scopes = scopes(&events, &run_id)?;
            let next = Self::authored_sequence(tx, &run_id).await? + 1;
            reason = membership_refusal(&events, &scopes, &commands.iter().collect::<Vec<_>>(), next)?;
        }
        let Some(reason) = reason else {
            return Ok(());
        };
        Err(RuntimeError::Protocol {
            status: StatusCode::CONFLICT,
            response: enveloped(json!({"task_id": DB::string(task, "id")?, "workflow_task_attempt": body["workflow_task_attempt"],
                "outcome": "rejected", "recorded": false, "reason": reason, "unavailable": unavailable, "requested_version": protocol,
                "minimum_protocol_version": "1.20"})),
        })
    }

    /// `validateCancellationScopeMembership` inside the bridge's completion,
    /// over the batch's non-terminal commands.
    pub(super) async fn cancellation_scope_completion_refusal(tx: &mut Transaction<'_, DB>, run_id: &str, commands: &[&Value])
        -> Result<Option<&'static str>>
    {
        let events = Self::scope_events(tx, run_id).await?;
        let scopes = scopes(&events, run_id)?;
        let next = Self::authored_sequence(tx, run_id).await? + 1;
        membership_refusal(&events, &scopes, commands, next)
    }
}

/// `ActivityCancellationCompletion::resolved`: the started attempt closed
/// on its own before any fence, the fence won before any attempt started,
/// or the original owner acknowledged the stop.
fn activity_resolved(events: &[Event], facts: &RunFacts, execution_id: &str) -> bool {
    let Some(scheduled) = events.iter().find(|event| event.kind == "ActivityScheduled" && event.payload["activity_execution_id"] == execution_id) else {
        return false;
    };
    let Some(sequence) = scheduled.payload["sequence"].as_i64().filter(|sequence| *sequence >= 1) else {
        return false;
    };
    let cancelled = events.iter().find(|event| event.kind == "ActivityCancelled" && event.payload["activity_execution_id"] == execution_id);
    let started = events.iter().filter(|event| event.kind == "ActivityStarted" && event.payload["activity_execution_id"] == execution_id).last();
    if let Some(started) = started
        && let Some(attempt) = started.payload["activity_attempt_id"].as_str()
        && events.iter().any(|event| {
            let closed = matches!(event.kind.as_str(), "ActivityCompleted" | "ActivityFailed")
                || event.kind == "ActivityRetryScheduled"
                    && event.payload["activity_attempt"]["id"] == attempt
                    && event.payload["activity_attempt"]["activity_execution_id"] == execution_id
                    && event.payload["activity_attempt"]["status"] == "failed"
                    && event.payload["activity_attempt"]["closed_at"].is_string()
                    && event.payload["timeout_kind"].is_null();
            closed
                && event.payload["activity_execution_id"] == execution_id
                && event.payload["activity_attempt_id"] == attempt
                && event.payload["sequence"] == sequence
                && event.sequence > started.sequence
                && cancelled.is_none_or(|cancelled| event.sequence < cancelled.sequence)
        })
    {
        return true;
    }
    let Some(cancelled) = cancelled else {
        return false;
    };
    if cancelled.sequence <= scheduled.sequence || cancelled.payload["sequence"] != sequence
        || cancelled.payload.get("activity_attempt_id").is_none() || cancelled.payload.get("activity_attempt").is_none()
    {
        return false;
    }
    if cancelled.payload["activity_attempt_id"].is_null() {
        return cancelled.payload["activity_attempt"].is_null()
            && !events.iter().any(|event| event.kind == "ActivityStarted" && event.payload["activity_execution_id"] == execution_id);
    }
    stop_receipt(events, facts, cancelled).is_some()
}

/// `ActivityCancellationCompletion::stopReceipt`.
fn stop_receipt<'a>(events: &'a [Event], facts: &RunFacts, cancelled: &Event) -> Option<&'a Event> {
    let (request, root, deadline) = cancellation_facts(events, facts, cancelled)?;
    let snapshot = &cancelled.payload["activity_attempt"];
    let attempt = cancelled.payload["activity_attempt_id"].as_str().filter(|attempt| !attempt.is_empty())?;
    let execution = cancelled.payload["activity_execution_id"].as_str().filter(|execution| !execution.is_empty())?;
    let owner = snapshot["lease_owner"].as_str().filter(|owner| !owner.is_empty())?;
    if cancelled.command_id.as_deref() != Some(request.as_str()) || snapshot["id"] != attempt || snapshot["activity_execution_id"] != execution
        || snapshot["status"] != "cancelled"
    {
        return None;
    }
    let source = if cancelled.payload["local_activity"] == true { "workflow_worker" } else { "activity_worker" };
    events.iter().find(|event| {
        event.kind == "ActivityCancellationAcknowledged"
            && event.sequence > cancelled.sequence
            && event.command_id.as_deref() == Some(request.as_str())
            && event.payload["sequence"] == cancelled.payload["sequence"]
            && event.payload["activity_execution_id"] == execution
            && event.payload["activity_attempt_id"] == attempt
            && event.payload["lease_owner"] == owner
            && event.payload["cancellation_history_event_id"] == cancelled.id.as_str()
            && event.payload["request_id"] == request.as_str()
            && event.payload["root_request_id"] == root.as_str()
            && event.payload["cleanup_deadline_at"] == deadline.as_str()
            && event.payload["callback_state"] == "stopped"
            && event.payload["evidence_source"] == source
    })
}

/// An activity fence's request, root request and cleanup deadline: the
/// scope's accepted context for a scoped fence, else the run request's.
pub(super) fn cancellation_facts(events: &[Event], facts: &RunFacts, cancelled: &Event) -> Option<(String, String, String)> {
    if let Some(snapshot) = cancelled.payload.get("cancellation_scope") {
        let context = &snapshot["cancellation"];
        let request = context_request(context)?;
        if snapshot["request_id"] != request {
            return None;
        }
        let deadline = context_deadline(context).map(iso)?;
        return Some((request.to_owned(), context["root_context"]["root_request_id"].as_str()?.to_owned(), deadline));
    }
    let context = run_context(events, facts)?;
    let request = context["request_id"].as_str()?;
    if cancelled.command_id.as_deref() != Some(request) {
        return None;
    }
    let root = context["lineage"][0]["request_id"].as_str().unwrap_or(request);
    Some((request.to_owned(), root.to_owned(), iso(instant(&context["cleanup_deadline_at"])?)))
}

/// `assertOperationAddresses`: every scheduled operation names root or a
/// recorded scope.
fn operation_addresses(events: &[Event], scopes: &[Scope]) -> Result<()> {
    for event in events.iter().filter(|event| OPERATION_EVENTS.contains(&event.kind.as_str())) {
        match membership(event) {
            Some(address) if address == ROOT || find(scopes, &address).is_some() => {}
            _ => return Err(no("cancellation_scope_delivery_history_invalid")),
        }
    }
    Ok(())
}
