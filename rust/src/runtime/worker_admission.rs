//! Server 2.10.0 `WorkerController::register` admission: Laravel's request
//! rules, then the capability guards, in PHP's order. A passing request
//! continues to the store with the normalized capability manifest.
use super::laravel_rules::{self, Rule, Rule::*};
use super::{Result, RuntimeError};
use axum::http::StatusCode;
use serde_json::{Map, Value, json};

/// `WorkerProtocol::PORTABLE_WORKER_AFFINITY_CAPABILITIES`.
const AFFINITY: &[&str] = &["local_activities", "worker_sessions", "sticky_execution"];
/// `WorkerProtocol::PORTABLE_WORKER_AFFINITY_MINIMUM_PROTOCOL_VERSION`.
const AFFINITY_FLOOR: &str = "1.18";
const COOPERATIVE: &str = "cooperative_cancellation";
/// `CooperativeCancellationPolicy` and `PreparedLocalActivityPolicy`.
const COOPERATIVE_FLOOR: &str = "1.20";
const PREPARED: &str = "prepared_local_activities";
const PREPARED_GROUPS: &str = "prepared_local_activity_groups";
const PREPARED_POLICIES: &str = "prepared_local_activity_cancellation_policies";
const NEXUS_COOPERATIVE: &str = "nexus_cooperative_cancellation";
/// `WorkflowMetadataCapabilityPolicy::definitions`, in order.
const METADATA: &[(&str, &str)] = &[("memo_upserts", "1.14"), ("typed_search_attributes", "1.16")];
/// `MessageStreamsContract`.
const MESSAGE_STREAMS: (&str, &str) = ("message_streams", "1.15");

const RUNTIMES: &[&str] = &["php", "python", "rust", "typescript", "go", "java", "external"];
const MANIFEST_REQUIRED: &[Rule] = &[Required, Array, RequiredArrayKeys(AFFINITY)];
const MANIFEST_NULLABLE: &[Rule] = &[Nullable, Array, RequiredArrayKeys(AFFINITY)];

/// The request rules, with the manifest required from protocol 1.18
/// (`WorkerProtocol::portableWorkerAffinitySupported`).
fn rules(manifest: &'static [Rule]) -> Vec<(&'static str, &'static [Rule])> {
    vec![
        ("worker_id", &[Nullable, String, Max(255)]),
        ("task_queue", &[Required, String, Max(255)]),
        ("runtime", &[Required, String, In(RUNTIMES)]),
        ("sdk_version", &[Nullable, String, Max(64)]),
        ("build_id", &[Nullable, String, Max(255)]),
        ("supported_workflow_types", &[Nullable, Array]),
        ("supported_workflow_types.*", &[String]),
        ("workflow_definition_fingerprints", &[Nullable, Array]),
        ("workflow_definition_fingerprints.*", &[String, Max(255)]),
        ("workflow_command_contracts", &[Nullable, Array]),
        ("supported_activity_types", &[Nullable, Array]),
        ("supported_activity_types.*", &[String]),
        ("capabilities", &[Nullable, Array]),
        ("capabilities.*", &[String, Max(255)]),
        ("capability_manifest", manifest),
        ("capability_manifest.*", &[Array]),
        ("capability_manifest.*.supported", &[Required, Boolean]),
        ("capability_manifest.*.minimum_protocol_version", &[Required, String, Max(32)]),
        ("capability_manifest.*.implementation", &[Nullable, String, Max(255)]),
        ("capability_manifest.*.reason", &[Nullable, String, Max(255)]),
        ("max_concurrent_workflow_tasks", &[Nullable, Integer, Min(0)]),
        ("max_concurrent_activity_tasks", &[Nullable, Integer, Min(0)]),
        ("max_concurrent_worker_sessions", &[Nullable, Integer, Min(1)]),
        ("task_slots", &[Nullable, Array]),
        ("task_slots.workflow_available", &[Nullable, Integer, Min(0)]),
        ("task_slots.activity_available", &[Nullable, Integer, Min(0)]),
        ("task_slots.session_available", &[Nullable, Integer, Min(0)]),
        ("process_metrics", &[Nullable, Array]),
        ("process_metrics.cpu_percent", &[Nullable, Numeric, Min(0)]),
        ("process_metrics.memory_bytes", &[Nullable, Integer, Min(0)]),
        ("process_metrics.process_uptime_seconds", &[Nullable, Integer, Min(0)]),
        ("process_metrics.process_id", &[Nullable, Integer, Min(0)]),
        ("process_metrics.host", &[Nullable, String, Max(255)]),
        ("process_metrics.process_started_at", &[Nullable, String, Max(64)]),
        ("heartbeat_interval_seconds", &[Nullable, Integer, Min(1), Max(3600)]),
    ]
}

