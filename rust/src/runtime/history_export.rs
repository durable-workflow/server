//! `HistoryExport::forRun` (Workflow 2.5.5): the replay bundle of one run.
//!
//! The bundle is built from the run's rows as JSON objects keyed by column.
//! Native keeps no timeline or full wait projection yet, so those sections
//! are empty and `selected_run` names them unavailable. Timers and lineage
//! are rebuilt from history and links, as PHP rebuilds a missing projection.
use super::backend::Backend;
use super::external_resolution::stored_envelope;
use super::task_queues::json_time;
use base64::{Engine, engine::general_purpose::STANDARD};
use hmac::{Hmac, KeyInit, Mac};
use serde_json::{Map, Value, json};
use sha2::{Digest, Sha256};

pub(super) const SCHEMA: &str = "durable-workflow.v2.history-export";
/// CRC-64-AVRO of the protocol Value schema, as single-object frames carry it.
pub(super) const VALUE_FINGERPRINT: &str = "e2a33dff55802237";
const UNAVAILABLE: &str = "native_unavailable";
const ACTIVITY_EVENTS: &[&str] = &[
    "ActivityScheduled",
    "ActivityStarted",
    "ActivityHeartbeatRecorded",
    "ActivityRetryScheduled",
    "ActivityCompleted",
    "ActivityFailed",
    "ActivityCancelled",
    "ActivityTimedOut",
];

/// How a column is read into the row's JSON.
#[derive(Clone, Copy)]
pub(super) enum Column {
    Text,
    Time,
    Int,
    /// A boolean read from its `<name>_flag` column, which the query selects
    /// as `CASE WHEN … THEN 1 ELSE 0 END` so every backend returns a number.
    Flag,
    Json,
}

/// One row as JSON keyed by column, with times in PHP's `toJSON` form.
pub(super) fn row_json<DB: Backend>(row: &DB::Row, columns: &[(&str, Column)]) -> super::Result<Value> {
    let mut object = Map::new();
    for &(name, column) in columns {
        let value = match column {
            Column::Text => json!(DB::optional_string(row, name)?),
            Column::Time => json_time(DB::optional_instant(row, name)?),
            Column::Int => json!(DB::optional_number(row, name)?),
            Column::Flag => json!(DB::optional_number(row, &format!("{name}_flag"))?.is_some_and(|flag| flag != 0)),
            Column::Json => DB::optional_document_row(row, name)?.unwrap_or(Value::Null),
        };
        object.insert(name.to_owned(), value);
    }
    Ok(Value::Object(object))
}

use Column::{Flag, Int, Json, Text, Time};

