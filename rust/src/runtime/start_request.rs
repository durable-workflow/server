//! `WorkflowController::start`'s request rules and the values published PHP
//! takes from a valid request.
use super::laravel_validation::{Rule, failure_messages, rule_errors};
use super::{Result, refuse};
use axum::http::StatusCode;
use serde_json::Value;

/// A start request after PHP's validation, with PHP's defaults applied.
#[derive(Debug, PartialEq)]
pub(super) struct StartRequest {
    pub(super) workflow_id: Option<String>,
    pub(super) workflow_type: String,
    pub(super) task_queue: String,
    pub(super) business_key: Option<String>,
    pub(super) use_existing: bool,
    pub(super) execution_timeout: Option<i64>,
    pub(super) run_timeout: Option<i64>,
    pub(super) priority: i64,
    pub(super) fairness_key: Option<String>,
    pub(super) fairness_weight: i64,
}

fn workflow_id_pattern(text: &str) -> bool {
    !text.is_empty() && text.bytes().all(|byte| byte.is_ascii_alphanumeric() || b"._:-".contains(&byte))
}

fn fairness_key_pattern(text: &str) -> bool {
    text.len() <= 64 && workflow_id_pattern(text)
}

use Rule::{Array, In, Integer, Max, Min, Nullable, Pattern, Required};

const RULES: &[(&str, &[Rule])] = &[
    ("workflow_id", &[Nullable, Rule::String, Max(128), Pattern(workflow_id_pattern)]),
    ("workflow_type", &[Required, Rule::String, Max(255)]),
    ("task_queue", &[Nullable, Rule::String, Max(255)]),
    ("input", &[Nullable, Array]),
    ("payload_codec", &[Nullable, Rule::String, Max(64)]),
    ("business_key", &[Nullable, Rule::String, Max(255)]),
    ("memo", &[Nullable, Array]),
    ("search_attributes", &[Nullable, Array]),
    ("duplicate_policy", &[Nullable, Rule::String, In(&["fail", "use-existing"])]),
    ("execution_timeout_seconds", &[Nullable, Integer, Min(1)]),
    ("run_timeout_seconds", &[Nullable, Integer, Min(1)]),
    ("priority", &[Nullable, Integer, Min(0), Max(9)]),
    ("fairness_key", &[Nullable, Rule::String, Max(64), Pattern(fairness_key_pattern)]),
    ("fairness_weight", &[Nullable, Integer, Min(1), Max(1000)]),
];

/// The validator's `after` hook: older field names PHP refuses by name.
const LEGACY_FIELDS: &[(&str, &str)] = &[
    ("workflow_execution_timeout", "Use execution_timeout_seconds instead of workflow_execution_timeout."),
    ("workflow_run_timeout", "Use run_timeout_seconds instead of workflow_run_timeout."),
    ("workflow_task_timeout", "The workflow_task_timeout field is not supported by the v2 workflow start API."),
    ("retry_policy", "The retry_policy field is not supported by the v2 workflow start API."),
    ("idempotency_key", "The idempotency_key field is not supported by the v2 workflow start API."),
    ("request_id", "The request_id field is not supported by the v2 workflow start API."),
];

/// Native's deadlines stay representable on every backend.
const MAX_TIMEOUT_SECONDS: i64 = 3_153_600_000;

fn custom_message(field: &str, rule: Rule) -> Option<&'static str> {
    Some(match (field, rule) {
        ("duplicate_policy", In(_)) => "The duplicate_policy field only supports fail or use-existing.",
        ("priority", Min(_) | Max(_)) => "The priority field must be an integer in the range 0..9 (lower runs first).",
        ("fairness_key", Pattern(_)) => {
            "The fairness_key field must be 1-64 URL-safe characters using letters, numbers, \".\", \"_\", \"-\", or \":\"."
        }
        ("fairness_weight", Min(_) | Max(_)) => "The fairness_weight field must be an integer in the range 1..1000.",
        _ => return None,
    })
}

/// Laravel's TrimStrings and ConvertEmptyStringsToNull, applied to every
/// string in the request as the middleware does.
pub(super) fn normalized(value: Value) -> Value {
    match value {
        Value::String(_) => super::laravel_validation::normalized(Some(&value)).unwrap_or(Value::Null),
        Value::Array(items) => Value::Array(items.into_iter().map(normalized).collect()),
        Value::Object(fields) => Value::Object(fields.into_iter().map(|(key, value)| (key, normalized(value))).collect()),
        other => other,
    }
}

