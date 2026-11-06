//! `GET /api/schedules` as published PHP's `ScheduleController::index`:
//! filters, the schedule visibility query, signed continuation tokens and
//! list items, with PHP's refusals.
use super::laravel_rules::{Rule, Rules, errors, normalize};
use super::schedule_storage::{
    LAST_SKIP_REASON_MAX_BYTES, LAST_SKIPPED_AT_MAX_BYTES, iso8601, last_skip,
};
use super::{
    Result, Runtime, RuntimeError,
    backend::Backend,
    schedules,
    store::Store,
};
use axum::{
    Extension, Json,
    extract::RawQuery,
    http::StatusCode,
};
use base64::{Engine, engine::general_purpose::URL_SAFE_NO_PAD};
use chrono::{DateTime, NaiveDateTime, Utc};
use hmac::{Hmac, KeyInit, Mac};
use serde_json::{Map, Value, json};
use sha2::{Digest, Sha256};
use sqlx::{AssertSqlSafe, ColumnIndex, Decode, Encode, Executor, IntoArguments, Type};

const ALLOWED: [&str; 5] = ["status", "workflow_type", "query", "page_size", "next_page_token"];

const LIST_RULES: &Rules = &[
    ("status", &[Rule::Sometimes, Rule::Required, Rule::String, Rule::In(&["active", "paused"])]),
    (
        "workflow_type",
        &[Rule::Sometimes, Rule::Required, Rule::String, Rule::Min(1), Rule::Max(255), Rule::NotBlank],
    ),
    ("query", &[Rule::Sometimes, Rule::Required, Rule::String, Rule::Min(1), Rule::Max(2048)]),
    ("page_size", &[Rule::Sometimes, Rule::Required, Rule::Integer, Rule::Min(1), Rule::Max(200)]),
    ("next_page_token", &[Rule::Sometimes, Rule::Required, Rule::String, Rule::Min(1), Rule::Max(8192)]),
];

/// A schedule system field a visibility predicate can name.
#[derive(Clone, Copy, PartialEq, Eq, Debug)]
pub(crate) enum Column {
    ScheduleId,
    Status,
    WorkflowType,
    TaskQueue,
    Note,
}

impl Column {
    fn named(field: &str) -> Option<(Self, &'static str, &'static str)> {
        Some(match field {
            "ScheduleId" => (Self::ScheduleId, "schedule_id", "keyword"),
            "Status" => (Self::Status, "status", "schedule_status"),
            "WorkflowType" => (Self::WorkflowType, "action->workflow_type", "keyword"),
            "TaskQueue" => (Self::TaskQueue, "action->task_queue", "keyword"),
            "Note" => (Self::Note, "note", "text"),
            _ => return None,
        })
    }

    /// The SQL expression Laravel compiles for this column.
    fn sql(self, backend: &str) -> &'static str {
        match (self, backend) {
            (Self::ScheduleId, _) => "s.schedule_id",
            (Self::Status, _) => "s.status",
            (Self::Note, _) => "s.note",
            (Self::WorkflowType, "SQLite") => "json_extract(s.action,'$.\"workflow_type\"')",
            (Self::TaskQueue, "SQLite") => "json_extract(s.action,'$.\"task_queue\"')",
            (Self::WorkflowType, "PostgreSQL") => "(s.action->>'workflow_type')",
            (Self::TaskQueue, "PostgreSQL") => "(s.action->>'task_queue')",
            (Self::WorkflowType, _) => "JSON_UNQUOTE(JSON_EXTRACT(s.action,'$.\"workflow_type\"'))",
            (Self::TaskQueue, _) => "JSON_UNQUOTE(JSON_EXTRACT(s.action,'$.\"task_queue\"'))",
        }
    }
}

