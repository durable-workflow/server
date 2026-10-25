//! Worker sessions, as published Server 2.11.1 serves them
//! (`WorkerSessionController` and `WorkerSessionRegistry`): a worker leases a
//! named session in its namespace, renews it with heartbeats, and closes it.
//! A session's lease and absolute TTL expire only when the session is read;
//! a holder whose registration is gone or silent leaves the session orphaned
//! on the next read. Session times are whole seconds, as PHP stores them.
//!
//! One published defect is not reproduced: PHP's `renewHeldSession` filters
//! its own `failure_reason => null` out, so a reused or reacquired session
//! keeps its old failure reason (Server #737). Native clears it, as that code
//! intends; the shared fixture bounds the difference explicitly.
use super::laravel_validation::{Rule, failure_messages, rule_errors};
use super::transaction::Transaction;
use super::{Result, RuntimeError, store::now};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Map, Value, json};
use std::collections::HashSet;
use std::hash::Hash;

const DEFAULT_LEASE_SECONDS: i64 = 120;
/// Portable session maxima, the same in PHP and native (Server #740). A lease
/// is renewed by heartbeats, so a longer one only delays holder-loss
/// detection. Thirty days of TTL keeps `ttl_expires_at` representable in a
/// MySQL `TIMESTAMP` for years, and every value fits PostgreSQL's `integer`.
pub(super) const MAX_LEASE_SECONDS: i64 = 86_400;
pub(super) const MAX_TTL_SECONDS: i64 = 2_592_000;
pub(super) const MAX_CONCURRENT_ACTIVITIES: i64 = 1_000;
const DEFAULT_TTL_SECONDS: i64 = 1800;
const DEFAULT_MAX_CONCURRENT_ACTIVITIES: i64 = 1;
const MINIMUM_PROTOCOL: (u64, u64) = (1, 8);

const SESSION_BY_ID: &str = "SELECT id,session_id,connection,queue,requirements,status,lease_owner,lease_expires_at,ttl_expires_at,closed_at,failure_reason,lease_seconds,ttl_seconds,max_concurrent_activities,CASE WHEN create_if_missing THEN 1 ELSE 0 END AS create_if_missing_flag,CASE WHEN allow_reacquire_after_failure THEN 1 ELSE 0 END AS allow_reacquire_flag,last_heartbeat_at,created_at,updated_at FROM workflow_worker_sessions WHERE namespace=$1 AND session_id=$2";
const SESSIONS_TO_REFRESH: &str = "SELECT session_id FROM workflow_worker_sessions WHERE namespace=$1 AND (status='active' OR (status<>'closed' AND ttl_expires_at<=$2 AND (failure_reason IS NULL OR failure_reason<>'ttl_expired'))) ORDER BY id";
const SESSIONS_LISTED: &str = "SELECT id,session_id,connection,queue,requirements,status,lease_owner,lease_expires_at,ttl_expires_at,closed_at,failure_reason,lease_seconds,ttl_seconds,max_concurrent_activities,CASE WHEN create_if_missing THEN 1 ELSE 0 END AS create_if_missing_flag,CASE WHEN allow_reacquire_after_failure THEN 1 ELSE 0 END AS allow_reacquire_flag,last_heartbeat_at,created_at,updated_at FROM workflow_worker_sessions WHERE namespace=$1 ORDER BY updated_at DESC,session_id ASC";
const HELD_SESSIONS: &str = "SELECT COUNT(*) FROM workflow_worker_sessions WHERE namespace=$1 AND lease_owner=$2 AND status='active' AND (lease_expires_at IS NULL OR lease_expires_at>$3) AND session_id<>$4";
const HOLDER: &str = "SELECT task_queue,status,last_heartbeat_at FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2";
/// The worker's current registration token, as the poll fence reads it.
/// The capabilities the worker's current registration declared.
const REGISTRATION_CAPABILITIES: &str = "SELECT capabilities FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2";
/// `WorkerSessionRegistry::REGISTRATION_TOKEN_CAPABILITY`: a worker that
/// declares it sends its registration token on every explicit session
/// create, heartbeat and close, and requests without it are refused.
const REGISTRATION_TOKEN_CAPABILITY: &str = "worker_session_registration_token";
const CURRENT_TOKEN: &str = "SELECT token FROM dw_worker_registration_incarnations WHERE namespace=$1 AND worker_id=$2 AND status='active' ORDER BY created_at DESC LIMIT 1";
/// The registration incarnation that acquired or last renewed a session.
const SESSION_HOLDER: &str = "SELECT incarnation FROM dw_worker_session_holders WHERE namespace=$1 AND session_id=$2";

/// A session time: PHP keeps whole seconds.
fn second(at: DateTime<Utc>) -> DateTime<Utc> {
    DateTime::from_timestamp(at.timestamp(), 0).expect("whole-second timestamp")
}

/// `at` plus a session's seconds. Validation bounds request values, so this
/// fails only for a stored value no current server accepts; that answers an
/// error instead of panicking inside a durable transition.
fn after(at: DateTime<Utc>, seconds: i64) -> Result<DateTime<Utc>> {
    chrono::Duration::try_seconds(seconds)
        .and_then(|span| at.checked_add_signed(span))
        .ok_or_else(|| RuntimeError::Protocol { status: StatusCode::UNPROCESSABLE_ENTITY,
            response: json!({"error":"Worker session duration is out of range.","reason":"invalid_worker_session"}) })
}

fn wire(at: Option<DateTime<Utc>>) -> Value {
    at.map_or(Value::Null, |at| json!(super::cooperative::timestamp(at)))
}

/// `server_capabilities.worker_sessions`: the published contract
/// (`WorkerProtocolVersion::workerSessionSemantics`) and its support.
pub(super) fn capability() -> Value {
    json!({"feature":"worker_sessions","contract_version":"1.0","minimum_protocol_version":"1.8","command_field":"worker_session",
        "activity_options_field":"worker_session","verbs":["create","heartbeat","close"],
        "lifecycle":{"creation":"lazy_create_on_first_admitted_activity_or_explicit_worker_create","renewal":"activity_heartbeat_or_explicit_session_heartbeat",
            "close":"explicit_holder_close","lease_expiry":"session_expires_when_lease_is_not_renewed",
            "ttl_expiry":"absolute_session_ttl_is_terminal_for_that_session_id"},
        "ownership":"single_worker_lease_owner",
        "lease":{"scope":"namespace_session_id","owner":"registered_worker_id","activity_attempt_leases_remain_independent":true},
        "admission":{"queue_routing_first":true,"requires_registered_worker":true,"requires_capabilities":true,"create_if_missing_default":true,
            "allow_reacquire_after_failure_default":true},
        "rollout_safety":{"minimum_protocol_version":"1.8","mixed_server_rollout_fenced_by_protocol_version":true,
            "servers_below_minimum_must_reject_worker_session_commands":true,"servers_below_minimum_must_not_claim_worker_session_activity_tasks":true},
        "limits":{"max_concurrent_worker_sessions":"worker_registration","max_concurrent_activities":"session"},
        "default_max_concurrent_activities":1,
        "renewal":{"activity_heartbeat_renews_session":true,"explicit_session_heartbeat":true},
        "failure_detection":["lease_expiry","registered_worker_heartbeat_staleness"],
        "holder_loss":{"in_flight_activities_keep_at_least_once_attempt_semantics":true,"replacement_worker_must_reacquire_session":true,
            "process_local_state_must_be_rebuilt_after_reacquire":true},
        "cancellation":{"workflow_cancellation_observed_through_activity_heartbeat":true,"session_lease_does_not_override_activity_cancel_requested":true,
            "planned_shutdown_should_close_sessions":true},
        "routing":["queue","connection","requirements"],
        "visibility":["active","closed","expired","failed","orphaned"],
        "statuses":["active","closed","expired","failed","orphaned"],
        "terminal_statuses":["closed"],
        "terminal_conditions":["explicit_close","ttl_expired","allow_reacquire_after_failure_false"],
        "authoring_guidance":["use_for_process_local_state_gpu_memory_or_filesystem_affinity","prefer_ordinary_queued_activities_for_independent_steps",
            "prefer_one_larger_activity_for_atomic_side_effects"],
        "supported":true})
}

