//! `RunWaitProjector::snapshotForRun` (Workflow 2.15.7) for a history export.
//! Native maintains `workflow_run_waits` in the transactions that author each
//! wait's history, so an export reads those rows in PHP's display order.
//! PHP also stores each wait's `RunWaitView` entry as the row payload; native
//! derives those kind-specific fields from the run's history and rows.
use super::history_export::{Column, Rows};
use serde_json::{Map, Value, json};
use std::collections::HashMap;

use Column::{Flag, Int, Json, Text, Time};

pub(super) const WAITS: &[(&str, Column)] = &[
    ("wait_id", Text), ("kind", Text), ("sequence", Int), ("status", Text), ("source_status", Text), ("summary", Text),
    ("opened_at", Time), ("deadline_at", Time), ("resolved_at", Time), ("target_name", Text), ("target_type", Text),
    ("task_backed", Flag), ("external_only", Flag), ("resume_source_kind", Text), ("resume_source_id", Text),
    ("task_id", Text), ("task_type", Text), ("task_status", Text), ("command_id", Text), ("command_sequence", Int),
    ("command_status", Text), ("command_outcome", Text), ("history_authority", Text),
    ("history_unsupported_reason", Text), ("payload", Json),
];
/// `WorkflowRunWait::scopeInDisplayOrder`.
pub(super) const WAITS_SQL: &str = "SELECT w.*,CASE WHEN w.task_backed THEN 1 ELSE 0 END AS task_backed_flag,CASE WHEN w.external_only THEN 1 ELSE 0 END AS external_only_flag FROM workflow_run_waits w WHERE w.workflow_run_id=$1 ORDER BY CASE w.status WHEN 'open' THEN 0 WHEN 'resolved' THEN 1 WHEN 'cancelled' THEN 2 ELSE 99 END,w.position,w.wait_id";

fn string(value: &Value) -> Option<&str> {
    value.as_str().filter(|text| !text.is_empty())
}

fn int(value: &Value) -> Option<i64> {
    match value {
        Value::Number(number) => number.as_i64(),
        Value::String(text) => text.trim().parse().ok(),
        _ => None,
    }
}

/// What the run's history says about one signal or condition wait.
#[derive(Default)]
struct External {
    signal_id: Option<String>,
    timer_id: Option<String>,
    timeout_seconds: Option<i64>,
    timeout_fired_at: Option<Value>,
    condition_key: Option<String>,
    fingerprint: Option<String>,
}

/// `SignalWaits` and `ConditionWaits`: the identities, timeouts and applied
/// signal of each wait, by wait id.
fn externals(events: &[Value]) -> HashMap<String, External> {
    let mut waits: HashMap<String, External> = HashMap::new();
    for event in events {
        let kind = event["event_type"].as_str().unwrap_or_default();
        let payload = &event["payload"];
        let wait_id = string(&payload["signal_wait_id"]).or_else(|| string(&payload["condition_wait_id"]));
        let Some(wait_id) = wait_id else {
            continue;
        };
        let wait = waits.entry(wait_id.to_owned()).or_default();
        match kind {
            "SignalWaitOpened" => wait.timeout_seconds = int(&payload["timeout_seconds"]),
            "ConditionWaitOpened" => {
                wait.condition_key = string(&payload["condition_key"]).map(str::to_owned);
                wait.fingerprint = string(&payload["condition_definition_fingerprint"]).map(str::to_owned);
                wait.timeout_seconds = int(&payload["timeout_seconds"]);
            }
            "SignalReceived" | "SignalApplied" => {
                if let Some(signal) = string(&payload["signal_id"]) {
                    wait.signal_id = Some(signal.to_owned());
                }
            }
            "TimerScheduled" | "TimerFired" | "TimerCancelled"
                if matches!(payload["timer_kind"].as_str(), Some("signal_timeout" | "condition_timeout")) =>
            {
                if let Some(timer) = string(&payload["timer_id"]) {
                    wait.timer_id = Some(timer.to_owned());
                }
                if payload["timer_kind"] == "signal_timeout" {
                    wait.timeout_seconds =
                        int(&payload["delay_seconds"]).or_else(|| int(&payload["timeout_seconds"])).or(wait.timeout_seconds);
                } else {
                    wait.timeout_seconds =
                        wait.timeout_seconds.or_else(|| int(&payload["timeout_seconds"])).or_else(|| int(&payload["delay_seconds"]));
                }
                if kind == "TimerFired" && wait.timeout_fired_at.is_none() {
                    let fired = string(&payload["fired_at"]).map_or_else(|| event["recorded_at"].clone(), |at| json!(at));
                    wait.timeout_fired_at = Some(fired);
                }
            }
            _ => {}
        }
    }
    waits
}