/// One parsed `Field = literal` predicate.
#[derive(Clone, Debug, PartialEq)]
pub(crate) struct Predicate {
    field: String,
    column: Option<(Column, &'static str)>,
    kind: String,
    literal: Value,
}

/// `ScheduleVisibilityQueryException`.
#[derive(Debug, PartialEq)]
pub(crate) struct QueryError {
    pub(crate) message: String,
    pub(crate) reason: &'static str,
}

fn query_error(message: impl Into<String>) -> QueryError {
    QueryError {
        message: message.into(),
        reason: "invalid_schedule_visibility_query",
    }
}

/// `ScheduleVisibilityQuery::parse` against the namespace's registered
/// search attribute types.
pub(crate) fn parse_query(
    query: &str,
    definitions: &[(String, String)],
) -> std::result::Result<Vec<Predicate>, QueryError> {
    let query = query.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0b']);
    if query.is_empty() {
        return Err(query_error("Schedule visibility query must not be empty."));
    }
    let parts = split_and(query).ok_or_else(|| {
        query_error("Schedule visibility query contains an unterminated string or empty predicate.")
    })?;
    let mut predicates = Vec::new();
    for part in parts {
        let unquoted = without_quoted(&part);
        let operator = ["!=", "<>", ">=", "<=", ">", "<"]
            .iter()
            .any(|operator| unquoted.contains(operator));
        let keyword = unquoted
            .split(|character: char| !(character.is_ascii_alphanumeric() || character == '_'))
            .any(|word| {
                ["OR", "NOT", "IN", "LIKE", "CONTAINS", "STARTS_WITH", "ENDS_WITH"]
                    .iter()
                    .any(|keyword| word.eq_ignore_ascii_case(keyword))
            });
        if operator || keyword {
            return Err(QueryError {
                message: "Schedule visibility supports only equality predicates joined by AND.".into(),
                reason: "unsupported_schedule_visibility_predicate",
            });
        }
        let Some((field, literal)) = equality(&part) else {
            return Err(query_error("Schedule visibility predicates must use: Field = literal."));
        };
        let literal = parse_literal(literal.trim_matches(|c: char| c.is_ascii_whitespace()))?;
        let (column, kind) = match Column::named(field) {
            Some((column, name, kind)) => (Some((column, name)), kind.to_owned()),
            None => match definitions.iter().find(|(name, _)| name == field) {
                Some((_, kind)) => (None, kind.clone()),
                None => {
                    return Err(QueryError {
                        message: format!(
                            "Schedule visibility field [{field}] is not searchable. Use ScheduleId, Status, \
                             WorkflowType, TaskQueue, Note, or a registered search attribute."
                        ),
                        reason: "unsupported_schedule_visibility_field",
                    });
                }
            },
        };
        let valid = match kind.as_str() {
            "keyword" | "string" | "text" | "datetime" | "keyword_list" => literal.is_string(),
            "int" => literal.is_i64(),
            "double" => literal.is_number(),
            "bool" => literal.is_boolean(),
            "schedule_status" => matches!(literal.as_str(), Some("active" | "paused")),
            _ => false,
        };
        if !valid {
            let wanted = match kind.as_str() {
                "int" => "integer",
                "double" => "number",
                "bool" => "boolean",
                "schedule_status" => "quoted active or paused",
                _ => "quoted string",
            };
            return Err(query_error(format!(
                "Schedule visibility field [{field}] requires a {wanted} literal."
            )));
        }
        predicates.push(Predicate {
            field: field.to_owned(),
            column,
            kind,
            literal,
        });
    }
    Ok(predicates)
}

/// `/^([A-Za-z][A-Za-z0-9_]*)\s*=\s*(.+)$/s`.
fn equality(part: &str) -> Option<(&str, &str)> {
    let first = part.chars().next()?;
    if !first.is_ascii_alphabetic() {
        return None;
    }
    let end = part
        .find(|character: char| !(character.is_ascii_alphanumeric() || character == '_'))
        .unwrap_or(part.len());
    let (field, rest) = part.split_at(end);
    let rest = rest.trim_start_matches(|c: char| c.is_ascii_whitespace() || c == '\x0b');
    let literal = rest.strip_prefix('=')?;
    let literal = literal.trim_start_matches(|c: char| c.is_ascii_whitespace() || c == '\x0b');
    (!literal.is_empty()).then_some((field, literal))
}

/// `splitAndPredicates`: split on a blank-delimited `AND` outside quotes.
fn split_and(query: &str) -> Option<Vec<String>> {
    let bytes = query.as_bytes();
    let mut parts = Vec::new();
    let mut current = Vec::new();
    let mut quote = None;
    let mut index = 0;
    let space = |byte: Option<&u8>| byte.is_none_or(|byte| byte.is_ascii_whitespace() || *byte == 0x0b);
    while index < bytes.len() {
        let byte = bytes[index];
        if let Some(open) = quote {
            current.push(byte);
            if byte == b'\\' && index + 1 < bytes.len() {
                index += 1;
                current.push(bytes[index]);
            } else if byte == open {
                quote = None;
            }
            index += 1;
            continue;
        }
        if byte == b'"' || byte == b'\'' {
            quote = Some(byte);
            current.push(byte);
            index += 1;
            continue;
        }
        if bytes.len() >= index + 3
            && bytes[index..index + 3].eq_ignore_ascii_case(b"AND")
            && space(index.checked_sub(1).and_then(|before| bytes.get(before)).or(Some(&b' ')))
            && space(bytes.get(index + 3))
        {
            let part = String::from_utf8_lossy(&current).trim().to_owned();
            if part.is_empty() {
                return None;
            }
            parts.push(part);
            current.clear();
            index += 3;
            continue;
        }
        current.push(byte);
        index += 1;
    }
    let last = String::from_utf8_lossy(&current).trim().to_owned();
    if quote.is_some() || last.is_empty() {
        return None;
    }
    parts.push(last);
    Some(parts)
}

/// `withoutQuotedLiterals`: quoted text replaced by spaces.
fn without_quoted(value: &str) -> String {
    let bytes = value.as_bytes();
    let mut result = String::new();
    let mut quote = None;
    let mut index = 0;
    while index < bytes.len() {
        let byte = bytes[index];
        if let Some(open) = quote {
            if byte == b'\\' && index + 1 < bytes.len() {
                index += 1;
            } else if byte == open {
                quote = None;
            }
            result.push(' ');
        } else if byte == b'"' || byte == b'\'' {
            quote = Some(byte);
            result.push(' ');
        } else {
            result.push(byte as char);
        }
        index += 1;
    }
    result
}

/// `parseLiteral`.
fn single_quoted_literal(literal: &str) -> std::result::Result<Value, QueryError> {
    let invalid = || query_error(format!(
        "Schedule visibility literal [{literal}] is not a valid quoted string."
    ));
    let mut characters = literal[1..].chars();
    let mut value = String::new();
    while let Some(character) = characters.next() {
        match character {
            '\'' => return if characters.next().is_none() { Ok(json!(value)) } else { Err(invalid()) },
            '\\' => {
                let Some(escaped) = characters.next() else { return Err(invalid()); };
                // Unknown escapes are literal data in PHP's single-quoted grammar.
                if !matches!(escaped, '\\' | '\'') {
                    value.push('\\');
                }
                value.push(escaped);
            }
            other => value.push(other),
        }
    }
    Err(invalid())
}

fn parse_literal(literal: &str) -> std::result::Result<Value, QueryError> {
    if literal.is_empty() {
        return Err(query_error("Schedule visibility predicate literal must not be empty."));
    }
    if literal.starts_with('"') {
        return match serde_json::from_str::<Value>(literal) {
            Ok(Value::String(text)) => Ok(json!(text)),
            _ => Err(query_error(format!(
                "Schedule visibility literal [{literal}] is not a valid JSON string."
            ))),
        };
    }
    if literal.starts_with('\'') {
        return single_quoted_literal(literal);
    }
    if literal.eq_ignore_ascii_case("true") {
        return Ok(json!(true));
    }
    if literal.eq_ignore_ascii_case("false") {
        return Ok(json!(false));
    }
    let digits = literal.strip_prefix('-').unwrap_or(literal);
    let canonical = |text: &str| {
        !text.is_empty()
            && text.bytes().all(|byte| byte.is_ascii_digit())
            && (text == "0" || !text.starts_with('0'))
    };
    if canonical(digits) {
        return literal.parse::<i64>().map(|number| json!(number)).map_err(|_| query_error(format!(
            "Schedule visibility integer literal [{literal}] is outside the signed 64-bit range."
        )));
    }
    if let Some((whole, fraction)) = digits.split_once('.')
        && canonical(whole)
        && !fraction.is_empty()
        && fraction.bytes().all(|byte| byte.is_ascii_digit())
        && let Ok(number) = literal.parse::<f64>()
        && number.is_finite()
    {
        return Ok(json!(number));
    }
    Err(query_error(format!(
        "Schedule visibility literal [{literal}] must be a quoted string, number, or boolean."
    )))
}

/// PHP's `$request->query()` from a raw query string: the last value of a
/// repeated key wins, and `key[]` / `key[name]` build arrays.
pub(crate) fn query_input(raw: Option<&str>) -> Vec<(String, Value)> {
    let mut input: Vec<(String, Value)> = Vec::new();
    for (key, value) in form_urlencoded::parse(raw.unwrap_or_default().as_bytes()) {
        let (name, nested) = match key.split_once('[') {
            Some((name, rest)) if rest.ends_with(']') && !name.is_empty() => {
                (name.to_owned(), Some(rest[..rest.len() - 1].to_owned()))
            }
            _ => (key.into_owned(), None),
        };
        let slot = match input.iter().position(|(existing, _)| *existing == name) {
            Some(position) => position,
            None => {
                input.push((name, Value::Null));
                input.len() - 1
            }
        };
        let current = &mut input[slot].1;
        match nested {
            None => *current = json!(value),
            Some(index) => {
                if !current.is_array() && !current.is_object() {
                    *current = json!([]);
                }
                if index.is_empty() {
                    match current {
                        Value::Array(items) => items.push(json!(value)),
                        Value::Object(fields) => {
                            let next = fields.len().to_string();
                            fields.insert(next, json!(value));
                        }
                        _ => {}
                    }
                } else {
                    if let Value::Array(items) = current {
                        *current = Value::Object(
                            items.drain(..).enumerate().map(|(i, v)| (i.to_string(), v)).collect(),
                        );
                    }
                    current
                        .as_object_mut()
                        .expect("an object")
                        .insert(index, json!(value));
                }
            }
        }
    }
    input
}

/// `scheduleListError`.
fn list_error(
    status: StatusCode,
    reason: &str,
    field: &str,
    message: &str,
    errors: Option<Value>,
    cursor: Option<Value>,
) -> RuntimeError {
    let errors = errors.unwrap_or_else(|| json!({field:[message]}));
    RuntimeError::Protocol {
        status,
        response: json!({"message":message,"reason":reason,"field":field,"errors":errors,
            "validation_errors":errors,"last_safe_cursor":cursor}),
    }
}

/// A decoded continuation token.
struct Token {
    namespace: String,
    fingerprint: String,
    created_at: String,
    schedule_id: String,
}

fn token_key(secret: &str) -> Vec<u8> {
    Sha256::digest(format!("durable-workflow.schedule-list.page-token|{secret}")).to_vec()
}

fn signature(encoded: &str, secret: &str) -> String {
    let mut mac = Hmac::<Sha256>::new_from_slice(&token_key(secret)).expect("HMAC key");
    mac.update(encoded.as_bytes());
    mac.finalize()
        .into_bytes()
        .iter()
        .map(|byte| format!("{byte:02x}"))
        .collect()
}

fn valid_cursor_time(text: &str) -> bool {
    NaiveDateTime::parse_from_str(text, "%Y-%m-%d %H:%M:%S%.6f")
        .is_ok_and(|time| time.format("%Y-%m-%d %H:%M:%S%.6f").to_string() == text)
}

fn hex64(text: &str) -> bool {
    text.len() == 64 && text.bytes().all(|byte| byte.is_ascii_digit() || (b'a'..=b'f').contains(&byte))
}

fn encode_token(namespace: &str, fingerprint: &str, created_at: &str, schedule_id: &str, secret: &str) -> String {
    let payload = json!({"version":1,"namespace":namespace,"filter_fingerprint":fingerprint,
        "cursor":{"created_at":created_at,"schedule_id":schedule_id}});
    let encoded = URL_SAFE_NO_PAD.encode(payload.to_string());
    let signature = signature(&encoded, secret);
    format!("{encoded}.{signature}")
}

fn decode_token(token: &str, secret: &str) -> Option<Token> {
    let (encoded, given) = token.split_once('.')?;
    if encoded.is_empty() || given.contains('.') || !hex64(given) {
        return None;
    }
    if !bool::from(subtle::ConstantTimeEq::ct_eq(signature(encoded, secret).as_bytes(), given.as_bytes())) {
        return None;
    }
    if !encoded.bytes().all(|byte| byte.is_ascii_alphanumeric() || byte == b'_' || byte == b'-') {
        return None;
    }
    let payload: Value = serde_json::from_slice(&URL_SAFE_NO_PAD.decode(encoded).ok()?).ok()?;
    let text = |value: &Value| value.as_str().map(str::to_owned);
    let cursor = &payload["cursor"];
    let token = Token {
        namespace: text(&payload["namespace"]).filter(|value| !value.is_empty())?,
        fingerprint: text(&payload["filter_fingerprint"]).filter(|value| hex64(value))?,
        created_at: text(&cursor["created_at"]).filter(|value| valid_cursor_time(value))?,
        schedule_id: text(&cursor["schedule_id"]).filter(|value| !value.is_empty())?,
    };
    (payload["version"] == 1 && payload["version"].is_i64()).then_some(token)
}

/// `publicScheduleCursor`.
fn public_cursor(token: &Token) -> Value {
    let created = NaiveDateTime::parse_from_str(&token.created_at, "%Y-%m-%d %H:%M:%S%.6f")
        .map(|time| time.format("%Y-%m-%dT%H:%M:%S%.6fZ").to_string())
        .unwrap_or_else(|_| token.created_at.clone());
    json!({"created_at":created,"schedule_id":token.schedule_id})
}

fn cursor_time(time: DateTime<Utc>) -> String {
    time.format("%Y-%m-%d %H:%M:%S%.6f").to_string()
}

/// PHP's default `json_encode` of a string: `/` and every non-ASCII
/// character escaped.
fn php_string(text: &str) -> String {
    let mut encoded = String::from("\"");
    for character in text.chars() {
        match character {
            '"' => encoded.push_str("\\\""),
            '\\' => encoded.push_str("\\\\"),
            '/' => encoded.push_str("\\/"),
            '\u{8}' => encoded.push_str("\\b"),
            '\u{c}' => encoded.push_str("\\f"),
            '\n' => encoded.push_str("\\n"),
            '\r' => encoded.push_str("\\r"),
            '\t' => encoded.push_str("\\t"),
            character if character.is_ascii() && (character as u32) >= 0x20 => encoded.push(character),
            character => {
                let mut units = [0; 2];
                for unit in character.encode_utf16(&mut units) {
                    encoded.push_str(&format!("\\u{unit:04x}"));
                }
            }
        }
    }
    encoded.push('"');
    encoded
}

fn php_scalar(value: &Value) -> String {
    match value {
        Value::String(text) => php_string(text),
        other => other.to_string(),
    }
}

/// `scheduleFilterFingerprint`: SHA-256 of PHP's JSON, in PHP's key order.
fn fingerprint(selection: &Selection) -> String {
    let optional = |value: &Option<String>| value.as_deref().map_or("null".to_owned(), php_string);
    let predicates = selection
        .predicates
        .iter()
        .map(|predicate| {
            format!(
                "{{\"field\":{},\"column\":{},\"type\":{},\"literal\":{}}}",
                php_string(&predicate.field),
                predicate.column.map_or("null".to_owned(), |(_, name)| php_string(name)),
                php_string(&predicate.kind),
                php_scalar(&predicate.literal)
            )
        })
        .collect::<Vec<_>>()
        .join(",");
    let json = format!(
        "{{\"status\":{},\"workflow_type\":{},\"visibility_predicates\":[{predicates}]}}",
        optional(&selection.status),
        optional(&selection.workflow_type)
    );
    Sha256::digest(json).iter().map(|byte| format!("{byte:02x}")).collect()
}

/// The filtered set's selection: PHP's `scheduleListQuery`.
pub(crate) struct Selection {
    status: Option<String>,
    workflow_type: Option<String>,
    predicates: Vec<Predicate>,
}

// Preserve existing list fields without transferring either stored action array.
const LIST_COLUMNS: &[&str] = &[
    "schedule_id",
    "status",
    "action",
    "next_fire_at",
    "last_fired_at",
    "overlap_policy",
    "note",
    "namespace",
    "spec",
    "search_attributes",
    "created_at",
    "updated_at",
    "fires_count",
    "jitter_seconds",
    "max_runs",
    "remaining_actions",
    "failures_count",
    "skipped_trigger_count",
];

fn byte_length(backend: &str, column: &str) -> String {
    if backend == "SQLite" {
        format!("length(CAST({column} AS BLOB))")
    } else {
        format!("OCTET_LENGTH({column})")
    }
}

fn exact_identity(backend: &str, left: &str, right: &str) -> String {
    if backend == "MySQL" {
        format!("({left}={right} AND BINARY {left}=BINARY {right})")
    } else {
        format!("{left}={right}")
    }
}

fn list_selection(backend: &str, cursor_probe: bool) -> String {
    if cursor_probe {
        return "SELECT s.schedule_id,s.created_at FROM workflow_schedules s".into();
    }
    let mut columns = LIST_COLUMNS
        .iter()
        .map(|name| format!("s.{name}"))
        .collect::<Vec<_>>();
    let scalars = [
        (
            "s.latest_workflow_instance_id",
            "latest_workflow_instance_id",
            764,
        ),
        ("i.current_run_id", "summary_current_run_pointer", 104),
        ("r.id", "summary_run_id", 104),
        ("r.status", "summary_run_status", 32),
    ];
    let mut invalid = Vec::new();
    for (column, alias, limit) in scalars {
        let length = byte_length(backend, column);
        columns.push(format!(
            "CASE WHEN {length}<={limit} THEN {column} ELSE NULL END AS {alias}"
        ));
        invalid.push(format!(
            "({column} IS NOT NULL AND ({length}=0 OR {length}>{limit}))"
        ));
    }
    let reason_length = byte_length(backend, "s.last_skip_reason");
    columns.push(format!("CASE WHEN {reason_length}<={LAST_SKIP_REASON_MAX_BYTES} THEN s.last_skip_reason ELSE NULL END AS last_skip_reason"));
    invalid.push(format!("(s.last_skip_reason IS NOT NULL AND {reason_length}>{LAST_SKIP_REASON_MAX_BYTES})"));
    let timestamp_text = match backend {
        "PostgreSQL" => "CAST(s.last_skipped_at AS TEXT)",
        "MySQL" => "CAST(s.last_skipped_at AS CHAR)",
        _ => "s.last_skipped_at",
    };
    let timestamp_length = byte_length(backend, timestamp_text);
    columns.push(format!("CASE WHEN {timestamp_length}<={LAST_SKIPPED_AT_MAX_BYTES} THEN s.last_skipped_at ELSE NULL END AS last_skipped_at"));
    invalid.push(format!("(s.last_skipped_at IS NOT NULL AND {timestamp_length}>{LAST_SKIPPED_AT_MAX_BYTES})"));
    columns.push(format!(
        "CASE WHEN {} THEN 1 ELSE 0 END AS summary_invalid",
        invalid.join(" OR ")
    ));
    let instance = exact_identity(backend, "i.id", "s.latest_workflow_instance_id");
    let instance_namespace = exact_identity(backend, "i.namespace", "s.namespace");
    let run = exact_identity(backend, "r.id", "i.current_run_id");
    let run_instance = exact_identity(backend, "r.workflow_instance_id", "i.id");
    let run_namespace = exact_identity(backend, "r.namespace", "s.namespace");
    format!(
        "SELECT {} FROM workflow_schedules s LEFT JOIN workflow_instances i ON {instance} AND {instance_namespace} LEFT JOIN workflow_runs r ON {run} AND {run_instance} AND {run_namespace}",
        columns.join(",")
    )
}

fn invalid_summary() -> RuntimeError {
    super::refuse(StatusCode::SERVICE_UNAVAILABLE, "storage_unavailable")
}

fn summary_identifier(value: Option<String>, max_characters: usize) -> Result<Option<String>> {
    if value
        .as_ref()
        .is_some_and(|value| value.is_empty() || value.chars().count() > max_characters)
    {
        return Err(invalid_summary());
    }
    Ok(value)
}

enum Bind {
    Text(String),
    Time(DateTime<Utc>),
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
    pub(super) async fn search_attribute_types(&self) -> Result<Vec<(String, String)>> {
        let rows = Self::query("SELECT name,type FROM search_attribute_definitions WHERE namespace=$1 ORDER BY name")
            .bind(self.namespace.as_ref())
            .fetch_all(&self.pool)
            .await?;
        rows.iter()
            .map(|row| Ok((DB::string(row, "name")?, DB::string(row, "type")?)))
            .collect()
    }

