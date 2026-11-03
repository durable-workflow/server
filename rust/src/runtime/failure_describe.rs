//! The terminal failure published PHP Server's `WorkflowController::formatRun`
//! adds to a failed run's describe (Server #967): `error`, `failure`,
//! `exception` and `failures`, built as Workflow Core 2.15's
//! `FailureSnapshots::forRun` builds them from the run's failure rows and
//! typed history, then compacted as the controller compacts them. A run
//! deadline or execution timeout is a failed run too, so its `failure` names
//! the timeout kind as its `reason`.
use super::Result;
use super::transaction::Transaction;
use serde_json::{Map, Value, json};

/// The history events `FailureSnapshots` reads a typed failure from.
const FAILURE_EVENTS: [&str; 10] = [
    "ActivityFailed",
    "ActivityTimedOut",
    "ChildRunFailed",
    "ChildRunCancelled",
    "ChildRunTerminated",
    "WorkflowFailed",
    "WorkflowTimedOut",
    "WorkflowCancelled",
    "WorkflowTerminated",
    "UpdateCompleted",
];

/// `nonEmptyString`: a trimmed, non-empty string, or nothing.
fn text(value: &Value) -> Option<String> {
    value.as_str().map(str::trim).filter(|text| !text.is_empty()).map(str::to_owned)
}

/// `withoutNullOrEmptyArrays`: drops nulls and empty lists, keeping order.
fn compact(fields: Vec<(&str, Value)>) -> Value {
    let mut map = Map::new();
    for (key, value) in fields {
        let empty = value.is_null() || value.as_array().is_some_and(Vec::is_empty);
        if !empty {
            map.insert(key.to_owned(), value);
        }
    }
    Value::Object(map)
}

fn optional(value: Option<String>) -> Value {
    value.map_or(Value::Null, Value::String)
}

fn source_kind_for(event_type: &str) -> Option<&'static str> {
    match event_type {
        "ActivityFailed" | "ActivityTimedOut" => Some("activity_execution"),
        "ChildRunFailed" | "ChildRunCancelled" | "ChildRunTerminated" => Some("child_workflow_run"),
        "WorkflowFailed" | "WorkflowTimedOut" | "WorkflowCancelled" | "WorkflowTerminated" => Some("workflow_run"),
        "UpdateCompleted" => Some("workflow_command"),
        _ => None,
    }
}

fn propagation_kind_for(event_type: &str, payload: &Value) -> Option<&'static str> {
    match event_type {
        "ActivityFailed" => Some("activity"),
        "ActivityTimedOut" | "WorkflowTimedOut" => Some("timeout"),
        "ChildRunFailed" => Some("child"),
        "ChildRunCancelled" | "WorkflowCancelled" => Some("cancelled"),
        "ChildRunTerminated" | "WorkflowTerminated" => Some("terminated"),
        "WorkflowFailed" => Some("terminal"),
        "UpdateCompleted" => text(&payload["failure_id"]).map(|_| "update"),
        _ => None,
    }
}

fn failure_category_for(event_type: &str, payload: &Value) -> Option<&'static str> {
    match event_type {
        "ActivityFailed" => Some("activity"),
        "ActivityTimedOut" | "WorkflowTimedOut" => Some("timeout"),
        "ChildRunFailed" => Some("child_workflow"),
        "ChildRunCancelled" | "WorkflowCancelled" => Some("cancelled"),
        "ChildRunTerminated" | "WorkflowTerminated" => Some("terminated"),
        "WorkflowFailed" => Some("application"),
        "UpdateCompleted" => text(&payload["failure_id"]).map(|_| "application"),
        _ => None,
    }
}