pub(super) const RUN: &[(&str, Column)] = &[
    ("id", Text), ("workflow_instance_id", Text), ("run_number", Int), ("workflow_type", Text), ("workflow_class", Text),
    ("business_key", Text), ("visibility_labels", Json), ("status", Text), ("closed_reason", Text), ("compatibility", Text),
    ("payload_codec", Text), ("arguments", Text), ("output", Text), ("output_payload_codec", Text), ("connection", Text),
    ("queue", Text), ("last_history_sequence", Int), ("started_at", Time), ("closed_at", Time), ("archived_at", Time),
    ("archive_command_id", Text), ("archive_reason", Text), ("last_progress_at", Time), ("updated_at", Time),
    ("current_run_id", Text), ("instance_business_key", Text), ("instance_visibility_labels", Json), ("continuations", Int),
];
pub(super) const RUN_SQL: &str = "SELECT r.*,i.current_run_id,i.business_key AS instance_business_key,i.visibility_labels AS instance_visibility_labels,(SELECT COUNT(*) FROM workflow_links l WHERE l.child_workflow_instance_id=r.workflow_instance_id AND l.link_type='continue_as_new') AS continuations FROM workflow_runs r JOIN workflow_instances i ON i.id=r.workflow_instance_id WHERE i.id=$1 AND r.id=$2 AND r.namespace=$3";
pub(super) const SUMMARY: &[(&str, Column)] = &[
    ("status", Text), ("status_bucket", Text), ("closed_reason", Text), ("archived_at", Time), ("archive_command_id", Text),
    ("archive_reason", Text), ("business_key", Text), ("visibility_labels", Json), ("is_current_run", Flag), ("started_at", Time),
    ("closed_at", Time), ("duration_ms", Int), ("wait_kind", Text), ("wait_reason", Text), ("wait_started_at", Time),
    ("wait_deadline_at", Time), ("open_wait_id", Text), ("resume_source_kind", Text), ("resume_source_id", Text),
    ("next_task_at", Time), ("next_task_id", Text), ("next_task_type", Text), ("next_task_status", Text),
    ("next_task_lease_expires_at", Time), ("liveness_state", Text), ("liveness_reason", Text), ("repair_attention", Flag),
    ("task_problem", Flag), ("exception_count", Int), ("history_event_count", Int), ("history_size_bytes", Int),
    ("history_fan_out", Int), ("continue_as_new_recommended", Flag), ("history_budget_pressure", Text),
    ("sort_timestamp", Time), ("sort_key", Text),
];
pub(super) const SUMMARY_SQL: &str = "SELECT s.*,CASE WHEN s.is_current_run THEN 1 ELSE 0 END AS is_current_run_flag,CASE WHEN s.repair_attention THEN 1 ELSE 0 END AS repair_attention_flag,CASE WHEN s.task_problem THEN 1 ELSE 0 END AS task_problem_flag,CASE WHEN s.continue_as_new_recommended THEN 1 ELSE 0 END AS continue_as_new_recommended_flag FROM workflow_run_summaries s WHERE s.id=$1";
pub(super) const EVENTS: &[(&str, Column)] = &[
    ("id", Text), ("sequence", Int), ("event_type", Text), ("workflow_task_id", Text), ("workflow_command_id", Text),
    ("recorded_at", Time), ("payload", Json),
];
pub(super) const EVENTS_SQL: &str = "SELECT id,sequence,event_type,workflow_task_id,workflow_command_id,recorded_at,payload FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence";
pub(super) const COMMANDS: &[(&str, Column)] = &[
    ("id", Text), ("command_sequence", Int), ("message_sequence", Int), ("command_type", Text), ("target_scope", Text),
    ("requested_workflow_run_id", Text), ("resolved_workflow_run_id", Text), ("target_name", Text), ("payload_codec", Text),
    ("payload", Text), ("source", Text), ("request_id", Text), ("status", Text), ("outcome", Text), ("rejection_reason", Text),
    ("accepted_at", Time), ("applied_at", Time), ("rejected_at", Time),
];
pub(super) const COMMANDS_SQL: &str = "SELECT c.*,COALESCE(s.signal_name,u.update_name) AS target_name FROM workflow_commands c LEFT JOIN workflow_signal_records s ON s.workflow_command_id=c.id LEFT JOIN workflow_updates u ON u.workflow_command_id=c.id WHERE c.workflow_run_id=$1 ORDER BY c.command_sequence,c.created_at,c.id";
pub(super) const SIGNALS: &[(&str, Column)] = &[
    ("id", Text), ("workflow_command_id", Text), ("command_sequence", Int), ("workflow_sequence", Int), ("signal_name", Text),
    ("signal_wait_id", Text), ("target_scope", Text), ("requested_workflow_run_id", Text), ("resolved_workflow_run_id", Text),
    ("status", Text), ("outcome", Text), ("rejection_reason", Text), ("validation_errors", Json), ("received_at", Time),
    ("applied_at", Time), ("rejected_at", Time), ("closed_at", Time), ("payload_codec", Text), ("arguments", Text),
];
pub(super) const SIGNALS_SQL: &str = "SELECT * FROM workflow_signal_records WHERE workflow_run_id=$1 ORDER BY command_sequence,created_at,id";
pub(super) const UPDATES: &[(&str, Column)] = &[
    ("id", Text), ("workflow_command_id", Text), ("command_sequence", Int), ("workflow_sequence", Int), ("update_name", Text),
    ("status", Text), ("outcome", Text), ("rejection_reason", Text), ("validation_errors", Json), ("failure_id", Text),
    ("accepted_at", Time), ("applied_at", Time), ("rejected_at", Time), ("closed_at", Time), ("payload_codec", Text),
    ("arguments", Text), ("result", Text),
];
pub(super) const UPDATES_SQL: &str = "SELECT * FROM workflow_updates WHERE workflow_run_id=$1 ORDER BY command_sequence,created_at,id";
pub(super) const TASKS: &[(&str, Column)] = &[
    ("id", Text), ("task_type", Text), ("status", Text), ("payload", Json), ("connection", Text), ("queue", Text),
    ("compatibility", Text), ("attempt_count", Int), ("repair_count", Int), ("available_at", Time), ("leased_at", Time),
    ("lease_owner", Text), ("lease_expires_at", Time), ("last_dispatch_attempt_at", Time), ("last_dispatched_at", Time),
    ("last_dispatch_error", Text), ("last_claim_failed_at", Time), ("last_claim_error", Text), ("repair_available_at", Time),
    ("created_at", Time),
];
pub(super) const TASKS_SQL: &str = "SELECT * FROM workflow_tasks WHERE workflow_run_id=$1 ORDER BY created_at,id";
pub(super) const EXECUTIONS: &[(&str, Column)] = &[
    ("id", Text), ("sequence", Int), ("activity_class", Text), ("activity_type", Text), ("status", Text), ("payload_codec", Text),
    ("arguments", Text), ("result", Text), ("connection", Text), ("queue", Text), ("attempt_count", Int),
    ("current_attempt_id", Text), ("retry_policy", Json), ("parallel_group_path", Json), ("activity_options", Json),
    ("started_at", Time), ("closed_at", Time), ("last_heartbeat_at", Time), ("created_at", Time),
];
pub(super) const EXECUTIONS_SQL: &str = "SELECT * FROM activity_executions WHERE workflow_run_id=$1 ORDER BY sequence,created_at,id";
pub(super) const ATTEMPTS: &[(&str, Column)] = &[
    ("id", Text), ("activity_execution_id", Text), ("workflow_task_id", Text), ("attempt_number", Int), ("status", Text),
    ("lease_owner", Text), ("started_at", Time), ("last_heartbeat_at", Time), ("lease_expires_at", Time), ("closed_at", Time),
    ("worker_attempt_id", Text),
];
pub(super) const ATTEMPTS_SQL: &str = "SELECT * FROM activity_attempts WHERE workflow_run_id=$1 ORDER BY attempt_number,created_at,id";
pub(super) const FAILURES: &[(&str, Column)] = &[
    ("id", Text), ("source_kind", Text), ("source_id", Text), ("propagation_kind", Text), ("failure_category", Text),
    ("non_retryable", Flag), ("handled", Flag), ("exception_class", Text), ("message", Text), ("file", Text), ("line", Int),
    ("trace_preview", Text), ("created_at", Time),
];
pub(super) const FAILURES_SQL: &str = "SELECT f.*,CASE WHEN f.non_retryable THEN 1 ELSE 0 END AS non_retryable_flag,CASE WHEN f.handled THEN 1 ELSE 0 END AS handled_flag FROM workflow_failures f WHERE f.workflow_run_id=$1 ORDER BY f.created_at,f.id";
pub(super) const LINKS: &[(&str, Column)] = &[
    ("id", Text), ("link_type", Text), ("sequence", Int), ("parent_workflow_instance_id", Text), ("parent_workflow_run_id", Text),
    ("child_workflow_instance_id", Text), ("child_workflow_run_id", Text), ("is_primary_parent", Flag), ("created_at", Time),
    ("run_number", Int), ("workflow_type", Text), ("workflow_class", Text), ("status", Text), ("closed_reason", Text),
];
pub(super) const PARENTS_SQL: &str = "SELECT l.*,CASE WHEN l.is_primary_parent THEN 1 ELSE 0 END AS is_primary_parent_flag,r.run_number,r.workflow_type,r.workflow_class,r.status,r.closed_reason FROM workflow_links l LEFT JOIN workflow_runs r ON r.id=l.parent_workflow_run_id WHERE l.child_workflow_run_id=$1 ORDER BY l.created_at,l.id";
pub(super) const CHILDREN_SQL: &str = "SELECT l.*,CASE WHEN l.is_primary_parent THEN 1 ELSE 0 END AS is_primary_parent_flag,r.run_number,r.workflow_type,r.workflow_class,r.status,r.closed_reason FROM workflow_links l LEFT JOIN workflow_runs r ON r.id=l.child_workflow_run_id WHERE l.parent_workflow_run_id=$1 AND l.link_type='continue_as_new' ORDER BY l.created_at,l.id";

