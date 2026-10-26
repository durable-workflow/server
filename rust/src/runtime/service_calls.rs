//! Nexus service-call execution, as published Server 2.11.1 serves it
//! (`ServiceCatalogController::executeOperation`, `serviceCallShow` and
//! `serviceCallCancel` over Workflow 2.10.2's `DefaultServiceControlPlane`).
//!
//! An operator executes a registered operation. The request is validated as
//! Laravel does, then admitted at the service boundary, which writes an audit
//! row to `workflow_service_calls` for every decision:
//! - authorization (required roles, caller-namespace deny and allow lists, on
//!   the endpoint, service and operation policies) and the circuit breaker;
//! - the shared admission limits: a per-minute rate and a concurrency limit
//!   per caller boundary refuse 429 once spent; an invalid limit answers 503
//!   `service_boundary_admission_unavailable`, as PHP does.
//!
//! An admitted `activity_execution` or `invocable_http` operation is then
//! dispatched: the call stays `accepted`, stamped with an activity execution
//! or carrier request id (an uppercase ULID unless the binding names one).
//! A binding with no target is a handler failure, retried in the request as
//! the operation's `retry_policy` allows. The execute `arguments` are checked
//! and then dropped, as PHP drops them for these kinds.
//!
//! A deadline policy sets `call_deadline_at` at admission. An open call past
//! its deadline fails as `timed_out` on the next execute, replay, show or
//! cancel that reads it. A repeated idempotency key replays the first call,
//! including a rejected one, after re-checking authorization and the circuit.
//!
//! Responses merge the controller's call view with the control plane's, as
//! `array_replace` does. Show builds the controller half from the row as
//! read before a lazy expiry, as PHP does.
//!
//! Not in this slice, refused 501 `rust_capability_not_implemented` before
//! anything is written:
//! - a workflow-bound handler kind (start, signal, update, query workflow);
//! - an `external_payload` reference in `arguments`;
//! - cancelling a call whose policy propagates to a linked workflow.
//!
//! Differences:
//! - Native writes the audit row and the dispatch stamps in one serialized
//!   transaction, where PHP commits each step on its own. The final rows are
//!   the same; a retry backoff is waited out with the transaction committed.
//! - A call's deadline lives in the native-owned `dw_service_call_deadlines`,
//!   not in PHP 2.11.1's `workflow_service_calls.call_deadline_at` column:
//!   native keeps the frozen PHP tables and its own relations, so takeover
//!   layouts stay recognisable. Takeover still refuses a source with service
//!   calls; accepting one will copy their deadlines.
//! - The shared admission counters PHP keeps in its cache live in the
//!   native-owned `dw_service_admission_counters`, counted under the
//!   transition lock and written in the call's transaction: a per-minute rate
//!   count that lives 120 s, and a concurrency lease that lives a day. Only a
//!   sync call returns its lease when the request ends; an async call's stays
//!   until it expires, as PHP's does. The namespace-wide limits and hard
//!   ceilings run only at their default of none.
use super::laravel_rules::{self, Rule};
use super::service_endpoints::{Endpoint, Operation, Service, deadline_instant, deadline_policy_error, php_document};
use super::{Result, RuntimeError};
use axum::http::StatusCode;
use chrono::{DateTime, Utc};
use serde_json::{Map, Value, json};

const MODES: &[&str] = &["sync", "async"];

/// `executeOperation`'s rules, in PHP's order.
const EXECUTE_RULES: &laravel_rules::Rules = &[
    ("arguments", &[Rule::Nullable]),
    ("payload_codec", &[Rule::Nullable, Rule::String, Rule::Max(191)]),
    ("mode_override", &[Rule::Nullable, Rule::String, Rule::In(MODES)]),
    ("wait_for", &[Rule::Nullable, Rule::String, Rule::In(&["accepted", "completed"])]),
    ("wait_timeout_seconds", &[Rule::Nullable, Rule::Integer, Rule::Min(0), Rule::Max(86400)]),
    ("idempotency_key", &[Rule::Nullable, Rule::String, Rule::Max(191)]),
    ("caller_namespace", &[Rule::Nullable, Rule::String, Rule::Max(255)]),
    ("caller_workflow_instance_id", &[Rule::Nullable, Rule::String, Rule::Max(191)]),
    ("caller_workflow_run_id", &[Rule::Nullable, Rule::String, Rule::Max(26)]),
    ("target_workflow_instance_id", &[Rule::Nullable, Rule::String, Rule::Max(191)]),
    ("target_workflow_run_id", &[Rule::Nullable, Rule::String, Rule::Max(26)]),
    ("connection", &[Rule::Nullable, Rule::String, Rule::Max(191)]),
    ("queue", &[Rule::Nullable, Rule::String, Rule::Max(191)]),
    ("business_key", &[Rule::Nullable, Rule::String, Rule::Max(191)]),
    ("labels", &[Rule::Nullable, Rule::Array]),
    ("memo", &[Rule::Nullable, Rule::Array]),
    ("search_attributes", &[Rule::Nullable, Rule::Array]),
    ("duplicate_start_policy", &[Rule::Nullable, Rule::String, Rule::In(&["reject_duplicate", "return_existing_active"])]),
];

/// `serviceCallCancel`'s rule.
const CANCEL_RULES: &laravel_rules::Rules = &[("reason", &[Rule::Nullable, Rule::String, Rule::Max(1000)])];

/// The rules the service boundary reads: the package defaults with the
/// server's, which native runs only at their defaults (`DW_SERVICE_BOUNDARY_*`
/// are refused at startup otherwise).
fn global_rules() -> Value {
    json!({
        "default_action": "allow",
        "authorization": {"required_roles": []},
        "namespaces": {"allow_callers": null, "deny_callers": [], "cross_namespace_default": "allow"},
        "rate_limit": {"requests_per_minute": null, "retry_after_seconds": 1, "sync_only": false},
        "concurrency": {"max_in_flight": null, "retry_after_seconds": 1, "sync_only": true},
        "circuit_break": {"open_targets": [], "retry_after_seconds": 30},
    })
}

const SHARED_POLICY: &str = "server.shared-service-boundary";
const DEFAULT_POLICY: &str = "workflow.default-service-boundary";
const TIMED_OUT: &str = "Service call deadline expired.";

// ---------------------------------------------------------------------------
// PHP value helpers.

/// `stringFrom`: a trimmed, non-empty string.
fn string_from(value: &Value) -> Option<String> {
    value.as_str().map(str::trim).filter(|text| !text.is_empty()).map(str::to_owned)
}

/// `is_array`, as PHP reads a decoded JSON value.
fn is_array(value: &Value) -> bool {
    value.is_array() || value.is_object()
}

/// `arrayValue`: the value if it is an array, else `[]`.
fn array_value(value: &Value) -> Value {
    if is_array(value) { value.clone() } else { json!([]) }
}

/// `arrayValueOrNull`.
fn array_or_null(value: &Value) -> Value {
    if is_array(value) { value.clone() } else { Value::Null }
}

/// A PHP array's entries, keyed as PHP keys them.
fn entries(value: &Value) -> Vec<(String, Value)> {
    match value {
        Value::Object(fields) => fields.iter().map(|(key, value)| (key.clone(), value.clone())).collect(),
        Value::Array(items) => items.iter().enumerate().map(|(index, value)| (index.to_string(), value.clone())).collect(),
        _ => Vec::new(),
    }
}

/// A PHP array's values, in order (`array_values`).
fn values(value: &Value) -> Vec<Value> {
    entries(value).into_iter().map(|(_, value)| value).collect()
}

/// `$array[$key] ?? null`.
fn at<'a>(value: &'a Value, key: &str) -> &'a Value {
    match value {
        Value::Object(fields) => fields.get(key).unwrap_or(&Value::Null),
        Value::Array(items) => key.parse::<usize>().ok().and_then(|index| items.get(index)).unwrap_or(&Value::Null),
        _ => &Value::Null,
    }
}

/// `array_is_list` for a non-empty associative array: PHP reads an object
/// keyed 0..n as a list, which `php_document` has already made one.
fn is_assoc(value: &Value) -> bool {
    value.as_object().is_some_and(|fields| !fields.is_empty())
}

/// `ServiceBoundaryRequest::mergePolicy`: associative arrays merge key by
/// key, recursively; lists, scalars and nulls replace.
fn merge_policy(policies: &[&Value]) -> Value {
    let mut merged = Map::new();
    for policy in policies {
        for (key, value) in entries(policy) {
            let nested = is_assoc(&value) && merged.get(&key).is_some_and(is_assoc);
            let value = if nested { merge_policy(&[&merged[&key], &value]) } else { value };
            merged.insert(key, value);
        }
    }
    php_document(Value::Object(merged))
}

/// `mergeMetadata`: null when both sides are empty.
fn merge_metadata(metadata: &Value, extra: &Value) -> Value {
    let base = array_value(metadata);
    if values(&base).is_empty() && values(extra).is_empty() {
        return Value::Null;
    }
    merge_policy(&[&base, extra])
}

/// PHP's array union `$left + $right`: the left side's keys win.
fn union(left: &Value, right: Value) -> Value {
    let mut map = match right {
        Value::Object(map) => map,
        _ => Map::new(),
    };
    for (key, value) in entries(left) {
        map.insert(key, value);
    }
    Value::Object(map)
}

/// `withoutAdmissionControls`: the rate and concurrency rules removed at
/// every associative level.
fn without_admission_controls(policy: &Value) -> Value {
    let mut stripped = Map::new();
    for (key, value) in entries(policy) {
        if matches!(key.as_str(), "rate_limit" | "concurrency" | "concurrency_limit") {
            continue;
        }
        let value = if is_assoc(&value) { without_admission_controls(&value) } else { value };
        stripped.insert(key, value);
    }
    php_document(Value::Object(stripped))
}

/// `arrayAt($source, [$key])`.
fn array_at(source: &Value, key: &str) -> Value {
    array_value(at(source, key))
}

/// `listAt`: the non-empty strings of the array at `path`, or none when the
/// path holds no array.
fn list_at(source: &Value, path: &[&str]) -> Option<Vec<String>> {
    let mut value = source;
    for part in path {
        if !is_array(value) {
            return None;
        }
        match value {
            Value::Object(fields) => value = fields.get(*part)?,
            Value::Array(items) => value = items.get(part.parse::<usize>().ok()?)?,
            _ => return None,
        }
    }
    is_array(value).then(|| {
        values(value)
            .into_iter()
            .filter_map(|entry| entry.as_str().filter(|text| !text.trim().is_empty()).map(str::to_owned))
            .collect()
    })
}

fn first_list(source: &Value, paths: &[&[&str]]) -> Option<Vec<String>> {
    paths.iter().find_map(|path| list_at(source, path))
}

/// PHP's `(int)` of a decoded JSON value.
fn php_int(value: &Value) -> i64 {
    match value {
        Value::Bool(flag) => i64::from(*flag),
        Value::Number(number) => number.as_i64().unwrap_or_else(|| {
            let float = number.as_f64().unwrap_or_default();
            if float.is_finite() { float.trunc().clamp(i64::MIN as f64, i64::MAX as f64) as i64 } else { 0 }
        }),
        Value::String(text) => php_string_int(text),
        Value::Array(items) => i64::from(!items.is_empty()),
        Value::Object(fields) => i64::from(!fields.is_empty()),
        Value::Null => 0,
    }
}

/// PHP's `(int)` of a string: its leading numeric prefix, after leading
/// whitespace, or 0.
fn php_string_int(text: &str) -> i64 {
    let text = text.trim_start_matches([' ', '\t', '\n', '\r', '\x0b', '\x0c']);
    let bytes = text.as_bytes();
    let mut end = usize::from(matches!(bytes.first(), Some(b'+' | b'-')));
    let digits = |from: usize| from + bytes[from..].iter().take_while(|byte| byte.is_ascii_digit()).count();
    let whole = digits(end);
    let mut fraction = whole;
    if bytes.get(whole) == Some(&b'.') {
        fraction = digits(whole + 1);
    }
    if fraction == end || (fraction == end + 1 && whole == end) {
        return 0;
    }
    end = fraction;
    if matches!(bytes.get(end), Some(b'e' | b'E')) {
        let sign = end + 1 + usize::from(matches!(bytes.get(end + 1), Some(b'+' | b'-')));
        let exponent = digits(sign);
        if exponent > sign {
            end = exponent;
        }
    }
    let prefix = &text[..end];
    prefix.parse::<i64>().unwrap_or_else(|_| {
        let float: f64 = prefix.parse().unwrap_or(0.0);
        if float.is_finite() { float.trunc().clamp(i64::MIN as f64, i64::MAX as f64) as i64 } else { 0 }
    })
}