    /// The selected live schedules, newest first, after an optional cursor.
    async fn select_schedules(
        &self,
        selection: &Selection,
        after: Option<(&str, &str)>,
        only: Option<&str>,
        limit: i64,
    ) -> Result<Vec<DB::Row>> {
        // A native schedule never holds search attribute values.
        if selection
            .predicates
            .iter()
            .any(|predicate| predicate.column.is_none())
        {
            return Ok(Vec::new());
        }
        let mysql = DB::NAME == "MySQL";
        let mut binds = Vec::new();
        let mut sql = list_selection(DB::NAME, only.is_some());
        sql.push_str(" WHERE s.namespace=");
        let mut placeholder = |sql: &mut String, bind: Bind| {
            binds.push(bind);
            if mysql {
                sql.push('?');
            } else {
                sql.push_str(&format!("${}", binds.len()));
            }
        };
        placeholder(&mut sql, Bind::Text(self.namespace.to_string()));
        if mysql {
            sql.push_str(" AND BINARY s.namespace=BINARY ");
            placeholder(&mut sql, Bind::Text(self.namespace.to_string()));
        }
        sql.push_str(" AND s.status!='deleted'");
        let mut equal = |sql: &mut String, column: Column, value: String| {
            sql.push_str(" AND ");
            sql.push_str(column.sql(DB::NAME));
            sql.push('=');
            placeholder(sql, Bind::Text(value));
        };
        if let Some(status) = &selection.status {
            equal(&mut sql, Column::Status, status.clone());
        }
        if let Some(workflow_type) = &selection.workflow_type {
            equal(&mut sql, Column::WorkflowType, workflow_type.clone());
        }
        for predicate in &selection.predicates {
            let (column, _) = predicate.column.expect("system field");
            equal(
                &mut sql,
                column,
                predicate.literal.as_str().unwrap_or_default().to_owned(),
            );
        }
        if let Some(schedule_id) = only {
            equal(&mut sql, Column::ScheduleId, schedule_id.to_owned());
        }
        if let Some((created, schedule_id)) = after {
            let time = NaiveDateTime::parse_from_str(created, "%Y-%m-%d %H:%M:%S%.6f")
                .map(|time| time.and_utc())
                .unwrap_or_default();
            sql.push_str(" AND (s.created_at<");
            placeholder(&mut sql, Bind::Time(time));
            sql.push_str(" OR (s.created_at=");
            placeholder(&mut sql, Bind::Time(time));
            sql.push_str(" AND s.schedule_id>");
            placeholder(&mut sql, Bind::Text(schedule_id.to_owned()));
            sql.push_str("))");
        }
        sql.push_str(&format!(
            " ORDER BY s.created_at DESC,s.schedule_id LIMIT {limit}"
        ));
        let mut query = sqlx::query::<DB>(AssertSqlSafe(sql));
        for bind in binds {
            query = match bind {
                Bind::Text(text) => query.bind(text),
                Bind::Time(time) => query.bind(DB::bind_time(time)),
            };
        }
        Ok(query.fetch_all(&self.pool).await?)
    }