/// PHP's `version_compare($version, $minimum, '>=')` on dotted numbers;
/// a missing version meets nothing (`WorkerProtocol::versionMeetsMinimum`).
pub(super) fn at_least(version: Option<&str>, minimum: &str) -> bool {
    let parts = |text: &str| -> Vec<u64> { text.trim().split('.').map(|part| part.parse().unwrap_or(0)).collect() };
    let Some(version) = version.filter(|version| !version.trim().is_empty()) else {
        return false;
    };
    let (version, minimum) = (parts(version), parts(minimum));
    let length = version.len().max(minimum.len());
    let padded = |list: &[u64]| (0..length).map(|index| list.get(index).copied().unwrap_or(0)).collect::<Vec<_>>();
    padded(&version) >= padded(&minimum)
}

fn refused(mut response: Value) -> RuntimeError {
    response["registered"] = json!(false);
    RuntimeError::Protocol { status: StatusCode::CONFLICT, response }
}

/// The registration's flat capabilities, as PHP's `nonEmptyStringArray`.
fn capabilities(body: &Value) -> Vec<&str> {
    body["capabilities"]
        .as_array()
        .into_iter()
        .flatten()
        .filter_map(Value::as_str)
        .filter(|capability| !capability.is_empty())
        .collect()
}

/// `portableWorkerCapabilityManifest`: the three affinity entries that are
/// arrays, with `supported` and a trimmed floor, plus non-empty
/// `implementation` and `reason`.
pub(super) fn manifest(body: &Value) -> Value {
    let mut normalized = Map::new();
    for capability in AFFINITY {
        let Some(entry) = body["capability_manifest"].get(*capability).filter(|entry| entry.is_object()) else {
            continue;
        };
        let supported = match &entry["supported"] {
            Value::Bool(flag) => *flag,
            Value::Number(number) => number.as_f64().is_some_and(|number| number != 0.0),
            Value::String(text) => !text.is_empty() && text != "0",
            _ => false,
        };
        let floor = match &entry["minimum_protocol_version"] {
            Value::String(text) => text.trim().to_owned(),
            Value::Number(number) => number.to_string(),
            _ => std::string::String::new(),
        };
        let mut item = json!({"supported": supported, "minimum_protocol_version": floor});
        for field in ["implementation", "reason"] {
            if let Some(text) = entry[field].as_str().map(str::trim).filter(|text| !text.is_empty()) {
                item[field] = json!(text);
            }
        }
        normalized.insert((*capability).to_owned(), item);
    }
    Value::Object(normalized)
}