/// `positiveInt`: an int, or a string of digits, above zero.
fn positive_int(value: &Value) -> Option<i64> {
    match value {
        Value::Number(number) => number.as_i64().filter(|whole| *whole > 0),
        Value::String(text) if !text.trim().is_empty() && text.trim().bytes().all(|byte| byte.is_ascii_digit()) => {
            super::php_value::int_cast(text.trim()).filter(|whole| *whole > 0)
        }
        _ => None,
    }
}

/// `numericValue`: an int, a float, or a numeric string.
fn numeric_value(value: &Value) -> Option<f64> {
    match value {
        Value::Number(number) => number.as_f64(),
        Value::String(text) => super::php_value::numeric(text.trim()),
        _ => None,
    }
}

/// `filter_var($text, FILTER_VALIDATE_INT)` for a string of digits.
fn validated_digits(text: &str) -> Option<i64> {
    (text == "0" || !text.starts_with('0')).then(|| text.parse().ok()).flatten()
}

// ---------------------------------------------------------------------------
// The service boundary.

/// The caller as the audit row records it (`ServiceCallPrincipal`).
#[derive(Clone, Debug)]
pub(crate) struct Caller {
    pub(crate) subject: String,
    pub(crate) method: String,
    pub(crate) roles: Vec<String>,
    pub(crate) tenant: Option<String>,
    pub(crate) claims: Value,
}

impl Caller {
    pub(crate) fn from_principal(principal: &super::runtime_credentials::Principal) -> Self {
        Self {
            subject: principal.subject.clone(),
            method: principal.method.to_owned(),
            roles: principal.roles.clone(),
            tenant: principal.tenant.clone(),
            claims: php_document(principal.claims.clone()),
        }
    }
}

/// `ServiceBoundaryDecision`.
#[derive(Clone, Debug)]
struct Decision {
    outcome: &'static str,
    reason: String,
    message: Option<String>,
    policy: String,
    retry_after: Option<i64>,
    metadata: Value,
}

impl Decision {
    fn allow(policy: &str, metadata: Value) -> Self {
        Self { outcome: "accepted", reason: "accepted".into(), message: None, policy: policy.into(), retry_after: None, metadata }
    }

    /// `denyAuthorization` and `denyNamespacePolicy`.
    fn forbidden(reason: &str, message: String, axis: &str) -> Self {
        Self {
            outcome: "rejected_forbidden",
            reason: reason.into(),
            message: Some(message),
            policy: DEFAULT_POLICY.into(),
            retry_after: None,
            metadata: json!({"failure_reason": "policy_rejection", "forbidden_axis": axis}),
        }
    }

    /// `SharedServiceBoundaryPolicy::unavailable`.
    fn unavailable() -> Self {
        Self {
            outcome: "rejected_throttled",
            reason: "service_boundary_admission_unavailable".into(),
            message: Some("Shared service-call admission could not be evaluated.".into()),
            policy: SHARED_POLICY.into(),
            retry_after: Some(1),
            metadata: json!({"failure_reason": "policy_rejection", "admission_scope": "shared_cache"}),
        }
    }

    fn allowed(&self) -> bool {
        self.outcome == "accepted"
    }

    /// `ServiceCallAdmission::httpStatus` for a denial.
    fn http_status(&self) -> StatusCode {
        if self.reason == "service_boundary_admission_unavailable" {
            return StatusCode::SERVICE_UNAVAILABLE;
        }
        match self.outcome {
            "rejected_forbidden" => StatusCode::FORBIDDEN,
            "rejected_not_found" => StatusCode::NOT_FOUND,
            "rejected_throttled" | "rejected_concurrency_limited" => StatusCode::TOO_MANY_REQUESTS,
            "rejected_circuit_open" => StatusCode::SERVICE_UNAVAILABLE,
            _ => StatusCode::CONFLICT,
        }
    }
}

/// `ServiceCallOutcome::category`.
fn category(outcome: &str) -> &'static str {
    match outcome {
        "accepted" => "accepted",
        "completed" | "cancelled" | "timed_out" | "degraded" | "handler_failed" => "handler",
        _ => "rejected",
    }
}

/// `ServiceCallBindingKind::tryFromHandlerBindingKind`.
fn binding_kind(handler_binding_kind: &str) -> Option<&'static str> {
    Some(match handler_binding_kind.trim().to_ascii_lowercase().as_str() {
        "workflow_run" | "start_workflow" | "workflow_class" => "workflow_run",
        "workflow_update" | "update_workflow" => "workflow_update",
        "workflow_signal" | "signal_workflow" => "workflow_signal",
        "workflow_query" | "query_workflow" => "workflow_query",
        "activity_execution" => "activity_execution",
        "invocable_carrier_request" | "invocable_http" => "invocable_carrier_request",
        _ => return None,
    })
}

/// `ServiceBoundaryRequest`, as `ServiceCallBoundary::requestFor` builds it.
struct BoundaryRequest<'a> {
    caller: &'a Caller,
    caller_namespace: String,
    target_namespace: String,
    endpoint: &'a Endpoint,
    service: &'a Service,
    operation: &'a Operation,
    mode: &'static str,
}

impl BoundaryRequest<'_> {
    fn boundary_key(&self) -> String {
        format!("{}|{}|{}|{}|{}", self.caller_namespace, self.target_namespace, self.endpoint.row.name,
            self.service.row.name, self.operation.row.name)
    }

    fn target_key(&self) -> String {
        format!("{}|{}|{}|{}", self.target_namespace, self.endpoint.row.name, self.service.row.name, self.operation.row.name)
    }

    fn policies(&self) -> [Value; 3] {
        [
            array_value(&self.endpoint.boundary_policy),
            array_value(&self.service.boundary_policy),
            array_value(self.operation.document("boundary_policy")),
        ]
    }

    fn effective_policy(&self) -> Value {
        let [endpoint, service, operation] = self.policies();
        merge_policy(&[&endpoint, &service, &operation])
    }

    /// `DefaultServiceBoundaryPolicy::evaluate` over the rules and request
    /// without admission controls: authorization, then the circuit.
    fn base_decision(&self) -> Decision {
        let rules = without_admission_controls(&global_rules());
        if let Some(denial) = self.authorization(&rules) {
            return denial;
        }
        let [endpoint, service, operation] = self.policies().map(|policy| without_admission_controls(&policy));
        let effective = merge_policy(&[&endpoint, &service, &operation]);
        let circuit = merge_policy(&[&array_at(&rules, "circuit_break"), &array_at(&effective, "circuit_break")]);
        let open_targets = list_at(&circuit, &["open_targets"]).unwrap_or_default();
        if at(&circuit, "open") == &Value::Bool(true) || open_targets.contains(&self.target_key()) {
            let retry = at(&circuit, "retry_after_seconds");
            return Decision {
                outcome: "rejected_circuit_open",
                reason: "circuit_open".into(),
                message: Some(format!("Service-boundary circuit is open for target [{}].", self.target_key())),
                policy: DEFAULT_POLICY.into(),
                retry_after: (!retry.is_null()).then(|| php_int(retry).max(0)),
                metadata: json!({"failure_reason": "policy_rejection", "target_key": self.target_key()}),
            };
        }
        Decision::allow(DEFAULT_POLICY, json!({"boundary_key": self.boundary_key()}))
    }

    /// `checkAuthorization`.
    fn authorization(&self, rules: &Value) -> Option<Decision> {
        if let Some(denial) = self.required_roles(&array_at(rules, "authorization"), "operation") {
            return Some(denial);
        }
        let [endpoint, service, operation] = self.policies();
        for (axis, policy) in [("endpoint", endpoint), ("service", service), ("operation", operation)] {
            if let Some(denial) = self.required_roles(&policy, axis).or_else(|| self.caller_namespace_policy(&policy, axis)) {
                return Some(denial);
            }
        }
        let namespaces = array_at(rules, "namespaces");
        if let Some(denial) = self.caller_namespace_policy(&namespaces, "operation") {
            return Some(denial);
        }
        if at(&namespaces, "cross_namespace_default").as_str().unwrap_or("allow") == "deny"
            && self.caller_namespace != self.target_namespace
        {
            return Some(Decision::forbidden("cross_namespace_blocked",
                format!("Cross-namespace calls from [{}] to [{}] are blocked by service-boundary policy.",
                    self.caller_namespace, self.target_namespace), "operation"));
        }
        if at(rules, "default_action").as_str().unwrap_or("allow") == "deny" {
            return Some(Decision::forbidden("default_action_denied", "Service-boundary policy default action is deny.".into(), "operation"));
        }
        None
    }

    /// `checkRequiredRoles`.
    fn required_roles(&self, policy: &Value, axis: &str) -> Option<Decision> {
        let required = first_list(policy, &[&["required_roles"], &["authorization", "required_roles"]]).unwrap_or_default();
        if required.is_empty() || required.iter().any(|role| self.caller.roles.contains(role)) {
            return None;
        }
        Some(Decision::forbidden("missing_required_role",
            format!("Caller [{}] is missing one of the required roles for service-boundary admission.", self.caller.subject), axis))
    }

    /// `checkCallerNamespacePolicy`. The caller namespace is never null here.
    fn caller_namespace_policy(&self, policy: &Value, axis: &str) -> Option<Decision> {
        let caller = &self.caller_namespace;
        let deny = first_list(policy, &[&["deny_callers"], &["caller_namespaces", "deny"], &["caller_namespaces", "deny_callers"],
            &["authorization", "deny_callers"], &["authorization", "caller_namespaces", "deny"],
            &["authorization", "caller_namespaces", "deny_callers"]]).unwrap_or_default();
        if deny.contains(caller) {
            return Some(Decision::forbidden("caller_namespace_denied",
                format!("Caller namespace [{caller}] is explicitly denied by service-boundary policy."), axis));
        }
        let allow = first_list(policy, &[&["allow_callers"], &["caller_namespaces", "allow"], &["caller_namespaces", "allow_callers"],
            &["authorization", "allow_callers"], &["authorization", "caller_namespaces", "allow"],
            &["authorization", "caller_namespaces", "allow_callers"]])?;
        (!allow.contains(caller)).then(|| Decision::forbidden("caller_namespace_not_allowed",
            format!("Caller namespace [{caller}] is not on the service-boundary allow list for target namespace [{}].",
                self.target_namespace), axis))
    }

    /// `SharedServiceBoundaryPolicy::limits`, or an error for an invalid one
    /// (PHP's `unavailable`). Native runs the namespace and hard limits only
    /// at their default of none.
    fn limits(&self) -> std::result::Result<Limits, ()> {
        let rules = global_rules();
        let effective = self.effective_policy();
        let rate = merge_policy(&[&array_at(&rules, "rate_limit"), &array_at(&effective, "rate_limit")]);
        let concurrency = merge_policy(&[&array_at(&rules, "concurrency"), &array_at(&effective, "concurrency_limit")]);
        let skips = |rules: &Value| at(rules, "sync_only") == &Value::Bool(true) && self.mode != "sync";
        let rate_limit = if skips(&rate) { None } else { limit(at(&rate, "requests_per_minute"))? };
        let concurrency_limit = if skips(&concurrency) { None } else { limit(at(&concurrency, "max_in_flight"))? };
        Ok(Limits {
            rate: rate_limit,
            concurrency: concurrency_limit,
            rate_retry: retry_after_seconds(&rate)?,
            concurrency_retry: retry_after_seconds(&concurrency)?,
        })
    }

    /// `boundaryRateKey` for this minute and `boundaryConcurrencyKey`.
    fn counter_keys(&self, now: DateTime<Utc>) -> (String, String) {
        use sha2::{Digest, Sha256};
        let hash: String = Sha256::digest(self.boundary_key().as_bytes()).iter().map(|byte| format!("{byte:02x}")).collect();
        (format!("server:service-boundary:rate:{hash}:{}", now.timestamp().div_euclid(60)),
            format!("server:service-boundary:concurrency:{hash}"))
    }
}

