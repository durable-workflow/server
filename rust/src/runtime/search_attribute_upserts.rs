//! The `upsert_search_attributes` workflow command, as published PHP applies
//! it. The Server's `SearchAttributeValueValidator` checks each value against
//! the namespace's registered types and resolves its storage type. The
//! Workflow package's `UpsertSearchAttributesCall` then normalizes the values,
//! and `SearchAttributeUpsertService` stores them as typed rows.
use std::{collections::BTreeMap, env};

use axum::http::StatusCode;
use chrono::{DateTime, FixedOffset, NaiveDate, NaiveTime, SecondsFormat, TimeZone, Timelike, Utc};
use serde_json::{Map, Value, json};
use sqlx::{ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

use super::{
    Result, backend::Backend, laravel_validation::failure_messages, memo_limits, refuse,
    transaction::Transaction,
    store::{Store, now},
};

/// `WorkflowMetadataCapabilityPolicy::TYPED_SEARCH_ATTRIBUTES`.
pub(super) const CAPABILITY: &str = "typed_search_attributes";
pub(super) const MINIMUM_PROTOCOL_VERSION: &str = "1.16";

/// `WorkflowSearchAttribute` limits.
const MAX_ATTRIBUTES_PER_RUN: usize = 100;
const MAX_STRING_LENGTH: usize = 2048;
const MAX_KEYWORD_LENGTH: usize = 255;
const MAX_TOTAL_SIZE_BYTES: usize = 65536;
/// `UpsertSearchAttributesCall` keys: 1-64 URL-safe characters.
const MAX_CALL_KEY_LENGTH: usize = 64;
const VALID_TYPES: [&str; 7] = ["string", "keyword", "keyword_list", "int", "float", "bool", "datetime"];

/// `SearchAttributeDefinition::canonicalType`.
pub(super) fn canonical_type(kind: &str) -> Option<&'static str> {
    match kind {
        "text" => Some("string"),
        "double" => Some("float"),
        _ => VALID_TYPES.iter().copied().find(|valid| *valid == kind),
    }
}

/// `server.limits.max_search_attribute_key_length` (bytes, default 128) and
/// `max_search_attribute_value_bytes` (default 2048), read like PHP's config.
pub(super) struct Limits {
    pub(super) key_length: i64,
    pub(super) value_bytes: i64,
}

impl Limits {
    pub(super) fn from_env() -> Result<Self> {
        let read = |primary: &str, legacy: &str, default: i64| -> Result<i64> {
            let value = |key: &str| match env::var(key) {
                Ok(value) => Ok(Some(value)),
                Err(env::VarError::NotPresent) => Ok(None),
                Err(env::VarError::NotUnicode(_)) => {
                    Err(refuse(StatusCode::BAD_REQUEST, "invalid_search_attribute_configuration"))
                }
            };
            let primary = value(primary)?.filter(|value| !memo_limits::typed_null(value));
            Ok(match primary.or(value(legacy)?) {
                Some(value) => memo_limits::cast_integer(&value),
                None => default,
            })
        };
        Ok(Self {
            key_length: read("DW_MAX_SEARCH_ATTRIBUTE_KEY_LENGTH", "WORKFLOW_MAX_SEARCH_ATTRIBUTE_KEY_LENGTH", 128)?,
            value_bytes: read(
                "DW_MAX_SEARCH_ATTRIBUTE_VALUE_BYTES",
                "WORKFLOW_MAX_SEARCH_ATTRIBUTE_VALUE_BYTES",
                2048,
            )?,
        })
    }

    fn keyword_bytes(&self) -> i64 {
        let limit = if self.value_bytes > 0 { self.value_bytes } else { MAX_KEYWORD_LENGTH as i64 };
        limit.min(MAX_KEYWORD_LENGTH as i64)
    }
}

/// A decoded JSON object key that PHP turns into an integer array key.
fn php_integer_key(key: &str) -> bool {
    let digits = key.strip_prefix('-').unwrap_or(key);
    let canonical = key == "0"
        || (!digits.is_empty()
            && !digits.starts_with('0')
            && digits.bytes().all(|byte| byte.is_ascii_digit()));
    canonical && key.parse::<i64>().is_ok()
}

fn is_scalar(value: &Value) -> bool {
    matches!(value, Value::Bool(_) | Value::Number(_) | Value::String(_))
}

fn is_int(value: &Value) -> bool {
    value.as_i64().is_some()
}

/// PHP decodes `[]` and `{}` both to an empty array, which is a list.
fn as_list(value: &Value) -> Option<Vec<Value>> {
    match value {
        Value::Array(items) => Some(items.clone()),
        Value::Object(map) if map.is_empty() => Some(Vec::new()),
        _ => None,
    }
}

/// `WorkflowSearchAttribute::inferType` for a decoded JSON value.
fn infer_type(value: &Value) -> &'static str {
    match value {
        Value::Bool(_) => "bool",
        Value::Number(number) if number.as_i64().is_some() => "int",
        Value::Number(_) => "float",
        Value::String(text) => {
            if text.chars().count() > MAX_KEYWORD_LENGTH || text.chars().any(char::is_whitespace) {
                "string"
            } else {
                "keyword"
            }
        }
        Value::Array(_) | Value::Object(_) => "keyword_list",
        Value::Null => "keyword",
    }
}

