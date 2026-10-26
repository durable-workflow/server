//! `LegacyV1ProjectionController` and `LegacyV1Projection`, as published
//! Server 2.11.1 serves them: a Waterline v1 flow detail projected into one
//! read-only standalone run. The identity is a deterministic hash of the
//! source id and the qualified v1 id; the report, events, dedupe key and
//! summary follow PHP's construction, including Eloquent storing ordinary
//! date columns as the source's wall-clock time and history times as UTC.
use super::execution::Runtime;
use super::php_value::{Flags, Key, Php, PhpArray};
use super::store::now;
use super::workflow_import::{Stamp, parse_time};
use super::Result;
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Value, json};
use sha2::{Digest, Sha256};

pub(super) const IMPORT_SOURCE: &str = "waterline_v1_projection";
pub(super) const ENGINE_SOURCE: &str = "v1_projection";
pub(super) const SCHEMA: &str = "durable-workflow.v2.waterline-v1-projection.contract";
const REPORT_SCHEMA: &str = "durable-workflow.v2.waterline-v1-projection.report";
pub(super) const VERSION: i64 = 1;
const OPAQUE_REASON: &str = "legacy_php_serialization_not_portable";
const OPAQUE_REMEDIATION: &str =
    "Treat the preserved value as opaque; export decoded JSON from the source application if portable payload access is required.";

/// A PHP array literal with string keys, in order.
fn object(entries: Vec<(&str, Php)>) -> Php {
    Php::Array(PhpArray(entries.into_iter().map(|(key, value)| (Key::of(key), value)).collect()))
}

fn list(items: Vec<Php>) -> Php {
    Php::Array(PhpArray(items.into_iter().enumerate().map(|(index, value)| (Key::Int(index as i64), value)).collect()))
}

fn text(value: impl Into<String>) -> Php {
    Php::Str(value.into())
}

fn optional(value: Option<String>) -> Php {
    value.map_or(Php::Null, Php::Str)
}

/// PHP's `trim()` with its default characters.
fn php_trim(value: &str) -> &str {
    value.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0b'])
}

/// `LegacyV1Projection::string`: a string that is not blank, trimmed.
fn string(value: Option<&Php>) -> Option<String> {
    match value {
        Some(Php::Str(text)) if !php_trim(text).is_empty() => Some(php_trim(text).to_owned()),
        _ => None,
    }
}

/// PHP's `$array[$key] ?? $default`: null and absent both fall through.
fn coalesce<'a>(array: &'a PhpArray, keys: &[&str]) -> Option<&'a Php> {
    keys.iter().find_map(|key| array.get(key).filter(|value| !matches!(value, Php::Null)))
}

/// PHP's `(string)` of a float: `precision` 14, as `%.14G` prints it.
fn float_string(value: f64) -> String {
    if value.is_nan() {
        return "NAN".into();
    }
    if value.is_infinite() {
        return if value > 0.0 { "INF".into() } else { "-INF".into() };
    }
    if value == 0.0 {
        return if value.is_sign_negative() { "-0".into() } else { "0".into() };
    }
    let scientific = format!("{value:.13e}");
    let (mantissa, exponent) = scientific.split_once('e').expect("scientific notation");
    let exponent: i32 = exponent.parse().expect("exponent");
    let (sign, digits) = mantissa.strip_prefix('-').map_or(("", mantissa), |digits| ("-", digits));
    let digits: String = digits.chars().filter(char::is_ascii_digit).collect();
    let digits = digits.trim_end_matches('0');
    let digits = if digits.is_empty() { "0" } else { digits };
    if exponent < -4 || exponent >= 14 {
        let fraction = if digits.len() > 1 { &digits[1..] } else { "0" };
        return format!("{sign}{}.{fraction}E{}{}", &digits[..1], if exponent < 0 { '-' } else { '+' }, exponent.abs());
    }
    if exponent < 0 {
        return format!("{sign}0.{}{digits}", "0".repeat((-exponent - 1) as usize));
    }
    let point = exponent as usize + 1;
    if digits.len() <= point {
        format!("{sign}{digits}{}", "0".repeat(point - digits.len()))
    } else {
        format!("{sign}{}.{}", &digits[..point], &digits[point..])
    }
}

/// `is_scalar($value) ? (string) $value : null`.
fn scalar_string(value: Option<&Php>) -> Option<String> {
    match value? {
        Php::Str(text) => Some(text.clone()),
        Php::Int(number) => Some(number.to_string()),
        Php::Float(number) => Some(float_string(*number)),
        Php::Bool(true) => Some("1".into()),
        Php::Bool(false) => Some(String::new()),
        _ => None,
    }
}

/// `LegacyV1Projection::timestamp`: a non-blank string Carbon can parse.
fn timestamp(value: Option<&Php>) -> Option<Stamp> {
    match value {
        Some(Php::Str(text)) if !php_trim(text).is_empty() => parse_time(text).ok(),
        _ => None,
    }
}

/// Carbon's `toJSON()`: the UTC instant with microseconds.
fn json_time(stamp: Option<Stamp>) -> Option<String> {
    stamp.map(|stamp| stamp.utc.format("%Y-%m-%dT%H:%M:%S%.6fZ").to_string())
}

fn sha256(text: &str) -> String {
    super::bundle_verifier::hex(&Sha256::digest(text.as_bytes()))
}

/// `rows`: the array members of a list, reindexed.
fn rows(value: Option<&Php>) -> Vec<PhpArray> {
    match value {
        Some(Php::Array(array)) => array.values().filter_map(|value| value.array().cloned()).collect(),
        _ => Vec::new(),
    }
}

/// How many rows `rows` would return, without copying them.
fn row_count(value: Option<&Php>) -> usize {
    match value {
        Some(Php::Array(array)) => array.values().filter(|value| value.array().is_some()).count(),
        _ => 0,
    }
}

/// A projection larger than one transaction may write: its history events
/// against `DW_V2_LIMIT_HISTORY_TRANSACTION_SIZE`, or its history bytes
/// against `DW_V2_LIMIT_HISTORY_TRANSACTION_BYTES`. Published PHP has no
/// such limit; native refuses before reading or writing any row.
fn too_large(dimension: &str, used: usize, limit: usize) -> Php {
    let (measure, setting) = match dimension {
        "history_transaction_size" => ("history events", "DW_V2_LIMIT_HISTORY_TRANSACTION_SIZE"),
        _ => ("history bytes", "DW_V2_LIMIT_HISTORY_TRANSACTION_BYTES"),
    };
    object(vec![
        ("reason", text("v1_projection_too_large")),
        ("message", text(format!("The projection would write {used} {measure} in one transaction; at most {limit} are allowed ({dimension})."))),
        ("remediation", text(format!("Keep the workflow on its v1 runtime, or raise {setting} on this server before importing it."))),
        ("limit", object(vec![("dimension", text(dimension)), ("used", Php::Int(used as i64)), ("limit", Php::Int(limit as i64))])),
    ])
}

/// The first limit a projection of `events` events and `bytes` bytes
/// exceeds, if any; 0 disables a limit.
fn exceeded(limits: &super::StructuralLimits, events: usize, bytes: usize) -> Option<Php> {
    let over = |limit: Option<usize>, used: usize| limit.filter(|limit| *limit > 0 && used > *limit);
    over(limits.history_events, events)
        .map(|limit| too_large("history_transaction_size", events, limit))
        .or_else(|| over(limits.history_bytes, bytes).map(|limit| too_large("history_transaction_bytes", bytes, limit)))
}

/// The body that refuses an operation on a read-only projection.
pub(super) fn read_only_body(workflow_id: &str, run_id: &str, operation: &str) -> Value {
    json!({
        "message": format!("Operation [{operation}] is unavailable because this execution is a read-only projection owned by the source v1 runtime."),
        "reason": "v1_projection_read_only",
        "remediation": "Run commands against the source v1 application until it completes; repeat projection never dispatches or advances standalone execution.",
        "workflow_id": workflow_id,
        "run_id": run_id,
        "operation": operation,
        "execution_owner": "v1",
    })
}

/// A message stream append refused on a read-only projection, in the shape
/// of the stream's own refusals. Published PHP accepts it.
pub(super) fn read_only_append(workflow_id: &str, run_id: &str, stream: &str) -> Value {
    let mut body = read_only_body(workflow_id, run_id, "message_stream_append");
    for (key, value) in [("accepted", json!(false)), ("duplicate", json!(false)), ("stream_name", json!(stream)),
        ("outcome", json!("rejected"))] {
        body[key] = value;
    }
    body
}

fn error(reason: &str, message: &str, remediation: &str) -> Php {
    object(vec![("reason", text(reason)), ("message", text(message)), ("remediation", text(remediation))])
}