/// The rows `forRun` reads for one run.
#[derive(Default)]
pub(super) struct Rows {
    pub(super) run: Value,
    pub(super) summary: Option<Value>,
    pub(super) events: Vec<Value>,
    pub(super) commands: Vec<Value>,
    pub(super) signals: Vec<Value>,
    pub(super) updates: Vec<Value>,
    pub(super) tasks: Vec<Value>,
    pub(super) executions: Vec<Value>,
    pub(super) attempts: Vec<Value>,
    pub(super) failures: Vec<Value>,
    pub(super) parents: Vec<Value>,
    pub(super) children: Vec<Value>,
    /// The memo projection, `[]` when the run has none.
    pub(super) memo: Value,
    /// `MemoPayload::mapEnvelope`, null when the run has no memo.
    pub(super) memo_payload: Value,
    pub(super) search_attributes: Value,
    pub(super) task_problem_badge: Value,
}

/// `DW_V2_HISTORY_EXPORT_SIGNING_KEY` and its optional key id.
pub(super) struct Signing {
    pub(super) key: String,
    pub(super) key_id: Option<String>,
}

fn text(value: &Value) -> Option<&str> {
    value.as_str().filter(|text| !text.is_empty())
}

fn or(first: &Value, second: &Value) -> Value {
    if first.is_null() { second.clone() } else { first.clone() }
}

/// `payloadBlob`: an inline payload string or an envelope's blob.
fn payload_blob(payload: &Value) -> Option<&str> {
    match payload {
        Value::String(blob) if !blob.is_empty() => stored_envelope(blob).is_none().then_some(blob.as_str()),
        Value::Object(fields) => fields.get("blob").and_then(text),
        _ => None,
    }
}

/// `externalStorageEnvelope`.
fn external_envelope(payload: &Value) -> Option<Value> {
    match payload {
        Value::String(blob) if !blob.is_empty() => stored_envelope(blob),
        Value::Object(fields) if fields.get("external_storage").is_some_and(Value::is_object) => Some(payload.clone()),
        _ => None,
    }
}

fn payload_available(payload: &Value) -> bool {
    payload_blob(payload).is_some() || external_envelope(payload).is_some()
}

/// `payloadValue`: an external reference's envelope, an inline payload, or null.
fn payload_value(payload: &Value) -> Value {
    if let Some(envelope) = external_envelope(payload) {
        return envelope;
    }
    if payload_blob(payload).is_some() { payload.clone() } else { Value::Null }
}

/// `normalizeStoredPayloadReferences`.
fn normalized(value: &Value) -> Value {
    match value {
        Value::String(blob) if !blob.is_empty() => stored_envelope(blob).unwrap_or_else(|| value.clone()),
        Value::Array(items) => Value::Array(items.iter().map(normalized).collect()),
        Value::Object(fields) => Value::Object(fields.iter().map(|(key, item)| (key.clone(), normalized(item))).collect()),
        other => other.clone(),
    }
}

fn list_or_empty(value: &Value) -> Value {
    if value.is_array() || value.is_object() { value.clone() } else { json!([]) }
}

