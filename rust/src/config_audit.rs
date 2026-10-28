//! Startup audit of PHP Server's `DW_*` environment contract
//! (`config/dw-contract.php`).
//!
//! Every contract setting has a disposition here. Native honors it, ignores
//! it because it cannot change what clients or operators observe, or refuses
//! to start when its value would make PHP behave in a way native does not.
//! Values are read the way PHP's config reads them: Laravel's `env()`
//! normalization, then the `DW_*` name with its legacy fallback.
//!
//! Like PHP's `env:audit`, the audit also warns about unknown `DW_*` names
//! and legacy names, and `DW_ENV_AUDIT_STRICT=1` turns those warnings into a
//! startup refusal.

use std::collections::BTreeMap;

use crate::runtime::WORKER_PROTOCOL_VERSION;

/// A value as Laravel's `env()` returns it.
#[derive(Clone, Debug, PartialEq, Eq)]
pub enum PhpValue {
    Null,
    Bool(bool),
    Str(String),
}

impl PhpValue {
    /// `Illuminate\Support\Env::get`: the words true, false, empty and null,
    /// bare or in parentheses and in any case, become typed values. One pair
    /// of matching outer quotes is removed from anything else.
    pub fn from_env(raw: &str) -> Self {
        match raw.to_ascii_lowercase().as_str() {
            "true" | "(true)" => return Self::Bool(true),
            "false" | "(false)" => return Self::Bool(false),
            "empty" | "(empty)" => return Self::Str(String::new()),
            "null" | "(null)" => return Self::Null,
            _ => {}
        }
        let bytes = raw.as_bytes();
        // `/\A(['"])(.*)\1\z/`: `.` does not cross a newline.
        if bytes.len() >= 2
            && matches!(bytes[0], b'"' | b'\'')
            && bytes[bytes.len() - 1] == bytes[0]
            && !raw[1..raw.len() - 1].contains('\n')
        {
            return Self::Str(raw[1..raw.len() - 1].to_owned());
        }
        Self::Str(raw.to_owned())
    }

    /// PHP's `(string)` cast.
    pub fn string(&self) -> &str {
        match self {
            Self::Null | Self::Bool(false) => "",
            Self::Bool(true) => "1",
            Self::Str(value) => value,
        }
    }

    /// PHP's `(int)` cast.
    pub fn int(&self) -> i64 {
        match self {
            Self::Null | Self::Bool(false) => 0,
            Self::Bool(true) => 1,
            Self::Str(value) => string_to_int(value),
        }
    }

    /// PHP's `(bool)` cast.
    pub fn cast_bool(&self) -> bool {
        match self {
            Self::Null => false,
            Self::Bool(value) => *value,
            Self::Str(value) => !(value.is_empty() || value == "0"),
        }
    }

    /// `filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE)`.
    pub fn filter_bool(&self) -> Option<bool> {
        match self {
            Self::Null => Some(false),
            Self::Bool(value) => Some(*value),
            Self::Str(value) => {
                match value
                    .trim_matches(|c| matches!(c, ' ' | '\t' | '\r' | '\n' | '\u{b}' | '\0'))
                    .to_ascii_lowercase()
                    .as_str()
                {
                    "1" | "true" | "on" | "yes" => Some(true),
                    "0" | "false" | "off" | "no" | "" => Some(false),
                    _ => None,
                }
            }
        }
    }

    /// PHP 8's `is_numeric`.
    pub fn is_numeric(&self) -> bool {
        match self {
            Self::Str(value) => {
                let value = value.trim_start_matches(php_space).trim_end_matches(php_space);
                numeric_prefix(value).is_some_and(|(length, _)| length == value.len())
            }
            Self::Null | Self::Bool(_) => false,
        }
    }
}

fn php_space(c: char) -> bool {
    matches!(c, ' ' | '\t' | '\n' | '\r' | '\u{b}' | '\u{c}')
}

/// The length of the leading PHP numeric string, and whether it is written
/// as a float (with a fraction or exponent).
fn numeric_prefix(value: &str) -> Option<(usize, bool)> {
    let bytes = value.as_bytes();
    let mut at = 0;
    if matches!(bytes.first(), Some(b'+' | b'-')) {
        at = 1;
    }
    let digits = |from: usize| bytes[from..].iter().take_while(|b| b.is_ascii_digit()).count();
    let whole = digits(at);
    at += whole;
    let mut float = false;
    let mut fraction = 0;
    if bytes.get(at) == Some(&b'.') {
        fraction = digits(at + 1);
        if whole > 0 || fraction > 0 {
            at += 1 + fraction;
            float = true;
        }
    }
    if whole == 0 && fraction == 0 {
        return None;
    }
    if matches!(bytes.get(at), Some(b'e' | b'E')) {
        let sign = usize::from(matches!(bytes.get(at + 1), Some(b'+' | b'-')));
        let exponent = digits(at + 1 + sign);
        if exponent > 0 {
            at += 1 + sign + exponent;
            float = true;
        }
    }
    Some((at, float))
}

/// PHP 8's `(int)` cast of a string: the leading numeric string after
/// whitespace, truncated toward zero and saturated, else 0.
fn string_to_int(value: &str) -> i64 {
    let value = value.trim_start_matches(php_space);
    match numeric_prefix(value) {
        None => 0,
        Some((length, true)) => value[..length].parse::<f64>().map_or(0, |number| number as i64),
        Some((length, false)) => value[..length].parse::<i64>().unwrap_or_else(|_| {
            if value.starts_with('-') {
                i64::MIN
            } else {
                i64::MAX
            }
        }),
    }
}

/// `EnvAuditor::env` and the package's `Env::dw`: the `DW_*` name unless
/// `env()` gives null for it, then the legacy name. `None` means PHP uses
/// its default.
pub fn effective<F>(lookup: F, name: &str, legacy: Option<&str>) -> Option<PhpValue>
where
    F: Fn(&str) -> Option<String>,
{
    match lookup(name).map(|raw| PhpValue::from_env(&raw)) {
        None | Some(PhpValue::Null) => legacy.and_then(|legacy| lookup(legacy).map(|raw| PhpValue::from_env(&raw))),
        value => value,
    }
}

/// `filter_var($value, FILTER_VALIDATE_INT)` within a range: surrounding
/// whitespace and a sign are allowed, a leading zero is not.
pub fn filter_int(value: &str, min: i64, max: i64) -> Option<i64> {
    let value = value.trim_matches(|c| matches!(c, ' ' | '\t' | '\r' | '\n' | '\u{b}' | '\0'));
    let (negative, digits) = match value.as_bytes().first() {
        Some(b'-') => (true, &value[1..]),
        Some(b'+') => (false, &value[1..]),
        _ => (false, value),
    };
    if digits.is_empty()
        || !digits.bytes().all(|byte| byte.is_ascii_digit())
        || (digits.len() > 1 && digits.starts_with('0'))
    {
        return None;
    }
    let number = digits.parse::<i64>().ok()?;
    let number = if negative { number.checked_neg()? } else { number };
    (min..=max).contains(&number).then_some(number)
}

/// PHP refuses to boot unless this lease is valid.
pub const ACTIVITY_TASK_TIMEOUT_MESSAGE: &str =
    "DW_ACTIVITY_TASK_TIMEOUT must be an integer between 1 and 86400 seconds.";

/// `ActivityTaskLeaseConfiguration::apply`: the activity task lease, 300
/// seconds unless set to an integer from 1 to 86400.
pub fn activity_task_lease_seconds<F>(lookup: F) -> Result<i64, &'static str>
where
    F: Fn(&str) -> Option<String>,
{
    match effective(lookup, "DW_ACTIVITY_TASK_TIMEOUT", Some("ACTIVITY_TASK_TIMEOUT")) {
        None => Ok(300),
        Some(PhpValue::Str(value)) => filter_int(&value, 1, 86_400).ok_or(ACTIVITY_TASK_TIMEOUT_MESSAGE),
        Some(_) => Err(ACTIVITY_TASK_TIMEOUT_MESSAGE),
    }
}

/// The longest update completion wait, whether a caller asks for it or the
/// operator configures the default (Server #775,
/// `ControlPlaneRequestContract::UPDATE_WAIT_TIMEOUT_SECONDS_MAX`).
pub const UPDATE_WAIT_TIMEOUT_SECONDS_MAX: i64 = 60;

/// `UpdateWaitPolicy::completionTimeoutSeconds`: the package casts the
/// setting with `(int)`, then takes at least one second; unset means 10.
/// The server's `UpdateWaitConfiguration` caps it at 60.
pub fn update_wait_completion_timeout_seconds<F>(lookup: F) -> i64
where
    F: Fn(&str) -> Option<String>,
{
    effective(
        lookup,
        "DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS",
        Some("WORKFLOW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS"),
    )
    .map_or(10, |value| value.int().clamp(1, UPDATE_WAIT_TIMEOUT_SECONDS_MAX))
}

/// `server.update_validation`: how long an update request waits for its
/// validator (`timeout`, at least 0, 10 when unset) and how long a worker's
/// claim on a validation task lasts (`lease_timeout`, at least 1, 5 when
/// unset). The broker casts each with `(int)`.
pub fn update_validation_timeouts<F>(lookup: F) -> (i64, i64)
where
    F: Fn(&str) -> Option<String>,
{
    let read = |name, legacy, default: i64| effective(&lookup, name, Some(legacy)).map_or(default, |value: PhpValue| value.int());
    (
        read("DW_UPDATE_VALIDATION_TIMEOUT", "WORKFLOW_SERVER_UPDATE_VALIDATION_TIMEOUT", 10).max(0),
        read("DW_UPDATE_VALIDATION_LEASE_TIMEOUT", "WORKFLOW_SERVER_UPDATE_VALIDATION_LEASE_TIMEOUT", 5).max(1),
    )
}

/// `server.worker_protocol.history_page_size_default` and `_max`: `(int)`
/// casts of the settings, 500 and 1000 when unset. The Workflow package's
/// bridge then keeps every page between 1 and 1000 events.
pub fn history_page_sizes<F>(lookup: F) -> (i64, i64)
where
    F: Fn(&str) -> Option<String>,
{
    let read = |name, legacy, default| effective(&lookup, name, Some(legacy)).map_or(default, |value: PhpValue| value.int());
    (
        read("DW_HISTORY_PAGE_SIZE_DEFAULT", "WORKFLOW_SERVER_HISTORY_PAGE_SIZE_DEFAULT", 500),
        read("DW_HISTORY_PAGE_SIZE_MAX", "WORKFLOW_SERVER_HISTORY_PAGE_SIZE_MAX", 1000),
    )
}

/// The raw value PHP's `EnvAuditor::jsonObject` reads: the `DW_*` name
/// whenever it is set, even to an invalid value, else the legacy name.
/// Laravel's `env()` conversions do not apply.
fn selected<'a, F>(lookup: &F, name: &'a str, legacy: Option<&'a str>) -> Option<(String, &'a str)>
where
    F: Fn(&str) -> Option<String>,
{
    lookup(name)
        .map(|raw| (raw, name))
        .or_else(|| legacy.and_then(|legacy| lookup(legacy).map(|raw| (raw, legacy))))
}

