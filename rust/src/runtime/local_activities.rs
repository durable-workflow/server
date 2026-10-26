//! `record_local_activity`, the workflow task command for a local activity
//! the worker already ran (worker protocol 1.18 and newer), as published
//! Server 2.11.1 serves it with Workflow 2.10.2: the advertised contract
//! (`LocalActivityContract::manifest`), the portable worker affinity guard
//! (`WorkerController::guardPortableWorkerAffinityCompletion`), the command
//! normalizer's rules (`WorkflowCommandNormalizer::normalize`) and the
//! bridge's recording (`DefaultWorkflowTaskBridge::applyRecordLocalActivity`).
//!
//! The normalizer sees each command as the worker sent it. Published PHP
//! hands it Laravel's `validated()` commands instead, which rebuild a nested
//! list in rule order: a report whose first attempt lacks `attempt_id`, or
//! whose first heartbeat lacks `details`, while a later entry has it reaches
//! the normalizer reordered and is refused as unordered. Native does not
//! reproduce that defect; the record-local-activity fixture bounds it.
use super::laravel_validation::failure_messages;
use super::store::{id, now};
use super::transaction::Transaction;
use super::{Result, RuntimeError, refuse};
use axum::http::StatusCode;
use chrono::{DateTime, Duration, SecondsFormat, Utc};
use serde_json::{Map, Value, json};
use std::collections::{BTreeMap, HashSet};
use std::sync::Arc;
use tokio::sync::Semaphore;

pub(super) const COMMAND: &str = "record_local_activity";
/// `WorkerProtocolVersion::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION`.
pub(super) const MINIMUM_PROTOCOL_VERSION: &str = "1.18";
/// `LOCAL_ACTIVITY_ATTEMPT_REPORTS_MINIMUM_PROTOCOL_VERSION`.
const ATTEMPT_REPORTS_PROTOCOL_VERSION: &str = "1.19";
const MAX_ATTEMPTS: i64 = 100;
const MAX_HEARTBEATS_PER_ATTEMPT: usize = 1000;
const MAX_HEARTBEATS: usize = 1000;
/// `WorkflowCommandNormalizer::MAX_LOCAL_ACTIVITY_DURATION_MS` (Workflow
/// #930): the longest attempt `duration_ms` or heartbeat `elapsed_ms`.
pub(super) const MAX_DURATION_MS: i64 = 604_800_000;
/// `MAX_LOCAL_ACTIVITY_BACKOFF_SECONDS`: the longest attempt backoff.
pub(super) const MAX_BACKOFF_SECONDS: i64 = 86_400;
const OUTCOMES: [&str; 4] = ["completed", "failed", "timed_out", "cancelled"];
const TIMEOUT_KINDS: [&str; 3] = ["start_to_close", "schedule_to_close", "heartbeat"];
const RETRY_POLICY_FIELDS: [&str; 3] = ["max_attempts", "backoff_seconds", "non_retryable_error_types"];
const ATTEMPT_FIELDS: [&str; 11] = ["attempt_id", "attempt_number", "outcome", "duration_ms", "message",
    "exception_type", "non_retryable", "timeout_kind", "retry_reason", "backoff_seconds", "heartbeats"];
const HEARTBEAT_FIELDS: [&str; 2] = ["elapsed_ms", "details"];
/// `PARALLEL_METADATA_FIELDS` and the group path, which belong only to the
/// scheduling commands.
const PARALLEL_FIELDS: [&str; 12] = ["parallel_group_id", "parallel_group_kind", "parallel_group_mode",
    "parallel_group_base_sequence", "parallel_group_size", "parallel_group_index", "selection_member_key",
    "selection_member_index", "selection_member_base_sequence", "selection_member_size", "selection_member_kind",
    "parallel_group_path"];
const TIMEOUT_EXCEPTION: &str = "Workflow\\V2\\Exceptions\\ActivityTimeoutException";

/// `server_capabilities.local_activities`: `LocalActivityContract::manifest`
/// with `supported` from the configured protocol, which native fixes at 1.20.
pub(super) fn manifest() -> Value {
    json!({
        "schema": "durable-workflow.v2.local-activity.contract",
        "version": 1,
        "supported": true,
        "worker_capability": "local_activities",
        "minimum_worker_protocol_version": MINIMUM_PROTOCOL_VERSION,
        "command_type": COMMAND,
        "command": {
            "type": COMMAND,
            "minimum_protocol_version": MINIMUM_PROTOCOL_VERSION,
            "required_fields": ["activity_type", "outcome", "attempts"],
            "attempt_reports": {
                "field": "attempts",
                "shape": "ordered_attempt_reports",
                "required_fields": ["attempt_number", "outcome"],
                "maximum": MAX_ATTEMPTS,
                "required_from_protocol_version": ATTEMPT_REPORTS_PROTOCOL_VERSION,
                "worker_attempt_identity": {"report_field": "attempt_id", "persistence_field": "worker_attempt_id",
                    "history_field": "worker_attempt_id", "durable_field": "activity_attempt_id"}
            },
            "heartbeat_reports": {
                "field": "heartbeats",
                "shape": "ordered_heartbeat_reports",
                "required_fields": ["elapsed_ms"],
                "maximum_per_attempt": MAX_HEARTBEATS_PER_ATTEMPT,
                "maximum_per_command": MAX_HEARTBEATS,
                "elapsed_ms_origin": "attempt_started_at",
                "persisted_last_heartbeat_at": "attempt_started_at_plus_last_elapsed_ms"
            },
            "rolling_upgrade": {
                "retained_protocol_version": MINIMUM_PROTOCOL_VERSION,
                "missing_attempts": "normalize_to_one_terminal_attempt_without_worker_identity_or_heartbeats",
                "strict_shape_selected_by": "negotiated_worker_protocol_version",
                "retained_nested_object_unknown_fields": "ignore",
                "strict_nested_object_shape_from_protocol_version": ATTEMPT_REPORTS_PROTOCOL_VERSION
            }
        },
        "api": {
            "functions": ["Workflow\\V2\\localActivity"],
            "workflow_facade": ["Workflow\\V2\\Workflow::localActivity", "Workflow\\V2\\Workflow::executeLocalActivity"],
            "options": "Workflow\\V2\\Support\\LocalActivityOptions"
        },
        "execution": {
            "mode": "local",
            "same_process": true,
            "ordinary_activity_task_created": false,
            "history_events": ["ActivityScheduled", "ActivityStarted", "ActivityHeartbeatRecorded", "ActivityRetryScheduled",
                "ActivityCompleted", "ActivityFailed", "ActivityTimedOut", "ActivityCancelled"],
            "history_marker": {"execution_mode": "local", "local_activity": true}
        },
        "heartbeating": {
            "lease": "workflow_task",
            "automatic_points": ["before_attempt_start", "after_activity_heartbeat"],
            "long_running_requirement": "activity_code_calls_heartbeat_before_the_workflow_task_lease_can_expire"
        },
        "cancellation": {
            "preemption": "cooperative",
            "observed_at": ["heartbeat", "timeout_enforcement", "attempt_completion"],
            "terminal_event": "ActivityCancelled"
        },
        "shutdown": {
            "graceful": "worker_stops_claiming_new_workflow_tasks_and_allows_in_process_attempt_to_finish_or_heartbeat",
            "crash": "workflow_task_lease_expires_and_task_repair_reclaims_the_workflow_task",
            "cold_replay": "committed_terminal_event_is_replayed_or_started_attempt_without_terminal_event_schedules_local_retry"
        },
        "routing": {
            "admission": "activity_class_must_resolve_in_the_workflow_worker_process",
            "queue_bypassed": true,
            "rejected_options": ["connection", "queue", "worker_session", "schedule_to_start_timeout"]
        },
        "retry": {
            "new_attempt_when": ["a local attempt starts", "a retry workflow task replays after backoff",
                "cold replay finds a previously started local attempt without a terminal event"],
            "backoff": "durable_workflow_task_available_at",
            "cold_replay_reason": "cold_replay"
        },
        "timeouts": {
            "start_to_close_timeout": "one_local_attempt",
            "schedule_to_close_timeout": "entire_local_execution_including_retries",
            "heartbeat_timeout": "gap_between_recorded_local_activity_heartbeats",
            "enforcement": "cooperative_at_heartbeat_attempt_start_and_attempt_completion_boundaries"
        },
        "visibility": {
            "activity_execution_marker": "activity_options.execution_mode",
            "history_marker": "payload.execution_mode",
            "metrics_marker": "activities.local_*",
            "history_export": "same_activity_event_payloads_with_execution_mode_local"
        }
    })
}

/// `WorkerProtocolVersion::supportsFeatureVersion`: the same major version
/// and at least the minimum minor version.
fn supports(candidate: &str, minimum: &str) -> bool {
    let parts = |version: &str| {
        let (major, minor) = version.split_once('.')?;
        let digits = |part: &str| (!part.is_empty() && part.bytes().all(|byte| byte.is_ascii_digit())).then(|| part.parse::<u64>().ok()).flatten();
        Some((digits(major)?, digits(minor)?))
    };
    matches!((parts(candidate), parts(minimum)), (Some((major, minor)), Some((least_major, least_minor)))
        if major == least_major && minor >= least_minor)
}

