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
    failure_messages(
        errors
            .into_iter()
            .map(|(field, messages)| (field.to_owned(), messages.into_iter().map(str::to_owned).collect()))
            .collect(),
    )
}

/// [`failure`] for messages built at run time.
pub(super) fn failure_messages(errors: Vec<(String, Vec<String>)>) -> Option<RuntimeError> {
    let errors: Vec<_> = errors
        .into_iter()
        .filter(|(_, messages)| !messages.is_empty())
        .collect();
    let all: Vec<&str> = errors
        .iter()
        .flat_map(|(_, messages)| messages.iter().map(String::as_str))
        .collect();
    let mut message = (*all.first()?).to_owned();
    match all.len() - 1 {
        0 => {}
        1 => message.push_str(" (and 1 more error)"),
        more => message.push_str(&format!(" (and {more} more errors)")),
    }
    let errors: Map<String, Value> = errors
        .into_iter()
        .map(|(field, messages)| (field, json!(messages)))
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

/// One Laravel validation rule, as the request controllers declare them.
#[derive(Clone, Copy)]
pub(super) enum Rule {
    Required,
    Nullable,
    String,
    Array,
    Integer,
    Boolean,
    Min(i64),
    Max(i64),
    Pattern(fn(&str) -> bool),
    In(&'static [&'static str]),
}
use Rule::{Array, Boolean, In, Integer, Max, Min, Nullable, Pattern, Required};

/// The messages for each field in rule order, then any `custom` message in
/// place of Laravel's default. Fields without messages are left for
/// [`failure_messages`] to drop.
pub(super) fn rule_errors(
    body: &Value,
    rules: &[(&str, &[Rule])],
    custom: fn(&str, Rule) -> Option<&'static str>,
) -> Vec<(String, Vec<String>)> {
    rules
        .iter()
        .map(|(field, rules)| ((*field).to_owned(), messages(field, rules, body.get(*field), custom)))
        .collect()
}

/// PHP's `(string)` of a scalar request value.
fn php_string(value: &Value) -> Option<String> {
    match value {
        Value::String(text) => Some(text.clone()),
        Value::Number(number) => Some(match number.as_i64() {
            Some(whole) => whole.to_string(),
            None => {
                let float = number.as_f64()?;
                if float.fract() == 0.0 && float.abs() < 1e15 { format!("{float:.0}") } else { float.to_string() }
            }
        }),
        Value::Bool(true) => Some("1".into()),
        Value::Bool(false) | Value::Null => Some(String::new()),
        _ => None,
    }
}

/// PHP 8's `is_numeric` for a request value.
fn php_numeric(value: &Value) -> Option<f64> {
    match value {
        Value::Number(number) => number.as_f64(),
        Value::String(text) => {
            let text = text.trim_matches([' ', '\t', '\n', '\r', '\x0b', '\x0c']);
            let unsigned = text.strip_prefix(['+', '-']).unwrap_or(text);
            let (mantissa, exponent) = match unsigned.find(['e', 'E']) {
                Some(at) => (&unsigned[..at], Some(&unsigned[at + 1..])),
                None => (unsigned, None),
            };
            let (whole, fraction) = mantissa.split_once('.').unwrap_or((mantissa, ""));
            let digits = |part: &str| part.bytes().all(|byte| byte.is_ascii_digit());
            let mantissa_ok = digits(whole) && digits(fraction) && !(whole.is_empty() && fraction.is_empty());
            let exponent_ok = exponent.is_none_or(|exponent| {
                let exponent = exponent.strip_prefix(['+', '-']).unwrap_or(exponent);
                !exponent.is_empty() && digits(exponent)
            });
            (mantissa_ok && exponent_ok).then(|| text.parse().ok()).flatten()
        }
        _ => None,
    }
}

/// Laravel's `getSize`: a number under a numeric rule, an array's count, or
/// a string's length in characters.
fn size(value: &Value, numeric: bool) -> f64 {
    if numeric && let Some(number) = php_numeric(value) {
        return number;
    }
    match value {
        Value::Array(items) => items.len() as f64,
        Value::Object(items) => items.len() as f64,
        other => php_string(other).map_or(0, |text| text.chars().count()) as f64,
    }
}

