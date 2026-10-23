//! Metadata projection compatible with the released RunActivityView. Listing
//! attempt flags describe recorded state; they do not authorize worker actions.

use chrono::{DateTime, SecondsFormat, Utc};
use serde_json::{Value, json};
use std::collections::BTreeMap;

const TERMINAL_WITHOUT_HISTORY: &str = "terminal_activity_row_without_typed_history";

fn string(value: &Value) -> Option<&str> {
    value.as_str().filter(|value| !value.is_empty())
}

fn array(value: &Value) -> bool {
    value.is_array() || value.is_object()
}

fn first(values: &[&Value]) -> Value {
    values
        .iter()
        .find(|value| !value.is_null())
        .map_or(Value::Null, |value| (*value).clone())
}

fn first_string(values: &[&Value]) -> Value {
    values
        .iter()
        .find_map(|value| string(value))
        .map_or(Value::Null, |value| json!(value))
}

fn numeric_int(value: &Value) -> Option<i64> {
    value.as_i64().or_else(|| {
        let value = value
            .as_str()?
            .trim_matches(|c: char| c.is_ascii_whitespace());
        value.parse::<i64>().ok().or_else(|| {
            value
                .parse::<f64>()
                .ok()
                .filter(|v| v.is_finite())
                .map(|v| v as i64)
        })
    })
}

fn timestamp(value: &Value) -> Value {
    string(value)
        .and_then(|v| DateTime::parse_from_rfc3339(v).ok())
        .map_or(Value::Null, |v| {
            json!(
                v.with_timezone(&Utc)
                    .to_rfc3339_opts(SecondsFormat::Micros, true)
            )
        })
}

fn millis(value: &Value) -> i64 {
    string(value)
        .and_then(|v| DateTime::parse_from_rfc3339(v).ok())
        // Carbon's Uu -> float conversion and PHP 8.3's decimal pre-rounding
        // both affect ties. Integer truncation or f64::round alone differs.
        .map_or(i64::MAX, |v| {
            let raw = format!("{}{:06}", v.timestamp(), v.timestamp_subsec_micros());
            let value = raw.parse::<f64>().unwrap() / 1000.0;
            let half_up = |v: f64| {
                if v < 0.0 {
                    (v - 0.5).ceil()
                } else {
                    (v + 0.5).floor()
                }
            };
            if value == 0.0 {
                return 0;
            }
            let precision = 14 - value.abs().log10().floor() as i32;
            let rounded = if precision > 0 && precision < 15 {
                let factor = 10_f64.powi(precision);
                half_up(half_up(value * factor) / factor)
            } else if value.abs() >= 1e15 {
                value
            } else {
                half_up(value)
            };
            rounded as i64
        })
}

fn merge(target: &mut Value, source: &Value, missing_only: bool, keep_null: bool) {
    if let Some(source) = source.as_object() {
        for (key, value) in source {
            if (!value.is_null() || keep_null) && (!missing_only || target.get(key).is_none()) {
                target[key] = value.clone();
            }
        }
    }
}

fn copy_strings(target: &mut Value, source: &Value, fields: &[&str]) {
    for field in fields {
        if let Some(value) = string(&source[field]) {
            target[field] = json!(value);
        }
    }
}

fn copy_ints(target: &mut Value, source: &Value, fields: &[&str]) {
    for field in fields {
        if let Some(value) = source[field].as_i64() {
            target[field] = json!(value);
        }
    }
}

fn local(value: &Value) -> bool {
    value["execution_mode"] == "local" || value["local_activity"] == true
}

fn activity_status(event_type: &str) -> Option<&'static str> {
    Some(match event_type {
        "ActivityScheduled" | "ActivityRetryScheduled" => "pending",
        "ActivityStarted" | "ActivityHeartbeatRecorded" => "running",
        "ActivityCompleted" => "completed",
        "ActivityFailed" | "ActivityTimedOut" => "failed",
        "ActivityCancelled" => "cancelled",
        _ => return None,
    })
}