/// How a refusal names the variable it read.
fn read_from(name: &str, source: &str) -> String {
    if source == name { name.to_owned() } else { format!("{name} (read from {source})") }
}

/// `EnvAuditor::jsonObject` (Server #705): a policy map is unset, or raw text
/// that decodes to a JSON object. Malformed JSON, `null`, booleans, numbers,
/// strings and lists are refused instead of becoming an empty policy, so a
/// mistyped cap cannot silently disappear. The refusal never repeats the value.
pub fn json_object<F>(lookup: F, name: &str, legacy: Option<&str>) -> Result<serde_json::Map<String, serde_json::Value>, String>
where
    F: Fn(&str) -> Option<String>,
{
    let Some((raw, source)) = selected(&lookup, name, legacy) else {
        return Ok(serde_json::Map::new());
    };
    match serde_json::from_str(&raw) {
        Ok(serde_json::Value::Object(policy)) => Ok(policy),
        _ => Err(format!("{} must contain a valid JSON object", read_from(name, source))),
    }
}

/// Policy maps native honors. Each must still be unset or a JSON object.
const HONORED_JSON_OBJECTS: &[(&str, &str)] =
    &[("DW_EXTERNAL_PAYLOAD_NAMESPACE_OVERRIDES", "WORKFLOW_SERVER_EXTERNAL_PAYLOAD_NAMESPACE_OVERRIDES")];

/// What a refused setting must be for native to start.
#[derive(Clone, Copy, Debug)]
pub enum Rule {
    /// The `(string)` value is one of these.
    Exact(&'static [&'static str]),
    /// The `(string)` value is none of these.
    NotIn(&'static [&'static str]),
    /// The `(string)` value equals this in any case.
    AnyCase(&'static str),
    /// The trimmed, lowercased `(string)` value is none of these.
    NotAnyCase(&'static [&'static str]),
    /// The `(string)` value is blank.
    Empty,
    /// `positiveIntOrNull` gives null: not a positive number.
    NoPositiveInt,
    /// `json_decode` gives nothing. Strict settings also refuse invalid JSON,
    /// which PHP either rejects or cannot have meant.
    EmptyJson { strict: bool },
    /// A policy map read by `EnvAuditor::jsonObject`: unset or a JSON object
    /// (see [`json_object`]), and empty, since native has no such policy.
    EmptyObject,
    /// The `(int)` cast equals PHP's default.
    Int(i64),
    /// `filter_var(..., FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? default`
    /// equals the default.
    FilterBool(bool),
    /// The `(bool)` cast equals PHP's default.
    CastBool(bool),
    /// The value names native's own database connection, or is blank.
    DatabaseConnection,
}

impl Rule {
    fn accepts(self, value: &PhpValue, database_connection: &str) -> bool {
        let text = value.string();
        match self {
            Self::Exact(values) => values.contains(&text),
            Self::NotIn(values) => !values.contains(&text),
            Self::AnyCase(expected) => text.eq_ignore_ascii_case(expected),
            Self::NotAnyCase(values) => {
                let text = text.trim().to_ascii_lowercase();
                !values.contains(&text.as_str())
            }
            Self::Empty => text.trim().is_empty(),
            Self::NoPositiveInt => {
                let trimmed = PhpValue::Str(text.trim().to_owned());
                !(matches!(value, PhpValue::Str(_)) && trimmed.is_numeric() && trimmed.int() > 0)
            }
            Self::EmptyJson { strict } => match serde_json::from_str::<serde_json::Value>(text) {
                Ok(decoded) => !php_truthy(&decoded),
                Err(_) => !strict,
            },
            Self::EmptyObject => {
                matches!(serde_json::from_str(text), Ok(serde_json::Value::Object(policy)) if policy.is_empty())
            }
            Self::Int(expected) => value.int() == expected,
            Self::FilterBool(default) => value.filter_bool().unwrap_or(default) == default,
            Self::CastBool(default) => value.cast_bool() == default,
            Self::DatabaseConnection => text.trim().is_empty() || text == database_connection,
        }
    }

    fn expectation(self) -> String {
        let quoted = |values: &[&str]| {
            values
                .iter()
                .map(|value| if value.is_empty() { "empty".to_owned() } else { format!("`{value}`") })
                .collect::<Vec<_>>()
                .join(" or ")
        };
        match self {
            Self::Exact(values) => format!("unset or {}", quoted(values)),
            Self::NotIn(values) | Self::NotAnyCase(values) => format!("unset or not {}", quoted(values)),
            Self::AnyCase(value) => format!("unset or `{value}`"),
            Self::Empty => "unset or empty".into(),
            Self::NoPositiveInt => "unset, empty or not a positive number".into(),
            Self::EmptyJson { .. } | Self::EmptyObject => "unset or `{}`".into(),
            Self::Int(value) => format!("unset or `{value}`"),
            Self::FilterBool(value) | Self::CastBool(value) => format!("unset or {value}"),
            Self::DatabaseConnection => "unset, empty or the DB_CONNECTION name".into(),
        }
    }
}

fn php_truthy(value: &serde_json::Value) -> bool {
    use serde_json::Value;
    match value {
        Value::Null => false,
        Value::Bool(value) => *value,
        Value::Number(number) => number.as_f64().is_some_and(|number| number != 0.0),
        Value::String(text) => !(text.is_empty() || text == "0"),
        Value::Array(items) => !items.is_empty(),
        Value::Object(fields) => !fields.is_empty(),
    }
}