    fn list_summary(row: &DB::Row) -> Result<Value> {
        if DB::number(row, "summary_invalid")? != 0 {
            return Err(invalid_summary());
        }
        let latest = summary_identifier(
            DB::optional_string(row, "latest_workflow_instance_id")?,
            191,
        )?;
        // Validate the complete bounded pointer even when its target was pruned.
        summary_identifier(DB::optional_string(row, "summary_current_run_pointer")?, 26)?;
        let run = summary_identifier(DB::optional_string(row, "summary_run_id")?, 26)?;
        let status = DB::optional_string(row, "summary_run_status")?;
        let current = match (run, status) {
            (None, None) => Value::Null,
            (Some(run), Some(status)) => {
                let bucket =
                    super::visibility::status_bucket(&status).map_err(|_| invalid_summary())?;
                json!({"run_id":run,"status":status,"status_bucket":bucket,"is_terminal":bucket!="running"})
            }
            _ => return Err(invalid_summary()),
        };
        let failures = DB::number(row, "failures_count").map_err(|_| invalid_summary())?;
        let skipped = DB::number(row, "skipped_trigger_count").map_err(|_| invalid_summary())?;
        if failures < 0 || skipped < 0 {
            return Err(invalid_summary());
        }
        Ok(
            json!({"failures_count":failures,"skipped_trigger_count":skipped,
            "latest_workflow_instance_id":latest,"current_run":current}),
        )
    }