fn unsupported(field: &str, reason: &str, remediation: &str) -> Php {
    object(vec![("field", text(field)), ("reason", text(reason)), ("remediation", text(remediation))])
}

/// `opaquePayload`.
fn opaque(value: Option<&Php>) -> Php {
    let value = value.filter(|value| !matches!(value, Php::Null));
    object(vec![
        ("available", Php::Bool(value.is_some())),
        ("encoding", text("php-serialize")),
        ("value", optional(scalar_string(value))),
        ("portable", Php::Bool(false)),
        ("unsupported_reason", text(OPAQUE_REASON)),
        ("remediation", text(OPAQUE_REMEDIATION)),
    ])
}

/// `mapStatus`.
fn map_status(status: &str) -> Option<&'static str> {
    Some(match status {
        "created" | "pending" => "pending",
        "running" => "running",
        "waiting" => "waiting",
        "completed" | "continued" => "completed",
        "failed" => "failed",
        "cancelled" => "cancelled",
        "terminated" => "terminated",
        _ => return None,
    })
}

/// `statusBucket`.
fn status_bucket(status: &str) -> &'static str {
    match status {
        "completed" => "completed",
        "failed" | "cancelled" | "terminated" => "failed",
        _ => "running",
    }
}

/// `mappedIdentity`. The source id passed its URL-safe pattern, so Laravel's
/// `Str::lower`, `replaceMatches`, `trim('-')` and `limit(32, '')` act on
/// ASCII only.
pub(super) fn mapped_identity(source_id: &str, qualified_id: &str) -> (String, String, String) {
    let key = format!("{}\0{}", php_trim(source_id), php_trim(qualified_id));
    let lowered = source_id.to_lowercase();
    let mut slug = String::new();
    let mut replaced = false;
    for character in lowered.chars() {
        if character.is_ascii_lowercase() || character.is_ascii_digit() || matches!(character, '.' | '_' | '-') {
            slug.push(character);
            replaced = false;
        } else if !replaced {
            slug.push('-');
            replaced = true;
        }
    }
    let mut slug: String = slug.trim_matches('-').chars().take(32).collect::<String>().trim_end().to_owned();
    if slug.is_empty() {
        slug = "source".into();
    }
    (
        format!("v1:{slug}:{}", &sha256(&format!("workflow\0{key}"))[..24]),
        sha256(&format!("run\0{key}"))[..26].to_owned(),
        sha256(&format!("import\0{key}")),
    )
}

/// One projected history event before it is stored.
pub(crate) struct Event {
    pub(super) id: String,
    pub(super) sequence: i64,
    pub(super) event_type: &'static str,
    pub(super) recorded_at: Option<Stamp>,
    pub(super) payload: Php,
}

/// A valid source, normalized as `LegacyV1Projection::normalize` does.
pub(crate) struct Normalized {
    pub(super) namespace: String,
    pub(super) workflow_id: String,
    pub(super) run_id: String,
    pub(super) import_id: String,
    pub(super) dedupe_key: String,
    pub(super) class: String,
    pub(super) connection: Option<String>,
    pub(super) queue: Option<String>,
    pub(super) status: &'static str,
    pub(super) closed_reason: Option<String>,
    pub(super) started_at: Option<Stamp>,
    pub(super) last_progress_at: Option<Stamp>,
    pub(super) closed_at: Option<Stamp>,
    pub(super) exception_count: i64,
    pub(super) events: Vec<Event>,
    pub(super) history_size_bytes: i64,
    identity: Php,
    unsupported: Php,
}

pub(crate) enum Outcome {
    Invalid { errors: Vec<Php> },
    /// Larger than one transaction may write. `built` is how many events
    /// were built first: 0 for a refusal by count. Only the controls read it.
    TooLarge {
        error: Php,
        #[cfg_attr(not(test), allow(dead_code))]
        built: usize,
    },
    Valid(Box<Normalized>),
}

/// `LegacyV1Projection::normalize`, without size limits.
#[cfg(test)]
pub(crate) fn normalize(source_id: &str, source: &PhpArray, namespace: Option<&str>) -> Outcome {
    let unlimited = super::StructuralLimits { history_events: None, history_bytes: None, ..super::StructuralLimits::default() };
    normalize_bounded(source_id, source, namespace, &unlimited)
}

/// `LegacyV1Projection::normalize`, refusing a projection larger than one
/// transaction may write. The event count is known from the source's row
/// counts and is checked before any row is copied or any event is built;
/// history bytes are added up event by event and the first event past the
/// limit stops the measure.
pub(crate) fn normalize_bounded(source_id: &str, source: &PhpArray, namespace: Option<&str>,
    limits: &super::StructuralLimits) -> Outcome {
    let source_id = php_trim(source_id).to_owned();
    let namespace = namespace.map(php_trim).filter(|namespace| !namespace.is_empty()).unwrap_or("default").to_owned();
    let qualified = string(coalesce(source, &["operator_id", "id"]));
    let legacy = scalar_string(source.get("legacy_id"));
    let class = string(source.get("class"));
    let source_status = string(source.get("status")).unwrap_or_default().to_lowercase();
    let status = map_status(&source_status);
    let mut errors = Vec::new();
    let source_id_valid = (1..=64).contains(&source_id.len())
        && source_id.bytes().all(|byte| byte.is_ascii_alphanumeric() || b"._:-".contains(&byte));
    if !source_id_valid {
        errors.push(error("invalid_source_id", "source_id must be 1-64 stable URL-safe characters.",
            "Use a deployment-stable identifier such as legacy-prod-us-east."));
    }
    let qualified_valid = qualified.as_deref()
        .and_then(|qualified| qualified.strip_prefix("v1:"))
        .is_some_and(|rest| !rest.is_empty() && !rest.contains('\n'));
    if !qualified_valid {
        errors.push(error("unqualified_v1_identity", "The Waterline workflow must carry operator_id or id in v1:<workflow-id> form.",
            "Export the detail from the Waterline hybrid migration API."));
    }
    if let (Some(legacy), Some(qualified)) = (&legacy, &qualified)
        && *qualified != format!("v1:{legacy}")
    {
        errors.push(error("v1_identity_mismatch", "legacy_id does not match the qualified Waterline identity.",
            "Re-export the workflow detail without editing its identity fields."));
    }
    if !matches!(source.get("engine_source"), Some(Php::Str(engine)) if engine == "v1") {
        errors.push(error("unsupported_source_engine", "Only Waterline engine_source=v1 projections are accepted.",
            "Use embedded-v2 import for v2-origin history bundles."));
    }
    if class.is_none() {
        errors.push(error("missing_v1_workflow_class", "The Waterline projection does not identify the workflow class.",
            "Export the complete v1 workflow detail response."));
    }
    if status.is_none() {
        errors.push(error("unsupported_v1_status", "The v1 workflow status cannot be represented by the standalone v2 visibility model.",
            "Complete or normalize the workflow on v1, then export it again."));
    }
    let (Some(qualified), Some(class), Some(status), true) = (qualified, class, status, errors.is_empty()) else {
        return Outcome::Invalid { errors };
    };

    // The start event, one event per log, signal and exception, and a
    // terminal event when the status has one.
    let terminal = matches!(status, "completed" | "failed" | "cancelled" | "terminated");
    let projected = ["logs", "signals", "exceptions"].iter()
        .fold(1usize + usize::from(terminal), |total, key| total.saturating_add(row_count(source.get(key))));
    if let Some(error) = exceeded(limits, projected, 0) {
        return Outcome::TooLarge { error, built: 0 };
    }
    let (workflow_id, run_id, import_id) = mapped_identity(&source_id, &qualified);
    let connection = string(source.get("connection"));
    let queue = string(source.get("queue"));
    let logs = rows(source.get("logs"));
    let signals = rows(source.get("signals"));
    let exceptions = rows(source.get("exceptions"));
    let unsupported = unsupported_fields(source, &logs, &signals, &exceptions, &source_status);
    let legacy_workflow_id = legacy.clone().unwrap_or_else(|| qualified[3..].to_owned());
    let waterline = object(vec![
        ("source_id", text(&source_id)),
        ("qualified_workflow_id", text(&qualified)),
        ("legacy_workflow_id", text(legacy_workflow_id)),
    ]);
    let identity = object(vec![
        ("waterline", waterline.clone()),
        ("standalone", object(vec![
            ("namespace", text(&namespace)),
            ("workflow_id", text(&workflow_id)),
            ("run_id", text(&run_id)),
        ])),
        ("relationship", text("deterministic_source_qualified_projection")),
        ("collision_policy", text("reject_without_overwrite")),
    ]);
    let origin = object(vec![
        ("engine_source", text("v1")),
        ("engine_version", text(string(source.get("engine_version")).unwrap_or_else(|| "1.x".into()))),
        ("execution_engine", text(string(source.get("execution_engine")).unwrap_or_else(|| "finish-on-v1".into()))),
    ]);
    let task_queue = object(vec![
        ("connection", optional(connection.clone())),
        ("name", optional(queue.clone())),
        ("execution_owner", text("v1")),
        ("standalone_dispatch", text("disabled")),
    ]);
    let started_at = timestamp(source.get("created_at"));
    let last_progress_at = timestamp(source.get("updated_at")).or(started_at);
    let closed_at = if terminal { last_progress_at } else { None };
    let raw = |key: &str| source.get(key).cloned().unwrap_or(Php::Null);
    let rows_value = |rows: &[PhpArray]| list(rows.iter().cloned().map(Php::Array).collect());
    let for_hash = object(vec![
        ("origin", origin.clone()),
        ("identity", waterline),
        ("class", text(&class)),
        ("status", text(&source_status)),
        ("connection", optional(connection.clone())),
        ("queue", optional(queue.clone())),
        ("arguments", raw("arguments")),
        ("output", raw("output")),
        ("logs", rows_value(&logs)),
        ("signals", rows_value(&signals)),
        ("exceptions", rows_value(&exceptions)),
        ("created_at", optional(json_time(started_at))),
        ("updated_at", optional(json_time(last_progress_at))),
    ]);
    let dedupe_key = sha256(&for_hash.canonical().encode(Flags { unescaped_slashes: true, ..Flags::default() }));
    let migration = object(vec![
        ("origin", origin),
        ("identity", identity.clone()),
        ("task_queue_context", task_queue),
        ("unsupported_fields", unsupported.clone()),
    ]);
    // Events are built one at a time, and the first past the byte limit
    // stops the build.
    let (events, history_size_bytes) = match events(source, &logs, &signals, &exceptions, migration, status, started_at,
        last_progress_at, &run_id, limits) {
        Ok(built) => built,
        Err((error, built)) => return Outcome::TooLarge { error, built },
    };
    let history_size_bytes = i64::try_from(history_size_bytes).unwrap_or(i64::MAX);
    Outcome::Valid(Box::new(Normalized {
        namespace,
        workflow_id,
        run_id,
        import_id,
        dedupe_key,
        class,
        connection,
        queue,
        status,
        closed_reason: terminal.then(|| source_status.clone()),
        started_at,
        last_progress_at,
        closed_at,
        exception_count: exceptions.len() as i64,
        events,
        history_size_bytes,
        identity,
        unsupported,
    }))
}

