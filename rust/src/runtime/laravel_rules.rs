//! The subset of Laravel request validation published PHP Server's control
//! plane uses, with Laravel's own messages, attribute names and order.
//!
//! Laravel first trims every string in the body and turns empty strings into
//! null (its TrimStrings and ConvertEmptyStringsToNull middleware). It then
//! expands `*` rules over the present items, validates the plain rules in
//! their listed order and the expanded ones after them, and for each
//! attribute reports every failing rule, stopping after a failed presence
//! rule. A rule other than a presence rule ignores an absent attribute, and
//! `nullable` lets null pass.
use super::RuntimeError;
use axum::http::StatusCode;
use serde_json::{Map, Value, json};

#[derive(Clone, Copy, Debug)]
pub(crate) enum Rule {
    Required,
    Nullable,
    /// Validate only when the attribute is present.
    Sometimes,
    /// `regex:/\S/`: some character that is not whitespace.
    NotBlank,
    /// Required when the named attribute is present and not empty.
    RequiredWith(&'static str),
    String,
    Array,
    Integer,
    /// `numeric`: PHP's `is_numeric`.
    Numeric,
    Boolean,
    /// `required_array_keys`: an array holding each named key.
    RequiredArrayKeys(&'static [&'static str]),
    /// A size floor: a number, string length or item count, by type rule.
    Min(i64),
    Max(i64),
    /// `between:min,max`: a size within both bounds, inclusive.
    Between(i64, i64),
    In(&'static [&'static str]),
    /// `date`, for the ISO-8601 shapes `schedule_backfill::php_date` reads.
    Date,
    /// `regex:...`, as a predicate on the string.
    Pattern(fn(&str) -> bool),
}

pub(crate) type Rules = [(&'static str, &'static [Rule])];

/// Laravel's TrimStrings and ConvertEmptyStringsToNull, applied throughout.
pub(crate) fn normalize(value: &Value) -> Value {
    match value {
        Value::String(text) => {
            let trimmed = text.trim_matches(|character: char| {
                character.is_whitespace() || matches!(character, '\u{feff}' | '\u{200b}' | '\u{200e}')
            });
            if trimmed.is_empty() {
                Value::Null
            } else {
                json!(trimmed)
            }
        }
        Value::Array(items) => Value::Array(items.iter().map(normalize).collect()),
        Value::Object(fields) => Value::Object(
            fields
                .iter()
                .map(|(key, value)| (key.clone(), normalize(value)))
                .collect(),
        ),
        other => other.clone(),
    }
}

/// The value at a dotted path, as `data_get`/`Arr::has` see it.
fn lookup<'a>(data: &'a Value, path: &str) -> Option<&'a Value> {
    path.split('.').try_fold(data, |value, key| match value {
        Value::Object(fields) => fields.get(key),
        Value::Array(items) => key.parse::<usize>().ok().and_then(|index| items.get(index)),
        _ => None,
    })
}

/// The concrete attributes a `*` rule covers, in data order.
fn expand(data: &Value, pattern: &str) -> Vec<String> {
    let mut paths = vec![String::new()];
    for segment in pattern.split('.') {
        let mut next = Vec::new();
        for prefix in paths {
            let joined = |key: &str| {
                if prefix.is_empty() {
                    key.to_owned()
                } else {
                    format!("{prefix}.{key}")
                }
            };
            if segment == "*" {
                let parent = if prefix.is_empty() {
                    Some(data)
                } else {
                    lookup(data, &prefix)
                };
                match parent {
                    Some(Value::Array(items)) => {
                        next.extend((0..items.len()).map(|index| joined(&index.to_string())))
                    }
                    Some(Value::Object(fields)) => next.extend(fields.keys().map(|key| joined(key))),
                    _ => {}
                }
            } else if prefix.is_empty()
                || !pattern_has_wildcard_before(pattern, &prefix)
                || lookup(data, &prefix).is_some_and(|item| item.is_array() || item.is_object())
            {
                // Laravel initializes `item.field` only under array items
                // (`data_set` with `*`), so a scalar item has no such field.
                next.push(joined(segment));
            }
        }
        paths = next;
    }
    paths
}

