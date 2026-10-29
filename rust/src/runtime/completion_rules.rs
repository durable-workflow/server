//! The transport rules published PHP 2.11.1 checks on a workflow task
//! completion before anything else, ownership included
//! (`WorkflowTaskCompletionValidation::rules` with the Workflow package's
//! parallel metadata rules, in `WorkerController::completeWorkflowTask`).
//!
//! PHP validates the top-level fields once and the commands in chunks of 25,
//! keeping each command's index. Laravel reports a chunk's errors rule by
//! rule, each rule's expansions in data order, so native does the same. A
//! wildcard attribute is named as written (`commands.0.type`); a named one is
//! shown with spaces (`sticky cache.workflow id`).
//!
//! Once the rules pass, PHP turns digit strings in its integer fields into
//! integers, so `"7"` is a valid delay.
use super::laravel_validation::{Rule, default_message, integer, passes, present};
use super::Result;
use serde_json::{Value, json};

#[derive(Clone, Copy)]
enum Check {
    Rule(Rule),
    Sometimes,
    Prohibited,
    /// `required_with`: the other field, relative to the same command, or
    /// to the body for a top-level field.
    RequiredWith(&'static str),
    /// `array:keys`: an array with no other keys.
    Keys(&'static [&'static str]),
}

const REQ: Check = Check::Rule(Rule::Required);
const NUL: Check = Check::Rule(Rule::Nullable);
const STR: Check = Check::Rule(Rule::String);
const ARR: Check = Check::Rule(Rule::Array);
const INT: Check = Check::Rule(Rule::Integer);
const BOOL: Check = Check::Rule(Rule::Boolean);
const SOME: Check = Check::Sometimes;
const NO: Check = Check::Prohibited;
const fn min(least: i64) -> Check {
    Check::Rule(Rule::Min(least))
}
const fn max(most: i64) -> Check {
    Check::Rule(Rule::Max(most))
}
const fn one_of(values: &'static [&'static str]) -> Check {
    Check::Rule(Rule::In(values))
}
const STREAM: Check = Check::RequiredWith("workflow_stream");
const STICKY: Check = Check::RequiredWith("sticky_cache");
const CLEANUP: Check = Check::RequiredWith("cancellation_cleanup");
const OUTCOMES: &[&str] = &["completed", "failed", "timed_out", "cancelled"];
const TIMEOUT_KINDS: &[&str] = &["start_to_close", "schedule_to_close", "heartbeat"];

/// The rules outside `commands.*`, in PHP's order.
const TOP: &[(&str, &[Check])] = &[
    ("lease_owner", &[REQ, STR]),
    ("workflow_task_attempt", &[REQ, INT, min(1)]),
    ("commands", &[REQ, ARR, min(1)]),
    ("sticky_cache", &[NUL, ARR]),
    ("sticky_cache.worker_id", &[STICKY, STR, max(255)]),
    ("sticky_cache.workflow_id", &[STICKY, STR, max(255)]),
    ("sticky_cache.run_id", &[STICKY, STR, max(255)]),
    ("sticky_cache.build_id", &[STICKY, STR, max(255)]),
    ("sticky_cache.ttl_seconds", &[STICKY, INT, min(1), max(3600)]),
    ("sticky_cache.metrics", &[NUL, ARR]),
    ("sticky_cache.metrics.hit", &[NUL, INT, min(0)]),
    ("sticky_cache.metrics.miss", &[NUL, INT, min(0)]),
    ("sticky_cache.metrics.eviction", &[NUL, INT, min(0)]),
    ("sticky_cache.metrics.forced_cold_replay", &[NUL, INT, min(0)]),
];