/// `unsupportedFields`.
fn unsupported_fields(source: &PhpArray, logs: &[PhpArray], signals: &[PhpArray], exceptions: &[PhpArray], status: &str) -> Php {
    let mut fields = vec![
        unsupported("runtime.replay", "v1_history_not_replayable_as_v2",
            "Retain the v1 application storage for v1 replay; use the projected typed history for operator inspection only."),
        unsupported("timers", "legacy_timer_state_not_exposed_by_waterline",
            "Inspect timers in the source application and allow open executions to finish on v1."),
    ];
    for field in ["arguments", "output"] {
        if source.get(field).is_some_and(|value| !matches!(value, Php::Null)) {
            fields.push(unsupported(&format!("payloads.{field}"), OPAQUE_REASON, OPAQUE_REMEDIATION));
        }
    }
    for (field, rows, key) in [
        ("payloads.activity_results", logs, "result"),
        ("payloads.signal_arguments", signals, "arguments"),
        ("payloads.exception_values", exceptions, "exception"),
    ] {
        if rows.iter().any(|row| row.get(key).is_some_and(|value| !matches!(value, Php::Null))) {
            fields.push(unsupported(field, OPAQUE_REASON, OPAQUE_REMEDIATION));
        }
    }
    if !matches!(status, "completed" | "continued" | "failed" | "cancelled" | "terminated") {
        fields.push(unsupported("runtime.execution", "finish_on_v1_execution_not_transferable",
            "Keep the v1 worker running until completion; the standalone projection is deliberately read-only and never dispatches work."));
    }
    list(fields)
}

/// `events`: started, then each log, signal and exception, then the
/// terminal event, ordered by boundary, whole-second time (untimed first)
/// and source order, with sequence and id assigned after sorting.
#[allow(clippy::too_many_arguments)]
#[cfg(test)]
thread_local! {
    /// How many events `events` has built, for the controls that prove a
    /// refused build stops at the first event past the limit.
    static EVENTS_BUILT: std::cell::Cell<usize> = const { std::cell::Cell::new(0) };
}