/// `version_compare($requested, $minimum, '<')` for the guard's numeric
/// `major.minor` versions; anything else is below the floor.
fn below(requested: Option<&str>, minimum: &str) -> bool {
    let parse = |version: &str| -> Option<Vec<u64>> { version.split('.').map(|part| part.parse().ok()).collect() };
    match (requested.and_then(parse), parse(minimum)) {
        (Some(requested), Some(minimum)) => requested < minimum,
        _ => true,
    }
}

/// PHP's `trim`.
fn trim(text: &str) -> &str {
    text.trim_matches([' ', '\t', '\n', '\r', '\0', '\x0B'])
}

/// A JSON object key PHP's decoder turns into an integer array key.
fn integer_key(key: &str) -> bool {
    let digits = key.strip_prefix('-').unwrap_or(key);
    !digits.is_empty() && digits.bytes().all(|byte| byte.is_ascii_digit())
        && (digits == "0" || !digits.starts_with('0')) && key != "-0" && key.parse::<i64>().is_ok()
}

/// PHP's `is_array($value) && array_is_list($value)` for a decoded JSON
/// value: an array, or an empty object.
fn list(value: &Value) -> bool {
    match value {
        Value::Array(_) => true,
        Value::Object(fields) => fields.is_empty(),
        _ => false,
    }
}

/// PHP's `is_array($value) && ! array_is_list($value)`.
fn object(value: &Value) -> Option<&Map<String, Value>> {
    value.as_object().filter(|fields| !fields.is_empty())
}

/// PHP's `array_values`: an object's values or an array's elements.
fn values(value: &Value) -> Vec<&Value> {
    match value {
        Value::Array(items) => items.iter().collect(),
        Value::Object(fields) => fields.values().collect(),
        _ => Vec::new(),
    }
}

/// A decoded PHP array encoded again: an empty object becomes `[]`.
fn php_json(value: &Value) -> Value {
    match value {
        Value::Object(fields) if fields.is_empty() => json!([]),
        Value::Object(fields) => Value::Object(fields.iter().map(|(key, value)| (key.clone(), php_json(value))).collect()),
        Value::Array(items) => Value::Array(items.iter().map(php_json).collect()),
        other => other.clone(),
    }
}

/// The normalizer's error map: a later message for a field replaces the
/// earlier one in place, as PHP's `$errors[$field] = [...]` does.
#[derive(Default)]
struct Errors(Vec<(String, Vec<String>)>);

impl Errors {
    fn set(&mut self, field: impl Into<String>, message: impl Into<String>) {
        let (field, messages) = (field.into(), vec![message.into()]);
        match self.0.iter_mut().find(|(seen, _)| *seen == field) {
            Some((_, current)) => *current = messages,
            None => self.0.push((field, messages)),
        }
    }
}

fn present(command: &Value, field: &str) -> bool {
    command.get(field).is_some_and(|value| !value.is_null())
}

/// `optionalCommandString`.
fn command_string(command: &Value, field: &str, index: usize, errors: &mut Errors) -> Option<String> {
    let value = command.get(field).filter(|value| !value.is_null())?;
    match value.as_str().map(trim).filter(|text| !text.is_empty()) {
        Some(text) => Some(text.to_owned()),
        None => {
            errors.set(format!("commands.{index}.{field}"),
                format!("Workflow task command field [{field}] must be a non-empty string when provided."));
            None
        }
    }
}

/// `optionalNestedString`.
fn nested_string(value: &Value, field: &str, path: &str, errors: &mut Errors) -> Option<String> {
    let value = value.get(field).filter(|value| !value.is_null())?;
    match value.as_str().map(trim).filter(|text| !text.is_empty()) {
        Some(text) => Some(text.to_owned()),
        None => {
            errors.set(format!("{path}.{field}"), format!("Local activity attempt field [{field}] must be a non-empty string."));
            None
        }
    }
}

/// `optionalPositiveInt`.
fn positive(command: &Value, field: &str, index: usize, errors: &mut Errors) -> Option<i64> {
    let value = command.get(field).filter(|value| !value.is_null())?;
    match value.as_i64().filter(|seconds| *seconds >= 1) {
        Some(seconds) => Some(seconds),
        None => {
            errors.set(format!("commands.{index}.{field}"),
                format!("Workflow task command field [{field}] must be a positive integer when provided."));
            None
        }
    }
}

/// `optionalRetryPolicy` for a local activity.
fn retry_policy(command: &Value, index: usize, errors: &mut Errors) -> Option<Map<String, Value>> {
    let raw = command.get("retry_policy").filter(|value| !value.is_null())?;
    let path = format!("commands.{index}.retry_policy");
    let Some(raw) = raw.as_object() else {
        if !raw.is_array() {
            errors.set(path, "Workflow task command field [retry_policy] must be an object when provided.");
        }
        return None;
    };
    let mut policy = Map::new();
    if let Some(value) = raw.get("max_attempts") {
        match value.as_i64().filter(|attempts| *attempts >= 1) {
            Some(attempts) => {
                policy.insert("max_attempts".into(), json!(attempts));
            }
            None => errors.set(format!("{path}.max_attempts"),
                "Local activity retry policy max_attempts must be a positive integer when provided."),
        }
    }
    if let Some(value) = raw.get("backoff_seconds") {
        if value.is_array() || value.is_object() {
            let mut backoff = Vec::new();
            for (position, entry) in values(value).into_iter().enumerate() {
                match entry.as_i64().filter(|seconds| *seconds >= 0) {
                    Some(seconds) => backoff.push(json!(seconds)),
                    None => errors.set(format!("{path}.backoff_seconds.{position}"),
                        "Local activity retry policy backoff_seconds entries must be non-negative integers."),
                }
            }
            policy.insert("backoff_seconds".into(), Value::Array(backoff));
        } else {
            errors.set(format!("{path}.backoff_seconds"),
                "Local activity retry policy backoff_seconds must be a list of non-negative integers.");
        }
    }
    if let Some(value) = raw.get("non_retryable_error_types") {
        if value.is_array() || value.is_object() {
            let mut types: Vec<String> = Vec::new();
            for (position, entry) in values(value).into_iter().enumerate() {
                match entry.as_str().map(trim).filter(|text| !text.is_empty()) {
                    Some(text) => {
                        if !types.iter().any(|seen| seen == text) {
                            types.push(text.to_owned());
                        }
                    }
                    None => errors.set(format!("{path}.non_retryable_error_types.{position}"),
                        "Local activity retry policy non_retryable_error_types entries must be non-empty strings."),
                }
            }
            policy.insert("non_retryable_error_types".into(), json!(types));
        } else {
            errors.set(format!("{path}.non_retryable_error_types"),
                "Local activity retry policy non_retryable_error_types must be a list of strings.");
        }
    }
    (!policy.is_empty()).then_some(policy)
}

/// `rejectUnknownNestedFields`: a decoded integer key is never refused.
fn unknown_fields(value: &Map<String, Value>, allowed: &[&str], path: &str, subject: &str, errors: &mut Errors) {
    for field in value.keys().filter(|field| !integer_key(field) && !allowed.contains(&field.as_str())) {
        errors.set(format!("{path}.{field}"), format!("Field [{field}] is not supported in a {subject} report."));
    }
}

/// `validateLocalActivityRetryPolicyShape`.
fn retry_policy_shape(command: &Value, strict: bool, index: usize, errors: &mut Errors) {
    let Some(raw) = command.get("retry_policy").and_then(Value::as_object) else {
        return;
    };
    let path = format!("commands.{index}.retry_policy");
    if strict {
        unknown_fields(raw, &RETRY_POLICY_FIELDS, &path, "local activity retry policy", errors);
    }
    for field in ["backoff_seconds", "non_retryable_error_types"] {
        if raw.get(field).and_then(object).is_some() {
            errors.set(format!("{path}.{field}"), format!("Local activity retry policy {field} must be an ordered list."));
        }
    }
    if let Some(types) = raw.get("non_retryable_error_types").filter(|types| list(types)) {
        let types = values(types);
        let mut unique: Vec<&Value> = Vec::new();
        for entry in &types {
            if !unique.contains(entry) {
                unique.push(entry);
            }
        }
        if unique.len() != types.len() {
            errors.set(format!("{path}.non_retryable_error_types"),
                "Local activity retry policy non_retryable_error_types must not contain duplicates.");
        }
    }
}