fn group(entry: &Value) -> Option<Value> {
    let id = entry["parallel_group_id"].as_str()?;
    let kind = entry["parallel_group_kind"].as_str().or_else(|| {
        [
            ("parallel-activities:", "activity"),
            ("parallel-calls:", "mixed"),
            ("parallel-timers:", "timer"),
            ("parallel-children:", "child"),
            ("select-calls:", "mixed"),
        ]
        .into_iter()
        .find_map(|(prefix, kind)| id.starts_with(prefix).then_some(kind))
    })?;
    let base = entry["parallel_group_base_sequence"].as_i64()?;
    let size = entry["parallel_group_size"].as_i64().filter(|v| *v >= 1)?;
    let index = entry["parallel_group_index"].as_i64()?;
    let select = string(&entry["parallel_group_mode"])
        .map_or_else(|| id.starts_with("select-calls:"), |mode| mode == "select");
    let key = &entry["selection_member_key"];
    if select && !(string(key).is_some() || key.as_i64().is_some_and(|v| v >= 0)) {
        return None;
    }
    let mut result = json!({"parallel_group_id":id,"parallel_group_kind":kind,
        "parallel_group_base_sequence":base,"parallel_group_size":size,"parallel_group_index":index});
    if select {
        result["parallel_group_mode"] = json!("select");
        result["selection_member_key"] = key.clone();
    }
    copy_ints(
        &mut result,
        entry,
        &[
            "selection_member_index",
            "selection_member_base_sequence",
            "selection_member_size",
        ],
    );
    copy_strings(&mut result, entry, &["selection_member_kind"]);
    Some(result)
}

fn path(value: &Value) -> Vec<Value> {
    let entries: Vec<_> = match value["parallel_group_path"].as_array() {
        Some(entries) => entries.iter().filter_map(group).collect(),
        None => value["parallel_group_path"]
            .as_object()
            .map(|entries| entries.values().filter_map(group).collect())
            .unwrap_or_default(),
    };
    if entries.is_empty() {
        group(value).into_iter().collect()
    } else {
        entries
    }
}