/// Where a projected event comes from.
#[derive(Clone, Copy)]
enum Origin {
    Start,
    Log(usize),
    Signal(usize),
    Exception(usize),
    Terminal(&'static str),
}

/// The projected events, ordered as PHP orders them, with their history
/// bytes (`strlen(json_encode($event, JSON_UNESCAPED_SLASHES))` over each
/// event as PHP holds it: type, Carbon time, payload, sequence and id).
/// The order is decided from each event's boundary, time and source order
/// alone; then each event is built and measured in turn, and the first one
/// past the byte limit stops the build with how many were built.
#[allow(clippy::too_many_arguments)]
fn events(source: &PhpArray, logs: &[PhpArray], signals: &[PhpArray], exceptions: &[PhpArray], migration: Php,
    status: &str, started_at: Option<Stamp>, last_progress_at: Option<Stamp>, run_id: &str,
    limits: &super::StructuralLimits) -> std::result::Result<(Vec<Event>, usize), (Php, usize)> {
    let field = |row: &PhpArray, key: &str| row.get(key).cloned().unwrap_or(Php::Null);
    let terminal = match status {
        "completed" => Some("WorkflowCompleted"),
        "failed" => Some("WorkflowFailed"),
        "cancelled" => Some("WorkflowCancelled"),
        "terminated" => Some("WorkflowTerminated"),
        _ => None,
    };
    let mut order: Vec<(u8, i64, Option<Stamp>, Origin)> =
        Vec::with_capacity(2 + logs.len() + signals.len() + exceptions.len());
    order.push((0, 0, started_at, Origin::Start));
    for (index, log) in logs.iter().enumerate() {
        order.push((1, 100 + index as i64, timestamp(coalesce(log, &["created_at", "now"])), Origin::Log(index)));
    }
    for (index, signal) in signals.iter().enumerate() {
        order.push((1, 200 + index as i64, timestamp(coalesce(signal, &["created_at", "received_at"])), Origin::Signal(index)));
    }
    for (index, exception) in exceptions.iter().enumerate() {
        order.push((1, 300 + index as i64, timestamp(exception.get("created_at")), Origin::Exception(index)));
    }
    if let Some(event_type) = terminal {
        order.push((2, i64::MAX, last_progress_at, Origin::Terminal(event_type)));
    }
    // Carbon's getTimestamp: whole seconds; PHP_INT_MIN for an untimed event.
    order.sort_by_key(|(boundary, order, at, _)| (*boundary, at.map_or(i64::MIN, |at| at.utc.timestamp()), *order));

    let mut migration = Some(migration);
    let mut built = Vec::with_capacity(order.len());
    let mut bytes: usize = 0;
    for (index, (_, _, recorded_at, origin)) in order.into_iter().enumerate() {
        let (event_type, payload) = match origin {
            Origin::Start => ("WorkflowStarted", object(vec![
                ("migration_projection", migration.take().unwrap_or(Php::Null)),
                ("legacy_payloads", object(vec![("arguments", opaque(source.get("arguments")))])),
            ])),
            Origin::Log(index) => {
                let log = &logs[index];
                ("ActivityCompleted", object(vec![
                    ("migration_projection", object(vec![
                        ("source_record", text("workflow_log")),
                        ("legacy_id", field(log, "id")),
                        ("legacy_index", field(log, "index")),
                    ])),
                    ("activity", object(vec![
                        ("class", field(log, "class")),
                        ("logical_time", field(log, "now")),
                        ("result", opaque(log.get("result"))),
                    ])),
                ]))
            }
            Origin::Signal(index) => {
                let signal = &signals[index];
                ("SignalReceived", object(vec![
                    ("migration_projection", object(vec![
                        ("source_record", text("workflow_signal")),
                        ("legacy_id", field(signal, "id")),
                    ])),
                    ("signal_name", coalesce(signal, &["name", "method"]).cloned().unwrap_or(Php::Null)),
                    ("arguments", opaque(signal.get("arguments"))),
                ]))
            }
            Origin::Exception(index) => {
                let exception = &exceptions[index];
                ("ActivityFailed", object(vec![
                    ("migration_projection", object(vec![
                        ("source_record", text("workflow_exception")),
                        ("legacy_id", field(exception, "id")),
                    ])),
                    ("exception", object(vec![
                        ("class", field(exception, "class")),
                        ("value", opaque(exception.get("exception"))),
                    ])),
                ]))
            }
            Origin::Terminal(event_type) => (event_type, object(vec![
                ("migration_projection", object(vec![
                    ("source_status", source.get("status").cloned().unwrap_or(Php::Null)),
                    ("execution_owner", text("v1")),
                ])),
                ("legacy_payloads", object(vec![("output", opaque(source.get("output")))])),
            ])),
        };
        #[cfg(test)]
        EVENTS_BUILT.with(|count| count.set(count.get() + 1));
        let sequence = index as i64 + 1;
        let event = Event {
            id: sha256(&format!("{run_id}\0{sequence}\0{event_type}"))[..26].to_owned(),
            sequence,
            event_type,
            recorded_at,
            payload,
        };
        let size = object(vec![
            ("event_type", text(event.event_type)),
            ("recorded_at", optional(json_time(event.recorded_at))),
            ("payload", event.payload.clone()),
            ("sequence", Php::Int(event.sequence)),
            ("id", text(&event.id)),
        ]).encode(Flags { unescaped_slashes: true, ..Flags::default() }).len();
        bytes = bytes.checked_add(size).unwrap_or(usize::MAX);
        if let Some(error) = exceeded(limits, 0, bytes) {
            return Err((error, index + 1));
        }
        built.push(event);
    }
    Ok((built, bytes))
}

/// `report`: the projection report, with the first error's reason, message
/// and remediation at the top of a rejection, then `extra`.
fn report(outcome: &Outcome, status: &str, extra: Vec<(&str, Value)>) -> Value {
    let (identity, dedupe, unsupported, errors) = match outcome {
        Outcome::Invalid { errors } => (Value::Null, Value::Null, json!([]), list(errors.clone()).to_json()),
        Outcome::TooLarge { error, .. } => (Value::Null, Value::Null, json!([]), list(vec![error.clone()]).to_json()),
        Outcome::Valid(normalized) => (normalized.identity.to_json(), json!(normalized.dedupe_key),
            normalized.unsupported.to_json(), json!([])),
    };
    let mut body = json!({"schema": REPORT_SCHEMA, "schema_version": VERSION, "status": status, "projection_only": true,
        "identity": identity, "dedupe_key": dedupe, "unsupported_fields": unsupported, "errors": errors});
    if status == "rejected"
        && let Some(first) = body["errors"].get(0).cloned()
    {
        for key in ["reason", "message", "remediation"] {
            body[key] = first[key].clone();
        }
    }
    for (key, value) in extra {
        body[key] = value;
    }
    body
}

/// What the importer needs to know about rows already holding the identity.
pub(crate) struct Existing {
    pub(super) run: Option<ExistingRun>,
    pub(super) instance_namespace: Option<Option<String>>,
}

pub(crate) struct ExistingRun {
    pub(super) workflow_instance_id: String,
    pub(super) import_source: Option<String>,
    pub(super) import_id: Option<String>,
    pub(super) dedupe_key: Option<String>,
    pub(super) namespace: Option<String>,
    pub(super) imported_at: Option<DateTime<Utc>>,
}

/// The validated request: `source_id` required|string|max:64, `workflow`
/// required|array, `dry_run` nullable|boolean, after Laravel's TrimStrings
/// and ConvertEmptyStringsToNull.
fn validate(body: &PhpArray) -> std::result::Result<(String, PhpArray, bool), Value> {
    let present = |value: Option<&Php>| match value {
        None | Some(Php::Null) => false,
        Some(Php::Str(text)) => !text.trim().is_empty(),
        Some(Php::Array(array)) => !array.is_empty(),
        Some(_) => true,
    };
    let mut errors: Vec<(String, Vec<String>)> = Vec::new();
    let source = body.get("source_id");
    let mut source_messages = Vec::new();
    if !present(source) {
        source_messages.push("The source id field is required.".to_owned());
    } else {
        if !matches!(source, Some(Php::Str(_))) {
            source_messages.push("The source id field must be a string.".to_owned());
        }
        match source {
            Some(Php::Array(array)) if array.len() > 64 => {
                source_messages.push("The source id field must not have more than 64 items.".to_owned());
            }
            Some(Php::Str(text)) if text.chars().count() > 64 => {
                source_messages.push("The source id field must not be greater than 64 characters.".to_owned());
            }
            other if !matches!(other, Some(Php::Array(_)) | Some(Php::Str(_)))
                && scalar_string(other).is_some_and(|text| text.chars().count() > 64) =>
            {
                source_messages.push("The source id field must not be greater than 64 characters.".to_owned());
            }
            _ => {}
        }
    }
    errors.push(("source_id".into(), source_messages));
    let workflow = body.get("workflow");
    let workflow_messages = if !present(workflow) {
        vec!["The workflow field is required.".to_owned()]
    } else if !matches!(workflow, Some(Php::Array(_))) {
        vec!["The workflow field must be an array.".to_owned()]
    } else {
        Vec::new()
    };
    errors.push(("workflow".into(), workflow_messages));
    let dry_run = body.get("dry_run");
    let boolean = match dry_run {
        None | Some(Php::Null) => Some(false),
        Some(Php::Bool(value)) => Some(*value),
        Some(Php::Int(0)) => Some(false),
        Some(Php::Int(1)) => Some(true),
        Some(Php::Str(text)) if text == "0" => Some(false),
        Some(Php::Str(text)) if text == "1" => Some(true),
        _ => None,
    };
    errors.push(("dry_run".into(), if boolean.is_none() { vec!["The dry run field must be true or false.".to_owned()] } else { Vec::new() }));
    if let Some(super::RuntimeError::Protocol { response, .. }) = super::laravel_validation::failure_messages(errors) {
        return Err(response);
    }
    let (Some(Php::Str(source_id)), Some(Php::Array(workflow)), Some(dry_run)) = (source, workflow, boolean) else {
        unreachable!("validated request");
    };
    Ok((source_id.clone(), workflow.clone(), dry_run))
}

/// A path segment percent-decoded, as the router decodes its parameters.
fn decoded(segment: &str) -> Option<String> {
    let bytes = segment.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut index = 0;
    while index < bytes.len() {
        if bytes[index] == b'%' {
            let hex = std::str::from_utf8(bytes.get(index + 1..index + 3)?).ok()?;
            out.push(u8::from_str_radix(hex, 16).ok()?);
            index += 3;
        } else {
            out.push(bytes[index]);
            index += 1;
        }
    }
    String::from_utf8(out).ok()
}

/// `projectedV1OperationResponse` for the control operations PHP refuses
/// on a projection before reading their bodies: signal, update, cancel,
/// terminate, repair and archive check the instance's current run; redrive
/// checks its source run. A query is refused once its `input` passes
/// validation, as PHP validates it first. Cooperative cancellation refuses
/// in its own transaction.
pub(super) async fn read_only_guard(request: axum::extract::Request, next: axum::middleware::Next) -> axum::response::Response {
    use axum::response::IntoResponse;
    use super::control_plane::Operation;
    if request.method() != axum::http::Method::POST {
        return next.run(request).await;
    }
    let path = request.uri().path().to_owned();
    let segments: Vec<&str> = path.trim_start_matches('/').split('/').collect();
    let (workflow, run, operation, name) = match segments.as_slice() {
        ["api", "workflows", workflow, operation @ ("cancel" | "terminate" | "repair" | "archive")] => (*workflow, None, *operation, None),
        ["api", "workflows", workflow, operation @ ("signal" | "query" | "update"), name] => (*workflow, None, *operation, Some(*name)),
        ["api", "workflows", workflow, "runs", run, operation @ ("cancel" | "terminate" | "repair" | "archive" | "redrive")] => {
            (*workflow, Some(*run), *operation, None)
        }
        ["api", "workflows", workflow, "runs", run, operation @ ("signal" | "query" | "update"), name] => {
            (*workflow, Some(*run), *operation, Some(*name))
        }
        // Refused before its body is read: an append would deliver a signal
        // and create a workflow task for the projection.
        ["api", "workflows", workflow, "message-streams", stream, "messages"] => {
            (*workflow, None, "message_stream_append", Some(*stream))
        }
        _ => return next.run(request).await,
    };
    let (Some(runtime), Some(workflow)) = (request.extensions().get::<Runtime>().cloned(), decoded(workflow)) else {
        return next.run(request).await;
    };
    let name = name.and_then(decoded).unwrap_or_default();
    let projected = match (operation, run.and_then(decoded)) {
        ("redrive", Some(run)) => runtime.projected_run(&workflow, &run).await,
        _ => runtime.projected_current_run(&workflow).await,
    };
    let Ok(Some(run_id)) = projected else {
        return next.run(request).await;
    };
    let request = if operation == "query" {
        // `input` nullable|array: an invalid input answers its validation error.
        let (parts, body) = request.into_parts();
        let Ok(bytes) = axum::body::to_bytes(body, usize::MAX).await else {
            return next.run(axum::extract::Request::from_parts(parts, axum::body::Body::empty())).await;
        };
        let input_valid = match super::php_value::request_body(&bytes).get("input") {
            None | Some(Php::Null | Php::Array(_)) => true,
            Some(_) => false,
        };
        let request = axum::extract::Request::from_parts(parts, axum::body::Body::from(bytes));
        if !input_valid {
            return next.run(request).await;
        }
        request
    } else {
        request
    };
    drop(request);
    if operation == "message_stream_append" {
        return (StatusCode::CONFLICT, axum::Json(read_only_append(&workflow, &run_id, &name))).into_response();
    }
    let body = read_only_body(&workflow, &run_id, operation);
    let body = match operation {
        "signal" => Operation::Signal(&name).response(body),
        "query" => Operation::Query(&name).response(body),
        "update" => Operation::Update(&name).response(body),
        "cancel" => Operation::Cancel.response(body),
        "terminate" => Operation::Terminate.response(body),
        "repair" => Operation::Repair.response(body),
        "archive" => Operation::Archive.response(body),
        _ => body,
    };
    (StatusCode::CONFLICT, axum::Json(body)).into_response()
}

impl Runtime {
    /// `LegacyV1ProjectionController::store` once the gates have passed.
    pub(crate) async fn import_waterline_v1(&self, body: &[u8]) -> Result<(StatusCode, Value)> {
        let operation = || super::control_plane::Operation::ImportWaterlineV1;
        let request = super::php_value::request_body(body);
        let (source_id, workflow, dry_run) = match validate(&request) {
            Ok(validated) => validated,
            Err(response) => return Ok((StatusCode::UNPROCESSABLE_ENTITY, operation().response(response))),
        };
        let namespace = self.namespace_name();
        // Hashing and JSON encoding of the whole source stay off the async workers.
        let limits = self.structural;
        let outcome = tokio::task::spawn_blocking(move || normalize_bounded(&source_id, &workflow, Some(&namespace), &limits))
            .await
            .map_err(|_| super::refuse(StatusCode::SERVICE_UNAVAILABLE, "waterline_import_unavailable"))?;
        let answer = |status: &str, extra: Vec<(&str, Value)>| {
            let code = match status {
                "projected" => StatusCode::CREATED,
                "already_projected" | "dry_run" => StatusCode::OK,
                _ => StatusCode::CONFLICT,
            };
            (code, operation().response(report(&outcome, status, extra)))
        };
        // A projection too large for one transaction is refused before any
        // row is read or written.
        if let Outcome::TooLarge { error, .. } = &outcome {
            return Ok(answer("rejected", vec![("limit", error.to_json()["limit"].clone())]));
        }
        let Outcome::Valid(normalized) = &outcome else {
            return Ok(answer("rejected", Vec::new()));
        };
        let existing = self.waterline_existing(&normalized.workflow_id, &normalized.run_id).await?;
        if existing.run.is_some() || existing.instance_namespace.is_some() {
            let same = existing.run.as_ref().is_some_and(|run| existing.instance_namespace.is_some()
                && run.workflow_instance_id == normalized.workflow_id
                && run.import_source.as_deref() == Some(IMPORT_SOURCE)
                && run.import_id.as_deref() == Some(normalized.import_id.as_str())
                && run.dedupe_key.as_deref() == Some(normalized.dedupe_key.as_str()));
            let instance_namespace = existing.instance_namespace.clone().flatten();
            let run_namespace = existing.run.as_ref().and_then(|run| run.namespace.clone());
            if same && run_namespace.as_deref() == Some(normalized.namespace.as_str())
                && instance_namespace.as_deref() == Some(normalized.namespace.as_str())
            {
                let imported = existing.run.as_ref().and_then(|run| run.imported_at);
                return Ok(answer("already_projected", vec![("imported_at", super::task_queues::json_time(imported))]));
            }
            if same {
                let stored = instance_namespace.filter(|namespace| Some(namespace) == run_namespace.as_ref());
                let mut identity = normalized.identity.to_json();
                identity["standalone"]["namespace"] = json!(stored);
                let (reason, message, remediation) = match &stored {
                    None => ("v1_projection_storage_inconsistent".to_owned(),
                        "The existing v1 projection has inconsistent instance and run namespaces.".to_owned(),
                        "Repair or remove the inconsistent projection rows before repeating the import.".to_owned()),
                    Some(stored) => ("v1_projection_namespace_collision".to_owned(),
                        "This v1 identity is already projected in a different standalone namespace.".to_owned(),
                        format!("Repeat the operation in the existing {stored} namespace, or choose a different stable source_id for an independent projection.")),
                };
                return Ok(answer("rejected", vec![("identity", identity), ("requested_namespace", json!(normalized.namespace)),
                    ("reason", json!(reason)), ("message", json!(message)), ("remediation", json!(remediation))]));
            }
            let changed = existing.run.as_ref().is_some_and(|run| run.import_source.as_deref() == Some(IMPORT_SOURCE));
            return Ok(answer("rejected", if changed {
                vec![("reason", json!("v1_projection_changed")),
                    ("message", json!("This v1 identity was already projected from different source state.")),
                    ("remediation", json!("Inspect the existing projection and resolve it before importing a different snapshot of the same v1 identity."))]
            } else {
                vec![("reason", json!("native_v2_identity_collision")),
                    ("message", json!("The mapped standalone identity is already owned by a native or unrelated v2 execution.")),
                    ("remediation", json!("Choose a different stable source_id; the server will not overwrite the colliding v2 execution."))]
            }));
        }
        if dry_run {
            return Ok(answer("dry_run", Vec::new()));
        }
        let imported_at = now();
        Ok(match self.waterline_write(normalized, imported_at).await {
            Ok(()) => answer("projected", vec![("imported_at", super::task_queues::json_time(Some(imported_at)))]),
            Err(collision) => {
                let (reason, message, remediation) = if collision {
                    ("native_v2_identity_collision", "The mapped standalone identity was claimed while the projection was being written.",
                        "Retry with a different stable source_id; no existing execution was overwritten.")
                } else {
                    ("target_write_failed", "The standalone server could not persist the v1 projection transaction.",
                        "Verify server storage readiness, then repeat the same idempotent operation.")
                };
                answer("rejected", vec![("reason", json!(reason)), ("message", json!(message)), ("remediation", json!(remediation))])
            }
        })
    }
}

store_methods! {
    /// `terminalFailurePayload` for a failed projection, which has no failure
    /// snapshots: its activity failure summaries and their last message, or
    /// null when it has none.
    pub(crate) async fn projected_failure(&self, run_id: &str) -> Result<Value> {
        let rows = Self::query("SELECT sequence,event_type,payload FROM workflow_history_events WHERE workflow_run_id=$1 AND event_type IN ('ActivityFailed','ActivityTimedOut') ORDER BY sequence")
            .bind(run_id)
            .fetch_all(&self.pool)
            .await?;
        let mut summaries = Vec::with_capacity(rows.len());
        for row in &rows {
            let payload = DB::document_row(row, "payload")?;
            let mut summary = serde_json::Map::new();
            summary.insert("event_sequence".into(), json!(DB::number(row, "sequence")?));
            summary.insert("event_type".into(), json!(DB::string(row, "event_type")?));
            for field in ["activity_type", "activity_class", "activity_execution_id", "activity_attempt_id", "failure_id",
                "failure_category", "exception_type", "exception_class", "message"] {
                if let Some(text) = payload[field].as_str().filter(|text| !text.is_empty()) {
                    summary.insert(field.into(), json!(text));
                }
            }
            if !payload["non_retryable"].is_null() {
                summary.insert("non_retryable".into(), payload["non_retryable"].clone());
            }
            summaries.push(Value::Object(summary));
        }
        if summaries.is_empty() {
            return Ok(Value::Null);
        }
        let message = summaries.iter().rev().find_map(|summary| summary["message"].as_str().map(str::to_owned));
        let mut failure = serde_json::Map::new();
        if let Some(message) = message {
            failure.insert("message".into(), json!(message));
        }
        failure.insert("activity_failures".into(), Value::Array(summaries));
        Ok(Value::Object(failure))
    }

    /// `LegacyV1Projection::metadataForRun` for a projected run: the started
    /// event's projection context and the projection's own description.
    pub(crate) async fn projection_metadata(&self, run_id: &str, imported_at: Option<DateTime<Utc>>, dedupe_key: Option<String>) -> Result<Value> {
        let started = Self::query("SELECT payload FROM workflow_history_events WHERE workflow_run_id=$1 ORDER BY sequence LIMIT 1")
            .bind(run_id)
            .fetch_optional(&self.pool)
            .await?
            .map(|row| DB::document_row(&row, "payload"))
            .transpose()?
            .unwrap_or(Value::Null);
        let migration = &started["migration_projection"];
        let unsupported = migration["unsupported_fields"].as_array().cloned().unwrap_or_default();
        Ok(json!({
            "origin": migration["origin"],
            "identity": migration["identity"],
            "task_queue_context": migration["task_queue_context"],
            "unsupported_fields": unsupported,
            "projection": {"schema": SCHEMA, "version": VERSION, "read_only": true, "execution_owner": "v1",
                "imported_at": super::task_queues::json_time(imported_at), "dedupe_key": dedupe_key},
        }))
    }

    /// The current run of an instance in this namespace when it is a
    /// Waterline v1 projection (`NamespaceWorkflowScope::currentRun`).
    pub(crate) async fn projected_current_run(&self, workflow_id: &str) -> Result<Option<String>> {
        Self::query("SELECT r.id FROM workflow_instances i JOIN workflow_runs r ON r.id=i.current_run_id WHERE i.id=$1 AND i.namespace=$2 AND r.import_source=$3")
            .bind(workflow_id).bind(self.namespace.as_ref()).bind(IMPORT_SOURCE)
            .fetch_optional(&self.pool).await?
            .map(|row| DB::string(&row, "id")).transpose()
    }

    /// A selected run of this namespace when it is a Waterline v1 projection.
    pub(crate) async fn projected_run(&self, workflow_id: &str, run_id: &str) -> Result<Option<String>> {
        Self::query("SELECT id FROM workflow_runs WHERE id=$1 AND workflow_instance_id=$2 AND namespace=$3 AND import_source=$4")
            .bind(run_id).bind(workflow_id).bind(self.namespace.as_ref()).bind(IMPORT_SOURCE)
            .fetch_optional(&self.pool).await?
            .map(|row| DB::string(&row, "id")).transpose()
    }

    /// The run and instance already holding a mapped identity, in any
    /// namespace: workflow and run ids are global.
    pub(crate) async fn waterline_existing(&self, workflow_id: &str, run_id: &str) -> Result<Existing> {
        let run = Self::query("SELECT workflow_instance_id,import_source,import_id,import_dedupe_key,namespace,imported_at FROM workflow_runs WHERE id=$1")
            .bind(run_id)
            .fetch_optional(&self.pool)
            .await?
            .map(|row| -> Result<ExistingRun> {
                Ok(ExistingRun {
                    workflow_instance_id: DB::string(&row, "workflow_instance_id")?,
                    import_source: DB::optional_string(&row, "import_source")?,
                    import_id: DB::optional_string(&row, "import_id")?,
                    dedupe_key: DB::optional_string(&row, "import_dedupe_key")?,
                    namespace: DB::optional_string(&row, "namespace")?,
                    imported_at: DB::optional_instant(&row, "imported_at")?,
                })
            })
            .transpose()?;
        let instance_namespace = Self::query("SELECT namespace FROM workflow_instances WHERE id=$1")
            .bind(workflow_id)
            .fetch_optional(&self.pool)
            .await?
            .map(|row| DB::optional_string(&row, "namespace"))
            .transpose()?;
        Ok(Existing { run, instance_namespace })
    }

    /// The projection's instance, run, history and summary in one
    /// transaction. `Err(true)` when the identity was claimed meanwhile.
    pub(crate) async fn waterline_write(&self, projection: &Normalized, imported_at: DateTime<Utc>) -> std::result::Result<(), bool> {
        #[cfg(test)]
        install_probe::reached(&projection.workflow_id);
        let mut tx = self.begin().await.map_err(|_| false)?;
        let claimed = Self::query("SELECT id FROM workflow_instances WHERE id=$1")
            .bind(&projection.workflow_id).fetch_optional(&mut *tx).await.map_err(|_| false)?.is_some()
            || Self::query("SELECT id FROM workflow_runs WHERE id=$1")
                .bind(&projection.run_id).fetch_optional(&mut *tx).await.map_err(|_| false)?.is_some();
        if claimed {
            return Err(true);
        }
        // Eloquent stores ordinary date columns as the parsed time's own
        // wall-clock time; history times go through the UTC cast.
        let wall = |stamp: Option<Stamp>| stamp.map(|stamp| stamp.wall);
        let created = wall(projection.started_at).unwrap_or(imported_at);
        let empty = json!([]);
        Self::query("INSERT INTO workflow_instances(id,workflow_class,workflow_type,namespace,visibility_labels,memo,current_run_id,run_count,last_message_sequence,started_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,1,0,$8,$9,$10)")
            .bind(&projection.workflow_id).bind(&projection.class).bind(&projection.class).bind(&projection.namespace)
            .bind(DB::document(&empty)).bind(DB::document(&empty)).bind(&projection.run_id)
            .bind(DB::optional_time(wall(projection.started_at))).bind(DB::bind_time(created)).bind(DB::bind_time(imported_at))
            .execute(&mut *tx).await.map_err(|_| false)?;
        Self::query("INSERT INTO workflow_runs(id,workflow_instance_id,run_number,workflow_class,workflow_type,namespace,visibility_labels,status,closed_reason,payload_codec,arguments,output,connection,queue,last_history_sequence,last_command_sequence,message_cursor_position,started_at,closed_at,last_progress_at,import_source,import_id,import_dedupe_key,import_contract_version,imported_at,created_at,updated_at) VALUES ($1,$2,1,$3,$4,$5,$6,$7,$8,NULL,NULL,NULL,$9,$10,$11,0,0,$12,$13,$14,$15,$16,$17,$18,$19,$20,$21)")
            .bind(&projection.run_id).bind(&projection.workflow_id).bind(&projection.class).bind(&projection.class)
            .bind(&projection.namespace).bind(DB::document(&empty)).bind(projection.status).bind(projection.closed_reason.as_deref())
            .bind(projection.connection.as_deref()).bind(projection.queue.as_deref()).bind(projection.events.len() as i64)
            .bind(DB::optional_time(wall(projection.started_at))).bind(DB::optional_time(wall(projection.closed_at)))
            .bind(DB::optional_time(wall(projection.last_progress_at))).bind(IMPORT_SOURCE).bind(&projection.import_id)
            .bind(&projection.dedupe_key).bind(VERSION).bind(DB::bind_time(imported_at)).bind(DB::bind_time(created))
            .bind(DB::bind_time(imported_at))
            .execute(&mut *tx).await.map_err(|_| false)?;
        for event in &projection.events {
            let stored = wall(event.recorded_at).unwrap_or(imported_at);
            Self::query("INSERT INTO workflow_history_events(id,workflow_run_id,sequence,event_type,payload,recorded_at,recorded_at_utc,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9)")
                .bind(&event.id).bind(&projection.run_id).bind(event.sequence).bind(event.event_type)
                .bind(DB::document(&event.payload.to_json()))
                .bind(DB::optional_time(event.recorded_at.map(|at| at.utc)))
                .bind(DB::optional_time(event.recorded_at.map(|at| at.utc)))
                .bind(DB::bind_time(stored)).bind(DB::bind_time(stored))
                .execute(&mut *tx).await.map_err(|_| false)?;
        }
        let bucket = status_bucket(projection.status);
        let running = bucket == "running";
        let sort = wall(projection.last_progress_at).or(wall(projection.started_at)).unwrap_or(imported_at);
        Self::query("INSERT INTO workflow_run_summaries(id,workflow_instance_id,run_number,is_current_run,engine_source,class,workflow_type,namespace,visibility_labels,status,status_bucket,closed_reason,connection,queue,started_at,sort_timestamp,closed_at,liveness_state,liveness_reason,repair_blocked_reason,repair_attention,task_problem,exception_count,history_event_count,history_size_bytes,continue_as_new_recommended,created_at,updated_at) VALUES ($1,$2,1,TRUE,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,'v1_projection_read_only',FALSE,FALSE,$18,$19,$20,FALSE,$21,$22)")
            .bind(&projection.run_id).bind(&projection.workflow_id).bind(ENGINE_SOURCE)
            .bind(&projection.class).bind(&projection.class).bind(&projection.namespace).bind(DB::document(&empty))
            .bind(projection.status).bind(bucket).bind(projection.closed_reason.as_deref())
            .bind(projection.connection.as_deref()).bind(projection.queue.as_deref())
            .bind(DB::optional_time(wall(projection.started_at))).bind(DB::bind_time(sort))
            .bind(DB::optional_time(wall(projection.closed_at)))
            .bind(running.then_some("external_v1"))
            .bind(running.then_some("Execution remains owned by the source v1 runtime; this standalone row is read-only."))
            .bind(projection.exception_count).bind(projection.events.len() as i64).bind(projection.history_size_bytes)
            .bind(DB::bind_time(created)).bind(DB::bind_time(imported_at))
            .execute(&mut *tx).await.map_err(|_| false)?;
        tx.commit().await.map_err(|_| false)?;
        self.wake.notify_waiters();
        Ok(())
    }
}

/// The point where a projection's install asks for the transition lock,
/// for the controls that prove an append cannot cross an install waiting on
/// it. Keyed by workflow id, so tests running in parallel do not see each
/// other's installs.
#[cfg(test)]
mod install_probe {
    use std::collections::HashMap;
    use std::sync::{Arc, Mutex, OnceLock};
    use tokio::sync::Notify;