/// The `commands.*.` rules, relative to one command, in PHP's order. Rules
/// that are only `nullable` never fail and are left out.
const COMMAND: &[(&str, &[Check])] = &[
    ("type", &[REQ, STR]),
    ("selection_group_id", &[SOME, STR]),
    ("member_key", &[SOME, STR]),
    ("member_index", &[SOME, INT, min(0)]),
    ("member_base_sequence", &[SOME, INT, min(1)]),
    ("member_size", &[SOME, INT, min(1)]),
    ("operation_kind", &[SOME, STR]),
    ("operation_identity", &[SOME, STR]),
    ("cancellation_scope_id", &[SOME, STR, min(1), max(255)]),
    ("cancellation_cleanup", &[SOME, REQ, Check::Keys(&["scope_id", "request_id", "delivery_history_event_id"])]),
    ("cancellation_cleanup.scope_id", &[SOME, REQ, STR, max(255)]),
    ("cancellation_cleanup.request_id", &[CLEANUP, STR, max(255)]),
    ("cancellation_cleanup.delivery_history_event_id", &[CLEANUP, STR, max(255)]),
    ("activity_type", &[NUL, STR]),
    ("connection", &[NUL, STR]),
    ("queue", &[NUL, STR]),
    ("retry_policy", &[NUL, ARR]),
    ("retry_policy.max_attempts", &[NUL, INT, min(1)]),
    ("retry_policy.backoff_seconds", &[NUL, ARR]),
    ("retry_policy.backoff_seconds.*", &[INT, min(0)]),
    ("retry_policy.non_retryable_error_types", &[NUL, ARR]),
    ("retry_policy.non_retryable_error_types.*", &[STR]),
    ("start_to_close_timeout", &[NUL, INT, min(1)]),
    ("schedule_to_start_timeout", &[NUL, INT, min(1)]),
    ("schedule_to_close_timeout", &[NUL, INT, min(1)]),
    ("heartbeat_timeout", &[NUL, INT, min(1)]),
    ("execution_mode", &[NUL, STR, one_of(&["local"])]),
    ("outcome", &[NUL, STR, one_of(OUTCOMES)]),
    ("attempts", &[NUL, ARR, max(100)]),
    ("attempts.*.attempt_id", &[NUL, STR, max(255)]),
    ("attempts.*.attempt_number", &[REQ, INT, min(1)]),
    ("attempts.*.outcome", &[REQ, STR, one_of(OUTCOMES)]),
    ("attempts.*.duration_ms", &[NUL, INT, min(0)]),
    ("attempts.*.message", &[NUL, STR]),
    ("attempts.*.exception_type", &[NUL, STR, max(255)]),
    ("attempts.*.non_retryable", &[NUL, BOOL]),
    ("attempts.*.timeout_kind", &[NUL, STR, one_of(TIMEOUT_KINDS)]),
    ("attempts.*.retry_reason", &[NUL, STR, one_of(&["failure", "timeout", "cold_replay"])]),
    ("attempts.*.backoff_seconds", &[NUL, INT, min(0)]),
    ("attempts.*.heartbeats", &[NUL, ARR, max(1000)]),
    ("attempts.*.heartbeats.*.details", &[NUL, ARR]),
    ("attempts.*.heartbeats.*.elapsed_ms", &[NUL, INT, min(0)]),
    ("worker_session", &[NUL, ARR]),
    ("worker_session.session_id", &[NUL, STR, max(255)]),
    ("worker_session.connection", &[NUL, STR, max(255)]),
    ("worker_session.queue", &[NUL, STR, max(255)]),
    ("worker_session.requirements", &[NUL, ARR]),
    ("worker_session.requirements.*", &[STR, max(255)]),
    ("worker_session.lease_seconds", &[NUL, INT, min(1), max(super::worker_sessions::MAX_LEASE_SECONDS)]),
    ("worker_session.ttl_seconds", &[NUL, INT, min(1), max(super::worker_sessions::MAX_TTL_SECONDS)]),
    ("worker_session.max_concurrent_activities", &[NUL, INT, min(1), max(super::worker_sessions::MAX_CONCURRENT_ACTIVITIES)]),
    ("worker_session.create_if_missing", &[NUL, BOOL]),
    ("worker_session.allow_reacquire_after_failure", &[NUL, BOOL]),
    ("execution_timeout_seconds", &[NUL, INT, min(1)]),
    ("run_timeout_seconds", &[NUL, INT, min(1)]),
    ("workflow_type", &[NUL, STR]),
    ("delay_seconds", &[NUL, INT, min(0)]),
    ("message", &[NUL, STR]),
    ("timeout_kind", &[NUL, STR, one_of(TIMEOUT_KINDS)]),
    ("payload_codec", &[NUL, STR]),
    ("update_id", &[NUL, STR]),
    ("exception_class", &[NUL, STR]),
    ("exception_type", &[NUL, STR]),
    ("exception", &[NUL, ARR]),
    ("failed_step_sequence", &[NUL, INT, min(1)]),
    ("failed_activity_execution_id", &[NUL, STR, max(255)]),
    ("change_id", &[NUL, STR]),
    ("version", &[NUL, INT]),
    ("min_supported", &[NUL, INT]),
    ("max_supported", &[NUL, INT]),
    ("attributes", &[NUL, ARR]),
    ("attribute_types", &[NUL, ARR]),
    ("attribute_types.*", &[STR]),
    ("entries", &[NUL, ARR]),
    ("non_retryable", &[NUL, BOOL]),
    ("parent_close_policy", &[NUL, STR]),
    ("cancellation_policy", &[NUL, STR, one_of(&["try_cancel", "wait_cancellation_completed", "abandon"])]),
    ("condition_key", &[NUL, STR]),
    ("condition_definition_fingerprint", &[NUL, STR]),
    ("condition_wait_occurrence_id", &[NUL, STR]),
    ("signal_name", &[NUL, STR]),
    ("timeout_seconds", &[NUL, INT, min(0)]),
    ("endpoint_name", &[NUL, STR, max(191)]),
    ("service_name", &[NUL, STR, max(191)]),
    ("operation_name", &[NUL, STR, max(191)]),
    ("namespace", &[NUL, STR, max(191)]),
    ("caller_namespace", &[NUL, STR, max(191)]),
    ("service_call_id", &[NUL, STR, max(191)]),
    ("idempotency_key", &[NUL, STR, max(191)]),
    ("mode_override", &[NUL, STR, one_of(&["sync", "async"])]),
    ("wait_for", &[NUL, STR, one_of(&["accepted", "completed"])]),
    ("wait_timeout_seconds", &[NUL, INT, min(0)]),
    ("target_workflow_instance_id", &[NUL, STR, max(191)]),
    ("target_workflow_run_id", &[NUL, STR, max(191)]),
    ("business_key", &[NUL, STR, max(191)]),
    ("labels", &[NUL, ARR]),
    ("memo", &[NUL, ARR]),
    ("search_attributes", &[NUL, ARR]),
    ("duplicate_start_policy", &[NUL, STR, one_of(&["reject_duplicate", "return_existing_active"])]),
    ("metadata", &[NUL, ARR]),
    ("request_payload_reference", &[NUL, STR, max(191)]),
    ("principal_subject", &[NO]),
    ("principal_method", &[NO]),
    ("principal_roles", &[NO]),
    ("principal_tenant", &[NO]),
    ("principal_claims", &[NO]),
    // `WorkflowCommandNormalizer::parallelMetadataValidationRules`.
    ("parallel_group_id", &[NUL, STR]),
    ("parallel_group_kind", &[NUL, STR]),
    ("parallel_group_mode", &[NUL, STR]),
    ("parallel_group_base_sequence", &[NUL, INT, min(1)]),
    ("parallel_group_size", &[NUL, INT, min(1)]),
    ("parallel_group_index", &[NUL, INT, min(0)]),
    ("selection_member_index", &[NUL, INT, min(0)]),
    ("selection_member_base_sequence", &[NUL, INT, min(1)]),
    ("selection_member_size", &[NUL, INT, min(1)]),
    ("selection_member_kind", &[NUL, STR]),
    ("parallel_group_path", &[NUL, ARR]),
    ("parallel_group_path.*.parallel_group_id", &[REQ, STR]),
    ("parallel_group_path.*.parallel_group_kind", &[REQ, STR]),
    ("parallel_group_path.*.parallel_group_mode", &[NUL, STR]),
    ("parallel_group_path.*.parallel_group_base_sequence", &[REQ, INT, min(1)]),
    ("parallel_group_path.*.parallel_group_size", &[REQ, INT, min(1)]),
    ("parallel_group_path.*.parallel_group_index", &[REQ, INT, min(0)]),
    ("parallel_group_path.*.selection_member_index", &[NUL, INT, min(0)]),
    ("parallel_group_path.*.selection_member_base_sequence", &[NUL, INT, min(1)]),
    ("parallel_group_path.*.selection_member_size", &[NUL, INT, min(1)]),
    ("parallel_group_path.*.selection_member_kind", &[NUL, STR]),
    ("workflow_stream", &[NUL, ARR]),
    ("workflow_stream.operation", &[STREAM, STR, one_of(&["append", "close", "error"])]),
    ("workflow_stream.stream_name", &[STREAM, STR, max(191)]),
    ("workflow_stream.command_identity", &[STREAM, STR, max(191)]),
    ("workflow_stream.command_ordinal", &[STREAM, INT, min(0)]),
    // `WorkflowStreamService::DEFAULT_MAX_ITEMS_PER_APPEND`.
    ("workflow_stream.items", &[NUL, ARR, max(100)]),
    ("workflow_stream.items.*", &[ARR]),
    ("workflow_stream.items.*.payload_reference", &[NUL, STR, max(191)]),
    ("workflow_stream.items.*.payload_codec", &[NUL, STR, max(64)]),
    ("workflow_stream.items.*.idempotency_key", &[NUL, STR, max(191)]),
    ("workflow_stream.items.*.item_type", &[NUL, STR, max(64)]),
    ("workflow_stream.items.*.content_type", &[NUL, STR, max(191)]),
    // `WorkflowStreamService::MAX_PENDING_ITEMS_MAX` and
    // `RETENTION_SECONDS_MAX` (Server #774).
    ("workflow_stream.max_pending_items", &[NUL, INT, min(1), max(10_000)]),
    ("workflow_stream.error_reason", &[NUL, STR, max(191)]),
    ("workflow_stream.retention_seconds", &[NUL, INT, min(1), max(2_592_000)]),
];