/// Whether `prefix`, a path built from `pattern`, already passed a `*`.
fn pattern_has_wildcard_before(pattern: &str, prefix: &str) -> bool {
    let depth = prefix.split('.').count();
    pattern.split('.').take(depth).any(|segment| segment == "*")
}

/// Laravel's displayable attribute: expanded `*` attributes keep their raw
/// path; others read with spaces for underscores.
fn display(attribute: &str, expanded: bool) -> String {
    if expanded {
        attribute.to_owned()
    } else {
        attribute.replace('_', " ")
    }
}

fn filled(value: Option<&Value>) -> bool {
    match value {
        None | Some(Value::Null) => false,
        Some(Value::String(text)) => !text.trim().is_empty(),
        Some(Value::Array(items)) => !items.is_empty(),
        Some(Value::Object(fields)) => !fields.is_empty(),
        Some(_) => true,
    }
}

/// `FILTER_VALIDATE_INT` on a decoded JSON value.
fn integer(value: &Value) -> Option<i64> {
    match value {
        Value::Number(number) => number.as_i64().or_else(|| {
            number
                .as_f64()
                .filter(|float| float.fract() == 0.0 && float.abs() < 9.2e18)
                .map(|float| float as i64)
        }),
        Value::String(text) => {
            let text = text.trim();
            let digits = text.strip_prefix(['+', '-']).unwrap_or(text);
            if digits.is_empty()
                || !digits.bytes().all(|byte| byte.is_ascii_digit())
                || (digits.len() > 1 && digits.starts_with('0'))
            {
                return None;
            }
            text.parse().ok()
        }
        Value::Bool(true) => Some(1),
        _ => None,
    }
}

/// PHP's `(string)` of a scalar, as `in` compares it.
fn php_string(value: &Value) -> Option<String> {
    match value {
        Value::String(text) => Some(text.clone()),
        Value::Number(number) => Some(number.to_string()),
        Value::Bool(true) => Some("1".into()),
        Value::Bool(false) | Value::Null => Some(String::new()),
        _ => None,
    }
}

fn message(rule: Rule, attribute: &str, rules: &[Rule], value: Option<&Value>) -> String {
    let kind = if rules.iter().any(|rule| matches!(rule, Rule::Integer | Rule::Numeric)) {
        "numeric"
    } else if rules.iter().any(|rule| matches!(rule, Rule::Array)) || value.is_some_and(Value::is_array) {
        "array"
    } else {
        "string"
    };
    match rule {
        Rule::Required => format!("The {attribute} field is required."),
        Rule::RequiredWith(other) => {
            format!("The {attribute} field is required when {} is present.", display(other, false))
        }
        Rule::String => format!("The {attribute} field must be a string."),
        Rule::Array => format!("The {attribute} field must be an array."),
        Rule::Integer => format!("The {attribute} field must be an integer."),
        Rule::Numeric => format!("The {attribute} field must be a number."),
        Rule::RequiredArrayKeys(keys) => {
            format!("The {attribute} field must contain entries for: {}.", keys.join(", "))
        }
        Rule::Boolean => format!("The {attribute} field must be true or false."),
        Rule::In(_) => format!("The selected {attribute} is invalid."),
        Rule::Min(limit) => match kind {
            "numeric" => format!("The {attribute} field must be at least {limit}."),
            "array" => format!("The {attribute} field must have at least {limit} items."),
            _ => format!("The {attribute} field must be at least {limit} characters."),
        },
        Rule::Max(limit) => match kind {
            "numeric" => format!("The {attribute} field must not be greater than {limit}."),
            "array" => format!("The {attribute} field must not have more than {limit} items."),
            _ => format!("The {attribute} field must not be greater than {limit} characters."),
        },
        Rule::Between(min, max) => match kind {
            "numeric" => format!("The {attribute} field must be between {min} and {max}."),
            "array" => format!("The {attribute} field must have between {min} and {max} items."),
            _ => format!("The {attribute} field must be between {min} and {max} characters."),
        },
        Rule::NotBlank | Rule::Pattern(_) => format!("The {attribute} field format is invalid."),
        Rule::Date => format!("The {attribute} field must be a valid date."),
        Rule::Nullable | Rule::Sometimes => String::new(),
    }
}