/// `rejectWorkerSessionsUnavailable`: worker sessions need protocol 1.8.
pub(super) fn sessions_gate(protocol: &str) -> Result<()> {
    let version = protocol.split_once('.').and_then(|(major, minor)| {
        let digits = |text: &str| !text.is_empty() && text.bytes().all(|byte| byte.is_ascii_digit());
        (digits(major) && digits(minor)).then(|| Some((major.parse::<u64>().ok()?, minor.parse::<u64>().ok()?))).flatten()
    });
    if version.is_some_and(|version| version >= MINIMUM_PROTOCOL) {
        return Ok(());
    }
    Err(RuntimeError::Protocol {
        status: StatusCode::CONFLICT,
        response: json!({"error":"Worker sessions require worker protocol 1.8 or newer.","reason":"worker_sessions_unavailable",
            "supported_version":super::WORKER_PROTOCOL_VERSION,"requested_version":protocol,"minimum_protocol_version":"1.8",
            "remediation":"Send the X-Durable-Workflow-Protocol-Version header with worker protocol 1.8 or newer and use a worker SDK that implements worker-session semantics."}),
    })
}

/// The controller's rules, after Laravel's TrimStrings and
/// ConvertEmptyStringsToNull.
fn validate(body: &Value, create: bool) -> Result<Value> {
    use Rule::{Array, Boolean, Integer, Max, Min, Nullable, Required};
    let body = super::laravel_rules::normalize(body);
    let mut rules: Vec<(&str, &[Rule])> = vec![("worker_id", &[Required, Rule::String, Max(255)])];
    if create {
        rules.extend([
            ("session_id", &[Required, Rule::String, Max(255)][..]),
            ("connection", &[Nullable, Rule::String, Max(255)][..]),
            ("queue", &[Nullable, Rule::String, Max(255)][..]),
            ("requirements", &[Nullable, Array][..]),
        ]);
    }
    rules.push(("lease_seconds", &[Nullable, Integer, Min(1), Max(MAX_LEASE_SECONDS)]));
    if create {
        rules.extend([
            ("ttl_seconds", &[Nullable, Integer, Min(1), Max(MAX_TTL_SECONDS)][..]),
            ("max_concurrent_activities", &[Nullable, Integer, Min(1), Max(MAX_CONCURRENT_ACTIVITIES)][..]),
            ("create_if_missing", &[Nullable, Boolean][..]),
            ("allow_reacquire_after_failure", &[Nullable, Boolean][..]),
        ]);
    }
    rules.push(("registration_token", REGISTRATION_TOKEN_RULES));
    let mut errors = rule_errors(&body, &rules, |_, _| None);
    // `requirements.*`: each a string of at most 255 characters, reported
    // after `requirements` itself.
    if let Some(items) = body.get("requirements").and_then(Value::as_array) {
        let at = errors.iter().position(|(field, _)| field == "requirements").map_or(errors.len(), |at| at + 1);
        let item_errors: Vec<(String, Vec<String>)> = items
            .iter()
            .enumerate()
            .map(|(index, item)| {
                let field = format!("requirements.{index}");
                let mut messages = Vec::new();
                match item {
                    Value::Null => {}
                    Value::String(text) if text.chars().count() > 255 => {
                        messages.push(format!("The {field} field must not be greater than 255 characters."));
                    }
                    Value::String(_) => {}
                    _ => messages.push(format!("The {field} field must be a string.")),
                }
                (field, messages)
            })
            .collect();
        errors.splice(at..at, item_errors);
    }
    if let Some(error) = failure_messages(errors) {
        return Err(error);
    }
    Ok(body)
}

/// `positiveInt`: an integer above zero, or a string of digits. A number
/// written with a fraction, even an integral one, is not.
fn positive_int(value: &Value) -> Option<i64> {
    match value {
        Value::Number(number) => number.as_i64().filter(|value| *value > 0),
        Value::String(text) if !text.is_empty() && text.bytes().all(|byte| byte.is_ascii_digit()) => {
            text.parse::<i64>().ok().filter(|value| *value > 0)
        }
        _ => None,
    }
}

/// `boolValue`, as Laravel's `boolean` admits.
fn bool_value(value: &Value, default: bool) -> bool {
    match value {
        Value::Bool(flag) => *flag,
        Value::Number(number) => number.as_i64().map_or(default, |value| value == 1),
        Value::String(text) => match text.as_str() {
            "1" => true,
            "0" => false,
            _ => default,
        },
        _ => default,
    }
}

/// The token registration returns (`bin2hex(random_bytes(16))`).
fn registration_token_format(text: &str) -> bool {
    text.len() == 32 && text.bytes().all(|byte| matches!(byte, b'0'..=b'9' | b'a'..=b'f'))
}

/// An optional `registration_token` on create, heartbeat and close.
const REGISTRATION_TOKEN_RULES: &[Rule] = &[Rule::Nullable, Rule::String, Rule::Pattern(registration_token_format)];

/// The digest a session keeps of its holder's registration, as PHP keeps
/// `hash('sha256', token)`, so the deregistration credential itself is never
/// stored with the session.
fn incarnation_digest(token: &str) -> String {
    use sha2::{Digest, Sha256};
    Sha256::digest(token.as_bytes()).iter().map(|byte| format!("{byte:02x}")).collect()
}

/// Equal tokens, compared without an early exit (`hash_equals`).
fn same_token(left: &str, right: &str) -> bool {
    left.len() == right.len() && left.bytes().zip(right.bytes()).fold(0, |differs, (a, b)| differs | (a ^ b)) == 0
}

fn string_value(value: &Value) -> Option<String> {
    value.as_str().map(str::trim).filter(|text| !text.is_empty()).map(str::to_owned)
}

/// Each item once, in order of first occurrence (`array_unique`). The seen
/// index is a hash set, so a request-sized list stays linear even while a
/// durable transition holds the server-wide lock.
fn first_occurrences<T: Hash + Eq + Clone>(items: impl IntoIterator<Item = T>) -> Vec<T> {
    let mut seen = HashSet::new();
    items.into_iter().filter(|item| seen.insert(item.clone())).collect()
}

/// The required items missing from `available`, in required order, looked up
/// in a set built once (`array_flip`), not by scanning `available` per item.
fn missing_from<'a, T: Hash + Eq>(required: &'a [T], available: &[T]) -> Vec<&'a T> {
    let available: HashSet<&T> = available.iter().collect();
    required.iter().filter(|item| !available.contains(item)).collect()
}

/// A `requirements` list as `normalizeOptions` and the stored snapshot read
/// it: trimmed, non-empty strings, each once.
fn requirement_list(value: &Value) -> Vec<String> {
    first_occurrences(value.as_array().into_iter().flatten().filter_map(string_value))
}

/// `normalizeOptions`.
struct Options {
    session_id: String,
    connection: Option<String>,
    queue: Option<String>,
    requirements: Vec<String>,
    lease_seconds: i64,
    ttl_seconds: i64,
    max_concurrent_activities: i64,
    create_if_missing: bool,
    allow_reacquire_after_failure: bool,
}

impl Options {
    fn from(body: &Value, fallback_queue: Option<String>) -> Option<Self> {
        let requirements = requirement_list(&body["requirements"]);
        Some(Self {
            session_id: string_value(&body["session_id"])?,
            connection: string_value(&body["connection"]),
            queue: string_value(&body["queue"]).or(fallback_queue),
            requirements,
            lease_seconds: positive_int(&body["lease_seconds"]).unwrap_or(DEFAULT_LEASE_SECONDS),
            ttl_seconds: positive_int(&body["ttl_seconds"]).unwrap_or(DEFAULT_TTL_SECONDS),
            max_concurrent_activities: positive_int(&body["max_concurrent_activities"]).unwrap_or(DEFAULT_MAX_CONCURRENT_ACTIVITIES),
            create_if_missing: bool_value(&body["create_if_missing"], true),
            allow_reacquire_after_failure: bool_value(&body["allow_reacquire_after_failure"], true),
        })
    }
}

