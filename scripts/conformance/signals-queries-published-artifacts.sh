#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
  cat <<'USAGE'
Usage: signals-queries-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]

Writes a source-free signals/queries conformance split-out result.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  signals-queries-result.json
  signals-queries-record.json
  signals-queries-findings.json

Environment overrides:
  DW_SERVER_VERSION                         Published server version under test.
  DW_CLI_VERSION                            Published CLI version under test.
  DW_PYTHON_SDK_VERSION                     Published Python SDK version under test.
  DW_WORKFLOW_PHP_VERSION                   Published PHP workflow version under test.
  DW_WATERLINE_VERSION                      Published Waterline version under test.
  DW_SIGNALS_QUERIES_RESULT_DIR             Result directory when --result-dir is omitted.
  DW_SIGNALS_QUERIES_EVIDENCE               Optional JSON evidence from a real matrix run.
  DW_SIGNALS_QUERIES_SMOKE_EVIDENCE         Deprecated alias for DW_SIGNALS_QUERIES_EVIDENCE.
USAGE
}

result_dir="${DW_SIGNALS_QUERIES_RESULT_DIR:-}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --result-dir)
      result_dir="${2:?--result-dir requires a value}"
      shift 2
      ;;
    --result-dir=*)
      result_dir="${1#--result-dir=}"
      if [[ -z "$result_dir" ]]; then
        printf '%s\n' '--result-dir requires a value' >&2
        usage >&2
        exit 2
      fi
      shift
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      printf 'unknown argument: %s\n' "$1" >&2
      usage >&2
      exit 2
      ;;
  esac
done

if [[ -z "$result_dir" ]]; then
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-signals-queries.XXXXXX")"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"

timestamp() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

started_at="$(timestamp)"

RESULT_DIR="$result_dir" \
STARTED_AT="$started_at" \
DW_SERVER_VERSION="${DW_SERVER_VERSION:-unresolved}" \
DW_CLI_VERSION="${DW_CLI_VERSION:-unresolved}" \
DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-unresolved}" \
DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-unresolved}" \
DW_WATERLINE_VERSION="${DW_WATERLINE_VERSION:-unresolved}" \
DW_SIGNALS_QUERIES_EVIDENCE="${DW_SIGNALS_QUERIES_EVIDENCE:-${DW_SIGNALS_QUERIES_SMOKE_EVIDENCE:-}}" \
DW_SIGNALS_QUERIES_SMOKE_EVIDENCE="${DW_SIGNALS_QUERIES_SMOKE_EVIDENCE:-}" \
python3 - <<'PY'
from __future__ import annotations

import hashlib
import json
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")


MISSING = object()
FORBIDDEN_ARTIFACT_SOURCES = (
    "local_product_source_checkout",
    "workspace_repo_as_artifact_under_test",
)
ARTIFACT_SOURCE_FIELDS = ("artifact_sources", "artifactSources")


def evidence_value(value: Any, key: str) -> Any:
    if isinstance(value, dict):
        if key in value:
            return value[key]
        for child in value.values():
            found = evidence_value(child, key)
            if found is not MISSING:
                return found
    if isinstance(value, list):
        for child in value:
            found = evidence_value(child, key)
            if found is not MISSING:
                return found
    return MISSING


def is_forbidden_artifact_source(source: Any) -> bool:
    if not isinstance(source, str):
        return False

    normalized = source.strip().lower()
    if normalized == "":
        return False

    return any(
        normalized == forbidden or forbidden in normalized
        for forbidden in FORBIDDEN_ARTIFACT_SOURCES
    )


def artifact_source_policy_violations(value: Any, path: str = "$") -> list[dict[str, str]]:
    violations: list[dict[str, str]] = []

    if isinstance(value, dict):
        for field in ARTIFACT_SOURCE_FIELDS:
            sources = value.get(field)
            if not isinstance(sources, dict):
                continue

            for artifact, source in sources.items():
                if not is_forbidden_artifact_source(source):
                    continue

                violations.append(
                    {
                        "path": f"{path}.{field}",
                        "field": field,
                        "artifact": str(artifact),
                        "source": str(source),
                    }
                )

        for key, child in value.items():
            if isinstance(child, (dict, list)):
                violations.extend(artifact_source_policy_violations(child, f"{path}.{key}"))

    if isinstance(value, list):
        for index, child in enumerate(value):
            if isinstance(child, (dict, list)):
                violations.extend(artifact_source_policy_violations(child, f"{path}[{index}]"))

    return violations


def evidence_source_policy_violations(*values: Any) -> list[dict[str, str]]:
    violations: list[dict[str, str]] = []
    for value in values:
        violations.extend(artifact_source_policy_violations(value))
    return violations


def flat_smoke_field(key: str) -> Any:
    if smoke_evidence is None:
        return MISSING
    if not isinstance(smoke_evidence, dict):
        return MISSING
    return smoke_evidence.get(key, MISSING)