/// `WORKFLOW_TASK_COMMAND_VALIDATION_BATCH_SIZE`.
const CHUNK: usize = 25;

/// `normalizeWorkflowTaskCommandIntegerFields`.
const INTEGER_FIELDS: &[&str] = &[
    "start_to_close_timeout",
    "schedule_to_start_timeout",
    "schedule_to_close_timeout",
    "heartbeat_timeout",
    "execution_timeout_seconds",
    "run_timeout_seconds",
    "delay_seconds",
    "version",
    "min_supported",
    "max_supported",
    "timeout_seconds",
    "wait_timeout_seconds",
    "failed_step_sequence",
];
const SESSION_INTEGER_FIELDS: &[&str] = &["lease_seconds", "ttl_seconds", "max_concurrent_activities"];

/// Each concrete attribute a rule key names in `value`, with its value when
/// present: `*` takes every element in order; a missing segment ends the
/// expansion unless no `*` follows it, when the attribute is absent.
fn expand<'a>(value: Option<&'a Value>, segments: &[&str], name: String, out: &mut Vec<(String, Option<&'a Value>)>) {
    let Some((segment, rest)) = segments.split_first() else {
        out.push((name, value));
        return;
    };
    let join = |key: &str| if name.is_empty() { key.to_owned() } else { format!("{name}.{key}") };
    if *segment == "*" {
        match value {
            Some(Value::Array(items)) => {
                for (index, item) in items.iter().enumerate() {
                    expand(Some(item), rest, join(&index.to_string()), out);
                }
            }
            Some(Value::Object(items)) => {
                for (key, item) in items {
                    expand(Some(item), rest, join(key), out);
                }
            }
            _ => {}
        }
        return;
    }
    let child = value.and_then(Value::as_object).and_then(|fields| fields.get(*segment));
    if child.is_none() && rest.contains(&"*") {
        return;
    }
    expand(child, rest, join(segment), out);
}

/// One attribute's messages, as Laravel's `validateAttribute` gives them.
fn messages(display: &str, checks: &[Check], value: Option<&Value>, other: Option<(String, bool)>) -> Vec<String> {
    let has = |wanted: fn(&Check) -> bool| checks.iter().any(wanted);
    if has(|check| matches!(check, Check::Sometimes)) && value.is_none() {
        return Vec::new();
    }
    let nullable = has(|check| matches!(check, Check::Rule(Rule::Nullable)));
    let numeric = has(|check| matches!(check, Check::Rule(Rule::Integer)));
    let array = has(|check| matches!(check, Check::Rule(Rule::Array) | Check::Keys(_)));
    let mut found = Vec::new();
    for check in checks {
        let implicit = matches!(check, Check::Rule(Rule::Required) | Check::RequiredWith(_));
        if !implicit && (value.is_none() || nullable && value.is_some_and(Value::is_null)) {
            continue;
        }
        let failed = match *check {
            Check::Sometimes | Check::Rule(Rule::Nullable) => None,
            Check::Rule(rule) => (!passes(rule, value, numeric)).then(|| default_message(display, rule, numeric, array)),
            Check::Prohibited => present(value).then(|| format!("The {display} field is prohibited.")),
            Check::RequiredWith(_) => match &other {
                Some((other, true)) if !present(value) => Some(format!("The {display} field is required when {other} is present.")),
                _ => None,
            },
            Check::Keys(keys) => {
                let fits = match value {
                    Some(Value::Object(fields)) => fields.keys().all(|key| keys.contains(&key.as_str())),
                    Some(Value::Array(items)) => items.is_empty(),
                    _ => false,
                };
                (!fits).then(|| default_message(display, Rule::Array, numeric, array))
            }
        };
        if let Some(message) = failed {
            found.push(message);
            if implicit {
                break;
            }
        }
    }
    found
}

/// The field a `required_with` names, shown as Laravel shows it, and
/// whether it is filled.
fn other_field(checks: &[Check], scope: Option<&Value>, prefix: &str) -> Option<(String, bool)> {
    checks.iter().find_map(|check| match check {
        Check::RequiredWith(other) => {
            let shown = if prefix.is_empty() { other.replace('_', " ") } else { format!("{prefix}.{other}") };
            Some((shown, present(scope.and_then(|scope| scope.get(*other)))))
        }
        _ => None,
    })
}

/// The errors for one set of rules over one value, rule by rule.
fn errors(rules: &[(&str, &[Check])], scope: Option<&Value>, prefix: &str, found: &mut Vec<(String, Vec<String>)>) {
    for (key, checks) in rules {
        let segments: Vec<&str> = key.split('.').collect();
        let mut attributes = Vec::new();
        expand(scope, &segments, prefix.to_owned(), &mut attributes);
        let other = other_field(checks, scope, prefix);
        for (name, value) in attributes {
            // A wildcard attribute is named as written; a named one with spaces.
            let display = if prefix.is_empty() && !key.contains('*') { name.replace('_', " ") } else { name.clone() };
            let messages = messages(&display, checks, value, other.clone());
            if !messages.is_empty() && !found.iter().any(|(seen, _)| *seen == name) {
                found.push((name, messages));
            }
        }
    }
}

/// PHP's `normalizeValidatedInteger`: a digit string becomes an integer.
fn whole(value: &mut Value) {
    if let Value::String(text) = value {
        let digits = text.strip_prefix('-').unwrap_or(text);
        if !digits.is_empty() && digits.bytes().all(|byte| byte.is_ascii_digit()) && let Ok(number) = text.parse::<i64>() {
            *value = Value::from(number);
        }
    }
}