/// `normalizeLocalActivityHeartbeats`.
fn heartbeats(value: Option<&Value>, attempt_path: &str, strict: bool, errors: &mut Errors) -> Vec<Value> {
    let Some(value) = value.filter(|value| !value.is_null()) else {
        return Vec::new();
    };
    if !list(value) {
        errors.set(format!("{attempt_path}.heartbeats"), "Local activity attempt heartbeats must be an ordered list.");
        return Vec::new();
    }
    let entries = values(value);
    if entries.len() > MAX_HEARTBEATS_PER_ATTEMPT {
        errors.set(format!("{attempt_path}.heartbeats"),
            format!("A local activity attempt may contain at most {MAX_HEARTBEATS_PER_ATTEMPT} heartbeats."));
    }
    let mut normalized = Vec::new();
    let mut last = -1;
    for (position, heartbeat) in entries.into_iter().enumerate() {
        let path = format!("{attempt_path}.heartbeats.{position}");
        let Some(fields) = object(heartbeat) else {
            errors.set(path, "Each local activity heartbeat must be an object.");
            continue;
        };
        if strict {
            unknown_fields(fields, &HEARTBEAT_FIELDS, &path, "local activity heartbeat", errors);
        }
        let Some(elapsed) = fields.get("elapsed_ms").and_then(Value::as_i64).filter(|elapsed| *elapsed >= 0) else {
            errors.set(format!("{path}.elapsed_ms"), "Local activity heartbeat elapsed_ms must be a non-negative integer.");
            continue;
        };
        if elapsed > MAX_DURATION_MS {
            errors.set(format!("{path}.elapsed_ms"), format!("Local activity heartbeat elapsed_ms must not exceed {MAX_DURATION_MS}."));
            continue;
        }
        if elapsed < last {
            errors.set(format!("{path}.elapsed_ms"), "Local activity heartbeat elapsed_ms values must be ordered monotonically.");
        }
        last = elapsed;
        let mut entry = json!({"elapsed_ms": elapsed});
        if let Some(details) = fields.get("details").filter(|details| !details.is_null()) {
            if object(details).is_some() || list(details) && values(details).is_empty() {
                entry["details"] = php_json(details);
            } else {
                errors.set(format!("{path}.details"), "Local activity heartbeat details must be an object when provided.");
            }
        }
        normalized.push(entry);
    }
    normalized
}

/// `normalizeLocalActivityAttempts`.
fn attempts(command: &Value, outcome: &str, terminal_non_retryable: Option<bool>, policy: Option<&Map<String, Value>>,
    protocol: &str, index: usize, errors: &mut Errors) -> Option<Vec<Value>> {
    let synthesized;
    let mut raw = command.get("attempts");
    // Protocol 1.18 workers may omit the list: one terminal attempt.
    if raw.is_none() && supports(protocol, MINIMUM_PROTOCOL_VERSION) && !supports(protocol, ATTEMPT_REPORTS_PROTOCOL_VERSION) {
        let mut attempt = json!({"attempt_number": 1, "outcome": outcome});
        if let Some(message) = command.get("message").and_then(Value::as_str) {
            attempt["message"] = json!(trim(message));
        }
        if let Some(exception) = nested_string(command, "exception_type", &format!("commands.{index}"), errors) {
            attempt["exception_type"] = json!(exception);
        }
        if let Some(non_retryable) = terminal_non_retryable {
            attempt["non_retryable"] = json!(non_retryable);
        }
        if let Some(kind) = command.get("timeout_kind").and_then(Value::as_str) {
            attempt["timeout_kind"] = json!(trim(kind));
        }
        attempt["heartbeats"] = json!([]);
        synthesized = json!([attempt]);
        raw = Some(&synthesized);
    }
    let Some(raw) = raw.filter(|raw| list(raw) && !values(raw).is_empty()) else {
        errors.set(format!("commands.{index}.attempts"), "Local activity attempts must be a non-empty ordered list.");
        return None;
    };
    let raw = values(raw);
    let count = raw.len() as i64;
    let strict = supports(protocol, ATTEMPT_REPORTS_PROTOCOL_VERSION);
    let max_attempts = policy.and_then(|policy| policy.get("max_attempts")).and_then(Value::as_i64).unwrap_or(1);
    retry_policy_shape(command, strict, index, errors);
    if max_attempts > MAX_ATTEMPTS {
        errors.set(format!("commands.{index}.retry_policy.max_attempts"),
            format!("Local activity retry policy max_attempts must not exceed {MAX_ATTEMPTS}."));
    }
    if count > MAX_ATTEMPTS {
        errors.set(format!("commands.{index}.attempts"), format!("Local activity reports may contain at most {MAX_ATTEMPTS} attempts."));
    } else if count > max_attempts {
        errors.set(format!("commands.{index}.attempts"),
            format!("Local activity report contains {count} attempts but retry_policy.max_attempts is {max_attempts}."));
    }
    let schedule: Vec<i64> = policy.and_then(|policy| policy.get("backoff_seconds")).and_then(Value::as_array)
        .map(|entries| entries.iter().filter_map(Value::as_i64).collect()).unwrap_or_default();
    if schedule.len() as i64 > (max_attempts - 1).max(0) {
        errors.set(format!("commands.{index}.retry_policy.backoff_seconds"),
            "Local activity retry backoff entries must not exceed the number of possible retries.");
    }
    let mut normalized = Vec::new();
    let mut identities = HashSet::new();
    let mut heartbeat_count = 0;
    let last = raw.len() - 1;
    for (position, attempt) in raw.into_iter().enumerate() {
        let path = format!("commands.{index}.attempts.{position}");
        let Some(fields) = object(attempt) else {
            errors.set(path, "Each local activity attempt must be an object.");
            continue;
        };
        if strict {
            unknown_fields(fields, &ATTEMPT_FIELDS, &path, "local activity attempt", errors);
        }
        let expected = position as i64 + 1;
        let number = fields.get("attempt_number").and_then(Value::as_i64);
        if number != Some(expected) {
            errors.set(format!("{path}.attempt_number"),
                format!("Local activity attempt_number must be the ordered one-based value {expected}."));
        }
        let Some(attempt_outcome) = fields.get("outcome").and_then(Value::as_str).filter(|value| OUTCOMES.contains(value)) else {
            errors.set(format!("{path}.outcome"), "Local activity attempt outcome must be completed, failed, timed_out, or cancelled.");
            continue;
        };
        let terminal = position == last;
        if terminal && attempt_outcome != outcome {
            errors.set(format!("{path}.outcome"), "The final local activity attempt outcome must match the command outcome.");
        }
        if !terminal && !matches!(attempt_outcome, "failed" | "timed_out") {
            errors.set(format!("{path}.outcome"), "Only failed or timed_out local activity attempts may be followed by a retry.");
        }
        let identity = nested_string(attempt, "attempt_id", &path, errors);
        match &identity {
            Some(identity) if identity.len() > 255 => errors.set(format!("{path}.attempt_id"), "Local activity attempt_id must not exceed 255 bytes."),
            Some(identity) if identities.contains(identity) => errors.set(format!("{path}.attempt_id"),
                "Local activity attempt_id must be unique within the reported sequence."),
            Some(identity) => {
                identities.insert(identity.clone());
            }
            None => {}
        }
        let duration = match fields.get("duration_ms") {
            None => None,
            Some(value) => match value.as_i64().filter(|duration| *duration >= 0) {
                Some(duration) if duration > MAX_DURATION_MS => {
                    errors.set(format!("{path}.duration_ms"), format!("Local activity attempt duration_ms must not exceed {MAX_DURATION_MS}."));
                    None
                }
                Some(duration) => Some(duration),
                None => {
                    errors.set(format!("{path}.duration_ms"), "Local activity attempt duration_ms must be a non-negative integer.");
                    None
                }
            },
        };
        let message = nested_string(attempt, "message", &path, errors);
        let exception_type = nested_string(attempt, "exception_type", &path, errors);
        if attempt_outcome != "completed" && message.is_none() {
            errors.set(format!("{path}.message"), "Failed, timed out, and cancelled local activity attempts require a message.");
        }
        let non_retryable = match fields.get("non_retryable") {
            None => None,
            Some(Value::Bool(value)) => {
                if !terminal && *value {
                    errors.set(format!("{path}.non_retryable"), "A non-retryable local activity attempt cannot be followed by a retry.");
                }
                Some(*value)
            }
            Some(_) => {
                errors.set(format!("{path}.non_retryable"), "Local activity attempt non_retryable must be boolean when provided.");
                None
            }
        };
        if terminal && non_retryable.is_some_and(|value| value != terminal_non_retryable.unwrap_or(false)) {
            errors.set(format!("{path}.non_retryable"),
                "The terminal local activity attempt non_retryable value must match the command outcome.");
        }
        let timeout_kind = nested_string(attempt, "timeout_kind", &path, errors);
        if attempt_outcome == "timed_out" && !timeout_kind.as_deref().is_some_and(|kind| TIMEOUT_KINDS.contains(&kind)) {
            errors.set(format!("{path}.timeout_kind"),
                "Timed out local activity attempts require start_to_close, schedule_to_close, or heartbeat timeout_kind.");
        }
        if attempt_outcome != "timed_out" && timeout_kind.is_some() {
            errors.set(format!("{path}.timeout_kind"), "Only timed out local activity attempts may declare timeout_kind.");
        }
        let retry_reason = nested_string(attempt, "retry_reason", &path, errors);
        let backoff = match fields.get("backoff_seconds") {
            None => None,
            Some(value) => match value.as_i64().filter(|seconds| *seconds >= 0) {
                Some(seconds) if seconds > MAX_BACKOFF_SECONDS => {
                    errors.set(format!("{path}.backoff_seconds"),
                        format!("Local activity attempt backoff_seconds must not exceed {MAX_BACKOFF_SECONDS}."));
                    None
                }
                Some(seconds) => Some(seconds),
                None => {
                    errors.set(format!("{path}.backoff_seconds"), "Local activity attempt backoff_seconds must be a non-negative integer.");
                    None
                }
            },
        };
        if !terminal {
            let allowed: [&str; 2] = if attempt_outcome == "timed_out" { ["timeout", "cold_replay"] } else { ["failure", "cold_replay"] };
            if !retry_reason.as_deref().is_some_and(|reason| allowed.contains(&reason)) {
                errors.set(format!("{path}.retry_reason"),
                    format!("A retried {attempt_outcome} local activity attempt requires a compatible retry_reason."));
            }
            // A given value keeps its own refusal (Workflow #930).
            match backoff {
                None if !fields.contains_key("backoff_seconds") => {
                    errors.set(format!("{path}.backoff_seconds"), "A retried local activity attempt requires backoff_seconds.");
                }
                None => {}
                Some(seconds) if schedule.get(position).is_some_and(|scheduled| *scheduled != seconds) => errors.set(
                    format!("{path}.backoff_seconds"), "Local activity attempt backoff_seconds must match retry_policy.backoff_seconds."),
                Some(_) => {}
            }
        } else if retry_reason.is_some() || backoff.is_some() {
            errors.set(path.clone(), "The terminal local activity attempt cannot declare retry_reason or backoff_seconds.");
        }
        let beats = heartbeats(fields.get("heartbeats"), &path, strict, errors);
        heartbeat_count += beats.len();
        let mut entry = Map::new();
        let mut put = |key: &str, value: Option<Value>| {
            if let Some(value) = value {
                entry.insert(key.to_owned(), value);
            }
        };
        put("attempt_id", identity.map(Value::from));
        put("attempt_number", Some(json!(number.unwrap_or(expected))));
        put("outcome", Some(json!(attempt_outcome)));
        put("duration_ms", duration.map(Value::from));
        put("message", message.map(Value::from));
        put("exception_type", exception_type.map(Value::from));
        put("non_retryable", non_retryable.map(Value::from));
        put("timeout_kind", timeout_kind.map(Value::from));
        put("retry_reason", retry_reason.map(Value::from));
        put("backoff_seconds", backoff.map(Value::from));
        put("heartbeats", Some(Value::Array(beats)));
        normalized.push(Value::Object(entry));
    }
    if heartbeat_count > MAX_HEARTBEATS {
        errors.set(format!("commands.{index}.attempts"),
            format!("Local activity reports may contain at most {MAX_HEARTBEATS} total heartbeats."));
    }
    Some(normalized)
}