/// The boundary limits in force for one request.
#[derive(Clone, Copy, Debug)]
struct Limits {
    rate: Option<i64>,
    concurrency: Option<i64>,
    rate_retry: i64,
    concurrency_retry: i64,
}

/// The rate counter's life and the concurrency lease's
/// (`concurrency_lease_ttl_seconds`, which native runs at its default).
const RATE_TTL_SECONDS: i64 = 120;
const CONCURRENCY_TTL_SECONDS: i64 = 86_400;

/// `SharedServiceBoundaryPolicy::limit`: null, empty or zero is no limit.
fn limit(value: &Value) -> std::result::Result<Option<i64>, ()> {
    match value {
        Value::Null => Ok(None),
        Value::String(text) if text.is_empty() || text == "0" => Ok(None),
        Value::Number(number) if number.as_i64() == Some(0) && !number.is_f64() => Ok(None),
        Value::Number(number) => number.as_i64().filter(|whole| *whole > 0).map(Some).ok_or(()),
        Value::String(text) if text.bytes().all(|byte| byte.is_ascii_digit()) && !text.starts_with('0') => {
            validated_digits(text).filter(|whole| *whole >= 1).map(Some).ok_or(())
        }
        _ => Err(()),
    }
}

/// `configuredRetryAfterSeconds`: whether the rule's value is valid.
fn retry_after_seconds(rules: &Value) -> std::result::Result<i64, ()> {
    match at(rules, "retry_after_seconds") {
        Value::Null => Ok(1),
        Value::String(text) if text.is_empty() => Ok(1),
        Value::Number(number) => number.as_i64().filter(|whole| *whole >= 0).map(|whole| whole.min(60)).ok_or(()),
        Value::String(text) if text.bytes().all(|byte| byte.is_ascii_digit()) => {
            validated_digits(text).map(|whole| whole.min(60)).ok_or(())
        }
        _ => Err(()),
    }
}

// ---------------------------------------------------------------------------
// The call row.

/// A `workflow_service_calls` row. JSON columns hold PHP's reading of them.
#[derive(Clone, Debug, Default)]
pub(crate) struct Call {
    id: String,
    endpoint_id: Option<String>,
    service_id: Option<String>,
    operation_id: Option<String>,
    namespace: Option<String>,
    endpoint_name: String,
    service_name: String,
    operation_name: String,
    caller_namespace: Option<String>,
    caller_instance: Option<String>,
    caller_run: Option<String>,
    target_namespace: Option<String>,
    linked_instance: Option<String>,
    linked_run: Option<String>,
    linked_update: Option<String>,
    status: String,
    outcome: Option<String>,
    operation_mode: String,
    binding_kind: Option<String>,
    target_reference: Option<String>,
    payload_codec: Option<String>,
    input_reference: Option<String>,
    output_reference: Option<String>,
    failure_reference: Option<String>,
    failure_message: Option<String>,
    idempotency_key: Option<String>,
    deadline_policy: Value,
    idempotency_policy: Value,
    cancellation_policy: Value,
    retry_policy: Value,
    boundary_policy: Value,
    metadata: Value,
    outcome_category: Option<String>,
    outcome_reason: Option<String>,
    outcome_message: Option<String>,
    outcome_metadata: Value,
    policy_name: Option<String>,
    retry_after: Option<i64>,
    principal_subject: Option<String>,
    principal_method: Option<String>,
    principal_roles: Value,
    principal_tenant: Option<String>,
    principal_claims: Value,
    accepted_at: Option<DateTime<Utc>>,
    call_deadline_at: Option<DateTime<Utc>>,
    started_at: Option<DateTime<Utc>>,
    completed_at: Option<DateTime<Utc>>,
    failed_at: Option<DateTime<Utc>>,
    cancelled_at: Option<DateTime<Utc>>,
    created_at: Option<DateTime<Utc>>,
    updated_at: Option<DateTime<Utc>>,
}

impl Call {
    fn is_open(&self) -> bool {
        matches!(self.status.as_str(), "pending" | "accepted" | "started")
    }

    fn is_terminal(&self) -> bool {
        matches!(self.status.as_str(), "completed" | "failed" | "cancelled")
    }

    fn is_failed(&self) -> bool {
        self.status == "failed"
    }

    /// `acceptedShape`.
    fn accepted_shape(&self) -> bool {
        self.status != "failed" && self.status != "cancelled"
    }

    /// `ServiceCallDeadline::expireLocked`.
    fn expire(&mut self, instant: DateTime<Utc>) -> bool {
        let Some(deadline) = self.call_deadline_at else { return false };
        if !self.is_open() || self.accepted_at.is_none() || deadline > instant {
            return false;
        }
        let mut metadata = array_value(&self.metadata);
        if let Value::Object(fields) = &mut metadata {
            fields.remove("nexus_result");
        }
        self.status = "failed".into();
        self.outcome = Some("timed_out".into());
        self.outcome_category = Some("handler".into());
        self.outcome_reason = Some("timed_out".into());
        self.outcome_message = Some(TIMED_OUT.into());
        self.failure_message = Some(TIMED_OUT.into());
        self.outcome_metadata = json!({"service_error_type": "ServiceCallTimedOut", "caller_observed_error_type": "ServiceCallTimedOut",
            "typed_error_message": TIMED_OUT, "call_deadline_at": micros(Some(deadline))});
        self.failed_at = Some(instant);
        self.completed_at = None;
        self.output_reference = None;
        self.failure_reference = None;
        self.metadata = php_document(metadata);
        true
    }

    /// `serviceCallAttempts`.
    fn attempts(&self) -> Vec<Value> {
        for container in [&self.metadata, &self.outcome_metadata] {
            let attempts = at(container, "service_call_attempts");
            if is_array(attempts) {
                return values(attempts).into_iter().filter(is_array).collect();
            }
        }
        Vec::new()
    }
}

/// `Y-m-d\TH:i:s.u\Z`.
fn micros(at: Option<DateTime<Utc>>) -> Value {
    at.map_or(Value::Null, |at| json!(at.format("%Y-%m-%dT%H:%M:%S%.6fZ").to_string()))
}

/// Carbon's `toIso8601String`.
fn iso(at: Option<DateTime<Utc>>) -> Value {
    at.map_or(Value::Null, |at| json!(at.to_rfc3339_opts(chrono::SecondsFormat::Secs, false)))
}

/// `failureTypeFrom`.
fn failure_type(source: &Value) -> Option<String> {
    for key in ["service_error_type", "serviceErrorType", "caller_observed_error_type", "callerObservedErrorType", "failure_type",
        "failureType", "error_type", "errorType", "typed_error", "typedError", "exception_type", "exceptionType"]
    {
        if let Some(value) = string_from(at(source, key)) {
            return Some(value);
        }
    }
    ["control_plane", "handler", "error", "failure"].iter().find_map(|key| {
        let nested = at(source, key);
        if is_array(nested) { failure_type(nested) } else { None }
    })
}

// ---------------------------------------------------------------------------
// Catalog reads (`ServiceCatalogReadController::callsIndex` and `callShow`).

/// `ServiceCallStatus::bucket`, for a known status.
fn status_bucket(status: &str) -> Option<&'static str> {
    match status {
        "pending" | "accepted" | "started" => Some("open"),
        "completed" => Some("completed"),
        "failed" => Some("failed"),
        "cancelled" => Some("cancelled"),
        _ => None,
    }
}

/// `ServiceCallOutcome::bucket`, for a known outcome.
fn outcome_bucket(outcome: &str) -> Option<&'static str> {
    match outcome {
        "accepted" => Some("open"),
        "completed" | "degraded" => Some("completed"),
        "cancelled" => Some("cancelled"),
        "timed_out" | "handler_failed" => Some("failed"),
        "rejected_not_found" | "rejected_forbidden" | "rejected_throttled" | "rejected_concurrency_limited"
        | "rejected_circuit_open" => Some("policy"),
        _ => None,
    }
}

/// `ServiceCallView::listItem`.
pub(crate) fn catalog_list_item(call: &Call) -> Value {
    let outcome = call.outcome.as_deref().filter(|outcome| outcome_bucket(outcome).is_some());
    let attempts = call.attempts();
    let pairs: Vec<(&str, Value)> = vec![
        ("id", json!(call.id)),
        ("workflow_service_endpoint_id", json!(call.endpoint_id)),
        ("workflow_service_id", json!(call.service_id)),
        ("workflow_service_operation_id", json!(call.operation_id)),
        ("namespace", json!(call.namespace)),
        ("endpoint_name", json!(call.endpoint_name)),
        ("service_name", json!(call.service_name)),
        ("operation_name", json!(call.operation_name)),
        ("caller_namespace", json!(call.caller_namespace)),
        ("caller_workflow_instance_id", json!(call.caller_instance)),
        ("caller_workflow_run_id", json!(call.caller_run)),
        ("caller_principal_subject", json!(call.principal_subject)),
        ("caller_principal_method", json!(call.principal_method)),
        ("caller_principal_roles", array_value(&call.principal_roles)),
        ("caller_principal_tenant", json!(call.principal_tenant)),
        ("target_namespace", json!(call.target_namespace)),
        ("linked_workflow_instance_id", json!(call.linked_instance)),
        ("linked_workflow_run_id", json!(call.linked_run)),
        ("linked_workflow_update_id", json!(call.linked_update)),
        ("status", json!(call.status)),
        ("status_bucket", json!(status_bucket(&call.status))),
        ("outcome", json!(outcome)),
        ("outcome_bucket", json!(outcome.and_then(outcome_bucket))),
        ("is_terminal", json!(call.is_terminal())),
        ("is_policy_outcome", json!(outcome.and_then(outcome_bucket) == Some("policy"))),
        ("operation_mode", json!(call.operation_mode)),
        ("resolved_binding_kind", json!(call.binding_kind)),
        ("resolved_target_reference", json!(call.target_reference)),
        ("idempotency_key", json!(call.idempotency_key)),
        ("retry_policy", array_value(&call.retry_policy)),
        ("retry_attempt_count", json!(attempts.len())),
        ("service_call_attempts", Value::Array(attempts)),
        ("failure_message", json!(call.failure_message)),
        ("accepted_at", micros(call.accepted_at)),
        ("call_deadline_at", micros(call.call_deadline_at)),
        ("started_at", micros(call.started_at)),
        ("completed_at", micros(call.completed_at)),
        ("failed_at", micros(call.failed_at)),
        ("cancelled_at", micros(call.cancelled_at)),
        ("created_at", micros(call.created_at)),
        ("updated_at", micros(call.updated_at)),
    ];
    Value::Object(pairs.into_iter().map(|(key, value)| (key.to_owned(), value)).collect())
}

/// `ServiceCallResultEnvelope::fromCall`.
fn result_envelope(call: &Call) -> Value {
    let result = at(&call.metadata, "nexus_result");
    if !is_array(result) {
        return Value::Null;
    }
    if at(result, "blob").is_string() {
        return result.clone();
    }
    let external = at(result, "external_reference");
    match (&call.output_reference, external) {
        (Some(uri), Value::Object(reference)) => {
            let mut storage = reference.clone();
            storage.entry("uri").or_insert_with(|| json!(uri));
            json!({"codec": at(result, "codec"), "external_storage": storage})
        }
        _ => Value::Null,
    }
}

