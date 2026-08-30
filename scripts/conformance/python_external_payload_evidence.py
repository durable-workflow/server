from __future__ import annotations

import hashlib
import json
import re
from pathlib import Path
from typing import Any, Mapping


RUNTIME_EXTERNAL_PAYLOAD_SCENARIO = "runtime_external_payload_round_trips"
RUNTIME_EXTERNAL_PAYLOAD_REFERENCE_SCHEMA = (
    "durable-workflow.v2.runtime-external-payload-reference.v1"
)
CLOUD_EVIDENCE_SCHEMA = (
    "durable-workflow.v2.python-runtime-external-payload.cloud-evidence"
)
CLOUD_EVIDENCE_VERSION = 1

REQUIRED_SCENARIOS = (
    "published_artifact_install_only",
    "official_cli_install_start_result_path",
    "cold_first_user_setup",
    "python_worker_registration",
    "activity_backed_workflow_execution",
    "workflow_result_surface",
    "worker_restart_activity_and_signal_state",
    RUNTIME_EXTERNAL_PAYLOAD_SCENARIO,
    "protocol_trace_capture",
    "php_assumption_audit",
    "capability_table_complete",
)

RUNTIME_EXTERNAL_PAYLOAD_CAPABILITIES = (
    "runtime_external_payload_inline_round_trip",
    "runtime_external_payload_externalized_round_trip",
    "runtime_external_payload_cross_language_round_trip",
    "runtime_external_payload_standalone_server",
    "runtime_external_payload_isolated_cloud",
    "runtime_external_payload_provider_setup_absent",
)

REQUIRED_CAPABILITIES = (
    "server_up",
    "official_cli_installed",
    "cli_reaches_server",
    "cli_starts_workflow",
    "cli_reads_workflow_result",
    "cold_first_user_setup",
    "python_sdk_installed_from_pypi",
    "python_worker_connects",
    "python_worker_registers_workflows",
    "python_worker_registers_activities",
    "python_workflow_runs",
    "python_activity_runs",
    "workflow_result_returned",
    "worker_restart_replays_activity_state",
    "worker_restart_replays_signal_state",
    *RUNTIME_EXTERNAL_PAYLOAD_CAPABILITIES,
    "protocol_traces_recorded",
    "php_assumptions_absent",
)

_BASELINE_FAILED_SCENARIOS = frozenset(
    {
        "official_cli_install_start_result_path",
        "python_worker_registration",
        "activity_backed_workflow_execution",
        "workflow_result_surface",
    }
)

CLOUD_REQUIRED_OBSERVATIONS = (
    "inline_round_trip",
    "externalized_round_trip",
    "cross_language_round_trip",
    "ordinary_runtime_credentials",
    "provider_setup_absent",
    "worker_replacement",
    "retained_history_replay_identity",
    "size_sha256_verification",
    "malformed_reference_rejection",
    "integrity_mismatch_rejection",
    "cleanup",
)

ARTIFACT_VERSION_FIELDS = (
    "server",
    "cli",
    "sdk-python",
    "workflow",
    "waterline",
)

_REFERENCE_ID = re.compile(r"^ep_[0-9A-HJKMNP-TV-Z]{26}$")
_SHA256 = re.compile(r"^[0-9a-f]{64}$")
_SENSITIVE_KEYS = {
    "access_key",
    "access_key_id",
    "authorization",
    "auth_profile",
    "backing_store",
    "backing_store_credentials",
    "bucket",
    "client_secret",
    "credential",
    "credentials",
    "provider_credentials",
    "provider_reference",
    "reference_id",
    "secret",
    "secret_access_key",
    "storage_uri",
    "token",
    "uri",
}