/// `ParallelChildGroup` metadata fields from a payload naming a group path.
fn parallel_fields(entry: &mut Map<String, Value>, path: Vec<Value>) {
    let last = path.last().cloned().unwrap_or(Value::Null);
    for name in ["parallel_group_kind", "parallel_group_id", "parallel_group_base_sequence", "parallel_group_size",
        "parallel_group_index"]
    {
        entry.insert(name.into(), last.get(name).cloned().unwrap_or(Value::Null));
    }
    entry.insert("parallel_group_path".into(), json!(path));
}

/// `RunWaitProjector::snapshotForRun`'s waits: each stored row as
/// `toWaitPayload` returns it. `timers` are the export's rebuilt timers.
pub(super) fn waits(rows: &Rows, timers: &[Value]) -> Vec<Value> {
    let path = super::export_timeline::parallel_path;
    let externals = externals(&rows.events);
    let executions: HashMap<&str, &Value> =
        rows.executions.iter().filter_map(|row| row["id"].as_str().map(|id| (id, row))).collect();
    let timers: HashMap<&str, &Value> = timers.iter().filter_map(|timer| timer["id"].as_str().map(|id| (id, timer))).collect();
    let tasks: HashMap<&str, &Value> = rows.tasks.iter().filter_map(|task| task["id"].as_str().map(|id| (id, task))).collect();
    let scheduled = |kind: &str, field: &str, id: &str| {
        rows.events.iter().find(|event| event["event_type"] == kind && event["payload"][field] == id).map(|event| &event["payload"])
    };
    rows.waits
        .iter()
        .map(|row| {
            let kind = row["kind"].as_str().unwrap_or_default();
            let wait_id = row["wait_id"].as_str().unwrap_or_default();
            let mut entry = row["payload"].as_object().cloned().unwrap_or_default();
            let unsupported = !row["history_unsupported_reason"].is_null();
            let diagnostic = unsupported || row["history_authority"] == "mutable_open_fallback";
            match kind {
                "activity" => {
                    let execution = string(&row["resume_source_id"]).and_then(|id| executions.get(id));
                    entry.insert("diagnostic_only".into(), json!(diagnostic));
                    entry.insert("row_status".into(), match execution {
                        Some(execution) if diagnostic => execution["status"].clone(),
                        _ => Value::Null,
                    });
                    let group = execution.map_or(Value::Null, |execution| json!({"parallel_group_path": execution["parallel_group_path"]}));
                    parallel_fields(&mut entry, path(&group));
                }
                "timer" => {
                    let id = wait_id.strip_prefix("timer:").unwrap_or(wait_id);
                    entry.insert("diagnostic_only".into(), json!(diagnostic));
                    entry.insert("row_status".into(), timers.get(id).map_or(Value::Null, |timer| timer["status"].clone()));
                    let payload = scheduled("TimerScheduled", "timer_id", id).cloned().unwrap_or(Value::Null);
                    parallel_fields(&mut entry, path(&payload));
                }
                "child" => {
                    let call = wait_id.strip_prefix("child:").unwrap_or(wait_id);
                    entry.insert("child_call_id".into(), json!(call));
                    entry.insert("child_workflow_run_id".into(), row["resume_source_id"].clone());
                    entry.insert("diagnostic_only".into(), json!(diagnostic));
                    let payload = scheduled("ChildWorkflowScheduled", "child_call_id", call).cloned().unwrap_or(Value::Null);
                    parallel_fields(&mut entry, path(&payload));
                }
                "signal" => {
                    let external = externals.get(wait_id);
                    entry.insert("signal_wait_id".into(), json!(wait_id));
                    entry.insert("signal_id".into(), json!(external.and_then(|wait| wait.signal_id.clone())));
                    entry.insert("timer_id".into(), json!(external.and_then(|wait| wait.timer_id.clone())));
                    entry.insert("timeout_seconds".into(), json!(external.and_then(|wait| wait.timeout_seconds)));
                    entry.insert("timeout_fired_at".into(),
                        external.and_then(|wait| wait.timeout_fired_at.clone()).unwrap_or(Value::Null));
                }
                "condition" => {
                    let external = externals.get(wait_id);
                    entry.insert("condition_wait_id".into(), json!(wait_id));
                    entry.insert("condition_key".into(),
                        json!(external.and_then(|wait| wait.condition_key.clone()).or_else(|| string(&row["target_name"]).map(str::to_owned))));
                    entry.insert("condition_definition_fingerprint".into(), json!(external.and_then(|wait| wait.fingerprint.clone())));
                    entry.insert("timeout_seconds".into(), json!(external.and_then(|wait| wait.timeout_seconds)));
                    entry.insert("timeout_fired_at".into(),
                        external.and_then(|wait| wait.timeout_fired_at.clone()).unwrap_or(Value::Null));
                }
                "update" => {
                    let update_id = if row["resume_source_kind"] == "workflow_update" { row["resume_source_id"].clone() } else { Value::Null };
                    entry.insert("update_id".into(), update_id.clone());
                    entry.insert("ordering_state".into(), ordering_state(rows, &update_id, &row["source_status"]));
                }
                _ => {}
            }
            for name in ["kind", "sequence", "status", "source_status", "summary", "opened_at", "deadline_at", "resolved_at",
                "target_name", "target_type", "task_backed", "external_only", "resume_source_kind", "resume_source_id",
                "task_id", "task_type", "task_status", "command_id", "command_sequence", "command_status",
                "command_outcome", "history_authority", "history_unsupported_reason"]
            {
                entry.insert(name.into(), row[name].clone());
            }
            entry.insert("id".into(), json!(wait_id));
            // `RunWaitView` reads each wait's task as it is now, and only
            // activity, timer and child waits carry a history authority.
            if let Some(task) = string(&row["task_id"]).and_then(|id| tasks.get(id)) {
                entry.insert("task_status".into(), task["status"].clone());
                entry.insert("task_backed".into(), json!(matches!(task["status"].as_str(), Some("ready" | "leased"))));
            }
            if matches!(kind, "signal" | "condition" | "update") {
                entry.insert("history_authority".into(), Value::Null);
            }
            let fired = entry.get("timeout_fired_at").cloned().unwrap_or(Value::Null);
            entry.insert("timeout_fired_at".into(), if string(&fired).is_some() { fired } else { Value::Null });
            Value::Object(entry)
        })
        .collect()
}

/// `RunUpdateView::withCurrentOrderingState`: an accepted update is queued
/// behind an earlier signal that is not applied yet.
fn ordering_state(rows: &Rows, update_id: &Value, source_status: &Value) -> Value {
    match source_status.as_str() {
        Some("completed") => return json!("applied"),
        Some("failed") => return json!("failed"),
        Some("rejected") => return json!("rejected"),
        Some("accepted") => {}
        _ => return json!("closed"),
    }
    let sequence = rows.updates.iter().find(|update| &update["id"] == update_id).and_then(|update| int(&update["command_sequence"]));
    let blocked = sequence.is_some_and(|sequence| {
        rows.signals.iter().any(|signal| {
            int(&signal["command_sequence"]).is_some_and(|earlier| earlier < sequence)
                && !matches!(signal["status"].as_str(), Some("applied" | "rejected"))
        })
    });
    json!(if blocked { "queued" } else { "ready" })
}