/// Validates the completion as PHP's transport rules do, then normalizes its
/// integer fields.
pub(super) fn checked(body: Value) -> Result<Value> {
    // Laravel's TrimStrings and ConvertEmptyStringsToNull run first.
    let mut body = super::start_request::normalized(body);
    let mut found = Vec::new();
    errors(TOP, Some(&body), "", &mut found);
    if let Some(commands) = body["commands"].as_array() {
        for (chunk, commands) in commands.chunks(CHUNK).enumerate() {
            let mut chunk_errors = Vec::new();
            for (key, checks) in COMMAND {
                for (offset, command) in commands.iter().enumerate() {
                    let index = chunk * CHUNK + offset;
                    errors(&[(*key, *checks)], Some(command), &format!("commands.{index}"), &mut chunk_errors);
                }
            }
            for (name, messages) in chunk_errors {
                if !found.iter().any(|(seen, _)| *seen == name) {
                    found.push((name, messages));
                }
            }
        }
    }
    if let Some(error) = super::laravel_validation::failure_messages(found) {
        return Err(error);
    }
    if let Some(attempt) = body.get("workflow_task_attempt").and_then(integer) {
        body["workflow_task_attempt"] = Value::from(attempt);
    }
    if let Some(commands) = body["commands"].as_array_mut() {
        for command in commands.iter_mut().filter_map(Value::as_object_mut) {
            for field in INTEGER_FIELDS {
                if let Some(value) = command.get_mut(*field) {
                    whole(value);
                }
            }
            if let Some(session) = command.get_mut("worker_session").and_then(Value::as_object_mut) {
                for field in SESSION_INTEGER_FIELDS {
                    if let Some(value) = session.get_mut(*field) {
                        whole(value);
                    }
                }
            }
            if let Some(policy) = command.get_mut("retry_policy").and_then(Value::as_object_mut) {
                if let Some(value) = policy.get_mut("max_attempts") {
                    whole(value);
                }
                if let Some(Value::Array(backoff)) = policy.get_mut("backoff_seconds") {
                    backoff.iter_mut().for_each(whole);
                }
            }
        }
    }
    // `WorkflowCommandNormalizer::preflightParallelMetadata`, which PHP
    // runs here, before the controller's scopes and any ownership guard.
    if let Some(commands) = body["commands"].as_array_mut() {
        commands.iter_mut().for_each(parallel_integers);
        if let Some(error) = super::laravel_validation::failure_messages(parallel_preflight(commands)) {
            return Err(error);
        }
    }
    if let Some(commands) = body["commands"].as_array()
        && let Some(error) = super::laravel_validation::failure_messages(controller_scopes(commands))
    {
        return Err(error);
    }
    Ok(body)
}

/// `PARALLEL_METADATA_INTEGER_FIELDS`.
const PARALLEL_INTEGER_FIELDS: &[&str] = &["parallel_group_base_sequence", "parallel_group_size", "parallel_group_index",
    "selection_member_index", "selection_member_base_sequence", "selection_member_size"];

/// `normalizeParallelMetadataIntegerFields`: a digit string becomes an
/// integer, at the top level and in each path entry.
fn parallel_integers(command: &mut Value) {
    let normalize = |fields: &mut serde_json::Map<String, Value>| {
        for field in PARALLEL_INTEGER_FIELDS {
            if let Some(value) = fields.get_mut(*field) {
                whole(value);
            }
        }
    };
    let Some(fields) = command.as_object_mut() else {
        return;
    };
    normalize(fields);
    if let Some(Value::Array(path)) = fields.get_mut("parallel_group_path") {
        path.iter_mut().filter_map(Value::as_object_mut).for_each(normalize);
    }
}

/// `preflightParallelMetadata`'s errors across the batch: parallel fields on
/// a command that is not a parallel leaf, and a leaf's invalid metadata.
fn parallel_preflight(commands: &[Value]) -> Vec<(String, Vec<String>)> {
    let mut found = Vec::new();
    for (index, command) in commands.iter().enumerate() {
        let Some(kind) = command.get("type").and_then(Value::as_str) else {
            continue;
        };
        if PARALLEL_LEAVES.contains(&kind) {
            found.extend(parallel_metadata_error(index, kind, command));
            continue;
        }
        for field in PARALLEL_FIELDS.iter().filter(|field| has_value(command, field)) {
            found.push((format!("commands.{index}.{field}"),
                vec![format!("{field} is only supported for activity, timer, and child-workflow scheduling commands.")]));
        }
    }
    found
}

fn has_value(command: &Value, field: &str) -> bool {
    command.get(field).is_some_and(|value| !value.is_null())
}