/// A payload field after `PayloadEnvelopeResolver`: its blob and codec.
/// External references are resolved before the normalizer in PHP; native
/// does not resolve them for local activities yet and refuses them.
fn payload(command: &Value, field: &str, index: usize, errors: &mut Errors) -> Result<(Option<String>, Option<String>)> {
    let not_payload = |errors: &mut Errors| errors.set(format!("commands.{index}.{field}"),
        format!("Workflow task command field [{field}] must be a string or a payload envelope when provided."));
    match command.get(field) {
        None | Some(Value::Null) => Ok((None, None)),
        // Arguments that are an empty string carry no payload.
        Some(Value::String(blob)) => Ok(((field == "result" || !blob.is_empty()).then(|| blob.clone()), None)),
        Some(value @ (Value::Object(_) | Value::Array(_))) => {
            let envelope = value.as_object().filter(|fields| {
                let mut keys: Vec<&str> = fields.keys().map(String::as_str).collect();
                keys.sort_unstable();
                fields.contains_key("codec") && (keys == ["blob", "codec"] || keys == ["codec", "external_storage"])
            });
            let Some(envelope) = envelope else {
                // A plain value is no payload for arguments and refused as a result.
                if field == "result" {
                    not_payload(errors);
                }
                return Ok((None, None));
            };
            let path = format!("commands.{index}.{field}");
            if !envelope["codec"].as_str().is_some_and(|codec| !codec.is_empty()) {
                errors.set(format!("{path}.codec"), "The payload envelope codec must be a non-empty string.");
                return Ok((None, None));
            }
            if let Some(message) = super::input_envelope::codec_refusal(&envelope["codec"]) {
                errors.set(format!("{path}.codec"), message);
                return Ok((None, None));
            }
            if envelope.contains_key("external_storage") {
                return Err(refuse(StatusCode::UNPROCESSABLE_ENTITY, "local_activity_external_payload_unsupported"));
            }
            let Some(blob) = envelope["blob"].as_str() else {
                errors.set(format!("{path}.blob"), "The payload envelope blob must be a string.");
                return Ok((None, None));
            };
            Ok(((field == "result" || !blob.is_empty()).then(|| blob.to_owned()), Some("avro".to_owned())))
        }
        Some(_) => {
            not_payload(errors);
            Ok((None, None))
        }
    }
}

/// `payloadCodecForResolvedPayload`.
fn payload_codec(command: &Value, resolved: Option<&str>, field: &str, index: usize, errors: &mut Errors) -> Option<String> {
    let explicit = match command.get("payload_codec").filter(|codec| !codec.is_null()) {
        None => None,
        Some(codec) => match codec.as_str().map(trim).filter(|codec| !codec.is_empty()) {
            None => {
                errors.set(format!("commands.{index}.payload_codec"),
                    "Workflow task command field [payload_codec] must be a non-empty string when provided.");
                None
            }
            Some(codec) => match super::input_envelope::codec_refusal(&json!(codec)) {
                Some(message) => {
                    errors.set(format!("commands.{index}.payload_codec"), message);
                    None
                }
                None => Some("avro".to_owned()),
            },
        },
    };
    if let (Some(explicit), Some(resolved)) = (&explicit, resolved)
        && explicit != resolved
    {
        errors.set(format!("commands.{index}.payload_codec"),
            format!("Workflow task command field [payload_codec] must match the {field} envelope codec."));
    }
    explicit.or_else(|| resolved.map(str::to_owned))
}

/// One record after the normalizer, as the bridge applies it.
#[derive(Debug)]
pub(super) struct Record {
    activity_type: String,
    arguments: Option<String>,
    result: Option<String>,
    payload_codec: Option<String>,
    outcome: String,
    message: Option<String>,
    exception_type: Option<String>,
    non_retryable: Option<bool>,
    timeout_kind: Option<String>,
    attempts: Vec<Value>,
    retry_policy: Option<Map<String, Value>>,
    timeouts: [Option<i64>; 3],
}

impl Record {
    /// The exact history events and rows this record writes (Workflow #930).
    pub(super) fn budget(&self) -> (usize, usize) {
        super::history_meter::local_activity_budget(&self.attempts, matches!(self.outcome.as_str(), "failed" | "timed_out"))
    }

    /// The message the execution's exception carries, when it has one.
    pub(super) fn exception_message(&self) -> Option<String> {
        (self.outcome != "completed").then(|| self.message.clone().unwrap_or_else(|| "Local activity failed.".to_owned()))
    }
}