fn workflow(rows: &Rows) -> Value {
    let run = &rows.run;
    let summary = rows.summary.clone().unwrap_or(Value::Null);
    let continued = run["continuations"].as_i64().is_some_and(|count| count > 0);
    let status = text(&run["status"]).unwrap_or_default();
    json!({
        "instance_id": run["workflow_instance_id"], "run_id": run["id"], "run_number": run["run_number"],
        "is_current_run": run["current_run_id"] == run["id"], "current_run_id": run["current_run_id"],
        "current_run_source": if continued { "continue_as_new_lineage" } else { "run_order_fallback" },
        "workflow_type": run["workflow_type"], "workflow_class": run["workflow_class"],
        "source_runtime": "standalone_server", "source_process_class": "server_http_node",
        "business_key": or(&or(&summary["business_key"], &run["business_key"]), &run["instance_business_key"]),
        "visibility_labels": list_or_empty(&or(&or(&summary["visibility_labels"], &run["visibility_labels"]), &run["instance_visibility_labels"])),
        "memo": rows.memo, "memo_payload": rows.memo_payload, "search_attributes": rows.search_attributes,
        "status": status, "status_bucket": super::visibility::status_bucket(status).ok(),
        "closed_reason": or(&summary["closed_reason"], &run["closed_reason"]),
        "archived_at": or(&summary["archived_at"], &run["archived_at"]),
        "archive_command_id": or(&summary["archive_command_id"], &run["archive_command_id"]),
        "archive_reason": or(&summary["archive_reason"], &run["archive_reason"]),
        "compatibility": run["compatibility"], "connection": run["connection"], "queue": run["queue"],
        "last_history_sequence": run["last_history_sequence"],
        "started_at": or(&summary["started_at"], &run["started_at"]),
        "closed_at": or(&summary["closed_at"], &run["closed_at"]),
        "last_progress_at": run["last_progress_at"],
    })
}

fn payloads(run: &Value) -> Value {
    let codec = text(&run["payload_codec"]).unwrap_or("avro");
    let output = !run["output"].is_null();
    json!({
        "codec": codec,
        "arguments": {"available": !run["arguments"].is_null(), "data": payload_value(&run["arguments"])},
        "output": {"available": output, "codec": output.then(|| text(&run["output_payload_codec"]).unwrap_or(codec)),
            "data": payload_value(&run["output"])},
    })
}

fn summary(rows: &Rows) -> Value {
    let Some(summary) = &rows.summary else {
        return Value::Null;
    };
    let mut projected = Map::new();
    for field in [
        "status", "status_bucket", "closed_reason", "archived_at", "archive_command_id", "archive_reason", "business_key",
    ] {
        projected.insert(field.into(), summary[field].clone());
    }
    projected.insert("visibility_labels".into(), list_or_empty(&summary["visibility_labels"]));
    projected.insert("search_attributes".into(), rows.search_attributes.clone());
    for field in [
        "is_current_run", "started_at", "closed_at", "duration_ms", "wait_kind", "wait_reason", "wait_started_at",
        "wait_deadline_at", "open_wait_id", "resume_source_kind", "resume_source_id", "next_task_at", "next_task_id",
        "next_task_type", "next_task_status", "next_task_lease_expires_at", "liveness_state", "liveness_reason",
        "repair_attention", "task_problem",
    ] {
        projected.insert(field.into(), summary[field].clone());
    }
    projected.insert("task_problem_badge".into(), rows.task_problem_badge.clone());
    for field in ["exception_count", "history_event_count", "history_size_bytes", "history_fan_out"] {
        projected.insert(field.into(), json!(summary[field].as_i64().unwrap_or(0)));
    }
    projected.insert("continue_as_new_recommended".into(), summary["continue_as_new_recommended"].clone());
    projected.insert(
        "history_budget_pressure".into(),
        json!(text(&summary["history_budget_pressure"]).unwrap_or("ok")),
    );
    projected.insert("sort_timestamp".into(), summary["sort_timestamp"].clone());
    projected.insert("sort_key".into(), summary["sort_key"].clone());
    Value::Object(projected)
}

fn history_event(event: &Value) -> Value {
    json!({"id": event["id"], "sequence": event["sequence"], "type": event["event_type"],
        "workflow_task_id": event["workflow_task_id"], "workflow_command_id": event["workflow_command_id"],
        "recorded_at": event["recorded_at"], "payload": normalized(&list_or_empty(&event["payload"]))})
}

/// `command`: native records no caller, principal or request context.
fn command(row: &Value) -> Value {
    let external = row["payload"].as_str().is_some_and(|payload| stored_envelope(payload).is_some());
    let rejected = row["status"] == "rejected";
    json!({"id": row["id"], "sequence": row["command_sequence"], "message_sequence": row["message_sequence"],
        "type": row["command_type"], "target_scope": row["target_scope"],
        "requested_run_id": row["requested_workflow_run_id"], "resolved_run_id": row["resolved_workflow_run_id"],
        "target_name": if external { Value::Null } else { row["target_name"].clone() },
        "payload_codec": row["payload_codec"], "payload": payload_value(&row["payload"]), "source": row["source"],
        "context": [], "caller_label": null, "principal_type": null, "principal_id": null, "principal_label": null,
        "auth_status": null, "auth_method": null, "request_method": null, "request_path": null,
        "request_route_name": null, "request_fingerprint": null, "request_id": row["request_id"], "correlation_id": null,
        "status": row["status"], "outcome": row["outcome"],
        "reason": if rejected || !external { row["rejection_reason"].clone() } else { Value::Null },
        "rejection_reason": row["rejection_reason"], "validation_errors": [],
        "accepted_at": row["accepted_at"], "applied_at": row["applied_at"], "rejected_at": row["rejected_at"]})
}