    /// Shared source controls called by each existing disposable backend suite.
    #[cfg(test)]
    pub(super) async fn qualify_schedule_list_summary(&self) {
        use axum::response::IntoResponse;
        async fn refused(error: RuntimeError) {
            let response = error.into_response();
            assert_eq!(response.status(), StatusCode::SERVICE_UNAVAILABLE);
            let body = axum::body::to_bytes(response.into_body(), 1024).await.unwrap();
            assert_eq!(serde_json::from_slice::<Value>(&body).unwrap(),
                json!({"reason":"storage_unavailable","message":"storage_unavailable"}));
        }
        Self::query("INSERT INTO workflow_schedules(id,schedule_id,namespace,action,spec,recent_actions,buffered_actions,failures_count,skipped_trigger_count,latest_workflow_instance_id) VALUES ('summary','summary',$1,$2,$3,$4,$5,2,3,'instance')")
            .bind(self.namespace.as_ref()).bind(DB::document(&json!({"workflow_type":"echo","task_queue":"test"})))
            .bind(DB::document(&json!({}))).bind(DB::document(&json!({"malformed":"deferred"})))
            .bind(DB::document(&json!({"malformed":"deferred"}))).execute(&self.pool).await.unwrap();
        let rows=self.select_schedules(&Selection {status:None,workflow_type:None,predicates:vec![]},None,None,50).await.unwrap();
        use sqlx::{Row,Column};
        assert!(rows[0].columns().iter().all(|column| !["recent_actions","buffered_actions"].contains(&column.name())));
        let page = self.list_schedules(None,"fixture-secret").await.unwrap();
        assert_eq!(page["schedules"][0]["failures_count"],2);
        assert_eq!(page["schedules"][0]["skipped_trigger_count"],3);
        assert!(page["schedules"][0]["current_run"].is_null());
        Self::query("INSERT INTO workflow_instances(id,workflow_class,workflow_type,namespace,current_run_id) VALUES ('instance','Echo','echo',$1,'run')")
            .bind(self.namespace.as_ref()).execute(&self.pool).await.unwrap();
        Self::query("INSERT INTO workflow_instances(id,workflow_class,workflow_type,namespace) VALUES ('other','Echo','echo',$1)")
            .bind(self.namespace.as_ref()).execute(&self.pool).await.unwrap();
        Self::query("INSERT INTO workflow_runs(id,workflow_instance_id,run_number,workflow_class,workflow_type,namespace,status) VALUES ('run','instance',1,'Echo','echo',$1,'running')")
            .bind(self.namespace.as_ref()).execute(&self.pool).await.unwrap();
        for (status,bucket,terminal) in [("pending","running",false),("running","running",false),("waiting","running",false),
            ("completed","completed",true),("failed","failed",true),("cancelled","failed",true),("terminated","failed",true)] {
            Self::query("UPDATE workflow_runs SET status=$1 WHERE id='run'").bind(status).execute(&self.pool).await.unwrap();
            assert_eq!(self.list_schedules(None,"fixture-secret").await.unwrap()["schedules"][0]["current_run"],
                json!({"run_id":"run","status":status,"status_bucket":bucket,"is_terminal":terminal}));
        }
        for invalid in ["Instance","instance ","absent"] {
            Self::query("UPDATE workflow_schedules SET latest_workflow_instance_id=$1").bind(invalid).execute(&self.pool).await.unwrap();
            assert!(self.list_schedules(None,"fixture-secret").await.unwrap()["schedules"][0]["current_run"].is_null());
        }
        Self::query("UPDATE workflow_schedules SET latest_workflow_instance_id='instance'").execute(&self.pool).await.unwrap();
        for namespace in ["Default","default ","other"] {
            Self::query("UPDATE workflow_instances SET namespace=$1 WHERE id='instance'").bind(namespace).execute(&self.pool).await.unwrap();
            assert!(self.list_schedules(None,"fixture-secret").await.unwrap()["schedules"][0]["current_run"].is_null());
        }
        Self::query("UPDATE workflow_instances SET namespace=$1 WHERE id='instance'").bind(self.namespace.as_ref()).execute(&self.pool).await.unwrap();
        Self::query("UPDATE workflow_runs SET namespace='other' WHERE id='run'").execute(&self.pool).await.unwrap();
        assert!(self.list_schedules(None,"fixture-secret").await.unwrap()["schedules"][0]["current_run"].is_null());
        Self::query("UPDATE workflow_runs SET namespace=$1,workflow_instance_id='other' WHERE id='run'").bind(self.namespace.as_ref()).execute(&self.pool).await.unwrap();
        assert!(self.list_schedules(None,"fixture-secret").await.unwrap()["schedules"][0]["current_run"].is_null());
        Self::query("UPDATE workflow_runs SET workflow_instance_id='instance' WHERE id='run'").execute(&self.pool).await.unwrap();
        for bad in ["unknown-storage-status".to_owned(),"x".repeat(33),String::new()] {
            Self::query("UPDATE workflow_runs SET status=$1 WHERE id='run'").bind(bad).execute(&self.pool).await.unwrap();
            refused(self.list_schedules(None,"fixture-secret").await.unwrap_err()).await;
        }
        Self::query("UPDATE workflow_runs SET status='running' WHERE id='run'").execute(&self.pool).await.unwrap();
        for bad in ["", "missing"] {
            Self::query("UPDATE workflow_instances SET current_run_id=$1 WHERE id='instance'").bind(bad).execute(&self.pool).await.unwrap();
            if bad.is_empty() {refused(self.list_schedules(None,"fixture-secret").await.unwrap_err()).await;}
            else {assert!(self.list_schedules(None,"fixture-secret").await.unwrap()["schedules"][0]["current_run"].is_null());}
        }
        Self::query("UPDATE workflow_instances SET current_run_id='run' WHERE id='instance'").execute(&self.pool).await.unwrap();
        // Complete values that fit every catalog. NUL has no PostgreSQL text cell.
        let mut identities=vec!["🙂".repeat(191),"x".repeat(191)];
        if DB::NAME!="PostgreSQL" {identities.push("ab\0cd🙂".into());}
        for exact in identities {
            Self::query("UPDATE workflow_schedules SET latest_workflow_instance_id=$1").bind(exact.as_str()).execute(&self.pool).await.unwrap();
            assert_eq!(self.list_schedules(None,"fixture-secret").await.unwrap()["schedules"][0]["latest_workflow_instance_id"],exact);
        }
        println!("schedule summary {}: NUL {}",DB::NAME,if DB::NAME=="PostgreSQL" {"storage-domain unsupported; no decoder pass"} else {"complete scalar decoded"});
        Self::query("UPDATE workflow_schedules SET latest_workflow_instance_id='instance'").execute(&self.pool).await.unwrap();
        let mut run_ids=vec!["🙂".repeat(26),"x".repeat(26)];
        if DB::NAME!="PostgreSQL" {run_ids.push("ab\0cd🙂".into());}
        for exact in run_ids {
            Self::query("UPDATE workflow_runs SET id=$1 WHERE workflow_instance_id='instance'").bind(exact.as_str()).execute(&self.pool).await.unwrap();
            Self::query("UPDATE workflow_instances SET current_run_id=$1 WHERE id='instance'").bind(exact.as_str()).execute(&self.pool).await.unwrap();
            assert_eq!(self.list_schedules(None,"fixture-secret").await.unwrap()["schedules"][0]["current_run"]["run_id"],exact);
        }
        Self::query("UPDATE workflow_runs SET id='run' WHERE workflow_instance_id='instance'").execute(&self.pool).await.unwrap();
        Self::query("UPDATE workflow_instances SET current_run_id='run' WHERE id='instance'").execute(&self.pool).await.unwrap();
        if DB::NAME=="MySQL" {
            for field in ["failures_count","skipped_trigger_count"] {
                sqlx::query::<DB>(AssertSqlSafe(format!("UPDATE workflow_schedules SET {field}=18446744073709551615"))).execute(&self.pool).await.unwrap();
                let row=Self::query("SELECT failures_count,skipped_trigger_count FROM workflow_schedules").fetch_one(&self.pool).await.unwrap();
                assert!(matches!(DB::number(&row,field),Err(RuntimeError::Refused {status:StatusCode::CONFLICT,reason:"unsupported_stored_integer"})),"unchanged shared counter decoder exposes old 409 path");
                refused(self.list_schedules(None,"fixture-secret").await.unwrap_err()).await;
                sqlx::query::<DB>(AssertSqlSafe(format!("UPDATE workflow_schedules SET {field}=0"))).execute(&self.pool).await.unwrap();
            }
            // Make a full referenced-table scan materially different from a PK lookup.
            for index in 0..256 {
                let instance=format!("plan-instance-{index:03}");let run=format!("plan-run-{index:03}");
                Self::query("INSERT INTO workflow_instances(id,workflow_class,workflow_type,namespace) VALUES ($1,'Echo','echo','default')").bind(instance.as_str()).execute(&self.pool).await.unwrap();
                Self::query("INSERT INTO workflow_runs(id,workflow_instance_id,run_number,workflow_class,workflow_type,namespace,status) VALUES ($1,$2,1,'Echo','echo','default','completed')").bind(run.as_str()).bind(instance.as_str()).execute(&self.pool).await.unwrap();
            }
            let plan=sqlx::query::<DB>(AssertSqlSafe(format!("EXPLAIN {} WHERE s.namespace=? AND BINARY s.namespace=BINARY ? AND s.status!='deleted' ORDER BY s.created_at DESC,s.schedule_id LIMIT 51",list_selection(DB::NAME,false))))
                .bind(self.namespace.as_ref()).bind(self.namespace.as_ref()).fetch_all(&self.pool).await.unwrap();
            for alias in ["i","r"] {
                let row=plan.iter().find(|row| DB::optional_string(row,"table").unwrap().as_deref()==Some(alias)).expect("joined plan row");
                let key=DB::optional_string(row,"key").unwrap();let access=DB::string(row,"type").unwrap();
                println!("schedule summary {} EXPLAIN {alias}: key={key:?} access={access}",DB::NAME);
                assert_eq!(key.as_deref(),Some("PRIMARY"));
                assert!(matches!(access.as_str(),"eq_ref"|"ref"|"const"));
            }
        }
        self.qualify_schedule_last_skip().await;
    }