/// `WorkerController::validateWorkflowTaskCommandScopes`, which PHP runs
/// after the integer normalization and before any guard.
fn controller_scopes(commands: &[Value]) -> Vec<(String, Vec<String>)> {
    let mut found: Vec<(String, Vec<String>)> = Vec::new();
    let mut add = |field: String, message: String| match found.iter_mut().find(|(seen, _)| *seen == field) {
        Some((_, messages)) => messages.push(message),
        None => found.push((field, vec![message])),
    };
    let int = |command: &Value, field: &str| command.get(field).and_then(Value::as_i64);
    for (index, command) in commands.iter().enumerate() {
        let Some(kind) = command.get("type").and_then(Value::as_str) else {
            continue;
        };
        let at = |field: &str| format!("commands.{index}.{field}");
        if has_value(command, "retry_policy")
            && !matches!(kind, "schedule_activity" | "record_local_activity" | "prepare_local_activity" | "start_child_workflow")
        {
            add(at("retry_policy"), "retry_policy is only supported for schedule_activity and start_child_workflow commands.".into());
        }
        for field in ["start_to_close_timeout", "schedule_to_start_timeout", "schedule_to_close_timeout", "heartbeat_timeout"] {
            if has_value(command, field) && !matches!(kind, "schedule_activity" | "record_local_activity" | "prepare_local_activity") {
                add(at(field), format!("{field} is only supported for schedule_activity commands."));
            }
        }
        if has_value(command, "worker_session") && kind != "schedule_activity" {
            add(at("worker_session"), "worker_session is only supported for schedule_activity commands.".into());
        }
        for field in ["execution_timeout_seconds", "run_timeout_seconds"] {
            if has_value(command, field) && kind != "start_child_workflow" {
                add(at(field), format!("{field} is only supported for start_child_workflow commands."));
            }
        }
        if has_value(command, "cancellation_policy")
            && !matches!(kind, "start_child_workflow" | "schedule_activity" | "prepare_local_activity")
        {
            add(at("cancellation_policy"),
                "cancellation_policy is only supported for start_child_workflow, schedule_activity or prepared local Activity commands.".into());
        }
        if has_value(command, "non_retryable") && !matches!(kind, "fail_workflow" | "fail_update" | "record_local_activity") {
            add(at("non_retryable"), "non_retryable is only supported for fail_workflow and fail_update commands.".into());
        }
        if has_value(command, "exception") && kind != "fail_workflow" {
            add(at("exception"), "exception is only supported for fail_workflow commands.".into());
        }
        for field in ["failed_step_sequence", "failed_activity_execution_id"] {
            if has_value(command, field) && kind != "fail_workflow" {
                add(at(field), format!("{field} is only supported for fail_workflow commands."));
            }
        }
        if kind == "fail_workflow" && has_value(command, "failed_step_sequence") != has_value(command, "failed_activity_execution_id") {
            add(at("failed_step_sequence"),
                "Uncaught activity failure identity requires both failed_step_sequence and failed_activity_execution_id.".into());
        }
        if matches!(kind, "schedule_activity" | "record_local_activity" | "prepare_local_activity") {
            // `validateActivityTimeoutEnvelope`.
            let (start, queue, close, heartbeat) = (int(command, "start_to_close_timeout"), int(command, "schedule_to_start_timeout"),
                int(command, "schedule_to_close_timeout"), int(command, "heartbeat_timeout"));
            if let (Some(heartbeat), Some(start)) = (heartbeat, start) && heartbeat > start {
                add(at("heartbeat_timeout"), "heartbeat_timeout cannot exceed start_to_close_timeout.".into());
            }
            if let (Some(start), Some(close)) = (start, close) && start > close {
                add(at("start_to_close_timeout"), "start_to_close_timeout cannot exceed schedule_to_close_timeout.".into());
            }
            if let (Some(queue), Some(close)) = (queue, close) && queue > close {
                add(at("schedule_to_start_timeout"), "schedule_to_start_timeout cannot exceed schedule_to_close_timeout.".into());
            }
        }
        if kind == "start_child_workflow"
            && let (Some(execution), Some(run)) = (int(command, "execution_timeout_seconds"), int(command, "run_timeout_seconds"))
            && run > execution
        {
            add(at("run_timeout_seconds"), "run_timeout_seconds cannot exceed execution_timeout_seconds.".into());
        }
    }
    found
}

/// `WorkflowCommandNormalizer::FIELD_SCOPES`: each field, the commands it
/// applies to, and the guidance PHP gives elsewhere.
const FIELD_SCOPES: &[(&str, &[&str], &str)] = &[
    ("retry_policy", &["schedule_activity", "record_local_activity", "start_child_workflow"],
        "Configure activity retries on a schedule_activity command, or child workflow retries on a start_child_workflow command. Workflow failure itself is non-retryable, and SDK HTTP transport retry is a client concern that does not appear in the workflow task command stream."),
    ("start_to_close_timeout", &["schedule_activity", "record_local_activity"],
        "start_to_close_timeout limits one activity attempt and only applies to a schedule_activity command."),
    ("schedule_to_start_timeout", &["schedule_activity"],
        "schedule_to_start_timeout limits queue wait before an activity attempt starts and only applies to a schedule_activity command."),
    ("schedule_to_close_timeout", &["schedule_activity", "record_local_activity"],
        "schedule_to_close_timeout limits the entire activity execution including retries and only applies to a schedule_activity command."),
    ("heartbeat_timeout", &["schedule_activity", "record_local_activity"],
        "heartbeat_timeout limits the gap between activity heartbeats and only applies to a schedule_activity command."),
    ("worker_session", &["schedule_activity"],
        "worker_session pins a sequence of activity attempts to one worker and only applies to a schedule_activity command."),
    ("execution_timeout_seconds", &["start_child_workflow"],
        "execution_timeout_seconds limits the entire child workflow execution and only applies to a start_child_workflow command."),
    ("run_timeout_seconds", &["start_child_workflow"],
        "run_timeout_seconds limits one child workflow run and only applies to a start_child_workflow command."),
    ("non_retryable", &["fail_workflow", "fail_update", "record_local_activity"],
        "non_retryable marks a workflow, update, or recorded local-activity failure as non-retryable. Durable activity non-retryable error types belong inside the schedule_activity retry_policy.non_retryable_error_types list."),
    ("timeout_kind", &["record_local_activity"],
        "timeout_kind identifies the bounded timeout that ended a recorded local activity and only applies to a record_local_activity command."),
    ("exception", &["fail_workflow"],
        "exception carries the structured terminal workflow failure payload and only applies to a fail_workflow command."),
    ("failed_step_sequence", &["fail_workflow"],
        "failed_step_sequence identifies an uncaught activity failure and only applies to a fail_workflow command."),
    ("failed_activity_execution_id", &["fail_workflow"],
        "failed_activity_execution_id identifies an uncaught activity failure and only applies to a fail_workflow command."),
    ("parent_close_policy", &["start_child_workflow"],
        "parent_close_policy declares how a child workflow reacts when its parent closes and only applies to a start_child_workflow command."),
    ("cancellation_policy", &["start_child_workflow", "schedule_activity"],
        "cancellation_policy declares how an awaiting workflow handles child or remote activity cancellation. Explicit activity policies require protocol 1.20 and remote abandon requires a finite schedule_to_close_timeout."),
    ("cancellation_scope_id", &["schedule_activity", "start_timer", "start_child_workflow", "open_signal_wait", "open_condition_wait"],
        "cancellation_scope_id records activity, timer, child or wait membership and requires candidate protocol 1.20."),
    ("cancellation_cleanup", &["start_timer"],
        "Shielded scoped timers require the original scope, request and delivery identities under candidate protocol 1.20."),
    ("delay_seconds", &["start_timer"], "delay_seconds is the timer delay and only applies to a start_timer command."),
    ("timeout_seconds", &["open_condition_wait", "open_signal_wait"],
        "timeout_seconds only applies to open_condition_wait or open_signal_wait. For activities use start_to_close_timeout / schedule_to_start_timeout / schedule_to_close_timeout / heartbeat_timeout; for child workflows use execution_timeout_seconds / run_timeout_seconds."),
    ("payload_codec", &["complete_workflow", "schedule_activity", "start_child_workflow", "continue_as_new", "complete_update",
        "record_side_effect", "record_local_activity", "start_service_operation"],
        "payload_codec identifies the codec used for command payload bytes and only applies to commands that carry result, arguments, or request_payload bytes."),
    ("entries", &["upsert_memo"], "entries contains the non-indexed memo patch and only applies to an upsert_memo command."),
];