fn signal(row: &Value) -> Value {
    json!({"id": row["id"], "command_id": row["workflow_command_id"], "command_sequence": row["command_sequence"],
        "workflow_sequence": row["workflow_sequence"], "name": row["signal_name"], "signal_wait_id": row["signal_wait_id"],
        "target_scope": row["target_scope"], "requested_run_id": row["requested_workflow_run_id"],
        "resolved_run_id": row["resolved_workflow_run_id"], "status": row["status"], "outcome": row["outcome"],
        "rejection_reason": row["rejection_reason"], "validation_errors": list_or_empty(&row["validation_errors"]),
        "received_at": row["received_at"], "applied_at": row["applied_at"], "rejected_at": row["rejected_at"],
        "closed_at": row["closed_at"], "payload_codec": row["payload_codec"], "arguments": payload_value(&row["arguments"])})
}

fn update(row: &Value) -> Value {
    json!({"id": row["id"], "command_id": row["workflow_command_id"], "command_sequence": row["command_sequence"],
        "workflow_sequence": row["workflow_sequence"], "name": row["update_name"], "status": row["status"],
        "outcome": row["outcome"], "rejection_reason": row["rejection_reason"],
        "validation_errors": list_or_empty(&row["validation_errors"]), "failure_id": row["failure_id"],
        "accepted_at": row["accepted_at"], "applied_at": row["applied_at"], "rejected_at": row["rejected_at"],
        "closed_at": row["closed_at"], "payload_codec": row["payload_codec"],
        "arguments": payload_value(&row["arguments"]), "result": payload_value(&row["result"])})
}

fn task(row: &Value) -> Value {
    let mut task = row.clone();
    if let Some(fields) = task.as_object_mut() {
        let kind = fields.remove("task_type").unwrap_or(Value::Null);
        fields.insert("type".into(), kind);
        let payload = list_or_empty(&fields["payload"]);
        fields.insert("payload".into(), payload);
    }
    task
}

/// `activityState` for an execution, from its typed history and row.
fn activity(execution: &Value, events: &[Value], attempts: &[Value]) -> Value {
    let id = &execution["id"];
    let mut types: Vec<&str> = Vec::new();
    for event in events {
        let kind = event["event_type"].as_str().unwrap_or_default();
        if ACTIVITY_EVENTS.contains(&kind) && &event["payload"]["activity_execution_id"] == id && !types.contains(&kind) {
            types.push(kind);
        }
    }
    let status = text(&execution["status"]).unwrap_or("pending");
    let open = matches!(status, "pending" | "running");
    let (authority, unsupported) = if !types.is_empty() {
        ("typed_history", None)
    } else if open {
        ("mutable_open_fallback", None)
    } else {
        ("unsupported_terminal_history", Some("terminal_activity_history_missing"))
    };
    let typed = authority == "typed_history";
    let attempts: Vec<Value> = attempts
        .iter()
        .filter(|attempt| &attempt["activity_execution_id"] == id)
        .map(|attempt| {
            json!({"id": attempt["id"], "worker_attempt_id": attempt["worker_attempt_id"], "activity_execution_id": id,
                "workflow_task_id": attempt["workflow_task_id"], "attempt_number": attempt["attempt_number"],
                "status": attempt["status"], "lease_owner": attempt["lease_owner"], "started_at": attempt["started_at"],
                "last_heartbeat_at": attempt["last_heartbeat_at"], "last_heartbeat_progress": null,
                "lease_expires_at": attempt["lease_expires_at"], "closed_at": attempt["closed_at"]})
        })
        .collect();
    let terminal_hidden = unsupported.is_some();
    json!({"id": id, "idempotency_key": id, "sequence": execution["sequence"], "activity_type": execution["activity_type"],
        "activity_class": execution["activity_class"], "execution_mode": execution["activity_options"]["execution_mode"],
        "local_activity": false, "payload_codec": execution["payload_codec"], "status": status,
        "source_status": status, "history_authority": authority, "diagnostic_only": !typed,
        "history_event_types": types, "history_unsupported_reason": unsupported, "reused_from_run_id": null,
        "reused_activity_execution_id": null, "reused_recorded_at": null,
        "row_status": if typed { Value::Null } else { json!(status) },
        "parallel_group_kind": null, "parallel_group_id": null, "parallel_group_base_sequence": null,
        "parallel_group_size": null, "parallel_group_index": null,
        "parallel_group_path": list_or_empty(&execution["parallel_group_path"]),
        "connection": execution["connection"], "queue": execution["queue"], "retry_policy": execution["retry_policy"],
        "attempt_count": execution["attempt_count"].as_i64().unwrap_or(0), "current_attempt_id": execution["current_attempt_id"],
        "current_worker_attempt_id": null, "arguments": payload_value(&execution["arguments"]),
        "result": if terminal_hidden { Value::Null } else { payload_value(&execution["result"]) },
        "created_at": execution["created_at"], "started_at": execution["started_at"],
        "last_heartbeat_at": execution["last_heartbeat_at"], "last_heartbeat_progress": null,
        "closed_at": if terminal_hidden { Value::Null } else { execution["closed_at"].clone() }, "attempts": attempts})
}