    #[cfg(test)]
    async fn qualify_schedule_last_skip(&self) {
        let schedule = "skip-projection";
        Self::query("INSERT INTO workflow_schedules(id,schedule_id,namespace,action,spec) VALUES ($1,$2,$3,$4,$5)")
            .bind(schedule).bind(schedule).bind(self.namespace.as_ref())
            .bind(DB::document(&json!({"workflow_type":"echo","task_queue":"test"})))
            .bind(DB::document(&json!({}))).execute(&self.pool).await.unwrap();
        let at = chrono::DateTime::parse_from_rfc3339("2026-11-05T12:00:01.000001Z")
            .unwrap()
            .with_timezone(&Utc);
        let maximum_reason = "🙂".repeat(64);
        let mut reasons = vec![None, Some(""), Some("NULL"), Some("  PaUsEd  "), Some(maximum_reason.as_str())];
        if DB::NAME != "PostgreSQL" {
            reasons.push(Some("NUL\0tail"));
        }
        for reason in reasons {
            Self::query("UPDATE workflow_schedules SET last_skip_reason=$1,last_skipped_at=$2 WHERE id=$3")
                .bind(reason).bind(DB::bind_time(at)).bind(schedule).execute(&self.pool).await.unwrap();
            let page = self.list_schedules(None,"fixture-secret").await.unwrap();
            let item = page["schedules"].as_array().unwrap().iter()
                .find(|item| item["schedule_id"]==schedule).unwrap();
            let detail = self.describe_schedule(schedule).await.unwrap();
            assert_eq!(item["last_skip_reason"],json!(reason));
            assert_eq!(item["last_skipped_at"],"2026-11-05T12:00:01.000001Z");
            assert_eq!(item["last_skip_reason"],detail["info"]["last_skip_reason"]);
            assert_eq!(item["last_skipped_at"],detail["info"]["last_skipped_at"]);
        }
        Self::query("DELETE FROM workflow_schedules WHERE id=$1").bind(schedule).execute(&self.pool).await.unwrap();
    }

    /// `ScheduleController::formatListItem`.
    fn list_item(row: &DB::Row) -> Result<Value> {
        let status = DB::string(row, "status")?;
        let action = DB::optional_document_row(row, "action")?;
        let skipped = last_skip::<DB>(row)?;
        let time = |field: &str| -> Result<Value> { Ok(json!(DB::optional_instant(row, field)?.map(iso8601))) };
        let mut item = json!({"schedule_id":DB::string(row,"schedule_id")?,
            "workflow_type":action.as_ref().map_or(Value::Null, |action| action["workflow_type"].clone()),
            "status":status,"paused":status=="paused","next_fire":time("next_fire_at")?,"last_fire":time("last_fired_at")?,
            "overlap_policy":DB::string(row,"overlap_policy")?,"note":DB::optional_string(row,"note")?,
            "namespace":DB::optional_string(row,"namespace")?,"spec":DB::optional_document_row(row,"spec")?,
            "action":action.map(|action| schedules::normalize_action_timeouts(&action)),
            "next_fire_at":time("next_fire_at")?,"last_fired_at":time("last_fired_at")?,
            "search_attributes":DB::optional_document_row(row,"search_attributes")?,
            "created_at":time("created_at")?,"updated_at":time("updated_at")?,
            "fires_count":DB::number(row,"fires_count")?,
            "last_skip_reason":skipped["last_skip_reason"],"last_skipped_at":skipped["last_skipped_at"]});
        let summary = Self::list_summary(row)?;
        item.as_object_mut()
            .expect("list item")
            .extend(summary.as_object().expect("summary").clone());
        let jitter = DB::number(row, "jitter_seconds")?;
        if jitter > 0 {
            item["jitter_seconds"] = json!(jitter);
        }
        for field in ["max_runs", "remaining_actions"] {
            if let Some(value) = DB::optional_number(row, field)? {
                item[field] = json!(value);
            }
        }
        Ok(item)
    }

    pub(crate) async fn list_schedules(&self, raw: Option<&str>, secret: &str) -> Result<Value> {
        let input: Vec<(String, Value)> = query_input(raw)
            .into_iter()
            .filter(|(key, _)| key != "namespace")
            .collect();
        if let Some((field, _)) = input.iter().find(|(key, _)| !ALLOWED.contains(&key.as_str())) {
            let message = format!("Schedule list filter [{field}] is not supported.");
            return Err(list_error(StatusCode::UNPROCESSABLE_ENTITY, "unsupported_schedule_list_filter",
                field, &message, None, None));
        }
        let filters = normalize(&Value::Object(input.into_iter().collect::<Map<_, _>>()));
        let failed = errors(&filters, LIST_RULES);
        if let Some((field, messages)) = failed.first() {
            let all: Map<String, Value> = failed
                .iter()
                .map(|(field, messages)| (field.clone(), json!(messages)))
                .collect();
            return Err(list_error(StatusCode::UNPROCESSABLE_ENTITY, "validation_failed", field,
                &messages[0], Some(Value::Object(all)), None));
        }
        let token = match filters["next_page_token"].as_str() {
            Some(text) => Some(decode_token(text, secret).ok_or_else(|| {
                list_error(StatusCode::BAD_REQUEST, "malformed_schedule_page_token", "next_page_token",
                    "Schedule continuation token is malformed or has an invalid signature.", None, None)
            })?),
            None => None,
        };
        let cursor = token.as_ref().map(public_cursor);
        if let Some(token) = &token
            && token.namespace != *self.namespace
        {
            return Err(list_error(StatusCode::FORBIDDEN, "schedule_page_token_namespace_mismatch", "next_page_token",
                "Schedule continuation token belongs to a different namespace.", None, cursor));
        }
        let predicates = match filters["query"].as_str() {
            Some(query) => parse_query(query, &self.search_attribute_types().await?).map_err(|error| {
                list_error(StatusCode::UNPROCESSABLE_ENTITY, error.reason, "query", &error.message, None, cursor.clone())
            })?,
            None => Vec::new(),
        };
        let selection = Selection {
            status: filters["status"].as_str().map(str::to_owned),
            workflow_type: filters["workflow_type"].as_str().map(str::to_owned),
            predicates,
        };
        let fingerprint = fingerprint(&selection);
        if let Some(token) = &token {
            if token.fingerprint != fingerprint {
                return Err(list_error(StatusCode::CONFLICT, "schedule_page_token_filter_mismatch", "next_page_token",
                    "Schedule continuation token must be reused with the same status, workflow type, and visibility query.",
                    None, cursor));
            }
            let current = self
                .select_schedules(&selection, None, Some(&token.schedule_id), 1)
                .await?;
            let present = match current.first() {
                Some(row) => DB::optional_instant(row, "created_at")?.map(cursor_time).as_deref()
                    == Some(token.created_at.as_str()),
                None => false,
            };
            if !present {
                return Err(list_error(StatusCode::CONFLICT, "stale_schedule_page_token", "next_page_token",
                    "Schedule continuation token cursor is no longer present in the unchanged filtered set. Restart pagination without a token.",
                    None, cursor));
            }
        }
        let page_size = filters["page_size"]
            .as_i64()
            .or_else(|| filters["page_size"].as_str().and_then(|text| text.parse().ok()))
            .unwrap_or(50);
        let rows = self
            .select_schedules(
                &selection,
                token.as_ref().map(|token| (token.created_at.as_str(), token.schedule_id.as_str())),
                None,
                page_size + 1,
            )
            .await?;
        let more = rows.len() as i64 > page_size;
        let page = &rows[..rows.len().min(page_size as usize)];
        let mut schedules = Vec::new();
        for row in page {
            schedules.push(Self::list_item(row)?);
        }
        let next = match page.last().filter(|_| more) {
            Some(last) => Some(encode_token(
                &self.namespace,
                &fingerprint,
                &DB::optional_instant(last, "created_at")?.map(cursor_time).unwrap_or_default(),
                &DB::string(last, "schedule_id")?,
                secret,
            )),
            None => None,
        };
        Ok(json!({"schedules":schedules,"schedule_count":page.len(),"next_page_token":next}))
    }
}

/// PHP signs continuation tokens with its application key, which the image
/// entrypoint takes from `DW_SERVER_KEY`, then `APP_KEY`. Native reads the
/// same two. Where PHP would generate a container-local key, native uses its
/// auth token, so tokens stay valid across restarts and nodes.
fn token_secret(runtime: &Runtime) -> String {
    ["DW_SERVER_KEY", "APP_KEY"]
        .iter()
        .find_map(|name| std::env::var(name).ok().filter(|key| !key.is_empty()))
        .unwrap_or_else(|| runtime.token.to_string())
}

/// `GET /api/schedules`.
pub(super) async fn list(
    Extension(runtime): Extension<Runtime>,
    RawQuery(raw): RawQuery,
) -> Result<Json<Value>> {
    let secret = token_secret(&runtime);
    runtime.list_schedules(raw.as_deref(), &secret).await.map(Json)
}

#[cfg(test)]
mod tests {
    use super::*;