def summarize_runtime_reference(
    reference: Mapping[str, Any],
    *,
    expected_bytes: bytes | None = None,
) -> dict[str, Any]:
    """Validate an opaque runtime reference and return non-reusable evidence."""

    required_keys = {"schema", "reference_id", "codec", "size_bytes", "sha256"}
    if set(reference) != required_keys:
        raise ValueError(
            "runtime reference must contain exactly the public opaque fields"
        )
    if reference.get("schema") != RUNTIME_EXTERNAL_PAYLOAD_REFERENCE_SCHEMA:
        raise ValueError("runtime reference schema is unsupported")

    reference_id = reference.get("reference_id")
    if (
        not isinstance(reference_id, str)
        or _REFERENCE_ID.fullmatch(reference_id) is None
    ):
        raise ValueError("runtime reference_id is malformed")
    if "://" in reference_id:
        raise ValueError("runtime reference_id exposes a provider-specific URI")

    codec = reference.get("codec")
    if codec != "avro":
        raise ValueError("runtime reference codec must be avro")
    size_bytes = reference.get("size_bytes")
    if (
        isinstance(size_bytes, bool)
        or not isinstance(size_bytes, int)
        or size_bytes < 0
    ):
        raise ValueError("runtime reference size_bytes is invalid")
    sha256 = reference.get("sha256")
    if not isinstance(sha256, str) or _SHA256.fullmatch(sha256) is None:
        raise ValueError("runtime reference sha256 is invalid")

    if expected_bytes is not None:
        if size_bytes != len(expected_bytes):
            raise ValueError(
                "runtime reference size does not match the encoded payload"
            )
        if sha256 != hashlib.sha256(expected_bytes).hexdigest():
            raise ValueError(
                "runtime reference SHA-256 does not match the encoded payload"
            )

    return {
        "schema": reference["schema"],
        "codec": codec,
        "size_bytes": size_bytes,
        "sha256": sha256,
        "opaque_reference": True,
        "provider_specific_reference_exposed": False,
    }


def failure_scenario_results(
    failure_scope: str,
    finding: Mapping[str, Any],
) -> dict[str, dict[str, Any]]:
    """Classify attempted product cells as failures, not coverage gaps."""

    failed_scenarios = set(_BASELINE_FAILED_SCENARIOS)
    if failure_scope == "runtime_external_payload":
        failed_scenarios.add(RUNTIME_EXTERNAL_PAYLOAD_SCENARIO)

    return {
        scenario: {
            "scenario_id": scenario,
            "status": "fail" if scenario in failed_scenarios else "not_covered",
            "observed_outputs": {"summary": str(finding.get("summary") or "")},
            "linked_findings": [dict(finding)],
        }
        for scenario in REQUIRED_SCENARIOS
    }


def failure_scope_for_phase(failure_phase: str) -> str:
    """Route an attempted phase to the product scenario that owns its failure."""

    phase = str(failure_phase or "runner_start")
    if phase in {"runner_start", "runner_initialized"}:
        return "runner_setup"
    if phase in {
        "cli_health_check",
        "cli_server_ready",
        "namespace_creation",
        "namespace_created",
        "cluster_info",
        "initial_worker_start",
        "initial_worker_started",
        "workflow_start",
        "workflow_started",
        "initial_worker_stop",
        "initial_worker_stopped",
        "initial_worker_absence_verification",
        "initial_worker_absent",
    }:
        return "server_routing"
    if phase.startswith("runtime_payload_") or phase.startswith("namespace_cleanup"):
        return "runtime_external_payload"
    if phase in {"approval_signal", "approval_signal_accepted"}:
        return "sdk_execution"

    return "workflow_state"


