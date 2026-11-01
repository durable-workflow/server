//! The parts of `/api/cluster/info` that describe the server itself
//! (`HealthController::clusterInfo`): its version, capabilities and discovery
//! paths. Each answer describes what native does. Where published PHP
//! advertises something native does not serve, native says so instead of
//! copying PHP's value, and `native_unqualified` names every member it leaves
//! out (Server #325).
use serde_json::{Value, json};

/// The release version the image was built from (`native-X.Y.Z` without the
/// prefix, passed as `DW_SERVER_VERSION` at build time), else the crate's.
pub(super) fn version() -> &'static str {
    option_env!("DW_SERVER_VERSION").filter(|version| !version.is_empty()).unwrap_or(env!("CARGO_PKG_VERSION"))
}

/// PHP's capability map, answered for native. `true` where native serves the
/// feature; `false` where it does not, including each `*_contract` member
/// whose manifest document native does not publish.
pub(super) fn capabilities() -> Value {
    let served = [
        "workflow_tasks",
        "activity_tasks",
        "signals",
        "queries",
        "updates",
        "schedules",
        "schedule_jitter",
        "schedule_max_runs",
        "search_attributes",
        "history_export",
        "continue_as_new",
        "child_workflows",
        "activity_timeouts",
        "activity_retry_policy",
        "child_workflow_retry_policy",
        "child_workflow_timeouts",
        "parent_close_policy",
        "non_retryable_failures",
        "worker_sessions",
        "history_retention",
        "payload_codec_envelope",
        "payload_codec_envelope_responses",
        "runtime_external_payload_transport",
        "workflow_activity_result_inspection",
        "service_catalog",
        "service_execution",
        "nexus",
        "workflow_streams",
        "message_streams",
        "principal_attribution_contract",
        "embedded_v2_import",
        "waterline_v1_projection",
    ];
    // Manifests PHP publishes beside these flags; native publishes none of them.
    let unpublished = [
        "namespace_runtime_contract",
        "activity_runtime_contract",
        "bridge_adapter_outcome_contract",
        "external_executor_config_contract",
        "invocable_carrier_contract",
        "replay_verification_contract",
        "search_attribute_runtime_contract",
        "schedules_runtime_contract",
        "single_region_failover_contract",
        "timer_runtime_contract",
        "child_workflow_runtime_contract",
        "saga_runtime_contract",
        "heartbeat_runtime_contract",
        "php_sdk_conformance_contract",
        "python_sdk_parity_contract",
        "prerelease_readiness_contract",
        "skew_refusal_matrix_contract",
        "worker_versioning_runtime_contract",
        "migration_runtime_contract",
        "workflow_lifecycle_contract",
        "workflow_update_runtime_contract",
    ];
    let mut map = serde_json::Map::new();
    for name in served {
        map.insert(name.into(), Value::Bool(true));
    }
    for name in unpublished {
        map.insert(name.into(), Value::Bool(false));
    }
    map.insert("payload_codecs".into(), json!(["avro"]));
    map.insert(
        "avro_value_protocol".into(),
        json!({"schema": "durable_workflow.protocol.Value", "fingerprint": super::history_export::VALUE_FINGERPRINT,
            "framing": "single_object", "magic_hex": "c301"}),
    );
    // Native sends uncompressed responses.
    map.insert("response_compression".into(), json!([]));
    Value::Object(map)
}

/// Discovery paths native serves. It has no `include=diagnostics` view.
pub(super) fn discovery() -> Value {
    json!({"mode": "compatibility", "operator_metrics_path": "/api/system/operator-metrics",
        "task_repair_path": "/api/system/repair"})
}

/// Members published PHP's cluster info carries that native leaves out, each
/// with the reason.
pub(super) fn unqualified() -> Value {
    json!({
        "supported_sdk_versions": "Native has not qualified SDK version ranges of its own.",
        "client_compatibility": "Native publishes no client compatibility matrix yet.",
        "topology": "Native runs as one process role and publishes no topology manifest.",
        "discovery.diagnostics_query": "Native serves no include=diagnostics view.",
        "discovery.diagnostics_path": "Native serves no include=diagnostics view.",
        "structural_limits.external_payload_transaction_bytes": "Native reads no DW_V2_LIMIT_EXTERNAL_PAYLOAD_TRANSACTION_BYTES.",
        "conformance_manifests": "Native publishes none of PHP's *_contract, platform_conformance_suite, platform_protocol_specs or migration manifests beyond those listed in this document.",
    })
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn every_published_capability_is_answered() {
        let caps = capabilities();
        // Published PHP 2.11.7's capability names.
        for name in ["workflow_tasks", "nexus", "response_compression", "avro_value_protocol", "payload_codecs",
            "workflow_update_runtime_contract", "waterline_v1_projection", "embedded_v2_import"] {
            assert!(caps.get(name).is_some(), "{name}");
        }
        assert_eq!(caps.as_object().unwrap().len(), 56);
        assert_eq!(caps["response_compression"], json!([]));
        assert_eq!(caps["timer_runtime_contract"], json!(false));
        assert_eq!(caps["principal_attribution_contract"], json!(true));
    }

    #[test]
    fn the_version_is_never_empty() {
        assert!(!version().is_empty());
    }
}