def smoke_field(key: str, scenario: str | None = None) -> Any:
    value = flat_smoke_field(key)
    if value is not MISSING:
        return value

    if scenario is None:
        return MISSING

    candidate = scenario_evidence_candidate(scenario)
    if candidate is None:
        return MISSING

    observed = scenario_observed_outputs(candidate)
    found = evidence_lookup(observed, key)
    if found is not MISSING:
        return found

    if key == "ten_signal_ordered_delivery_total":
        return evidence_lookup(observed, "queried_total")

    return MISSING


def smoke_field_present(key: str, scenario: str | None = None) -> bool:
    value = smoke_field(key, scenario)
    return value is not MISSING and value not in (None, "", [], {})


def smoke_field_true(key: str, scenario: str | None = None) -> bool:
    value = smoke_field(key, scenario)
    if value is True:
        return True
    if isinstance(value, str):
        return value.strip().lower() in {"true", "pass", "passed", "ok", "yes"}
    return False


def is_placeholder_version(version: str) -> bool:
    normalized = version.strip().lower()
    if not normalized:
        return True
    placeholder_tokens = ("latest", "current", "head", "unresolved", "placeholder")
    return (
        normalized.startswith("<")
        or "${" in normalized
        or "{{" in normalized
        or any(token in normalized for token in placeholder_tokens)
    )


def artifact_versions_pinned() -> bool:
    required = ("server", "cli", "sdk-python", "workflow-php", "waterline")
    return all(not is_placeholder_version(str(artifact_versions.get(artifact, ""))) for artifact in required)


ARTIFACT_VERSION_ALIASES: dict[str, list[str]] = {
    "workflow-php": ["workflow-php", "workflow_php", "workflow"],
    "sdk-python": ["sdk-python", "sdk_python", "python"],
    "waterline": ["waterline", "waterline-ui", "waterline_ui"],
}

ARTIFACT_VERSION_FIELDS = (
    "artifact_versions",
    "artifactVersions",
    "published_artifact_versions",
    "publishedArtifactVersions",
)


def artifact_version_value(versions: dict[str, Any], artifact: str) -> str:
    for key in ARTIFACT_VERSION_ALIASES.get(artifact, [artifact]):
        value = versions.get(key)
        if value is None:
            continue
        normalized = str(value).strip()
        if normalized:
            return normalized
    return ""


def declared_artifact_versions(value: Any) -> dict[str, Any]:
    if not isinstance(value, dict):
        return {}

    for field in ARTIFACT_VERSION_FIELDS:
        versions = value.get(field)
        if isinstance(versions, dict):
            return versions

    return {}


def declared_artifact_version_maps(value: Any) -> list[dict[str, Any]]:
    if isinstance(value, list):
        maps: list[dict[str, Any]] = []
        for child in value:
            maps.extend(declared_artifact_version_maps(child))
        return maps

    if not isinstance(value, dict):
        return []

    maps = []
    versions = declared_artifact_versions(value)
    if versions:
        maps.append(versions)

    for child in value.values():
        maps.extend(declared_artifact_version_maps(child))

    return maps


def artifact_version_mismatches(versions: dict[str, Any]) -> dict[str, dict[str, str]]:
    mismatched: dict[str, dict[str, str]] = {}
    for artifact in ("server", "cli", "sdk-python", "workflow-php", "waterline"):
        expected = artifact_version_value(artifact_versions, artifact)
        actual = artifact_version_value(versions, artifact)
        if expected and actual and expected != actual:
            mismatched[artifact] = {"expected": expected, "actual": actual}
    return mismatched


def smoke_artifact_version_mismatches() -> dict[str, dict[str, str]]:
    mismatched: dict[str, dict[str, str]] = {}
    for versions in declared_artifact_version_maps(smoke_evidence):
        for artifact, mismatch in artifact_version_mismatches(versions).items():
            mismatched.setdefault(artifact, mismatch)
    return mismatched


def smoke_evidence_matches_current_tuple() -> bool:
    return smoke_artifact_version_mismatches() == {}


def candidate_artifact_versions(candidate: dict[str, Any], observed: dict[str, Any]) -> dict[str, Any]:
    for value in (candidate, observed):
        versions = declared_artifact_versions(value)
        if versions:
            return versions

    return {}


def candidate_matches_current_tuple(candidate: dict[str, Any], observed: dict[str, Any]) -> bool:
    if not smoke_evidence_matches_current_tuple():
        return False

    if evidence_source_policy_violations(candidate, observed):
        return False

    versions = candidate_artifact_versions(candidate, observed)
    return not versions or artifact_version_mismatches(versions) == {}