fn event_activity(event: &Value) -> Option<Value> {
    let kind = event["event_type"].as_str()?;
    let status = activity_status(kind)?;
    let payload = &event["payload"];
    let nested = &payload["activity"];
    let id = first_string(&[&nested["id"], &payload["activity_execution_id"]]);
    string(&id)?;
    let mut snapshot = json!({"id":id,"local_activity":local(payload)});
    for (target, source) in [
        ("idempotency_key", "idempotency_key"),
        ("type", "activity_type"),
        ("class", "activity_class"),
        ("execution_mode", "execution_mode"),
    ] {
        if let Some(value) = string(&payload[source]) {
            snapshot[target] = json!(value);
        }
    }
    copy_strings(
        &mut snapshot,
        payload,
        &[
            "reused_from_run_id",
            "reused_activity_execution_id",
            "reused_recorded_at",
            "parallel_group_id",
            "parallel_group_kind",
        ],
    );
    copy_ints(
        &mut snapshot,
        payload,
        &[
            "sequence",
            "parallel_group_base_sequence",
            "parallel_group_size",
            "parallel_group_index",
        ],
    );
    if let Some(value) = payload["attempt_number"].as_i64() {
        snapshot["attempt_count"] = json!(value);
    }
    if array(&payload["retry_policy"]) {
        snapshot["retry_policy"] = payload["retry_policy"].clone();
    }
    let event_progress = progress(&payload["progress"]);
    if !event_progress.is_null() {
        snapshot["last_heartbeat_progress"] = event_progress;
    }
    let group_path = path(payload);
    if !group_path.is_empty() {
        snapshot["parallel_group_path"] = json!(group_path);
    }
    let time_field = match kind {
        "ActivityScheduled" => Some("created_at"),
        "ActivityStarted" => Some("started_at"),
        "ActivityCompleted" | "ActivityFailed" | "ActivityCancelled" | "ActivityTimedOut" => {
            Some("closed_at")
        }
        _ => None,
    };
    if let Some(field) = time_field {
        snapshot[field] = timestamp(&event["recorded_at"]);
    }
    copy_strings(
        &mut snapshot,
        nested,
        &[
            "id",
            "idempotency_key",
            "type",
            "class",
            "execution_mode",
            "attempt_id",
            "status",
            "connection",
            "queue",
            "created_at",
            "started_at",
            "closed_at",
            "last_heartbeat_at",
            "reused_from_run_id",
            "reused_activity_execution_id",
            "reused_recorded_at",
            "parallel_group_id",
            "parallel_group_kind",
        ],
    );
    copy_ints(
        &mut snapshot,
        nested,
        &[
            "sequence",
            "attempt_count",
            "parallel_group_base_sequence",
            "parallel_group_size",
            "parallel_group_index",
        ],
    );
    if nested.get("execution_mode").is_some() || nested.get("local_activity").is_some() {
        snapshot["local_activity"] = json!(local(nested));
    }
    if array(&nested["retry_policy"]) {
        snapshot["retry_policy"] = nested["retry_policy"].clone();
    }
    let nested_path = path(nested);
    if !nested_path.is_empty() {
        snapshot["parallel_group_path"] = json!(nested_path);
    }
    let nested_progress = progress(&nested["last_heartbeat_progress"]);
    if !nested_progress.is_null() {
        snapshot["last_heartbeat_progress"] = nested_progress;
    }
    if string(&snapshot["status"]).is_none() {
        snapshot["status"] = json!(status);
    }
    let count = if !local(&snapshot) && kind != "ActivityScheduled" {
        payload["task"]["attempt_count"].as_i64()
    } else {
        None
    }
    .or_else(|| snapshot["attempt_count"].as_i64());
    snapshot["attempt_count"] = json!(if (kind == "ActivityScheduled"
        || (snapshot["status"] == "pending" && string(&snapshot["started_at"]).is_none()))
        && count.is_none_or(|v| v <= 1)
    {
        0
    } else {
        count.filter(|v| *v > 0).unwrap_or(1)
    });
    Some(snapshot)
}

fn event_attempt(event: &Value) -> Option<(String, String, Value)> {
    let kind = event["event_type"].as_str()?;
    let status = match kind {
        "ActivityStarted" | "ActivityHeartbeatRecorded" => "running",
        "ActivityRetryScheduled" | "ActivityFailed" => "failed",
        "ActivityCompleted" => "completed",
        "ActivityTimedOut" => "expired",
        "ActivityCancelled" => "cancelled",
        _ => return None,
    };
    let p = &event["payload"];
    let a = &p["activity_attempt"];
    let activity_id = first_string(&[
        &p["activity_execution_id"],
        &p["activity"]["id"],
        &a["activity_execution_id"],
    ]);
    let attempt_id = first_string(&[
        &a["id"],
        &p["activity_attempt_id"],
        &p["activity"]["attempt_id"],
    ]);
    let (activity_id, attempt_id) = (
        string(&activity_id)?.to_owned(),
        string(&attempt_id)?.to_owned(),
    );
    let terminal = status != "running";
    let mut result = json!({"id":attempt_id,"status":status});
    for (field, values) in [
        (
            "worker_attempt_id",
            vec![&a["worker_attempt_id"], &p["worker_attempt_id"]],
        ),
        (
            "workflow_task_id",
            vec![&a["task_id"], &event["workflow_task_id"], &p["task"]["id"]],
        ),
        (
            "lease_owner",
            vec![&a["lease_owner"], &p["task"]["lease_owner"]],
        ),
    ] {
        let value = first_string(&values);
        if !value.is_null() {
            result[field] = value;
        }
    }
    if let Some(number) = [
        &a["attempt_number"],
        &p["attempt_number"],
        &p["retry_after_attempt"],
        &p["activity"]["attempt_count"],
        &p["task"]["attempt_count"],
    ]
    .into_iter()
    .find_map(numeric_int)
    {
        result["attempt_number"] = json!(number);
    }
    let event_time = first(&[&event["recorded_at"], &event["created_at"]]);
    let mut started = first_string(&[&a["started_at"]]);
    if started.is_null() && kind == "ActivityStarted" {
        started = event_time.clone();
    }
    let mut closed = first_string(&[&a["closed_at"]]);
    if closed.is_null() && terminal {
        closed = event_time;
    }
    for (field, value) in [
        ("started_at", started),
        ("closed_at", closed),
        (
            "last_heartbeat_at",
            first_string(&[&p["heartbeat_at"], &a["last_heartbeat_at"]]),
        ),
        (
            "lease_expires_at",
            first_string(&[
                &p["lease_expires_at"],
                &a["lease_expires_at"],
                &p["task"]["lease_expires_at"],
            ]),
        ),
    ] {
        if !value.is_null() {
            result[field] = value;
        }
    }
    let progress = first(&[
        &progress(&p["progress"]),
        &progress(&a["last_heartbeat_progress"]),
    ]);
    if !progress.is_null() {
        result["last_heartbeat_progress"] = progress;
    }
    // A null terminal lease is authoritative and cannot be restored by a stale row.
    if terminal {
        result["lease_expires_at"] = Value::Null;
    }
    Some((activity_id, attempt_id, result))
}