fn passes(rule: Rule, value: Option<&Value>, numeric: bool) -> bool {
    let Some(value) = value else {
        return !matches!(rule, Required);
    };
    match rule {
        Required => present(Some(value)),
        // Laravel's `boolean`: true, false, 1, 0, "1" and "0".
        Boolean => matches!(value, Value::Bool(_))
            || value.as_i64().is_some_and(|number| number == 0 || number == 1)
            || matches!(value.as_str(), Some("0" | "1")),
        Nullable => true,
        Rule::String => value.is_string(),
        Array => value.is_array() || value.is_object(),
        Integer => integer(value).is_some(),
        Min(least) => size(value, numeric) >= least as f64,
        Max(most) => size(value, numeric) <= most as f64,
        Pattern(matches) => match value {
            Value::String(_) | Value::Number(_) => php_string(value).is_some_and(|text| matches(&text)),
            _ => false,
        },
        In(allowed) => !(value.is_array() || value.is_object())
            && php_string(value).is_some_and(|text| allowed.contains(&text.as_str())),
    }
}

fn default_message(attribute: &str, rule: Rule, numeric: bool, array: bool) -> String {
    let kind = if numeric { "numeric" } else if array { "array" } else { "string" };
    match (rule, kind) {
        (Required, _) => format!("The {attribute} field is required."),
        (Rule::String, _) => format!("The {attribute} field must be a string."),
        (Array, _) => format!("The {attribute} field must be an array."),
        (Integer, _) => format!("The {attribute} field must be an integer."),
        (Boolean, _) => format!("The {attribute} field must be true or false."),
        (Pattern(_), _) => format!("The {attribute} field format is invalid."),
        (In(_), _) => format!("The selected {attribute} is invalid."),
        (Min(least), "numeric") => format!("The {attribute} field must be at least {least}."),
        (Min(least), "array") => format!("The {attribute} field must have at least {least} items."),
        (Min(least), _) => format!("The {attribute} field must be at least {least} characters."),
        (Max(most), "numeric") => format!("The {attribute} field must not be greater than {most}."),
        (Max(most), "array") => format!("The {attribute} field must not have more than {most} items."),
        (Max(most), _) => format!("The {attribute} field must not be greater than {most} characters."),
        (Nullable, _) => String::new(),
    }
}

/// One attribute's messages in rule order. Absent and nullable null values
/// skip every rule except `required`, which stops the attribute when it fails.
fn messages(
    field: &str,
    rules: &[Rule],
    value: Option<&Value>,
    custom: fn(&str, Rule) -> Option<&'static str>,
) -> Vec<String> {
    // Laravel shows a nested attribute such as `run_ids.0` as written.
    let attribute = if field.contains('.') { field.to_owned() } else { field.replace('_', " ") };
    let nullable = rules.iter().any(|rule| matches!(rule, Nullable));
    let numeric = rules.iter().any(|rule| matches!(rule, Integer));
    let array = rules.iter().any(|rule| matches!(rule, Array));
    let mut found = Vec::new();
    for &rule in rules {
        let implicit = matches!(rule, Required);
        let skipped = !implicit && (value.is_none() || nullable && value.is_some_and(Value::is_null));
        if matches!(rule, Nullable) || skipped || passes(rule, value, numeric) {
            continue;
        }
        found.push(custom(field, rule).map_or_else(|| default_message(&attribute, rule, numeric, array), str::to_owned));
        if implicit {
            break;
        }
    }
    found
}

/// `WorkflowController::validateOperationName`, which a signal, update or
/// query request runs first: the name is one to 256 bytes
/// (DW_MAX_OPERATION_NAME_LENGTH, which native runs only at its default)
/// without ASCII control characters.
pub(super) fn operation_name(name: &str, kind: &str) -> super::Result<()> {
    let message = if name.is_empty() {
        format!("The {kind} name must not be empty.")
    } else if name.len() > 256 {
        format!("The {kind} name exceeds the maximum length of 256 bytes.")
    } else if name.bytes().any(|byte| byte < 0x20 || byte == 0x7f) {
        format!("The {kind} name must not contain control characters.")
    } else {
        return Ok(());
    };
    Err(failure_messages(vec![(format!("{kind}_name"), vec![message])]).expect("one message"))
}