/// Timers rebuilt from their typed history, in scheduling order.
fn timers(events: &[Value]) -> Vec<Value> {
    let mut timers: Vec<(String, Value, Vec<String>)> = Vec::new();
    for event in events {
        let kind = event["event_type"].as_str().unwrap_or_default();
        if !matches!(kind, "TimerScheduled" | "TimerFired" | "TimerCancelled") {
            continue;
        }
        let payload = &event["payload"];
        let Some(timer_id) = text(&payload["timer_id"]).map(str::to_owned) else {
            continue;
        };
        if kind == "TimerScheduled" && !timers.iter().any(|(id, _, _)| *id == timer_id) {
            timers.push((timer_id.clone(), json!({"id": timer_id, "sequence": payload["sequence"], "status": "pending",
                "delay_seconds": payload["delay_seconds"], "fire_at": payload["fire_at"], "fired_at": null,
                "cancelled_at": null, "created_at": event["recorded_at"], "timer_kind": payload["timer_kind"],
                "condition_wait_id": payload["condition_wait_id"], "condition_key": payload["condition_key"],
                "condition_definition_fingerprint": payload["condition_definition_fingerprint"],
                "signal_wait_id": payload["signal_wait_id"], "signal_name": payload["signal_name"]}), Vec::new()));
        }
        let Some((_, timer, types)) = timers.iter_mut().find(|(id, _, _)| *id == timer_id) else {
            continue;
        };
        if !types.iter().any(|seen| seen == kind) {
            types.push(kind.to_owned());
        }
        match kind {
            "TimerFired" => {
                timer["status"] = json!("fired");
                timer["fired_at"] = or(&payload["fired_at"], &event["recorded_at"]);
            }
            "TimerCancelled" => {
                timer["status"] = json!("cancelled");
                timer["cancelled_at"] = or(&payload["cancelled_at"], &event["recorded_at"]);
            }
            _ => {}
        }
    }
    timers
        .into_iter()
        .map(|(_, mut timer, types)| {
            timer["source_status"] = timer["status"].clone();
            timer["history_authority"] = json!("typed_history");
            timer["diagnostic_only"] = json!(false);
            timer["history_event_types"] = json!(types);
            timer["history_unsupported_reason"] = Value::Null;
            timer["row_status"] = Value::Null;
            timer
        })
        .collect()
}

fn failure(row: &Value) -> Value {
    json!({"id": row["id"], "source_kind": row["source_kind"], "source_id": row["source_id"],
        "propagation_kind": row["propagation_kind"], "failure_category": row["failure_category"],
        "non_retryable": row["non_retryable"], "handled": row["handled"], "history_authority": "typed_history",
        "diagnostic_only": false, "exception_type": null, "exception_class": row["exception_class"],
        "exception_resolved_class": null, "exception_resolution_source": null, "exception_resolution_error": null,
        "exception_replay_blocked": false, "message": row["message"], "file": row["file"], "line": row["line"],
        "trace_preview": row["trace_preview"], "created_at": row["created_at"]})
}

/// `parentLinks` and `childLinks`: `row` is the link with the related run.
fn link(row: &Value, run: &Value, parent: bool) -> Value {
    let (parent_instance, parent_run, child_instance, child_run) = if parent {
        (&row["parent_workflow_instance_id"], &row["parent_workflow_run_id"], &run["workflow_instance_id"], &run["id"])
    } else {
        (&run["workflow_instance_id"], &run["id"], &row["child_workflow_instance_id"], &row["child_workflow_run_id"])
    };
    let status = text(&row["status"]).unwrap_or_default();
    json!({"id": row["id"], "type": row["link_type"], "parent_workflow_instance_id": parent_instance,
        "parent_workflow_run_id": parent_run, "child_workflow_instance_id": child_instance,
        "child_workflow_run_id": child_run, "child_call_id": null, "sequence": row["sequence"],
        "is_primary_parent": row["is_primary_parent"], "run_number": row["run_number"],
        "workflow_type": row["workflow_type"], "class": row["workflow_class"], "status": row["status"],
        "status_bucket": super::visibility::status_bucket(status).ok(), "closed_reason": row["closed_reason"],
        "created_at": row["created_at"], "history_authority": "typed_history", "diagnostic_only": false})
}

fn encoding(codec: &str) -> &'static str {
    match codec {
        "avro" => "base64-avro-single-object",
        "workflow-serializer-y" | "Workflow\\Serializers\\Y" => "php-serialized-escaped",
        "workflow-serializer-base64" | "Workflow\\Serializers\\Base64" => "php-serialized-base64",
        _ => "opaque-string",
    }
}