fn row_activity(row: &Value) -> Value {
    let open = matches!(string(&row["status"]), Some("pending" | "running"));
    let mut result = json!({"id":row["id"],"idempotency_key":row["id"],"sequence":row["sequence"],
        "type":row["activity_type"],"class":row["activity_class"],"row_status":row["status"],
        "status":row["status"],"history_event_types":[],"local_activity":row["activity_options"]["execution_mode"] == "local",
        "history_authority":if open {"mutable_open_fallback"} else {"unsupported_terminal_without_history"}});
    copy_strings(&mut result, row, &["connection", "queue"]);
    result["attempt_id"] = first_string(&[&row["current_attempt_id"]]);
    result["execution_mode"] = first_string(&[&row["activity_options"]["execution_mode"]]);
    result["retry_policy"] = row["retry_policy"].clone();
    let count = row["attempt_count"].as_i64().unwrap_or(0);
    result["attempt_count"] = json!(if row["status"] == "pending"
        && row["started_at"].is_null()
        && count <= 1
    {
        0
    } else {
        count.max(1)
    });
    for field in ["created_at", "started_at", "last_heartbeat_at"] {
        result[field] = timestamp(&row[field]);
    }
    if open {
        result["closed_at"] = timestamp(&row["closed_at"]);
    } else {
        result["history_unsupported_reason"] = json!(TERMINAL_WITHOUT_HISTORY);
    }
    let stored_path = &row["parallel_group_path"];
    let path = if stored_path.get("parallel_group_id").is_some() {
        group(stored_path).into_iter().collect()
    } else {
        path(row)
    };
    if let Some(last) = path.last() {
        merge(&mut result, last, false, false);
    }
    if !path.is_empty() {
        result["parallel_group_path"] = json!(path);
    }
    result
}

fn row_attempt(row: &Value) -> Value {
    let mut result = json!({});
    for field in [
        "id",
        "worker_attempt_id",
        "workflow_task_id",
        "attempt_number",
        "status",
        "lease_owner",
    ] {
        if !row[field].is_null() {
            result[field] = row[field].clone();
        }
    }
    for field in [
        "started_at",
        "last_heartbeat_at",
        "lease_expires_at",
        "closed_at",
    ] {
        let value = timestamp(&row[field]);
        if !value.is_null() {
            result[field] = value;
        }
    }
    result
}