/// A `schedule_activity` command's `worker_session`, as the command
/// normalizer leaves it and `WorkerSessionOptions::toSnapshot` stores it in
/// the activity's options: omitted values stay null.
pub(super) struct CommandSession {
    pub(super) session_id: String,
    pub(super) connection: Option<String>,
    pub(super) queue: Option<String>,
    requirements: Vec<String>,
    lease_seconds: Option<i64>,
    ttl_seconds: Option<i64>,
    max_concurrent_activities: Option<i64>,
    create_if_missing: bool,
    allow_reacquire_after_failure: bool,
}

impl CommandSession {
    pub(super) fn snapshot(&self) -> Value {
        json!({"session_id":self.session_id,"connection":self.connection,"queue":self.queue,"requirements":self.requirements,
            "lease_seconds":self.lease_seconds,"ttl_seconds":self.ttl_seconds,"max_concurrent_activities":self.max_concurrent_activities,
            "create_if_missing":self.create_if_missing,"allow_reacquire_after_failure":self.allow_reacquire_after_failure})
    }

    /// The stored snapshot, from an activity's options.
    pub(super) fn from_snapshot(value: &Value) -> Option<Self> {
        let requirements = requirement_list(&value["requirements"]);
        Some(Self {
            session_id: string_value(&value["session_id"])?,
            connection: string_value(&value["connection"]),
            queue: string_value(&value["queue"]),
            requirements,
            lease_seconds: value["lease_seconds"].as_i64().filter(|value| *value > 0),
            ttl_seconds: value["ttl_seconds"].as_i64().filter(|value| *value > 0),
            max_concurrent_activities: value["max_concurrent_activities"].as_i64().filter(|value| *value > 0),
            create_if_missing: value["create_if_missing"].as_bool().unwrap_or(true),
            allow_reacquire_after_failure: value["allow_reacquire_after_failure"].as_bool().unwrap_or(true),
        })
    }

    /// `normalizeOptions` with the activity's queue and connection as the
    /// fallbacks.
    fn options(&self, queue: Option<&str>, connection: Option<&str>) -> Options {
        Options {
            session_id: self.session_id.clone(),
            connection: self.connection.clone().or_else(|| connection.map(str::to_owned)),
            queue: self.queue.clone().or_else(|| queue.map(str::to_owned)),
            requirements: self.requirements.clone(),
            lease_seconds: self.lease_seconds.unwrap_or(DEFAULT_LEASE_SECONDS),
            ttl_seconds: self.ttl_seconds.unwrap_or(DEFAULT_TTL_SECONDS),
            max_concurrent_activities: self.max_concurrent_activities.unwrap_or(DEFAULT_MAX_CONCURRENT_ACTIVITIES),
            create_if_missing: self.create_if_missing,
            allow_reacquire_after_failure: self.allow_reacquire_after_failure,
        }
    }

    /// `workerCanSatisfySession`: the claiming worker's protocol, task queue
    /// and capabilities.
    pub(super) fn claimable_by(&self, protocol: &str, worker_queue: &str, activity_queue: &str, capabilities: &[String]) -> bool {
        sessions_gate(protocol).is_ok()
            && self.queue.as_deref().unwrap_or(activity_queue) == worker_queue
            && missing_from(&self.requirements, capabilities).is_empty()
    }

    /// The lease an activity heartbeat renews the session by.
    pub(super) fn heartbeat_lease(&self) -> i64 {
        self.lease_seconds.unwrap_or(DEFAULT_LEASE_SECONDS)
    }
}

/// A validated command's `worker_session`, when it names a session.
pub(super) fn command_session(command: &Value) -> Option<CommandSession> {
    let raw = command.get("worker_session").filter(|value| value.is_object())?;
    CommandSession::from_snapshot(raw)
}

/// The command normalizer's `worker_session` checks, with its messages.
fn normalizer_errors(index: usize, command: &Value) -> Vec<(String, Vec<String>)> {
    let Some(raw) = command.get("worker_session").filter(|value| !value.is_null()) else {
        return Vec::new();
    };
    let at = |field: &str| format!("commands.{index}.worker_session{field}");
    let Some(raw) = raw.as_object() else {
        return vec![(at(""), vec!["Workflow task command field [worker_session] must be an object when provided.".into()])];
    };
    if raw.get("session_id").and_then(Value::as_str).is_none_or(|id| id.trim().is_empty()) {
        return vec![(at(".session_id"), vec!["Worker session commands require a non-empty session_id.".into()])];
    }
    let mut errors = Vec::new();
    for field in ["connection", "queue"] {
        if let Some(value) = raw.get(field).filter(|value| !value.is_null())
            && value.as_str().is_none_or(|text| text.trim().is_empty())
        {
            errors.push((at(&format!(".{field}")), vec![format!("Worker session field [{field}] must be a non-empty string when provided.")]));
        }
    }
    for field in ["lease_seconds", "ttl_seconds", "max_concurrent_activities"] {
        if let Some(value) = raw.get(field).filter(|value| !value.is_null())
            && value.as_i64().is_none_or(|value| value < 1)
        {
            errors.push((at(&format!(".{field}")), vec![format!("Worker session field [{field}] must be a positive integer when provided.")]));
        }
    }
    for field in ["create_if_missing", "allow_reacquire_after_failure"] {
        if let Some(value) = raw.get(field).filter(|value| !value.is_null())
            && !value.is_boolean()
        {
            errors.push((at(&format!(".{field}")), vec![format!("Worker session field [{field}] must be boolean when provided.")]));
        }
    }
    if let Some(requirements) = raw.get("requirements").filter(|value| !value.is_null()) {
        match requirements.as_array() {
            None => errors.push((at(".requirements"), vec!["Worker session requirements must be a list of non-empty strings.".into()])),
            Some(items) => {
                for (position, item) in items.iter().enumerate() {
                    if item.as_str().is_none_or(|text| text.trim().is_empty()) {
                        errors.push((at(&format!(".requirements.{position}")), vec!["Worker session requirements must be non-empty strings.".into()]));
                    }
                }
            }
        }
    }
    errors
}

/// A protocol version as `major.minor`.
fn version(protocol: Option<&str>) -> Option<(u64, u64)> {
    let (major, minor) = protocol?.split_once('.')?;
    let digits = |text: &str| !text.is_empty() && text.bytes().all(|byte| byte.is_ascii_digit());
    (digits(major) && digits(minor)).then(|| Some((major.parse().ok()?, minor.parse().ok()?))).flatten()
}

/// A session row.
struct Session {
    id: i64,
    session_id: String,
    connection: Option<String>,
    queue: Option<String>,
    requirements: Vec<String>,
    status: String,
    lease_owner: Option<String>,
    lease_expires_at: Option<DateTime<Utc>>,
    ttl_expires_at: Option<DateTime<Utc>>,
    closed_at: Option<DateTime<Utc>>,
    failure_reason: Option<String>,
    lease_seconds: i64,
    ttl_seconds: i64,
    max_concurrent_activities: i64,
    create_if_missing: bool,
    allow_reacquire_after_failure: bool,
    last_heartbeat_at: Option<DateTime<Utc>>,
    created_at: Option<DateTime<Utc>>,
    updated_at: Option<DateTime<Utc>>,
}

/// A registry answer: the HTTP status and PHP's body, which repeats it.
pub(crate) struct Answer {
    pub(crate) status: StatusCode,
    pub(crate) body: Value,
}