/// `callShow` over `ServiceCallView::detail`, read from `observer`'s
/// namespace: a call owned elsewhere hides its payload references.
pub(crate) fn catalog_detail(call: &Call, observer: &str) -> Value {
    let mut view = catalog_list_item(call);
    let in_observer = |namespace: &Option<String>| namespace.as_deref() == Some(observer);
    let reference = |namespace: &Option<String>, fields: Vec<(&str, Value)>| {
        let mut fields: Map<String, Value> = fields.into_iter().map(|(key, value)| (key.to_owned(), value)).collect();
        fields.insert("namespace".into(), json!(namespace));
        fields.insert("in_observer_namespace".into(), json!(in_observer(namespace)));
        Value::Object(fields)
    };
    let owned = in_observer(&call.namespace);
    let mut metadata = array_value(&call.metadata);
    if !owned && let Value::Object(fields) = &mut metadata {
        fields.remove("nexus_result");
    }
    let hidden = |value: &Option<String>| if owned { json!(value) } else { Value::Null };
    let detail: Vec<(&str, Value)> = vec![
        ("payload_codec", json!(call.payload_codec)),
        ("input_payload_reference", hidden(&call.input_reference)),
        ("output_payload_reference", hidden(&call.output_reference)),
        ("failure_payload_reference", hidden(&call.failure_reference)),
        ("deadline_policy", array_value(&call.deadline_policy)),
        ("idempotency_policy", array_value(&call.idempotency_policy)),
        ("cancellation_policy", array_value(&call.cancellation_policy)),
        ("boundary_policy", array_value(&call.boundary_policy)),
        ("metadata", metadata),
        ("caller_principal_claims", array_value(&call.principal_claims)),
        ("caller_link", if call.caller_instance.is_none() && call.caller_run.is_none() {
            Value::Null
        } else {
            reference(&call.caller_namespace, vec![("workflow_instance_id", json!(call.caller_instance)),
                ("workflow_run_id", json!(call.caller_run))])
        }),
        ("linked_run_ref", if call.linked_instance.is_none() && call.linked_run.is_none() {
            Value::Null
        } else {
            reference(&call.target_namespace, vec![("workflow_instance_id", json!(call.linked_instance)),
                ("workflow_run_id", json!(call.linked_run))])
        }),
        ("linked_update_ref", if call.linked_update.is_none() {
            Value::Null
        } else {
            reference(&call.target_namespace, vec![("workflow_instance_id", json!(call.linked_instance)),
                ("workflow_update_id", json!(call.linked_update)), ("workflow_run_id", json!(call.linked_run))])
        }),
        ("payloads_in_observer_namespace", json!(owned)),
        ("result_envelope", if owned { result_envelope(call) } else { Value::Null }),
        ("failure_type", json!(failure_type(&array_value(&call.outcome_metadata)))),
    ];
    let fields = view.as_object_mut().expect("a list item is an object");
    for (key, value) in detail {
        fields.insert(key.to_owned(), value);
    }
    view
}

/// The controller's `serializeServiceCall`.
fn controller_view(call: &Call, endpoint: &Endpoint, service: &Service, operation: &Operation) -> Map<String, Value> {
    let metadata_string = |key: &str| string_from(at(&call.outcome_metadata, key)).map_or(Value::Null, Value::from);
    let attempts = call.attempts();
    let pairs: Vec<(&str, Value)> = vec![
        ("id", json!(call.id)),
        ("namespace", json!(call.namespace)),
        ("endpoint_id", json!(endpoint.row.id)),
        ("endpoint_name", json!(endpoint.row.name)),
        ("service_id", json!(service.row.id)),
        ("service_name", json!(service.row.name)),
        ("operation_id", json!(operation.row.id)),
        ("operation_name", json!(operation.row.name)),
        ("caller_namespace", json!(call.caller_namespace)),
        ("caller_workflow_instance_id", json!(call.caller_instance)),
        ("caller_workflow_run_id", json!(call.caller_run)),
        ("target_namespace", json!(call.target_namespace)),
        ("linked_workflow_instance_id", json!(call.linked_instance)),
        ("linked_workflow_run_id", json!(call.linked_run)),
        ("linked_workflow_update_id", json!(call.linked_update)),
        ("status", json!(call.status)),
        ("outcome", json!(call.outcome)),
        ("outcome_category", json!(call.outcome_category)),
        ("outcome_reason", json!(call.outcome_reason)),
        ("outcome_message", json!(call.outcome_message)),
        ("outcome_metadata", call.outcome_metadata.clone()),
        ("service_error_type", metadata_string("service_error_type")),
        ("caller_observed_error_type", metadata_string("caller_observed_error_type")),
        ("typed_error_message", metadata_string("typed_error_message")),
        ("policy_name", json!(call.policy_name)),
        ("retry_after_seconds", json!(call.retry_after)),
        ("operation_mode", json!(call.operation_mode)),
        ("resolved_binding_kind", json!(call.binding_kind)),
        ("resolved_target_reference", json!(call.target_reference)),
        ("caller_principal_subject", json!(call.principal_subject)),
        ("caller_principal_method", json!(call.principal_method)),
        ("caller_principal_roles", call.principal_roles.clone()),
        ("caller_principal_tenant", json!(call.principal_tenant)),
        ("caller_principal_claims", call.principal_claims.clone()),
        ("payload_codec", json!(call.payload_codec)),
        ("input_payload_reference", json!(call.input_reference)),
        ("output_payload_reference", json!(call.output_reference)),
        ("failure_payload_reference", json!(call.failure_reference)),
        ("failure_message", json!(call.failure_message)),
        ("idempotency_key", json!(call.idempotency_key)),
        ("deadline_policy", call.deadline_policy.clone()),
        ("idempotency_policy", call.idempotency_policy.clone()),
        ("cancellation_policy", call.cancellation_policy.clone()),
        ("retry_policy", call.retry_policy.clone()),
        ("boundary_policy", call.boundary_policy.clone()),
        ("metadata", call.metadata.clone()),
        ("retry_attempt_count", json!(attempts.len())),
        ("service_call_attempts", Value::Array(attempts)),
        ("accepted_at", micros(call.accepted_at)),
        ("call_deadline_at", micros(call.call_deadline_at)),
        ("started_at", micros(call.started_at)),
        ("completed_at", micros(call.completed_at)),
        ("failed_at", micros(call.failed_at)),
        ("cancelled_at", micros(call.cancelled_at)),
        ("created_at", iso(call.created_at)),
        ("updated_at", iso(call.updated_at)),
    ];
    pairs.into_iter().map(|(key, value)| (key.to_owned(), value)).collect()
}

/// The control plane's `serialize`, on a call already reconciled.
fn plane_view(call: &Call) -> Map<String, Value> {
    let attempts = call.attempts();
    let pairs: Vec<(&str, Value)> = vec![
        ("service_call_id", json!(call.id)),
        ("namespace", json!(call.namespace)),
        ("caller_namespace", json!(call.caller_namespace)),
        ("target_namespace", json!(call.target_namespace)),
        ("endpoint_name", json!(call.endpoint_name)),
        ("service_name", json!(call.service_name)),
        ("operation_name", json!(call.operation_name)),
        ("operation_mode", json!(call.operation_mode)),
        ("status", json!(call.status)),
        ("outcome", json!(call.outcome)),
        ("outcome_category", json!(call.outcome_category)),
        ("outcome_reason", json!(call.outcome_reason)),
        ("resolved_binding_kind", json!(call.binding_kind)),
        ("resolved_target_reference", json!(call.target_reference)),
        ("linked_workflow_instance_id", json!(call.linked_instance)),
        ("linked_workflow_run_id", json!(call.linked_run)),
        ("linked_workflow_update_id", json!(call.linked_update)),
        ("payload_codec", json!(call.payload_codec)),
        ("input_payload_reference", json!(call.input_reference)),
        ("output_payload_reference", json!(call.output_reference)),
        ("failure_payload_reference", json!(call.failure_reference)),
        ("retry_attempt_count", json!(attempts.len())),
        ("service_call_attempts", Value::Array(attempts)),
        ("accepted_at", micros(call.accepted_at)),
        ("call_deadline_at", micros(call.call_deadline_at)),
        ("started_at", micros(call.started_at)),
        ("completed_at", micros(call.completed_at)),
        ("failed_at", micros(call.failed_at)),
        ("cancelled_at", micros(call.cancelled_at)),
        ("failure_message", json!(call.failure_message)),
    ];
    let mut view: Map<String, Value> = pairs.into_iter().map(|(key, value)| (key.to_owned(), value)).collect();
    if let Some(kind) = failure_type(&array_value(&call.outcome_metadata)) {
        for key in ["error_type", "failure_type", "service_error_type"] {
            view.insert(key.into(), json!(kind));
        }
    }
    let message = string_from(at(&call.outcome_metadata, "typed_error_message"))
        .or_else(|| call.outcome_message.as_deref().and_then(|text| string_from(&json!(text))))
        .or_else(|| call.failure_message.as_deref().and_then(|text| string_from(&json!(text))));
    if call.is_failed()
        && let Some(message) = message
    {
        view.insert("message".into(), json!(message));
    }
    view
}

/// `array_replace($first, $second)`.
fn replaced(mut first: Map<String, Value>, second: Map<String, Value>) -> Value {
    first.extend(second);
    Value::Object(first)
}

fn with(mut view: Map<String, Value>, extra: Value) -> Map<String, Value> {
    if let Value::Object(fields) = extra {
        view.extend(fields);
    }
    view
}

/// `serializeAdmissionRejection`, with the target redacted for a forbidden
/// call.
fn rejection_view(call: &Call, decision: &Decision, endpoint: &Endpoint, service: &Service, operation: &Operation) -> Value {
    let redacted = decision.outcome == "rejected_forbidden";
    let mut view = json!({
        "accepted": false, "service_call_id": call.id, "namespace": call.namespace, "status": call.status,
        "outcome": decision.outcome, "error_type": decision.outcome, "outcome_category": call.outcome_category,
        "reason": decision.reason, "retry_after_seconds": decision.retry_after, "policy_name": decision.policy,
        "caller_namespace": call.caller_namespace, "caller_principal_subject": call.principal_subject,
        "linked_workflow_instance_id": null, "linked_workflow_run_id": null, "linked_workflow_update_id": null,
    });
    if redacted {
        let failure_reason = match at(&decision.metadata, "failure_reason") {
            Value::String(text) => text.clone(),
            Value::Number(number) => number.to_string(),
            Value::Bool(flag) => if *flag { "1".into() } else { String::new() },
            _ => "policy_rejection".into(),
        };
        view["message"] = json!("Nexus operation is not permitted for this caller.");
        view["outcome_metadata"] = json!({"failure_reason": failure_reason});
    } else {
        view["message"] = json!(decision.message);
        view["outcome_metadata"] = decision.metadata.clone();
        for (key, value) in [("endpoint_id", &endpoint.row.id), ("endpoint_name", &endpoint.row.name),
            ("service_id", &service.row.id), ("service_name", &service.row.name), ("operation_id", &operation.row.id),
            ("operation_name", &operation.row.name)]
        {
            view[key] = json!(value);
        }
        view["operation_mode"] = json!(call.operation_mode);
    }
    view
}

/// `serviceCallNotFound`.
fn call_not_found(endpoint: &Endpoint, service: &Service, operation: &Operation, id: &str) -> RuntimeError {
    let namespace = service.row.namespace.as_deref().unwrap_or_default();
    RuntimeError::Protocol {
        status: StatusCode::NOT_FOUND,
        response: json!({
            "message": format!("Service call [{id}] not found under operation [{}] for service [{}] at endpoint [{}] in namespace [{namespace}].",
                operation.row.name, service.row.name, endpoint.row.name),
            "reason": "service_call_not_found", "namespace": service.row.namespace, "endpoint_name": endpoint.row.name,
            "service_name": service.row.name, "operation_name": operation.row.name, "service_call_id": id}),
    }
}

fn not_implemented() -> RuntimeError {
    super::refuse(StatusCode::NOT_IMPLEMENTED, "rust_capability_not_implemented")
}

/// `ServiceCallDeadlinePolicy` failures at execute (`assertDeadlinePolicy`).
fn invalid(field: &str, message: &str) -> RuntimeError {
    laravel_rules::failure(vec![(field.to_owned(), vec![message.to_owned()])]).expect("a message")
}

// ---------------------------------------------------------------------------
// Dispatch helpers.

/// `maxServiceCallAttempts`: the first positive attempt count, at most 10.
fn max_attempts(policy: &Value) -> i64 {
    ["max_attempts", "maximum_attempts", "maximumAttempts"]
        .iter()
        .find_map(|key| positive_int(at(policy, key)))
        .map_or(1, |attempts| attempts.min(10))
}