/// `WorkflowCommandNormalizer::normalize` for each record in the batch, at
/// the request's protocol version (1.20 without one), with its field scopes
/// and messages, collected across the batch.
pub(super) fn normalize(commands: &[Value], protocol: Option<&str>) -> Result<BTreeMap<usize, Record>> {
    let protocol = protocol.unwrap_or(super::WORKER_PROTOCOL_VERSION);
    let mut errors = Errors::default();
    let mut records = BTreeMap::new();
    for (index, command) in commands.iter().enumerate().filter(|(_, command)| command["type"] == COMMAND) {
        for (field, messages) in super::completion_rules::field_scope_errors(index, command) {
            for message in messages {
                errors.set(field.clone(), message);
            }
        }
        for field in PARALLEL_FIELDS.iter().filter(|field| present(command, field)) {
            errors.set(format!("commands.{index}.{field}"),
                format!("{field} is only supported for activity, timer, and child-workflow scheduling commands."));
        }
        // `optionalOperationScopeMetadata`.
        if let Some(scope) = command.get("cancellation_scope_id") {
            if !scope.as_str().is_some_and(|scope| !trim(scope).is_empty() && scope.len() <= 255) {
                errors.set(format!("commands.{index}.cancellation_scope_id"), "Expected a nonempty canonical scope identity.");
            } else if !supports(protocol, "1.20") {
                errors.set(format!("commands.{index}.cancellation_scope_id"), "Operation scope membership requires candidate protocol 1.20.");
            }
        }
        let Some(activity_type) = command["activity_type"].as_str().map(trim).filter(|text| !text.is_empty()) else {
            errors.set(format!("commands.{index}.activity_type"), "Local activity records require a non-empty activity_type.");
            continue;
        };
        let outcome = command_string(command, "outcome", index, &mut errors);
        let Some(outcome) = outcome.filter(|outcome| OUTCOMES.contains(&outcome.as_str())) else {
            errors.set(format!("commands.{index}.outcome"), "Local activity outcome must be completed, failed, timed_out, or cancelled.");
            continue;
        };
        let policy = retry_policy(command, index, &mut errors);
        let start = positive(command, "start_to_close_timeout", index, &mut errors);
        let close = positive(command, "schedule_to_close_timeout", index, &mut errors);
        let heartbeat = positive(command, "heartbeat_timeout", index, &mut errors);
        // `assertActivityTimeoutOrdering`.
        if let (Some(start), Some(close)) = (start, close)
            && close < start
        {
            errors.set(format!("commands.{index}.schedule_to_close_timeout"), format!("schedule_to_close_timeout ({close}) must be greater than or equal to start_to_close_timeout ({start}). schedule_to_close covers the whole activity execution including retries; one attempt cannot exceed the total budget."));
        }
        if let (Some(start), Some(heartbeat)) = (start, heartbeat)
            && heartbeat > start
        {
            errors.set(format!("commands.{index}.heartbeat_timeout"), format!("heartbeat_timeout ({heartbeat}) must be less than or equal to start_to_close_timeout ({start}). heartbeat_timeout polices liveness within one attempt and cannot exceed the per-attempt budget."));
        }
        let non_retryable = match command.get("non_retryable") {
            None => None,
            Some(Value::Bool(value)) => {
                if outcome == "completed" {
                    errors.set(format!("commands.{index}.non_retryable"), "Completed local activities cannot be marked non-retryable.");
                }
                Some(*value)
            }
            Some(_) => {
                errors.set(format!("commands.{index}.non_retryable"), "Local activity non_retryable must be boolean when provided.");
                None
            }
        };
        let attempts = attempts(command, &outcome, non_retryable, policy.as_ref(), protocol, index, &mut errors);
        let timeout_kind = command_string(command, "timeout_kind", index, &mut errors);
        if timeout_kind.as_deref().is_some_and(|kind| !TIMEOUT_KINDS.contains(&kind)) {
            errors.set(format!("commands.{index}.timeout_kind"),
                "Local activity timeout_kind must be start_to_close, schedule_to_close, or heartbeat.");
        }
        if outcome == "timed_out" && timeout_kind.is_none() {
            errors.set(format!("commands.{index}.timeout_kind"), "Timed out local activities require a timeout_kind.");
        }
        if outcome != "timed_out" && timeout_kind.is_some() {
            errors.set(format!("commands.{index}.timeout_kind"), "Only timed out local activities may declare timeout_kind.");
        }
        let (arguments, arguments_codec) = payload(command, "arguments", index, &mut errors)?;
        let (result, result_codec) = payload(command, "result", index, &mut errors)?;
        let codec = if arguments.is_some() {
            payload_codec(command, arguments_codec.as_deref(), "arguments", index, &mut errors)
        } else {
            payload_codec(command, result_codec.as_deref(), "result", index, &mut errors)
        };
        if outcome == "completed" && result.is_none() {
            errors.set(format!("commands.{index}.result"), "Completed local activities require a result payload envelope.");
        }
        let message = command["message"].as_str().map(|message| trim(message).to_owned());
        if outcome != "completed" && message.as_deref().is_none_or(str::is_empty) {
            errors.set(format!("commands.{index}.message"), "Failed, timed out, and cancelled local activities require a message.");
        }
        let exception_type = command_string(command, "exception_type", index, &mut errors);
        if let Some(attempts) = attempts {
            records.insert(index, Record {
                activity_type: activity_type.to_owned(),
                arguments,
                result,
                payload_codec: codec,
                outcome,
                message,
                exception_type,
                non_retryable,
                timeout_kind,
                attempts,
                retry_policy: policy,
                timeouts: [start, close, heartbeat],
            });
        }
    }
    match failure_messages(errors.0) {
        Some(error) => Err(error),
        None => Ok(records),
    }
}

/// `toJSON()`: microseconds and `Z`.
fn time(at: DateTime<Utc>) -> Value {
    json!(at.to_rfc3339_opts(SecondsFormat::Micros, true))
}

fn milliseconds(value: Option<&Value>) -> i64 {
    value.and_then(Value::as_i64).unwrap_or(0)
}

/// The earliest timestamp every supported column type stores (MySQL
/// TIMESTAMP's 1970-01-01T00:00:01Z), in Unix milliseconds.
const EARLIEST_STORED_TIMESTAMP_MS: i64 = 1000;

/// How far a report walks back from completion: each attempt's span (its
/// duration or last heartbeat, whichever is later) and, before the next
/// attempt, its backoff. None if the sum overflows.
fn backdated_ms(attempts: &[Value]) -> Option<i64> {
    let mut total: i64 = 0;
    for (position, report) in attempts.iter().enumerate() {
        let beats = report["heartbeats"].as_array().map_or(&[][..], Vec::as_slice);
        let span = milliseconds(beats.last().and_then(|beat| beat.get("elapsed_ms")))
            .max(milliseconds(report.get("duration_ms")));
        let backoff = if position + 1 < attempts.len() {
            milliseconds(report.get("backoff_seconds")).checked_mul(1000)?
        } else {
            0
        };
        total = total.checked_add(span)?.checked_add(backoff)?;
    }
    Some(total)
}

/// Whether the walk's earliest timestamp, `backdated` before `at_ms`, is
/// one every supported column type stores.
fn storable(at_ms: i64, backdated: i64) -> bool {
    at_ms.checked_sub(backdated).is_some_and(|earliest| earliest >= EARLIEST_STORED_TIMESTAMP_MS)
}

/// Checked timestamp arithmetic: the bounds above make these infallible, and
/// a failure is refused rather than unwound.
fn checked_sub(at: DateTime<Utc>, delta: Option<Duration>) -> Result<DateTime<Utc>> {
    delta.and_then(|delta| at.checked_sub_signed(delta))
        .ok_or_else(|| refuse(StatusCode::CONFLICT, "local_activity_timestamp_out_of_range"))
}

fn checked_add(at: DateTime<Utc>, delta: Option<Duration>) -> Result<DateTime<Utc>> {
    delta.and_then(|delta| at.checked_add_signed(delta))
        .ok_or_else(|| refuse(StatusCode::CONFLICT, "local_activity_timestamp_out_of_range"))
}

/// One reported attempt with the times the bridge gives it.
struct Attempt<'a> {
    report: &'a Value,
    id: String,
    started_at: DateTime<Utc>,
    last_heartbeat_at: Option<DateTime<Utc>>,
    closed_at: DateTime<Utc>,
}

impl Attempt<'_> {
    fn number(&self) -> i64 {
        self.report["attempt_number"].as_i64().unwrap_or(1)
    }
    fn worker_id(&self) -> Value {
        self.report.get("attempt_id").cloned().unwrap_or(Value::Null)
    }
    fn outcome(&self) -> &str {
        self.report["outcome"].as_str().unwrap_or("failed")
    }
    fn status(&self) -> &'static str {
        match self.outcome() {
            "completed" => "completed",
            "cancelled" => "cancelled",
            _ => "failed",
        }
    }
    fn heartbeats(&self) -> &[Value] {
        self.report["heartbeats"].as_array().map_or(&[], Vec::as_slice)
    }
}

/// Inserts the fields that have values, in order.
fn filtered(fields: &[(&str, Value)]) -> Value {
    Value::Object(fields.iter().filter(|(_, value)| !value.is_null()).map(|(key, value)| ((*key).to_owned(), value.clone())).collect())
}

/// Encodes each record's exception message with the official codec.
pub(super) async fn exceptions(records: &BTreeMap<usize, Record>, codec: Arc<Semaphore>) -> Result<BTreeMap<usize, String>> {
    let messages: Vec<(usize, String)> = records.iter().filter_map(|(index, record)| record.exception_message().map(|message| (*index, message))).collect();
    if messages.is_empty() {
        return Ok(BTreeMap::new());
    }
    let unavailable = || refuse(StatusCode::SERVICE_UNAVAILABLE, "local_activity_codec_unavailable");
    let permit = codec.acquire_owned().await.map_err(|_| unavailable())?;
    tokio::task::spawn_blocking(move || {
        let _permit = permit;
        let codec = crate::codec::ValueCodec::new().map_err(|_| unavailable())?;
        messages.into_iter()
            .map(|(index, message)| codec.encode(&crate::codec::Value::String(message)).map(|blob| (index, blob)).map_err(|_| unavailable()))
            .collect()
    })
    .await
    .map_err(|_| unavailable())?
}

/// `portableWorkerAffinityRejection`.
fn affinity_rejection(task_id: &str, attempt: &Value, reason: &str, requested: Option<&str>) -> RuntimeError {
    RuntimeError::Protocol {
        status: StatusCode::CONFLICT,
        response: json!({"task_id": task_id, "workflow_task_attempt": attempt, "outcome": "rejected", "recorded": false,
            "reason": reason, "capability": "local_activities", "requested_version": requested,
            "minimum_protocol_version": MINIMUM_PROTOCOL_VERSION,
            "remediation": "Use cold durable replay unless the registered worker manifest explicitly supports the required capability and exact cache identity.",
            "protocol_version": super::WORKER_PROTOCOL_VERSION, "server_capabilities": super::store::capabilities()}),
    }
}