/// `Avro::payloadMetadata` for one blob.
fn avro_metadata(blob: &str, entry: &mut Map<String, Value>) {
    let Ok(bytes) = STANDARD.decode(blob) else {
        let json = serde_json::from_str::<Value>(blob).is_ok();
        entry.insert("diagnostic".into(), json!(if json { "json_bytes_labeled_avro" } else { "invalid_base64" }));
        return;
    };
    if bytes.is_empty() {
        entry.insert("diagnostic".into(), json!("empty_payload"));
        return;
    }
    entry.insert("avro_prefix_hex".into(), json!(hex(&bytes[..bytes.len().min(2)])));
    if !bytes.starts_with(&[0xc3, 0x01]) {
        entry.insert("diagnostic".into(), json!("invalid_single_object_magic"));
        return;
    }
    entry.insert("avro_framing".into(), json!("single_object"));
    let fingerprint = hex(&bytes[2..bytes.len().min(10)]);
    entry.insert("writer_schema_fingerprint".into(), json!(format!("crc64-avro:{fingerprint}")));
    if fingerprint == VALUE_FINGERPRINT {
        entry.insert("writer_schema".into(), json!(crate::codec::VALUE_SCHEMA.trim()));
    } else {
        entry.insert("diagnostic".into(), json!("unsupported_payload_schema"));
    }
}

fn hex(bytes: &[u8]) -> String {
    bytes.iter().map(|byte| format!("{byte:02x}")).collect()
}

/// `addPayloadManifestEntry`. Native applies no redaction.
fn manifest_entry(path: String, codec: Option<&str>, payload: &Value) -> Value {
    let codec = codec.filter(|codec| !codec.is_empty()).unwrap_or("avro");
    let mut entry = Map::new();
    for (key, value) in [
        ("path", json!(path)),
        ("codec", json!(codec)),
        ("available", json!(payload_available(payload))),
        ("redacted", json!(false)),
        ("encoding", json!(encoding(codec))),
        ("avro_framing", Value::Null),
        ("avro_prefix_hex", Value::Null),
        ("writer_schema", Value::Null),
        ("writer_schema_fingerprint", Value::Null),
        ("diagnostic", Value::Null),
    ] {
        entry.insert(key.into(), value);
    }
    if !payload_available(payload) {
        entry.insert("diagnostic".into(), json!("payload_unavailable"));
    } else if let Some(envelope) = external_envelope(payload) {
        entry.insert("codec".into(), json!(text(&envelope["codec"]).unwrap_or(codec)));
        entry.insert("encoding".into(), json!("external-storage-reference"));
        entry.insert("diagnostic".into(), json!("external_storage_reference"));
    } else if let Some(blob) = payload_blob(payload) {
        if codec == "avro" {
            avro_metadata(blob, &mut entry);
        }
    } else {
        entry.insert("diagnostic".into(), json!("payload_missing"));
    }
    Value::Object(entry)
}

/// `payloadManifest`.
fn manifest(bundle: &Value) -> Value {
    let run_codec = text(&bundle["payloads"]["codec"]).unwrap_or("avro");
    let output_codec = text(&bundle["payloads"]["output"]["codec"]).unwrap_or(run_codec);
    let mut entries = vec![
        manifest_entry("workflow.memo_payload".into(), Some("avro"), &bundle["workflow"]["memo_payload"]),
        manifest_entry("payloads.arguments.data".into(), Some(run_codec), &bundle["payloads"]["arguments"]["data"]),
        manifest_entry("payloads.output.data".into(), Some(output_codec), &bundle["payloads"]["output"]["data"]),
    ];
    let section = |name: &str| bundle[name].as_array().cloned().unwrap_or_default();
    for (index, event) in section("history_events").iter().enumerate() {
        let kind = event["type"].as_str().unwrap_or_default();
        let fields: &[&str] = match kind {
            "WorkflowCompleted" => &["output"],
            "ChildRunCompleted" => &["output", "result"],
            _ => continue,
        };
        let payload = &event["payload"];
        let codec = text(&payload["payload_codec"]).or((kind == "WorkflowCompleted").then_some(output_codec));
        for field in fields {
            entries.push(manifest_entry(format!("history_events.{index}.payload.{field}"), codec, &payload[*field]));
        }
    }
    for (index, row) in section("commands").iter().enumerate() {
        entries.push(manifest_entry(format!("commands.{index}.payload"), text(&row["payload_codec"]), &row["payload"]));
    }
    for (index, row) in section("signals").iter().enumerate() {
        entries.push(manifest_entry(format!("signals.{index}.arguments"), text(&row["payload_codec"]), &row["arguments"]));
    }
    for (index, row) in section("updates").iter().enumerate() {
        for field in ["arguments", "result"] {
            entries.push(manifest_entry(format!("updates.{index}.{field}"), text(&row["payload_codec"]), &row[field]));
        }
    }
    for (index, row) in section("activities").iter().enumerate() {
        let codec = text(&row["payload_codec"]).or(Some(run_codec));
        for field in ["arguments", "result"] {
            entries.push(manifest_entry(format!("activities.{index}.{field}"), codec, &row[field]));
        }
    }
    json!({"version": 1, "entries": entries})
}

/// `collectCodecSchemas`: every native payload is Avro.
fn codec_schemas() -> Value {
    json!({"avro": {"encoding": "base64-avro-single-object", "framing": "single_object", "magic_hex": "c301",
        "current_fingerprint": VALUE_FINGERPRINT,
        "writer_schemas": {VALUE_FINGERPRINT: {"schema": crate::codec::VALUE_SCHEMA.trim(),
            "fingerprint": format!("crc64-avro:{VALUE_FINGERPRINT}")}}}})
}