def exact_python_smoke_present() -> bool:
    return all(
        smoke_field_true(field, "python_worker_cli_and_sdk_baseline")
        for field in (
            "python_worker_query_task_routing",
            "cli_signal_and_query",
            "sdk_python_signal_and_query",
            "immediate_repeat_query_consistency",
        )
    )


def exact_ordered_delivery_smoke_present() -> bool:
    rapid_inputs = smoke_field("rapid_increment_inputs", "ordered_signal_delivery")
    history_signal_order = smoke_field("history_signal_order", "ordered_signal_delivery")
    return (
        rapid_inputs == list(range(1, 11))
        and smoke_field("ten_signal_ordered_delivery_total", "ordered_signal_delivery") == 55
        and history_signal_order == list(range(1, 11))
    )


ALLOWED_SCENARIO_STATUSES = {"pass", "fail", "unsupported", "not_covered", "runner_blocked"}

SCENARIO_REQUIRED_EVIDENCE: dict[str, list[str]] = {
    "published_artifact_install_only": [
        "published_artifact_versions",
        "artifact_sources",
    ],
    "python_worker_cli_and_sdk_baseline": [
        "python_worker_query_task_routing",
        "cli_signal_and_query",
        "sdk_python_signal_and_query",
        "immediate_repeat_query_consistency",
    ],
    "php_worker_cli_and_sdk_baseline": [
        "php_worker_query_task_routing",
        "cli_signal_and_query",
        "workflow_php_signal_and_query",
        "immediate_repeat_query_consistency",
    ],
    "python_worker_php_facing_and_cli_clients": [
        "php_client_signal_and_query",
        "cli_signal_and_query",
        "cross_language_query_consistency",
        "wire_envelope_compatibility",
    ],
    "php_worker_python_and_cli_clients": [
        "sdk_python_signal_and_query",
        "cli_signal_and_query",
        "cross_language_query_consistency",
        "wire_envelope_compatibility",
    ],
    "ordered_signal_delivery": [
        "rapid_increment_inputs",
        "queried_total",
        "history_signal_order",
    ],
    "dedup_contract_observation": [
        "client_side_key_support",
        "documented_contract",
        "handler_observation_count",
    ],
    "signal_during_replay": [
        "signal_api_sample",
        "signal_status_code",
        "worker_restart_at",
        "signal_sent_at",
        "replay_completed_at",
        "signal_applied_at",
    ],
    "query_during_replay": [
        "query_api_sample",
        "query_status_code",
        "worker_restart_at",
        "query_sent_at",
        "replay_completed_at",
        "query_handler_invoked_at",
        "query_completed_at",
        "query_answer",
        "expected_answer",
    ],
    "completed_run_signal_and_query": [
        "completed_run_id",
        "completed_at",
        "signal_api_sample",
        "signal_error.status_code",
        "signal_error.reason",
        "signal_error.rejection_reason",
        "query_api_sample",
        "query_result_or_error.status_code",
        "query_result_or_error.outcome",
        "signal_error",
        "query_result_or_error",
        "public_query_surfaces",
        "run_status_after_operations",
    ],
    "unknown_signal_and_query_errors": [
        "unknown_signal",
        "missing_workflow_signal",
        "missing_workflow_query",
        "query_not_found",
        "rejected_unknown_query",
    ],
    "malformed_signal_and_query_payloads": [
        "invalid_signal_arguments",
        "invalid_query_arguments",
        "invalid_signal_arguments.status_code",
        "invalid_signal_arguments.reason",
        "invalid_query_arguments.status_code",
        "invalid_query_arguments.reason",
        "invalid_signal_arguments_context",
        "invalid_query_arguments_context",
        "signal_handler_invocation_count_after_invalid_payload",
        "query_state_mutation_count_after_invalid_payload",
        "post_error_valid_query_result",
        "cli_invalid_signal_arguments_sample",
        "cli_invalid_query_arguments_sample",
        "sdk_python_invalid_signal_arguments_sample",
        "sdk_python_invalid_query_arguments_sample",
    ],
    "waterline_operator_visibility": [
        "artifact_versions",
        "artifact_sources",
        "captured_at",
        "observer_state.selected_run",
        "observer_state.signals",
        "observer_state.queries",
        "observer_state.paths.selected_run_query_template",
        "api_paths.selected_run_detail",
        "api_paths.selected_run_query_action",
        "dashboard_json_envelopes.selected_run_detail",
        "api_captures.selected_run_detail",
        "api_captures.selected_run_query_action",
        "comparison.run_status_matches_public_clients",
        "comparison.counter_state_matches_public_clients",
        "comparison.server_observation",
        "comparison.cli_observation",
        "comparison.sdk_observation",
    ],
}