fn success(outcome: &str, session: Value, status: StatusCode) -> Answer {
    Answer { status, body: json!({"admitted":true,"outcome":outcome,"session":session,"activity_task_id":null,
        "status":status.as_u16(),"reason":null}) }
}

fn failure(reason: &str, error: &str, status: StatusCode, context: Map<String, Value>) -> Answer {
    let mut body = context;
    body.insert("admitted".into(), json!(false));
    body.insert("error".into(), json!(error));
    body.insert("reason".into(), json!(reason));
    body.insert("status".into(), json!(status.as_u16()));
    Answer { status, body: Value::Object(body) }
}

fn with_session(session: Value) -> Map<String, Value> {
    Map::from_iter([("session".to_owned(), session)])
}

/// The compact form a task or status carries (`activitySessionPayload`):
/// the normalized options with the row's lease, without null values.
fn compact(options: &Options, session: Option<&Session>) -> Value {
    let mut fields = json!({"session_id":options.session_id,"connection":options.connection,"queue":options.queue,
        "requirements":options.requirements,"lease_seconds":options.lease_seconds,"ttl_seconds":options.ttl_seconds,
        "max_concurrent_activities":options.max_concurrent_activities,"create_if_missing":options.create_if_missing,
        "allow_reacquire_after_failure":options.allow_reacquire_after_failure});
    if let Some(session) = session {
        fields["status"] = json!(session.status);
        fields["lease_owner"] = json!(session.lease_owner);
        fields["lease_expires_at"] = wire(session.lease_expires_at);
        fields["ttl_expires_at"] = wire(session.ttl_expires_at);
    }
    if let Some(map) = fields.as_object_mut() {
        map.retain(|_, value| !value.is_null());
    }
    fields
}