    fn reason(query: &str) -> (&'static str, String) {
        match parse_query(query, &[("Team".into(), "keyword".into()), ("Tier".into(), "int".into())]) {
            Err(error) => (error.reason, error.message),
            Ok(predicates) => panic!("{query} parsed as {predicates:?}"),
        }
    }

    #[test]
    fn visibility_queries_parse_and_refuse_as_php_does() {
        let parsed = parse_query("ScheduleId = 'a\\'b' AND Status = \"paused\" and Tier = -5 AND Team = 'x AND y'",
            &[("Team".into(), "keyword".into()), ("Tier".into(), "int".into())]).unwrap();
        assert_eq!(parsed.iter().map(|p| p.literal.clone()).collect::<Vec<_>>(),
            vec![json!("a'b"), json!("paused"), json!(-5), json!("x AND y")]);
        assert_eq!(reason("Status != 'active'").0, "unsupported_schedule_visibility_predicate");
        assert_eq!(reason("Status = 'a' OR Note = 'b'").0, "unsupported_schedule_visibility_predicate");
        assert_eq!(reason("Status IN ('active')").0, "unsupported_schedule_visibility_predicate");
        assert_eq!(reason("Owner = 'x'").0, "unsupported_schedule_visibility_field");
        assert_eq!(reason("Status = 'deleted'").1, "Schedule visibility field [Status] requires a quoted active or paused literal.");
        assert_eq!(reason("Tier = 1.5").1, "Schedule visibility field [Tier] requires a integer literal.");
        assert_eq!(reason("Note = 01").1, "Schedule visibility literal [01] must be a quoted string, number, or boolean.");
        assert_eq!(reason("Note = 'open").1, "Schedule visibility query contains an unterminated string or empty predicate.");
        assert_eq!(reason("AND Note = 'x'").1, "Schedule visibility query contains an unterminated string or empty predicate.");
        assert_eq!(reason("Note 'x'").1, "Schedule visibility predicates must use: Field = literal.");
        assert_eq!(reason("   ").1, "Schedule visibility query must not be empty.");
    }

    #[test]
    fn visibility_queries_consume_the_shared_system_field_vectors() {
        let fixture: Value = serde_json::from_str(include_str!(
            "../../../tests/Fixtures/ScheduleVisibilityQuery/system-fields.json"
        )).unwrap();
        let mut names = std::collections::HashSet::new();
        for case in fixture["cases"].as_array().unwrap() {
            let name = case["name"].as_str().unwrap();
            assert!(names.insert(name));
            let query = super::super::laravel_validation::laravel_trim(case["query"].as_str().unwrap());
            let parsed = parse_query(query, &[]);
            if let Some(expected) = case.get("predicates") {
                let actual: Vec<_> = parsed.unwrap().into_iter().map(|predicate| json!({
                    "field":predicate.field,"column":predicate.column.map(|(_, column)| column),
                    "type":predicate.kind,"literal":predicate.literal,
                })).collect();
                assert_eq!(json!(actual), *expected, "{name}");
            } else {
                assert_eq!(parsed.unwrap_err().reason, case["reason"].as_str().unwrap(), "{name}");
            }
        }
        assert!(!names.is_empty());
    }

    #[test]
    fn numeric_visibility_literals_preserve_boundaries_and_refuse_overflow() {
        let definitions = [("Count".into(), "int".into()), ("Weight".into(), "double".into())];
        for value in [i64::MIN, i64::MAX] {
            let parsed = parse_query(&format!("Count = {value}"), &definitions).unwrap();
            assert_eq!(parsed[0].literal, json!(value));
        }
        for literal in ["1.0", "-0.0"] {
            let parsed = parse_query(&format!("Weight = {literal}"), &definitions).unwrap();
            assert_eq!(parsed[0].literal.as_f64().unwrap().to_bits(), literal.parse::<f64>().unwrap().to_bits());
        }
        for query in ["Count = 9223372036854775808".to_owned(), "Count = -9223372036854775809".to_owned(),
            format!("Weight = {}.0", "9".repeat(400))] {
            assert_eq!(parse_query(&query, &definitions).unwrap_err().reason, "invalid_schedule_visibility_query");
        }
    }

    #[test]
    fn continuation_tokens_round_trip_and_refuse_tampering() {
        let token = encode_token("default", &"a".repeat(64), "2026-10-21 05:00:00.123456", "nightly", "secret");
        let decoded = decode_token(&token, "secret").unwrap();
        assert_eq!((decoded.created_at.as_str(), decoded.schedule_id.as_str()), ("2026-10-21 05:00:00.123456", "nightly"));
        assert!(decode_token(&token, "other").is_none());
        assert!(decode_token(&token.replacen('e', "f", 1), "secret").is_none());
        assert!(decode_token("not-a-token", "secret").is_none());
        assert_eq!(public_cursor(&decoded)["created_at"], "2026-10-21T05:00:00.123456Z");
    }

    #[test]
    fn query_strings_read_as_php_reads_them() {
        assert_eq!(query_input(Some("status=a&status=b&x[]=1&x[]=2&y[k]=v")), vec![
            ("status".into(), json!("b")), ("x".into(), json!(["1", "2"])), ("y".into(), json!({"k":"v"}))]);
    }
    async fn add_schedule(store: &Store<sqlx::Sqlite>, schedule: &str) {
        sqlx::query("INSERT INTO workflow_schedules(id,schedule_id,namespace,action,spec,created_at,updated_at) VALUES (?,?,?,'{\"workflow_type\":\"echo\",\"task_queue\":\"test\"}','{}','2026-10-21 05:00:00.000000','2026-10-21 05:00:00.000000')")
            .bind(schedule).bind(schedule).bind("default").execute(&store.pool).await.unwrap();
    }

    async fn summary_store() -> (tempfile::TempDir, Store<sqlx::Sqlite>) {
        let directory = tempfile::tempdir().unwrap();
        let file = directory.path().join("schedule-summary.sqlite");
        let pool = super::super::sqlite::open(file.to_str().unwrap())
            .await
            .unwrap();
        (directory, Store::new(pool))
    }

