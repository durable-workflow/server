//! Laravel's request normalization and validation failure body, as published
//! PHP renders them for control-plane requests.
use super::RuntimeError;
use axum::http::StatusCode;
use serde_json::{Map, Value, json};

/// Laravel's TrimStrings and ConvertEmptyStringsToNull run before validation.
pub(super) fn normalized(value: Option<&Value>) -> Option<Value> {
    let trimmed = |c: char| c.is_whitespace() || matches!(c, '\u{feff}' | '\u{200b}' | '\u{200e}');
    match value {
        Some(Value::String(text)) => {
            let text = text.trim_matches(trimmed);
            Some(if text.is_empty() {
                Value::Null
            } else {
                json!(text)
            })
        }
        other => other.cloned(),
    }
}

/// Laravel's `required`: null, an empty string and an empty array fail.
pub(super) fn present(value: Option<&Value>) -> bool {
    match value {
        None | Some(Value::Null) => false,
        Some(Value::String(text)) => !text.trim().is_empty(),
        Some(Value::Array(items)) => !items.is_empty(),
        Some(Value::Object(items)) => !items.is_empty(),
        Some(_) => true,
    }
}

/// Laravel's 422 ValidationException body for control-plane requests. Fields
/// without messages are left out; the summary counts the remaining messages.
pub(super) fn failure(errors: Vec<(&'static str, Vec<&'static str>)>) -> Option<RuntimeError> {
    let errors: Vec<_> = errors
        .into_iter()
        .filter(|(_, messages)| !messages.is_empty())
        .collect();
    let all: Vec<&str> = errors
        .iter()
        .flat_map(|(_, messages)| messages.iter().copied())
        .collect();
    let mut message = (*all.first()?).to_owned();
    match all.len() - 1 {
        0 => {}
        1 => message.push_str(" (and 1 more error)"),
        more => message.push_str(&format!(" (and {more} more errors)")),
    }
    let errors: Map<String, Value> = errors
        .into_iter()
        .map(|(field, messages)| (field.to_owned(), json!(messages)))
        .collect();
    Some(RuntimeError::Protocol {
        status: StatusCode::UNPROCESSABLE_ENTITY,
        response: json!({"message":message,"reason":"validation_failed",
            "errors":errors.clone(),"validation_errors":errors}),
    })
}

/// PHP's `filter_var($value, FILTER_VALIDATE_INT)`, which Laravel's
/// `integer` rule uses: canonical decimal strings, integral numbers and true.
pub(super) fn integer(value: &Value) -> Option<i64> {
    match value {
        Value::Number(number) => number.as_i64().or_else(|| {
            number
                .as_f64()
                .filter(|float| float.fract() == 0.0 && float.abs() < 9.0e18)
                .map(|float| float as i64)
        }),
        Value::String(text) => {
            let digits = text.strip_prefix(['+', '-']).unwrap_or(text);
            let canonical = digits == "0"
                || !digits.is_empty()
                    && !digits.starts_with('0')
                    && digits.bytes().all(|byte| byte.is_ascii_digit());
            canonical.then(|| text.parse().ok()).flatten()
        }
        Value::Bool(true) => Some(1),
        _ => None,
    }
}