/// One snapshot, holding the fields the controller's compaction reads.
#[derive(Clone, Debug, Default, PartialEq)]
struct Snapshot {
    id: Option<String>,
    source_kind: Option<String>,
    source_id: Option<String>,
    propagation_kind: Option<String>,
    reason: Option<String>,
    failure_category: Option<String>,
    non_retryable: bool,
    handled: bool,
    exception_type: Option<String>,
    exception_class: Option<String>,
    message: Option<String>,
    payload_type: Option<String>,
    payload_class: Option<String>,
    payload_message: Option<String>,
    event_sequence: Option<i64>,
    history_authority: &'static str,
    created_millis: i64,
}

/// A `workflow_failures` row as the snapshot reads it.
#[derive(Clone, Debug, Default)]
pub(super) struct FailureRow {
    pub id: String,
    pub source_kind: Option<String>,
    pub source_id: Option<String>,
    pub propagation_kind: Option<String>,
    pub failure_category: Option<String>,
    pub non_retryable: bool,
    pub handled: bool,
    pub exception_class: Option<String>,
    pub message: Option<String>,
    pub created_millis: i64,
}

/// A history event as the snapshot reads it.
pub(super) struct FailureEvent {
    pub sequence: i64,
    pub event_type: String,
    pub run_id: String,
    pub command_id: Option<String>,
    pub payload: Value,
    pub recorded_millis: i64,
}

/// `snapshotFromFailure`.
fn from_row(row: &FailureRow) -> Snapshot {
    Snapshot {
        id: Some(row.id.clone()),
        source_kind: row.source_kind.clone(),
        source_id: row.source_id.clone(),
        propagation_kind: row.propagation_kind.clone(),
        reason: None,
        failure_category: row.failure_category.clone(),
        non_retryable: row.non_retryable,
        handled: row.handled,
        exception_type: None,
        exception_class: row.exception_class.clone(),
        message: row.message.clone(),
        payload_type: None,
        payload_class: row.exception_class.clone(),
        payload_message: row.message.clone(),
        event_sequence: None,
        history_authority: "failure_row_fallback",
        created_millis: row.created_millis,
    }
}

/// `snapshotFromEvent`, with `exceptionPayload`'s class, type and message.
fn from_event(event: &FailureEvent, row: Option<&FailureRow>) -> Option<Snapshot> {
    let payload = &event.payload;
    let failure_id = text(&payload["failure_id"])?;
    let exception = if payload["exception"].is_object() { payload["exception"].clone() } else { Value::Null };
    let fallback_class = text(&payload["exception_class"]).or_else(|| row.and_then(|row| row.exception_class.clone()));
    let fallback_message = text(&payload["message"]).or_else(|| row.and_then(|row| row.message.clone()));
    let payload_class = text(&exception["class"]).or(fallback_class);
    let payload_type = text(&exception["type"]);
    let payload_message = text(&exception["message"]).or(fallback_message);
    let source_id_default = match event.event_type.as_str() {
        "ActivityFailed" | "ActivityTimedOut" => text(&payload["activity_execution_id"]),
        "ChildRunFailed" | "ChildRunCancelled" | "ChildRunTerminated" => text(&payload["child_workflow_run_id"]),
        "WorkflowFailed" | "WorkflowTimedOut" | "WorkflowCancelled" | "WorkflowTerminated" => Some(event.run_id.clone()),
        "UpdateCompleted" => event.command_id.clone().or_else(|| text(&payload["update_id"])),
        _ => None,
    };
    Some(Snapshot {
        id: Some(row.map_or(failure_id, |row| row.id.clone())),
        source_kind: text(&payload["source_kind"])
            .or_else(|| row.and_then(|row| row.source_kind.clone()))
            .or_else(|| source_kind_for(&event.event_type).map(str::to_owned)),
        source_id: text(&payload["source_id"])
            .or_else(|| row.and_then(|row| row.source_id.clone()))
            .or(source_id_default),
        propagation_kind: row
            .and_then(|row| row.propagation_kind.clone())
            .or_else(|| propagation_kind_for(&event.event_type, payload).map(str::to_owned)),
        reason: text(&payload["timeout_kind"]).or_else(|| text(&payload["reason"])),
        failure_category: text(&payload["failure_category"])
            .or_else(|| row.and_then(|row| row.failure_category.clone()))
            .or_else(|| failure_category_for(&event.event_type, payload).map(str::to_owned)),
        non_retryable: payload["non_retryable"].as_bool().unwrap_or_else(|| row.is_some_and(|row| row.non_retryable)),
        handled: row.is_some_and(|row| row.handled),
        exception_type: payload_type.clone().or_else(|| text(&payload["exception_type"])),
        exception_class: payload_class.clone().or_else(|| row.and_then(|row| row.exception_class.clone())),
        message: payload_message.clone().or_else(|| row.and_then(|row| row.message.clone())),
        payload_type,
        payload_class,
        payload_message,
        event_sequence: Some(event.sequence),
        history_authority: "typed_history",
        created_millis: row.map_or(event.recorded_millis, |row| row.created_millis),
    })
}