/// The admission checks before any registration state is read. `body` is
/// the request after Laravel's trimming; `version` is the request's
/// worker protocol header.
pub(super) fn admit(body: &Value, version: Option<&str>) -> Result<()> {
    let manifest_rule = if at_least(Some(version.unwrap_or(super::WORKER_PROTOCOL_VERSION)), AFFINITY_FLOOR) {
        MANIFEST_REQUIRED
    } else {
        MANIFEST_NULLABLE
    };
    laravel_rules::validate(body, &rules(manifest_rule))?;
    let flags = capabilities(body);
    let has = |capability: &str| flags.contains(&capability);
    let cooperative = has(COOPERATIVE) && at_least(version, COOPERATIVE_FLOOR);
    let requested = json!(version);
    let pair = json!([COOPERATIVE, PREPARED]);
    if has(PREPARED_POLICIES) && !(cooperative && has(PREPARED) && has(PREPARED_POLICIES)) {
        return Err(refused(json!({"reason": "prepared_local_activity_cancellation_policy_capability_mismatch",
            "required_capabilities": pair, "minimum_protocol_version": COOPERATIVE_FLOOR, "requested_version": requested})));
    }
    if has(PREPARED_GROUPS) && (!has(PREPARED) || !cooperative) {
        return Err(refused(json!({"reason": "prepared_local_activity_group_capability_mismatch",
            "required_capabilities": pair, "minimum_protocol_version": COOPERATIVE_FLOOR, "requested_version": requested})));
    }
    if has(PREPARED) && !cooperative {
        return Err(refused(json!({"reason": "prepared_local_activity_capability_mismatch",
            "required_capability": COOPERATIVE, "minimum_protocol_version": COOPERATIVE_FLOOR, "requested_version": requested})));
    }
    if has(NEXUS_COOPERATIVE) && !cooperative {
        return Err(refused(json!({"reason": "nexus_cooperative_cancellation_capability_mismatch",
            "required_capabilities": [COOPERATIVE, NEXUS_COOPERATIVE], "minimum_protocol_version": COOPERATIVE_FLOOR,
            "requested_version": requested})));
    }
    if has(COOPERATIVE) && !cooperative {
        return Err(refused(json!({"reason": "cooperative_cancellation_protocol_mismatch",
            "minimum_protocol_version": COOPERATIVE_FLOOR, "requested_version": requested})));
    }
    // `guardPortableWorkerCapabilityManifest`: the structured flag and the
    // flat capability agree, and an advertised capability meets its floor.
    let normalized = manifest(body);
    for capability in AFFINITY {
        let advertised = has(capability);
        let entry = normalized.get(*capability);
        if entry.is_none() && !advertised {
            continue;
        }
        if entry.is_none_or(|entry| entry["supported"].as_bool() != Some(advertised)) {
            return Err(refused(json!({"reason": "worker_capability_manifest_mismatch", "capability": capability,
                "requested_version": requested, "minimum_protocol_version": AFFINITY_FLOOR,
                "remediation": "Keep the structured supported flag and the flat routing capability in exact agreement."})));
        }
        let floor = entry.and_then(|entry| entry["minimum_protocol_version"].as_str()).unwrap_or_default();
        if floor.is_empty() || !at_least(Some(floor), AFFINITY_FLOOR) || (advertised && !at_least(version, floor)) {
            return Err(refused(json!({"reason": "worker_capability_protocol_floor_mismatch", "capability": capability,
                "requested_version": requested, "minimum_protocol_version": AFFINITY_FLOOR,
                "remediation": format!("Advertise {capability} only from a worker targeting protocol {AFFINITY_FLOOR} or newer.")})));
        }
    }
    if let Some((capability, minimum)) =
        METADATA.iter().find(|(capability, minimum)| has(capability) && !at_least(version, minimum))
    {
        return Err(refused(json!({"reason": "workflow_metadata_capability_protocol_mismatch",
            "capability": capability, "requested_version": requested, "minimum_protocol_version": minimum,
            "remediation": format!("Advertise {capability} only while sending worker protocol {minimum} or newer.")})));
    }
    let (streams, minimum) = MESSAGE_STREAMS;
    if has(streams) && !at_least(version, minimum) {
        return Err(refused(json!({"reason": "message_streams_unavailable", "requested_version": requested,
            "minimum_protocol_version": minimum,
            "remediation": format!("Advertise message_streams only while sending worker protocol {minimum} or newer.")})));
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn protocol_versions_compare_like_php() {
        assert!(at_least(Some("1.20"), "1.18"));
        assert!(at_least(Some("1.18"), "1.18"));
        assert!(!at_least(Some("1.9"), "1.18"));
        assert!(at_least(Some("2.0"), "1.20"));
        assert!(!at_least(None, "1.0"));
    }

    #[test]
    fn the_manifest_keeps_the_affinity_entries_that_are_arrays() {
        let body = json!({"capability_manifest": {
            "local_activities": {"supported": 1, "minimum_protocol_version": " 1.18 ", "implementation": " sdk ", "reason": ""},
            "worker_sessions": "unsupported",
            "sticky_execution": {"supported": false, "minimum_protocol_version": "1.18"},
            "other": {"supported": true, "minimum_protocol_version": "1.18"}}});
        assert_eq!(manifest(&body), json!({
            "local_activities": {"supported": true, "minimum_protocol_version": "1.18", "implementation": "sdk"},
            "sticky_execution": {"supported": false, "minimum_protocol_version": "1.18"}}));
    }
}