fn present_attempt(attempt: &Value, execution: Option<&Value>) -> Value {
    let status = string(&attempt["status"]);
    let can_continue = execution.map_or(status == Some("running"), |row| {
        first_string(&[&attempt["id"]]) == first_string(&[&row["current_attempt_id"]])
            && numeric_int(&attempt["attempt_number"]) == numeric_int(&row["attempt_count"])
            && row["status"] == "running"
            && status == Some("running")
    });
    let cancelled =
        status == Some("cancelled") || execution.is_some_and(|row| row["status"] == "cancelled");
    let reason = if status == Some("cancelled") {
        Some("attempt_cancelled")
    } else if cancelled {
        Some("activity_cancelled")
    } else if status == Some("expired") {
        Some("attempt_expired")
    } else if matches!(status, Some("completed" | "failed")) {
        Some("attempt_closed")
    } else if execution
        .is_some_and(|row| matches!(string(&row["status"]), Some("completed" | "failed")))
    {
        Some("activity_closed")
    } else if !can_continue {
        Some("stale_attempt")
    } else {
        None
    };
    let mut result = json!({"id":attempt["id"],"worker_attempt_id":attempt["worker_attempt_id"],
        "attempt_number":attempt["attempt_number"],"status":attempt["status"],"task_id":attempt["workflow_task_id"],
        "lease_owner":attempt["lease_owner"],"last_heartbeat_progress":progress(&attempt["last_heartbeat_progress"]),
        "can_continue":can_continue,"cancel_requested":cancelled,"stop_reason":reason});
    for field in [
        "started_at",
        "last_heartbeat_at",
        "lease_expires_at",
        "closed_at",
    ] {
        result[field] = timestamp(&attempt[field]);
    }
    result
}

fn synthetic_attempt(state: &Value, row: &Value) -> Option<Value> {
    let id = first_string(&[&state["attempt_id"], &row["current_attempt_id"]]);
    string(&id)?;
    let count = state["attempt_count"]
        .as_i64()
        .or_else(|| row["attempt_count"].as_i64())
        .filter(|v| *v > 0)?;
    let status = first(&[&state["status"], &row["status"]]);
    let can_continue = if row.is_null() {
        state["status"] == "running"
    } else {
        first_string(&[&state["attempt_id"]]) == first_string(&[&row["current_attempt_id"]])
            && row["status"] == "running"
            && state["status"] == "running"
    };
    let cancelled = state["status"] == "cancelled" || row["status"] == "cancelled";
    let reason = if cancelled {
        Some("activity_cancelled")
    } else if matches!(string(&status), Some("completed" | "failed")) {
        Some("attempt_closed")
    } else if status == "pending" || row["status"] == "pending" {
        None
    } else if !can_continue {
        Some("stale_attempt")
    } else {
        None
    };
    let mut result = json!({"id":id,"attempt_number":count,"status":status,"task_id":null,
        "lease_owner":null,"lease_expires_at":null,"can_continue":can_continue,
        "cancel_requested":cancelled,"stop_reason":reason,
        "last_heartbeat_progress":progress(&state["last_heartbeat_progress"])});
    for field in ["started_at", "last_heartbeat_at", "closed_at"] {
        result[field] = first(&[&state[field], &row[field]]);
    }
    Some(result)
}

fn order_key(value: &Value, number: &str, time: &str) -> (i64, i64, String) {
    (
        value[number].as_i64().unwrap_or(i64::MAX),
        millis(&value[time]),
        value["id"].as_str().unwrap_or("").to_owned(),
    )
}