/// `withIntegrity`: SHA-256, and HMAC-SHA-256 when a key is configured, over
/// the bundle's canonical JSON. Native objects keep their keys sorted, so
/// compact serialization is the recursive-ksort canonical form.
fn integrity(bundle: &Value, signing: Option<&Signing>) -> Value {
    let canonical = serde_json::to_string(bundle).expect("JSON values serialize");
    let checksum = hex(&Sha256::digest(canonical.as_bytes()));
    let signature = signing.map(|signing| {
        let mut mac = Hmac::<Sha256>::new_from_slice(signing.key.as_bytes()).expect("HMAC takes any key length");
        mac.update(canonical.as_bytes());
        hex(&mac.finalize().into_bytes())
    });
    json!({"canonicalization": "json-recursive-ksort-v1", "checksum_algorithm": "sha256", "checksum": checksum,
        "signature_algorithm": signing.map(|_| "hmac-sha256"), "signature": signature,
        "key_id": signing.and_then(|signing| signing.key_id.clone())})
}

/// `HistoryExport::forRun`.
pub(super) fn bundle(rows: &Rows, exported_at: &str, signing: Option<&Signing>) -> Value {
    let run = &rows.run;
    let terminal = matches!(run["status"].as_str(), Some("completed" | "failed" | "cancelled" | "terminated"));
    let progress = or(&run["last_progress_at"], &run["updated_at"]);
    let dedupe_key = format!(
        "{}:{}:{}",
        text(&run["id"]).unwrap_or_default(),
        run["last_history_sequence"].as_i64().unwrap_or(0),
        text(&progress).unwrap_or("unknown")
    );
    let activities: Vec<Value> =
        rows.executions.iter().map(|execution| activity(execution, &rows.events, &rows.attempts)).collect();
    let mut bundle = json!({
        "schema": SCHEMA, "schema_version": 2, "exported_at": exported_at, "dedupe_key": dedupe_key,
        "history_complete": terminal, "workflow": workflow(rows), "payloads": payloads(run), "summary": summary(rows),
        "selected_run": {"waits_projection_source": UNAVAILABLE, "timeline_projection_source": UNAVAILABLE,
            "timers_projection_source": "workflow_run_timer_entries_rebuilt", "timers_projection_rebuild_reasons": [],
            "lineage_projection_source": "workflow_run_lineage_entries_rebuilt"},
        "history_events": rows.events.iter().map(history_event).collect::<Vec<_>>(),
        "waits": [], "timeline": [], "linked_intakes_scope": "selected_run", "linked_intakes": [],
        "commands": rows.commands.iter().map(command).collect::<Vec<_>>(),
        "signals": rows.signals.iter().map(signal).collect::<Vec<_>>(),
        "updates": rows.updates.iter().map(update).collect::<Vec<_>>(),
        "tasks": rows.tasks.iter().map(task).collect::<Vec<_>>(),
        "activities": activities,
        "timers": timers(&rows.events),
        "failures": rows.failures.iter().map(failure).collect::<Vec<_>>(),
        "links": {"projection_source": "workflow_run_lineage_entries_rebuilt",
            "parents": rows.parents.iter().map(|row| link(row, run, true)).collect::<Vec<_>>(),
            "children": rows.children.iter().map(|row| link(row, run, false)).collect::<Vec<_>>()},
        "redaction": {"applied": false, "policy": null, "paths": []},
        "codec_schemas": codec_schemas(),
    });
    bundle["payload_manifest"] = manifest(&bundle);
    bundle["integrity"] = integrity(&bundle, signing);
    bundle
}

#[cfg(test)]
mod tests {
    use super::*;

    fn blob(value: crate::codec::Value) -> String {
        crate::codec::ValueCodec::new().unwrap().encode(&value).unwrap()
    }

    #[test]
    fn manifest_reads_the_single_object_frame() {
        let encoded = blob(crate::codec::Value::Long(1));
        let entry = manifest_entry("payloads.arguments.data".into(), Some("avro"), &json!(encoded));
        assert_eq!(entry["avro_framing"], "single_object");
        assert_eq!(entry["avro_prefix_hex"], "c301");
        assert_eq!(entry["writer_schema_fingerprint"], format!("crc64-avro:{VALUE_FINGERPRINT}"));
        assert!(entry["diagnostic"].is_null());
        let missing = manifest_entry("payloads.output.data".into(), Some("avro"), &Value::Null);
        assert_eq!((missing["available"].clone(), missing["diagnostic"].clone()), (json!(false), json!("payload_unavailable")));
        assert_eq!(manifest_entry("x".into(), None, &json!("{}"))["diagnostic"], "json_bytes_labeled_avro");
    }

    #[test]
    fn integrity_covers_the_canonical_bundle() {
        let rows = Rows { run: json!({"id":"r","status":"completed","last_history_sequence":2,"updated_at":"t"}), ..Default::default() };
        let signing = Signing { key: "k".into(), key_id: Some("v1".into()) };
        let signed = bundle(&rows, "now", Some(&signing));
        let mut unsigned = signed.clone();
        unsigned.as_object_mut().unwrap().remove("integrity");
        let canonical = serde_json::to_string(&unsigned).unwrap();
        assert_eq!(signed["integrity"]["checksum"], hex(&Sha256::digest(canonical.as_bytes())));
        assert_eq!(signed["integrity"]["signature_algorithm"], "hmac-sha256");
        assert_eq!(signed["integrity"]["key_id"], "v1");
        assert_eq!(signed["dedupe_key"], "r:2:t");
        assert_eq!(signed["history_complete"], true);
    }
}