/// `Carbon::parse` for the deterministic forms it accepts: an ISO 8601
/// date, or date and time (`T`, `t` or a space between them, minutes with
/// optional seconds and fraction) with an offset written `Z`, `+HH:MM`,
/// `+HHMM` or `+HH`, or none, read as UTC like the server's timezone; and an
/// RFC 2822 date. Fractional seconds are truncated to microseconds, as PHP
/// stores them. Forms that depend on the clock, a timezone database or
/// PHP's date overflow (`tomorrow`, `@1700000000`, `Europe/Paris`,
/// `2026-02-30`, `24:00`) are refused as an unparsable value.
pub(super) fn parse_datetime(text: &str) -> Option<DateTime<Utc>> {
    let time = parse_iso_datetime(text.as_bytes())
        .or_else(|| DateTime::parse_from_rfc2822(text).ok().map(|time| time.with_timezone(&Utc)))?;
    time.with_nanosecond(time.nanosecond() / 1_000 * 1_000)
}

fn parse_iso_datetime(text: &[u8]) -> Option<DateTime<Utc>> {
    let digits = |from: usize, count: usize| -> Option<u32> {
        let part = text.get(from..from + count)?;
        part.iter().all(u8::is_ascii_digit).then(|| part.iter().fold(0, |sum, digit| sum * 10 + u32::from(digit - b'0')))
    };
    if text.get(4) != Some(&b'-') || text.get(7) != Some(&b'-') {
        return None;
    }
    let date = NaiveDate::from_ymd_opt(i32::try_from(digits(0, 4)?).ok()?, digits(5, 2)?, digits(8, 2)?)?;
    if text.len() == 10 {
        return date.and_hms_opt(0, 0, 0).map(|time| time.and_utc());
    }
    if !matches!(text.get(10), Some(b'T' | b't' | b' ')) || text.get(13) != Some(&b':') {
        return None;
    }
    let (hour, minute) = (digits(11, 2)?, digits(14, 2)?);
    let mut at = 16;
    let mut second = 0;
    let mut nanos = 0;
    if text.get(at) == Some(&b':') {
        second = digits(at + 1, 2)?;
        at += 3;
        if text.get(at) == Some(&b'.') {
            let start = at + 1;
            let end = start + text[start..].iter().take_while(|byte| byte.is_ascii_digit()).count();
            if end == start {
                return None;
            }
            nanos = text[start..end.min(start + 9)].iter().enumerate()
                .fold(0, |sum, (index, digit)| sum + u32::from(digit - b'0') * 10u32.pow(8 - index as u32));
            at = end;
        }
    }
    let local = date.and_time(NaiveTime::from_hms_nano_opt(hour, minute, second, nanos)?);
    let offset_seconds = match &text[at..] {
        [] => 0,
        [b'Z' | b'z'] => 0,
        [sign @ (b'+' | b'-'), rest @ ..] => {
            let (hours, minutes) = match rest {
                [_, _] => (digits(at + 1, 2)?, 0),
                [_, _, _, _] => (digits(at + 1, 2)?, digits(at + 3, 2)?),
                [_, _, b':', _, _] => (digits(at + 1, 2)?, digits(at + 4, 2)?),
                _ => return None,
            };
            if hours > 23 || minutes > 59 {
                return None;
            }
            let seconds = i32::try_from(hours * 3600 + minutes * 60).ok()?;
            if *sign == b'-' { -seconds } else { seconds }
        }
        _ => return None,
    };
    FixedOffset::east_opt(offset_seconds)?.from_local_datetime(&local).single().map(|time| time.with_timezone(&Utc))
}

/// `SearchAttributeValueValidator::validateForNamespace`: the storage type of
/// each attribute, or the validation messages PHP reports.
/// `definitions` holds the namespace's custom registered types.
pub(super) fn validate(
    definitions: &BTreeMap<String, String>,
    attributes: &Map<String, Value>,
    declared: &Map<String, Value>,
    limits: &Limits,
) -> std::result::Result<BTreeMap<String, &'static str>, Vec<String>> {
    let registered = |key: &str| {
        super::search_attributes::SYSTEM_ATTRIBUTES
            .iter()
            .find(|(name, _)| *name == key)
            .map(|(_, kind)| (*kind).to_owned())
            .or_else(|| definitions.get(key).cloned())
    };
    let schema_enforced = !definitions.is_empty();
    let mut messages = Vec::new();
    let mut types = BTreeMap::new();
    for (key, kind) in declared {
        if php_integer_key(key) || !attributes.contains_key(key) {
            messages.push("Declared search attribute types must name keys present in the attribute update.".to_owned());
            continue;
        }
        if kind.as_str().is_none_or(|kind| canonical_type(kind) != Some(kind)) {
            let shown = match kind {
                Value::String(text) => text.clone(),
                Value::Bool(true) => "1".to_owned(),
                Value::Bool(false) => String::new(),
                Value::Number(number) => number.to_string(),
                Value::Null => "null".to_owned(),
                Value::Array(_) | Value::Object(_) => "array".to_owned(),
            };
            messages.push(format!(
                "Search attribute [{key}] declares unsupported type [{shown}]; allowed types are {}.",
                VALID_TYPES.join(", ")
            ));
        }
    }
    for (key, value) in php_order(attributes) {
        if key.is_empty() || php_integer_key(key) {
            messages.push("Search attribute keys must be non-empty strings.".to_owned());
            continue;
        }
        if limits.key_length > 0 && key.len() as i64 > limits.key_length {
            messages.push(format!(
                "Search attribute key [{key}] exceeds the maximum length of {} bytes.",
                limits.key_length
            ));
            continue;
        }
        let mut chars = key.chars();
        if !chars.next().is_some_and(|first| first.is_ascii_alphabetic())
            || !chars.all(|character| character.is_ascii_alphanumeric() || character == '_')
        {
            messages.push(format!(
                "Search attribute key [{key}] must start with a letter and contain only letters, numbers, and underscores."
            ));
            continue;
        }
        let registered_type = registered(key).and_then(|kind| canonical_type(&kind));
        let declared_type = declared.get(key).and_then(Value::as_str).and_then(canonical_type);
        if let (Some(registered), Some(declared)) = (registered_type, declared_type)
            && registered != declared
        {
            messages.push(format!(
                "Search attribute [{key}] declares type [{declared}] but is registered as [{registered}]."
            ));
            continue;
        }
        if let Some(effective) = registered_type.or(declared_type) {
            validate_declared_value(&mut messages, key, value, effective, limits);
            if !value.is_null() || declared_type.is_some() {
                types.insert(key.clone(), effective);
            }
            continue;
        }
        if schema_enforced {
            messages.push(format!("Search attribute [{key}] is not registered for this namespace."));
            continue;
        }
        if !value.is_null() && !is_scalar(value) {
            messages.push(format!(
                "Search attribute [{key}] must be a scalar value or null unless it is registered as keyword_list."
            ));
        } else if let Value::String(text) = value
            && limits.value_bytes > 0
            && text.len() as i64 > limits.value_bytes
        {
            messages.push(format!(
                "Search attribute [{key}] value exceeds the maximum of {} bytes.",
                limits.value_bytes
            ));
        }
        if !value.is_null() {
            types.insert(key.clone(), infer_type(value));
        }
    }
    if messages.is_empty() { Ok(types) } else { Err(messages) }
}