/// `FailureSnapshots::forRun`: a snapshot per failure row, replaced by the
/// typed history event that names it, handled when a `FailureHandled` event
/// names it, ordered by event sequence (rows without one last), creation
/// time and id.
fn snapshots(rows: &[FailureRow], events: &[FailureEvent]) -> Vec<Snapshot> {
    let mut by_id: Vec<(String, Snapshot)> = rows.iter().map(|row| (row.id.clone(), from_row(row))).collect();
    for event in events {
        if event.event_type == "FailureHandled" {
            if let Some(id) = text(&event.payload["failure_id"])
                && let Some((_, snapshot)) = by_id.iter_mut().find(|(key, _)| *key == id)
            {
                snapshot.handled = true;
            }
            continue;
        }
        if !FAILURE_EVENTS.contains(&event.event_type.as_str()) {
            continue;
        }
        let Some(id) = text(&event.payload["failure_id"]) else { continue };
        let row = rows.iter().find(|row| row.id == id);
        let Some(mut snapshot) = from_event(event, row) else { continue };
        if let Some((_, existing)) = by_id.iter_mut().find(|(key, _)| *key == id) {
            snapshot.handled |= existing.handled;
            *existing = snapshot;
        } else {
            by_id.push((id, snapshot));
        }
    }
    let mut list: Vec<Snapshot> = by_id.into_iter().map(|(_, snapshot)| snapshot).collect();
    list.sort_by(|left, right| {
        let sequence = |snapshot: &Snapshot| (snapshot.event_sequence.is_none(), snapshot.event_sequence.unwrap_or(0));
        sequence(left).cmp(&sequence(right))
            .then(left.created_millis.cmp(&right.created_millis))
            .then(left.id.cmp(&right.id))
    });
    list
}

/// `compactFailureSnapshots` for one snapshot.
fn compact_snapshot(snapshot: &Snapshot) -> Value {
    let payload = compact(vec![
        ("type", optional(snapshot.payload_type.clone())),
        ("class", optional(snapshot.payload_class.clone())),
        ("message", optional(snapshot.payload_message.clone())),
    ]);
    let payload = if payload.as_object().is_some_and(Map::is_empty) { Value::Null } else { payload };
    compact(vec![
        ("id", optional(snapshot.id.clone())),
        ("source_kind", optional(snapshot.source_kind.clone())),
        ("source_id", optional(snapshot.source_id.clone())),
        ("propagation_kind", optional(snapshot.propagation_kind.clone())),
        ("reason", optional(snapshot.reason.clone())),
        ("failure_category", optional(snapshot.failure_category.clone())),
        ("non_retryable", json!(snapshot.non_retryable)),
        ("handled", json!(snapshot.handled)),
        ("exception_type", optional(snapshot.exception_type.clone())),
        ("exception_class", optional(snapshot.exception_class.clone())),
        ("message", optional(snapshot.message.clone())),
        ("exception_payload", payload),
        ("event_sequence", snapshot.event_sequence.map_or(Value::Null, |sequence| json!(sequence))),
        ("history_authority", json!(snapshot.history_authority)),
    ])
}

