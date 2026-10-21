//! Schedule definitions as published PHP Server accepts and stores them
//! (`ScheduleController::store`/`update` and `ScheduleManager`).
use super::laravel_rules::{Rule, Rules, normalize, validate, validated};
use super::{Result, RuntimeError, refuse, schedule_cron::Cron, schedule_spec};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};

pub(crate) const OVERLAP_POLICIES: &[&str] = &[
    "skip",
    "buffer_one",
    "buffer_all",
    "cancel_other",
    "terminate_other",
    "allow_all",
];

/// `ScheduleController::storeRules`, in order.
const STORE_RULES: &Rules = &[
    ("schedule_id", &[Rule::Nullable, Rule::String, Rule::Max(128)]),
    ("spec", &[Rule::Required, Rule::Array]),
    ("spec.cron_expressions", &[Rule::Nullable, Rule::Array]),
    ("spec.cron_expressions.*", &[Rule::String]),
    ("spec.intervals", &[Rule::Nullable, Rule::Array]),
    (
        "spec.intervals.*.every",
        &[Rule::RequiredWith("spec.intervals"), Rule::String, Rule::Max(64)],
    ),
    ("spec.intervals.*.offset", &[Rule::Nullable, Rule::String, Rule::Max(64)]),
    ("spec.timezone", &[Rule::Nullable, Rule::String, Rule::Max(64)]),
    ("action", &[Rule::Required, Rule::Array]),
    ("action.workflow_type", &[Rule::Required, Rule::String]),
    ("action.task_queue", &[Rule::Nullable, Rule::String]),
    ("action.input", &[Rule::Nullable, Rule::Array]),
    ("action.execution_timeout_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
    ("action.run_timeout_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
    ("action.workflow_execution_timeout", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
    ("action.workflow_run_timeout", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
    ("overlap_policy", &[Rule::Nullable, Rule::String, Rule::In(OVERLAP_POLICIES)]),
    ("jitter_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(0), Rule::Max(3600)]),
    ("max_runs", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
    ("memo", &[Rule::Nullable, Rule::Array]),
    ("search_attributes", &[Rule::Nullable, Rule::Array]),
    ("paused", &[Rule::Nullable, Rule::Boolean]),
    ("note", &[Rule::Nullable, Rule::String, Rule::Max(1000)]),
];

/// `ScheduleController::updateRules`, in order.
const UPDATE_RULES: &Rules = &[
    ("spec", &[Rule::Nullable, Rule::Array]),
    ("spec.cron_expressions", &[Rule::Nullable, Rule::Array]),
    ("spec.cron_expressions.*", &[Rule::String]),
    ("spec.intervals", &[Rule::Nullable, Rule::Array]),
    (
        "spec.intervals.*.every",
        &[Rule::RequiredWith("spec.intervals"), Rule::String, Rule::Max(64)],
    ),
    ("spec.intervals.*.offset", &[Rule::Nullable, Rule::String, Rule::Max(64)]),
    ("spec.timezone", &[Rule::Nullable, Rule::String, Rule::Max(64)]),
    ("action", &[Rule::Nullable, Rule::Array]),
    ("action.workflow_type", &[Rule::Nullable, Rule::String]),
    ("action.task_queue", &[Rule::Nullable, Rule::String]),
    ("action.input", &[Rule::Nullable, Rule::Array]),
    ("action.execution_timeout_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
    ("action.run_timeout_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
    ("action.workflow_execution_timeout", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
    ("action.workflow_run_timeout", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
    ("overlap_policy", &[Rule::Nullable, Rule::String, Rule::In(OVERLAP_POLICIES)]),
    ("jitter_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(0), Rule::Max(3600)]),
    ("max_runs", &[Rule::Nullable, Rule::Integer, Rule::Min(1)]),
    ("memo", &[Rule::Nullable, Rule::Array]),
    ("search_attributes", &[Rule::Nullable, Rule::Array]),
    ("note", &[Rule::Nullable, Rule::String, Rule::Max(1000)]),
];

/// A validated schedule update: each field PHP's `ScheduleManager::update`
/// changes, `None` where it keeps the stored value.
pub(crate) struct Changes {
    pub(crate) spec: Option<Value>,
    /// The fields to merge over the stored action.
    pub(crate) action: Option<Value>,
    pub(crate) overlap_policy: Option<String>,
    pub(crate) jitter_seconds: Option<i64>,
    pub(crate) note: Option<String>,
    /// `Some(None)` clears the memo.
    pub(crate) memo: Option<Option<Value>>,
    /// The body named `search_attributes` (empty: native stores none).
    pub(crate) clears_search_attributes: bool,
    pub(crate) max_runs: Option<i64>,
}

/// Validates an update body as PHP's `update` does, after the schedule is
/// found.
pub(crate) fn changes(body: &Value) -> Result<Changes> {
    let body = normalize(body);
    validate(&body, UPDATE_RULES)?;
    let validated = validated(&body, UPDATE_RULES);
    memo_size(&validated["memo"])?;
    if !empty(&validated["search_attributes"]) {
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "schedule_search_attributes_unavailable",
        ));
    }
    let action = Some(validated["action"].clone()).filter(|action| !action.is_null());
    if let Some(input) = action.as_ref().and_then(|action| action.get("input"))
        && !input.is_null()
    {
        super::envelope(action.as_ref().expect("action"), "input")?;
    }
    let fields = validated.as_object().expect("validated object");
    Ok(Changes {
        spec: Some(validated["spec"].clone()).filter(|spec| !spec.is_null()),
        action,
        overlap_policy: validated["overlap_policy"].as_str().map(str::to_owned),
        jitter_seconds: integer(&validated["jitter_seconds"]),
        note: validated["note"].as_str().map(str::to_owned),
        memo: fields
            .contains_key("memo")
            .then(|| Some(validated["memo"].clone()).filter(|memo| !empty(memo))),
        clears_search_attributes: fields.contains_key("search_attributes"),
        max_runs: integer(&validated["max_runs"]),
    })
}

/// A validated schedule creation.
pub(crate) struct Definition {
    pub(crate) schedule_id: Option<String>,
    pub(crate) spec: Value,
    pub(crate) action: Value,
    pub(crate) overlap_policy: String,
    pub(crate) memo: Option<Value>,
    pub(crate) jitter_seconds: i64,
    pub(crate) max_runs: Option<i64>,
    pub(crate) note: Option<String>,
    pub(crate) paused: bool,
}

/// An integer as Laravel's `integer` rule accepted it.
fn integer(value: &Value) -> Option<i64> {
    value
        .as_i64()
        .or_else(|| value.as_f64().map(|float| float as i64))
        .or_else(|| value.as_str().and_then(|text| text.trim().parse().ok()))
}

fn empty(value: &Value) -> bool {
    match value {
        Value::Null => true,
        Value::Array(items) => items.is_empty(),
        Value::Object(fields) => fields.is_empty(),
        _ => false,
    }
}

/// `WorkflowSchedule::normalizeActionTimeouts`.
pub(crate) fn normalize_action_timeouts(action: &Value) -> Value {
    let mut action = action.clone();
    let Some(fields) = action.as_object_mut() else {
        return action;
    };
    for (canonical, alias) in [
        ("execution_timeout_seconds", "workflow_execution_timeout"),
        ("run_timeout_seconds", "workflow_run_timeout"),
    ] {
        if fields.get(canonical).is_none_or(Value::is_null)
            && let Some(value) = fields.get(alias).filter(|value| !value.is_null())
        {
            let value = json!(integer(value).unwrap_or(0));
            fields.insert(canonical.to_owned(), value);
        }
        fields.remove(alias);
    }
    action
}

/// The byte length of PHP's default `json_encode`, which escapes `/` and
/// every non-ASCII character.
pub(crate) fn php_json_len(value: &Value) -> usize {
    match value {
        Value::String(text) => {
            2 + text
                .chars()
                .map(|character| match character {
                    '"' | '\\' | '/' | '\u{8}' | '\u{c}' | '\n' | '\r' | '\t' => 2,
                    character if (character as u32) < 0x20 => 6,
                    character if character.is_ascii() => 1,
                    character => 6 * character.len_utf16(),
                })
                .sum::<usize>()
        }
        Value::Array(items) if items.is_empty() => 2,
        Value::Array(items) => 1 + items.iter().map(|item| php_json_len(item) + 1).sum::<usize>(),
        Value::Object(fields) if fields.is_empty() => 2,
        Value::Object(fields) => {
            1 + fields
                .iter()
                .map(|(key, value)| php_json_len(&json!(key)) + 1 + php_json_len(value) + 1)
                .sum::<usize>()
        }
        other => other.to_string().len(),
    }
}

/// `ScheduleController::validateMemoSize`.
fn memo_size(memo: &Value) -> Result<()> {
    if !(memo.is_object() || memo.is_array()) {
        return Ok(());
    }
    let limit = std::env::var("DW_MAX_MEMO_BYTES")
        .or_else(|_| std::env::var("WORKFLOW_MAX_MEMO_BYTES"))
        .ok()
        .and_then(|value| value.trim().parse::<i64>().ok())
        .unwrap_or(256 * 1024);
    if php_json_len(memo) as i64 > limit {
        return Err(RuntimeError::Protocol {
            status: StatusCode::UNPROCESSABLE_ENTITY,
            response: json!({"message":format!("The memo exceeds the maximum allowed size of {limit} bytes."),
                "reason":"memo_too_large","limit":limit}),
        });
    }
    Ok(())
}

/// `ScheduleManager::assertValidSpec`, with PHP's messages.
pub(crate) fn spec_problem(spec: &Value) -> Option<String> {
    let cron = spec.get("cron_expressions").unwrap_or(&Value::Null);
    let intervals = spec.get("intervals").unwrap_or(&Value::Null);
    let list = |value: &Value| value.is_null() || value.is_array() || value.is_object();
    if !list(cron) || !list(intervals) {
        return Some("Schedule spec cron_expressions/intervals must be arrays.".into());
    }
    if empty(cron) && empty(intervals) {
        return Some(
            "Schedule spec must include at least one cron_expression or interval.".into(),
        );
    }
    let expressions: Vec<&Value> = match cron {
        Value::Array(items) => items.iter().collect(),
        Value::Object(fields) => fields.values().collect(),
        _ => Vec::new(),
    };
    for expression in expressions {
        if !expression.as_str().is_some_and(Cron::is_valid) {
            let text = match expression {
                Value::String(text) => text.clone(),
                Value::Number(number) => number.to_string(),
                Value::Bool(true) => "1".into(),
                _ => String::new(),
            };
            return Some(format!("Invalid cron expression: [{text}]."));
        }
    }
    None
}

/// `ScheduleController::invalidScheduleSpecResponse`.
pub(crate) fn invalid_spec(message: String, schedule_id: &str) -> RuntimeError {
    let cron = message.starts_with("Invalid cron expression");
    let field = if cron { "spec.cron_expressions" } else { "spec" };
    RuntimeError::Protocol {
        status: StatusCode::UNPROCESSABLE_ENTITY,
        response: json!({"message":message,
            "reason": if cron {"invalid_cron_expression"} else {"invalid_schedule_spec"},
            "schedule_id":schedule_id,"errors":{field:[message]}}),
    }
}

/// Validates a creation body as PHP's `store` does, before any lookup.
pub(crate) fn definition(body: &Value) -> Result<Definition> {
    let body = normalize(body);
    validate(&body, STORE_RULES)?;
    let validated = validated(&body, STORE_RULES);
    memo_size(&validated["memo"])?;
    if !empty(&validated["search_attributes"]) {
        // Native runs carry no search attribute values yet.
        return Err(refuse(
            StatusCode::UNPROCESSABLE_ENTITY,
            "schedule_search_attributes_unavailable",
        ));
    }
    let action = normalize_action_timeouts(&validated["action"]);
    if action
        .get("input")
        .is_some_and(|input| !input.is_null())
    {
        super::envelope(&action, "input")?;
    }
    Ok(Definition {
        schedule_id: validated["schedule_id"].as_str().map(str::to_owned),
        spec: validated["spec"].clone(),
        action,
        overlap_policy: validated["overlap_policy"]
            .as_str()
            .filter(|policy| OVERLAP_POLICIES.contains(policy))
            .unwrap_or("skip")
            .to_owned(),
        memo: Some(validated["memo"].clone()).filter(|memo| !empty(memo)),
        jitter_seconds: integer(&validated["jitter_seconds"]).unwrap_or(0),
        max_runs: integer(&validated["max_runs"]),
        note: validated["note"].as_str().map(str::to_owned),
        paused: validated["paused"].as_bool().unwrap_or(false)
            || validated["paused"].as_i64() == Some(1)
            || validated["paused"].as_str() == Some("1"),
    })
}

/// `computeNextFireAtWithJitter`: the next fire time after `after`, plus up
/// to `jitter` random seconds. PHP raises a cron evaluation failure; native
/// reports it as an invalid spec.
pub(crate) fn next_fire(spec: &Value, jitter: i64, after: DateTime<Utc>) -> Result<Option<DateTime<Utc>>> {
    let at = jiff::Timestamp::new(after.timestamp(), after.timestamp_subsec_nanos() as i32)
        .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_schedule_spec"))?;
    let next = schedule_spec::next_fire(spec, at).map_err(|error| RuntimeError::Protocol {
        status: StatusCode::UNPROCESSABLE_ENTITY,
        response: json!({"message":format!("Schedule spec could not be evaluated ({}).", error.php_class()),
            "reason":"invalid_schedule_spec"}),
    })?;
    Ok(next.map(|next| {
        let seconds = next.as_second() + if jitter > 0 { random_up_to(jitter) } else { 0 };
        DateTime::from_timestamp(seconds, next.subsec_nanosecond() as u32).unwrap_or(after)
    }))
}

/// `computeNextFireAt` without jitter, with the UTC offset PHP's result
/// carries; failures as for `next_fire`.
pub(crate) fn next_occurrence(spec: &Value, after: DateTime<Utc>) -> Result<Option<(DateTime<Utc>, i64)>> {
    let at = jiff::Timestamp::new(after.timestamp(), after.timestamp_subsec_nanos() as i32)
        .map_err(|_| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_schedule_spec"))?;
    let next = schedule_spec::next_occurrence(spec, at).map_err(|error| RuntimeError::Protocol {
        status: StatusCode::UNPROCESSABLE_ENTITY,
        response: json!({"message":format!("Schedule spec could not be evaluated ({}).", error.php_class()),
            "reason":"invalid_schedule_spec"}),
    })?;
    Ok(next.and_then(|(next, offset)| {
        DateTime::from_timestamp(next.as_second(), next.subsec_nanosecond() as u32).map(|next| (next, offset))
    }))
}

/// A uniform integer in `0..=limit`, like PHP's `random_int(0, $limit)`.
fn random_up_to(limit: i64) -> i64 {
    let mut bytes = [0u8; 8];
    if getrandom::fill(&mut bytes).is_err() {
        return 0;
    }
    (u64::from_le_bytes(bytes) % (limit as u64 + 1)) as i64
}

#[cfg(test)]
mod tests {
    use super::*;

    fn refusal(body: Value) -> Value {
        match definition(&body) {
            Err(RuntimeError::Protocol { response, .. }) => response,
            Err(RuntimeError::Refused { reason, .. }) => json!({"reason":reason}),
            Err(other) => panic!("{other:?}"),
            Ok(_) => panic!("accepted {body}"),
        }
    }

    #[test]
    fn creation_follows_php_validation_and_normalization() {
        let created = definition(&json!({"schedule_id":" nightly ","spec":{"cron_expressions":["0 3 * * *"],"timezone":"Europe/Budapest"},
            "action":{"workflow_type":"echo","workflow_execution_timeout":"90","workflow_run_timeout":30},
            "overlap_policy":"buffer_one","jitter_seconds":"5","max_runs":3,"memo":{},"paused":"1","note":"  "}))
        .unwrap();
        assert_eq!(created.schedule_id.as_deref(), Some("nightly"));
        assert_eq!(
            created.action,
            json!({"workflow_type":"echo","execution_timeout_seconds":90,"run_timeout_seconds":30})
        );
        assert_eq!(created.overlap_policy, "buffer_one");
        assert_eq!((created.jitter_seconds, created.max_runs), (5, Some(3)));
        assert!(created.memo.is_none() && created.note.is_none() && created.paused);
        assert_eq!(
            refusal(json!({}))["errors"],
            json!({"spec":["The spec field is required."],"action":["The action field is required."],
                "action.workflow_type":["The action.workflow type field is required."]})
        );
        assert_eq!(
            refusal(json!({"spec":{"intervals":[{"every":"PT1H"}]},"action":{"workflow_type":"echo"},"search_attributes":{"team":"ops"}}))["reason"],
            "schedule_search_attributes_unavailable"
        );
        let large = json!({"spec":{"intervals":[{"every":"PT1H"}]},"action":{"workflow_type":"echo"},"memo":{"text":"é".repeat(50000)}});
        assert_eq!(refusal(large)["reason"], "memo_too_large");
    }

    #[test]
    fn spec_problems_use_php_messages() {
        assert_eq!(
            spec_problem(&json!({})).as_deref(),
            Some("Schedule spec must include at least one cron_expression or interval.")
        );
        assert_eq!(
            spec_problem(&json!({"cron_expressions":"0 3 * * *"})).as_deref(),
            Some("Schedule spec cron_expressions/intervals must be arrays.")
        );
        assert_eq!(
            spec_problem(&json!({"cron_expressions":["0 3 * *"]})).as_deref(),
            Some("Invalid cron expression: [0 3 * *].")
        );
        assert_eq!(spec_problem(&json!({"intervals":[{"every":"PT1H"}]})), None);
    }

    #[test]
    fn php_json_length_counts_escapes() {
        assert_eq!(php_json_len(&json!({"a":"\u{e9}/"})), 16); // {"a":"\\u00e9\\/"}
        assert_eq!(php_json_len(&json!([1, "x", {}, []])), r#"[1,"x",[],[]]"#.len());
    }
}
