//! Runtime external payload discovery in `/api/cluster/info`, as published
//! PHP's `HealthController::clusterInfo` reports it: the ordinary request
//! limits, the request namespace's storage policy and the transport manifest
//! that SDKs read before uploading a payload. The same manifest is the worker
//! capability `runtime_external_payload_transport`.
//!
//! Native differs from Server 2.11.1 in three manifest members, each a feature
//! native does not serve: no prepared local activity or cancellation-scope
//! completion context (`prepared_schema`, `scope_schema`), and no audit log
//! events (`audit_events`).
use axum::http::StatusCode;
use serde_json::{Value, json};

use super::{ExternalPayloadConfig, Result, Runtime, RuntimeError};
use crate::external_payload::REFERENCE_SCHEMA;

/// `RuntimeExternalPayloadReference::transportManifest()`, with native's limits.
pub(crate) fn transport_manifest() -> Value {
    let config = ExternalPayloadConfig::from_env().unwrap_or_default();
    json!({
        "schema": "durable-workflow.v2.runtime-external-payload-transport.v1",
        "version": 1,
        "reference_schema": REFERENCE_SCHEMA,
        "mode": "authenticated_namespace_runtime",
        "upload": {
            "method": "POST",
            "path": "/api/external-payloads/v1",
            "content_type": "application/octet-stream",
            "declared_metadata_headers": ["X-Durable-Workflow-Payload-Codec",
                "X-Durable-Workflow-Payload-Size", "X-Durable-Workflow-Payload-SHA256"],
            "idempotency": "content_addressed_per_namespace",
            "completion_context": {
                "schema": "durable-workflow.v2.payload-completion-context.v1",
                "prepared_schema": null,
                "scope_schema": null,
                "header": "X-Durable-Workflow-Payload-Completion",
                "use": "retry_draining_refusal_for_current_worker_completion_only",
                "max_bytes_per_lease": config.completion_max_bytes.unwrap_or(config.max_bytes),
                "max_slots_per_lease": super::activity_completion_uploads::MAX_SLOTS_PER_LEASE,
                "fenced_or_stale_admission": "refuse",
            },
        },
        "fetch": {
            "method": "GET",
            "path_template": "/api/external-payloads/v1/{referenceId}",
            "content_type": "application/octet-stream",
            "cache_control": "immutable, max-age=60, private",
        },
        "authorization": {
            "authentication": "normal_runtime_role_credential",
            "namespace_header": "X-Namespace",
            "roles": ["worker", "operator", "admin"],
            "cross_namespace_lookup": "not_found_without_existence_disclosure",
            "provider_credentials_exposed": false,
        },
        "limits": {
            "max_payload_bytes": config.max_bytes,
            "buffering": "bounded_by_declared_and_observed_size",
            "request_timeout_seconds": config.timeout.as_secs(),
            "abandoned_upload_expiry_seconds": config.abandoned_seconds,
        },
        "integrity": {
            "algorithm": "sha256",
            "upload_verified_before_reference_commit": true,
            "fetch_verified_before_response": true,
            "sdk_must_verify_before_decode": true,
        },
        "retention": {
            "authority": "namespace_runtime",
            "client_delete_supported": false,
            "sdk_cache_may_delete_backing_object": false,
            "reference_claim": "first_verified_state-bearing_request",
        },
        "direct_provider_adapters": {
            "required": false,
            "default_enabled": false,
            "self_hosted_only": true,
            "capability_negotiated": true,
        },
        "typed_outcomes": {
            "external_payload_not_found": {"status": 404, "retryable": false},
            "external_payload_expired": {"status": 410, "retryable": false},
            "external_payload_unauthorized": {"statuses": [401, 403], "retryable": false},
            "external_payload_unavailable": {"status": 503, "retryable": true},
            "external_payload_oversized": {"status": 413, "retryable": false},
            "external_payload_unsupported": {"statuses": [415, 422], "retryable": false},
            "external_payload_integrity_mismatch": {"status": 422, "retryable": false},
            "external_payload_namespace_bytes_exhausted": {"status": 429, "retryable": true},
            "external_payload_namespace_objects_exhausted": {"status": 429, "retryable": true},
            "external_payload_namespace_quota_unavailable": {"status": 503, "retryable": true},
            "external_payload_completion_invalid": {"status": 422, "retryable": false},
            "external_payload_completion_lease_rejected": {"status": 409, "retryable": false},
            "external_payload_completion_conflict": {"status": 409, "retryable": false},
            "external_payload_completion_budget_exhausted": {"status": 429, "retryable": true},
        },
        "audit_events": [],
        "secrets_in_references_or_logs": false,
    })
}