/// `activityFailureSummaries`: one per activity failure or timeout event.
fn activity_failures(events: &[FailureEvent]) -> Vec<Value> {
    events
        .iter()
        .filter(|event| matches!(event.event_type.as_str(), "ActivityFailed" | "ActivityTimedOut"))
        .map(|event| {
            let payload = &event.payload;
            let field = |name: &str| optional(text(&payload[name]));
            compact(vec![
                ("event_sequence", json!(event.sequence)),
                ("event_type", json!(event.event_type)),
                ("activity_type", field("activity_type")),
                ("activity_class", field("activity_class")),
                ("activity_execution_id", field("activity_execution_id")),
                ("activity_attempt_id", field("activity_attempt_id")),
                ("failure_id", field("failure_id")),
                ("failure_category", field("failure_category")),
                ("exception_type", field("exception_type")),
                ("exception_class", field("exception_class")),
                ("message", field("message")),
                ("non_retryable", payload.get("non_retryable").cloned().unwrap_or(Value::Null)),
            ])
        })
        .collect()
}

/// `terminalFailurePayload` and the four describe fields it fills, in the
/// controller's order: `error`, `failure`, `exception`, `failures`. Nothing
/// for a run that is not failed, or that has no failure at all.
pub(super) fn describe_fields(status: &str, rows: &[FailureRow], events: &[FailureEvent]) -> Option<Vec<(&'static str, Value)>> {
    if status != "failed" {
        return None;
    }
    let failures: Vec<Value> = snapshots(rows, events).iter().map(compact_snapshot).collect();
    let activities = activity_failures(events);
    if failures.is_empty() && activities.is_empty() {
        return None;
    }
    // `terminalFailureSnapshot`: the last run-level or terminal snapshot,
    // else the last one.
    let terminal = failures
        .iter()
        .rev()
        .find(|failure| failure["source_kind"] == "workflow_run" || failure["propagation_kind"] == "terminal")
        .or_else(|| failures.last())
        .cloned()
        .unwrap_or_else(|| json!({}));
    let message = text(&terminal["message"]).or_else(|| activities.iter().rev().find_map(|failure| text(&failure["message"])));
    // `failureExceptionPayload` over the compacted terminal snapshot.
    let exception_payload = &terminal["exception_payload"];
    let exception = compact(vec![
        ("type", optional(text(&exception_payload["type"]).or_else(|| text(&terminal["exception_type"])))),
        ("class", optional(text(&exception_payload["class"]).or_else(|| text(&exception_payload["__constructor"])).or_else(|| text(&terminal["exception_class"])))),
        ("message", optional(text(&exception_payload["message"]).or_else(|| text(&terminal["message"])))),
    ]);
    let exception = if exception.as_object().is_some_and(Map::is_empty) { Value::Null } else { exception };
    let failure = compact(vec![
        ("message", optional(message.clone())),
        ("reason", optional(text(&terminal["reason"]))),
        ("exception_type", optional(text(&terminal["exception_type"]))),
        ("exception_class", optional(text(&terminal["exception_class"]))),
        ("failure_category", optional(text(&terminal["failure_category"]))),
        ("non_retryable", terminal.get("non_retryable").cloned().unwrap_or(Value::Null)),
        ("source_kind", optional(text(&terminal["source_kind"]))),
        ("source_id", optional(text(&terminal["source_id"]))),
        ("event_sequence", terminal.get("event_sequence").cloned().unwrap_or(Value::Null)),
        ("exception", exception.clone()),
        ("activity_failures", json!(activities)),
        ("failures", json!(failures)),
    ]);
    let exception = failure.get("exception").cloned().unwrap_or(Value::Null);
    let failures = failure.get("failures").cloned().unwrap_or_else(|| json!([]));
    Some(vec![("error", optional(message)), ("failure", failure), ("exception", exception), ("failures", failures)])
}