    #[tokio::test]
    async fn last_skip_projection_bounds_raw_values_before_hydration() {
        let (_directory, store) = summary_store().await;
        add_schedule(&store,"skip-bounds").await;
        for (field, reason, at) in [("last_skip_reason","x".repeat(300000),None),
            ("last_skip_reason",format!("\0{}","x".repeat(300000)),None),
            ("last_skipped_at","retained".to_owned(),Some("9".repeat(10000)))] {
            sqlx::query("UPDATE workflow_schedules SET last_skip_reason=?,last_skipped_at=? WHERE schedule_id='skip-bounds'")
                .bind(reason).bind(at).execute(&store.pool).await.unwrap();
            let rows = store.select_schedules(&Selection {status:None,workflow_type:None,predicates:vec![]},None,None,50)
                .await.unwrap();
            assert_eq!(<sqlx::Sqlite as Backend>::number(&rows[0],"summary_invalid").unwrap(),1);
            assert!(<sqlx::Sqlite as Backend>::optional_string(&rows[0],field).unwrap().is_none());
            assert!(matches!(store.list_schedules(None,"fixture-secret").await,
                Err(RuntimeError::Refused {status:StatusCode::SERVICE_UNAVAILABLE,reason:"storage_unavailable"})));
        }
    }

    #[tokio::test]
    async fn list_summary_has_complete_scalars_and_separate_namespace_bound_current_run() {
        let (_directory, store) = summary_store().await;
        add_schedule(&store, "summary").await;
        sqlx::query("UPDATE workflow_schedules SET failures_count=2,skipped_trigger_count=3,latest_workflow_instance_id='instance',recent_actions='not JSON',buffered_actions='not JSON',last_skip_reason=? WHERE schedule_id='summary'")
            .bind("retained skip reason").execute(&store.pool).await.unwrap();
        let missing = store.list_schedules(None, "fixture-secret").await.unwrap();
        let item = &missing["schedules"][0];
        assert_eq!(item["failures_count"], 2);
        assert_eq!(item["skipped_trigger_count"], 3);
        assert_eq!(item["latest_workflow_instance_id"], "instance");
        assert_eq!(item["last_skip_reason"], "retained skip reason");
        assert_eq!(item.get("last_skipped_at"), Some(&Value::Null));
        assert!(
            item["current_run"].is_null(),
            "missing is unknown, never completed or healthy"
        );
        for field in [
            "recent_actions",
            "buffered_actions",
            "last_action",
        ] {
            assert!(item.get(field).is_none(), "{field} is deferred");
        }
        let rows = store
            .select_schedules(
                &Selection {
                    status: None,
                    workflow_type: None,
                    predicates: vec![],
                },
                None,
                None,
                50,
            )
            .await
            .unwrap();
        use sqlx::Row;
        assert!(rows[0].try_column("recent_actions").is_err());
        assert!(rows[0].try_column("buffered_actions").is_err());
        sqlx::query("INSERT INTO workflow_instances(id,workflow_class,workflow_type,namespace,current_run_id) VALUES ('instance','Echo','echo','other','run')")
            .execute(&store.pool).await.unwrap();
        sqlx::query("INSERT INTO workflow_runs(id,workflow_instance_id,run_number,workflow_class,workflow_type,namespace,status) VALUES ('run','instance',1,'Echo','echo','other','running')")
            .execute(&store.pool).await.unwrap();
        assert!(store.list_schedules(None,"fixture-secret").await.unwrap()["schedules"][0]["current_run"].is_null());
        sqlx::query("UPDATE workflow_instances SET namespace='default'")
            .execute(&store.pool)
            .await
            .unwrap();
        assert!(store.list_schedules(None,"fixture-secret").await.unwrap()["schedules"][0]["current_run"].is_null());
        sqlx::query("UPDATE workflow_runs SET namespace='default'")
            .execute(&store.pool)
            .await
            .unwrap();
        for (status, bucket, terminal) in [
            ("pending", "running", false),
            ("running", "running", false),
            ("waiting", "running", false),
            ("completed", "completed", true),
            ("failed", "failed", true),
            ("cancelled", "failed", true),
            ("terminated", "failed", true),
        ] {
            sqlx::query("UPDATE workflow_runs SET status=?")
                .bind(status)
                .execute(&store.pool)
                .await
                .unwrap();
            assert_eq!(
                store.list_schedules(None, "fixture-secret").await.unwrap()["schedules"][0]["current_run"],
                json!({"run_id":"run","status":status,"status_bucket":bucket,"is_terminal":terminal})
            );
        }
        sqlx::query("UPDATE workflow_runs SET status='unknown-storage-status'").execute(&store.pool).await.unwrap();
        assert!(store.list_schedules(None,"fixture-secret").await.is_err());
        sqlx::query("UPDATE workflow_runs SET status='running'").execute(&store.pool).await.unwrap();
        sqlx::query("DELETE FROM workflow_runs")
            .execute(&store.pool)
            .await
            .unwrap();
        assert!(store.list_schedules(None,"fixture-secret").await.unwrap()["schedules"][0]["current_run"].is_null());
        store.close().await;
    }

    #[tokio::test]
    async fn list_summary_refuses_complete_malformed_or_oversized_scalars_without_lossy_prefixes() {
        let (_directory, store) = summary_store().await;
        add_schedule(&store, "scalar").await;
        let exact = "ab\0cd🙂";
        sqlx::query("UPDATE workflow_schedules SET latest_workflow_instance_id=?")
            .bind(exact)
            .execute(&store.pool)
            .await
            .unwrap();
        assert_eq!(
            store.list_schedules(None, "fixture-secret").await.unwrap()["schedules"][0]["latest_workflow_instance_id"],
            exact
        );
        for invalid in [String::new(), "x".repeat(192), "🙂".repeat(192)] {
            sqlx::query("UPDATE workflow_schedules SET latest_workflow_instance_id=?")
                .bind(invalid)
                .execute(&store.pool)
                .await
                .unwrap();
            assert!(matches!(
                store.list_schedules(None, "fixture-secret").await,
                Err(RuntimeError::Refused {
                    status: StatusCode::SERVICE_UNAVAILABLE,
                    reason: "storage_unavailable"
                })
            ));
        }
        sqlx::query("UPDATE workflow_schedules SET latest_workflow_instance_id=CAST(X'FF' AS TEXT)").execute(&store.pool).await.unwrap();
        assert!(store.list_schedules(None,"fixture-secret").await.is_err());
        sqlx::query(
            "UPDATE workflow_schedules SET latest_workflow_instance_id=NULL,failures_count=-1",
        )
        .execute(&store.pool)
        .await
        .unwrap();
        assert!(store.list_schedules(None, "fixture-secret").await.is_err());
        store.close().await;
    }

    #[tokio::test]
    async fn list_summary_query_cost_is_constant_per_page_with_existing_cursor_and_filter_guards() {
        use std::sync::{
            Arc,
            atomic::{AtomicUsize, Ordering},
        };
        let (directory, store) = summary_store().await;
        for index in 0..51 {
            add_schedule(&store, &format!("page-{index:03}")).await;
        }
        store.close().await;
        let calls = Arc::new(AtomicUsize::new(0));
        let measured = Arc::clone(&calls);
        let pool = sqlx::sqlite::SqlitePoolOptions::new()
            .max_connections(1)
            .before_acquire(move |_, _| {
                measured.fetch_add(1, Ordering::SeqCst);
                Box::pin(async { Ok(true) })
            })
            .connect_with(
                sqlx::sqlite::SqliteConnectOptions::new()
                    .filename(directory.path().join("schedule-summary.sqlite")),
            )
            .await
            .unwrap();
        let store = Store::<sqlx::Sqlite>::new(pool);
        for (query, expected) in [
            ("page_size=50", 1),
            ("page_size=50&query=Status%20%3D%20%27active%27", 2),
        ] {
            calls.store(0, Ordering::SeqCst);
            let first = store
                .list_schedules(Some(query), "fixture-secret")
                .await
                .unwrap();
            assert_eq!(first["schedule_count"], 50);
            assert_eq!(calls.load(Ordering::SeqCst), expected);
            let token = first["next_page_token"].as_str().unwrap();
            calls.store(0, Ordering::SeqCst);
            let second = store
                .list_schedules(
                    Some(&format!("{query}&next_page_token={token}")),
                    "fixture-secret",
                )
                .await
                .unwrap();
            assert_eq!(second["schedule_count"], 1);
            assert_eq!(calls.load(Ordering::SeqCst), expected + 1);
        }
        store.close().await;
    }
}