store_methods! {
    /// `guardPortableWorkerAffinityCompletion` for a batch with a local
    /// activity record, after the ownership guard: protocol 1.18 or newer,
    /// and a lease owner whose registration lists `local_activities` with a
    /// supported manifest entry.
    pub(super) async fn guard_local_activities(tx: &mut Transaction<'_, DB>, namespace: &str, task: &DB::Row, body: &Value,
        protocol: Option<&str>) -> Result<()> {
        let task_id = DB::string(task, "id")?;
        let attempt = body["workflow_task_attempt"].as_i64().map_or(Value::Null, Value::from);
        if below(protocol, MINIMUM_PROTOCOL_VERSION) {
            return Err(affinity_rejection(&task_id, &attempt, "portable_worker_affinity_protocol_floor_unavailable", protocol));
        }
        let worker = Self::query("SELECT capabilities,capability_manifest FROM workflow_worker_registrations WHERE namespace=$1 AND worker_id=$2")
            .bind(namespace).bind(super::text(body, "lease_owner")?).fetch_optional(&mut **tx).await?;
        let (capabilities, manifest) = match &worker {
            Some(row) => (DB::optional_document_row(row, "capabilities")?, DB::optional_document_row(row, "capability_manifest")?),
            None => (None, None),
        };
        let listed = capabilities.as_ref().and_then(Value::as_array)
            .is_some_and(|capabilities| capabilities.iter().any(|capability| capability.as_str() == Some("local_activities")));
        let supported = manifest.as_ref().and_then(|manifest| manifest.get("local_activities"))
            .is_some_and(|entry| (entry.is_object() || entry.is_array()) && entry["supported"] == Value::Bool(true));
        if !listed || !supported {
            return Err(affinity_rejection(&task_id, &attempt, "worker_capability_not_supported", protocol));
        }
        Ok(())
    }


    /// `local_activity_timestamp_out_of_range`, in the completion's response.
    fn timestamp_out_of_range(tx: &Transaction<'_, DB>) -> RuntimeError {
        const REASON: &str = "local_activity_timestamp_out_of_range";
        tx.meter.as_ref().map_or_else(|| refuse(StatusCode::CONFLICT, REASON), |meter| meter.refused_with(REASON))
    }

    /// `applyRecordLocalActivity`: the execution, its attempts with times
    /// walked back from now, and the history PHP writes for them.
    #[allow(clippy::too_many_arguments)]
    pub(super) async fn record_local_activity(tx: &mut Transaction<'_, DB>, run: &DB::Row, task: &DB::Row, record: &Record,
        exception: Option<&str>, sequence: i64) -> Result<()> {
        let run_id = DB::string(run, "id")?;
        let task_id = DB::string(task, "id")?;
        let lease_owner = DB::optional_string(task, "lease_owner")?;
        let codec = record.payload_codec.clone()
            .or(DB::optional_string(run, "payload_codec")?.filter(|codec| !codec.is_empty()))
            .unwrap_or_else(|| "avro".to_owned());
        let at = now();
        // Workflow #930: the normalizer bounds every timing field; the walk's
        // total is also checked, and its earliest timestamp must be one every
        // supported column type stores, before anything is written.
        if !backdated_ms(&record.attempts).is_some_and(|backdated| storable(at.timestamp_millis(), backdated)) {
            return Err(Self::timestamp_out_of_range(tx));
        }
        // Reports carry durations, not times: walk back from the close so
        // each attempt ends before the next one's declared backoff. Identities
        // are made last attempt first, as PHP makes them, and are uppercase.
        let mut attempts: Vec<Attempt<'_>> = Vec::with_capacity(record.attempts.len());
        let mut closed_at = at;
        let mut latest_heartbeat: Option<DateTime<Utc>> = None;
        for (position, report) in record.attempts.iter().enumerate().rev() {
            let beats = report["heartbeats"].as_array().map_or(&[][..], Vec::as_slice);
            let last_elapsed = milliseconds(beats.last().and_then(|beat| beat.get("elapsed_ms")));
            let duration = milliseconds(report.get("duration_ms"));
            let started_at = checked_sub(closed_at, Duration::try_milliseconds(last_elapsed.max(duration)))?;
            let last_heartbeat_at = if beats.is_empty() {
                None
            } else {
                Some(checked_add(started_at, Duration::try_milliseconds(last_elapsed))?)
            };
            if let Some(beat) = last_heartbeat_at && latest_heartbeat.is_none_or(|latest| beat > latest) {
                latest_heartbeat = Some(beat);
            }
            attempts.push(Attempt { report, id: id().to_uppercase(), started_at, last_heartbeat_at, closed_at });
            if position > 0 {
                let backoff = milliseconds(record.attempts[position - 1].get("backoff_seconds"));
                closed_at = checked_sub(started_at, Duration::try_seconds(backoff))?;
            }
        }
        attempts.reverse();
        let last = attempts.last().expect("a normalized record has attempts");
        let status = match record.outcome.as_str() {
            "completed" => "completed",
            "cancelled" => "cancelled",
            _ => "failed",
        };
        let mut options = json!({"execution_mode": "local", "queue_bypassed": true, "routing": "same_process_workflow_task"});
        for (field, value) in ["start_to_close_timeout", "schedule_to_close_timeout", "heartbeat_timeout"].into_iter().zip(record.timeouts) {
            if let Some(seconds) = value {
                options[field] = json!(seconds);
            }
        }
        let policy = record.retry_policy.clone().map(Value::Object);
        let execution_id = id();
        let connection = DB::optional_string(run, "connection")?;
        let queue = DB::optional_string(run, "queue")?;
        let started_at = attempts[0].started_at;
        tx.charge_rows(1)?;
        Self::query("INSERT INTO activity_executions(id,workflow_run_id,sequence,activity_class,activity_type,status,payload_codec,arguments,result,exception,connection,queue,attempt_count,current_attempt_id,retry_policy,activity_options,started_at,closed_at,last_heartbeat_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,$21)")
            .bind(&execution_id).bind(&run_id).bind(sequence).bind(&record.activity_type).bind(&record.activity_type).bind(status)
            .bind(&codec).bind(record.arguments.as_deref()).bind(record.result.as_deref()).bind(exception)
            .bind(connection.as_deref()).bind(queue.as_deref()).bind(attempts.len() as i64).bind(&last.id)
            .bind(policy.as_ref().map(DB::document)).bind(DB::document(&options)).bind(DB::bind_time(started_at))
            .bind(DB::bind_time(at)).bind(DB::optional_time(latest_heartbeat)).bind(DB::bind_time(at)).bind(DB::bind_time(at))
            .execute(&mut **tx).await?;
        // `ActivitySnapshot::fromExecution`.
        let wire = |blob: &Option<String>| blob.as_deref().map_or(Value::Null, |blob| json!({"codec": codec, "blob": blob}));
        let terminal_activity = filtered(&[
            ("id", json!(execution_id)), ("idempotency_key", json!(execution_id)), ("sequence", json!(sequence)),
            ("type", json!(record.activity_type)), ("class", json!(record.activity_type)), ("execution_mode", json!("local")),
            ("local_activity", json!(true)), ("attempt_id", json!(last.id)), ("status", json!(status)),
            ("payload_codec", json!(codec)), ("attempt_count", json!(attempts.len())), ("retry_policy", policy.clone().unwrap_or(Value::Null)),
            ("connection", json!(connection)), ("queue", json!(queue)), ("last_heartbeat_at", latest_heartbeat.map_or(Value::Null, time)),
            ("created_at", time(at)), ("started_at", time(started_at)), ("closed_at", time(at)),
            ("arguments", wire(&record.arguments)), ("result", wire(&record.result)), ("exception", json!(exception)),
        ]);
        let without = |snapshot: &Value, fields: &[&str]| {
            let mut snapshot = snapshot.clone();
            for field in fields {
                snapshot.as_object_mut().expect("snapshot").remove(*field);
            }
            snapshot
        };
        let mut scheduled_activity = without(&terminal_activity, &["attempt_id", "started_at", "last_heartbeat_at", "closed_at", "result", "exception"]);
        scheduled_activity["status"] = json!("pending");
        scheduled_activity["attempt_count"] = json!(0);
        let snapshot = Self::task_snapshot(task)?;
        let base = json!({"execution_mode": "local", "local_activity": true, "activity_execution_id": execution_id,
            "activity_class": record.activity_type, "activity_type": record.activity_type, "sequence": sequence,
            "workflow_task_id": task_id, "task": snapshot});
        let event = |activity: Value, fields: Value| {
            let mut payload = base.clone();
            payload["activity"] = activity;
            for (key, value) in fields.as_object().expect("event fields") {
                payload[key] = value.clone();
            }
            payload
        };
        Self::append(tx, &run_id, "ActivityScheduled", event(scheduled_activity, json!({})), Some(&task_id), None).await?;
        let mut emitted: Option<DateTime<Utc>> = None;
        for (position, attempt) in attempts.iter().enumerate() {
            let worker_attempt_id = attempt.report.get("attempt_id").and_then(Value::as_str);
            tx.charge_rows(1)?;
            Self::query("INSERT INTO activity_attempts(id,workflow_run_id,activity_execution_id,workflow_task_id,worker_attempt_id,attempt_number,status,lease_owner,started_at,last_heartbeat_at,lease_expires_at,closed_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,NULL,$11,$12,$13)")
                .bind(&attempt.id).bind(&run_id).bind(&execution_id).bind(&task_id).bind(worker_attempt_id)
                .bind(attempt.number()).bind(attempt.status()).bind(lease_owner.as_deref()).bind(DB::bind_time(attempt.started_at))
                .bind(DB::optional_time(attempt.last_heartbeat_at)).bind(DB::bind_time(attempt.closed_at))
                .bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut **tx).await?;
            let terminal_attempt = filtered(&[
                ("id", json!(attempt.id)), ("worker_attempt_id", attempt.worker_id()), ("attempt_number", json!(attempt.number())),
                ("status", json!(attempt.status())), ("task_id", json!(task_id)), ("lease_owner", json!(lease_owner)),
                ("started_at", time(attempt.started_at)), ("last_heartbeat_at", attempt.last_heartbeat_at.map_or(Value::Null, time)),
                ("closed_at", time(attempt.closed_at)),
            ]);
            let mut running_attempt = without(&terminal_attempt, &["last_heartbeat_at", "closed_at"]);
            running_attempt["status"] = json!("running");
            let mut running_activity = without(&terminal_activity, &["closed_at", "result", "exception", "last_heartbeat_at"]);
            running_activity["attempt_id"] = json!(attempt.id);
            running_activity["attempt_count"] = json!(attempt.number());
            running_activity["status"] = json!("running");
            if let Some(emitted) = emitted {
                running_activity["last_heartbeat_at"] = time(emitted);
            }
            let started = json!({"activity_attempt_id": attempt.id, "worker_attempt_id": attempt.worker_id(),
                "attempt_number": attempt.number(), "activity_attempt": running_attempt});
            Self::append(tx, &run_id, "ActivityStarted", event(running_activity.clone(), started.clone()), Some(&task_id), None).await?;
            for heartbeat in attempt.heartbeats() {
                let heartbeat_at = checked_add(attempt.started_at, Duration::try_milliseconds(milliseconds(heartbeat.get("elapsed_ms"))))?;
                if emitted.is_none_or(|latest| heartbeat_at > latest) {
                    emitted = Some(heartbeat_at);
                }
                let mut activity = running_activity.clone();
                activity["last_heartbeat_at"] = time(emitted.expect("a heartbeat was emitted"));
                let mut fields = started.clone();
                fields["activity_attempt"]["last_heartbeat_at"] = time(heartbeat_at);
                fields["heartbeat_at"] = time(heartbeat_at);
                fields["lease_expires_at"] = Value::Null;
                if let Some(details) = heartbeat.get("details") {
                    fields["progress"] = details.clone();
                }
                Self::append(tx, &run_id, "ActivityHeartbeatRecorded", event(activity, fields), Some(&task_id), None).await?;
            }
            if position + 1 < attempts.len() {
                let mut activity = running_activity.clone();
                activity["status"] = json!("pending");
                match emitted {
                    Some(emitted) => activity["last_heartbeat_at"] = time(emitted),
                    None => {
                        activity.as_object_mut().expect("snapshot").remove("last_heartbeat_at");
                    }
                }
                let report = attempt.report;
                let retry = json!({"activity_attempt_id": attempt.id, "worker_attempt_id": attempt.worker_id(),
                    "activity_attempt": terminal_attempt, "retry_after_attempt_id": attempt.id, "retry_after_attempt": attempt.number(),
                    "message": report.get("message").cloned().unwrap_or_else(|| json!("Local activity attempt failed.")),
                    "exception_type": report.get("exception_type").cloned().unwrap_or(Value::Null),
                    "retry_reason": report.get("retry_reason").cloned().unwrap_or_else(|| json!("failure")),
                    "retry_backoff_seconds": report.get("backoff_seconds").cloned().unwrap_or_else(|| json!(0))});
                Self::append(tx, &run_id, "ActivityRetryScheduled", event(activity, retry), Some(&task_id), None).await?;
            }
        }
        let mut terminal = json!({"activity_attempt_id": last.id, "worker_attempt_id": last.worker_id(), "attempt_number": last.number(),
            "activity_attempt": filtered(&[
                ("id", json!(last.id)), ("worker_attempt_id", last.worker_id()), ("attempt_number", json!(last.number())),
                ("status", json!(last.status())), ("task_id", json!(task_id)), ("lease_owner", json!(lease_owner)),
                ("started_at", time(last.started_at)), ("last_heartbeat_at", last.last_heartbeat_at.map_or(Value::Null, time)),
                ("closed_at", time(last.closed_at)),
            ])});
        let event_type = match record.outcome.as_str() {
            "completed" => "ActivityCompleted",
            "cancelled" => "ActivityCancelled",
            "timed_out" => "ActivityTimedOut",
            _ => "ActivityFailed",
        };
        match record.outcome.as_str() {
            "completed" => {
                terminal["result"] = wire(&record.result);
                terminal["payload_codec"] = json!(codec);
            }
            "cancelled" => terminal["cancelled_at"] = time(at),
            outcome => {
                let timed_out = outcome == "timed_out";
                let failure_id = id();
                let message = record.message.clone().unwrap_or_default();
                let class = record.exception_type.clone()
                    .unwrap_or_else(|| if timed_out { TIMEOUT_EXCEPTION } else { "RuntimeException" }.to_owned());
                let non_retryable = record.non_retryable.unwrap_or(false);
                tx.charge_rows(1)?;
                Self::query("INSERT INTO workflow_failures(id,workflow_run_id,source_kind,source_id,propagation_kind,failure_category,non_retryable,handled,exception_class,message,file,line,trace_preview,created_at,updated_at) VALUES ($1,$2,'activity_execution',$3,$4,$5,CASE WHEN $6='true' THEN TRUE ELSE FALSE END,FALSE,$7,$8,'',0,'',$9,$10)")
                    .bind(&failure_id).bind(&run_id).bind(&execution_id).bind(if timed_out { "timeout" } else { "activity" })
                    .bind(if timed_out { "timeout" } else { "activity" }).bind(if non_retryable { "true" } else { "false" })
                    .bind(&class).bind(&message).bind(DB::bind_time(at)).bind(DB::bind_time(at)).execute(&mut **tx).await?;
                terminal["failure_id"] = json!(failure_id);
                if timed_out {
                    terminal["failure_category"] = json!("timeout");
                    terminal["timeout_kind"] = json!(record.timeout_kind.as_deref().unwrap_or("start_to_close"));
                    terminal["message"] = json!(record.message.as_deref().unwrap_or("Local activity timed out."));
                    terminal["exception_class"] = json!(record.exception_type);
                } else {
                    terminal["failure_category"] = json!("application");
                    terminal["message"] = json!(record.message.as_deref().unwrap_or("Local activity failed."));
                    terminal["exception_type"] = json!(record.exception_type);
                    terminal["non_retryable"] = json!(non_retryable);
                }
            }
        }
        Self::append(tx, &run_id, event_type, event(terminal_activity, terminal), Some(&task_id), None).await?;
        Ok(())
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn refusal(commands: Value, protocol: &str) -> Value {
        match normalize(commands.as_array().unwrap(), Some(protocol)) {
            Err(RuntimeError::Protocol { response, .. }) => response,
            other => panic!("{other:?}"),
        }
    }

    fn record(fields: Value) -> Value {
        let mut command = json!({"type": COMMAND, "activity_type": "parity.v1.local", "arguments": {"codec": "avro", "blob": "wwHioz3/VYAiNwwA"},
            "result": {"codec": "avro", "blob": "wwHioz3/VYAiNwoIZG9uZQ=="}, "outcome": "completed",
            "attempts": [{"attempt_id": "w-1", "attempt_number": 1, "outcome": "completed", "duration_ms": 25}]});
        for (key, value) in fields.as_object().unwrap() {
            command[key] = value.clone();
        }
        command
    }

    #[test]
    fn each_timing_field_admits_its_maximum_and_refuses_one_more() {
        let cases = [
            ("commands.0.attempts.0.duration_ms", "Local activity attempt duration_ms must not exceed 604800000."),
            ("commands.0.attempts.0.heartbeats.0.elapsed_ms", "Local activity heartbeat elapsed_ms must not exceed 604800000."),
            ("commands.0.attempts.0.backoff_seconds", "Local activity attempt backoff_seconds must not exceed 86400."),
        ];
        let report = |duration: i64, elapsed: i64, backoff: i64| record(json!({"retry_policy": {"max_attempts": 2},
            "attempts": [
                {"attempt_number": 1, "outcome": "failed", "message": "retry", "retry_reason": "failure",
                    "duration_ms": duration, "backoff_seconds": backoff, "heartbeats": [{"elapsed_ms": elapsed}]},
                {"attempt_number": 2, "outcome": "completed", "duration_ms": 25}]}));
        assert!(normalize(&[report(MAX_DURATION_MS, MAX_DURATION_MS, MAX_BACKOFF_SECONDS)], Some("1.20")).is_ok());
        for over in [1, i64::MAX] {
            for (index, (field, message)) in cases.iter().enumerate() {
                let bump = |value: i64, own: usize| if index == own { value.saturating_add(over) } else { value };
                let response = refusal(json!([report(bump(MAX_DURATION_MS, 0), bump(MAX_DURATION_MS, 1),
                    bump(MAX_BACKOFF_SECONDS, 2))]), "1.20");
                let fields: Vec<&String> = response["errors"].as_object().unwrap().keys().collect();
                assert_eq!(fields, [field], "{response}");
                assert_eq!(response["errors"][field], json!([message]), "{response}");
            }
        }
    }

    #[test]
    fn a_retried_attempt_needs_backoff_seconds_only_when_absent() {
        let response = refusal(json!([record(json!({"retry_policy": {"max_attempts": 2}, "attempts": [
            {"attempt_number": 1, "outcome": "failed", "message": "retry", "retry_reason": "failure"},
            {"attempt_number": 2, "outcome": "completed"}]}))]), "1.20");
        assert_eq!(response["errors"]["commands.0.attempts.0.backoff_seconds"],
            json!(["A retried local activity attempt requires backoff_seconds."]));
        let response = refusal(json!([record(json!({"retry_policy": {"max_attempts": 2}, "attempts": [
            {"attempt_number": 1, "outcome": "failed", "message": "retry", "retry_reason": "failure", "backoff_seconds": 86_401},
            {"attempt_number": 2, "outcome": "completed"}]}))]), "1.20");
        assert_eq!(response["errors"]["commands.0.attempts.0.backoff_seconds"],
            json!(["Local activity attempt backoff_seconds must not exceed 86400."]));
    }

    #[test]
    fn the_walk_is_checked_and_must_stay_storable() {
        let attempt = |duration: i64, elapsed: Option<i64>, backoff: i64| {
            let mut attempt = json!({"duration_ms": duration, "backoff_seconds": backoff});
            if let Some(elapsed) = elapsed {
                attempt["heartbeats"] = json!([{"elapsed_ms": elapsed}]);
            }
            attempt
        };
        // A span is the later of the duration and the last heartbeat; the
        // last attempt's backoff does not count.
        assert_eq!(backdated_ms(&[attempt(1_000, Some(5_000), 2), attempt(3_000, None, 9)]), Some(5_000 + 2_000 + 3_000));
        // The longest report: 100 seven-day attempts and 99 one-day backoffs.
        let longest: Vec<Value> = (0..100).map(|_| attempt(MAX_DURATION_MS, Some(MAX_DURATION_MS), MAX_BACKOFF_SECONDS)).collect();
        assert_eq!(backdated_ms(&longest), Some(100 * MAX_DURATION_MS + 99 * MAX_BACKOFF_SECONDS * 1_000));
        // Arithmetic past i64 fails closed.
        assert_eq!(backdated_ms(&[attempt(i64::MAX, None, 0), attempt(1, None, 0)]), None);
        assert_eq!(backdated_ms(&[attempt(0, None, i64::MAX), attempt(0, None, 0)]), None);
        // The earliest storable instant is 1970-01-01T00:00:01Z.
        let one_day = 86_400_000;
        assert!(storable(one_day, one_day - EARLIEST_STORED_TIMESTAMP_MS));
        assert!(!storable(one_day, one_day - EARLIEST_STORED_TIMESTAMP_MS + 1));
        assert!(!storable(one_day, 2 * one_day));
        assert!(!storable(i64::MIN + 1, 2));
    }

    #[test]
    fn protocol_versions_compare_like_php() {
        assert!(supports("1.18", "1.18") && supports("1.20", "1.19") && !supports("1.17", "1.18") && !supports("2.20", "1.18"));
        assert!(!supports("1.x", "1.18") && !supports("1", "1.18"));
        assert!(below(Some("1.17"), "1.18") && below(Some("1.9"), "1.18") && !below(Some("1.18"), "1.18") && below(None, "1.18"));
    }

    #[test]
    fn a_later_message_for_a_field_replaces_it_in_place() {
        // The published multi-error report: six fields, PHP's order.
        let response = refusal(json!([record(json!({"non_retryable": false, "result": null,
            "attempts": [{"attempt_number": 2, "outcome": "failed", "extra": 1}]}))]), "1.20");
        assert_eq!(response["message"], "Completed local activities cannot be marked non-retryable. (and 5 more errors)");
        let fields: Vec<&String> = response["errors"].as_object().unwrap().keys().collect();
        assert_eq!(fields.len(), 6);
        assert_eq!(response["errors"]["commands.0.attempts.0.outcome"],
            json!(["The final local activity attempt outcome must match the command outcome."]));
        // The timed out report: the timeout kind's second message wins.
        let response = refusal(json!([record(json!({"outcome": "timed_out", "result": null, "message": "late",
            "timeout_kind": "bogus", "attempts": [{"attempt_number": 1, "outcome": "timed_out", "message": "x", "timeout_kind": "heartbeat"}]}))]), "1.20");
        assert_eq!(response["errors"]["commands.0.timeout_kind"],
            json!(["Local activity timeout_kind must be start_to_close, schedule_to_close, or heartbeat."]));
    }

    #[test]
    fn protocol_1_18_synthesizes_one_terminal_attempt_and_ignores_unknown_fields() {
        let mut command = record(json!({"outcome": "failed", "result": null, "message": " no attempts ", "exception_type": "E"}));
        command.as_object_mut().unwrap().remove("attempts");
        let records = normalize(&[command.clone()], Some("1.18")).unwrap();
        assert_eq!(records[&0].attempts, vec![json!({"attempt_number": 1, "outcome": "failed", "message": "no attempts",
            "exception_type": "E", "heartbeats": []})]);
        assert_eq!(refusal(json!([command]), "1.19")["message"], "Local activity attempts must be a non-empty ordered list.");
        let lenient = record(json!({"retry_policy": {"max_attempts": 1, "jitter": 1},
            "attempts": [{"attempt_number": 1, "outcome": "completed", "shard": "x", "heartbeats": [{"elapsed_ms": 5, "note": "x"}]}]}));
        let records = normalize(&[lenient.clone()], Some("1.18")).unwrap();
        assert_eq!(records[&0].attempts[0]["heartbeats"], json!([{"elapsed_ms": 5}]));
        assert_eq!(records[&0].retry_policy, Some(json!({"max_attempts": 1}).as_object().unwrap().clone()));
        let strict = refusal(json!([lenient]), "1.20");
        assert_eq!(strict["message"], "Field [jitter] is not supported in a local activity retry policy report. (and 2 more errors)");
    }

    #[test]
    fn reports_are_read_as_sent() {
        // Published PHP reorders this report before its normalizer sees it.
        let response = refusal(json!([record(json!({"retry_policy": {"max_attempts": 2, "backoff_seconds": [0]},
            "attempts": [{"attempt_number": 1, "outcome": "failed", "message": "one", "retry_reason": "failure", "backoff_seconds": 0},
                {"attempt_id": "e-2", "attempt_number": 2, "outcome": "completed", "heartbeats": [{"elapsed_ms": 9}, {"elapsed_ms": 5, "details": {}}, "beat"]}]}))]), "1.20");
        assert_eq!(response["errors"], json!({
            "commands.0.attempts.1.heartbeats.1.elapsed_ms": ["Local activity heartbeat elapsed_ms values must be ordered monotonically."],
            "commands.0.attempts.1.heartbeats.2": ["Each local activity heartbeat must be an object."]}));
        // Empty heartbeat details are PHP's empty array.
        let records = normalize(&[record(json!({"attempts": [{"attempt_number": 1, "outcome": "completed",
            "heartbeats": [{"elapsed_ms": 1, "details": {}}, {"elapsed_ms": 2, "details": {"a": {}}}]}]}))], Some("1.20")).unwrap();
        assert_eq!(records[&0].attempts[0]["heartbeats"], json!([{"elapsed_ms": 1, "details": []}, {"elapsed_ms": 2, "details": {"a": []}}]));
    }

    #[test]
    fn errors_are_collected_across_the_batch_and_scopes_come_first() {
        let response = refusal(json!([record(json!({"schedule_to_start_timeout": 5, "activity_type": null})),
            record(json!({"parallel_group_id": "g"})), {"type": "start_timer", "delay_seconds": 1}]), "1.20");
        let fields: Vec<&String> = response["errors"].as_object().unwrap().keys().collect();
        assert_eq!(fields.len(), 3, "{response}");
        assert!(response["message"].as_str().unwrap().starts_with("Workflow task command field [schedule_to_start_timeout]"));
        assert_eq!(response["errors"]["commands.1.parallel_group_id"],
            json!(["parallel_group_id is only supported for activity, timer, and child-workflow scheduling commands."]));
    }

    #[test]
    fn payloads_follow_the_resolver() {
        let records = normalize(&[record(json!({"arguments": "wwHioz3/VYAiNwwA", "payload_codec": "avro"}))], None).unwrap();
        assert_eq!(records[&0].arguments.as_deref(), Some("wwHioz3/VYAiNwwA"));
        assert_eq!(records[&0].payload_codec.as_deref(), Some("avro"));
        // Plain arguments are no payload; a plain result is refused. For a
        // completed record PHP then replaces the resolver's message with the
        // missing-result one under the same key.
        let records = normalize(&[record(json!({"arguments": {"a": 1}}))], None).unwrap();
        assert_eq!(records[&0].arguments, None);
        assert_eq!(refusal(json!([record(json!({"result": [1]}))]), "1.20")["errors"]["commands.0.result"],
            json!(["Completed local activities require a result payload envelope."]));
        assert_eq!(refusal(json!([record(json!({"result": [1], "outcome": "failed", "message": "no",
            "attempts": [{"attempt_number": 1, "outcome": "failed", "message": "no"}]}))]), "1.20")["errors"]["commands.0.result"],
            json!(["Workflow task command field [result] must be a string or a payload envelope when provided."]));
        let records = normalize(&[record(json!({"outcome": "cancelled", "result": null, "message": "stop",
            "attempts": [{"attempt_number": 1, "outcome": "cancelled", "message": "stop"}]}))], None).unwrap();
        assert_eq!(records[&0].exception_message().as_deref(), Some("stop"));
    }
}