/// `serviceCallBackoffSeconds`.
fn backoff_seconds(policy: &Value, completed_attempt: i64) -> f64 {
    for key in ["backoff_seconds", "backoffSeconds"] {
        if let Some(backoff) = policy.as_object().and_then(|fields| fields.get(key)) {
            let value = if is_array(backoff) {
                values(backoff).get(usize::try_from(completed_attempt - 1).unwrap_or(0)).and_then(numeric_value)
            } else {
                numeric_value(backoff)
            };
            return value.unwrap_or(0.0).max(0.0);
        }
    }
    let first = |keys: &[&str]| keys.iter().find_map(|key| numeric_value(at(policy, key)));
    let initial = first(&["initial_interval_seconds", "initialIntervalSeconds", "initial_interval", "initialInterval"]).unwrap_or(0.0);
    let coefficient = first(&["backoff_coefficient", "backoffCoefficient"]).unwrap_or(2.0);
    let maximum = first(&["maximum_interval_seconds", "max_interval_seconds", "maximumIntervalSeconds", "maxIntervalSeconds"]);
    let mut backoff = initial * coefficient.powi(i32::try_from((completed_attempt - 1).max(0)).unwrap_or(0));
    if let Some(maximum) = maximum.filter(|maximum| *maximum >= 0.0) {
        backoff = backoff.min(maximum);
    }
    backoff.max(0.0)
}

/// `nonRetryableFailureTypes`.
fn non_retryable(policy: &Value) -> Vec<String> {
    for key in ["non_retryable_error_types", "nonRetryableErrorTypes", "non_retryable_failure_types", "nonRetryableFailureTypes"] {
        let types = list_at(policy, &[key]).unwrap_or_default();
        if !types.is_empty() {
            return types;
        }
    }
    Vec::new()
}

/// `cancellationAllowed`.
fn cancellation_allowed(policy: &Value) -> bool {
    let policy = array_value(policy);
    if ["allow_cancel", "cancellable", "allow_external_cancel"]
        .iter()
        .any(|key| policy.as_object().is_some_and(|fields| fields.get(*key) == Some(&Value::Bool(false))))
    {
        return false;
    }
    at(&policy, "mode").as_str() != Some("none")
}

/// A new uppercase ULID, as `(string) Str::ulid()` makes one.
fn upper_ulid() -> String {
    super::store::id()
}

/// What a binding names, as `bindingString` reads it.
fn binding_string(binding: &Value, keys: &[&str]) -> Option<String> {
    keys.iter().find_map(|key| string_from(at(binding, key)))
}

/// The execute request after its checks: what the boundary and the
/// handlers read.
#[derive(Clone, Debug, Default)]
pub(crate) struct Execution {
    caller_namespace: String,
    mode_override: Option<String>,
    idempotency_key: Option<String>,
    caller_instance: Option<String>,
    caller_run: Option<String>,
    connection: Option<String>,
    queue: Option<String>,
}

// ---------------------------------------------------------------------------
// The request checks before admission.

/// `RuntimeExternalPayloadTransport::resolveIncoming` on `arguments`, before
/// the route runs.
fn transport(body: &Value) -> Result<()> {
    let Some(arguments) = body.get("arguments").filter(|arguments| is_array(arguments)) else {
        return Ok(());
    };
    if !at(arguments, "external_storage").is_null() || arguments.as_object().is_some_and(|fields| fields.contains_key("external_storage")) {
        return Err(super::external_transport::provider_reference());
    }
    let Some(fields) = arguments.as_object().filter(|fields| fields.contains_key("external_payload")) else {
        return Ok(());
    };
    let mut keys: Vec<&str> = fields.keys().map(String::as_str).collect();
    keys.sort_unstable();
    if keys != ["codec", "external_payload"] || !is_array(&fields["external_payload"]) {
        return Err(super::external_transport::typed_error(StatusCode::UNPROCESSABLE_ENTITY, "external_payload_unsupported",
            "External payload envelopes must contain exactly codec and external_payload."));
    }
    // Resolving and claiming a runtime reference here is a later slice.
    Err(not_implemented())
}

/// The `arguments` checks of `executeOperation`: the field and blob that
/// must decode as one Avro value, if any.
fn arguments_blob(arguments: &Value) -> Result<Option<(&'static str, String)>> {
    use super::input_envelope::codec_refusal;
    match arguments {
        Value::String(blob) => Ok(Some(("arguments", blob.clone()))),
        Value::Object(fields) if fields.contains_key("codec") => {
            if let Some(message) = codec_refusal(&fields["codec"]) {
                return Err(invalid("arguments.codec", &message));
            }
            let mut keys: Vec<&str> = fields.keys().map(String::as_str).collect();
            keys.sort_unstable();
            if keys != ["blob", "codec"] {
                // The transport refuses `external_storage` before the route.
                return Err(invalid("arguments", "The arguments envelope must contain only codec and blob, or codec and external storage."));
            }
            if !fields["codec"].as_str().is_some_and(|codec| !codec.is_empty()) {
                return Err(invalid("arguments.codec", "The payload envelope codec must be a non-empty string."));
            }
            match &fields["blob"] {
                Value::String(blob) => Ok(Some(("arguments.blob", blob.clone()))),
                _ => Err(invalid("arguments.blob", "The payload envelope blob must be a string.")),
            }
        }
        _ => Ok(None),
    }
}

impl super::Runtime {
    /// `ServiceCatalogController::executeOperation`.
    pub(crate) async fn execute_service_operation(
        &self,
        names: [&str; 3],
        body: Value,
        query: &[(String, String)],
        caller: Caller,
    ) -> Result<(StatusCode, Value)> {
        let body = match body {
            Value::Object(fields) => Value::Object(fields),
            _ => json!({}),
        };
        transport(&laravel_rules::normalize(&body))?;
        self.service_operation_exists(names).await?;
        let input = super::service_endpoints::input(body, query, EXECUTE_RULES);
        laravel_rules::validate(&input, EXECUTE_RULES)?;
        let validated = laravel_rules::validated(&input, EXECUTE_RULES);
        if let Some(message) = super::input_envelope::codec_refusal(at(&validated, "payload_codec")) {
            return Err(invalid("payload_codec", &message));
        }
        if let Some((field, blob)) = arguments_blob(at(&validated, "arguments"))?
            && !self.avro_value_decodes(blob).await?
        {
            return Err(invalid(field, super::command_payloads::UNDECODABLE));
        }
        let attributes = at(&validated, "search_attributes");
        if !attributes.is_null() {
            self.validate_service_call_search_attributes(attributes.clone()).await?;
        }
        let text = |key: &str| at(&validated, key).as_str().map(str::to_owned);
        let execution = Execution {
            caller_namespace: text("caller_namespace").unwrap_or_else(|| self.namespace().to_owned()),
            mode_override: text("mode_override"),
            idempotency_key: text("idempotency_key"),
            caller_instance: text("caller_workflow_instance_id"),
            caller_run: text("caller_workflow_run_id"),
            connection: text("connection"),
            queue: text("queue"),
        };
        self.execute_service_call(names, execution, caller).await
    }

    /// `ServiceCatalogController::serviceCallCancel`: the call first, then the
    /// input.
    pub(crate) async fn cancel_service_operation_call(
        &self,
        names: [&str; 3],
        id: &str,
        body: Value,
        query: &[(String, String)],
    ) -> Result<(StatusCode, Value)> {
        self.service_call_exists(names, id).await?;
        let input = super::service_endpoints::input(body, query, CANCEL_RULES);
        laravel_rules::validate(&input, CANCEL_RULES)?;
        let reason = string_from(at(&laravel_rules::validated(&input, CANCEL_RULES), "reason"));
        self.cancel_service_call(names, id, reason).await
    }
}