store_methods! {
    /// `POST /api/worker/sessions`.
    pub(crate) async fn create_worker_session(&self, body: Value, protocol: &str) -> Result<Answer> {
        sessions_gate(protocol)?;
        let body = validate(&body, true)?;
        let worker_id = body["worker_id"].as_str().unwrap_or_default().to_owned();
        let Some(worker) = Self::query("SELECT task_queue,capabilities,max_concurrent_worker_sessions FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
            .bind(self.namespace.as_ref()).bind(&worker_id).fetch_optional(&self.pool).await?
        else {
            return Err(RuntimeError::Protocol { status: StatusCode::PRECONDITION_FAILED,
                response: json!({"error":"Worker must be registered before creating a worker session.","reason":"worker_not_registered","worker_id":worker_id}) });
        };
        let task_queue = DB::string(&worker, "task_queue")?;
        if let Some(queue) = string_value(&body["queue"]).filter(|queue| *queue != task_queue) {
            return Err(RuntimeError::Protocol { status: StatusCode::CONFLICT,
                response: json!({"error":format!("Worker [{worker_id}] is registered for task queue [{task_queue}], not session queue [{queue}]."),
                    "reason":"session_queue_mismatch","worker_id":worker_id,"registered_task_queue":task_queue,"requested_session_queue":queue}) });
        }
        let Some(options) = Options::from(&body, Some(task_queue.clone())) else {
            return Err(RuntimeError::Protocol { status: StatusCode::UNPROCESSABLE_ENTITY,
                response: json!({"error":"Worker session requires a non-empty session_id.","reason":"invalid_worker_session"}) });
        };
        let capabilities: Vec<String> = DB::optional_document_row(&worker, "capabilities")?
            .as_ref()
            .and_then(Value::as_array)
            .into_iter()
            .flatten()
            .filter_map(|value| value.as_str().map(str::to_owned))
            .collect();
        let limit = DB::optional_number(&worker, "max_concurrent_worker_sessions")?.unwrap_or(10);
        let mut tx = self.begin().await?;
        let answer = match self.stale_registration(&mut tx, &worker_id, body["registration_token"].as_str()).await? {
            Some(stale) => stale,
            None => self.acquire(&mut tx, &worker_id, &task_queue, &capabilities, limit, &options, None).await?,
        };
        tx.commit().await?;
        Ok(answer)
    }

    /// `acquireLocked`.
    async fn acquire(
        &self,
        tx: &mut Transaction<'_, DB>,
        worker_id: &str,
        task_queue: &str,
        capabilities: &[String],
        limit: i64,
        options: &Options,
        activity_task: Option<&str>,
    ) -> Result<Answer> {
        if options.queue.as_deref().is_some_and(|queue| queue != task_queue) {
            return Ok(failure("session_queue_mismatch", "Worker session queue does not match the worker's task queue.",
                StatusCode::CONFLICT, Map::new()));
        }
        // Requirements are checked before the session is looked up.
        let missing = missing_from(&options.requirements, capabilities);
        if !missing.is_empty() {
            return Ok(failure("session_requirements_not_met", "Worker does not advertise every worker-session requirement.",
                StatusCode::CONFLICT, Map::from_iter([("missing_requirements".to_owned(), json!(missing))])));
        }
        let Some(mut session) = self.locked_session(tx, &options.session_id).await? else {
            if !options.create_if_missing {
                return Ok(failure("session_missing", "Worker session does not exist and creation is disabled.",
                    StatusCode::CONFLICT, Map::new()));
            }
            if let Some(refused) = self.session_limit(tx, worker_id, limit, &options.session_id).await? {
                return Ok(refused);
            }
            let at = second(now());
            Self::query("INSERT INTO workflow_worker_sessions(namespace,session_id,connection,queue,requirements,status,lease_owner,lease_expires_at,ttl_expires_at,lease_seconds,ttl_seconds,max_concurrent_activities,create_if_missing,allow_reacquire_after_failure,last_heartbeat_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,'active',$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16)")
                .bind(self.namespace.as_ref()).bind(&options.session_id).bind(options.connection.as_deref()).bind(options.queue.as_deref())
                .bind(DB::document(&json!(options.requirements))).bind(worker_id)
                .bind(DB::bind_time(after(at, options.lease_seconds)?))
                .bind(DB::bind_time(after(at, options.ttl_seconds)?))
                .bind(options.lease_seconds).bind(options.ttl_seconds).bind(options.max_concurrent_activities)
                .bind(DB::optional_flag(Some(options.create_if_missing))).bind(DB::optional_flag(Some(options.allow_reacquire_after_failure)))
                .bind(DB::bind_time(at)).bind(DB::bind_time(at)).bind(DB::bind_time(at))
                .execute(&mut **tx).await?;
            self.record_holder(tx, &options.session_id, worker_id).await?;
            let created = self.locked_session(tx, &options.session_id).await?.expect("the inserted session");
            return Ok(success("created", self.snapshot(tx, &created).await?, StatusCode::CREATED));
        };
        if session.status == "active" {
            if session.lease_owner.as_deref() != Some(worker_id) {
                let snapshot = self.snapshot(tx, &session).await?;
                return Ok(failure("session_owned_by_another_worker", "Worker session is currently leased by another worker.",
                    StatusCode::CONFLICT, with_session(snapshot)));
            }
            // An activity claim also respects the session's activity limit.
            if let Some(task) = activity_task {
                let running = self.running_session_activities(tx, &session, task).await?;
                if running >= session.max_concurrent_activities.max(1) {
                    let snapshot = self.snapshot(tx, &session).await?;
                    return Ok(failure("session_activity_limit_exceeded", "Worker session has reached max_concurrent_activities.",
                        StatusCode::CONFLICT, with_session(snapshot)));
                }
            }
            self.renew(tx, &mut session, options, worker_id).await?;
            return Ok(success("reused", self.snapshot(tx, &session).await?, StatusCode::OK));
        }
        if session.status == "closed" {
            let snapshot = self.snapshot(tx, &session).await?;
            return Ok(failure("session_closed", "Worker session was closed and cannot be reacquired.",
                StatusCode::CONFLICT, with_session(snapshot)));
        }
        if session.failure_reason.as_deref() == Some("ttl_expired") || !session.allow_reacquire_after_failure {
            let snapshot = self.snapshot(tx, &session).await?;
            return Ok(failure("session_reacquire_disallowed", "Worker session is terminal and cannot be reacquired.",
                StatusCode::CONFLICT, with_session(snapshot)));
        }
        if !options.allow_reacquire_after_failure {
            let snapshot = self.snapshot(tx, &session).await?;
            return Ok(failure("session_reacquire_disallowed", "Worker session options disallow reacquisition after holder failure.",
                StatusCode::CONFLICT, with_session(snapshot)));
        }
        if let Some(refused) = self.session_limit(tx, worker_id, limit, &options.session_id).await? {
            return Ok(refused);
        }
        self.renew(tx, &mut session, options, worker_id).await?;
        Ok(success("reacquired", self.snapshot(tx, &session).await?, StatusCode::OK))
    }

    /// The worker session limit, not counting this session.
    async fn session_limit(&self, tx: &mut Transaction<'_, DB>, worker_id: &str, limit: i64, session_id: &str) -> Result<Option<Answer>> {
        let held = Self::scalar(HELD_SESSIONS).bind(self.namespace.as_ref()).bind(worker_id).bind(DB::bind_time(now())).bind(session_id)
            .fetch_one(&mut **tx).await?;
        Ok((held >= limit).then(|| failure("worker_session_limit_exceeded", "Worker has reached max_concurrent_worker_sessions.",
            StatusCode::CONFLICT, Map::from_iter([("max_concurrent_worker_sessions".to_owned(), json!(limit))]))))
    }

    /// `renewHeldSession`. Options the request leaves out reset to their
    /// defaults; the TTL deadline is never extended; an omitted connection
    /// keeps the stored one. Unlike published PHP, the failure reason is
    /// cleared (Server #737).
    async fn renew(&self, tx: &mut Transaction<'_, DB>, session: &mut Session, options: &Options, worker_id: &str) -> Result<()> {
        let at = second(now());
        if options.connection.is_some() {
            session.connection.clone_from(&options.connection);
        }
        session.queue.clone_from(&options.queue);
        session.requirements.clone_from(&options.requirements);
        session.status = "active".into();
        session.lease_owner = Some(worker_id.to_owned());
        session.lease_expires_at = Some(after(at, options.lease_seconds)?);
        session.closed_at = None;
        session.failure_reason = None;
        session.lease_seconds = options.lease_seconds;
        session.ttl_seconds = options.ttl_seconds;
        session.max_concurrent_activities = options.max_concurrent_activities;
        session.create_if_missing = options.create_if_missing;
        session.allow_reacquire_after_failure = options.allow_reacquire_after_failure;
        session.last_heartbeat_at = Some(at);
        self.save(tx, session).await?;
        // The renewing registration now holds the session.
        self.record_holder(tx, &session.session_id, worker_id).await
    }

    /// `activeActivityCount`: the owner's running, unexpired attempts of
    /// activities bound to this session, other than one task.
    async fn running_session_activities(&self, tx: &mut Transaction<'_, DB>, session: &Session, except_task: &str) -> Result<i64> {
        let Some(owner) = session.lease_owner.as_deref() else {
            return Ok(0);
        };
        Ok(Self::scalar(DB::SESSION_RUNNING_ATTEMPTS).bind(self.namespace.as_ref()).bind(DB::bind_time(now())).bind(owner)
            .bind(session.session_id.as_str()).bind(except_task).fetch_one(&mut **tx).await?)
    }

    /// `guardWorkerSessionCommandsAvailable`, then
    /// `guardPortableWorkerAffinityCompletion`, then the command normalizer's
    /// `worker_session` checks, in PHP's order.
    pub(super) async fn guard_worker_session_commands(
        &self,
        tx: &mut Transaction<'_, DB>,
        task: &DB::Row,
        task_id: &str,
        body: &Value,
        protocol: Option<&str>,
        commands: &[Value],
    ) -> Result<()> {
        if !commands.iter().any(|command| command.get("worker_session").is_some_and(|value| !value.is_null())) {
            return Ok(());
        }
        let attempt = body["workflow_task_attempt"].clone();
        let version = version(protocol);
        if version.is_none_or(|version| version < MINIMUM_PROTOCOL) {
            return Err(RuntimeError::Protocol { status: StatusCode::CONFLICT,
                response: json!({"task_id":task_id,"workflow_task_attempt":attempt,"outcome":"rejected","recorded":false,
                    "reason":"worker_sessions_unavailable","error":"Worker-session activity commands require worker protocol 1.8 or newer.",
                    "requested_version":protocol,"minimum_protocol_version":"1.8",
                    "remediation":"Complete worker-session workflow tasks through a server node advertising worker protocol 1.8 or newer."}) });
        }
        let rejection = |reason: &str| RuntimeError::Protocol { status: StatusCode::CONFLICT,
            response: json!({"task_id":task_id,"workflow_task_attempt":attempt,"outcome":"rejected","recorded":false,"reason":reason,
                "capability":"worker_sessions","requested_version":protocol,"minimum_protocol_version":"1.18",
                "remediation":"Use cold durable replay unless the registered worker manifest explicitly supports the required capability and exact cache identity."}) };
        if version.is_none_or(|version| version < (1, 18)) {
            return Err(rejection("portable_worker_affinity_protocol_floor_unavailable"));
        }
        let owner = DB::optional_string(task, "lease_owner")?.unwrap_or_default();
        let worker = Self::query("SELECT capabilities,capability_manifest FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
            .bind(self.namespace.as_ref()).bind(&owner).fetch_optional(&mut **tx).await?;
        let capable = match &worker {
            Some(row) => {
                let flat = DB::optional_document_row(row, "capabilities")?
                    .and_then(|capabilities| capabilities.as_array().map(|items| items.iter().any(|item| item == "worker_sessions")))
                    .unwrap_or(false);
                let manifest = DB::optional_document_row(row, "capability_manifest")?
                    .is_some_and(|manifest| manifest["worker_sessions"]["supported"] == json!(true));
                flat && manifest
            }
            None => false,
        };
        if !capable {
            return Err(rejection("worker_capability_not_supported"));
        }
        let errors: Vec<_> = commands.iter().enumerate().flat_map(|(index, command)| normalizer_errors(index, command)).collect();
        failure_messages(errors).map_or(Ok(()), Err)
    }

    /// `admitActivity` inside a claim: acquire or reuse the activity's
    /// session for the claiming worker, within its activity limit. Answers
    /// the compact form, or `None` when the session refuses the claim.
    pub(super) async fn admit_session_activity(
        &self,
        tx: &mut Transaction<'_, DB>,
        worker: &DB::Row,
        session: &CommandSession,
        activity_queue: &str,
        activity_connection: Option<&str>,
        task_id: &str,
    ) -> Result<Option<Value>> {
        let worker_id = DB::string(worker, "worker_id")?;
        let task_queue = DB::string(worker, "task_queue")?;
        let capabilities: Vec<String> = DB::optional_document_row(worker, "capabilities")?
            .as_ref()
            .and_then(Value::as_array)
            .into_iter()
            .flatten()
            .filter_map(|value| value.as_str().map(str::to_owned))
            .collect();
        let limit = DB::optional_number(worker, "max_concurrent_worker_sessions")?.unwrap_or(10);
        let options = session.options(Some(activity_queue), activity_connection);
        let answer = self.acquire(tx, &worker_id, &task_queue, &capabilities, limit, &options, Some(task_id)).await?;
        if answer.body["admitted"] != json!(true) {
            return Ok(None);
        }
        let row = self.locked_session(tx, &options.session_id).await?;
        Ok(Some(compact(&options, row.as_ref())))
    }

    /// `heartbeatForAttempt`: an activity heartbeat or lease renewal renews
    /// its session by the command's lease. Answers the full snapshot, or the
    /// refusal's status, its snapshot (if any) and the renewal's failure.
    pub(super) async fn renew_session_for_attempt(
        &self,
        tx: &mut Transaction<'_, DB>,
        session: &CommandSession,
        lease_owner: &str,
    ) -> Result<std::result::Result<Value, (StatusCode, Value, Value)>> {
        let Some(mut row) = self.locked_session(tx, &session.session_id).await? else {
            return Ok(Err((StatusCode::NOT_FOUND, Value::Null,
                json!({"admitted":false,"reason":"session_not_found","error":"Worker session was not found."}))));
        };
        if row.status != "active" {
            let snapshot = self.snapshot(tx, &row).await?;
            return Ok(Err((StatusCode::CONFLICT, snapshot,
                json!({"admitted":false,"reason":"session_not_active","error":"Worker session is not active."}))));
        }
        if row.lease_owner.as_deref() != Some(lease_owner) {
            let snapshot = self.snapshot(tx, &row).await?;
            return Ok(Err((StatusCode::CONFLICT, snapshot,
                json!({"admitted":false,"reason":"session_owner_mismatch","error":"Worker session lease is owned by another worker."}))));
        }
        let at = second(now());
        row.lease_expires_at = Some(after(at, session.heartbeat_lease().max(1))?);
        row.last_heartbeat_at = Some(at);
        row.failure_reason = None;
        self.save(tx, &row).await?;
        Ok(Ok(self.snapshot(tx, &row).await?))
    }

    /// The compact form an attempt's status carries, read without a
    /// refresh, and whether the session still lets the attempt continue.
    pub(super) async fn session_for_status(
        &self,
        tx: &mut Transaction<'_, DB>,
        session: &CommandSession,
        activity_queue: &str,
        activity_connection: Option<&str>,
        lease_owner: &str,
    ) -> Result<(Value, bool)> {
        let row = Self::query(SESSION_BY_ID).bind(self.namespace.as_ref()).bind(session.session_id.as_str()).fetch_optional(&mut **tx).await?;
        let row = row.as_ref().map(Self::session).transpose()?;
        let at = now();
        let usable = row.as_ref().is_some_and(|row| row.status == "active" && row.lease_owner.as_deref() == Some(lease_owner)
            && row.lease_expires_at.is_some_and(|lease| lease > at) && row.ttl_expires_at.is_some_and(|ttl| ttl > at));
        Ok((compact(&session.options(Some(activity_queue), activity_connection), row.as_ref()), usable))
    }

    /// `metrics`: the namespace's sessions by status, after a refresh.
    pub(crate) async fn worker_session_metrics(&self) -> Result<Value> {
        self.refresh_namespace_sessions().await?;
        let rows = Self::query("SELECT status FROM workflow_worker_sessions WHERE namespace=$1").bind(self.namespace.as_ref()).fetch_all(&self.pool).await?;
        let mut metrics = json!({"total":0,"active":0,"closed":0,"expired":0,"failed":0,"orphaned":0});
        for row in rows {
            let status = DB::string(&row, "status")?;
            metrics["total"] = json!(metrics["total"].as_i64().unwrap_or(0) + 1);
            if let Some(count) = metrics.get_mut(status.as_str()) {
                *count = json!(count.as_i64().unwrap_or(0) + 1);
            }
        }
        Ok(metrics)
    }

    /// `POST /api/worker/sessions/{sessionId}/heartbeat`.
    pub(crate) async fn heartbeat_worker_session(&self, session_id: &str, body: Value, protocol: &str) -> Result<Answer> {
        sessions_gate(protocol)?;
        let body = validate(&body, false)?;
        let worker_id = body["worker_id"].as_str().unwrap_or_default();
        let mut tx = self.begin().await?;
        if let Some(stale) = self.stale_registration(&mut tx, worker_id, body["registration_token"].as_str()).await? {
            tx.commit().await?;
            return Ok(stale);
        }
        let Some(mut session) = self.locked_session(&mut tx, session_id).await? else {
            tx.commit().await?;
            return Ok(failure("session_not_found", "Worker session was not found.", StatusCode::NOT_FOUND, Map::new()));
        };
        let answer = if session.status != "active" {
            failure("session_not_active", "Worker session is not active.", StatusCode::CONFLICT, with_session(self.snapshot(&mut tx, &session).await?))
        } else if session.lease_owner.as_deref() != Some(worker_id) {
            failure("session_owner_mismatch", "Worker session lease is owned by another worker.", StatusCode::CONFLICT,
                with_session(self.snapshot(&mut tx, &session).await?))
        } else {
            let at = second(now());
            let lease = super::laravel_validation::integer(&body["lease_seconds"]).unwrap_or(session.lease_seconds).max(1);
            session.lease_expires_at = Some(after(at, lease)?);
            session.last_heartbeat_at = Some(at);
            session.failure_reason = None;
            self.save(&mut tx, &session).await?;
            success("heartbeat_recorded", self.snapshot(&mut tx, &session).await?, StatusCode::OK)
        };
        tx.commit().await?;
        Ok(answer)
    }

    /// `DELETE /api/worker/sessions/{sessionId}`.
    pub(crate) async fn close_worker_session(&self, session_id: &str, body: Value, protocol: &str) -> Result<Answer> {
        sessions_gate(protocol)?;
        let body = validate(&body, false)?;
        let worker_id = body["worker_id"].as_str().unwrap_or_default();
        let mut tx = self.begin().await?;
        if let Some(stale) = self.stale_registration(&mut tx, worker_id, body["registration_token"].as_str()).await? {
            tx.commit().await?;
            return Ok(stale);
        }
        let Some(mut session) = self.locked_session(&mut tx, session_id).await? else {
            tx.commit().await?;
            return Ok(failure("session_not_found", "Worker session was not found.", StatusCode::NOT_FOUND, Map::new()));
        };
        let owner = session.lease_owner.as_deref() == Some(worker_id);
        let answer = if session.status == "closed" && owner {
            success("already_closed", self.snapshot(&mut tx, &session).await?, StatusCode::OK)
        } else if !owner {
            failure("session_owner_mismatch", "Worker session lease is owned by another worker.", StatusCode::CONFLICT,
                with_session(self.snapshot(&mut tx, &session).await?))
        } else {
            session.status = "closed".into();
            session.closed_at = Some(second(now()));
            session.failure_reason = None;
            self.save(&mut tx, &session).await?;
            success("closed", self.snapshot(&mut tx, &session).await?, StatusCode::OK)
        };
        tx.commit().await?;
        Ok(answer)
    }

    /// `GET /api/worker-sessions`: refreshes the namespace's sessions, then
    /// lists them, newest change first, with the status counts.
    pub(crate) async fn list_worker_sessions(&self) -> Result<Value> {
        self.refresh_namespace_sessions().await?;
        let mut tx = self.begin().await?;
        let rows = Self::query(SESSIONS_LISTED).bind(self.namespace.as_ref()).fetch_all(&mut *tx).await?;
        let mut sessions = Vec::new();
        let mut metrics = json!({"total":0,"active":0,"closed":0,"expired":0,"failed":0,"orphaned":0});
        for row in &rows {
            let session = Self::session(row)?;
            metrics["total"] = json!(metrics["total"].as_i64().unwrap_or(0) + 1);
            if let Some(count) = metrics.get_mut(session.status.as_str()) {
                *count = json!(count.as_i64().unwrap_or(0) + 1);
            }
            sessions.push(self.snapshot(&mut tx, &session).await?);
        }
        tx.commit().await?;
        Ok(json!({"namespace":self.namespace.as_ref(),"metrics":metrics,"sessions":sessions}))
    }

    /// `GET /api/worker-sessions/{sessionId}`.
    pub(crate) async fn show_worker_session(&self, session_id: &str) -> Result<Value> {
        self.refresh_namespace_sessions().await?;
        let mut tx = self.begin().await?;
        let row = Self::query(SESSION_BY_ID).bind(self.namespace.as_ref()).bind(session_id).fetch_optional(&mut *tx).await?;
        let Some(row) = row else {
            tx.commit().await?;
            return Err(RuntimeError::Protocol { status: StatusCode::NOT_FOUND,
                response: json!({"message":format!("Worker session [{session_id}] not found in namespace [{}].", self.namespace),
                    "reason":"worker_session_not_found"}) });
        };
        let session = Self::session(&row)?;
        let snapshot = self.snapshot(&mut tx, &session).await?;
        tx.commit().await?;
        Ok(json!({"namespace":self.namespace.as_ref(),"session":snapshot}))
    }

    /// `refreshNamespace`: each session that may have expired, in its own
    /// transaction.
    async fn refresh_namespace_sessions(&self) -> Result<()> {
        let ids = Self::query(SESSIONS_TO_REFRESH).bind(self.namespace.as_ref()).bind(DB::bind_time(now())).fetch_all(&self.pool).await?;
        for row in ids {
            let mut tx = self.begin().await?;
            self.locked_session(&mut tx, &DB::string(&row, "session_id")?).await?;
            tx.commit().await?;
        }
        Ok(())
    }

    /// The session, refreshed (`refreshStatusLocked`).
    async fn locked_session(&self, tx: &mut Transaction<'_, DB>, session_id: &str) -> Result<Option<Session>> {
        let Some(row) = Self::query(SESSION_BY_ID).bind(self.namespace.as_ref()).bind(session_id).fetch_optional(&mut **tx).await? else {
            return Ok(None);
        };
        let mut session = Self::session(&row)?;
        if session.status == "closed" || session.failure_reason.as_deref() == Some("ttl_expired") {
            return Ok(Some(session));
        }
        let at = now();
        let change = if session.ttl_expires_at.is_some_and(|ttl| ttl <= at) {
            Some(("expired", "ttl_expired"))
        } else if session.status != "active" {
            None
        } else if session.lease_expires_at.is_some_and(|lease| lease <= at) {
            Some(("expired", "lease_expired"))
        } else {
            self.holder_loss(tx, &session).await?.map(|reason| ("orphaned", reason))
        };
        if let Some((status, reason)) = change {
            session.status = status.into();
            session.failure_reason = Some(reason.into());
            self.save(tx, &session).await?;
        }
        Ok(Some(session))
    }

    /// Why an active session's holder no longer holds it: its registration
    /// is gone or silent, or the worker ID registered again since it acquired
    /// or renewed the session. A replacement process must reacquire it and
    /// rebuild its process-local state, so it never inherits it as reused.
    async fn holder_loss(&self, tx: &mut Transaction<'_, DB>, session: &Session) -> Result<Option<&'static str>> {
        if self.holder_stale(tx, session.lease_owner.as_deref()).await? {
            return Ok(Some("worker_heartbeat_stale"));
        }
        let current = self.current_incarnation(tx, session.lease_owner.as_deref().unwrap_or_default()).await?;
        let holder = Self::query(SESSION_HOLDER).bind(self.namespace.as_ref()).bind(&session.session_id)
            .fetch_optional(&mut **tx).await?
            .map(|row| DB::string(&row, "incarnation"))
            .transpose()?;
        Ok((current != holder).then_some("worker_registration_replaced"))
    }

    async fn current_token(&self, tx: &mut Transaction<'_, DB>, worker_id: &str) -> Result<Option<String>> {
        Self::query(CURRENT_TOKEN).bind(self.namespace.as_ref()).bind(worker_id).fetch_optional(&mut **tx).await?
            .map(|row| DB::string(&row, "token"))
            .transpose()
    }

    async fn current_incarnation(&self, tx: &mut Transaction<'_, DB>, worker_id: &str) -> Result<Option<String>> {
        Ok(self.current_token(tx, worker_id).await?.as_deref().map(incarnation_digest))
    }

    /// Records the worker's current registration as the session's holder. A
    /// registration without a token, such as one kept from a PHP takeover,
    /// records none.
    async fn record_holder(&self, tx: &mut Transaction<'_, DB>, session_id: &str, worker_id: &str) -> Result<()> {
        Self::query("DELETE FROM dw_worker_session_holders WHERE namespace=$1 AND session_id=$2")
            .bind(self.namespace.as_ref()).bind(session_id).execute(&mut **tx).await?;
        if let Some(incarnation) = self.current_incarnation(tx, worker_id).await? {
            Self::query("INSERT INTO dw_worker_session_holders(namespace,session_id,incarnation,updated_at) VALUES ($1,$2,$3,$4)")
                .bind(self.namespace.as_ref()).bind(session_id).bind(incarnation).bind(DB::bind_time(now()))
                .execute(&mut **tx).await?;
        }
        Ok(())
    }

    /// A registration token the request sent must be the worker's current
    /// one. Without a token, a stale process and its same-ID replacement are
    /// indistinguishable once the replacement has reacquired, so a worker
    /// whose current registration declares [`REGISTRATION_TOKEN_CAPABILITY`]
    /// must send it on every explicit create, heartbeat and close. Without
    /// the capability, a request without a token acts for the worker ID's
    /// current registration, as published PHP's does.
    async fn stale_registration(&self, tx: &mut Transaction<'_, DB>, worker_id: &str, token: Option<&str>) -> Result<Option<Answer>> {
        let Some(token) = token else {
            let declared = Self::query(REGISTRATION_CAPABILITIES)
                .bind(self.namespace.as_ref()).bind(worker_id).fetch_optional(&mut **tx).await?
                .map(|row| DB::optional_document_row(&row, "capabilities")).transpose()?.flatten()
                .is_some_and(|flags| flags.as_array().is_some_and(|flags| flags.iter().any(|flag| flag == REGISTRATION_TOKEN_CAPABILITY)));
            return Ok(declared.then(|| failure("stale_worker_registration",
                "The worker's current registration requires its registration token on worker session requests. Send it, or register again and reacquire the session.",
                StatusCode::CONFLICT, Map::new())));
        };
        let current = self.current_token(tx, worker_id).await?;
        Ok((!current.is_some_and(|current| same_token(&current, token))).then(|| failure("stale_worker_registration",
            "The registration token is not the worker's current registration. Register again and reacquire the session.",
            StatusCode::CONFLICT, Map::new())))
    }

    /// `holderIsStale`: no registration, no heartbeat, or a heartbeat older
    /// than the stale window.
    async fn holder_stale(&self, tx: &mut Transaction<'_, DB>, owner: Option<&str>) -> Result<bool> {
        let Some(owner) = owner else {
            return Ok(true);
        };
        let row = Self::query(HOLDER).bind(self.namespace.as_ref()).bind(owner).fetch_optional(&mut **tx).await?;
        let heartbeat = match row {
            Some(row) => DB::optional_instant(&row, "last_heartbeat_at")?,
            None => None,
        };
        Ok(heartbeat.is_none_or(|heartbeat| heartbeat < now() - chrono::Duration::seconds(self.worker_stale_after_seconds.max(1))))
    }

    async fn save(&self, tx: &mut Transaction<'_, DB>, session: &Session) -> Result<()> {
        Self::query("UPDATE workflow_worker_sessions SET connection=$1,queue=$2,requirements=$3,status=$4,lease_owner=$5,lease_expires_at=$6,ttl_expires_at=$7,closed_at=$8,failure_reason=$9,lease_seconds=$10,ttl_seconds=$11,max_concurrent_activities=$12,create_if_missing=$13,allow_reacquire_after_failure=$14,last_heartbeat_at=$15,updated_at=$16 WHERE id=$17")
            .bind(session.connection.as_deref()).bind(session.queue.as_deref()).bind(DB::document(&json!(session.requirements)))
            .bind(session.status.as_str()).bind(session.lease_owner.as_deref())
            .bind(DB::optional_time(session.lease_expires_at)).bind(DB::optional_time(session.ttl_expires_at)).bind(DB::optional_time(session.closed_at))
            .bind(session.failure_reason.as_deref()).bind(session.lease_seconds).bind(session.ttl_seconds).bind(session.max_concurrent_activities)
            .bind(DB::optional_flag(Some(session.create_if_missing))).bind(DB::optional_flag(Some(session.allow_reacquire_after_failure)))
            .bind(DB::optional_time(session.last_heartbeat_at)).bind(DB::bind_time(second(now()))).bind(session.id)
            .execute(&mut **tx).await?;
        Ok(())
    }

    fn session(row: &DB::Row) -> Result<Session> {
        let requirements = DB::optional_document_row(row, "requirements")?
            .as_ref()
            .and_then(Value::as_array)
            .into_iter()
            .flatten()
            .filter_map(|value| value.as_str().map(str::to_owned))
            .collect();
        Ok(Session {
            id: DB::number(row, "id")?,
            session_id: DB::string(row, "session_id")?,
            connection: DB::optional_string(row, "connection")?,
            queue: DB::optional_string(row, "queue")?,
            requirements,
            status: DB::string(row, "status")?,
            lease_owner: DB::optional_string(row, "lease_owner")?,
            lease_expires_at: DB::optional_instant(row, "lease_expires_at")?,
            ttl_expires_at: DB::optional_instant(row, "ttl_expires_at")?,
            closed_at: DB::optional_instant(row, "closed_at")?,
            failure_reason: DB::optional_string(row, "failure_reason")?,
            lease_seconds: DB::number(row, "lease_seconds")?,
            ttl_seconds: DB::number(row, "ttl_seconds")?,
            max_concurrent_activities: DB::number(row, "max_concurrent_activities")?,
            create_if_missing: DB::number(row, "create_if_missing_flag")? == 1,
            allow_reacquire_after_failure: DB::number(row, "allow_reacquire_flag")? == 1,
            last_heartbeat_at: DB::optional_instant(row, "last_heartbeat_at")?,
            created_at: DB::optional_instant(row, "created_at")?,
            updated_at: DB::optional_instant(row, "updated_at")?,
        })
    }

    /// PHP's session snapshot, with its holder read from the registration.
    async fn snapshot(&self, tx: &mut Transaction<'_, DB>, session: &Session) -> Result<Value> {
        let holder = match session.lease_owner.as_deref() {
            None => Value::Null,
            Some(owner) => {
                let row = Self::query(HOLDER).bind(self.namespace.as_ref()).bind(owner).fetch_optional(&mut **tx).await?;
                let (task_queue, status, heartbeat) = match &row {
                    Some(row) => (DB::optional_string(row, "task_queue")?, DB::optional_string(row, "status")?, DB::optional_instant(row, "last_heartbeat_at")?),
                    None => (None, None, None),
                };
                let stale = heartbeat.is_none_or(|heartbeat| heartbeat < now() - chrono::Duration::seconds(self.worker_stale_after_seconds.max(1)));
                json!({"worker_id":owner,"task_queue":task_queue,"status":status,"last_heartbeat_at":wire(heartbeat),"is_stale":stale})
            }
        };
        Ok(json!({"session_id":session.session_id,"namespace":self.namespace.as_ref(),"connection":session.connection,"queue":session.queue,
            "requirements":session.requirements,"status":session.status,"lease_owner":session.lease_owner,"holder":holder,
            "lease_expires_at":wire(session.lease_expires_at),"ttl_expires_at":wire(session.ttl_expires_at),"closed_at":wire(session.closed_at),
            "failure_reason":session.failure_reason,"lease_seconds":session.lease_seconds,"ttl_seconds":session.ttl_seconds,
            "max_concurrent_activities":session.max_concurrent_activities,"create_if_missing":session.create_if_missing,
            "allow_reacquire_after_failure":session.allow_reacquire_after_failure,"active_activity_count":self.running_session_activities(tx, session, "").await?,
            "last_heartbeat_at":wire(session.last_heartbeat_at),"created_at":wire(session.created_at),"updated_at":wire(session.updated_at)}))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn sessions_need_protocol_one_eight() {
        assert!(sessions_gate("1.8").is_ok());
        assert!(sessions_gate("1.20").is_ok());
        assert!(sessions_gate("1.10").is_ok());
        assert!(sessions_gate("1.7").is_err());
        assert!(sessions_gate("1.x").is_err());
        assert!(sessions_gate("+1.9").is_err());
    }

    #[test]
    fn options_read_like_php() {
        assert_eq!(positive_int(&json!("60")), Some(60));
        assert_eq!(positive_int(&json!(7)), Some(7));
        assert_eq!(positive_int(&serde_json::from_str::<Value>("7.0").unwrap()), None);
        assert_eq!(positive_int(&json!("0")), None);
        let options = Options::from(&json!({"session_id":"s","requirements":["gpu",null,"gpu","tpu"]}), Some("q".into())).unwrap();
        assert_eq!(options.requirements, ["gpu", "tpu"]);
        assert_eq!((options.queue.as_deref(), options.lease_seconds, options.ttl_seconds), (Some("q"), 120, 1800));
    }

    /// A string whose equality checks are counted, so the tests below fail
    /// on a quadratic scan rather than on a timing threshold.
    #[derive(Clone)]
    struct Counted(String);

    // Hash and equality both read only the string, so equal values hash alike.
    impl std::hash::Hash for Counted {
        fn hash<H: std::hash::Hasher>(&self, state: &mut H) {
            self.0.hash(state);
        }
    }

    thread_local! {
        static COMPARISONS: std::cell::Cell<usize> = const { std::cell::Cell::new(0) };
    }

    impl Counted {
        fn eq_count() -> usize {
            COMPARISONS.with(std::cell::Cell::get)
        }
    }

    impl PartialEq for Counted {
        fn eq(&self, other: &Self) -> bool {
            COMPARISONS.with(|count| count.set(count.get() + 1));
            self.0 == other.0
        }
    }

    impl Eq for Counted {}

    /// Distinct near-limit strings with a shared 248-character prefix, as a
    /// 2 MiB request can carry about 8,000 of them.
    fn near_limit(count: usize) -> Vec<Counted> {
        let prefix = "r".repeat(248);
        (0..count).map(|index| Counted(format!("{prefix}{index:07}"))).collect()
    }

    #[test]
    fn requirement_lists_stay_linear_at_the_request_limit() {
        let distinct = near_limit(8_000);
        let mut doubled = distinct.clone();
        doubled.extend(distinct.iter().rev().cloned());
        let before = Counted::eq_count();
        let unique = first_occurrences(doubled);
        assert_eq!(unique.len(), 8_000);
        assert!(unique.iter().zip(&distinct).all(|(left, right)| left.0 == right.0), "first occurrence order");
        let missing = missing_from(&unique, &distinct);
        assert!(missing.is_empty());
        let mut partial = distinct.clone();
        partial.truncate(4_000);
        assert_eq!(missing_from(&unique, &partial).len(), 4_000);
        // A Vec scan would compare about n^2/2 = 32 million times.
        assert!(Counted::eq_count() - before < 64_000, "{} comparisons", Counted::eq_count() - before);
    }

    #[test]
    fn near_limit_requirements_normalize_in_order() {
        let items: Vec<Value> = near_limit(8_000).into_iter().map(|item| json!(item.0)).collect();
        let mut body = json!({"session_id":"s"});
        body["requirements"] = Value::Array(items.iter().chain(items.iter()).cloned().collect());
        let options = Options::from(&body, None).unwrap();
        assert_eq!(options.requirements.len(), 8_000);
        assert_eq!(options.requirements.first().map(String::as_str), items[0].as_str());
        let stored = CommandSession::from_snapshot(&json!({"session_id":"s","requirements":body["requirements"]})).unwrap();
        assert_eq!(stored.requirements, options.requirements);
        let capabilities = options.requirements.clone();
        assert!(stored.claimable_by("1.20", "q", "q", &capabilities));
        assert!(!stored.claimable_by("1.20", "q", "q", &capabilities[1..]));
    }
}