TRUTHY_REQUIRED_EVIDENCE = {
    "python_worker_query_task_routing",
    "cli_signal_and_query",
    "sdk_python_signal_and_query",
    "immediate_repeat_query_consistency",
    "php_worker_query_task_routing",
    "workflow_php_signal_and_query",
    "php_client_signal_and_query",
    "cross_language_query_consistency",
    "wire_envelope_compatibility",
    "comparison.run_status_matches_public_clients",
    "comparison.counter_state_matches_public_clients",
}


def path_value(value: Any, path: list[str]) -> Any:
    current = value
    for segment in path:
        if not isinstance(current, dict) or segment not in current:
            return MISSING
        current = current[segment]
    return current


def evidence_present(value: Any) -> bool:
    if value is MISSING or value is None:
        return False
    if isinstance(value, str):
        return value.strip() != ""
    if isinstance(value, (list, dict)):
        return bool(value)
    return True


def evidence_true(value: Any) -> bool:
    if value is True:
        return True
    if isinstance(value, str):
        return value.strip().lower() in {"true", "pass", "passed", "ok", "yes"}
    return False


def required_evidence_satisfied(evidence_key: str, value: Any) -> bool:
    if evidence_key in TRUTHY_REQUIRED_EVIDENCE:
        return evidence_true(value)

    return evidence_present(value)


def evidence_lookup(value: Any, key: str) -> Any:
    if "." in key and isinstance(value, dict):
        found = path_value(value, key.split("."))
        if found is not MISSING:
            return found

    return evidence_value(value, key)


def integer_value(value: Any) -> int | None:
    if isinstance(value, bool):
        return None
    if isinstance(value, int):
        return value
    if isinstance(value, str) and value.strip().lstrip("-").isdigit():
        return int(value.strip())
    return None


def status_code_in_range(observed: dict[str, Any], key: str, minimum: int, maximum: int) -> bool:
    status = integer_value(evidence_lookup(observed, key))
    return status is not None and minimum <= status <= maximum


def timestamp_seconds(value: Any) -> float | None:
    if not isinstance(value, str) or value.strip() == "":
        return None
    normalized = value.strip()
    if normalized.endswith("Z"):
        normalized = f"{normalized[:-1]}+00:00"
    try:
        return datetime.fromisoformat(normalized).timestamp()
    except ValueError:
        return None


def timestamps_in_order(observed: dict[str, Any], orders: list[tuple[str, str, str]]) -> bool:
    for left_key, operator, right_key in orders:
        left = timestamp_seconds(evidence_lookup(observed, left_key))
        right = timestamp_seconds(evidence_lookup(observed, right_key))
        if left is None or right is None:
            return False
        if operator == "<" and not left < right:
            return False
        if operator == "<=" and not left <= right:
            return False
    return True


def has_required_evidence(scenario: str, observed: dict[str, Any]) -> bool:
    if scenario == "published_artifact_install_only" and not artifact_versions_pinned():
        return False

    if scenario == "ordered_signal_delivery":
        rapid_inputs = evidence_lookup(observed, "rapid_increment_inputs")
        queried_total = evidence_lookup(observed, "queried_total")
        if queried_total is MISSING:
            queried_total = evidence_lookup(observed, "ten_signal_ordered_delivery_total")
        history_signal_order = evidence_lookup(observed, "history_signal_order")

        return (
            rapid_inputs == list(range(1, 11))
            and queried_total == 55
            and history_signal_order == list(range(1, 11))
        )

    if scenario == "signal_during_replay":
        return (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and status_code_in_range(observed, "signal_status_code", 200, 299)
            and timestamps_in_order(
                observed,
                [
                    ("worker_restart_at", "<=", "signal_sent_at"),
                    ("signal_sent_at", "<", "replay_completed_at"),
                    ("replay_completed_at", "<=", "signal_applied_at"),
                ],
            )
        )

    if scenario == "query_during_replay":
        return (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and status_code_in_range(observed, "query_status_code", 200, 299)
            and evidence_lookup(observed, "query_answer") == evidence_lookup(observed, "expected_answer")
            and timestamps_in_order(
                observed,
                [
                    ("worker_restart_at", "<=", "query_sent_at"),
                    ("query_sent_at", "<", "replay_completed_at"),
                    ("replay_completed_at", "<=", "query_handler_invoked_at"),
                    ("query_handler_invoked_at", "<=", "query_completed_at"),
                ],
            )
        )

    if scenario == "completed_run_signal_and_query":
        query_status = integer_value(evidence_lookup(observed, "query_result_or_error.status_code"))
        query_reason = evidence_lookup(observed, "query_result_or_error.reason")
        return (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and status_code_in_range(observed, "signal_error.status_code", 400, 499)
            and evidence_lookup(observed, "signal_error.reason") == "run_not_active"
            and evidence_lookup(observed, "signal_error.rejection_reason") == "run_not_active"
            and query_status is not None
            and 200 <= query_status <= 499
            and (query_status < 400 or required_evidence_satisfied("query_result_or_error.reason", query_reason))
        )

    if scenario == "malformed_signal_and_query_payloads":
        return (
            all(
                required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
                for evidence_key in SCENARIO_REQUIRED_EVIDENCE[scenario]
            )
            and status_code_in_range(observed, "invalid_signal_arguments.status_code", 422, 422)
            and status_code_in_range(observed, "invalid_query_arguments.status_code", 422, 422)
            and evidence_lookup(observed, "invalid_signal_arguments.reason") == "invalid_signal_arguments"
            and evidence_lookup(observed, "invalid_query_arguments.reason") == "invalid_query_arguments"
            and integer_value(evidence_lookup(
                observed,
                "signal_handler_invocation_count_after_invalid_payload",
            )) == 0
            and integer_value(evidence_lookup(
                observed,
                "query_state_mutation_count_after_invalid_payload",
            )) == 0
        )

    return all(
        required_evidence_satisfied(evidence_key, evidence_lookup(observed, evidence_key))
        for evidence_key in SCENARIO_REQUIRED_EVIDENCE.get(scenario, [])
    )