def cloud_evidence_handoff(
    evidence_path: str | Path | None,
    artifact_versions: Mapping[str, str],
) -> dict[str, Any]:
    """Validate optional isolated-Cloud evidence against the standalone tuple."""

    expected_tuple = {
        field: str(artifact_versions.get(field, ""))
        for field in ARTIFACT_VERSION_FIELDS
    }
    if evidence_path is None or str(evidence_path).strip() == "":
        return _cloud_non_pass(
            "not_covered",
            "isolated Cloud evidence was not supplied by the managed-runtime conformance authority",
            expected_tuple,
            ["cloud_evidence_path_missing"],
        )

    path = Path(evidence_path)
    try:
        evidence = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        return _cloud_non_pass(
            "fail",
            "isolated Cloud evidence could not be read as JSON",
            expected_tuple,
            [f"cloud_evidence_unreadable:{type(exc).__name__}"],
        )
    if not isinstance(evidence, dict):
        return _cloud_non_pass(
            "fail",
            "isolated Cloud evidence must be a JSON object",
            expected_tuple,
            ["cloud_evidence_not_object"],
        )

    failures: list[str] = []
    if evidence.get("schema") != CLOUD_EVIDENCE_SCHEMA:
        failures.append("schema")
    if evidence.get("version") != CLOUD_EVIDENCE_VERSION:
        failures.append("version")
    if evidence.get("outcome") != "pass":
        failures.append("outcome")
    if evidence.get("runner_blocked") is not False:
        failures.append("runner_blocked")
    for field in ("evidence_id", "generated_at"):
        if not isinstance(evidence.get(field), str) or evidence[field].strip() == "":
            failures.append(field)

    environment = evidence.get("environment")
    if not isinstance(environment, dict):
        failures.append("environment")
    else:
        if environment.get("kind") != "isolated_managed_namespace":
            failures.append("environment.kind")
        if environment.get("namespace_isolated") is not True:
            failures.append("environment.namespace_isolated")

    source_policy = evidence.get("source_policy")
    if not isinstance(source_policy, dict):
        failures.append("source_policy")
    else:
        if source_policy.get("artifact_source") != "published_artifacts":
            failures.append("source_policy.artifact_source")
        if source_policy.get("local_product_sources_used") is not False:
            failures.append("source_policy.local_product_sources_used")

    supplied_tuple = evidence.get("artifact_versions")
    if not isinstance(supplied_tuple, dict):
        failures.append("artifact_versions")
        supplied_tuple = {}
    for field, expected in expected_tuple.items():
        if supplied_tuple.get(field) != expected:
            failures.append(f"artifact_versions.{field}")

    scenario = evidence.get("scenario_result")
    if not isinstance(scenario, dict):
        failures.append("scenario_result")
        scenario = {}
    else:
        if scenario.get("scenario_id") != RUNTIME_EXTERNAL_PAYLOAD_SCENARIO:
            failures.append("scenario_result.scenario_id")
        if scenario.get("status") != "pass":
            failures.append("scenario_result.status")

    observed = scenario.get("observed_outputs") if isinstance(scenario, dict) else None
    if not isinstance(observed, dict):
        failures.append("scenario_result.observed_outputs")
        observed = {}
    for field in CLOUD_REQUIRED_OBSERVATIONS:
        item = observed.get(field)
        if not isinstance(item, dict) or item.get("status") != "pass":
            failures.append(f"scenario_result.observed_outputs.{field}")

    sensitive_paths = _sensitive_paths(evidence)
    if sensitive_paths:
        failures.extend(f"sensitive_field.{path}" for path in sensitive_paths)

    if failures:
        return _cloud_non_pass(
            "fail",
            "isolated Cloud evidence does not satisfy the exact-tuple handoff contract",
            expected_tuple,
            failures,
        )

    return {
        "status": "pass",
        "isolated_cloud": {
            "status": "pass",
            "authority": "managed_runtime_conformance",
            "evidence_schema": CLOUD_EVIDENCE_SCHEMA,
            "evidence_id": evidence.get("evidence_id"),
            "generated_at": evidence.get("generated_at"),
            "artifact_versions": expected_tuple,
            "environment": environment,
            "observed_outputs": {
                field: observed[field] for field in CLOUD_REQUIRED_OBSERVATIONS
            },
        },
        "capability": {
            "status": "pass",
            "evidence": {
                "authority": "managed_runtime_conformance",
                "evidence_schema": CLOUD_EVIDENCE_SCHEMA,
                "evidence_id": evidence.get("evidence_id"),
                "artifact_versions": expected_tuple,
            },
        },
        "findings": [],
    }


def _cloud_non_pass(
    status: str,
    summary: str,
    expected_tuple: Mapping[str, str],
    failures: list[str],
) -> dict[str, Any]:
    finding = {
        "type": "conformance_runner_coverage_gap"
        if status == "not_covered"
        else "cloud_evidence_contract_mismatch",
        "owning_surface": "managed_runtime_conformance",
        "summary": summary,
        "failures": failures,
        "next_acceptance_criterion": (
            "supply passing isolated managed-namespace evidence for the exact published artifact tuple"
        ),
    }
    evidence = {
        "status": status,
        "authority": "managed_runtime_conformance",
        "required_evidence_schema": CLOUD_EVIDENCE_SCHEMA,
        "required_artifact_versions": dict(expected_tuple),
        "failures": failures,
    }
    return {
        "status": status,
        "isolated_cloud": evidence,
        "capability": {
            "status": status,
            "evidence": evidence,
        },
        "findings": [finding],
    }


def _sensitive_paths(value: Any, path: tuple[str, ...] = ()) -> list[str]:
    found: list[str] = []
    if isinstance(value, dict):
        for key, item in value.items():
            normalized = str(key).lower()
            item_path = (*path, str(key))
            if normalized in _SENSITIVE_KEYS:
                found.append(".".join(item_path))
            found.extend(_sensitive_paths(item, item_path))
    elif isinstance(value, list):
        for index, item in enumerate(value):
            found.extend(_sensitive_paths(item, (*path, str(index))))
    elif isinstance(value, str) and any(
        scheme in value.lower() for scheme in ("file://", "s3://", "gs://", "azure://")
    ):
        found.append(".".join(path))
    return found