/// Laravel's `getSize`: a numeric value under a numeric rule, otherwise
/// an item count or the string length.
fn size(value: &Value, rules: &[Rule]) -> Option<f64> {
    if rules.iter().any(|rule| matches!(rule, Rule::Integer | Rule::Numeric)) {
        match value {
            Value::Number(number) => return number.as_f64(),
            Value::String(text) if super::schedule_cron::php_is_numeric(text) => {
                return text.trim().parse().ok();
            }
            _ => {}
        }
    }
    match value {
        Value::Bool(flag) => Some(f64::from(u8::from(*flag))),
        Value::Array(items) => Some(items.len() as f64),
        Value::Object(fields) => Some(fields.len() as f64),
        Value::String(text) => Some(text.chars().count() as f64),
        Value::Number(number) => number.as_f64(),
        _ => None,
    }
}

fn passes(rule: Rule, data: &Value, value: Option<&Value>, rules: &[Rule]) -> bool {
    match rule {
        Rule::Required => filled(value),
        Rule::RequiredWith(other) => !filled(lookup(data, other)) || filled(value),
        Rule::Nullable | Rule::Sometimes => true,
        Rule::NotBlank => value.and_then(Value::as_str).is_some_and(|text| text.chars().any(|character| !character.is_whitespace())),
        Rule::String => value.is_some_and(Value::is_string),
        Rule::Pattern(matches) => value.and_then(Value::as_str).is_some_and(matches),
        Rule::Date => value
            .and_then(Value::as_str)
            .is_some_and(|text| super::schedule_backfill::php_date(text).is_some()),
        Rule::Array => value.is_some_and(|value| value.is_array() || value.is_object()),
        Rule::Integer => value.and_then(integer).is_some(),
        Rule::Numeric => value.is_some_and(|value| match value {
            Value::Number(_) => true,
            Value::String(text) => super::schedule_cron::php_is_numeric(text),
            _ => false,
        }),
        Rule::RequiredArrayKeys(keys) => value
            .and_then(Value::as_object)
            .is_some_and(|fields| keys.iter().all(|key| fields.contains_key(*key))),
        Rule::Boolean => matches!(
            value,
            Some(Value::Bool(_))
        ) || value.is_some_and(|value| {
            value.as_i64().is_some_and(|number| number == 0 || number == 1)
                || value.as_str().is_some_and(|text| text == "0" || text == "1")
        }),
        Rule::In(allowed) => value
            .and_then(php_string)
            .is_some_and(|text| allowed.contains(&text.as_str())),
        Rule::Min(limit) => value
            .and_then(|value| size(value, rules))
            .is_some_and(|size| size >= limit as f64),
        Rule::Max(limit) => value
            .and_then(|value| size(value, rules))
            .is_some_and(|size| size <= limit as f64),
        Rule::Between(min, max) => value
            .and_then(|value| size(value, rules))
            .is_some_and(|size| size >= min as f64 && size <= max as f64),
    }
}

/// Validates `body` (already normalized) and returns Laravel's 422 failure,
/// or nothing when every rule passes.
pub(crate) fn validate(body: &Value, rules: &Rules) -> Result<(), RuntimeError> {
    match failure(errors(body, rules)) {
        Some(error) => Err(error),
        None => Ok(()),
    }
}