def scenario_result_items(raw: Any) -> list[dict[str, Any]]:
    if isinstance(raw, dict):
        items = []
        for scenario_id, value in raw.items():
            if not isinstance(value, dict):
                continue
            item = dict(value)
            item.setdefault("scenario_id", scenario_id)
            items.append(item)
        return items

    if isinstance(raw, list):
        return [item for item in raw if isinstance(item, dict)]

    return []


def scenario_evidence_candidate(scenario: str) -> dict[str, Any] | None:
    if not isinstance(smoke_evidence, dict):
        return None

    for field in ("scenario_results", "scenarioResults"):
        for item in scenario_result_items(smoke_evidence.get(field)):
            candidate_scenario = item.get("scenario_id") or item.get("scenario") or item.get("id")
            if candidate_scenario == scenario:
                return item

    direct = smoke_evidence.get(scenario)
    if isinstance(direct, dict):
        return direct

    for section in (
        "replay_timing",
        "terminal_run_behavior",
        "adversarial_errors",
        "waterline_observer_comparison",
    ):
        section_value = smoke_evidence.get(section)
        if not isinstance(section_value, dict):
            continue

        keyed = section_value.get(scenario)
        if isinstance(keyed, dict):
            return keyed

        for item in scenario_result_items(section_value):
            candidate_scenario = item.get("scenario_id") or item.get("scenario") or item.get("id")
            if candidate_scenario == scenario:
                return item

    return None


def scenario_status(candidate: dict[str, Any]) -> str:
    for field in ("status", "outcome", "verdict"):
        status = candidate.get(field)
        if isinstance(status, str) and status in ALLOWED_SCENARIO_STATUSES:
            return status

    return ""


def scenario_observed_outputs(candidate: dict[str, Any]) -> dict[str, Any]:
    for field in ("observed_outputs", "observedOutputs", "evidence", "outputs"):
        value = candidate.get(field)
        if isinstance(value, dict):
            return dict(value)

    metadata_fields = {
        "scenario_id",
        "scenario",
        "id",
        "status",
        "outcome",
        "verdict",
        "linked_findings",
        "linkedFindings",
        "finding_links",
        "findingLinks",
    }
    return {
        key: value
        for key, value in candidate.items()
        if key not in metadata_fields
    }


def scenario_linked_findings(candidate: dict[str, Any]) -> list[Any]:
    for field in ("linked_findings", "linkedFindings", "finding_links", "findingLinks"):
        value = candidate.get(field)
        if isinstance(value, list) and value:
            return value
    return []


def imported_scenario_result(scenario: str) -> dict[str, Any] | None:
    candidate = scenario_evidence_candidate(scenario)
    if candidate is None:
        return None

    observed = scenario_observed_outputs(candidate)
    if smoke_descriptor is not None:
        observed.setdefault("external_smoke_evidence", smoke_descriptor)

    if not candidate_matches_current_tuple(candidate, observed):
        return None

    status = scenario_status(candidate)
    if status == "" and has_required_evidence(scenario, observed):
        status = "pass"

    if status == "pass":
        if not has_required_evidence(scenario, observed):
            return None
        return {
            "scenario_id": scenario,
            "status": "pass",
            "observed_outputs": observed,
        }

    if status in ALLOWED_SCENARIO_STATUSES:
        result: dict[str, Any] = {
            "scenario_id": scenario,
            "status": status,
        }
        if observed:
            result["observed_outputs"] = observed
        linked_findings = scenario_linked_findings(candidate)
        if linked_findings:
            result["linked_findings"] = linked_findings
        return result

    return None


