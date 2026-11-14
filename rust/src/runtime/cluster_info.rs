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

/// What `/api/ready` and `/api/cluster/info` report about the build:
/// `(development, qualification)`. A release image carries its version in
/// `DW_SERVER_VERSION`; a source build without one is a development build.
pub(super) fn build_identity() -> (bool, &'static str) {
    build_identity_for(option_env!("DW_SERVER_VERSION"))
}

fn build_identity_for(release_version: Option<&str>) -> (bool, &'static str) {
    if release_version.is_some_and(|version| !version.is_empty()) {
        (false, "release")
    } else {
        (true, "unpublished_execution_slice")
    }
}

#[cfg(test)]
mod build_identity_tests {
    use super::build_identity_for;

    #[test]
    fn only_a_build_with_a_release_version_reports_itself_released() {
        assert_eq!(build_identity_for(Some("3.0.1")), (false, "release"));
        assert_eq!(build_identity_for(Some("3.1.0-rc.1")), (false, "release"));
        assert_eq!(build_identity_for(Some("")), (true, "unpublished_execution_slice"));
        assert_eq!(build_identity_for(None), (true, "unpublished_execution_slice"));
    }
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
        super::version_usage::CAPABILITY,
        "history_export",
        "continue_as_new",
        "child_workflows",
        "activity_timeouts",
        "activity_retry_policy",
        "child_workflow_retry_policy",
        "child_workflow_timeouts",
        "child_workflow_start_turn",
        "condition_wait_idempotent_reopen",
        "condition_wait_occurrence_deadline",
        "update_outcome_reads",
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
        super::task_queue_pauses::CAPABILITY,
        // Server #934: a signal or update request_id is a durable command key.
        "command_request_keys",
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

/// Native-qualified client ranges: the single source of both
/// `supported_sdk_versions` and `client_compatibility.clients`, so the two can
/// never disagree (server#1005). Each lower bound is the oldest release that
/// passed installed acceptance against native; the cluster-capabilities
/// fixture records the source of each. The upper bound is the next major.
const CLIENT_RANGES: [(&str, &str, &str); 4] = [
    ("php", "sdk-php", ">=3.8.1,<4.0.0"),
    ("python", "sdk-python", ">=2.13.0,<3.0.0"),
    ("rust", "sdk-rust", ">=4.10.3,<5.0.0"),
    ("cli", "cli", ">=2.0.0,<3.0.0"),
];

/// `supported_sdk_versions`, keyed as published PHP keys it.
pub(super) fn supported_sdk_versions() -> Value {
    Value::Object(
        CLIENT_RANGES
            .iter()
            .map(|(key, _, range)| ((*key).to_owned(), json!(range)))
            .collect(),
    )
}

/// `client_compatibility` (published PHP's `ClientCompatibility::info`, schema
/// version 2), citing only protocols native serves: the auth composition
/// contract, the control plane with its request contract, and the worker
/// protocol. Their identities are read from the values cluster info serves, so
/// the block cannot cite one native does not. PHP's skew refusal matrix and
/// external execution contracts are not published by native, so they are not
/// cited: the CLI checks every protocol the block requires.
pub(super) fn client_compatibility(auth_composition: &Value, request_contract: &Value) -> Value {
    let identity = |contract: &Value| json!({"schema": contract["schema"], "version": contract["version"]});
    let served = ["auth_composition.version", "control_plane.version", "control_plane.request_contract"];
    let mut clients = serde_json::Map::new();
    // PHP's order: sdk-php, cli, sdk-python, sdk-rust.
    for name in ["sdk-php", "cli", "sdk-python", "sdk-rust"] {
        let (_, _, range) = CLIENT_RANGES.iter().find(|(_, client, _)| *client == name).expect("every client has a range");
        let mut requires: Vec<&str> = served.to_vec();
        if name != "cli" {
            requires.push("worker_protocol.version");
        }
        clients.insert(name.to_owned(), json!({"supported_versions": range, "requires": requires}));
    }
    json!({
        "schema": "durable-workflow.v2.client-compatibility",
        "version": 2,
        "authority": "protocol_manifests",
        "top_level_version_role": "informational",
        "fail_closed": true,
        "required_protocols": {
            "auth_composition": identity(auth_composition),
            "control_plane": {"version": "2", "header": "X-Durable-Workflow-Control-Plane-Version",
                "request_contract": identity(request_contract)},
            "worker_protocol": {"version": super::WORKER_PROTOCOL_VERSION, "header": "X-Durable-Workflow-Protocol-Version"},
        },
        "clients": clients,
    })
}

/// Members published PHP's cluster info carries that native leaves out, each
/// with the reason.
pub(super) fn unqualified() -> Value {
    json!({
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
        // PHP's names plus native's child turn, occurrence-id reopen,
        // occurrence deadline, update outcome reads, task queue pauses and
        // command request keys and open-run version usage.
        assert_eq!(caps.as_object().unwrap().len(), 63);
        assert_eq!(caps[super::super::version_usage::CAPABILITY], json!(true));
        assert_eq!(caps["task_queue_activity_dispatch_pause"], json!(true));
        assert_eq!(caps["command_request_keys"], json!(true));
        assert_eq!(caps["update_outcome_reads"], json!(true));
        assert_eq!(caps["child_workflow_start_turn"], json!(true));
        assert_eq!(caps["condition_wait_idempotent_reopen"], json!(true));
        assert_eq!(caps["condition_wait_occurrence_deadline"], json!(true));
        assert_eq!(caps["response_compression"], json!([]));
        assert_eq!(caps["timer_runtime_contract"], json!(false));
        assert_eq!(caps["principal_attribution_contract"], json!(true));
    }

    #[test]
    fn the_version_is_never_empty() {
        assert!(!version().is_empty());
    }

    fn served_compatibility() -> Value {
        let auth: Value = serde_json::from_str(include_str!("auth-composition.v1.json")).unwrap();
        client_compatibility(&auth, &super::super::visibility::request_contract())
    }

    #[test]
    fn supported_sdk_versions_and_client_compatibility_never_disagree() {
        // server#1005: one source, so every client's range is the same in both.
        let compatibility = served_compatibility();
        let ranges = supported_sdk_versions();
        for (key, client) in [("php", "sdk-php"), ("python", "sdk-python"), ("rust", "sdk-rust"), ("cli", "cli")] {
            assert_eq!(compatibility["clients"][client]["supported_versions"], ranges[key], "{client}");
        }
        assert_eq!(ranges.as_object().unwrap().len(), 4);
        assert_eq!(compatibility["clients"].as_object().unwrap().len(), 4);
        assert_eq!(ranges, json!({"php": ">=3.8.1,<4.0.0", "python": ">=2.13.0,<3.0.0",
            "rust": ">=4.10.3,<5.0.0", "cli": ">=2.0.0,<3.0.0"}));
    }

    #[test]
    fn client_compatibility_cites_only_protocols_native_serves() {
        let compatibility = served_compatibility();
        assert_eq!(compatibility["authority"], "protocol_manifests");
        assert_eq!(compatibility["fail_closed"], true);
        let required = compatibility["required_protocols"].as_object().unwrap();
        let mut keys: Vec<&str> = required.keys().map(String::as_str).collect();
        keys.sort();
        assert_eq!(keys, ["auth_composition", "control_plane", "worker_protocol"]);
        // The identities are the ones cluster info serves beside the block.
        assert_eq!(required["auth_composition"], json!({"schema": "durable-workflow.v2.auth-composition.contract", "version": 1}));
        assert_eq!(required["control_plane"]["request_contract"],
            json!({"schema": "durable-workflow.v2.control-plane-request.contract", "version": 1}));
        assert_eq!(required["worker_protocol"], json!({"version": super::super::WORKER_PROTOCOL_VERSION,
            "header": "X-Durable-Workflow-Protocol-Version"}));
        // No external execution contract is cited anywhere: native publishes none.
        assert!(!compatibility.to_string().contains("external_"), "{compatibility}");
        // Every client requirement resolves to a cited protocol member.
        for (client, entry) in compatibility["clients"].as_object().unwrap() {
            for requirement in entry["requires"].as_array().unwrap() {
                let (protocol, member) = requirement.as_str().unwrap().split_once('.').unwrap();
                assert!(required.get(protocol).and_then(|value| value.get(member)).is_some(),
                    "{client} requires {protocol}.{member}, which the block does not cite");
            }
        }
    }
}
