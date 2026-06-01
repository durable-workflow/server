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
  DW_SIGNALS_QUERIES_SMOKE_EVIDENCE         Optional JSON evidence from a real smoke run.
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


def smoke_field(key: str) -> Any:
    if smoke_evidence is None:
        return MISSING
    return evidence_value(smoke_evidence, key)


def smoke_field_present(key: str) -> bool:
    value = smoke_field(key)
    return value is not MISSING and value not in (None, "", [], {})


def smoke_field_true(key: str) -> bool:
    value = smoke_field(key)
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


def exact_python_smoke_present() -> bool:
    return all(
        smoke_field_true(field)
        for field in (
            "python_worker_query_task_routing",
            "cli_signal_and_query",
            "sdk_python_signal_and_query",
            "immediate_repeat_query_consistency",
        )
    )


def exact_ordered_delivery_smoke_present() -> bool:
    rapid_inputs = smoke_field("rapid_increment_inputs")
    history_signal_order = smoke_field("history_signal_order")
    return (
        rapid_inputs == list(range(1, 11))
        and smoke_field("ten_signal_ordered_delivery_total") == 55
        and history_signal_order == list(range(1, 11))
    )


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

smoke_path = os.environ.get("DW_SIGNALS_QUERIES_SMOKE_EVIDENCE", "")
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
install_evidence_pass = smoke_attached and artifact_versions_pinned()
python_smoke_pass = smoke_attached and exact_python_smoke_present()
ordered_delivery_pass = smoke_attached and exact_ordered_delivery_smoke_present()
scenario_results: dict[str, dict[str, Any]] = {}
findings: list[dict[str, Any]] = []
finding_links: dict[str, list[str]] = {}

for scenario in required_scenarios:
    observed: dict[str, Any] = {}
    status = "not_covered"

    if install_evidence_pass and scenario == "published_artifact_install_only":
        status = "pass"
        observed = {
            "published_artifact_versions": artifact_versions,
            "external_smoke_evidence": smoke_descriptor,
        }
    elif python_smoke_pass and scenario == "python_worker_cli_and_sdk_baseline":
        status = "pass"
        observed = {
            "python_worker_query_task_routing": smoke_field("python_worker_query_task_routing"),
            "cli_signal_and_query": smoke_field("cli_signal_and_query"),
            "sdk_python_signal_and_query": smoke_field("sdk_python_signal_and_query"),
            "immediate_repeat_query_consistency": smoke_field("immediate_repeat_query_consistency"),
            "external_smoke_evidence": smoke_descriptor,
        }
    elif ordered_delivery_pass and scenario == "ordered_signal_delivery":
        status = "pass"
        observed = {
            "rapid_increment_inputs": smoke_field("rapid_increment_inputs"),
            "queried_total": smoke_field("ten_signal_ordered_delivery_total"),
            "history_signal_order": smoke_field("history_signal_order"),
            "external_smoke_evidence": smoke_descriptor,
        }

    result = {
        "scenario_id": scenario,
        "status": status,
    }
    if observed:
        result["observed_outputs"] = observed

    if status != "pass":
        route = scenario_routes[scenario]
        finding_id = route["type"]
        finding = {
            "id": finding_id,
            "type": route["type"],
            "scenario_id": scenario,
            "owner": route["owner"],
            "title": route["title"],
            "current_evidence": {
                "published_artifact_smoke_present": smoke_attached,
                "smoke_evidence": smoke_descriptor,
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