store_methods! {
    pub(crate) fn service_call_row(row: &DB::Row) -> Result<Call> {
        let text = |field: &str| -> Result<Option<String>> { DB::optional_string(row, field) };
        let document = |field: &str| -> Result<Value> { Ok(DB::optional_document_row(row, field)?.map_or(Value::Null, php_document)) };
        let time = |field: &str| -> Result<Option<DateTime<Utc>>> { DB::optional_instant(row, field) };
        Ok(Call {
            id: DB::string(row, "id")?,
            endpoint_id: text("workflow_service_endpoint_id")?,
            service_id: text("workflow_service_id")?,
            operation_id: text("workflow_service_operation_id")?,
            namespace: text("namespace")?,
            endpoint_name: DB::string(row, "endpoint_name")?,
            service_name: DB::string(row, "service_name")?,
            operation_name: DB::string(row, "operation_name")?,
            caller_namespace: text("caller_namespace")?,
            caller_instance: text("caller_workflow_instance_id")?,
            caller_run: text("caller_workflow_run_id")?,
            target_namespace: text("target_namespace")?,
            linked_instance: text("linked_workflow_instance_id")?,
            linked_run: text("linked_workflow_run_id")?,
            linked_update: text("linked_workflow_update_id")?,
            status: DB::string(row, "status")?,
            outcome: text("outcome")?,
            operation_mode: DB::string(row, "operation_mode")?,
            binding_kind: text("resolved_binding_kind")?,
            target_reference: text("resolved_target_reference")?,
            payload_codec: text("payload_codec")?,
            input_reference: text("input_payload_reference")?,
            output_reference: text("output_payload_reference")?,
            failure_reference: text("failure_payload_reference")?,
            failure_message: text("failure_message")?,
            idempotency_key: text("idempotency_key")?,
            deadline_policy: document("deadline_policy")?,
            idempotency_policy: document("idempotency_policy")?,
            cancellation_policy: document("cancellation_policy")?,
            retry_policy: document("retry_policy")?,
            boundary_policy: document("boundary_policy")?,
            metadata: document("metadata")?,
            outcome_category: text("outcome_category")?,
            outcome_reason: text("outcome_reason")?,
            outcome_message: text("outcome_message")?,
            outcome_metadata: document("outcome_metadata")?,
            policy_name: text("policy_name")?,
            retry_after: DB::optional_number(row, "retry_after_seconds")?,
            principal_subject: text("caller_principal_subject")?,
            principal_method: text("caller_principal_method")?,
            principal_roles: document("caller_principal_roles")?,
            principal_tenant: text("caller_principal_tenant")?,
            principal_claims: document("caller_principal_claims")?,
            accepted_at: time("accepted_at")?,
            call_deadline_at: time("call_deadline_at")?,
            started_at: time("started_at")?,
            completed_at: time("completed_at")?,
            failed_at: time("failed_at")?,
            cancelled_at: time("cancelled_at")?,
            created_at: time("created_at")?,
            updated_at: time("updated_at")?,
        })
    }

    async fn insert_service_call(connection: &mut DB::Connection, call: &Call) -> Result<()> {
        Self::query("INSERT INTO workflow_service_calls(id,workflow_service_endpoint_id,workflow_service_id,workflow_service_operation_id,namespace,endpoint_name,service_name,operation_name,caller_namespace,caller_workflow_instance_id,caller_workflow_run_id,target_namespace,linked_workflow_instance_id,linked_workflow_run_id,linked_workflow_update_id,status,outcome,operation_mode,resolved_binding_kind,resolved_target_reference,payload_codec,input_payload_reference,output_payload_reference,failure_payload_reference,failure_message,idempotency_key,deadline_policy,idempotency_policy,cancellation_policy,retry_policy,boundary_policy,metadata,outcome_category,outcome_reason,outcome_message,outcome_metadata,policy_name,retry_after_seconds,caller_principal_subject,caller_principal_method,caller_principal_roles,caller_principal_tenant,caller_principal_claims,accepted_at,started_at,completed_at,failed_at,cancelled_at,created_at,updated_at) VALUES ($1,$2,$3,$4,$5,$6,$7,$8,$9,$10,$11,$12,$13,$14,$15,$16,$17,$18,$19,$20,$21,$22,$23,$24,$25,$26,$27,$28,$29,$30,$31,$32,$33,$34,$35,$36,$37,$38,$39,$40,$41,$42,$43,$44,$45,$46,$47,$48,$49,$50)")
            .bind(call.id.as_str()).bind(call.endpoint_id.as_deref()).bind(call.service_id.as_deref())
            .bind(call.operation_id.as_deref()).bind(call.namespace.as_deref()).bind(call.endpoint_name.as_str())
            .bind(call.service_name.as_str()).bind(call.operation_name.as_str()).bind(call.caller_namespace.as_deref())
            .bind(call.caller_instance.as_deref()).bind(call.caller_run.as_deref()).bind(call.target_namespace.as_deref())
            .bind(call.linked_instance.as_deref()).bind(call.linked_run.as_deref()).bind(call.linked_update.as_deref())
            .bind(call.status.as_str()).bind(call.outcome.as_deref()).bind(call.operation_mode.as_str())
            .bind(call.binding_kind.as_deref()).bind(call.target_reference.as_deref()).bind(call.payload_codec.as_deref())
            .bind(call.input_reference.as_deref()).bind(call.output_reference.as_deref()).bind(call.failure_reference.as_deref())
            .bind(call.failure_message.as_deref()).bind(call.idempotency_key.as_deref())
            .bind(DB::optional_document(&call.deadline_policy)).bind(DB::optional_document(&call.idempotency_policy))
            .bind(DB::optional_document(&call.cancellation_policy)).bind(DB::optional_document(&call.retry_policy))
            .bind(DB::optional_document(&call.boundary_policy)).bind(DB::optional_document(&call.metadata))
            .bind(call.outcome_category.as_deref()).bind(call.outcome_reason.as_deref()).bind(call.outcome_message.as_deref())
            .bind(DB::optional_document(&call.outcome_metadata)).bind(call.policy_name.as_deref())
            .bind(DB::optional_integer(call.retry_after)).bind(call.principal_subject.as_deref())
            .bind(call.principal_method.as_deref()).bind(DB::optional_document(&call.principal_roles))
            .bind(call.principal_tenant.as_deref()).bind(DB::optional_document(&call.principal_claims))
            .bind(DB::optional_time(call.accepted_at))
            .bind(DB::optional_time(call.started_at)).bind(DB::optional_time(call.completed_at))
            .bind(DB::optional_time(call.failed_at)).bind(DB::optional_time(call.cancelled_at))
            .bind(DB::optional_time(call.created_at)).bind(DB::optional_time(call.updated_at))
            .execute(&mut *connection).await?;
        if let Some(deadline) = call.call_deadline_at {
            Self::query("INSERT INTO dw_service_call_deadlines(service_call_id,namespace,call_deadline_at) VALUES ($1,$2,$3)")
                .bind(call.id.as_str()).bind(call.namespace.as_deref().unwrap_or_default()).bind(DB::bind_time(deadline))
                .execute(&mut *connection).await?;
        }
        Ok(())
    }

    /// Writes the row's changeable columns and moves `updated_at`, as an
    /// Eloquent save of a dirty model does.
    async fn save_service_call(connection: &mut DB::Connection, call: &mut Call) -> Result<()> {
        call.updated_at = Some(super::store::now());
        Self::query("UPDATE workflow_service_calls SET linked_workflow_instance_id=$1,linked_workflow_run_id=$2,linked_workflow_update_id=$3,status=$4,outcome=$5,resolved_binding_kind=$6,resolved_target_reference=$7,output_payload_reference=$8,failure_payload_reference=$9,failure_message=$10,metadata=$11,outcome_category=$12,outcome_reason=$13,outcome_message=$14,outcome_metadata=$15,started_at=$16,completed_at=$17,failed_at=$18,cancelled_at=$19,updated_at=$20 WHERE id=$21")
            .bind(call.linked_instance.as_deref()).bind(call.linked_run.as_deref()).bind(call.linked_update.as_deref())
            .bind(call.status.as_str()).bind(call.outcome.as_deref()).bind(call.binding_kind.as_deref())
            .bind(call.target_reference.as_deref()).bind(call.output_reference.as_deref())
            .bind(call.failure_reference.as_deref()).bind(call.failure_message.as_deref())
            .bind(DB::optional_document(&call.metadata)).bind(call.outcome_category.as_deref())
            .bind(call.outcome_reason.as_deref()).bind(call.outcome_message.as_deref())
            .bind(DB::optional_document(&call.outcome_metadata)).bind(DB::optional_time(call.started_at))
            .bind(DB::optional_time(call.completed_at)).bind(DB::optional_time(call.failed_at))
            .bind(DB::optional_time(call.cancelled_at)).bind(DB::optional_time(call.updated_at)).bind(call.id.as_str())
            .execute(&mut *connection).await?;
        Ok(())
    }

    /// `LinkedServiceCallCloser::reconcile` for these handler kinds: the lazy
    /// deadline expiry of an open call.
    async fn reconcile_service_call(connection: &mut DB::Connection, call: &mut Call) -> Result<()> {
        if call.expire(super::store::now()) {
            Self::save_service_call(connection, call).await?;
        }
        Ok(())
    }

    /// `findServiceCall`: by namespace, operation and the trimmed id.
    async fn find_service_call(&self, connection: &mut DB::Connection, operation: &Operation, id: &str) -> Result<Option<Call>> {
        Self::query("SELECT c.*,d.call_deadline_at FROM workflow_service_calls c LEFT JOIN dw_service_call_deadlines d ON d.service_call_id=c.id WHERE c.namespace=$1 AND c.workflow_service_operation_id=$2 AND c.id=$3 LIMIT 1")
            .bind(self.namespace.as_ref()).bind(operation.row.id.as_str()).bind(id.trim())
            .fetch_optional(&mut *connection).await?
            .map(|row| Self::service_call_row(&row)).transpose()
    }

    /// The catalog lookups `executeOperation` answers before any input check.
    pub(crate) async fn service_operation_exists(&self, names: [&str; 3]) -> Result<()> {
        let mut tx = self.begin_read().await?;
        self.required_operation(&mut tx, names).await?;
        tx.commit().await?;
        Ok(())
    }

    /// The catalog and call lookups `serviceCallCancel` answers before its input check.
    pub(crate) async fn service_call_exists(&self, names: [&str; 3], id: &str) -> Result<()> {
        let mut tx = self.begin_read().await?;
        let (endpoint, service, operation) = self.required_operation(&mut tx, names).await?;
        if self.find_service_call(&mut tx, &operation, id).await?.is_none() {
            return Err(call_not_found(&endpoint, &service, &operation, id.trim()));
        }
        tx.commit().await?;
        Ok(())
    }

    /// `SearchAttributeValueValidator::validateForNamespace` on the execute
    /// request's attributes; PHP keeps only their types, which these
    /// handlers never read.
    pub(crate) async fn validate_service_call_search_attributes(&self, attributes: Value) -> Result<()> {
        let definitions = Self::query("SELECT name,type FROM search_attribute_definitions WHERE namespace=$1")
            .bind(self.namespace.as_ref()).fetch_all(&self.pool).await?
            .iter()
            .map(|row| Ok((DB::string(row, "name")?, DB::string(row, "type")?)))
            .collect::<Result<std::collections::BTreeMap<_, _>>>()?;
        let attributes: Map<String, Value> = entries(&attributes).into_iter().collect();
        let limits = super::search_attribute_upserts::Limits::from_env()?;
        super::search_attribute_upserts::validate(&definitions, &attributes, &Map::new(), &limits)
            .map(|_| ())
            .map_err(|messages| laravel_rules::failure(vec![("search_attributes".to_owned(), messages)]).expect("messages"))
    }

    /// The rest of `executeOperation`: idempotent replay, admission, dispatch.
    pub(crate) async fn execute_service_call(&self, names: [&str; 3], execution: Execution, caller: Caller) -> Result<(StatusCode, Value)> {
        let mut tx = self.begin().await?;
        let (endpoint, service, operation) = self.required_operation(&mut tx, names).await?;
        let mode = match execution.mode_override.as_deref().or(Some(operation.mode())).map(|mode| mode.trim().to_ascii_lowercase()) {
            Some(mode) if mode == "sync" => "sync",
            _ => "async",
        };
        let request = BoundaryRequest {
            caller: &caller,
            caller_namespace: execution.caller_namespace.clone(),
            target_namespace: operation.row.namespace.clone().unwrap_or_default(),
            endpoint: &endpoint,
            service: &service,
            operation: &operation,
            mode,
        };
        let kind = binding_kind(operation.kind());
        if let Some(key) = execution.idempotency_key.as_deref().map(str::trim).filter(|key| !key.is_empty())
            && let Some(mut prior) = Self::query("SELECT c.*,d.call_deadline_at FROM workflow_service_calls c LEFT JOIN dw_service_call_deadlines d ON d.service_call_id=c.id WHERE c.namespace=$1 AND c.workflow_service_operation_id=$2 AND c.caller_namespace=$3 AND c.idempotency_key=$4 ORDER BY c.created_at,c.id LIMIT 1")
                .bind(self.namespace.as_ref()).bind(operation.row.id.as_str()).bind(execution.caller_namespace.as_str()).bind(key)
                .fetch_optional(&mut *tx).await?
                .map(|row| Self::service_call_row(&row)).transpose()?
        {
            // `authorizeReplay`: authorization and the circuit, no limits.
            let decision = request.base_decision();
            if !decision.allowed() {
                let call = self.admission_row(&request, &execution, kind, &decision);
                Self::insert_service_call(&mut tx, &call).await?;
                tx.commit().await?;
                return Ok((decision.http_status(), rejection_view(&call, &decision, &endpoint, &service, &operation)));
            }
            // Native commits a call's dispatch with its admission, so an
            // accepted call here has issued its handler.
            let accepted = prior.accepted_shape();
            Self::reconcile_service_call(&mut tx, &mut prior).await?;
            tx.commit().await?;
            let body = with(json!({"accepted": accepted, "idempotent_replay": true, "reason": null}).as_object().cloned().unwrap_or_default(),
                Value::Object(with(Map::from_iter([("found".to_owned(), json!(true))]), Value::Object(plane_view(&prior)))));
            return Ok((if accepted { StatusCode::OK } else { StatusCode::CONFLICT }, Value::Object(body)));
        }
        if let Some(message) = deadline_policy_error(&array_or_null(operation.document("deadline_policy"))) {
            return Err(invalid("deadline_policy", message));
        }
        if !matches!(kind, Some("activity_execution" | "invocable_carrier_request")) {
            return Err(not_implemented());
        }
        let mut decision = request.base_decision();
        // A sync call's concurrency lease, released when the request ends.
        let mut lease = None;
        if decision.allowed() {
            decision = match request.limits() {
                Err(()) => Decision::unavailable(),
                Ok(limits) if limits.rate.is_none() && limits.concurrency.is_none() => {
                    Decision::allow(SHARED_POLICY, decision.metadata)
                }
                Ok(limits) => {
                    let (admitted, reserved) = Self::admit_with_counters(&mut tx, &request, limits, decision.metadata).await?;
                    if mode == "sync" {
                        lease = reserved;
                    }
                    admitted
                }
            };
        }
        let mut call = self.admission_row(&request, &execution, kind, &decision);
        Self::insert_service_call(&mut tx, &call).await?;
        if !decision.allowed() {
            tx.commit().await?;
            return Ok((decision.http_status(), rejection_view(&call, &decision, &endpoint, &service, &operation)));
        }
        // `markAccepted` writes nothing new; then an already-past deadline.
        if call.expire(super::store::now()) {
            Self::save_service_call(&mut tx, &mut call).await?;
            tx.commit().await?;
            self.release_lease(lease).await?;
            let plane = with(plane_view(&call), json!({"accepted": true, "idempotent_replay": false, "reason": call.outcome_reason}));
            return Ok((StatusCode::OK, replaced(controller_view(&call, &endpoint, &service, &operation), plane)));
        }
        // PHP releases in a `finally`, so a failed dispatch returns it too.
        let dispatched = self.dispatch_service_call(tx, &mut call, &operation, &execution).await;
        self.release_lease(lease).await?;
        let handler = dispatched?;
        let reason = call.is_failed().then(|| call.outcome_reason.clone().unwrap_or_else(|| "handler_failed".into()));
        let accepted = call.accepted_shape();
        let plane = with(plane_view(&call), json!({"accepted": accepted, "idempotent_replay": false, "handler": handler, "reason": reason}));
        Ok((if accepted { StatusCode::OK } else { StatusCode::CONFLICT },
            replaced(controller_view(&call, &endpoint, &service, &operation), plane)))
    }

    /// A shared admission counter: its value until it expires, else 0.
    async fn admission_counter(connection: &mut DB::Connection, key: &str, now: DateTime<Utc>) -> Result<i64> {
        let row = Self::query("SELECT amount,expires_at FROM dw_service_admission_counters WHERE cache_key=$1")
            .bind(key).fetch_optional(&mut *connection).await?;
        Ok(match row {
            Some(row) if DB::instant(&row, "expires_at")? > now => DB::number(&row, "amount")?.max(0),
            _ => 0,
        })
    }

    /// Stores a counter's new value with a fresh life, as the cache's `put` does.
    async fn put_admission_counter(connection: &mut DB::Connection, key: &str, amount: i64, ttl: i64, now: DateTime<Utc>) -> Result<()> {
        Self::query("DELETE FROM dw_service_admission_counters WHERE cache_key=$1").bind(key).execute(&mut *connection).await?;
        if amount > 0 {
            Self::query("INSERT INTO dw_service_admission_counters(cache_key,amount,expires_at) VALUES ($1,$2,$3)")
                .bind(key).bind(amount).bind(DB::bind_time(now + chrono::Duration::seconds(ttl)))
                .execute(&mut *connection).await?;
        }
        Ok(())
    }

    /// `admitWithLock` under the transition lock: the rate, then the
    /// concurrency limit; an admitted call counts against both. Returns the
    /// decision and the concurrency lease it took, if any.
    async fn admit_with_counters(
        connection: &mut DB::Connection,
        request: &BoundaryRequest<'_>,
        limits: Limits,
        base_metadata: Value,
    ) -> Result<(Decision, Option<String>)> {
        let now = super::store::now();
        Self::query("DELETE FROM dw_service_admission_counters WHERE expires_at<=$1")
            .bind(DB::bind_time(now)).execute(&mut *connection).await?;
        let (rate_key, concurrency_key) = request.counter_keys(now);
        let rate = Self::admission_counter(connection, &rate_key, now).await?;
        let concurrency = Self::admission_counter(connection, &concurrency_key, now).await?;
        let denied = |outcome: &'static str, reason: &str, message: &str, retry: i64, metadata: Value| Decision {
            outcome,
            reason: reason.into(),
            message: Some(message.into()),
            policy: SHARED_POLICY.into(),
            retry_after: Some(retry),
            metadata: union(&json!({"failure_reason": "policy_rejection"}), metadata),
        };
        if let Some(limit) = limits.rate.filter(|limit| rate >= *limit) {
            return Ok((denied("rejected_throttled", "rate_limit_exceeded", "Shared service-call rate capacity is exhausted.",
                limits.rate_retry, json!({"admission_scope": "boundary", "observed_window_count": rate, "requests_per_minute": limit})), None));
        }
        if let Some(limit) = limits.concurrency.filter(|limit| concurrency >= *limit) {
            return Ok((denied("rejected_concurrency_limited", "concurrency_limit_exceeded",
                "Shared service-call concurrency capacity is exhausted.", limits.concurrency_retry,
                json!({"admission_scope": "boundary", "observed_in_flight": concurrency, "max_in_flight": limit})), None));
        }
        if limits.rate.is_some() {
            Self::put_admission_counter(connection, &rate_key, rate + 1, RATE_TTL_SECONDS, now).await?;
        }
        let lease = if limits.concurrency.is_some() {
            Self::put_admission_counter(connection, &concurrency_key, concurrency + 1, CONCURRENCY_TTL_SECONDS, now).await?;
            Some(concurrency_key)
        } else {
            None
        };
        let metadata = union(&base_metadata, json!({"admission_scope": "shared_cache", "boundary_rate_limit": limits.rate,
            "namespace_rate_limit": null, "boundary_concurrency_limit": limits.concurrency, "namespace_concurrency_limit": null}));
        Ok((Decision::allow(SHARED_POLICY, metadata), lease))
    }

    /// `SharedServiceBoundaryPolicy::release` of a sync call's lease.
    async fn release_lease(&self, lease: Option<String>) -> Result<()> {
        let Some(key) = lease else { return Ok(()) };
        let mut tx = self.begin().await?;
        let now = super::store::now();
        let current = Self::admission_counter(&mut tx, &key, now).await?;
        Self::put_admission_counter(&mut tx, &key, current - 1, CONCURRENCY_TTL_SECONDS, now).await?;
        tx.commit().await?;
        Ok(())
    }

    /// `ServiceBoundaryAuditRecorder::record`.
    fn admission_row(&self, request: &BoundaryRequest<'_>, execution: &Execution, kind: Option<&'static str>, decision: &Decision) -> Call {
        let now = super::store::now();
        let operation = request.operation;
        let effective = request.effective_policy();
        let metadata = if values(&decision.metadata).is_empty() { Value::Null } else { decision.metadata.clone() };
        let deadline_policy = array_or_null(operation.document("deadline_policy"));
        let allowed = decision.allowed();
        Call {
            id: super::store::id().to_ascii_lowercase(),
            endpoint_id: Some(request.endpoint.row.id.clone()),
            service_id: Some(request.service.row.id.clone()),
            operation_id: Some(operation.row.id.clone()),
            namespace: Some(request.target_namespace.clone()),
            endpoint_name: request.endpoint.row.name.clone(),
            service_name: request.service.row.name.clone(),
            operation_name: operation.row.name.clone(),
            caller_namespace: Some(request.caller_namespace.clone()),
            caller_instance: execution.caller_instance.clone(),
            caller_run: execution.caller_run.clone(),
            target_namespace: Some(request.target_namespace.clone()),
            status: if allowed { "accepted" } else { "failed" }.into(),
            outcome: Some(decision.outcome.into()),
            operation_mode: request.mode.into(),
            binding_kind: Some(kind.unwrap_or("unresolved").into()),
            target_reference: kind.and(operation.target().map(str::to_owned)),
            idempotency_key: execution.idempotency_key.clone(),
            call_deadline_at: if allowed { deadline_instant(&deadline_policy, now) } else { None },
            deadline_policy,
            idempotency_policy: array_or_null(operation.document("idempotency_policy")),
            cancellation_policy: array_or_null(operation.document("cancellation_policy")),
            retry_policy: array_or_null(operation.document("retry_policy")),
            boundary_policy: if values(&effective).is_empty() { Value::Null } else { effective },
            outcome_metadata: metadata.clone(),
            metadata,
            outcome_category: Some(category(decision.outcome).into()),
            outcome_reason: Some(decision.reason.clone()),
            outcome_message: decision.message.clone(),
            policy_name: Some(decision.policy.clone()),
            retry_after: decision.retry_after,
            principal_subject: Some(request.caller.subject.clone()),
            principal_method: Some(request.caller.method.clone()),
            principal_roles: if request.caller.roles.is_empty() { Value::Null } else { json!(request.caller.roles) },
            principal_tenant: request.caller.tenant.clone(),
            principal_claims: if values(&request.caller.claims).is_empty() { Value::Null } else { request.caller.claims.clone() },
            accepted_at: allowed.then_some(now),
            failed_at: (!allowed).then_some(now),
            failure_message: if allowed { None } else { decision.message.clone() },
            created_at: Some(now),
            updated_at: Some(now),
            ..Call::default()
        }
    }

    /// `dispatchHandler`: each attempt, its bookkeeping, and the retry
    /// backoff waited out with the transaction committed.
    async fn dispatch_service_call(
        &self,
        mut tx: super::transaction::Transaction<'static, DB>,
        call: &mut Call,
        operation: &Operation,
        execution: &Execution,
    ) -> Result<Value> {
        let max = max_attempts(&call.retry_policy);
        let mut result = Value::Null;
        for attempt in 1..=max {
            if call.expire(super::store::now()) {
                Self::save_service_call(&mut tx, call).await?;
            }
            if !call.is_open() {
                tx.commit().await?;
                return Ok(json!({"accepted": call.accepted_shape(), "reason": call.outcome_reason, "retry_scheduled": false}));
            }
            let started = super::store::now();
            result = Self::dispatch_attempt(&mut tx, call, operation, execution, attempt, max).await?;
            Self::reconcile_service_call(&mut tx, call).await?;
            let retry = attempt < max && result["retry_scheduled"] == json!(true);
            let backoff = retry.then(|| backoff_seconds(&call.retry_policy, attempt));
            let entry = Self::attempt_entry(call, &result, attempt, started, retry, backoff);
            Self::record_attempt(&mut tx, call, entry).await?;
            if !retry {
                break;
            }
            if let Some(backoff) = backoff.filter(|backoff| *backoff > 0.0) {
                tx.commit().await?;
                tokio::time::sleep(std::time::Duration::from_secs_f64(backoff.min(86400.0))).await;
                tx = self.begin().await?;
                *call = Self::query("SELECT c.*,d.call_deadline_at FROM workflow_service_calls c LEFT JOIN dw_service_call_deadlines d ON d.service_call_id=c.id WHERE c.id=$1")
                    .bind(call.id.as_str()).fetch_one(&mut *tx).await
                    .map_err(RuntimeError::from)
                    .and_then(|row| Self::service_call_row(&row))?;
            }
        }
        // The response reads the call again, which may expire it.
        Self::reconcile_service_call(&mut tx, call).await?;
        tx.commit().await?;
        Ok(result)
    }

    /// One attempt of an `activity_execution` or `invocable_carrier_request`
    /// handler.
    async fn dispatch_attempt(
        connection: &mut DB::Connection,
        call: &mut Call,
        operation: &Operation,
        execution: &Execution,
        attempt: i64,
        max: i64,
    ) -> Result<Value> {
        let binding = array_value(operation.document("handler_binding"));
        let target = operation.target().and_then(|target| string_from(&json!(target)));
        if binding_kind(operation.kind()) == Some("activity_execution") {
            let class = binding_string(&binding, &["activity_class", "class"]).or(target);
            let Some(kind) = binding_string(&binding, &["activity_type", "type"]).or_else(|| class.clone()) else {
                return Self::handler_failure(connection, call, "handler_target_missing", "Activity binding has no activity class or type.", attempt, max).await;
            };
            let id = binding_string(&binding, &["activity_execution_id"]).unwrap_or_else(upper_ulid);
            let mut metadata = json!({"activity_execution_id": id, "activity_class": class, "activity_type": kind});
            for (key, value) in [("connection", &execution.connection), ("queue", &execution.queue)] {
                if let Some(value) = binding_string(&binding, &[key]).or_else(|| value.clone()) {
                    metadata[key] = json!(value);
                }
            }
            call.binding_kind = Some("activity_execution".into());
            call.target_reference = Some(id.clone());
            call.linked_instance = binding_string(&binding, &["workflow_instance_id", "owning_workflow_instance_id"]);
            call.linked_run = binding_string(&binding, &["workflow_run_id", "owning_workflow_run_id"]);
            call.linked_update = None;
            call.metadata = merge_metadata(&call.metadata, &metadata);
            Self::save_service_call(connection, call).await?;
            return Ok(json!({"accepted": true, "kind": "activity_execution", "activity_execution_id": id,
                "activity_class": class, "activity_type": kind}));
        }
        let Some(endpoint) = binding_string(&binding, &["carrier_endpoint", "endpoint", "url"]).or(target) else {
            return Self::handler_failure(connection, call, "handler_target_missing",
                "Invocable carrier binding has no endpoint or carrier reference.", attempt, max).await;
        };
        let handler = binding_string(&binding, &["carrier_handler", "handler", "handler_name"]);
        let carrier = binding_string(&binding, &["carrier", "carrier_name"]);
        let id = binding_string(&binding, &["carrier_request_id"]).unwrap_or_else(upper_ulid);
        let mut metadata = json!({"carrier_request_id": id, "carrier_endpoint": endpoint});
        if let Some(handler) = &handler {
            metadata["carrier_handler"] = json!(handler);
        }
        if let Some(carrier) = &carrier {
            metadata["carrier"] = json!(carrier);
        }
        call.binding_kind = Some("invocable_carrier_request".into());
        call.target_reference = Some(id.clone());
        call.linked_instance = binding_string(&binding, &["workflow_instance_id", "bound_workflow_instance_id"]);
        call.linked_run = None;
        call.linked_update = None;
        call.metadata = merge_metadata(&call.metadata, &metadata);
        Self::save_service_call(connection, call).await?;
        Ok(json!({"accepted": true, "kind": "invocable_carrier_request", "carrier_request_id": id, "carrier_endpoint": endpoint,
            "carrier_handler": handler, "carrier": carrier}))
    }

    /// `markHandlerFailure`: a retry while attempts remain and the failure
    /// type is retryable, else the call fails.
    async fn handler_failure(
        connection: &mut DB::Connection,
        call: &mut Call,
        reason: &str,
        message: &str,
        attempt: i64,
        max: i64,
    ) -> Result<Value> {
        if call.expire(super::store::now()) {
            Self::save_service_call(connection, call).await?;
        }
        if call.is_terminal() {
            return Ok(json!({"accepted": false, "kind": call.binding_kind, "reason": call.outcome_reason,
                "message": call.failure_message, "retry_scheduled": false}));
        }
        let typed = json!({"failure_type": reason, "service_error_type": reason, "caller_observed_error_type": reason,
            "typed_error_message": message});
        let outcome_metadata = merge_policy(&[&json!({"failure_reason": "handler_failure"}), &typed]);
        let retry = attempt < max && !non_retryable(&call.retry_policy).iter().any(|kind| kind == reason);
        if retry {
            call.status = "started".into();
            call.outcome = Some("accepted".into());
            call.outcome_category = Some("accepted".into());
            call.outcome_reason = Some("retry_scheduled".into());
            call.outcome_message = Some(message.into());
            call.outcome_metadata = Value::Null;
            call.metadata = merge_metadata(&call.metadata, &json!({"last_retry_failure": outcome_metadata}));
            call.started_at = call.started_at.or_else(|| Some(super::store::now()));
            call.failure_message = None;
            call.failed_at = None;
        } else {
            call.status = "failed".into();
            call.outcome = Some("handler_failed".into());
            call.outcome_category = Some("handler".into());
            call.outcome_reason = Some(reason.into());
            call.outcome_message = Some(message.into());
            call.metadata = merge_metadata(&call.metadata, &outcome_metadata);
            call.outcome_metadata = outcome_metadata;
            call.failure_message = Some(message.into());
            call.failed_at = Some(super::store::now());
        }
        Self::save_service_call(connection, call).await?;
        Ok(json!({"accepted": false, "kind": call.binding_kind, "reason": reason, "message": message, "error_type": reason,
            "failure_type": reason, "service_error_type": reason, "retry_scheduled": retry}))
    }

    /// `serviceCallAttemptEntry`: null fields left out.
    fn attempt_entry(call: &Call, result: &Value, attempt: i64, started: DateTime<Utc>, retry: bool, backoff: Option<f64>) -> Value {
        let mut entry = Map::new();
        let failure = failure_type(&array_value(&call.outcome_metadata)).or_else(|| failure_type(&array_value(result)));
        for (key, value) in [
            ("attempt", json!(attempt)),
            ("status", json!(call.status)),
            ("outcome", json!(call.outcome)),
            ("outcome_reason", json!(string_from(at(result, "reason")).or_else(|| call.outcome_reason.clone()))),
            ("failure_type", json!(failure)),
            ("failure_message", json!(call.failure_message.clone().or_else(|| string_from(at(result, "message"))))),
            ("started_at", micros(Some(started))),
            ("completed_at", micros(call.completed_at)),
            ("failed_at", micros(call.failed_at)),
            ("cancelled_at", micros(call.cancelled_at)),
            ("retry_scheduled", json!(retry)),
            ("scheduled_backoff_seconds", json!(backoff)),
        ] {
            if !value.is_null() {
                entry.insert(key.into(), value);
            }
        }
        php_document(Value::Object(entry))
    }

    /// `recordServiceCallAttempt`.
    async fn record_attempt(connection: &mut DB::Connection, call: &mut Call, entry: Value) -> Result<()> {
        let mut metadata = array_value(&call.metadata);
        let existing = at(&metadata, "service_call_attempts");
        let mut attempts: Vec<Value> = if is_array(existing) { values(existing).into_iter().filter(is_array).collect() } else { Vec::new() };
        attempts.push(entry);
        let count = attempts.len();
        metadata = merge_policy(&[&metadata, &json!({"service_call_attempts": attempts.clone(), "retry_attempt_count": count})]);
        call.metadata = metadata;
        if call.is_failed() {
            call.outcome_metadata = merge_policy(&[&array_value(&call.outcome_metadata),
                &json!({"service_call_attempts": attempts, "retry_attempt_count": count})]);
        }
        Self::save_service_call(connection, call).await
    }

    /// `serviceCallShow`: the controller view of the row as read, merged with
    /// the control plane's after a lazy expiry.
    pub(crate) async fn show_service_call(&self, names: [&str; 3], id: &str) -> Result<Value> {
        let mut tx = self.begin().await?;
        let (endpoint, service, operation) = self.required_operation(&mut tx, names).await?;
        let Some(read) = self.find_service_call(&mut tx, &operation, id).await? else {
            return Err(call_not_found(&endpoint, &service, &operation, id.trim()));
        };
        let mut call = read.clone();
        Self::reconcile_service_call(&mut tx, &mut call).await?;
        tx.commit().await?;
        let plane = with(Map::from_iter([("found".to_owned(), json!(true))]), Value::Object(plane_view(&call)));
        Ok(replaced(controller_view(&read, &endpoint, &service, &operation), plane))
    }

    /// `cancelCall`, then the controller's view of the call as it is now.
    pub(crate) async fn cancel_service_call(&self, names: [&str; 3], id: &str, reason: Option<String>) -> Result<(StatusCode, Value)> {
        let mut tx = self.begin().await?;
        let (endpoint, service, operation) = self.required_operation(&mut tx, names).await?;
        let Some(mut call) = self.find_service_call(&mut tx, &operation, id).await? else {
            return Err(call_not_found(&endpoint, &service, &operation, id.trim()));
        };
        let refusal = if call.expire(super::store::now()) {
            Self::save_service_call(&mut tx, &mut call).await?;
            Some("service_call_terminal")
        } else if call.is_terminal() {
            Some("service_call_terminal")
        } else if !cancellation_allowed(&call.cancellation_policy) {
            Some("cancellation_not_allowed")
        } else {
            None
        };
        if refusal.is_none()
            && at(&array_value(&call.cancellation_policy), "propagate_to_linked_workflow") == &Value::Bool(true)
            && call.linked_instance.as_deref().is_some_and(|instance| !instance.is_empty())
        {
            // Cancelling the linked workflow comes with the workflow-bound handlers.
            return Err(not_implemented());
        }
        if refusal.is_none() {
            call.status = "cancelled".into();
            call.outcome = Some("cancelled".into());
            call.outcome_category = Some("handler".into());
            call.outcome_reason = Some("cancelled_by_request".into());
            call.outcome_message = reason.clone();
            call.outcome_metadata = json!({"failure_reason": "cancellation"});
            call.metadata = if values(&call.metadata).is_empty() { Value::Null } else { call.metadata.clone() };
            call.cancelled_at = call.cancelled_at.or_else(|| Some(super::store::now()));
            call.failure_message = reason.or_else(|| call.failure_message.clone());
            Self::save_service_call(&mut tx, &mut call).await?;
        }
        Self::reconcile_service_call(&mut tx, &mut call).await?;
        tx.commit().await?;
        let accepted = refusal.is_none();
        let plane = with(plane_view(&call), json!({"accepted": accepted, "reason": refusal}));
        Ok((if accepted { StatusCode::OK } else { StatusCode::CONFLICT },
            replaced(controller_view(&call, &endpoint, &service, &operation), plane)))
    }
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn policies_merge_as_php_arrays() {
        let merged = merge_policy(&[&json!({"a": {"x": 1, "y": [1]}, "b": 1}), &json!({"a": {"y": [2], "z": null}, "c": {}})]);
        assert_eq!(merged, json!({"a": {"x": 1, "y": [2], "z": null}, "b": 1, "c": []}));
        assert_eq!(merge_metadata(&Value::Null, &json!([])), Value::Null);
        assert_eq!(without_admission_controls(&json!({"rate_limit": 1, "auth": {"concurrency": 2, "k": 3}})), json!({"auth": {"k": 3}}));
    }

    #[test]
    fn limits_follow_the_shared_policy() {
        for (value, expected) in [(json!(null), Ok(None)), (json!(0), Ok(None)), (json!("0"), Ok(None)), (json!(""), Ok(None)),
            (json!(3), Ok(Some(3))), (json!("12"), Ok(Some(12))), (json!(-1), Err(())), (json!(1.5), Err(())),
            (json!("lots"), Err(())), (json!("012"), Err(())), (json!(true), Err(()))]
        {
            assert_eq!(limit(&value), expected, "{value}");
        }
        for (value, expected) in [(json!({}), Ok(1)), (json!({"retry_after_seconds": 90}), Ok(60)),
            (json!({"retry_after_seconds": "0"}), Ok(0)), (json!({"retry_after_seconds": "007"}), Err(())),
            (json!({"retry_after_seconds": -1}), Err(()))]
        {
            assert_eq!(retry_after_seconds(&value), expected, "{value}");
        }
    }

    #[test]
    fn retries_read_the_policy_as_php() {
        assert_eq!(max_attempts(&json!({"max_attempts": 3})), 3);
        assert_eq!(max_attempts(&json!({"maximumAttempts": "40"})), 10);
        assert_eq!(max_attempts(&json!({"max_attempts": 0})), 1);
        assert_eq!(backoff_seconds(&json!({}), 1), 0.0);
        assert_eq!(backoff_seconds(&json!({"backoff_seconds": [1, 2.5]}), 2), 2.5);
        assert_eq!(backoff_seconds(&json!({"initial_interval_seconds": 1, "maximum_interval_seconds": 3}), 3), 3.0);
        assert_eq!(non_retryable(&json!({"non_retryable_error_types": ["a", "", 3]})), vec!["a".to_owned()]);
        assert!(!cancellation_allowed(&json!({"mode": "none"})));
        assert!(!cancellation_allowed(&json!({"cancellable": false})));
        assert!(cancellation_allowed(&Value::Null));
    }

    #[test]
    fn arguments_follow_the_envelope_rules() {
        assert_eq!(arguments_blob(&json!("x")).unwrap(), Some(("arguments", "x".to_owned())));
        assert_eq!(arguments_blob(&json!({"codec": "avro", "blob": "b"})).unwrap(), Some(("arguments.blob", "b".to_owned())));
        assert_eq!(arguments_blob(&json!([1, 2])).unwrap(), None);
        assert_eq!(arguments_blob(&json!({"a": 1})).unwrap(), None);
        let message = |value: Value| match arguments_blob(&value) {
            Err(RuntimeError::Protocol { response, .. }) => response["errors"].clone(),
            other => panic!("{other:?}"),
        };
        assert_eq!(message(json!({"codec": "avro"})),
            json!({"arguments": ["The arguments envelope must contain only codec and blob, or codec and external storage."]}));
        assert_eq!(message(json!({"codec": "avro", "blob": 5})), json!({"arguments.blob": ["The payload envelope blob must be a string."]}));
        assert_eq!(message(json!({"codec": null, "blob": "b"})),
            json!({"arguments.codec": ["The payload envelope codec must be a non-empty string."]}));
        assert!(message(json!({"codec": "json", "blob": "b"}))["arguments.codec"][0].as_str().unwrap().starts_with("unsupported_payload_codec"));
    }

    #[test]
    fn php_casts_match() {
        assert_eq!([php_int(&json!("12abc")), php_int(&json!(3.9)), php_int(&json!(true)), php_int(&json!([1]))], [12, 3, 1, 1]);
        assert_eq!([php_string_int(" 7"), php_string_int("1e3"), php_string_int("-2.9x"), php_string_int("abc"), php_string_int(".5")],
            [7, 1000, -2, 0, 0]);
        assert_eq!(positive_int(&json!(" 7 ")), Some(7));
        assert_eq!(positive_int(&json!(-1)), None);
    }
}