fn validate_declared_value(messages: &mut Vec<String>, key: &str, value: &Value, kind: &str, limits: &Limits) {
    if value.is_null() {
        return;
    }
    let exceeds = |bytes: usize, limit: i64| limit > 0 && bytes as i64 > limit;
    match kind {
        "keyword" | "string" => match value {
            Value::String(text) => {
                let limit = if kind == "keyword" { limits.keyword_bytes() } else { limits.value_bytes };
                if exceeds(text.len(), limit) {
                    messages.push(format!("Search attribute [{key}] value exceeds the maximum of {limit} bytes."));
                }
            }
            _ => messages.push(format!("Search attribute [{key}] is registered as {kind} and must be a string.")),
        },
        "int" if !is_int(value) => {
            messages.push(format!("Search attribute [{key}] is registered as int and must be an integer."));
        }
        "float" if !value.is_number() => {
            messages.push(format!("Search attribute [{key}] is registered as float and must be a number."));
        }
        "bool" if !value.is_boolean() => {
            messages.push(format!("Search attribute [{key}] is registered as bool and must be a boolean."));
        }
        "datetime" => {
            let rfc = || format!("Search attribute [{key}] is registered as datetime and must be an RFC3339 datetime string.");
            match value {
                Value::String(text) if exceeds(text.len(), limits.value_bytes) => messages.push(format!(
                    "Search attribute [{key}] value exceeds the maximum of {} bytes.",
                    limits.value_bytes
                )),
                Value::String(text) if parse_datetime(text).is_some() => {}
                _ => messages.push(rfc()),
            }
        }
        "keyword_list" => {
            let Some(items) = as_list(value) else {
                messages.push(format!(
                    "Search attribute [{key}] is registered as keyword_list and must be a list of strings."
                ));
                return;
            };
            let limit = limits.keyword_bytes();
            for item in items {
                let Value::String(text) = item else {
                    messages.push(format!(
                        "Search attribute [{key}] is registered as keyword_list and must contain only strings."
                    ));
                    return;
                };
                if exceeds(text.len(), limit) {
                    messages.push(format!("Search attribute [{key}] list value exceeds the maximum of {limit} bytes."));
                    return;
                }
            }
        }
        _ => {}
    }
}

/// `UpsertSearchAttributesCall`: trimmed values, an empty string as a
/// deletion, sorted keys. An error is the `LogicException` message that
/// `WorkflowCommandNormalizer` reports for the command's attributes.
fn normalized_call(attributes: &Map<String, Value>) -> std::result::Result<BTreeMap<String, Value>, String> {
    if attributes.is_empty() {
        return Err("Upsert search attributes commands require a non-empty attributes object.".to_owned());
    }
    let mut normalized = BTreeMap::new();
    for (key, value) in php_order(attributes) {
        if php_integer_key(key)
            || key.is_empty()
            || key.chars().count() > MAX_CALL_KEY_LENGTH
            || !key.bytes().all(|byte| byte.is_ascii_alphanumeric() || b"_.:-".contains(&byte))
        {
            return Err("Workflow v2 search attribute keys must be 1-64 URL-safe characters using letters, numbers, \".\", \"_\", \"-\", and \":\".".to_owned());
        }
        let value = match value {
            Value::Null => Value::Null,
            Value::String(text) => {
                let trimmed = php_trim(text);
                if trimmed.chars().count() > MAX_STRING_LENGTH {
                    return Err(format!("Workflow v2 search attribute [{key}] must be up to {MAX_STRING_LENGTH} characters."));
                }
                if trimmed.is_empty() { Value::Null } else { json!(trimmed) }
            }
            Value::Bool(_) | Value::Number(_) => value.clone(),
            Value::Array(_) | Value::Object(_) => {
                let items = as_list(value)
                    .ok_or_else(|| format!("Workflow v2 search attribute [{key}] list value must be a JSON array."))?;
                let mut list = Vec::with_capacity(items.len());
                for item in items {
                    let Value::String(text) = item else {
                        return Err(format!("Workflow v2 search attribute [{key}] list values must contain only strings."));
                    };
                    let trimmed = php_trim(&text);
                    if trimmed.chars().count() > MAX_KEYWORD_LENGTH {
                        return Err(format!(
                            "Workflow v2 search attribute [{key}] list values must be up to {MAX_KEYWORD_LENGTH} characters."
                        ));
                    }
                    list.push(json!(trimmed));
                }
                Value::Array(list)
            }
        };
        normalized.insert(key.clone(), value);
    }
    Ok(normalized)
}