pub(super) fn project(events: &[Value], executions: &[Value]) -> Value {
    let executions_by_id: BTreeMap<_, _> = executions
        .iter()
        .filter_map(|row| string(&row["id"]).map(|id| (id, row)))
        .collect();
    let mut states: BTreeMap<String, Value> = BTreeMap::new();
    let mut attempts: BTreeMap<String, BTreeMap<String, Value>> = BTreeMap::new();
    let mut events: Vec<_> = events.iter().collect();
    events.sort_by_key(|event| event["sequence"].as_i64().unwrap_or(0));
    for event in events {
        if let Some(snapshot) = event_activity(event) {
            let id = string(&snapshot["id"]).unwrap().to_owned();
            let state = states
                .entry(id.clone())
                .or_insert_with(|| json!({"id":id,"history_event_types":[]}));
            state["history_authority"] = json!("typed_history");
            if !state["history_event_types"]
                .as_array()
                .unwrap()
                .contains(&event["event_type"])
            {
                state["history_event_types"]
                    .as_array_mut()
                    .unwrap()
                    .push(event["event_type"].clone());
            }
            merge(state, &snapshot, false, false);
        }
        if let Some((activity, id, snapshot)) = event_attempt(event) {
            let state = attempts
                .entry(activity)
                .or_default()
                .entry(id)
                .or_insert_with(|| json!({}));
            merge(state, &snapshot, false, true);
        }
    }
    for row in executions {
        let Some(id) = string(&row["id"]) else {
            continue;
        };
        states
            .entry(id.to_owned())
            .or_insert_with(|| row_activity(row));
        for attempt in row["attempts"].as_array().into_iter().flatten() {
            let Some(attempt_id) = string(&attempt["id"]) else {
                continue;
            };
            let state = attempts
                .entry(id.to_owned())
                .or_default()
                .entry(attempt_id.to_owned())
                .or_insert_with(|| json!({}));
            merge(state, &row_attempt(attempt), true, false);
        }
    }
    let null = Value::Null;
    let mut activities = Vec::new();
    for (id, state) in states {
        let execution = executions_by_id.get(id.as_str()).copied();
        let row = execution.unwrap_or(&null);
        let mut presented: Vec<_> = attempts
            .get(&id)
            .into_iter()
            .flat_map(BTreeMap::values)
            .map(|a| present_attempt(a, execution))
            .collect();
        if let Some(synthetic) = synthetic_attempt(&state, row)
            && !presented.iter().any(|a| a["id"] == synthetic["id"])
        {
            presented.push(synthetic);
        }
        presented.sort_by_cached_key(|a| order_key(a, "attempt_number", "started_at"));
        let latest = presented.last().unwrap_or(&null);
        let unsupported = state["history_unsupported_reason"] == TERMINAL_WITHOUT_HISTORY;
        let status = if unsupported {
            json!("unsupported")
        } else {
            first(&[&state["status"], &json!("pending")])
        };
        let mut result = json!({"id":id,"idempotency_key":first(&[&state["idempotency_key"], &state["id"]]),
            "execution_mode":first_string(&[&state["execution_mode"]]),"local_activity":local(&state),
            "history_authority":state["history_authority"],
            "diagnostic_only":string(&state["history_authority"]).is_some_and(|s| s != "typed_history"),
            "history_event_types":state["history_event_types"],"status":status,
            "attempt_id":first(&[&state["attempt_id"], &latest["id"]]),"worker_attempt_id":latest["worker_attempt_id"],
            "attempt_count":state["attempt_count"].as_i64().or_else(|| latest["attempt_number"].as_i64()).unwrap_or(0),
            "retry_policy":first(&[&state["retry_policy"], &row["retry_policy"]]),
            "parallel_group_path":first(&[&state["parallel_group_path"], &json!([])]),
            "last_heartbeat_at":first(&[&state["last_heartbeat_at"], &latest["last_heartbeat_at"]]),
            "last_heartbeat_progress":progress(&first(&[&state["last_heartbeat_progress"], &latest["last_heartbeat_progress"]])),
            "started_at":first(&[&state["started_at"], &latest["started_at"]]),
            "closed_at":if matches!(string(&status), Some("completed" | "failed" | "cancelled")) {
                first(&[&state["closed_at"], &latest["closed_at"]])
            } else {Value::Null},"attempts":presented});
        for field in [
            "sequence",
            "type",
            "class",
            "history_unsupported_reason",
            "reused_from_run_id",
            "reused_activity_execution_id",
            "reused_recorded_at",
            "row_status",
            "parallel_group_kind",
            "parallel_group_id",
            "parallel_group_base_sequence",
            "parallel_group_size",
            "parallel_group_index",
            "connection",
            "queue",
            "created_at",
        ] {
            result[field] = state[field].clone();
        }
        activities.push(result);
    }
    activities.sort_by_cached_key(|a| order_key(a, "sequence", "created_at"));
    json!(activities)
}