    fn watched() -> &'static Mutex<HashMap<String, Arc<Notify>>> {
        static WATCHED: OnceLock<Mutex<HashMap<String, Arc<Notify>>>> = OnceLock::new();
        WATCHED.get_or_init(Mutex::default)
    }

    /// Notified once the install of `workflow_id` reaches the transition lock.
    pub(super) fn watch(workflow_id: &str) -> Arc<Notify> {
        let notify = Arc::new(Notify::new());
        watched().lock().unwrap().insert(workflow_id.to_owned(), notify.clone());
        notify
    }

    pub(super) fn reached(workflow_id: &str) {
        if let Some(notify) = watched().lock().unwrap().get(workflow_id) {
            notify.notify_one();
        }
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[tokio::test]
    async fn direct_appends_and_malformed_counts_never_touch_a_projection() {
        let directory = tempfile::tempdir().unwrap();
        let runtime = super::super::Runtime::open(directory.path().join("projection.sqlite").to_str().unwrap(), "test-token".into())
            .await
            .unwrap();
        let source = json!({"id": "v1:4201", "legacy_id": "4201", "engine_source": "v1", "class": "App\\Workflows\\Legacy\\Subscription",
            "status": "running", "arguments": "a:0:{}", "logs": [{"id": 9101, "index": 0, "class": "App\\Activities\\Bill",
                "result": "i:42;", "created_at": "2026-09-30T09:00:02.000000Z"}], "created_at": "2026-09-30T09:00:00.000000Z"});
        let body = serde_json::to_vec(&json!({"source_id": "guard-src", "workflow": source})).unwrap();
        let (status, report) = runtime.import_waterline_v1(&body).await.unwrap();
        assert_eq!(status, StatusCode::CREATED, "{report}");
        let workflow_id = report["identity"]["standalone"]["workflow_id"].as_str().unwrap().to_owned();
        let run_id = report["identity"]["standalone"]["run_id"].clone();
        let pool = runtime.sqlite_pool();
        let snapshot = || async {
            let mut counts = Vec::new();
            for table in ["workflow_inbound_streams", "workflow_inbound_stream_items", "workflow_signal_records", "workflow_commands",
                "workflow_history_events", "workflow_tasks", "workflow_run_summaries"] {
                let count: i64 = sqlx::query_scalar(sqlx::AssertSqlSafe(format!("SELECT COUNT(*) FROM {table}"))).fetch_one(pool).await.unwrap();
                counts.push((table, count));
            }
            let summary: Option<String> = sqlx::query_scalar("SELECT CAST(updated_at AS TEXT) FROM workflow_run_summaries")
                .fetch_one(pool).await.unwrap();
            (counts, summary)
        };
        let before = snapshot().await;

        // The service refuses before reading the body, valid or not.
        for body in [json!({"message_id": "m-1", "input": ["x"]}), json!({"message_id": 7, "input": "not a list"})] {
            match runtime.append_message(&workflow_id, "orders", body, json!({})).await {
                Err(super::super::RuntimeError::Protocol { status, response }) => {
                    assert_eq!(status, StatusCode::CONFLICT, "{response}");
                    assert_eq!((&response["reason"], &response["run_id"]), (&json!("v1_projection_read_only"), &run_id), "{response}");
                    assert_eq!(response["operation"], "message_stream_append");
                }
                other => panic!("{other:?}"),
            }
        }
        // The store's own guard refuses under its lock, and malformed input
        // is not counted against a projection.
        match runtime.store_append_message(&workflow_id, "orders", "m-2", "wwHioz3/VYAiNwA=").await {
            Err(super::super::RuntimeError::Protocol { status, response }) => {
                assert_eq!((status, &response["reason"]), (StatusCode::CONFLICT, &json!("v1_projection_read_only")), "{response}");
            }
            other => panic!("{other:?}"),
        }
        runtime.record_malformed_message(&workflow_id, "orders", &json!("m-3")).await.unwrap();

        assert_eq!(snapshot().await, before);
        runtime.close().await;
    }

    /// SQLite's writer waits inside its own busy handler, which no other
    /// connection can see; the install probe shows it reached the transition
    /// lock instead. With `BEGIN IMMEDIATE` held, an append finds no instance
    /// and writes nothing; once the install commits, an append is refused.
    #[tokio::test(flavor = "multi_thread", worker_threads = 4)]
    async fn an_append_cannot_cross_an_install_waiting_on_the_sqlite_lock() {
        use sqlx::Connection;
        let directory = tempfile::tempdir().unwrap();
        let path = directory.path().join("barrier.sqlite");
        let runtime = super::super::Runtime::open(path.to_str().unwrap(), "test-token".into()).await.unwrap();
        let source = json!({"id": "v1:4201", "legacy_id": "4201", "engine_source": "v1", "class": "App\\Workflows\\Legacy\\Subscription",
            "status": "running", "arguments": "a:0:{}", "logs": [{"id": 9101, "index": 0, "class": "App\\Activities\\Bill",
                "result": "i:42;", "created_at": "2026-09-30T09:00:02.000000Z"}], "created_at": "2026-09-30T09:00:00.000000Z"});
        let body = |dry_run: bool| serde_json::to_vec(&json!({"source_id": "barrier-src", "workflow": source, "dry_run": dry_run})).unwrap();
        let (status, dry) = runtime.import_waterline_v1(&body(true)).await.unwrap();
        assert_eq!(status, StatusCode::OK, "{dry}");
        let workflow_id = dry["identity"]["standalone"]["workflow_id"].as_str().unwrap().to_owned();
        let pool = runtime.sqlite_pool().clone();
        let counts = || async {
            let tables: Vec<String> = sqlx::query_scalar("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")
                .fetch_all(&pool).await.unwrap();
            let mut counts = Vec::new();
            for table in tables {
                let count: i64 = sqlx::query_scalar(sqlx::AssertSqlSafe(format!("SELECT COUNT(*) FROM {table}"))).fetch_one(&pool).await.unwrap();
                counts.push((table, count));
            }
            counts
        };
        let before = counts().await;
        let append = |message: &str| json!({"message_id": message, "input": ["x"]});

        let reached = install_probe::watch(&workflow_id);
        let mut holder = sqlx::SqliteConnection::connect(&format!("sqlite://{}", path.display())).await.unwrap();
        sqlx::query("BEGIN IMMEDIATE").execute(&mut holder).await.unwrap();
        let install = tokio::spawn({
            let runtime = runtime.clone();
            let body = body(false);
            async move { runtime.import_waterline_v1(&body).await }
        });
        tokio::time::timeout(std::time::Duration::from_secs(10), reached.notified()).await
            .expect("the install never reached the transition lock");

        // The install is at its transaction and cannot commit.
        match runtime.append_message(&workflow_id, "orders", append("m-pending"), json!({})).await {
            Err(super::super::RuntimeError::Protocol { status, response }) => {
                assert_eq!((status, &response["reason"]), (StatusCode::NOT_FOUND, &json!("instance_not_found")), "{response}");
            }
            other => panic!("{other:?}"),
        }
        assert!(!install.is_finished(), "the install committed while BEGIN IMMEDIATE was held");
        sqlx::query("ROLLBACK").execute(&mut holder).await.unwrap();

        let (status, report) = install.await.unwrap().unwrap();
        assert_eq!(status, StatusCode::CREATED, "{report}");
        match runtime.append_message(&workflow_id, "orders", append("m-after"), json!({})).await {
            Err(super::super::RuntimeError::Protocol { status, response }) => {
                assert_eq!((status, &response["reason"]), (StatusCode::CONFLICT, &json!("v1_projection_read_only")), "{response}");
            }
            other => panic!("{other:?}"),
        }
        // Only the projection's rows were written.
        let after = counts().await;
        assert_eq!(before.len(), after.len());
        for ((table, was), (_, now)) in before.iter().zip(&after) {
            let grew = match table.as_str() {
                "workflow_instances" | "workflow_runs" | "workflow_run_summaries" => 1,
                "workflow_history_events" => 2,
                _ => 0,
            };
            assert_eq!(*now, was + grew, "{table}");
        }
        runtime.close().await;
    }

    #[test]
    fn size_limits_name_the_first_dimension_exceeded() {
        let limits = |events, bytes| super::super::StructuralLimits { history_events: events, history_bytes: bytes,
            ..super::super::StructuralLimits::default() };
        assert!(exceeded(&limits(Some(3), Some(100)), 3, 100).is_none());
        let refusal = exceeded(&limits(Some(3), Some(100)), 4, 101).unwrap().to_json();
        assert_eq!((&refusal["reason"], &refusal["limit"]), (&json!("v1_projection_too_large"),
            &json!({"dimension": "history_transaction_size", "used": 4, "limit": 3})));
        let refusal = exceeded(&limits(Some(3), Some(100)), 3, 101).unwrap().to_json();
        assert_eq!(refusal["limit"]["dimension"], "history_transaction_bytes");
        // 0 disables a limit.
        assert!(exceeded(&limits(Some(0), Some(0)), usize::MAX, usize::MAX).is_none());
    }

    #[test]
    fn mapped_identities_follow_php() {
        // PHP: LegacyV1Projection::mappedIdentity('legacy-prod', 'v1:42').
        let (workflow, run, import) = mapped_identity("legacy-prod", "v1:42");
        assert!(workflow.starts_with("v1:legacy-prod:") && workflow.len() == "v1:legacy-prod:".len() + 24);
        assert_eq!(run.len(), 26);
        assert_eq!(import.len(), 64);
        assert_eq!(mapped_identity("Legacy:Prod..X", "v1:1").0.split(':').nth(1), Some("legacy-prod..x"));
        assert_eq!(mapped_identity(&"a".repeat(40), "v1:1").0.split(':').nth(1).map(str::len), Some(32));
        assert_eq!(mapped_identity("::", "v1:1").0.split(':').nth(1), Some("source"));
    }

    /// A completed source with `logs` logs, parsed as the request body is.
    fn completed_source(logs: usize) -> Php {
        let logs: Vec<Value> = (0..logs).map(|index| json!({"id": index, "class": "App\\Activities\\Step",
            "result": "s:4:\"done\";", "created_at": "2026-09-30T08:00:01.000000Z"})).collect();
        let body = json!({"operator_id": "v1:4501", "engine_source": "v1", "class": "App\\Workflows\\Legacy\\Bare",
            "status": "completed", "logs": logs, "signals": [{"id": 1, "name": "go"}], "exceptions": "not rows"});
        super::super::php_value::decode(body.to_string().as_bytes()).unwrap()
    }

    fn limited(events: Option<usize>, bytes: Option<usize>) -> super::super::StructuralLimits {
        super::super::StructuralLimits { history_events: events, history_bytes: bytes, ..super::super::StructuralLimits::default() }
    }

    /// Events built by `events` while `run` runs on this thread.
    fn events_built<T>(run: impl FnOnce() -> T) -> (T, usize) {
        EVENTS_BUILT.with(|count| count.set(0));
        let result = run();
        (result, EVENTS_BUILT.with(std::cell::Cell::get))
    }

    #[test]
    fn the_event_count_is_refused_before_any_event_is_built() {
        let source = completed_source(10);
        let source = source.array().unwrap();
        // Start, ten logs, one signal and the terminal event; a non-row
        // exceptions value counts nothing.
        let (outcome, built) = events_built(|| normalize_bounded("src-1", source, None, &limited(Some(13), None)));
        let Outcome::Valid(normalized) = outcome else { panic!("exact count") };
        assert_eq!((normalized.events.len(), built), (13, 13));
        let (outcome, built) = events_built(|| normalize_bounded("src-1", source, None, &limited(Some(12), None)));
        match outcome {
            Outcome::TooLarge { error, built: reported } => {
                // Refused from the row counts: no event built.
                assert_eq!((reported, built), (0, 0));
                assert_eq!(error.to_json()["limit"], json!({"dimension": "history_transaction_size", "used": 13, "limit": 12}));
            }
            _ => panic!("one over the event limit"),
        }
        // An invalid source still answers its own errors first.
        let invalid = super::super::php_value::decode(br#"{"engine_source":"v1","logs":[{},{},{}]}"#).unwrap();
        assert!(matches!(normalize_bounded("src-1", invalid.array().unwrap(), None, &limited(Some(1), None)), Outcome::Invalid { .. }));
    }

    #[test]
    fn history_bytes_stop_the_build_at_the_first_event_over_the_limit() {
        let source = completed_source(10);
        let source = source.array().unwrap();
        let Outcome::Valid(normalized) = normalize_bounded("src-1", source, None, &limited(None, None)) else { panic!("valid") };
        let total = usize::try_from(normalized.history_size_bytes).unwrap();
        let Outcome::Valid(exact) = normalize_bounded("src-1", source, None, &limited(None, Some(total))) else { panic!("exact bytes") };
        assert_eq!(exact.history_size_bytes, normalized.history_size_bytes);
        // One byte short: every event is built, and the last passes the limit.
        match normalize_bounded("src-1", source, None, &limited(None, Some(total - 1))) {
            Outcome::TooLarge { error, built } => {
                assert_eq!(built, normalized.events.len());
                assert_eq!(error.to_json()["limit"], json!({"dimension": "history_transaction_bytes", "used": total, "limit": total - 1}));
            }
            _ => panic!("one byte over"),
        }
    }

    #[test]
    fn an_early_event_over_the_byte_limit_builds_no_later_event() {
        // The start event is first and carries the source's arguments; many
        // valid logs follow it.
        let mut source = completed_source(2_000);
        if let Php::Array(array) = &mut source {
            array.0.push((Key::of("arguments"), text("x".repeat(50_000))));
        }
        let source = source.array().unwrap();
        let (outcome, built) = events_built(|| normalize_bounded("src-1", source, None, &limited(None, Some(10_000))));
        match outcome {
            Outcome::TooLarge { error, built: reported } => {
                // Only the start event was built; none of the 2,002 after it.
                assert_eq!((reported, built), (1, 1));
                assert_eq!(error.to_json()["limit"]["dimension"], "history_transaction_bytes");
            }
            _ => panic!("the start event alone passes the limit"),
        }
        // With room for the start event and a few more, the build stops at
        // the first event past the limit.
        let (outcome, start) = events_built(|| normalize_bounded("src-1", source, None, &limited(None, None)));
        let Outcome::Valid(all) = outcome else { panic!("valid") };
        assert_eq!(start, all.events.len());
        let first = |count: usize| all.events[..count].iter().map(|event| object(vec![
            ("event_type", text(event.event_type)),
            ("recorded_at", optional(json_time(event.recorded_at))),
            ("payload", event.payload.clone()),
            ("sequence", Php::Int(event.sequence)),
            ("id", text(&event.id)),
        ]).encode(Flags { unescaped_slashes: true, ..Flags::default() }).len()).sum::<usize>();
        let (outcome, built) = events_built(|| normalize_bounded("src-1", source, None, &limited(None, Some(first(4)))));
        assert!(matches!(outcome, Outcome::TooLarge { built: 5, .. }));
        assert_eq!(built, 5);
    }

    #[test]
    fn events_and_history_size_follow_php() {
        // PHP 2.11.1: LegacyV1Projection::normalize('src-1', bare source, 'ns-1')
        // printed these event encodings (json_encode, JSON_UNESCAPED_SLASHES).
        let source = super::super::php_value::decode(br#"{"operator_id":"v1:4501","engine_source":"v1","class":"App\\Workflows\\Legacy\\Bare","status":"completed"}"#)
            .unwrap();
        let Outcome::Valid(normalized) = normalize("src-1", source.array().unwrap(), Some("ns-1")) else { panic!("valid") };
        let encoded: Vec<String> = normalized.events.iter().map(|event| object(vec![
            ("event_type", text(event.event_type)),
            ("recorded_at", optional(json_time(event.recorded_at))),
            ("payload", event.payload.clone()),
            ("sequence", Php::Int(event.sequence)),
            ("id", text(&event.id)),
        ]).encode(Flags { unescaped_slashes: true, ..Flags::default() })).collect();
        assert_eq!(encoded[0].len(), 1374, "{}", encoded[0]);
        assert_eq!(encoded[1].len(), 496, "{}", encoded[1]);
        assert_eq!(normalized.history_size_bytes, 1870);
        assert_eq!(normalized.workflow_id, "v1:src-1:c8c25815a3332910ba072598");
        assert_eq!(normalized.run_id, "b0ee56b2b89080ea9181490e2f");
        assert_eq!(normalized.events[0].id, "9730c0bb4149e0a120cc8a8d7e");
    }

    #[test]
    fn floats_cast_to_strings_as_php() {
        assert_eq!(float_string(4101.0), "4101");
        assert_eq!(float_string(1.5), "1.5");
        assert_eq!(float_string(0.1 + 0.2), "0.3");
        assert_eq!(float_string(1e25), "1.0E+25");
        assert_eq!(float_string(-0.00001), "-1.0E-5");
        assert_eq!(float_string(0.0001), "0.0001");
    }
}