/// The order PHP visits a decoded object's keys in: integer-like keys
/// first, as PHP reports them. Native compares the remaining keys sorted,
/// not in request order.
fn php_order(map: &Map<String, Value>) -> impl Iterator<Item = (&String, &Value)> {
    map.iter()
        .filter(|(key, _)| php_integer_key(key))
        .chain(map.iter().filter(|(key, _)| !php_integer_key(key)))
}

/// PHP's `trim`: spaces, tabs, newlines, carriage returns, NUL and vertical tabs.
fn php_trim(text: &str) -> &str {
    text.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0b'])
}

/// One typed row: its type and the value for that type's column.
#[derive(Clone, Debug, PartialEq)]
pub(super) enum Typed {
    String(String),
    Keyword(String),
    KeywordList(Vec<String>),
    Int(i64),
    Float(f64),
    Bool(bool),
    Datetime(DateTime<Utc>),
}

impl Typed {
    pub(super) fn kind(&self) -> &'static str {
        match self {
            Self::String(_) => "string",
            Self::Keyword(_) => "keyword",
            Self::KeywordList(_) => "keyword_list",
            Self::Int(_) => "int",
            Self::Float(_) => "float",
            Self::Bool(_) => "bool",
            Self::Datetime(_) => "datetime",
        }
    }

    /// `WorkflowSearchAttribute::getValue`, as PHP serializes it.
    pub(super) fn json(&self) -> Value {
        match self {
            Self::String(text) | Self::Keyword(text) => json!(text),
            Self::KeywordList(items) => json!(items),
            Self::Int(number) => json!(number),
            Self::Float(number) => json!(number),
            Self::Bool(flag) => json!(flag),
            Self::Datetime(time) => json!(time.to_rfc3339_opts(SecondsFormat::Micros, true)),
        }
    }

    /// `validateAttributeSet`'s size of one stored value.
    fn size(&self) -> usize {
        match self {
            Self::String(text) | Self::Keyword(text) => text.len(),
            Self::KeywordList(items) => items.iter().map(String::len).sum(),
            Self::Int(_) | Self::Float(_) | Self::Datetime(_) => 8,
            Self::Bool(_) => 1,
        }
    }
}

/// `WorkflowSearchAttribute::setTypedValue` for a validated value.
fn typed(value: &Value, kind: &str) -> Option<Typed> {
    let text = || match value {
        Value::String(text) => Some(text.clone()),
        Value::Number(number) => Some(number.to_string()),
        Value::Bool(true) => Some("1".to_owned()),
        Value::Bool(false) => Some(String::new()),
        _ => None,
    };
    Some(match kind {
        "string" => Typed::String(text().filter(|text| text.chars().count() <= MAX_STRING_LENGTH)?),
        "keyword" => Typed::Keyword(text().filter(|text| text.chars().count() <= MAX_KEYWORD_LENGTH)?),
        "keyword_list" => Typed::KeywordList(
            value
                .as_array()?
                .iter()
                .map(|item| {
                    item.as_str()
                        .filter(|text| text.chars().count() <= MAX_KEYWORD_LENGTH)
                        .map(str::to_owned)
                })
                .collect::<Option<_>>()?,
        ),
        "int" => Typed::Int(value.as_i64()?),
        "float" => Typed::Float(value.as_f64().filter(|_| value.is_number())?),
        "bool" => Typed::Bool(value.as_bool()?),
        "datetime" => Typed::Datetime(value.as_str().and_then(parse_datetime)?),
        _ => return None,
    })
}

/// A start's search attributes after the controller's registry validation:
/// the values as the request named them, which the start events record,
/// and their validated types.
pub(super) struct StartSearchAttributes {
    pub(super) authored: Map<String, Value>,
    pub(super) types: BTreeMap<String, &'static str>,
}

impl StartSearchAttributes {
    /// Core's start seeding (`UpsertSearchAttributesCall`, then
    /// `SearchAttributeUpsertService::validateAttributeSet`). The controller
    /// reports the `LogicException` either throws under `workflow_type`.
    pub(super) fn prepare(&self) -> Result<PreparedUpsert> {
        let prepared = prepare(&self.authored, &self.types, "workflow_type")?;
        match attribute_set_refusal(prepared.rows.values()) {
            None => Ok(prepared),
            Some(message) => Err(failure_messages(vec![("workflow_type".to_owned(), vec![message])]).expect("message")),
        }
    }
}

/// A validated, normalized upsert ready to store.
pub(super) struct PreparedUpsert {
    /// Normalized values; `null` deletes the key.
    pub(super) attributes: BTreeMap<String, Value>,
    /// `canonicalTypes`: the stored type of every typed key.
    pub(super) attribute_types: BTreeMap<String, &'static str>,
    /// Typed rows to write, by key.
    pub(super) rows: BTreeMap<String, Typed>,
}