/// `assertCommandFieldScope`: each field this command type does not take.
pub(super) fn field_scope_errors(index: usize, command: &Value) -> Vec<(String, Vec<String>)> {
    let Some(kind) = command.get("type").and_then(Value::as_str) else {
        return Vec::new();
    };
    let mut found = Vec::new();
    for (field, allowed, guidance) in FIELD_SCOPES {
        // A marker's wire shape is frozen; PHP drops its payload codec.
        if !has_value(command, field) || allowed.contains(&kind) || kind == "record_version_marker" && *field == "payload_codec" {
            continue;
        }
        found.push((format!("commands.{index}.{field}"),
            vec![format!("Workflow task command field [{field}] is not valid on a {kind} command. {guidance}")]));
    }
    found
}

/// The command normalizer's per-command checks native shares with PHP:
/// `assertCommandFieldScope`, then the timer, version marker and wait rules.
/// PHP collects these across the batch; native stops at the first command.
pub(super) fn normalizer_checks(index: usize, command: &Value) -> Result<()> {
    let Some(kind) = command.get("type").and_then(Value::as_str) else {
        return Ok(());
    };
    let mut found = field_scope_errors(index, command);
    let whole = |field: &str| command.get(field).and_then(Value::as_i64);
    match kind {
        "start_timer" if !whole("delay_seconds").is_some_and(|delay| delay >= 0) => found.push((
            format!("commands.{index}.delay_seconds"),
            vec!["Start timer commands require a non-negative integer delay_seconds.".into()],
        )),
        "open_signal_wait" | "open_condition_wait"
            if has_value(command, "timeout_seconds") && !whole("timeout_seconds").is_some_and(|seconds| seconds >= 0) =>
        {
            let wait = if kind == "open_signal_wait" { "signal" } else { "condition" };
            found.push((format!("commands.{index}.timeout_seconds"),
                vec![format!("Open {wait} wait timeout_seconds must be a non-negative integer when provided.")]));
        }
        "record_version_marker" => {
            let mut messages = Vec::new();
            if !command.get("change_id").and_then(Value::as_str).is_some_and(|id| !id.trim().is_empty()) {
                messages.push("change_id is required".to_owned());
            }
            for field in ["version", "min_supported", "max_supported"] {
                if whole(field).is_none() {
                    messages.push(format!("{field} must be an integer"));
                }
            }
            if !messages.is_empty() {
                found.push((format!("commands.{index}"), messages));
            }
        }
        _ => {}
    }
    match super::laravel_validation::failure_messages(found) {
        Some(error) => Err(error),
        None => Ok(()),
    }
}

/// `WorkflowCommandNormalizer::optionalParallelMetadata`'s refusal for a
/// leaf command: incomplete top-level metadata or path, an invalid path
/// entry, or an innermost entry that differs from the top level.
fn parallel_metadata_error(index: usize, kind: &str, command: &Value) -> Option<(String, Vec<String>)> {
    let leaf = match kind {
        "schedule_activity" => "activity",
        "start_timer" => "timer",
        "start_child_workflow" => "child",
        "open_condition_wait" => "condition",
        "open_signal_wait" => "signal",
        _ => return None,
    };
    if !PARALLEL_FIELDS.iter().any(|field| command.get(*field).is_some()) {
        return None;
    }
    let refuse = |field: String, message: &str| Some((field, vec![message.to_owned()]));
    let top = parallel_entry(command, leaf);
    let path = command.get("parallel_group_path").and_then(Value::as_array).filter(|path| !path.is_empty());
    let (Some(top), Some(path)) = (top, path) else {
        return refuse(format!("commands.{index}.parallel_group_path"),
            "Parallel workflow commands require complete top-level metadata and a non-empty group path.");
    };
    let mut innermost = None;
    for (position, entry) in path.iter().enumerate() {
        innermost = parallel_entry(entry, leaf);
        if innermost.is_none() {
            return refuse(format!("commands.{index}.parallel_group_path.{position}"),
                "Each parallel group path entry must contain valid identity, kind, base, size, and index fields.");
        }
    }
    (innermost != Some(top)).then(|| (format!("commands.{index}.parallel_group_path"),
        vec!["The innermost parallel group path entry must match the top-level parallel metadata.".to_owned()]))
}

/// `parallelMetadataEntry`: the entry's normalized fields, or `None` when
/// PHP refuses it. A group's size is bounded by the command batch size.
fn parallel_entry(value: &Value, leaf: &str) -> Option<Vec<(&'static str, Value)>> {
    let text = |field: &str| value.get(field).and_then(Value::as_str);
    let int = |field: &str| value.get(field).filter(|value| value.is_i64() || value.is_u64()).and_then(Value::as_i64);
    let id = text("parallel_group_id").filter(|id| !id.is_empty())?;
    let kind = text("parallel_group_kind").filter(|kind| *kind == leaf || *kind == "mixed")?;
    let base = int("parallel_group_base_sequence").filter(|base| *base >= 1)?;
    let size = int("parallel_group_size").filter(|size| (1..=super::memo_limits::COMMAND_BATCH_SIZE).contains(size))?;
    let index = int("parallel_group_index").filter(|index| (0..size).contains(index))?;
    let mode = match value.get("parallel_group_mode") {
        None | Some(Value::Null) => "all",
        Some(mode) => mode.as_str().filter(|mode| matches!(*mode, "all" | "select"))?,
    };
    let prefix = match (mode, kind) {
        ("select", _) => "select-calls",
        (_, "activity") => "parallel-activities",
        (_, "child") => "parallel-children",
        (_, "timer") => "parallel-timers",
        _ => "parallel-calls",
    };
    if id != format!("{prefix}:{base}:{size}") {
        return None;
    }
    let mut entry = vec![("parallel_group_id", json!(id)), ("parallel_group_kind", json!(kind))];
    if mode == "select" {
        // `validSelectionKey`: a non-empty string or a non-negative PHP
        // integer; a larger JSON number decodes to a float in PHP.
        let key = value.get("selection_member_key")
            .filter(|key| key.as_str().is_some_and(|key| !key.is_empty()) || key.as_i64().is_some_and(|key| key >= 0))?;
        let member_index = int("selection_member_index").filter(|index| *index >= 0)?;
        // PHP's sums past the integer range become floats that fail these
        // bounds; checked sums refuse them the same way.
        let end = base.checked_add(size)?;
        let member_base = int("selection_member_base_sequence").filter(|member| (base..end).contains(member))?;
        let member_size = int("selection_member_size")
            .filter(|member| *member >= 1 && member_base.checked_add(*member).is_some_and(|last| last <= end))?;
        let member_kind = text("selection_member_kind")
            .filter(|kind| matches!(*kind, "activity" | "child" | "timer" | "signal" | "condition" | "group"))?;
        entry.extend([("parallel_group_mode", json!("select")), ("parallel_group_base_sequence", json!(base)),
            ("parallel_group_size", json!(size)), ("parallel_group_index", json!(index)),
            ("selection_member_key", key.clone()), ("selection_member_index", json!(member_index)),
            ("selection_member_base_sequence", json!(member_base)), ("selection_member_size", json!(member_size)),
            ("selection_member_kind", json!(member_kind))]);
    } else {
        entry.extend([("parallel_group_base_sequence", json!(base)), ("parallel_group_size", json!(size)),
            ("parallel_group_index", json!(index))]);
    }
    Some(entry)
}