/// `server.limits`, from the settings native enforces. The two pending counts
/// are reported as PHP reports them; neither server enforces them.
pub(super) fn limits() -> Result<Value> {
    let attributes = super::search_attribute_upserts::Limits::from_env()?;
    Ok(json!({
        "max_payload_bytes": super::payload_limits::max_payload_bytes(),
        "max_memo_bytes": super::schedules::max_memo_bytes(),
        "max_search_attributes": super::search_attributes::configured_limit()?,
        "max_search_attribute_key_length": attributes.key_length,
        "max_search_attribute_value_bytes": attributes.value_bytes,
        // Native refuses to start with any other value (config audit).
        "max_operation_name_length": 256,
        "max_pending_activities": setting("DW_MAX_PENDING_ACTIVITIES", "WORKFLOW_MAX_PENDING_ACTIVITIES", 2000),
        "max_pending_children": setting("DW_MAX_PENDING_CHILDREN", "WORKFLOW_MAX_PENDING_CHILDREN", 2000),
    }))
}

/// `(int) EnvAuditor::env(primary, legacy, default)`.
fn setting(primary: &str, legacy: &str, default: i64) -> i64 {
    let primary = std::env::var(primary).ok().filter(|value| !super::memo_limits::typed_null(value));
    primary
        .or_else(|| std::env::var(legacy).ok())
        .map_or(default, |value| super::memo_limits::cast_integer(&value))
}

/// `DW_SERVER_ID`, else the host name, as PHP's `server.server_id` reads it.
pub(super) fn server_id() -> String {
    let primary = std::env::var("DW_SERVER_ID").ok().filter(|value| !super::memo_limits::typed_null(value));
    primary
        .or_else(|| std::env::var("WORKFLOW_SERVER_ID").ok())
        .or_else(|| std::fs::read_to_string("/proc/sys/kernel/hostname").ok().map(|name| name.trim().to_owned()))
        .or_else(|| std::env::var("HOSTNAME").ok())
        .unwrap_or_default()
}

impl Runtime {
    /// `HealthController::namespacePolicy` for the request namespace. A
    /// namespace that does not exist still answers, with `exists: false`.
    pub(super) async fn namespace_discovery(&self) -> Result<Value> {
        let name = self.namespace().to_ascii_lowercase();
        let row = match self.describe_namespace(&name).await {
            Ok(row) => Some(row),
            Err(RuntimeError::Protocol { status, .. }) if status == StatusCode::NOT_FOUND => None,
            Err(error) => return Err(error),
        };
        let field = |key: &str| row.as_ref().map_or(Value::Null, |row| row[key].clone());
        let policy = row.as_ref().map(|row| &row["external_payload_storage"]);
        Ok(json!({
            "name": name,
            "exists": row.is_some(),
            "status": field("status"),
            "retention_mode": field("retention_mode"),
            "retention_days": field("retention_days"),
            "external_payload_storage": storage_policy(policy.unwrap_or(&Value::Null)),
        }))
    }
}

/// `HealthController::externalPayloadStoragePolicy`. Native stores only the
/// `local` driver; any other driver (carried over by a takeover) cannot resolve.
fn storage_policy(policy: &Value) -> Value {
    let configured = policy.as_object().is_some_and(|map| !map.is_empty())
        || policy.as_array().is_some_and(|items| !items.is_empty());
    let enabled = configured && policy["enabled"] != false;
    let threshold = match &policy["threshold_bytes"] {
        Value::Null => super::payload_limits::max_payload_bytes() as i64,
        Value::Number(number) => number.as_i64().unwrap_or_else(|| number.as_f64().unwrap_or(0.0) as i64),
        Value::String(text) => super::memo_limits::cast_integer(text),
        Value::Bool(value) => i64::from(*value),
        _ => 1,
    };
    let resolved = enabled && policy["driver"] == "local";
    let status = match (configured, enabled, resolved) {
        (false, _, _) => "unconfigured",
        (true, false, _) => "disabled",
        (true, true, true) => "available",
        (true, true, false) => "driver_unavailable",
    };
    json!({
        "schema": REFERENCE_SCHEMA,
        "version": 1,
        "configured": configured,
        "enabled": enabled,
        "status": status,
        "configuration_error": (enabled && !resolved).then_some("external_payload_storage_driver_unsupported"),
        "threshold_bytes": threshold,
        "transport": transport_manifest(),
        "provider_details_exposed": false,
        "direct_provider_adapters": {"required": false, "default_enabled": false, "capability_negotiated": true},
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn storage_policy_states_follow_php() {
        let status = |policy: Value| storage_policy(&policy)["status"].clone();
        assert_eq!(status(Value::Null), "unconfigured");
        assert_eq!(status(json!({})), "unconfigured");
        assert_eq!(status(json!({"driver": "local", "enabled": false, "threshold_bytes": 32})), "disabled");
        assert_eq!(status(json!({"driver": "local"})), "available");
        assert_eq!(status(json!({"driver": "local", "enabled": null})), "available");
        let s3 = storage_policy(&json!({"driver": "s3", "enabled": true}));
        assert_eq!((s3["status"].clone(), s3["configuration_error"].clone()),
            (json!("driver_unavailable"), json!("external_payload_storage_driver_unsupported")));
        assert_eq!(storage_policy(&json!({"driver": "local", "enabled": false, "threshold_bytes": 32}))["threshold_bytes"], 32);
        assert_eq!(storage_policy(&json!({"driver": "local", "threshold_bytes": "64"}))["threshold_bytes"], 64);
    }
}