/// The bridge's `normalizeUpsertSearchAttributesCommand` and
/// `canonicalTypes` after the Server resolved `attribute_types`.
pub(super) fn prepare(
    attributes: &Map<String, Value>,
    attribute_types: &BTreeMap<String, &'static str>,
    field: &str,
) -> Result<PreparedUpsert> {
    let invalid = || refuse(StatusCode::UNPROCESSABLE_ENTITY, "invalid_commands");
    let attributes = normalized_call(attributes).map_err(|message| {
        failure_messages(vec![(field.to_owned(), vec![message])]).expect("message")
    })?;
    let mut rows = BTreeMap::new();
    for (key, kind) in attribute_types {
        let value = attributes.get(key).ok_or_else(invalid)?;
        if !value.is_null() {
            rows.insert(key.clone(), typed(value, kind).ok_or_else(invalid)?);
        }
    }
    let mut types = attribute_types.clone();
    for (key, value) in &attributes {
        if !value.is_null() && !types.contains_key(key) {
            let kind = infer_type(value);
            rows.insert(key.clone(), typed(value, kind).ok_or_else(invalid)?);
            types.insert(key.clone(), kind);
        }
    }
    Ok(PreparedUpsert {
        attributes,
        attribute_types: types,
        rows,
    })
}

/// `validateAttributeSet` over the merged rows. Its
/// `InvalidArgumentException` reaches the worker completion's catch
/// (`WorkerController::completeWorkflowTask`), which rejects the completion
/// with `invalid_workflow_stream_command` and the exception's message.
fn check_attribute_set<'a>(rows: impl Iterator<Item = &'a Typed>, task_id: &Value, attempt: &Value) -> Result<()> {
    match attribute_set_refusal(rows) {
        None => Ok(()),
        Some(message) => Err(super::RuntimeError::Protocol {
            status: StatusCode::UNPROCESSABLE_ENTITY,
            response: json!({"task_id":task_id,"workflow_task_attempt":attempt,"outcome":"rejected","recorded":false,
                "reason":INVALID_COMMAND,"error":message}),
        }),
    }
}

const INVALID_COMMAND: &str = "invalid_workflow_stream_command";

/// The same catch answers `workflow_memo_validation_failed` instead when the
/// completion also carries an `upsert_memo` command
/// (`commandsUseWorkflowMemoUpdates`).
pub(super) fn with_memo_commands(commands: &[Value], error: super::RuntimeError) -> super::RuntimeError {
    match error {
        super::RuntimeError::Protocol { status, mut response }
            if response["reason"] == INVALID_COMMAND && commands.iter().any(|command| command["type"] == "upsert_memo") =>
        {
            response["reason"] = json!("workflow_memo_validation_failed");
            super::RuntimeError::Protocol { status, response }
        }
        other => other,
    }
}

/// `validateAttributeSet`'s message for a set over the per-run limits.
fn attribute_set_refusal<'a>(rows: impl Iterator<Item = &'a Typed>) -> Option<String> {
    let (count, bytes) = rows.fold((0usize, 0usize), |(count, bytes), row| (count + 1, bytes + row.size()));
    if count > MAX_ATTRIBUTES_PER_RUN {
        Some(format!("Search attributes count exceeds maximum ({count} > {MAX_ATTRIBUTES_PER_RUN})"))
    } else if bytes > MAX_TOTAL_SIZE_BYTES {
        Some(format!("Total search attributes size exceeds maximum ({bytes} > {MAX_TOTAL_SIZE_BYTES} bytes)"))
    } else {
        None
    }
}

/// The upsert's `attributes`, as PHP decodes them: a JSON list becomes an
/// array with integer keys, which the validator then refuses.
fn attribute_map(command: &Value) -> Option<Map<String, Value>> {
    match &command["attributes"] {
        Value::Object(map) => Some(map.clone()),
        Value::Array(items) => Some(items.iter().enumerate().map(|(index, item)| (index.to_string(), item.clone())).collect()),
        _ => None,
    }
}

/// Laravel's `commands.*.attribute_types` rules: `nullable|array`, each a string.
pub(super) fn validate_shape(command: &Value, index: usize) -> Result<()> {
    // Laravel shows a wildcard rule's expanded attribute as it is.
    let field = format!("commands.{index}.attribute_types");
    let messages = match &command["attribute_types"] {
        Value::Null => Vec::new(),
        Value::Object(types) => types
            .iter()
            .filter(|(_, kind)| !kind.is_string())
            .map(|(key, _)| (format!("commands.{index}.attribute_types.{key}"), vec![format!("The {field}.{key} field must be a string.")]))
            .collect(),
        Value::Array(types) => types
            .iter()
            .enumerate()
            .filter(|(_, kind)| !kind.is_string())
            .map(|(key, _)| (format!("commands.{index}.attribute_types.{key}"), vec![format!("The {field}.{key} field must be a string.")]))
            .collect(),
        _ => vec![(format!("commands.{index}.attribute_types"), vec![format!("The {field} field must be an array.")])],
    };
    if let Some(error) = failure_messages(messages) {
        return Err(error);
    }
    if !matches!(command["attributes"], Value::Null | Value::Object(_) | Value::Array(_)) {
        return Err(failure_messages(vec![(
            format!("commands.{index}.attributes"),
            vec![format!("The commands.{index}.attributes field must be an array.")],
        )])
        .expect("one message"));
    }
    Ok(())
}