/// The payload fields whose bare string PHP's normalizer takes as the blob
/// itself, in the run's codec (`resolveCommandPayloadWithCodec`).
const BLOB_FIELDS: &[(&str, &str)] = &[
    ("complete_workflow", "result"),
    ("schedule_activity", "arguments"),
    ("start_child_workflow", "arguments"),
    ("continue_as_new", "arguments"),
    ("complete_update", "result"),
];

/// The fields `WorkflowCommandNormalizer::normalize` reads for each command
/// type native runs. PHP ignores any other field, apart from the ones its
/// scope checks refuse on the wrong command.
const READS: &[(&str, &[&str])] = &[
    ("complete_workflow", &["result", "payload_codec"]),
    ("fail_workflow", &["message", "exception_class", "exception_type", "non_retryable", "exception",
        "failed_step_sequence", "failed_activity_execution_id"]),
    ("schedule_activity", &["activity_type", "arguments", "payload_codec", "connection", "queue", "retry_policy",
        "start_to_close_timeout", "schedule_to_start_timeout", "schedule_to_close_timeout", "heartbeat_timeout",
        "cancellation_policy", "worker_session"]),
    ("start_timer", &["delay_seconds", "cancellation_cleanup"]),
    ("start_child_workflow", &["workflow_type", "arguments", "payload_codec", "connection", "queue", "retry_policy",
        "execution_timeout_seconds", "run_timeout_seconds", "parent_close_policy", "cancellation_policy"]),
    ("continue_as_new", &["arguments", "payload_codec", "workflow_type", "queue"]),
    ("complete_update", &["update_id", "result", "payload_codec"]),
    ("fail_update", &["update_id", "message", "exception_class", "exception_type", "non_retryable"]),
    ("record_side_effect", &["result", "payload_codec"]),
    ("record_version_marker", &["change_id", "version", "min_supported", "max_supported"]),
    ("upsert_search_attributes", &["attributes", "attribute_types"]),
    ("upsert_memo", &["entries"]),
    ("open_condition_wait", &["condition_key", "condition_definition_fingerprint", "condition_wait_occurrence_id", "timeout_seconds"]),
    ("open_signal_wait", &["signal_name", "timeout_seconds"]),
];

/// `parallelMetadataValidationRules`' fields, read for the leaf commands.
const PARALLEL_FIELDS: &[&str] = &["parallel_group_id", "parallel_group_kind", "parallel_group_mode",
    "parallel_group_base_sequence", "parallel_group_size", "parallel_group_index", "selection_member_key",
    "selection_member_index", "selection_member_base_sequence", "selection_member_size", "selection_member_kind",
    "parallel_group_path"];
const PARALLEL_LEAVES: &[&str] = &["schedule_activity", "start_timer", "start_child_workflow", "open_condition_wait", "open_signal_wait"];

/// Fields a scope check refuses on the wrong command, so they must reach it.
const SCOPED_FIELDS: &[&str] = &["retry_policy", "start_to_close_timeout", "schedule_to_start_timeout", "schedule_to_close_timeout",
    "heartbeat_timeout", "worker_session", "execution_timeout_seconds", "run_timeout_seconds", "cancellation_policy",
    "non_retryable", "exception", "failed_step_sequence", "failed_activity_execution_id", "timeout_kind",
    "parent_close_policy", "cancellation_scope_id", "cancellation_cleanup", "delay_seconds", "timeout_seconds",
    "payload_codec", "entries", "principal_subject", "principal_method", "principal_roles", "principal_tenant",
    "principal_claims"];

/// `optionalRetryPolicy` reads these keys and ignores the rest.
const RETRY_POLICY_FIELDS: &[&str] = &["max_attempts", "backoff_seconds", "non_retryable_error_types"];

/// Drops what PHP ignores before native's own field checks: on a command
/// type native runs, a field PHP's normalizer neither reads nor scope-checks,
/// and an unknown retry policy key. A field PHP reads stays, so native still
/// refuses one it cannot run.
fn ignored_fields(command: &mut Value) {
    let Some(kind) = command.get("type").and_then(Value::as_str).map(str::to_owned) else {
        return;
    };
    let Some((_, reads)) = READS.iter().find(|(owner, _)| *owner == kind) else {
        return;
    };
    let Some(fields) = command.as_object_mut() else {
        return;
    };
    let parallel = PARALLEL_LEAVES.contains(&kind.as_str());
    fields.retain(|field, value| {
        field == "type"
            || reads.contains(&field.as_str())
            || parallel && PARALLEL_FIELDS.contains(&field.as_str())
            || SCOPED_FIELDS.contains(&field.as_str()) && !value.is_null()
    });
    if let Some(Value::Object(policy)) = fields.get_mut("retry_policy") {
        policy.retain(|key, _| RETRY_POLICY_FIELDS.contains(&key.as_str()));
    }
}