fn trimmed(value: &Value, max: usize) -> Option<String> {
    let value = value
        .as_str()?
        .trim_matches([' ', '\t', '\r', '\n', '\0', '\u{b}']);
    (!value.is_empty() && value.chars().count() <= max).then(|| value.to_owned())
}

fn number(value: &Value) -> Option<Value> {
    if let Some(value) = value.as_i64() {
        return (value >= 0).then(|| json!(value));
    }
    if let Some(value) = value.as_u64() {
        return Some(json!(value));
    }
    let parsed = if let Some(text) = value.as_str() {
        let text = text.trim_matches(|c: char| c.is_ascii_whitespace());
        if !text.contains('.')
            && let Ok(number) = text.parse::<i64>()
        {
            return (number >= 0).then(|| json!(number));
        }
        text.parse::<f64>().ok()?
    } else {
        value.as_f64()?
    };
    if !parsed.is_finite() || parsed < 0.0 {
        return None;
    }
    Some(if parsed.fract() == 0.0 {
        json!(parsed as i64)
    } else {
        json!(parsed)
    })
}

fn progress(value: &Value) -> Value {
    if !array(value) {
        return Value::Null;
    }
    let mut result = json!({});
    if let Some(value) = trimmed(&value["message"], 280) {
        result["message"] = json!(value);
    }
    for field in ["current", "total"] {
        if let Some(number) = number(&value[field]) {
            result[field] = number;
        }
    }
    if (result.get("current").is_some() || result.get("total").is_some())
        && let Some(unit) = trimmed(&value["unit"], 64)
    {
        result["unit"] = json!(unit);
    }
    let mut details = json!({});
    if let Some(entries) = value["details"].as_object() {
        for (key, value) in entries {
            if key.is_empty()
                || key.len() > 64
                || !key
                    .bytes()
                    .all(|b| b.is_ascii_alphanumeric() || b"_.:-".contains(&b))
                || key
                    .parse::<i64>()
                    .ok()
                    .is_some_and(|v| v.to_string() == *key)
            {
                continue;
            }
            let value = if value.is_string() {
                trimmed(value, 191).map(|s| json!(s))
            } else if value.is_null() || value.is_boolean() || value.as_i64().is_some() {
                Some(value.clone())
            } else if let Some(v) = value.as_f64().filter(|v| v.is_finite()) {
                Some(if v.fract() == 0.0 {
                    json!(v as i64)
                } else {
                    json!(v)
                })
            } else {
                None
            };
            if let Some(value) = value {
                details[key] = value;
            }
        }
    }
    if !details.as_object().unwrap().is_empty() {
        result["details"] = details;
    }
    if result.as_object().unwrap().is_empty() {
        Value::Null
    } else {
        result
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn released_php_metadata_projection_corpus() {
        let fixture: Value = serde_json::from_str(include_str!(
            "../../../tests/Fixtures/ActivityMetadataProjection/v1.json"
        ))
        .unwrap();
        let cases = fixture["cases"].as_array().unwrap();
        assert_eq!(cases.len(), 32);
        for case in cases {
            let input = &case["input"];
            assert_eq!(
                project(
                    input["events"].as_array().unwrap(),
                    input["executions"].as_array().unwrap()
                ),
                case["expected"],
                "{}",
                case["name"]
            );
        }
    }
}