result_dir = Path(os.environ["RESULT_DIR"])
started_at = os.environ["STARTED_AT"]
finished_at = now()
artifact_versions = {
    "server": os.environ["DW_SERVER_VERSION"],
    "cli": os.environ["DW_CLI_VERSION"],
    "sdk-python": os.environ["DW_PYTHON_SDK_VERSION"],
    "workflow": os.environ["DW_WORKFLOW_PHP_VERSION"],
    "workflow-php": os.environ["DW_WORKFLOW_PHP_VERSION"],
    "waterline": os.environ["DW_WATERLINE_VERSION"],
}

smoke_path = os.environ.get("DW_SIGNALS_QUERIES_EVIDENCE", "") or os.environ.get(
    "DW_SIGNALS_QUERIES_SMOKE_EVIDENCE",
    "",
)
smoke_evidence: Any = None
smoke_descriptor: dict[str, Any] | None = None
if smoke_path:
    candidate = Path(smoke_path)
    if candidate.is_file():
        raw = candidate.read_bytes()
        smoke_descriptor = {
            "file": candidate.name,
            "sha256": hashlib.sha256(raw).hexdigest(),
        }
        try:
            smoke_evidence = json.loads(raw.decode("utf-8"))
        except Exception as exc:
            smoke_descriptor["decode_error"] = f"{type(exc).__name__}: {exc}"

required_scenarios = [
    "published_artifact_install_only",
    "python_worker_cli_and_sdk_baseline",
    "php_worker_cli_and_sdk_baseline",
    "python_worker_php_facing_and_cli_clients",
    "php_worker_python_and_cli_clients",
    "ordered_signal_delivery",
    "dedup_contract_observation",
    "signal_during_replay",
    "query_during_replay",
    "completed_run_signal_and_query",
    "unknown_signal_and_query_errors",
    "malformed_signal_and_query_payloads",
    "waterline_operator_visibility",
]

scenario_routes = {
    "published_artifact_install_only": {
        "type": "signal_query_published_artifact_install_uncovered",
        "owner": "conformance_harness",
        "title": "Signals/queries published-artifact install evidence remains unproved",
        "acceptance": [
            "resolve concrete server, CLI, Python SDK, PHP workflow, and Waterline versions",
            "prove every actor starts from a published package, image, or release asset",
        ],
    },
    "python_worker_cli_and_sdk_baseline": {
        "type": "signal_query_python_smoke_uncovered",
        "owner": "sdk-python, cli, server",
        "title": "Signals/queries Python worker CLI and SDK baseline remains unproved",
        "acceptance": [
            "start Counter on the Python worker",
            "verify CLI and Python SDK signals update query-visible state",
            "record immediate repeat-query consistency",
        ],
    },
    "ordered_signal_delivery": {
        "type": "signal_query_ordered_delivery_uncovered",
        "owner": "server",
        "title": "Signals/queries ordered delivery evidence remains unproved",
        "acceptance": [
            "send increment(1) through increment(10) rapidly",
            "query total 55",
            "record history signal order matching submission order",
        ],
    },
    "dedup_contract_observation": {
        "type": "signal_query_dedup_contract_uncovered",
        "owner": "server, sdk-python, workflow, cli, docs",
        "title": "Signals/queries dedup contract remains unproved",
        "acceptance": [
            "send duplicate signals with the documented idempotency or dedup key when supported",
            "record whether the handler observes one transition or two",
            "link any docs/runtime mismatch to the owning surface",
        ],
    },
    "php_worker_cli_and_sdk_baseline": {
        "type": "signal_query_php_worker_mirror_uncovered",
        "owner": "workflow",
        "title": "Signals/queries PHP worker mirror remains unproved",
        "acceptance": [
            "start Counter on the PHP worker",
            "verify CLI and PHP SDK signals update query-visible state",
            "record PHP handler and query evidence using published artifacts",
        ],
    },
    "python_worker_php_facing_and_cli_clients": {
        "type": "signal_query_cross_language_client_matrix_uncovered",
        "owner": "workflow, cli, server",
        "title": "Signals/queries Python worker with PHP-facing clients remains unproved",
        "acceptance": [
            "start Counter on the Python worker from a PHP-facing client",
            "send signals from PHP and CLI clients",
            "prove query results agree across clients",
        ],
    },
    "php_worker_python_and_cli_clients": {
        "type": "signal_query_cross_language_client_matrix_uncovered",
        "owner": "workflow, sdk-python, cli, server",
        "title": "Signals/queries PHP worker with Python and CLI clients remains unproved",
        "acceptance": [
            "start Counter on the PHP worker from the Python SDK",
            "send signals from Python and CLI clients",
            "prove query results agree across clients",
        ],
    },
    "signal_during_replay": {
        "type": "signal_query_replay_timing_uncovered",
        "owner": "workflow, sdk-python, server",
        "title": "Signals during replay timing remains unproved",
        "acceptance": [
            "restart a worker with non-empty history",
            "send a signal while replay is in progress",
            "prove the signal applies after replay reaches a consistent point",
        ],
    },
    "query_during_replay": {
        "type": "signal_query_replay_timing_uncovered",
        "owner": "workflow, sdk-python, server",
        "title": "Query during replay consistency remains unproved",
        "acceptance": [
            "restart a worker with non-empty history",
            "query while replay is in progress",
            "prove the answer matches the expected replay-consistent state",
        ],
    },
    "completed_run_signal_and_query": {
        "type": "signal_query_completed_run_handling_uncovered",
        "owner": "server, workflow, sdk-python, cli",
        "title": "Signals/queries completed-run handling remains unproved",
        "acceptance": [
            "complete Counter cleanly with a replayable query handler",
            "prove signal-to-completed-run returns a typed terminal outcome",
            "prove every claimed query surface returns final state or a documented handler-unavailable error",
        ],
    },
    "unknown_signal_and_query_errors": {
        "type": "signal_query_unknown_handler_errors_uncovered",
        "owner": "server, workflow, sdk-python, cli",
        "title": "Signals/queries unknown-handler errors remain unproved",
        "acceptance": [
            "send an unknown signal and unknown query",
            "capture stable typed error envelopes",
            "prove known queries still work after the errors",
        ],
    },
    "malformed_signal_and_query_payloads": {
        "type": "signal_query_adversarial_error_shapes_uncovered",
        "owner": "server, workflow, sdk-python, cli",
        "title": "Signals/queries malformed-payload errors remain unproved",
        "acceptance": [
            "send malformed signal and query payloads",
            "capture stable validation or decoding errors with argument context",
            "prove malformed attempts do not mutate workflow state",
            "record public CLI and Python SDK error samples for malformed signal and query calls",
        ],
    },
    "waterline_operator_visibility": {
        "type": "signal_query_waterline_observer_comparison_uncovered",
        "owner": "waterline",
        "title": "Signals/queries Waterline observer comparison remains unproved",
        "acceptance": [
            "compare Waterline selected-run detail against server, CLI, and SDK observations",
            "show applied, rejected, and terminal-run signal/query outcomes",
            "record any unsupported Waterline query-result materialization as an explicit finding",
        ],
    },
}