/// Gives each payload the envelope form native applies, as PHP's normalizer
/// reads it: a bare string is an Avro blob, an empty one is no payload, and
/// continue-as-new arguments that are plain values are no arguments, so the
/// run continues with its own. Runs after the payload checks, which name the
/// field as the worker sent it.
pub(super) fn canonical_payloads(mut body: Value) -> Result<Value> {
    let Some(commands) = body["commands"].as_array_mut() else {
        return Ok(body);
    };
    for (index, command) in commands.iter_mut().enumerate() {
        ignored_fields(command);
        let Some(kind) = command["type"].as_str().map(str::to_owned) else {
            continue;
        };
        let avro = command.get("payload_codec").is_none_or(|codec| codec.is_null() || codec == "avro");
        for (_, field) in BLOB_FIELDS.iter().filter(|(owner, _)| *owner == kind) {
            match command.get(*field) {
                Some(Value::String(blob)) if blob.is_empty() => command[*field] = Value::Null,
                Some(Value::String(blob)) if avro => command[*field] = serde_json::json!({"codec":"avro","blob":blob}),
                _ => {}
            }
        }
        if matches!(kind.as_str(), "continue_as_new" | "start_child_workflow") {
            match command.get("arguments") {
                Some(Value::Array(_)) => command["arguments"] = Value::Null,
                Some(Value::Object(fields)) if !fields.contains_key("codec") => command["arguments"] = Value::Null,
                Some(Value::Number(_) | Value::Bool(_)) => {
                    return Err(super::command_payloads::invalid(
                        &format!("commands.{index}.arguments"),
                        "Workflow task command field [arguments] must be a string or a payload envelope when provided.",
                    ));
                }
                _ => {}
            }
        }
    }
    Ok(body)
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn refusal(body: Value) -> Value {
        match checked(body) {
            Err(super::super::RuntimeError::Protocol { response, .. }) => response,
            other => panic!("{other:?}"),
        }
    }

    fn base(commands: Value) -> Value {
        json!({"lease_owner":"w","workflow_task_attempt":1,"commands":commands})
    }

    #[test]
    fn errors_follow_php_s_rule_order_names_and_chunks() {
        let response = refusal(base(json!([{"type":"schedule_activity","attempts":[{}, {"attempt_number":0,"outcome":"x",
            "heartbeats":[{"elapsed_ms":-1,"details":"x"}]}]}])));
        assert_eq!(response["message"], "The commands.0.attempts.0.attempt_number field is required. (and 5 more errors)");
        assert_eq!(response["errors"]["commands.0.attempts.1.outcome"], json!(["The selected commands.0.attempts.1.outcome is invalid."]));
        let response = refusal(json!({"lease_owner":"w","workflow_task_attempt":1,"commands":[{"type":"start_timer","delay_seconds":1}],
            "sticky_cache":{"worker_id":"w"}}));
        assert_eq!(response["message"], "The sticky cache.workflow id field is required when sticky cache is present. (and 3 more errors)");
        let response = refusal(base(json!([{"type":"start_timer","delay_seconds":1,"cancellation_cleanup":{"scope_id":"a"}}])));
        assert_eq!(response["errors"]["commands.0.cancellation_cleanup.request_id"],
            json!(["The commands.0.cancellation_cleanup.request_id field is required when commands.0.cancellation_cleanup is present."]));
        let response = refusal(base(json!([{"type":"start_timer","delay_seconds":1,"cancellation_cleanup":
            {"scope_id":"a","request_id":"b","delivery_history_event_id":"c","extra":1}}])));
        assert_eq!(response["message"], "The commands.0.cancellation_cleanup field must be an array.");
        let response = refusal(base(json!([{"type":"start_timer","delay_seconds":1,"cancellation_scope_id":null}])));
        assert_eq!(response["errors"]["commands.0.cancellation_scope_id"], json!(["The commands.0.cancellation_scope_id field must be a string.",
            "The commands.0.cancellation_scope_id field must be at least 1 characters."]));
        let response = refusal(base(json!([{"type":"complete_workflow","principal_subject":"x","principal_roles":null}])));
        assert_eq!(response["message"], "The commands.0.principal_subject field is prohibited.");
        // A later chunk's errors follow the earlier chunk's, whatever the rule.
        let mut commands = vec![json!({"type":"record_side_effect"}); 30];
        commands[3]["member_index"] = json!(-1);
        commands[26] = json!({"type":5});
        commands[29] = json!({"type":"start_timer","delay_seconds":-1});
        let response = refusal(base(Value::Array(commands)));
        assert_eq!(response["message"], "The commands.3.member_index field must be at least 0. (and 2 more errors)");
        assert_eq!(refusal(base(json!([])))["message"], "The commands field is required.");
        assert_eq!(refusal(base(json!(["x"])))["message"], "The commands.0.type field is required.");
        assert_eq!(refusal(json!({"workflow_task_attempt":"x","commands":[{"type":"a"}]}))["message"],
            "The lease owner field is required. (and 1 more error)");
        // Server #774: a workflow stream's producer controls are bounded.
        let stream = |field: &str, value: i64| base(json!([{"type":"record_side_effect","workflow_stream":{"operation":"append",
            "stream_name":"tokens","command_identity":"c","command_ordinal":0,field:value}}]));
        assert_eq!(refusal(stream("max_pending_items", 10_001))["errors"]["commands.0.workflow_stream.max_pending_items"],
            json!(["The commands.0.workflow_stream.max_pending_items field must not be greater than 10000."]));
        assert_eq!(refusal(stream("retention_seconds", 2_592_001))["errors"]["commands.0.workflow_stream.retention_seconds"],
            json!(["The commands.0.workflow_stream.retention_seconds field must not be greater than 2592000."]));
        assert!(checked(stream("max_pending_items", 10_000)).is_ok());
        assert!(checked(stream("retention_seconds", 2_592_000)).is_ok());
    }

    #[test]
    fn digit_strings_become_integers_once_the_rules_pass() {
        let body = checked(json!({"lease_owner":"w","workflow_task_attempt":"2","commands":[{"type":"start_timer","delay_seconds":"7"},
            {"type":"schedule_activity","heartbeat_timeout":"10","retry_policy":{"max_attempts":"3","backoff_seconds":["1",2]},
                "worker_session":{"ttl_seconds":"30"}},{"type":"start_timer","delay_seconds":5.0}],"unknown":1})).unwrap();
        assert_eq!(body["workflow_task_attempt"], 2);
        assert_eq!(body["commands"][0]["delay_seconds"], 7);
        assert_eq!(body["commands"][1]["heartbeat_timeout"], 10);
        assert_eq!(body["commands"][1]["retry_policy"], json!({"max_attempts":3,"backoff_seconds":[1,2]}));
        assert_eq!(body["commands"][1]["worker_session"]["ttl_seconds"], 30);
        // A float stays a float, as PHP leaves it.
        assert_eq!(body["commands"][2]["delay_seconds"], 5.0);
    }
}