/// `PayloadCodecContract::canonicalize`.
fn payload_codec(value: &Value) -> Result<()> {
    match value {
        Value::Null => Ok(()),
        Value::String(codec) if codec == "avro" => Ok(()),
        other => {
            let rendered = other.as_str().map_or_else(|| debug_type(other).to_owned(), str::to_owned);
            Err(failure_messages(vec![("payload_codec".into(), vec![format!(
                "unsupported_payload_codec: workflow payload codec \"{rendered}\" is not supported by Durable Workflow 2.0; use codec=\"avro\" with the fixed Avro Value schema and single-object framing. JSON remains the HTTP document transport, not a workflow payload codec."
            )])])
            .expect("one message"))
        }
    }
}

/// PHP's `get_debug_type`.
fn debug_type(value: &Value) -> &'static str {
    match value {
        Value::Null => "null",
        Value::Bool(_) => "bool",
        Value::Number(number) if number.is_i64() || number.is_u64() => "int",
        Value::Number(_) => "float",
        Value::String(_) => "string",
        Value::Array(_) | Value::Object(_) => "array",
    }
}

/// `WorkflowStartService::intValue`: only a JSON integer is taken.
fn whole(body: &Value, field: &str) -> Option<i64> {
    body.get(field).and_then(Value::as_i64)
}

fn text(body: &Value, field: &str) -> Option<String> {
    body.get(field).and_then(Value::as_str).map(str::trim).filter(|text| !text.is_empty()).map(str::to_owned)
}