#[derive(Clone, Copy, Debug)]
pub enum Disposition {
    /// Native reads the setting and applies PHP's meaning.
    Honored,
    /// The setting changes nothing native clients or operators observe.
    Ignored,
    /// Native refuses to start unless the setting keeps PHP's default meaning.
    Refused(Rule, &'static str),
}

#[derive(Clone, Copy, Debug)]
pub struct Setting {
    pub name: &'static str,
    pub legacy: Option<&'static str>,
    pub disposition: Disposition,
}

/// The startup findings.
#[derive(Debug, Default, PartialEq, Eq)]
pub struct Report {
    /// Settings native cannot honor. Startup stops on any of these.
    pub refusals: Vec<String>,
    /// PHP's `env:audit` warnings: unknown `DW_*` names and legacy names.
    pub warnings: Vec<String>,
    /// `DW_ENV_AUDIT_STRICT=1`: warnings stop startup too, as in PHP's image.
    pub strict: bool,
}

impl Report {
    pub fn refusal(&self) -> Option<String> {
        if !self.refusals.is_empty() {
            return Some(self.refusals.join("; "));
        }
        (self.strict && !self.warnings.is_empty()).then(|| {
            "DW_ENV_AUDIT_STRICT=1 refuses to start with unknown or legacy environment names".into()
        })
    }
}

/// Audit an environment snapshot.
pub fn audit(environment: &BTreeMap<String, String>) -> Report {
    let lookup = |key: &str| environment.get(key).cloned();
    let database_connection = environment.get("DB_CONNECTION").map_or("sqlite", String::as_str);
    let mut report = Report {
        // The image entrypoint compares the raw value in shell.
        strict: environment.get("DW_ENV_AUDIT_STRICT").is_some_and(|value| value == "1"),
        ..Report::default()
    };
    for setting in SETTINGS.iter().chain(UNLISTED) {
        let Disposition::Refused(rule, reason) = setting.disposition else {
            continue;
        };
        if let Rule::EmptyObject = rule {
            match json_object(lookup, setting.name, setting.legacy) {
                Err(message) => report.refusals.push(message),
                Ok(policy) if !policy.is_empty() => {
                    let source = selected(&lookup, setting.name, setting.legacy).map_or(setting.name, |(_, source)| source);
                    report.refusals.push(format!("{} must be {}: {reason}", read_from(setting.name, source), rule.expectation()));
                }
                Ok(_) => {}
            }
            continue;
        }
        let read = |name: &'static str| lookup(name).map(|raw| (PhpValue::from_env(&raw), name));
        let primary = read(setting.name).filter(|(value, _)| *value != PhpValue::Null);
        let Some((value, source)) = primary.or_else(|| setting.legacy.and_then(read)) else {
            continue;
        };
        if !rule.accepts(&value, database_connection) {
            let source = if source == setting.name { String::new() } else { format!(" (read from {source})") };
            report
                .refusals
                .push(format!("{}{source} must be {}: {reason}", setting.name, rule.expectation()));
        }
    }
    if let Err(message) = activity_task_lease_seconds(lookup) {
        report.refusals.push(message.into());
    }
    for (name, legacy) in HONORED_JSON_OBJECTS {
        if let Err(message) = json_object(lookup, name, Some(legacy)) {
            report.refusals.push(message);
        }
    }
    let unknown: Vec<&str> = environment
        .keys()
        .map(String::as_str)
        .filter(|name| name.starts_with("DW_"))
        .filter(|name| !SETTINGS.iter().chain(NATIVE_HONORED).any(|setting| setting.name == *name))
        .filter(|name| !native_only(name))
        .collect();
    if !unknown.is_empty() {
        report.warnings.push(format!(
            "Unknown DW_ variables set in environment (typo or silent-drop rename?): {}",
            unknown.join(", ")
        ));
    }
    for name in environment.keys() {
        if let Some(setting) = SETTINGS.iter().chain(NATIVE_HONORED).find(|setting| setting.legacy == Some(name.as_str())) {
            report.warnings.push(format!(
                "Legacy env var {name} is still honored but deprecated — rename to {}.",
                setting.name
            ));
        }
    }
    report
}

/// Names native reads that PHP's contract does not list.
fn native_only(name: &str) -> bool {
    matches!(
        name,
        "DW_BIND_ADDRESS" | "DW_ENV" | "DW_RUST_EXPERIMENTAL" | "DW_RUST_MAINTENANCE_LOOP" | "DW_UPGRADE_BACKUP_FINGERPRINT"
    )
        || ["DW_RUST_UPGRADE_QUEUE_", "DW_SCHEMA_TEST_", "DW_TAKEOVER_TEST_", "DW_TEST_"]
            .iter()
            .any(|prefix| name.starts_with(prefix))
}

const TOPOLOGY: &str = "native runs only as a standalone HTTP server process";
const DEFAULT_NAMESPACE: &str = "native serves `default` to requests that name no namespace";
const POLL_DISPATCH: &str = "native hands tasks only to polling workers";
const MEMO_RECOVERY: &str = "native has no memo migration recovery mode";
const EXTERNAL_EXECUTOR: &str = "native has no external executor configuration";
const STORAGE_ADMISSION: &str = "native has no storage admission file";
/// The parity harness looks for this exact wording.
const AUTH: &str = "unsupported development authentication configuration";
const FORWARDED: &str = "native never trusts forwarded attribution headers";
const TASK_ADMISSION: &str = "native has no task admission limits";
const NAMESPACE_ADMISSION: &str = "native has no namespace request admission limits";
const DURABLE_LIMITS: &str = "native has no namespace durable-state limits";
const PROTOCOL: &str = "native implements one worker protocol version";
const QUERY_PENDING: &str = "native cannot apply a changed pending query task cap";
const METRICS: &str = "native cannot apply a changed metrics series limit";
const S3: &str = "native stores external payloads only on its local disk";
const NEXUS: &str = "native cannot apply a changed Nexus operation limit";
const PROVENANCE: &str = "native does not publish package provenance";
const BOUNDARY: &str = "native has no service boundary policy";
const V1: &str = "native does not run Workflow 1 workflows";
const COMPATIBILITY: &str = "native pins runs to registered worker build ids only; it has no server-wide compatibility markers";
const PINNING: &str = "native always pins runs to their recorded workflow definition";
const STRUCTURAL: &str = "native cannot apply a changed structural limit";
const REPAIR: &str = "native cannot apply changed task repair timing";
const MULTI_NODE: &str = "native does not report a multi-node declaration";
const FLEET: &str = "native does not validate worker fleet compatibility";
const SERIALIZER: &str = "native reports no payload codec issues";
const STORAGE_CONNECTION: &str = "native keeps workflow tables on its one database connection";
const LEGACY_SCHEDULE_TIMEZONE: &str = "native does not convert pre-UTC schedule timestamps";

use Disposition::{Honored, Ignored, Refused};
use Rule::{AnyCase, CastBool, DatabaseConnection, Empty, EmptyJson, EmptyObject, Exact, FilterBool, Int, NoPositiveInt, NotAnyCase, NotIn};

/// Settings PHP reads that its contract does not list. PHP's audit warns about
/// them as unknown names; native also refuses values it cannot honor.
const UNLISTED: &[Setting] = &[
    Setting { name: "DW_STORAGE_CONNECTION", legacy: Some("WORKFLOW_STORAGE_CONNECTION"), disposition: Refused(DatabaseConnection, STORAGE_CONNECTION) },
    Setting { name: "DW_V2_LEGACY_SCHEDULE_STORAGE_TIMEZONE", legacy: Some("WORKFLOW_V2_LEGACY_SCHEDULE_STORAGE_TIMEZONE"), disposition: Refused(Empty, LEGACY_SCHEDULE_TIMEZONE) },
];

/// Settings native honors that PHP's contract does not list yet: the
/// Workflow package reads them (Workflow #930), and PHP's contract gains them
/// when the PHP Server takes that release. They are not unknown names, and
/// their legacy names warn as the contract's do.
pub const NATIVE_HONORED: &[Setting] = &[
    Setting { name: "DW_V2_LIMIT_HISTORY_TRANSACTION_BYTES", legacy: Some("WORKFLOW_V2_LIMIT_HISTORY_TRANSACTION_BYTES"), disposition: Honored },
    Setting { name: "DW_V2_LIMIT_HISTORY_TRANSACTION_ROWS", legacy: Some("WORKFLOW_V2_LIMIT_HISTORY_TRANSACTION_ROWS"), disposition: Honored },
];

/// Every `vars` entry of `config/dw-contract.php`, with its contract legacy name.
pub const SETTINGS: &[Setting] = &[
    Setting { name: "DW_ACTIVITY_TASK_MAX_ACTIVE_LEASES_PER_NAMESPACE", legacy: Some("WORKFLOW_SERVER_ACTIVITY_TASK_MAX_ACTIVE_LEASES_PER_NAMESPACE"), disposition: Refused(NoPositiveInt, TASK_ADMISSION) },
    Setting { name: "DW_ACTIVITY_TASK_MAX_ACTIVE_LEASES_PER_QUEUE", legacy: Some("WORKFLOW_SERVER_ACTIVITY_TASK_MAX_ACTIVE_LEASES_PER_QUEUE"), disposition: Refused(NoPositiveInt, TASK_ADMISSION) },
    Setting { name: "DW_ACTIVITY_TASK_MAX_DISPATCHES_PER_MINUTE", legacy: Some("WORKFLOW_SERVER_ACTIVITY_TASK_MAX_DISPATCHES_PER_MINUTE"), disposition: Refused(NoPositiveInt, TASK_ADMISSION) },
    Setting { name: "DW_ACTIVITY_TASK_MAX_DISPATCHES_PER_MINUTE_PER_NAMESPACE", legacy: Some("WORKFLOW_SERVER_ACTIVITY_TASK_MAX_DISPATCHES_PER_MINUTE_PER_NAMESPACE"), disposition: Refused(NoPositiveInt, TASK_ADMISSION) },
    Setting { name: "DW_ACTIVITY_TASK_TIMEOUT", legacy: Some("ACTIVITY_TASK_TIMEOUT"), disposition: Honored },
    Setting { name: "DW_ADMIN_SIGNATURE_KEY", legacy: Some("WORKFLOW_SERVER_ADMIN_SIGNATURE_KEY"), disposition: Ignored },
    Setting { name: "DW_ADMIN_TOKEN", legacy: Some("WORKFLOW_SERVER_ADMIN_TOKEN"), disposition: Honored },
    Setting { name: "DW_AUTH_BACKWARD_COMPATIBLE", legacy: Some("WORKFLOW_SERVER_AUTH_BACKWARD_COMPATIBLE"), disposition: Refused(FilterBool(true), AUTH) },
    Setting { name: "DW_AUTH_DRIVER", legacy: Some("WORKFLOW_SERVER_AUTH_DRIVER"), disposition: Honored },
    Setting { name: "DW_AUTH_METHOD_HEADER", legacy: Some("WORKFLOW_SERVER_AUTH_METHOD_HEADER"), disposition: Ignored },
    Setting { name: "DW_AUTH_PROVIDER", legacy: Some("WORKFLOW_SERVER_AUTH_PROVIDER"), disposition: Refused(Empty, AUTH) },
    Setting { name: "DW_AUTH_STATUS_HEADER", legacy: Some("WORKFLOW_SERVER_AUTH_STATUS_HEADER"), disposition: Ignored },
    Setting { name: "DW_AUTH_TOKEN", legacy: Some("WORKFLOW_SERVER_AUTH_TOKEN"), disposition: Honored },
    Setting { name: "DW_BOOTSTRAP_DELAY_SECONDS", legacy: Some("WORKFLOW_SERVER_BOOTSTRAP_DELAY_SECONDS"), disposition: Ignored },
    Setting { name: "DW_BOOTSTRAP_RETRIES", legacy: Some("WORKFLOW_SERVER_BOOTSTRAP_RETRIES"), disposition: Ignored },
    Setting { name: "DW_CALLER_LABEL_HEADER", legacy: Some("WORKFLOW_SERVER_CALLER_LABEL_HEADER"), disposition: Ignored },
    Setting { name: "DW_CALLER_TYPE_HEADER", legacy: Some("WORKFLOW_SERVER_CALLER_TYPE_HEADER"), disposition: Ignored },
    Setting { name: "DW_COMPRESSION_ENABLED", legacy: Some("WORKFLOW_SERVER_COMPRESSION_ENABLED"), disposition: Ignored },
    Setting { name: "DW_DEFAULT_NAMESPACE", legacy: Some("WORKFLOW_SERVER_DEFAULT_NAMESPACE"), disposition: Refused(AnyCase("default"), DEFAULT_NAMESPACE) },
    Setting { name: "DW_DUE_TIMER_RECOVERY_SCAN_LIMIT", legacy: Some("WORKFLOW_SERVER_DUE_TIMER_RECOVERY_SCAN_LIMIT"), disposition: Ignored },
    Setting { name: "DW_ENV_AUDIT_STRICT", legacy: None, disposition: Honored },
    Setting { name: "DW_EXPIRED_WORKFLOW_TASK_RECOVERY_SCAN_LIMIT", legacy: Some("WORKFLOW_SERVER_EXPIRED_WORKFLOW_TASK_RECOVERY_SCAN_LIMIT"), disposition: Honored },
    Setting { name: "DW_EXPIRED_WORKFLOW_TASK_RECOVERY_TTL_SECONDS", legacy: Some("WORKFLOW_SERVER_EXPIRED_WORKFLOW_TASK_RECOVERY_TTL_SECONDS"), disposition: Ignored },
    Setting { name: "DW_EXPOSE_PACKAGE_PROVENANCE", legacy: Some("WORKFLOW_SERVER_EXPOSE_PACKAGE_PROVENANCE"), disposition: Refused(FilterBool(false), PROVENANCE) },
    Setting { name: "DW_EXTERNAL_EXECUTOR_CONFIG_OVERLAY", legacy: Some("WORKFLOW_SERVER_EXTERNAL_EXECUTOR_CONFIG_OVERLAY"), disposition: Refused(Empty, EXTERNAL_EXECUTOR) },
    Setting { name: "DW_EXTERNAL_EXECUTOR_CONFIG_PATH", legacy: Some("WORKFLOW_SERVER_EXTERNAL_EXECUTOR_CONFIG_PATH"), disposition: Refused(Empty, EXTERNAL_EXECUTOR) },
    Setting { name: "DW_EXTERNAL_PAYLOAD_COMPLETION_MAX_BYTES", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_COMPLETION_MAX_BYTES"), disposition: Honored },
    Setting { name: "DW_EXTERNAL_PAYLOAD_HARD_MAX_BYTES_PER_NAMESPACE", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_HARD_MAX_BYTES_PER_NAMESPACE"), disposition: Honored },
    Setting { name: "DW_EXTERNAL_PAYLOAD_HARD_MAX_OBJECTS_PER_NAMESPACE", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_HARD_MAX_OBJECTS_PER_NAMESPACE"), disposition: Honored },
    Setting { name: "DW_EXTERNAL_PAYLOAD_MAX_BYTES", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_MAX_BYTES"), disposition: Honored },
    Setting { name: "DW_EXTERNAL_PAYLOAD_MAX_BYTES_PER_NAMESPACE", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_MAX_BYTES_PER_NAMESPACE"), disposition: Honored },
    Setting { name: "DW_EXTERNAL_PAYLOAD_MAX_OBJECTS_PER_NAMESPACE", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_MAX_OBJECTS_PER_NAMESPACE"), disposition: Honored },
    Setting { name: "DW_EXTERNAL_PAYLOAD_NAMESPACE_OVERRIDES", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_NAMESPACE_OVERRIDES"), disposition: Honored },
    Setting { name: "DW_EXTERNAL_PAYLOAD_REQUEST_TIMEOUT", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_REQUEST_TIMEOUT"), disposition: Honored },
    Setting { name: "DW_EXTERNAL_PAYLOAD_S3_ACCESS_KEY_ID", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_S3_ACCESS_KEY_ID"), disposition: Ignored },
    Setting { name: "DW_EXTERNAL_PAYLOAD_S3_BUCKET", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_S3_BUCKET"), disposition: Refused(Empty, S3) },
    Setting { name: "DW_EXTERNAL_PAYLOAD_S3_ENDPOINT", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_S3_ENDPOINT"), disposition: Ignored },
    Setting { name: "DW_EXTERNAL_PAYLOAD_S3_REGION", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_S3_REGION"), disposition: Ignored },
    Setting { name: "DW_EXTERNAL_PAYLOAD_S3_SECRET_ACCESS_KEY", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_S3_SECRET_ACCESS_KEY"), disposition: Ignored },
    Setting { name: "DW_EXTERNAL_PAYLOAD_S3_SESSION_TOKEN", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_S3_SESSION_TOKEN"), disposition: Ignored },
    Setting { name: "DW_EXTERNAL_PAYLOAD_S3_USE_PATH_STYLE_ENDPOINT", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_S3_USE_PATH_STYLE_ENDPOINT"), disposition: Ignored },
    Setting { name: "DW_EXTERNAL_PAYLOAD_UPLOAD_EXPIRY", legacy: Some("WORKFLOW_SERVER_EXTERNAL_PAYLOAD_UPLOAD_EXPIRY"), disposition: Honored },
    Setting { name: "DW_HISTORY_PAGE_SIZE_DEFAULT", legacy: Some("WORKFLOW_SERVER_HISTORY_PAGE_SIZE_DEFAULT"), disposition: Honored },
    Setting { name: "DW_HISTORY_PAGE_SIZE_MAX", legacy: Some("WORKFLOW_SERVER_HISTORY_PAGE_SIZE_MAX"), disposition: Honored },
    Setting { name: "DW_HISTORY_RETENTION_DAYS", legacy: Some("WORKFLOW_HISTORY_RETENTION_DAYS"), disposition: Honored },
    Setting { name: "DW_MAX_HISTORY_EVENTS", legacy: Some("WORKFLOW_MAX_HISTORY_EVENTS"), disposition: Ignored },
    Setting { name: "DW_MAX_MEMO_BYTES", legacy: Some("WORKFLOW_MAX_MEMO_BYTES"), disposition: Honored },
    Setting { name: "DW_MAX_NEXUS_OPERATIONS_PER_CALLER", legacy: Some("WORKFLOW_MAX_NEXUS_OPERATIONS_PER_CALLER"), disposition: Refused(Int(200), NEXUS) },
    Setting { name: "DW_MAX_OPERATION_NAME_LENGTH", legacy: Some("WORKFLOW_MAX_OPERATION_NAME_LENGTH"), disposition: Refused(Int(256), NEXUS) },
    Setting { name: "DW_MAX_PAYLOAD_BYTES", legacy: Some("WORKFLOW_MAX_PAYLOAD_BYTES"), disposition: Honored },
    Setting { name: "DW_MAX_PENDING_ACTIVITIES", legacy: Some("WORKFLOW_MAX_PENDING_ACTIVITIES"), disposition: Honored },
    Setting { name: "DW_MAX_PENDING_CHILDREN", legacy: Some("WORKFLOW_MAX_PENDING_CHILDREN"), disposition: Honored },
    Setting { name: "DW_MAX_SEARCH_ATTRIBUTES", legacy: Some("WORKFLOW_MAX_SEARCH_ATTRIBUTES"), disposition: Honored },
    Setting { name: "DW_MAX_SEARCH_ATTRIBUTE_KEY_LENGTH", legacy: Some("WORKFLOW_MAX_SEARCH_ATTRIBUTE_KEY_LENGTH"), disposition: Honored },
    Setting { name: "DW_MAX_SEARCH_ATTRIBUTE_VALUE_BYTES", legacy: Some("WORKFLOW_MAX_SEARCH_ATTRIBUTE_VALUE_BYTES"), disposition: Honored },
    Setting { name: "DW_MAX_TASKS_PER_POLL", legacy: Some("WORKFLOW_SERVER_MAX_TASKS_PER_POLL"), disposition: Ignored },
    Setting { name: "DW_METRICS_PROMETHEUS_ACTIVITY_SERIES_LIMIT", legacy: Some("WORKFLOW_SERVER_METRICS_PROMETHEUS_ACTIVITY_SERIES_LIMIT"), disposition: Refused(Int(100), METRICS) },
    Setting { name: "DW_METRICS_PROMETHEUS_TASK_QUEUE_SERIES_LIMIT", legacy: Some("WORKFLOW_SERVER_METRICS_PROMETHEUS_TASK_QUEUE_SERIES_LIMIT"), disposition: Refused(Int(100), METRICS) },
    Setting { name: "DW_METRICS_PROMETHEUS_WORKFLOW_SERIES_LIMIT", legacy: Some("WORKFLOW_SERVER_METRICS_PROMETHEUS_WORKFLOW_SERIES_LIMIT"), disposition: Refused(Int(100), METRICS) },
    Setting { name: "DW_METRICS_WORKFLOW_TASK_FAILURE_TYPE_LIMIT", legacy: Some("WORKFLOW_SERVER_METRICS_WORKFLOW_TASK_FAILURE_TYPE_LIMIT"), disposition: Refused(Int(20), METRICS) },
    Setting { name: "DW_MODE", legacy: Some("WORKFLOW_SERVER_MODE"), disposition: Refused(Exact(&["service"]), TOPOLOGY) },
    Setting { name: "DW_NAMESPACE_ADMISSION_OVERRIDES", legacy: Some("WORKFLOW_SERVER_NAMESPACE_ADMISSION_OVERRIDES"), disposition: Refused(EmptyObject, NAMESPACE_ADMISSION) },
    Setting { name: "DW_NAMESPACE_DURABLE_HARD_LIMITS", legacy: Some("WORKFLOW_SERVER_NAMESPACE_DURABLE_HARD_LIMITS"), disposition: Refused(EmptyObject, DURABLE_LIMITS) },
    Setting { name: "DW_NAMESPACE_DURABLE_LIMITS", legacy: Some("WORKFLOW_SERVER_NAMESPACE_DURABLE_LIMITS"), disposition: Refused(EmptyObject, DURABLE_LIMITS) },
    Setting { name: "DW_NAMESPACE_DURABLE_OVERRIDES", legacy: Some("WORKFLOW_SERVER_NAMESPACE_DURABLE_OVERRIDES"), disposition: Refused(EmptyObject, DURABLE_LIMITS) },
    Setting { name: "DW_NAMESPACE_HARD_MAX_CONCURRENT_REQUESTS", legacy: Some("WORKFLOW_SERVER_NAMESPACE_HARD_MAX_CONCURRENT_REQUESTS"), disposition: Refused(Empty, NAMESPACE_ADMISSION) },
    Setting { name: "DW_NAMESPACE_HARD_MAX_REQUESTS_PER_MINUTE", legacy: Some("WORKFLOW_SERVER_NAMESPACE_HARD_MAX_REQUESTS_PER_MINUTE"), disposition: Refused(Empty, NAMESPACE_ADMISSION) },
    Setting { name: "DW_NAMESPACE_MAX_CONCURRENT_REQUESTS", legacy: Some("WORKFLOW_SERVER_NAMESPACE_MAX_CONCURRENT_REQUESTS"), disposition: Refused(Empty, NAMESPACE_ADMISSION) },
    Setting { name: "DW_NAMESPACE_MAX_REQUESTS_PER_MINUTE", legacy: Some("WORKFLOW_SERVER_NAMESPACE_MAX_REQUESTS_PER_MINUTE"), disposition: Refused(Empty, NAMESPACE_ADMISSION) },
    Setting { name: "DW_OBSERVABILITY_BAGGAGE_ALLOWLIST", legacy: None, disposition: Ignored },
    Setting { name: "DW_OBSERVABILITY_CONTEXT_ENABLED", legacy: None, disposition: Ignored },
    Setting { name: "DW_OPERATOR_SIGNATURE_KEY", legacy: Some("WORKFLOW_SERVER_OPERATOR_SIGNATURE_KEY"), disposition: Ignored },
    Setting { name: "DW_OPERATOR_TOKEN", legacy: Some("WORKFLOW_SERVER_OPERATOR_TOKEN"), disposition: Honored },
    Setting { name: "DW_PACKAGE_PROVENANCE_PATH", legacy: Some("WORKFLOW_SERVER_PACKAGE_PROVENANCE_PATH"), disposition: Ignored },
    Setting { name: "DW_POLLING_CACHE_PATH", legacy: Some("WORKFLOW_SERVER_POLLING_CACHE_PATH"), disposition: Ignored },
    Setting { name: "DW_PRINCIPAL_TOKENS", legacy: Some("WORKFLOW_SERVER_PRINCIPAL_TOKENS"), disposition: Refused(Empty, AUTH) },
    Setting { name: "DW_QUERY_TASK_LEASE_TIMEOUT", legacy: Some("WORKFLOW_SERVER_QUERY_TASK_LEASE_TIMEOUT"), disposition: Honored },
    Setting { name: "DW_QUERY_TASK_MAX_PENDING_PER_QUEUE", legacy: Some("WORKFLOW_SERVER_QUERY_TASK_MAX_PENDING_PER_QUEUE"), disposition: Refused(Int(1024), QUERY_PENDING) },
    Setting { name: "DW_QUERY_TASK_POLL_MAX_CONCURRENT", legacy: Some("WORKFLOW_SERVER_QUERY_TASK_POLL_MAX_CONCURRENT"), disposition: Ignored },
    Setting { name: "DW_QUERY_TASK_POLL_MAX_CONCURRENT_PER_NAMESPACE", legacy: Some("WORKFLOW_SERVER_QUERY_TASK_POLL_MAX_CONCURRENT_PER_NAMESPACE"), disposition: Ignored },
    Setting { name: "DW_QUERY_TASK_POLL_TIMEOUT", legacy: Some("WORKFLOW_SERVER_QUERY_TASK_POLL_TIMEOUT"), disposition: Honored },
    Setting { name: "DW_QUERY_TASK_TIMEOUT", legacy: Some("WORKFLOW_SERVER_QUERY_TASK_TIMEOUT"), disposition: Honored },
    Setting { name: "DW_QUERY_TASK_TTL_SECONDS", legacy: Some("WORKFLOW_SERVER_QUERY_TASK_TTL_SECONDS"), disposition: Honored },
    Setting { name: "DW_RUNTIME_CREDENTIALS_ENABLED", legacy: Some("WORKFLOW_SERVER_RUNTIME_CREDENTIALS_ENABLED"), disposition: Honored },
    Setting { name: "DW_SERIALIZER", legacy: Some("WORKFLOW_SERIALIZER"), disposition: Refused(Exact(&["", "avro"]), SERIALIZER) },
    Setting { name: "DW_SERVER_ID", legacy: Some("WORKFLOW_SERVER_ID"), disposition: Honored },
    Setting { name: "DW_SERVER_KEY", legacy: None, disposition: Honored },
    Setting { name: "DW_SERVER_PROCESS_CLASS", legacy: Some("WORKFLOW_SERVER_PROCESS_CLASS"), disposition: Refused(NotIn(&["scheduler_node", "worker_node"]), TOPOLOGY) },
    Setting { name: "DW_SERVER_TOPOLOGY_SHAPE", legacy: Some("WORKFLOW_SERVER_TOPOLOGY_SHAPE"), disposition: Refused(NotIn(&["embedded", "split_control_execution"]), TOPOLOGY) },
    Setting { name: "DW_SERVICE_BOUNDARY_CROSS_NAMESPACE_DEFAULT", legacy: Some("WORKFLOW_SERVER_SERVICE_BOUNDARY_CROSS_NAMESPACE_DEFAULT"), disposition: Refused(Exact(&["allow"]), BOUNDARY) },
    Setting { name: "DW_SERVICE_BOUNDARY_HARD_MAX_IN_FLIGHT", legacy: Some("WORKFLOW_SERVER_SERVICE_BOUNDARY_HARD_MAX_IN_FLIGHT"), disposition: Refused(Empty, BOUNDARY) },
    Setting { name: "DW_SERVICE_BOUNDARY_HARD_RATE_LIMIT_PER_MINUTE", legacy: Some("WORKFLOW_SERVER_SERVICE_BOUNDARY_HARD_RATE_LIMIT_PER_MINUTE"), disposition: Refused(Empty, BOUNDARY) },
    Setting { name: "DW_SERVICE_BOUNDARY_MAX_IN_FLIGHT", legacy: Some("WORKFLOW_SERVER_SERVICE_BOUNDARY_MAX_IN_FLIGHT"), disposition: Refused(Empty, BOUNDARY) },
    Setting { name: "DW_SERVICE_BOUNDARY_NAMESPACE_MAX_IN_FLIGHT", legacy: Some("WORKFLOW_SERVER_SERVICE_BOUNDARY_NAMESPACE_MAX_IN_FLIGHT"), disposition: Refused(Empty, BOUNDARY) },
    Setting { name: "DW_SERVICE_BOUNDARY_NAMESPACE_OVERRIDES", legacy: Some("WORKFLOW_SERVER_SERVICE_BOUNDARY_NAMESPACE_OVERRIDES"), disposition: Refused(EmptyJson { strict: true }, BOUNDARY) },
    Setting { name: "DW_SERVICE_BOUNDARY_NAMESPACE_RATE_LIMIT_PER_MINUTE", legacy: Some("WORKFLOW_SERVER_SERVICE_BOUNDARY_NAMESPACE_RATE_LIMIT_PER_MINUTE"), disposition: Refused(Empty, BOUNDARY) },
    Setting { name: "DW_SERVICE_BOUNDARY_RATE_LIMIT_PER_MINUTE", legacy: Some("WORKFLOW_SERVER_SERVICE_BOUNDARY_RATE_LIMIT_PER_MINUTE"), disposition: Refused(Empty, BOUNDARY) },
    Setting { name: "DW_SIGNATURE_KEY", legacy: Some("WORKFLOW_SERVER_SIGNATURE_KEY"), disposition: Ignored },
    Setting { name: "DW_SQLITE_CLAIM_LOCK_TTL_SECONDS", legacy: Some("WORKFLOW_SERVER_SQLITE_CLAIM_LOCK_TTL_SECONDS"), disposition: Ignored },
    Setting { name: "DW_SQLITE_CLAIM_LOCK_WAIT_SECONDS", legacy: Some("WORKFLOW_SERVER_SQLITE_CLAIM_LOCK_WAIT_SECONDS"), disposition: Ignored },
    Setting { name: "DW_STORAGE_ADMISSION_FILE", legacy: None, disposition: Refused(Empty, STORAGE_ADMISSION) },
    Setting { name: "DW_STORAGE_ADMISSION_MAX_AGE_SECONDS", legacy: None, disposition: Ignored },
    Setting { name: "DW_STORAGE_ADMISSION_SOURCE", legacy: None, disposition: Refused(Empty, STORAGE_ADMISSION) },
    Setting { name: "DW_TASK_DISPATCH_MODE", legacy: Some("WORKFLOW_V2_TASK_DISPATCH_MODE"), disposition: Refused(Exact(&["poll"]), POLL_DISPATCH) },
    Setting { name: "DW_TASK_QUEUE_ADMISSION_OVERRIDES", legacy: Some("WORKFLOW_SERVER_TASK_QUEUE_ADMISSION_OVERRIDES"), disposition: Refused(EmptyObject, TASK_ADMISSION) },
    Setting { name: "DW_TRUST_FORWARDED_ATTRIBUTION_HEADERS", legacy: Some("WORKFLOW_SERVER_TRUST_FORWARDED_ATTRIBUTION_HEADERS"), disposition: Refused(FilterBool(false), FORWARDED) },
    Setting { name: "DW_UPDATE_VALIDATION_LEASE_TIMEOUT", legacy: Some("WORKFLOW_SERVER_UPDATE_VALIDATION_LEASE_TIMEOUT"), disposition: Honored },
    Setting { name: "DW_UPDATE_VALIDATION_TIMEOUT", legacy: Some("WORKFLOW_SERVER_UPDATE_VALIDATION_TIMEOUT"), disposition: Honored },
    Setting { name: "DW_V1_ENABLED", legacy: Some("WORKFLOW_V1_ENABLED"), disposition: Refused(CastBool(false), V1) },
    Setting { name: "DW_V2_CACHE_VALIDATION_MODE", legacy: Some("WORKFLOW_V2_CACHE_VALIDATION_MODE"), disposition: Ignored },
    Setting { name: "DW_V2_COMPATIBILITY_HEARTBEAT_TTL", legacy: Some("WORKFLOW_V2_COMPATIBILITY_HEARTBEAT_TTL"), disposition: Refused(Int(30), COMPATIBILITY) },
    Setting { name: "DW_V2_COMPATIBILITY_NAMESPACE", legacy: Some("WORKFLOW_V2_COMPATIBILITY_NAMESPACE"), disposition: Refused(Empty, COMPATIBILITY) },
    Setting { name: "DW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD", legacy: Some("WORKFLOW_V2_CONTINUE_AS_NEW_EVENT_THRESHOLD"), disposition: Honored },
    Setting { name: "DW_V2_CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD", legacy: Some("WORKFLOW_V2_CONTINUE_AS_NEW_SIZE_BYTES_THRESHOLD"), disposition: Honored },
    Setting { name: "DW_V2_CURRENT_COMPATIBILITY", legacy: Some("WORKFLOW_V2_CURRENT_COMPATIBILITY"), disposition: Refused(Empty, COMPATIBILITY) },
    Setting { name: "DW_V2_FLEET_VALIDATION_MODE", legacy: Some("WORKFLOW_V2_FLEET_VALIDATION_MODE"), disposition: Refused(NotAnyCase(&["fail"]), FLEET) },
    Setting { name: "DW_V2_GUARDRAILS_BOOT", legacy: Some("WORKFLOW_V2_GUARDRAILS_BOOT"), disposition: Ignored },
    Setting { name: "DW_V2_HISTORY_EXPORT_SIGNING_KEY", legacy: Some("WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY"), disposition: Honored },
    Setting { name: "DW_V2_HISTORY_EXPORT_SIGNING_KEY_ID", legacy: Some("WORKFLOW_V2_HISTORY_EXPORT_SIGNING_KEY_ID"), disposition: Honored },
    Setting { name: "DW_V2_LIMIT_COMMAND_BATCH_SIZE", legacy: Some("WORKFLOW_V2_LIMIT_COMMAND_BATCH_SIZE"), disposition: Refused(Int(1000), STRUCTURAL) },
    Setting { name: "DW_V2_LIMIT_HISTORY_TRANSACTION_SIZE", legacy: Some("WORKFLOW_V2_LIMIT_HISTORY_TRANSACTION_SIZE"), disposition: Honored },
    Setting { name: "DW_V2_LIMIT_MEMO_SIZE_BYTES", legacy: Some("WORKFLOW_V2_LIMIT_MEMO_SIZE_BYTES"), disposition: Honored },
    Setting { name: "DW_V2_LIMIT_PAYLOAD_SIZE_BYTES", legacy: Some("WORKFLOW_V2_LIMIT_PAYLOAD_SIZE_BYTES"), disposition: Honored },
    Setting { name: "DW_V2_LIMIT_PENDING_ACTIVITIES", legacy: Some("WORKFLOW_V2_LIMIT_PENDING_ACTIVITIES"), disposition: Honored },
    Setting { name: "DW_V2_LIMIT_PENDING_CHILDREN", legacy: Some("WORKFLOW_V2_LIMIT_PENDING_CHILDREN"), disposition: Honored },
    Setting { name: "DW_V2_LIMIT_PENDING_SIGNALS", legacy: Some("WORKFLOW_V2_LIMIT_PENDING_SIGNALS"), disposition: Honored },
    Setting { name: "DW_V2_LIMIT_PENDING_TIMERS", legacy: Some("WORKFLOW_V2_LIMIT_PENDING_TIMERS"), disposition: Honored },
    Setting { name: "DW_V2_LIMIT_PENDING_UPDATES", legacy: Some("WORKFLOW_V2_LIMIT_PENDING_UPDATES"), disposition: Honored },
    Setting { name: "DW_V2_LIMIT_SEARCH_ATTRIBUTE_SIZE_BYTES", legacy: Some("WORKFLOW_V2_LIMIT_SEARCH_ATTRIBUTE_SIZE_BYTES"), disposition: Ignored },
    Setting { name: "DW_V2_LIMIT_WARNING_THRESHOLD_PERCENT", legacy: Some("WORKFLOW_V2_LIMIT_WARNING_THRESHOLD_PERCENT"), disposition: Ignored },
    Setting { name: "DW_V2_MATCHING_ROLE_QUEUE_WAKE", legacy: Some("WORKFLOW_V2_MATCHING_ROLE_QUEUE_WAKE"), disposition: Ignored },
    Setting { name: "DW_V2_MULTI_NODE", legacy: Some("WORKFLOW_V2_MULTI_NODE"), disposition: Refused(CastBool(false), MULTI_NODE) },
    Setting { name: "DW_V2_NAMESPACE", legacy: Some("WORKFLOW_V2_NAMESPACE"), disposition: Refused(Empty, COMPATIBILITY) },
    Setting { name: "DW_V2_PIN_TO_RECORDED_FINGERPRINT", legacy: Some("WORKFLOW_V2_PIN_TO_RECORDED_FINGERPRINT"), disposition: Refused(CastBool(true), PINNING) },
    Setting { name: "DW_V2_SUPPORTED_COMPATIBILITIES", legacy: Some("WORKFLOW_V2_SUPPORTED_COMPATIBILITIES"), disposition: Refused(Empty, COMPATIBILITY) },
    Setting { name: "DW_V2_TASK_DISPATCH_MODE", legacy: None, disposition: Ignored },
    Setting { name: "DW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS", legacy: Some("WORKFLOW_V2_TASK_REPAIR_FAILURE_BACKOFF_MAX_SECONDS"), disposition: Refused(Int(60), REPAIR) },
    Setting { name: "DW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS", legacy: Some("WORKFLOW_V2_TASK_REPAIR_LOOP_THROTTLE_SECONDS"), disposition: Ignored },
    Setting { name: "DW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS", legacy: Some("WORKFLOW_V2_TASK_REPAIR_REDISPATCH_AFTER_SECONDS"), disposition: Refused(Int(3), REPAIR) },
    Setting { name: "DW_V2_TASK_REPAIR_SCAN_LIMIT", legacy: Some("WORKFLOW_V2_TASK_REPAIR_SCAN_LIMIT"), disposition: Refused(Int(25), REPAIR) },
    Setting { name: "DW_V2_TENANCY_ENVIRONMENT", legacy: Some("WORKFLOW_V2_TENANCY_ENVIRONMENT"), disposition: Ignored },
    Setting { name: "DW_V2_TENANCY_ORGANIZATION", legacy: Some("WORKFLOW_V2_TENANCY_ORGANIZATION"), disposition: Ignored },
    Setting { name: "DW_V2_TENANCY_PROJECT", legacy: Some("WORKFLOW_V2_TENANCY_PROJECT"), disposition: Ignored },
    Setting { name: "DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS", legacy: Some("WORKFLOW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS"), disposition: Honored },
    Setting { name: "DW_V2_UPDATE_WAIT_POLL_INTERVAL_MS", legacy: Some("WORKFLOW_V2_UPDATE_WAIT_POLL_INTERVAL_MS"), disposition: Ignored },
    Setting { name: "DW_V2_VALIDATE_CACHE_BACKEND", legacy: Some("WORKFLOW_V2_VALIDATE_CACHE_BACKEND"), disposition: Ignored },
    Setting { name: "DW_WAKE_SIGNAL_TTL_SECONDS", legacy: Some("WORKFLOW_SERVER_WAKE_SIGNAL_TTL_SECONDS"), disposition: Ignored },
    Setting { name: "DW_WORKER_HEARTBEAT_INTERVAL_SECONDS", legacy: Some("WORKFLOW_SERVER_WORKER_HEARTBEAT_INTERVAL_SECONDS"), disposition: Honored },
    Setting { name: "DW_WORKER_LONG_POLL_MAX_CONCURRENT", legacy: Some("WORKFLOW_SERVER_WORKER_LONG_POLL_MAX_CONCURRENT"), disposition: Ignored },
    Setting { name: "DW_WORKER_LONG_POLL_MAX_CONCURRENT_PER_NAMESPACE", legacy: Some("WORKFLOW_SERVER_WORKER_LONG_POLL_MAX_CONCURRENT_PER_NAMESPACE"), disposition: Ignored },
    Setting { name: "DW_WORKER_LONG_POLL_RESERVED_HTTP_WORKERS", legacy: Some("WORKFLOW_SERVER_WORKER_LONG_POLL_RESERVED_HTTP_WORKERS"), disposition: Ignored },
    Setting { name: "DW_WORKER_POLL_INTERVAL_MS", legacy: Some("WORKFLOW_SERVER_WORKER_POLL_INTERVAL_MS"), disposition: Ignored },
    Setting { name: "DW_WORKER_POLL_SIGNAL_CHECK_INTERVAL_MS", legacy: Some("WORKFLOW_SERVER_WORKER_POLL_SIGNAL_CHECK_INTERVAL_MS"), disposition: Ignored },
    Setting { name: "DW_WORKER_POLL_TIMEOUT", legacy: Some("WORKFLOW_SERVER_WORKER_POLL_TIMEOUT"), disposition: Honored },
    Setting { name: "DW_WORKER_PROTOCOL_VERSION", legacy: Some("WORKFLOW_SERVER_WORKER_PROTOCOL_VERSION"), disposition: Refused(Exact(&[WORKER_PROTOCOL_VERSION]), PROTOCOL) },
    Setting { name: "DW_WORKER_SIGNATURE_KEY", legacy: Some("WORKFLOW_SERVER_WORKER_SIGNATURE_KEY"), disposition: Ignored },
    Setting { name: "DW_WORKER_STALE_AFTER_SECONDS", legacy: Some("WORKFLOW_SERVER_WORKER_STALE_AFTER_SECONDS"), disposition: Honored },
    Setting { name: "DW_WORKER_TOKEN", legacy: Some("WORKFLOW_SERVER_WORKER_TOKEN"), disposition: Honored },
    Setting { name: "DW_WORKFLOW_MEMO_MIGRATION_RECOVERY", legacy: Some("WORKFLOW_SERVER_WORKFLOW_MEMO_MIGRATION_RECOVERY"), disposition: Refused(Empty, MEMO_RECOVERY) },
    Setting { name: "DW_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_NAMESPACE", legacy: Some("WORKFLOW_SERVER_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_NAMESPACE"), disposition: Refused(NoPositiveInt, TASK_ADMISSION) },
    Setting { name: "DW_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_QUEUE", legacy: Some("WORKFLOW_SERVER_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_QUEUE"), disposition: Refused(NoPositiveInt, TASK_ADMISSION) },
    Setting { name: "DW_WORKFLOW_TASK_MAX_DISPATCHES_PER_MINUTE", legacy: Some("WORKFLOW_SERVER_WORKFLOW_TASK_MAX_DISPATCHES_PER_MINUTE"), disposition: Refused(NoPositiveInt, TASK_ADMISSION) },
    Setting { name: "DW_WORKFLOW_TASK_MAX_DISPATCHES_PER_MINUTE_PER_NAMESPACE", legacy: Some("WORKFLOW_SERVER_WORKFLOW_TASK_MAX_DISPATCHES_PER_MINUTE_PER_NAMESPACE"), disposition: Refused(NoPositiveInt, TASK_ADMISSION) },
    Setting { name: "DW_WORKFLOW_TASK_TIMEOUT", legacy: Some("WORKFLOW_TASK_TIMEOUT"), disposition: Honored },
];

#[cfg(test)]
mod tests {
    use super::*;

    fn environment(values: &[(&str, &str)]) -> BTreeMap<String, String> {
        values.iter().map(|(name, value)| ((*name).to_owned(), (*value).to_owned())).collect()
    }

    fn refusals(values: &[(&str, &str)]) -> Vec<String> {
        audit(&environment(values)).refusals
    }

    /// `(name, legacy, default)` for every `vars` entry, in contract order.
    fn contract() -> Vec<(String, Option<String>, Option<String>)> {
        let source = std::fs::read_to_string(concat!(env!("CARGO_MANIFEST_DIR"), "/../config/dw-contract.php"))
            .expect("config/dw-contract.php");
        let quoted = |line: &str| line.split('\'').nth(1).map(str::to_owned);
        let mut entries: Vec<(String, Option<String>, Option<String>)> = Vec::new();
        let mut in_vars = false;
        for line in source.lines() {
            if line.starts_with("    'vars' => [") {
                in_vars = true;
            } else if in_vars && line.starts_with("    ],") {
                break;
            } else if in_vars && line.starts_with("        '") && line.ends_with("=> [") {
                entries.push((quoted(line).unwrap(), None, None));
            } else if in_vars && line.starts_with("            'legacy' =>") {
                entries.last_mut().unwrap().1 = line.split("=>").nth(1).and_then(quoted);
            } else if in_vars && line.starts_with("            'default' =>") {
                entries.last_mut().unwrap().2 = line.split("=>").nth(1).and_then(quoted);
            }
        }
        entries
    }

    #[test]
    fn every_contract_setting_has_one_disposition() {
        let contract = contract();
        assert_eq!(contract.len(), 164, "PHP's contract changed; classify the new settings");
        let mut names: Vec<&str> = SETTINGS.iter().map(|setting| setting.name).collect();
        names.sort_unstable();
        names.dedup();
        assert_eq!(names.len(), SETTINGS.len(), "a setting is listed twice");
        for (name, legacy, _) in &contract {
            let setting = SETTINGS
                .iter()
                .find(|setting| setting.name == name)
                .unwrap_or_else(|| panic!("{name} is in the contract but has no disposition"));
            assert_eq!(setting.legacy, legacy.as_deref(), "{name}'s legacy name differs from the contract");
        }
        for setting in SETTINGS {
            assert!(contract.iter().any(|(name, ..)| name == setting.name), "{} is not in the contract", setting.name);
        }
        for setting in UNLISTED {
            assert!(!contract.iter().any(|(name, ..)| name == setting.name), "{} is now in the contract", setting.name);
        }
    }

    #[test]
    fn every_refusal_accepts_the_contract_default() {
        for (name, _, default) in contract() {
            // Skip placeholders and PHP constant names.
            let Some(default) = default.filter(|default| default != "(unset)" && !default.contains("::")) else {
                continue;
            };
            let setting = SETTINGS.iter().find(|setting| setting.name == name).unwrap();
            if let Refused(rule, _) = setting.disposition {
                assert!(rule.accepts(&PhpValue::from_env(&default), "sqlite"), "{name} refuses its default `{default}`");
            }
        }
    }

    #[test]
    fn shipped_deployment_values_start() {
        // Values the shipped Compose, Helm, k8s and `.env.example` files set
        // for the HTTP server process.
        let shipped = [
            ("DW_AUTH_BACKWARD_COMPATIBLE", "true"),
            ("DW_AUTH_PROVIDER", ""),
            ("DW_PRINCIPAL_TOKENS", ""),
            ("DW_DEFAULT_NAMESPACE", "default"),
            ("DW_EXTERNAL_PAYLOAD_S3_BUCKET", ""),
            ("DW_METRICS_WORKFLOW_TASK_FAILURE_TYPE_LIMIT", "20"),
            ("DW_NAMESPACE_DURABLE_LIMITS", "{}"),
            ("DW_NAMESPACE_DURABLE_HARD_LIMITS", "{}"),
            ("DW_NAMESPACE_DURABLE_OVERRIDES", "{}"),
            ("DW_SERVER_TOPOLOGY_SHAPE", "standalone_server"),
            ("DW_SERVER_PROCESS_CLASS", "server_http_node"),
            ("DW_STORAGE_ADMISSION_FILE", ""),
            ("DW_STORAGE_ADMISSION_SOURCE", ""),
            ("DW_TASK_DISPATCH_MODE", "poll"),
            ("DW_V1_ENABLED", "false"),
            ("DW_WORKFLOW_MEMO_MIGRATION_RECOVERY", ""),
            ("DW_ENV_AUDIT_STRICT", "1"),
            ("DW_RUST_EXPERIMENTAL", "1"),
            ("DW_RUST_MAINTENANCE_LOOP", "1"),
            ("DW_BIND_ADDRESS", "0.0.0.0:8080"),
        ];
        let report = audit(&environment(&shipped));
        assert_eq!(report, Report { strict: true, ..Report::default() });
        assert_eq!(report.refusal(), None);
    }

    #[test]
    fn values_are_read_like_laravel_env() {
        use PhpValue::{Bool, Null, Str};
        let cases = [
            ("true", Bool(true)),
            ("(TRUE)", Bool(true)),
            ("False", Bool(false)),
            ("(false)", Bool(false)),
            ("empty", Str(String::new())),
            ("(Empty)", Str(String::new())),
            ("null", Null),
            ("(null)", Null),
            ("\"quoted\"", Str("quoted".into())),
            ("'quoted'", Str("quoted".into())),
            ("\"true\"", Str("true".into())),
            ("'mismatched\"", Str("'mismatched\"".into())),
            ("\"", Str("\"".into())),
            ("\"\"", Str(String::new())),
            ("\"two\nlines\"", Str("\"two\nlines\"".into())),
            (" true", Str(" true".into())),
            ("", Str(String::new())),
        ];
        for (raw, expected) in cases {
            assert_eq!(PhpValue::from_env(raw), expected, "{raw:?}");
        }
    }

    #[test]
    fn casts_match_php() {
        let int = |raw: &str| PhpValue::Str(raw.into()).int();
        for (raw, expected) in [
            ("500", 500),
            (" 500", 500),
            ("500 ", 500),
            ("+7", 7),
            ("-3", -3),
            ("12abc", 12),
            ("1.9", 1),
            ("-1.9", -1),
            ("1e3", 1000),
            ("1e", 1),
            (".5", 0),
            ("abc", 0),
            ("", 0),
            ("0x1A", 0),
            ("99999999999999999999", i64::MAX),
        ] {
            assert_eq!(int(raw), expected, "(int) {raw:?}");
        }
        assert_eq!(PhpValue::Bool(true).int(), 1);
        assert_eq!(PhpValue::Null.int(), 0);
        let numeric = |raw: &str| PhpValue::Str(raw.into()).is_numeric();
        for raw in ["1", " 1", "1 ", "-1.5", ".5", "1.", "1e3", "+0"] {
            assert!(numeric(raw), "{raw:?}");
        }
        for raw in ["", ".", "1e", "abc", "1a", "0x1A", " "] {
            assert!(!numeric(raw), "{raw:?}");
        }
        let filter = |raw: &str| PhpValue::Str(raw.into()).filter_bool();
        for raw in ["1", "true", " Yes ", "ON"] {
            assert_eq!(filter(raw), Some(true), "{raw:?}");
        }
        for raw in ["0", "false", "off", "No", "", "  "] {
            assert_eq!(filter(raw), Some(false), "{raw:?}");
        }
        assert_eq!(filter("maybe"), None);
        let cast = |raw: &str| PhpValue::Str(raw.into()).cast_bool();
        assert!(cast("off") && cast("no") && cast("false ") && cast("00"));
        assert!(!cast("") && !cast("0"));
    }

    #[test]
    fn update_validation_timeouts_are_read_like_the_broker() {
        let read = |values: &[(&str, &str)]| {
            let environment = environment(values);
            update_validation_timeouts(|key| environment.get(key).cloned())
        };
        assert_eq!(read(&[]), (10, 5));
        assert_eq!(read(&[("DW_UPDATE_VALIDATION_TIMEOUT", "3"), ("DW_UPDATE_VALIDATION_LEASE_TIMEOUT", "2")]), (3, 2));
        assert_eq!(read(&[("WORKFLOW_SERVER_UPDATE_VALIDATION_TIMEOUT", "4")]), (4, 5));
        assert_eq!(read(&[("DW_UPDATE_VALIDATION_TIMEOUT", "-1"), ("DW_UPDATE_VALIDATION_LEASE_TIMEOUT", "0")]), (0, 1));
    }

    #[test]
    fn update_wait_is_read_like_the_package() {
        let read = |values: &[(&str, &str)]| {
            let environment = environment(values);
            update_wait_completion_timeout_seconds(|key| environment.get(key).cloned())
        };
        assert_eq!(read(&[]), 10);
        assert_eq!(read(&[("DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS", "25")]), 25);
        assert_eq!(read(&[("WORKFLOW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS", " 3s")]), 3);
        assert_eq!(read(&[("DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS", "0")]), 1);
        assert_eq!(read(&[("DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS", "never")]), 1);
        assert_eq!(read(&[("DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS", "null")]), 10);
        assert_eq!(read(&[("DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS", "60")]), 60);
        assert_eq!(read(&[("DW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS", "61")]), 60);
        assert_eq!(read(&[("WORKFLOW_V2_UPDATE_WAIT_COMPLETION_TIMEOUT_SECONDS", "600")]), 60);
    }

    #[test]
    fn history_page_sizes_are_read_like_server_config() {
        let read = |values: &[(&str, &str)]| {
            let environment = environment(values);
            history_page_sizes(|key| environment.get(key).cloned())
        };
        assert_eq!(read(&[]), (500, 1000));
        assert_eq!(read(&[("DW_HISTORY_PAGE_SIZE_DEFAULT", "50"), ("DW_HISTORY_PAGE_SIZE_MAX", "200")]), (50, 200));
        assert_eq!(read(&[("WORKFLOW_SERVER_HISTORY_PAGE_SIZE_DEFAULT", " 75 pages")]), (75, 1000));
        assert_eq!(read(&[("DW_HISTORY_PAGE_SIZE_MAX", "\"5000\"")]), (500, 5000));
        // `(int)` of a word or `false` is 0; the bridge then serves one event.
        assert_eq!(read(&[("DW_HISTORY_PAGE_SIZE_DEFAULT", "many"), ("DW_HISTORY_PAGE_SIZE_MAX", "false")]), (0, 0));
        assert_eq!(read(&[("DW_HISTORY_PAGE_SIZE_DEFAULT", "null")]), (500, 1000));
    }

    #[test]
    fn primary_names_win_and_legacy_names_fill_in_like_php() {
        let read = |values: &[(&str, &str)], name, legacy| {
            let environment = environment(values);
            effective(|key: &str| environment.get(key).cloned(), name, legacy)
        };
        let both = [("DW_X", "primary"), ("LEGACY_X", "legacy")];
        assert_eq!(read(&both, "DW_X", Some("LEGACY_X")), Some(PhpValue::Str("primary".into())));
        // A set but empty primary still wins, as PHP's env() returns it.
        let empty = [("DW_X", ""), ("LEGACY_X", "legacy")];
        assert_eq!(read(&empty, "DW_X", Some("LEGACY_X")), Some(PhpValue::Str(String::new())));
        // A null primary falls back; a null legacy value stays null.
        let null = [("DW_X", "(null)"), ("LEGACY_X", "legacy")];
        assert_eq!(read(&null, "DW_X", Some("LEGACY_X")), Some(PhpValue::Str("legacy".into())));
        assert_eq!(read(&[("DW_X", "null"), ("LEGACY_X", "NULL")], "DW_X", Some("LEGACY_X")), Some(PhpValue::Null));
        assert_eq!(read(&[("DW_X", "null")], "DW_X", None), None);
        assert_eq!(read(&[("LEGACY_X", "legacy")], "DW_X", None), None);
        assert_eq!(read(&[], "DW_X", Some("LEGACY_X")), None);
    }

    #[test]
    fn values_native_cannot_honor_refuse_start() {
        let accepted: &[&[(&str, &str)]] = &[
            &[],
            &[("DW_DEFAULT_NAMESPACE", "Default")],
            &[("WORKFLOW_SERVER_DEFAULT_NAMESPACE", "default")],
            &[("DW_DEFAULT_NAMESPACE", "null"), ("WORKFLOW_SERVER_DEFAULT_NAMESPACE", "default")],
            &[("DW_WORKER_PROTOCOL_VERSION", WORKER_PROTOCOL_VERSION)],
            &[("DW_ACTIVITY_TASK_TIMEOUT", "300")],
            &[("DW_ACTIVITY_TASK_TIMEOUT", " 30 ")],
            &[("ACTIVITY_TASK_TIMEOUT", "86400")],
            &[("DW_METRICS_PROMETHEUS_ACTIVITY_SERIES_LIMIT", "\"100\"")],
            // PHP's `?? true` keeps an unrecognized value on.
            &[("DW_AUTH_BACKWARD_COMPATIBLE", "maybe")],
            &[("DW_TRUST_FORWARDED_ATTRIBUTION_HEADERS", "(false)")],
            &[("DW_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_QUEUE", "0")],
            &[("DW_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_QUEUE", "true")],
            &[("DW_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_QUEUE", "none")],
            &[("DW_TASK_QUEUE_ADMISSION_OVERRIDES", "{}")],
            &[("DW_TASK_QUEUE_ADMISSION_OVERRIDES", " \n{ } ")],
            &[("WORKFLOW_SERVER_NAMESPACE_ADMISSION_OVERRIDES", "{}")],
            &[("DW_EXTERNAL_PAYLOAD_NAMESPACE_OVERRIDES", "{\"billing\":{\"max_bytes\":5}}")],
            &[("DW_V2_FLEET_VALIDATION_MODE", "warn")],
            &[("DW_V2_PIN_TO_RECORDED_FINGERPRINT", "no")],
            &[("DW_SERIALIZER", "")],
            &[("DW_STORAGE_CONNECTION", "pgsql"), ("DB_CONNECTION", "pgsql")],
            // Ignored and reported settings take any value.
            &[("DW_MAX_PENDING_ACTIVITIES", "10"), ("DW_COMPRESSION_ENABLED", "false")],
        ];
        for values in accepted {
            assert_eq!(refusals(values), Vec::<String>::new(), "{values:?}");
        }
        let refused: &[(&[(&str, &str)], &str)] = &[
            (&[("DW_DEFAULT_NAMESPACE", "billing")], "DW_DEFAULT_NAMESPACE must be unset or `default`"),
            (&[("DW_DEFAULT_NAMESPACE", "")], "DW_DEFAULT_NAMESPACE"),
            (&[("WORKFLOW_SERVER_DEFAULT_NAMESPACE", "billing")], "DW_DEFAULT_NAMESPACE (read from WORKFLOW_SERVER_DEFAULT_NAMESPACE)"),
            // A set primary wins over a matching legacy value, as in PHP.
            (&[("DW_DEFAULT_NAMESPACE", "billing"), ("WORKFLOW_SERVER_DEFAULT_NAMESPACE", "default")], "DW_DEFAULT_NAMESPACE must"),
            (&[("DW_WORKER_PROTOCOL_VERSION", "1.19")], "DW_WORKER_PROTOCOL_VERSION"),
            (&[("WORKFLOW_SERVER_WORKER_PROTOCOL_VERSION", "1.2")], "DW_WORKER_PROTOCOL_VERSION"),
            (&[("DW_ACTIVITY_TASK_TIMEOUT", "0")], ACTIVITY_TASK_TIMEOUT_MESSAGE),
            (&[("DW_ACTIVITY_TASK_TIMEOUT", "86401")], ACTIVITY_TASK_TIMEOUT_MESSAGE),
            (&[("DW_ACTIVITY_TASK_TIMEOUT", "030")], ACTIVITY_TASK_TIMEOUT_MESSAGE),
            (&[("DW_ACTIVITY_TASK_TIMEOUT", "true")], ACTIVITY_TASK_TIMEOUT_MESSAGE),
            (&[("DW_ACTIVITY_TASK_TIMEOUT", "")], ACTIVITY_TASK_TIMEOUT_MESSAGE),
            (&[("ACTIVITY_TASK_TIMEOUT", "5m")], ACTIVITY_TASK_TIMEOUT_MESSAGE),
            (&[("DW_TRUST_FORWARDED_ATTRIBUTION_HEADERS", "(true)")], "DW_TRUST_FORWARDED_ATTRIBUTION_HEADERS"),
            (&[("DW_TRUST_FORWARDED_ATTRIBUTION_HEADERS", " On ")], "DW_TRUST_FORWARDED_ATTRIBUTION_HEADERS"),
            (&[("DW_AUTH_BACKWARD_COMPATIBLE", "")], AUTH),
            (&[("DW_AUTH_BACKWARD_COMPATIBLE", "false")], AUTH),
            (&[("DW_PRINCIPAL_TOKENS", "[]")], AUTH),
            (&[("WORKFLOW_SERVER_AUTH_PROVIDER", "custom")], AUTH),
            (&[("DW_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_QUEUE", " 5 ")], "DW_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_QUEUE"),
            (&[("DW_TASK_QUEUE_ADMISSION_OVERRIDES", "{\"q\":{}}")], "DW_TASK_QUEUE_ADMISSION_OVERRIDES"),
            (&[("DW_SERVICE_BOUNDARY_NAMESPACE_OVERRIDES", "not json")], "DW_SERVICE_BOUNDARY_NAMESPACE_OVERRIDES"),
            (&[("DW_V2_FLEET_VALIDATION_MODE", " FAIL ")], "DW_V2_FLEET_VALIDATION_MODE"),
            (&[("DW_V2_PIN_TO_RECORDED_FINGERPRINT", "0")], "DW_V2_PIN_TO_RECORDED_FINGERPRINT"),
            (&[("DW_V1_ENABLED", "off")], "DW_V1_ENABLED"),
            (&[("DW_SERIALIZER", "json")], "DW_SERIALIZER"),
            (&[("DW_MODE", "embedded")], "DW_MODE"),
            (&[("DW_SERVER_PROCESS_CLASS", "worker_node")], "DW_SERVER_PROCESS_CLASS"),
            (&[("DW_STORAGE_CONNECTION", "workflow")], "DW_STORAGE_CONNECTION"),
            (&[("WORKFLOW_V2_LEGACY_SCHEDULE_STORAGE_TIMEZONE", "America/Chicago")], "DW_V2_LEGACY_SCHEDULE_STORAGE_TIMEZONE"),
        ];
        for (values, expected) in refused {
            let refusals = refusals(values);
            assert_eq!(refusals.len(), 1, "{values:?}: {refusals:?}");
            assert!(refusals[0].starts_with(expected) || refusals[0].ends_with(expected), "{values:?}: {}", refusals[0]);
        }
    }

    #[test]
    fn policy_maps_must_be_json_objects_like_php_705() {
        // The six maps PHP reads with `EnvAuditor::jsonObject`.
        let maps = [
            ("DW_TASK_QUEUE_ADMISSION_OVERRIDES", "WORKFLOW_SERVER_TASK_QUEUE_ADMISSION_OVERRIDES"),
            ("DW_NAMESPACE_ADMISSION_OVERRIDES", "WORKFLOW_SERVER_NAMESPACE_ADMISSION_OVERRIDES"),
            ("DW_NAMESPACE_DURABLE_LIMITS", "WORKFLOW_SERVER_NAMESPACE_DURABLE_LIMITS"),
            ("DW_NAMESPACE_DURABLE_HARD_LIMITS", "WORKFLOW_SERVER_NAMESPACE_DURABLE_HARD_LIMITS"),
            ("DW_NAMESPACE_DURABLE_OVERRIDES", "WORKFLOW_SERVER_NAMESPACE_DURABLE_OVERRIDES"),
            ("DW_EXTERNAL_PAYLOAD_NAMESPACE_OVERRIDES", "WORKFLOW_SERVER_EXTERNAL_PAYLOAD_NAMESPACE_OVERRIDES"),
        ];
        let invalid = [
            "", " ", "not json", "{", "{\"cap\":1", "null", "(null)", "empty", "true", "false", "0", "1", "[]",
            "[{\"cap\":1}]", "\"{}\"", "'{}'", "\"text\"",
        ];
        for (name, legacy) in maps {
            for value in ["{}", " \n\t{}\r\n"] {
                assert_eq!(refusals(&[(name, value)]), Vec::<String>::new(), "{name}={value:?}");
                assert_eq!(refusals(&[(legacy, value)]), Vec::<String>::new(), "{legacy}={value:?}");
            }
            for value in invalid {
                assert_eq!(refusals(&[(name, value)]), [format!("{name} must contain a valid JSON object")], "{name}={value:?}");
                assert_eq!(
                    refusals(&[(legacy, value)]),
                    [format!("{name} (read from {legacy}) must contain a valid JSON object")],
                    "{legacy}={value:?}"
                );
                // A set DW name wins even when it is invalid and the legacy
                // value is valid.
                assert_eq!(refusals(&[(name, value), (legacy, "{}")]).len(), 1, "{name}={value:?}");
            }
            // A valid DW name hides an invalid legacy value.
            assert_eq!(refusals(&[(name, "{}"), (legacy, "not json")]), Vec::<String>::new(), "{name}");
        }
        // Native honors the external payload overrides; it refuses the other
        // five unless they are empty, as before.
        let policy = "{\"billing\":{\"max_bytes\":5}}";
        for (name, legacy) in &maps[..5] {
            assert_eq!(refusals(&[(name, policy)]).len(), 1, "{name}");
            assert!(refusals(&[(legacy, policy)])[0].starts_with(&format!("{name} (read from {legacy}) must be unset or `{{}}`")));
        }
        assert_eq!(refusals(&[(maps[5].0, policy)]), Vec::<String>::new());
        assert_eq!(
            json_object(|_| Some(policy.to_owned()), maps[5].0, Some(maps[5].1)).unwrap()["billing"]["max_bytes"],
            5
        );
        // The refusal never repeats the value.
        let secret = "{\"secret-tenant-value\":";
        assert!(refusals(&[(maps[5].0, secret)]).iter().all(|refusal| !refusal.contains("secret-tenant-value")));
    }

    #[test]
    fn refusals_never_repeat_the_value() {
        let secret = "principal-secret-value";
        let report = audit(&environment(&[("DW_PRINCIPAL_TOKENS", secret), ("DW_AUTH_PROVIDER", secret)]));
        assert_eq!(report.refusals.len(), 2);
        assert!(report.refusal().is_some_and(|refusal| !refusal.contains(secret)));
    }

    #[test]
    fn the_history_transaction_limits_are_honored_settings() {
        let honored = |name: &str| SETTINGS.iter().chain(NATIVE_HONORED).find(|setting| setting.name == name)
            .is_some_and(|setting| matches!(setting.disposition, Honored));
        for name in ["DW_V2_LIMIT_HISTORY_TRANSACTION_SIZE", "DW_V2_LIMIT_HISTORY_TRANSACTION_BYTES", "DW_V2_LIMIT_HISTORY_TRANSACTION_ROWS"] {
            assert!(honored(name), "{name}");
        }
        // NATIVE_HONORED names are outside PHP's contract and listed once.
        for setting in NATIVE_HONORED {
            assert!(!contract().iter().any(|(name, ..)| name == setting.name), "{} is now in the contract", setting.name);
            assert!(!SETTINGS.iter().any(|listed| listed.name == setting.name));
        }
        // Strict mode starts with each primary name set.
        let mut primary = environment(&[("DW_V2_LIMIT_HISTORY_TRANSACTION_SIZE", "123"), ("DW_V2_LIMIT_HISTORY_TRANSACTION_BYTES", "4567"),
            ("DW_V2_LIMIT_HISTORY_TRANSACTION_ROWS", "89")]);
        primary.insert("DW_ENV_AUDIT_STRICT".into(), "1".into());
        let report = audit(&primary);
        assert_eq!((report.warnings.as_slice(), report.refusal()), (&[] as &[String], None));
        // A legacy name warns as deprecated, naming its primary, and nothing else.
        let report = audit(&environment(&[("WORKFLOW_V2_LIMIT_HISTORY_TRANSACTION_SIZE", "123"),
            ("WORKFLOW_V2_LIMIT_HISTORY_TRANSACTION_BYTES", "4567"), ("WORKFLOW_V2_LIMIT_HISTORY_TRANSACTION_ROWS", "89")]));
        assert_eq!(report.warnings, [
            "Legacy env var WORKFLOW_V2_LIMIT_HISTORY_TRANSACTION_BYTES is still honored but deprecated — rename to DW_V2_LIMIT_HISTORY_TRANSACTION_BYTES.",
            "Legacy env var WORKFLOW_V2_LIMIT_HISTORY_TRANSACTION_ROWS is still honored but deprecated — rename to DW_V2_LIMIT_HISTORY_TRANSACTION_ROWS.",
            "Legacy env var WORKFLOW_V2_LIMIT_HISTORY_TRANSACTION_SIZE is still honored but deprecated — rename to DW_V2_LIMIT_HISTORY_TRANSACTION_SIZE.",
        ]);
        assert_eq!(report.refusal(), None);
    }

    #[test]
    fn unknown_and_legacy_names_warn_and_strict_mode_refuses_them() {
        let values = [
            ("DW_AUTH_TOKN", "typo"),
            ("DW_TEST_POSTGRES_URL", "native test"),
            ("DW_RUST_UPGRADE_QUEUE_CONNECTION", "native"),
            ("WORKFLOW_SERVER_AUTH_TOKEN", "legacy"),
            ("DW_V2_LEGACY_SCHEDULE_STORAGE_TIMEZONE", ""),
        ];
        let report = audit(&environment(&values));
        assert_eq!(
            report.warnings,
            [
                "Unknown DW_ variables set in environment (typo or silent-drop rename?): DW_AUTH_TOKN, DW_V2_LEGACY_SCHEDULE_STORAGE_TIMEZONE",
                "Legacy env var WORKFLOW_SERVER_AUTH_TOKEN is still honored but deprecated — rename to DW_AUTH_TOKEN.",
            ]
        );
        assert_eq!(report.refusal(), None);
        for strict in ["1", "true"] {
            let mut environment = environment(&values);
            environment.insert("DW_ENV_AUDIT_STRICT".into(), strict.into());
            let refusal = audit(&environment).refusal();
            assert_eq!(refusal.is_some(), strict == "1", "{strict}");
        }
        let mut clean = environment(&[("DW_AUTH_TOKEN", "token")]);
        clean.insert("DW_ENV_AUDIT_STRICT".into(), "1".into());
        assert_eq!(audit(&clean).refusal(), None);
    }
}