smoke_attached = smoke_evidence is not None
smoke_tuple_matches = smoke_evidence_matches_current_tuple()
smoke_tuple_mismatches = smoke_artifact_version_mismatches()
smoke_source_policy_violations = evidence_source_policy_violations(smoke_evidence)
smoke_source_policy_ok = smoke_source_policy_violations == []
if smoke_descriptor is not None and smoke_tuple_mismatches:
    smoke_descriptor["artifact_version_mismatches"] = smoke_tuple_mismatches
if smoke_descriptor is not None and smoke_source_policy_violations:
    smoke_descriptor["artifact_source_policy_violations"] = smoke_source_policy_violations
install_evidence_pass = smoke_attached and smoke_tuple_matches and smoke_source_policy_ok and artifact_versions_pinned()
python_smoke_pass = smoke_attached and smoke_tuple_matches and smoke_source_policy_ok and exact_python_smoke_present()
ordered_delivery_pass = smoke_attached and smoke_tuple_matches and smoke_source_policy_ok and exact_ordered_delivery_smoke_present()
scenario_results: dict[str, dict[str, Any]] = {}
findings: list[dict[str, Any]] = []
finding_links: dict[str, list[str]] = {}

for scenario in required_scenarios:
    observed: dict[str, Any] = {}
    status = "not_covered"
    imported_result = imported_scenario_result(scenario)

    if imported_result is not None:
        result = imported_result
        status = str(result["status"])
    elif install_evidence_pass and scenario == "published_artifact_install_only":
        status = "pass"
        observed = {
            "published_artifact_versions": artifact_versions,
            "artifact_sources": {
                "server": "published_docker_image",
                "cli": "published_cli_release",
                "sdk-python": "published_pypi_package",
                "workflow-php": "published_composer_package",
                "waterline": "published_waterline_artifact",
            },
            "external_smoke_evidence": smoke_descriptor,
        }
        result = {
            "scenario_id": scenario,
            "status": status,
            "observed_outputs": observed,
        }
    elif python_smoke_pass and scenario == "python_worker_cli_and_sdk_baseline":
        status = "pass"
        observed = {
            "python_worker_query_task_routing": smoke_field(
                "python_worker_query_task_routing",
                scenario,
            ),
            "cli_signal_and_query": smoke_field("cli_signal_and_query", scenario),
            "sdk_python_signal_and_query": smoke_field("sdk_python_signal_and_query", scenario),
            "immediate_repeat_query_consistency": smoke_field(
                "immediate_repeat_query_consistency",
                scenario,
            ),
            "external_smoke_evidence": smoke_descriptor,
        }
        result = {
            "scenario_id": scenario,
            "status": status,
            "observed_outputs": observed,
        }
    elif ordered_delivery_pass and scenario == "ordered_signal_delivery":
        status = "pass"
        observed = {
            "rapid_increment_inputs": smoke_field("rapid_increment_inputs", scenario),
            "queried_total": smoke_field("ten_signal_ordered_delivery_total", scenario),
            "history_signal_order": smoke_field("history_signal_order", scenario),
            "external_smoke_evidence": smoke_descriptor,
        }
        result = {
            "scenario_id": scenario,
            "status": status,
            "observed_outputs": observed,
        }
    else:
        result = {
            "scenario_id": scenario,
            "status": status,
        }

    if status != "pass":
        linked_findings = result.get("linked_findings")
        if isinstance(linked_findings, list) and linked_findings:
            finding_links[scenario] = linked_findings
            findings.extend([item for item in linked_findings if isinstance(item, dict)])
        else:
            route = scenario_routes[scenario]
            finding_id = route["type"]
            finding = {
                "id": finding_id,
                "type": route["type"],
                "scenario_id": scenario,
                "owner": route["owner"],
                "title": route["title"],
                "current_evidence": {
                    "published_artifact_evidence_present": smoke_attached,
                    "evidence": smoke_descriptor,
                },
                "acceptance": route["acceptance"],
            }
            result["linked_findings"] = [finding_id]
            findings.append(finding)
            finding_links[scenario] = [finding_id]

    scenario_results[scenario] = result