store_methods! {
    /// The run's terminal failure fields for describe, read in one snapshot.
    pub(super) async fn terminal_failure_describe(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        status: &str,
    ) -> Result<Option<Vec<(&'static str, Value)>>> {
        if status != "failed" {
            return Ok(None);
        }
        let millis = |at: Option<chrono::DateTime<chrono::Utc>>| at.map_or(0, |at| at.timestamp_millis());
        let mut rows = vec![];
        for row in Self::query("SELECT id,source_kind,source_id,propagation_kind,failure_category,CASE WHEN non_retryable THEN 1 ELSE 0 END AS non_retryable,CASE WHEN handled THEN 1 ELSE 0 END AS handled,exception_class,message,created_at FROM workflow_failures WHERE workflow_run_id=$1")
            .bind(run_id).fetch_all(&mut **tx).await?
        {
            rows.push(FailureRow {
                id: DB::string(&row, "id")?,
                source_kind: DB::optional_string(&row, "source_kind")?,
                source_id: DB::optional_string(&row, "source_id")?,
                propagation_kind: DB::optional_string(&row, "propagation_kind")?,
                failure_category: DB::optional_string(&row, "failure_category")?,
                non_retryable: DB::number(&row, "non_retryable")? != 0,
                handled: DB::number(&row, "handled")? != 0,
                exception_class: DB::optional_string(&row, "exception_class")?,
                message: DB::optional_string(&row, "message")?,
                created_millis: millis(DB::optional_instant(&row, "created_at")?),
            });
        }
        let mut events = vec![];
        for row in Self::query("SELECT sequence,event_type,workflow_command_id,payload,recorded_at FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('FailureHandled','ActivityFailed','ActivityTimedOut','ChildRunFailed','ChildRunCancelled','ChildRunTerminated','WorkflowFailed','WorkflowTimedOut','WorkflowCancelled','WorkflowTerminated','UpdateCompleted') ORDER BY sequence")
            .bind(run_id).fetch_all(&mut **tx).await?
        {
            events.push(FailureEvent {
                sequence: DB::number(&row, "sequence")?,
                event_type: DB::string(&row, "event_type")?,
                run_id: run_id.to_owned(),
                command_id: DB::optional_string(&row, "workflow_command_id")?,
                payload: DB::optional_document_row(&row, "payload")?.unwrap_or(Value::Null),
                recorded_millis: millis(DB::optional_instant(&row, "recorded_at")?),
            });
        }
        Ok(describe_fields(status, &rows, &events))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn event(sequence: i64, event_type: &str, payload: Value) -> FailureEvent {
        FailureEvent { sequence, event_type: event_type.into(), run_id: "RUN".into(), command_id: None, payload, recorded_millis: sequence }
    }

    fn field<'a>(fields: &'a [(&'static str, Value)], name: &str) -> &'a Value {
        &fields.iter().find(|(key, _)| *key == name).unwrap().1
    }

    #[test]
    fn a_failed_run_reports_its_terminal_typed_failure_as_php_does() {
        // The #967 run: a non-retryable activity failure the workflow fails with.
        let rows = vec![
            FailureRow { id: "F-ACT".into(), source_kind: Some("activity_execution".into()), source_id: Some("EXEC".into()),
                propagation_kind: Some("activity".into()), failure_category: Some("activity".into()), non_retryable: true,
                exception_class: Some("RuntimeException".into()), message: Some("rejected bob".into()), created_millis: 1, ..Default::default() },
            FailureRow { id: "F-RUN".into(), source_kind: Some("workflow_run".into()), source_id: Some("RUN".into()),
                propagation_kind: Some("terminal".into()), failure_category: Some("application".into()), non_retryable: true,
                exception_class: Some("durable_workflow::ActivityFailure".into()), message: Some("activity failed (activity): rejected bob".into()),
                created_millis: 2, ..Default::default() },
        ];
        let events = vec![
            event(5, "ActivityFailed", json!({"failure_id":"F-ACT","activity_type":"qualification.reject","activity_class":"qualification.reject",
                "activity_execution_id":"EXEC","activity_attempt_id":"ATT","failure_category":"activity","exception_type":"QualificationRejected",
                "exception_class":"RuntimeException","message":"rejected bob","non_retryable":true})),
            event(6, "WorkflowFailed", json!({"failure_id":"F-RUN","source_kind":"workflow_run","source_id":"RUN","failure_category":"application",
                "non_retryable":true,"exception_type":"ActivityFailed","exception_class":"durable_workflow::ActivityFailure",
                "message":"activity failed (activity): rejected bob","exception":{"class":"durable_workflow::ActivityFailure",
                "type":"ActivityFailed","message":"activity failed (activity): rejected bob","code":0}})),
        ];
        let fields = describe_fields("failed", &rows, &events).unwrap();
        assert_eq!(fields.iter().map(|(key, _)| *key).collect::<Vec<_>>(), ["error", "failure", "exception", "failures"]);
        let failure = field(&fields, "failure");
        assert_eq!(failure["message"], "activity failed (activity): rejected bob");
        assert_eq!(failure["exception_type"], "ActivityFailed");
        assert_eq!(failure["exception_class"], "durable_workflow::ActivityFailure");
        assert_eq!(failure["failure_category"], "application");
        assert_eq!(failure["non_retryable"], true);
        assert_eq!(failure["source_kind"], "workflow_run");
        assert_eq!(failure["event_sequence"], 6);
        assert!(failure.get("reason").is_none());
        assert_eq!(failure["exception"], json!({"type":"ActivityFailed","class":"durable_workflow::ActivityFailure",
            "message":"activity failed (activity): rejected bob"}));
        assert_eq!(failure["activity_failures"][0]["exception_type"], "QualificationRejected");
        assert_eq!(failure["failures"].as_array().unwrap().len(), 2);
        assert_eq!(failure["failures"][0]["exception_payload"], json!({"class":"RuntimeException","message":"rejected bob"}));
        assert_eq!(field(&fields, "error"), "activity failed (activity): rejected bob");
        assert_eq!(field(&fields, "exception"), &failure["exception"]);
    }

    #[test]
    fn a_timed_out_run_names_its_timeout_kind_as_the_reason() {
        let rows = vec![FailureRow { id: "F-T".into(), source_kind: Some("workflow_run".into()), source_id: Some("RUN".into()),
            propagation_kind: Some("timeout".into()), failure_category: Some("timeout".into()),
            exception_class: Some("Workflow\\V2\\Exceptions\\WorkflowTimeoutException".into()),
            message: Some("Workflow run deadline expired.".into()), created_millis: 1, ..Default::default() }];
        let events = vec![event(5, "WorkflowTimedOut", json!({"failure_id":"F-T","timeout_kind":"run_timeout","failure_category":"timeout",
            "message":"Workflow run deadline expired.","exception_class":"Workflow\\V2\\Exceptions\\WorkflowTimeoutException"}))];
        let fields = describe_fields("failed", &rows, &events).unwrap();
        let failure = field(&fields, "failure");
        assert_eq!(failure["reason"], "run_timeout");
        assert_eq!(failure["failure_category"], "timeout");
        assert_eq!(failure["non_retryable"], false);
        assert!(failure.get("exception_type").is_none());
        assert_eq!(failure["exception"], json!({"class":"Workflow\\V2\\Exceptions\\WorkflowTimeoutException","message":"Workflow run deadline expired."}));
        assert!(failure.get("activity_failures").is_none());
    }

    #[test]
    fn only_a_failed_run_with_a_failure_gets_the_fields() {
        assert!(describe_fields("completed", &[], &[]).is_none());
        assert!(describe_fields("failed", &[], &[]).is_none());
    }
}