impl<DB: Backend> Store<DB>
where
    for<'c> &'c mut DB::Connection: Executor<'c, Database = DB>,
    DB::Arguments: IntoArguments<DB>,
    for<'q> &'q str: Encode<'q, DB> + Type<DB>,
    for<'q> Option<&'q str>: Encode<'q, DB>,
    for<'q> Option<DB::EncodedDocument>: Encode<'q, DB>,
    for<'q> String: Encode<'q, DB> + Type<DB>,
    for<'q> i64: Encode<'q, DB> + Type<DB>,
    for<'r> i64: Decode<'r, DB>,
    usize: ColumnIndex<DB::Row>,
{
    /// The run's typed rows by key, as `WorkflowRun::typedSearchAttributes`
    /// reads them. Rows of an unknown type are left out.
    pub(super) async fn typed_rows(
        connection: &mut DB::Connection,
        run_id: &str,
    ) -> Result<BTreeMap<String, Typed>> {
        let rows = Self::query(DB::SEARCH_ATTRIBUTE_ROWS)
            .bind(run_id)
            .fetch_all(&mut *connection)
            .await?;
        let mut typed = BTreeMap::new();
        for row in rows {
            if let Some(value) = Self::typed_value(&row)? {
                typed.insert(DB::string(&row, "attribute_key")?, value);
            }
        }
        Ok(typed)
    }

    /// One typed row's value; a row of an unknown type has none.
    pub(super) fn typed_value(row: &DB::Row) -> Result<Option<Typed>> {
        Ok(match DB::string(row, "type")?.as_str() {
            "string" => DB::optional_string(row, "value_string")?.map(Typed::String),
            "keyword" => DB::optional_string(row, "value_keyword")?.map(Typed::Keyword),
            "keyword_list" => Some(Typed::KeywordList(
                DB::optional_document_row(row, "value_keyword_list")?
                    .and_then(|list| serde_json::from_value::<Vec<String>>(list).ok())
                    .unwrap_or_default(),
            )),
            "int" => DB::optional_number(row, "value_int")?.map(Typed::Int),
            "float" => DB::optional_float(row, "value_float")?.map(Typed::Float),
            "bool" => DB::optional_number(row, "value_bool")?.map(|flag| Typed::Bool(flag != 0)),
            "datetime" => DB::optional_instant(row, "value_datetime")?.map(Typed::Datetime),
            _ => None,
        })
    }

    /// `SearchAttributeUpsertService::upsert`'s writes: each key set to
    /// `null` is deleted and each other key stores its typed row at
    /// `sequence`.
    pub(super) async fn write_typed_rows(
        tx: &mut Transaction<'_, DB>,
        run_id: &str,
        workflow_id: &str,
        prepared: &PreparedUpsert,
        sequence: i64,
    ) -> Result<()> {
        let written = now();
        for (key, value) in &prepared.attributes {
            if value.is_null() {
                Self::query(DB::SEARCH_ATTRIBUTE_DELETE)
                    .bind(run_id)
                    .bind(key.as_str())
                    .execute(&mut **tx)
                    .await?;
                continue;
            }
            let row = &prepared.rows[key];
            let text = |kind| match row {
                Typed::String(text) if kind == "string" => Some(text.as_str()),
                Typed::Keyword(text) if kind == "keyword" => Some(text.as_str()),
                _ => None,
            };
            Self::query(DB::SEARCH_ATTRIBUTE_UPSERT)
                .bind(run_id)
                .bind(workflow_id)
                .bind(key.as_str())
                .bind(row.kind())
                .bind(text("string"))
                .bind(text("keyword"))
                .bind(match row {
                    Typed::KeywordList(items) => Some(DB::document(&json!(items))),
                    _ => None,
                })
                .bind(DB::optional_integer(match row {
                    Typed::Int(number) => Some(*number),
                    _ => None,
                }))
                .bind(DB::optional_float_value(match row {
                    Typed::Float(number) => Some(*number),
                    _ => None,
                }))
                .bind(DB::optional_flag(match row {
                    Typed::Bool(flag) => Some(*flag),
                    _ => None,
                }))
                .bind(DB::optional_time(match row {
                    Typed::Datetime(time) => Some(*time),
                    _ => None,
                }))
                .bind(sequence)
                .bind(DB::bind_time(written))
                .bind(DB::bind_time(written))
                .execute(&mut **tx)
                .await?;
        }
        Ok(())
    }

    /// A start's `search_attributes` (Server `WorkflowController::start`):
    /// `SearchAttributeValueValidator::validateForNamespace` against the
    /// namespace's registry, before the reservation and routing checks.
    /// Returns nothing for an absent, null or empty value. Core normalizes
    /// and stores them later, inside the start (`StartSearchAttributes::prepare`).
    pub(super) async fn validate_start_search_attributes(
        tx: &mut Transaction<'_, DB>,
        namespace: &str,
        value: &Value,
    ) -> Result<Option<StartSearchAttributes>> {
        let Some(attributes) = attribute_map(&json!({"attributes": value})).filter(|attributes| !attributes.is_empty()) else {
            return Ok(None);
        };
        let definitions = Self::query("SELECT name,type FROM search_attribute_definitions WHERE namespace=$1")
            .bind(namespace)
            .fetch_all(&mut **tx)
            .await?
            .iter()
            .map(|row| Ok((DB::string(row, "name")?, DB::string(row, "type")?)))
            .collect::<Result<BTreeMap<_, _>>>()?;
        let types = validate(&definitions, &attributes, &Map::new(), &Limits::from_env()?).map_err(|messages| {
            failure_messages(vec![("search_attributes".to_owned(), messages)]).expect("messages")
        })?;
        Ok(Some(StartSearchAttributes { authored: attributes, types }))
    }

    /// The bridge's `applyUpsertSearchAttributes` after the Server's
    /// `validateWorkflowTaskSearchAttributeCommands`.
    pub(super) async fn upsert_search_attributes(
        &self,
        tx: &mut Transaction<'_, DB>,
        run: &DB::Row,
        task: &DB::Row,
        command: &Value,
        index: usize,
        sequence: i64,
    ) -> Result<()> {
        let run_id = DB::string(run, "id")?;
        let task_id = DB::string(task, "id")?;
        // PHP fills in each upsert's registered types before its second
        // capability check, so any upsert with attributes needs the capability
        // (`validateWorkflowTaskSearchAttributeCommands`).
        if command.get("attribute_types").is_some() || command["attributes"].is_object() || command["attributes"].is_array() {
            let worker_id = DB::optional_string(task, "lease_owner")?
                .ok_or_else(|| refuse(StatusCode::CONFLICT, "task_not_leased"))?;
            let worker = Self::query("SELECT capabilities FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
                .bind(self.namespace.as_ref()).bind(&worker_id).fetch_optional(&mut **tx).await?;
            let capable = match &worker {
                Some(worker) => DB::optional_document_row(worker, "capabilities")?
                    .is_some_and(|flags| flags.as_array().is_some_and(|flags| flags.iter().any(|flag| flag == CAPABILITY))),
                None => false,
            };
            if !capable {
                return Err(super::RuntimeError::Protocol {
                    status: StatusCode::CONFLICT,
                    response: json!({"task_id":task_id,"workflow_task_attempt":DB::number(task, "attempt_count")?,
                        "outcome":"rejected","recorded":false,"reason":"workflow_metadata_capability_not_advertised",
                        "capability":CAPABILITY,"command_type":"upsert_search_attributes",
                        "minimum_protocol_version":MINIMUM_PROTOCOL_VERSION,
                        "remediation":format!("Re-register lease owner {worker_id} with the {CAPABILITY} capability before emitting upsert_search_attributes.")}),
                });
            }
        }
        let attributes = attribute_map(command).unwrap_or_default();
        let declared = command["attribute_types"].as_object().cloned().unwrap_or_default();
        let definitions = Self::query("SELECT name,type FROM search_attribute_definitions WHERE namespace=$1")
            .bind(self.namespace.as_ref())
            .fetch_all(&mut **tx)
            .await?
            .iter()
            .map(|row| Ok((DB::string(row, "name")?, DB::string(row, "type")?)))
            .collect::<Result<BTreeMap<_, _>>>()?;
        let types = validate(&definitions, &attributes, &declared, &Limits::from_env()?).map_err(|messages| {
            failure_messages(vec![(format!("commands.{index}.attributes"), messages)]).expect("messages")
        })?;
        let prepared = prepare(&attributes, &types, &format!("commands.{index}.attributes"))?;
        let existing = Self::typed_rows(&mut **tx, &run_id).await?;
        let mut stored = existing.clone();
        let mut merged: BTreeMap<String, Value> =
            existing.iter().map(|(key, value)| (key.clone(), value.json())).collect();
        for (key, value) in &prepared.attributes {
            if value.is_null() {
                stored.remove(key);
                merged.remove(key);
            } else {
                stored.insert(key.clone(), prepared.rows[key].clone());
                merged.insert(key.clone(), value.clone());
            }
        }
        check_attribute_set(stored.values(), &json!(task_id), &json!(DB::number(task, "attempt_count")?))?;
        let workflow_id = DB::string(run, "workflow_instance_id")?;
        Self::write_typed_rows(tx, &run_id, &workflow_id, &prepared, sequence).await?;
        // PHP serializes an empty map as a JSON list.
        let object = |map: Map<String, Value>| if map.is_empty() { json!([]) } else { Value::Object(map) };
        Self::append(
            tx,
            &run_id,
            "SearchAttributesUpserted",
            json!({"sequence":sequence,
                "attributes":object(prepared.attributes.into_iter().collect()),
                "attribute_types":object(prepared.attribute_types.into_iter().map(|(key, kind)| (key, json!(kind))).collect()),
                "merged":object(merged.into_iter().collect()),
                "task":Self::task_snapshot(task)?}),
            Some(&task_id),
            None,
        )
        .await
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn limits() -> Limits {
        Limits { key_length: 128, value_bytes: 2048 }
    }

    fn map(value: Value) -> Map<String, Value> {
        value.as_object().cloned().unwrap()
    }

    #[test]
    fn registered_declared_and_inferred_types_resolve_like_php() {
        let definitions = BTreeMap::from([
            ("Ratio".to_owned(), "double".to_owned()),
            ("Note".to_owned(), "text".to_owned()),
        ]);
        let types = validate(
            &definitions,
            &map(json!({"Ratio": 2, "Note": "a b", "Tags": ["x"], "WorkflowType": "manual", "Gone": null})),
            &map(json!({"Tags": "keyword_list", "Gone": "keyword"})),
            &limits(),
        )
        .unwrap();
        assert_eq!(
            types,
            BTreeMap::from([
                ("Gone".to_owned(), "keyword"),
                ("Note".to_owned(), "string"),
                ("Ratio".to_owned(), "float"),
                ("Tags".to_owned(), "keyword_list"),
                ("WorkflowType".to_owned(), "keyword"),
            ])
        );
        // Without custom definitions, values infer their type.
        let inferred = validate(
            &BTreeMap::new(),
            &map(json!({"Id": "a1", "Text": "two words", "Count": 3, "Score": 1.5, "Flag": true, "Gone": null})),
            &Map::new(),
            &limits(),
        )
        .unwrap();
        assert_eq!(
            inferred,
            BTreeMap::from([
                ("Count".to_owned(), "int"),
                ("Flag".to_owned(), "bool"),
                ("Id".to_owned(), "keyword"),
                ("Score".to_owned(), "float"),
                ("Text".to_owned(), "string"),
            ])
        );
    }

    #[test]
    fn validation_messages_match_php() {
        let definitions = BTreeMap::from([("Count".to_owned(), "int".to_owned())]);
        let messages = validate(
            &definitions,
            &map(json!({"Count": "3", "1bad": 1, "Other": 1, "7": 1})),
            &map(json!({"Count": "keyword", "Missing": "int"})),
            &limits(),
        )
        .unwrap_err();
        assert_eq!(
            messages,
            [
                "Declared search attribute types must name keys present in the attribute update.",
                "Search attribute keys must be non-empty strings.",
                "Search attribute key [1bad] must start with a letter and contain only letters, numbers, and underscores.",
                "Search attribute [Count] declares type [keyword] but is registered as [int].",
                "Search attribute [Other] is not registered for this namespace.",
            ]
        );
        let declared = validate(
            &BTreeMap::new(),
            &map(json!({"A": 1.5, "B": "x", "C": 2, "D": "not a time", "E": ["ok", 3], "F": "界".repeat(86), "G": {"k": 1}})),
            &map(json!({"A": "int", "B": "bool", "C": "text", "D": "datetime", "E": "keyword_list", "F": "keyword"})),
            &limits(),
        )
        .unwrap_err();
        assert_eq!(
            declared,
            [
                "Search attribute [C] declares unsupported type [text]; allowed types are string, keyword, keyword_list, int, float, bool, datetime.",
                "Search attribute [A] is registered as int and must be an integer.",
                "Search attribute [B] is registered as bool and must be a boolean.",
                "Search attribute [C] is registered as string and must be a string.",
                "Search attribute [D] is registered as datetime and must be an RFC3339 datetime string.",
                "Search attribute [E] is registered as keyword_list and must contain only strings.",
                "Search attribute [F] value exceeds the maximum of 255 bytes.",
                "Search attribute [G] must be a scalar value or null unless it is registered as keyword_list.",
            ]
        );
    }

    #[test]
    fn prepared_values_are_trimmed_typed_and_sized_like_php() {
        let attributes = map(json!({"Tags": [" urgent ", "urgent"], "Name": "  ", "Count": 9_007_199_254_740_993_i64,
            "Ratio": 2, "At": "2026-10-08T12:34:56.123456Z", "Gone": null}));
        let types = BTreeMap::from([
            ("At".to_owned(), "datetime"),
            ("Count".to_owned(), "int"),
            ("Name".to_owned(), "keyword"),
            ("Ratio".to_owned(), "float"),
            ("Tags".to_owned(), "keyword_list"),
        ]);
        let prepared = prepare(&attributes, &types, "commands.0.attributes").unwrap();
        assert_eq!(prepared.attributes["Tags"], json!(["urgent", "urgent"]));
        // A blank string deletes its key but keeps its declared type.
        assert_eq!(prepared.attributes["Name"], Value::Null);
        assert_eq!(prepared.attribute_types["Name"], "keyword");
        assert!(!prepared.rows.contains_key("Name") && !prepared.rows.contains_key("Gone"));
        assert_eq!(prepared.rows["Ratio"], Typed::Float(2.0));
        assert_eq!(prepared.rows["Count"], Typed::Int(9_007_199_254_740_993));
        assert_eq!(prepared.rows["At"].json(), json!("2026-10-08T12:34:56.123456Z"));
        let long_key = "k".repeat(65);
        assert!(prepare(&map(json!({long_key: 1})), &BTreeMap::new(), "commands.0.attributes").is_err());
        let many: Vec<Typed> = (0..101).map(Typed::Int).collect();
        assert!(check_attribute_set(many.iter(), &json!("task"), &json!(1)).is_err());
        assert!(check_attribute_set(many[..100].iter(), &json!("task"), &json!(1)).is_ok());
    }

    #[test]
    fn datetimes_parse_as_carbon_reads_their_deterministic_forms() {
        let at = |text: &str| parse_datetime(text).map(|time| time.to_rfc3339_opts(SecondsFormat::Micros, true));
        for (text, stored) in [
            ("2026-10-08T12:34:56+02:00", "2026-10-08T10:34:56.000000Z"),
            ("2026-10-08T12:34:56.123456-05:30", "2026-10-08T18:04:56.123456Z"),
            ("2026-10-08T12:34:56+0200", "2026-10-08T10:34:56.000000Z"),
            ("2026-10-08T12:34:56+02", "2026-10-08T10:34:56.000000Z"),
            ("2026-10-08T12:34:56.123456789Z", "2026-10-08T12:34:56.123456Z"),
            ("2026-10-08T12:34:56.9999995Z", "2026-10-08T12:34:56.999999Z"),
            ("2026-10-08 12:34:56+02:00", "2026-10-08T10:34:56.000000Z"),
            ("2026-10-08t10:34:56z", "2026-10-08T10:34:56.000000Z"),
            ("Thu, 08 Oct 2026 12:34:56 +0200", "2026-10-08T10:34:56.000000Z"),
            ("2026-10-08 12:34:56", "2026-10-08T12:34:56.000000Z"),
            ("2026-10-08T12:34", "2026-10-08T12:34:00.000000Z"),
            ("2026-10-08T12:34+02:00", "2026-10-08T10:34:00.000000Z"),
            ("2026-10-08", "2026-10-08T00:00:00.000000Z"),
        ] {
            assert_eq!(at(text).as_deref(), Some(stored), "{text}");
        }
        for text in ["not a date", "", "tomorrow", "@1700000000", "2026-02-30", "2026-10-08T24:00:00Z",
            "2026-10-08T12:34:56 Europe/Paris", "20261008T123456Z", "2026-10-08T12:34:56.", "2026-10-08T12:34:56+2",
            "2026-10-08T12:34:56+24:00", "2026-10-8", "2026-10-08T12:34:56Zx"] {
            assert_eq!(at(text), None, "{text}");
        }
    }
}