/// Every failing attribute and its messages, in Laravel's order.
pub(crate) fn errors(body: &Value, rules: &Rules) -> Vec<(String, Vec<String>)> {
    let mut checks: Vec<(String, bool, &[Rule])> = Vec::new();
    let mut expanded = Vec::new();
    for (pattern, list) in rules {
        if pattern.contains('*') {
            expanded.extend(expand(body, pattern).into_iter().map(|path| (path, true, *list)));
        } else {
            checks.push(((*pattern).to_owned(), false, *list));
        }
    }
    checks.extend(expanded);
    let mut errors: Vec<(String, Vec<String>)> = Vec::new();
    for (attribute, raw, list) in checks {
        let value = lookup(body, &attribute);
        let nullable = list.iter().any(|rule| matches!(rule, Rule::Nullable));
        if value.is_none() && list.iter().any(|rule| matches!(rule, Rule::Sometimes)) {
            continue;
        }
        let name = display(&attribute, raw);
        let mut messages = Vec::new();
        for rule in list {
            let implicit = matches!(rule, Rule::Required | Rule::RequiredWith(_));
            if matches!(rule, Rule::Nullable | Rule::Sometimes) {
                continue;
            }
            // presentOrRuleIsImplicit, then isNotNullIfMarkedAsNullable.
            let blank = value.is_some_and(|value| value.as_str().is_some_and(|text| text.trim().is_empty()));
            if !implicit && (value.is_none() || blank) {
                continue;
            }
            if !implicit && nullable && value.is_some_and(Value::is_null) {
                continue;
            }
            if !passes(*rule, body, value, list) {
                messages.push(message(*rule, &name, list, value));
                if implicit {
                    break;
                }
            }
        }
        if !messages.is_empty() {
            errors.push((attribute, messages));
        }
    }
    errors
}

/// Laravel's 422 ValidationException body: the first message, a count of the
/// rest, and every attribute's messages under `errors` and `validation_errors`.
pub(crate) fn failure(errors: Vec<(String, Vec<String>)>) -> Option<RuntimeError> {
    let total: usize = errors.iter().map(|(_, messages)| messages.len()).sum();
    let mut message = errors.first()?.1.first()?.clone();
    match total - 1 {
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

/// Laravel's `validated()`: the normalized body restricted to the ruled
/// top-level keys, keeping whatever those keys hold.
pub(crate) fn validated(body: &Value, rules: &Rules) -> Value {
    let mut result = Map::new();
    for (pattern, _) in rules {
        let top = pattern.split('.').next().unwrap_or(pattern);
        if let Some(value) = body.get(top) {
            result.insert(top.to_owned(), value.clone());
        }
    }
    Value::Object(result)
}

#[cfg(test)]
mod tests {
    use super::*;

    const STORE: &Rules = &[
        ("spec", &[Rule::Required, Rule::Array]),
        ("spec.cron_expressions.*", &[Rule::String]),
        ("spec.intervals.*.every", &[Rule::RequiredWith("spec.intervals"), Rule::String, Rule::Max(64)]),
        ("spec.timezone", &[Rule::Nullable, Rule::String, Rule::Max(64)]),
        ("action.workflow_type", &[Rule::Required, Rule::String]),
        ("jitter_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(0), Rule::Max(3600)]),
        ("paused", &[Rule::Nullable, Rule::Boolean]),
    ];

    fn errors(body: Value) -> Value {
        match validate(&normalize(&body), STORE) {
            Err(RuntimeError::Protocol { response, .. }) => response,
            other => panic!("{other:?}"),
        }
    }

    #[test]
    fn laravel_names_messages_and_order() {
        let response = errors(json!({"spec":{"cron_expressions":[5,"x"],"intervals":[{"offset":"x"}],"timezone":7},
            "action":{"workflow_type":"  "},"jitter_seconds":4000,"paused":"maybe"}));
        assert_eq!(response["message"], "The spec.timezone field must be a string. (and 5 more errors)");
        assert_eq!(
            response["errors"],
            json!({"spec.timezone":["The spec.timezone field must be a string."],
                "action.workflow_type":["The action.workflow type field is required."],
                "jitter_seconds":["The jitter seconds field must not be greater than 3600."],
                "paused":["The paused field must be true or false."],
                "spec.cron_expressions.0":["The spec.cron_expressions.0 field must be a string."],
                "spec.intervals.0.every":["The spec.intervals.0.every field is required when spec.intervals is present."]})
        );
        assert_eq!(response["errors"], response["validation_errors"]);
    }

    #[test]
    fn absent_attributes_skip_rules_other_than_presence() {
        assert!(validate(&normalize(&json!({"spec":{"timezone":"UTC"},"action":{"workflow_type":"echo"}})), STORE).is_ok());
        let response = errors(json!({}));
        assert_eq!(
            response["errors"],
            json!({"spec":["The spec field is required."],
                "action.workflow_type":["The action.workflow type field is required."]})
        );
    }
}