pins = {
    "artifact_versions": artifact_versions,
    "artifact_sources": {
        "server": "published_docker_image",
        "cli": "published_cli_release",
        "sdk-python": "published_pypi_package",
        "workflow-php": "published_composer_package",
        "waterline": "published_waterline_artifact",
    },
}
write_json(result_dir / "pins.json", pins)

run_metadata = {
    "schema": "durable-workflow.v2.signal-query-runtime.run-metadata",
    "started_at": started_at,
    "finished_at": finished_at,
    "runner": "scripts/conformance/signals-queries-published-artifacts.sh",
    "local_product_source_checkouts_used": False,
    "smoke_evidence": smoke_descriptor,
}
write_json(result_dir / "run-metadata.json", run_metadata)
write_json(result_dir / "signals-queries-findings.json", findings)

def section_for(*scenario_ids: str) -> dict[str, dict[str, Any]]:
    return {
        scenario_id: {
            "status": scenario_results[scenario_id]["status"],
            "linked_findings": scenario_results[scenario_id].get("linked_findings", []),
            "observed_outputs": scenario_results[scenario_id].get("observed_outputs", {}),
        }
        for scenario_id in scenario_ids
    }


outcome = "pass" if not findings and all(item["status"] == "pass" for item in scenario_results.values()) else "non_passing"
result = {
    "schema": "durable-workflow.v2.signal-query-runtime.result",
    "started_at": started_at,
    "finished_at": finished_at,
    "outcome": outcome,
    "runner_blocked": False,
    "artifactVersions": artifact_versions,
    "artifact_sources": pins["artifact_sources"],
    "runtime_matrix": {
        "runtimes": ["workflow-php", "sdk-python"],
        "same_language_cells": [
            {
                "scenario": "python_worker_cli_and_sdk_baseline",
                "worker": "sdk-python",
                "clients": ["cli", "sdk-python"],
            },
            {
                "scenario": "php_worker_cli_and_sdk_baseline",
                "worker": "workflow-php",
                "clients": ["cli", "workflow-php-sdk"],
            },
        ],
        "cross_language_cells": [
            {
                "scenario": "python_worker_php_facing_and_cli_clients",
                "worker": "sdk-python",
                "clients": ["workflow-php-sdk", "cli"],
            },
            {
                "scenario": "php_worker_python_and_cli_clients",
                "worker": "workflow-php",
                "clients": ["sdk-python", "cli"],
            },
        ],
    },
    "replay_timing": section_for("signal_during_replay", "query_during_replay"),
    "terminal_run_behavior": section_for("completed_run_signal_and_query"),
    "adversarial_errors": section_for(
        "unknown_signal_and_query_errors",
        "malformed_signal_and_query_payloads",
    ),
    "waterline_observer_comparison": section_for("waterline_operator_visibility"),
    "scenario_results": scenario_results,
    "findings": findings,
    "finding_links": finding_links,
}
write_json(result_dir / "signals-queries-result.json", result)

record = {
    "experiment": "signals-queries",
    "outcome": "pass" if outcome == "pass" else "fail",
    "runnerBlocked": False,
    "artifactVersions": artifact_versions,
    "result_file": "signals-queries-result.json",
    "findings_file": "signals-queries-findings.json",
}
write_json(result_dir / "signals-queries-record.json", record)

print(json.dumps({"outcome": outcome, "result_dir": str(result_dir)}, sort_keys=True))
PY