/// The rules, the `after` hook and the codec check, in PHP's order, then the
/// values `WorkflowStartService` passes to the control plane.
pub(super) fn validate(body: &Value) -> Result<StartRequest> {
    let mut errors = rule_errors(body, RULES, custom_message);
    for (field, message) in LEGACY_FIELDS {
        if body.get(*field).is_some() {
            errors.push(((*field).to_owned(), vec![(*message).to_owned()]));
        }
    }
    if let Some(error) = failure_messages(errors) {
        return Err(error);
    }
    payload_codec(&body["payload_codec"])?;
    let execution_timeout = whole(body, "execution_timeout_seconds");
    let run_timeout = whole(body, "run_timeout_seconds");
    for (field, seconds) in [("execution_timeout_seconds", execution_timeout), ("run_timeout_seconds", run_timeout)] {
        if seconds.is_some_and(|seconds| seconds > MAX_TIMEOUT_SECONDS) {
            let message = format!("The {} field must not be greater than {MAX_TIMEOUT_SECONDS}.", field.replace('_', " "));
            return Err(failure_messages(vec![(field.to_owned(), vec![message])]).expect("one message"));
        }
    }
    let workflow_type = text(body, "workflow_type").ok_or_else(|| refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_request"))?;
    Ok(StartRequest {
        workflow_id: text(body, "workflow_id"),
        workflow_type,
        task_queue: text(body, "task_queue").unwrap_or_else(|| "default".into()),
        business_key: body.get("business_key").and_then(Value::as_str).map(str::to_owned),
        use_existing: body["duplicate_policy"] == "use-existing",
        execution_timeout,
        run_timeout,
        priority: whole(body, "priority").unwrap_or(5),
        fairness_key: text(body, "fairness_key").map(|key| key.to_lowercase()),
        fairness_weight: whole(body, "fairness_weight").unwrap_or(1),
    })
}

/// `PayloadEnvelopeResolver::looksLikeEnvelope`, with the external payload
/// reference PHP's transport middleware resolves first. Keys are sorted.
pub(super) fn envelope_keys(keys: &[&str]) -> bool {
    matches!(keys, ["blob", "codec"] | ["codec", "external_storage"] | ["codec", "external_payload"])
}

/// PHP's input: absent, null or an empty list starts with no arguments; an
/// explicit envelope is kept; any other list or object is its values.
pub(super) enum Input<'a> {
    Empty,
    Envelope,
    Values(Vec<&'a Value>),
}

pub(super) fn input(body: &Value) -> Input<'_> {
    match body.get("input") {
        None | Some(Value::Null) => Input::Empty,
        Some(Value::Array(items)) if items.is_empty() => Input::Empty,
        Some(Value::Array(items)) => Input::Values(items.iter().collect()),
        Some(Value::Object(fields)) => {
            let mut keys: Vec<&str> = fields.keys().map(String::as_str).collect();
            keys.sort_unstable();
            if envelope_keys(&keys) {
                Input::Envelope
            } else if fields.is_empty() {
                Input::Empty
            } else {
                Input::Values(fields.values().collect())
            }
        }
        Some(_) => Input::Envelope,
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn errors(body: Value) -> Value {
        match validate(&normalized(body)) {
            Err(super::super::RuntimeError::Protocol { response, .. }) => response["errors"].clone(),
            other => panic!("{other:?}"),
        }
    }

    #[test]
    fn rules_report_php_messages() {
        assert_eq!(
            errors(json!({"workflow_id":"sp-3","workflow_type":"t","priority":10,"fairness_key":"bad key","fairness_weight":0,
                "business_key":123,"workflow_execution_timeout":5,"request_id":"x"})),
            json!({"business_key":["The business key field must be a string."],
                "priority":["The priority field must be an integer in the range 0..9 (lower runs first)."],
                "fairness_key":["The fairness_key field must be 1-64 URL-safe characters using letters, numbers, \".\", \"_\", \"-\", or \":\"."],
                "fairness_weight":["The fairness_weight field must be an integer in the range 1..1000."],
                "workflow_execution_timeout":["Use execution_timeout_seconds instead of workflow_execution_timeout."],
                "request_id":["The request_id field is not supported by the v2 workflow start API."]})
        );
        assert_eq!(
            errors(json!({"workflow_id":"bad id!","execution_timeout_seconds":0,"run_timeout_seconds":"abc","duplicate_policy":"x",
                "memo":"m","input":"i","search_attributes":5,"task_queue":7})),
            json!({"workflow_id":["The workflow id field format is invalid."],"workflow_type":["The workflow type field is required."],
                "task_queue":["The task queue field must be a string."],"input":["The input field must be an array."],
                "memo":["The memo field must be an array."],"search_attributes":["The search attributes field must be an array."],
                "duplicate_policy":["The duplicate_policy field only supports fail or use-existing."],
                "execution_timeout_seconds":["The execution timeout seconds field must be at least 1."],
                "run_timeout_seconds":["The run timeout seconds field must be an integer."]})
        );
        assert!(
            errors(json!({"workflow_type":"t","payload_codec":"AVRO"}))["payload_codec"][0]
                .as_str()
                .unwrap()
                .starts_with("unsupported_payload_codec: workflow payload codec \"AVRO\" is not supported by Durable Workflow 2.0;")
        );
        assert_eq!(
            errors(json!({"workflow_type":"t","workflow_id":"x".repeat(129)})),
            json!({"workflow_id":["The workflow id field must not be greater than 128 characters."]})
        );
    }

    #[test]
    fn valid_requests_take_php_defaults() {
        let request = validate(&normalized(json!({"workflow_type":" t ","task_queue":"  ","business_key":"","fairness_key":" Tenant-A ",
            "priority":"4","fairness_weight":7,"execution_timeout_seconds":"60","foo":1}))).unwrap();
        assert_eq!(request, StartRequest {
            workflow_id: None, workflow_type: "t".into(), task_queue: "default".into(), business_key: None, use_existing: false,
            execution_timeout: None, run_timeout: None, priority: 5, fairness_key: Some("tenant-a".into()), fairness_weight: 7,
        });
        assert_eq!(validate(&json!({"workflow_type":"t","priority":2.0})).unwrap().priority, 5);
        assert_eq!(validate(&json!({"workflow_type":"t","priority":0})).unwrap().priority, 0);
    }

    #[test]
    fn plain_input_is_its_values() {
        assert!(matches!(input(&json!({})), Input::Empty));
        assert!(matches!(input(&json!({"input":[]})), Input::Empty));
        assert!(matches!(input(&json!({"input":{"codec":"avro","blob":"x"}})), Input::Envelope));
        assert!(matches!(input(&json!({"input":{"codec":"avro","external_payload":{}}})), Input::Envelope));
        assert!(matches!(input(&json!({"input":{"codec":"avro"}})), Input::Values(values) if values == [&json!("avro")]));
        assert!(matches!(input(&json!({"input":[1,"a"]})), Input::Values(values) if values.len() == 2));
    }
}
