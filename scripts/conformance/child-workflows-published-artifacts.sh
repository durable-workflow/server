#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: child-workflows-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Writes a scenario-level child-workflows conformance result for published artifacts.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  child-workflows-result.json
  child-workflows-record.json

Environment overrides:
  DW_CHILD_WORKFLOWS_RESULT_DIR          Result directory. Defaults to run root.
  DW_CHILD_WORKFLOWS_RUN_ROOT           Scratch directory. Defaults to mktemp.
  DW_CHILD_WORKFLOWS_KEEP_RUN_ROOT=1    Keep scratch directory after success.
  DW_CHILD_WORKFLOWS_SCENARIO_MANIFEST  Scenario manifest path. Defaults to the server static mirror.
  DW_CHILD_WORKFLOWS_ARTIFACT_INSTALL_EVIDENCE
                                      JSON proof that each published artifact was downloaded/installed.
                                      Defaults to artifact-install-evidence.json in the result directory.
  DW_CHILD_WORKFLOWS_TYPED_FAILURE_EVIDENCE
                                      JSON proof for failure-round-trip cells observed through published
                                      artifacts. A partial cell set is recorded but cannot pass the matrix.
  DW_SERVER_IMAGE                       Exact server image tag or digest to test.
  DW_SERVER_VERSION                     Exact patch server Docker tag; required for digest-only DW_SERVER_IMAGE.
  DW_CLI_VERSION                        Exact CLI release version.
  DW_PYTHON_SDK_VERSION                 Exact PyPI durable-workflow version.
  DW_WORKFLOW_PHP_VERSION               Exact Composer durable-workflow/workflow version.
  DW_WATERLINE_VERSION                  Exact Waterline artifact version.
USAGE
}

keep_run_root="${DW_CHILD_WORKFLOWS_KEEP_RUN_ROOT:-0}"
result_dir="${DW_CHILD_WORKFLOWS_RESULT_DIR:-}"

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
    --keep-run-root)
      keep_run_root=1
      shift
      ;;
    --keep-run-root=*)
      keep_run_root="${1#--keep-run-root=}"
      if [[ "$keep_run_root" == "true" ]]; then
        keep_run_root=1
      elif [[ "$keep_run_root" != "1" ]]; then
        keep_run_root=0
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

timestamp() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

require_command() {
  local name="$1"

  command -v "$name" >/dev/null 2>&1
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
scenario_manifest="${DW_CHILD_WORKFLOWS_SCENARIO_MANIFEST:-$repo_root/static/platform-conformance/child-workflow-runtime-scenarios.json}"

run_root="${DW_CHILD_WORKFLOWS_RUN_ROOT:-}"
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-child-workflows.XXXXXX")"
fi
mkdir -p "$run_root"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"

cleanup() {
  local code=$?

  if [[ "$keep_run_root" != "1" && "$code" -eq 0 && "$result_dir" != "$run_root" ]]; then
    rm -rf "$run_root"
  fi
}
trap cleanup EXIT

started_at="$(timestamp)"

if ! require_command python3; then
  printf '%s\n' 'required command not found: python3' >&2
  exit 1
fi

python3 - "$result_dir" "$started_at" "$scenario_manifest" <<'PY'
from __future__ import annotations

import json
import os
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any, Optional

RESULT_DIR = Path(sys.argv[1])
STARTED_AT = sys.argv[2]
MANIFEST_PATH = Path(sys.argv[3])

SEMVER_RE = re.compile(r"^\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?$")
SERVER_TAG_RE = re.compile(r"(?::|/)(\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.-]+)?)$")
PLACEHOLDER_RE = re.compile(r"(<[^>]+>|\$\{[^}]+}|{{[^}]+}}|(^|[^a-z0-9])latest([^a-z0-9]|$))", re.I)

REQUIRED_INSTALL_ARTIFACTS = [
    "server",
    "cli",
    "sdk-python",
    "workflow-php",
    "waterline",
]

FORBIDDEN_INSTALL_SOURCE_TOKENS = [
    "local_product_source_checkout",
    "workspace_repo_as_artifact_under_test",
    "local_checkout",
    "source_checkout",
    "/workspace/repos/",
]

FALLBACK_REQUIRED_SCENARIO_IDS = [
    "published_artifact_install_only",
    "python_parent_python_child_baseline",
    "php_parent_php_child_baseline",
    "php_parent_python_child_cross_language",
    "python_parent_php_child_cross_language",
    "child_failure_round_trip_matrix",
    "parent_cancellation_propagates_to_child",
    "direct_child_cancellation_observed_by_parent",
    "worker_restart_replay_preserves_child_outcome",
    "concurrent_child_fan_out",
    "child_workflow_namespace_contract",
]

DEFAULT_EXPECTED_BEHAVIOR = {
    "published_artifact_install_only": "all artifacts are resolved from published install channels",
    "python_parent_python_child_baseline": "Python parent starts Python child, receives the exact child result, and records child schedule/completion events",
    "php_parent_php_child_baseline": "PHP parent starts PHP child, receives the exact child result, and records child schedule/completion events",
    "php_parent_python_child_cross_language": "PHP parent starts Python child by workflow type and receives the typed child result",
    "python_parent_php_child_cross_language": "Python parent starts PHP child by workflow type and receives the typed child result",
    "child_failure_round_trip_matrix": "typed child failures preserve exception class, message, and failure kind across all parent/child runtime cells",
    "parent_cancellation_propagates_to_child": "cancelling the parent cancels the scheduled child and the child worker observes typed cancellation",
    "direct_child_cancellation_observed_by_parent": "direct child cancellation is surfaced to the parent as typed cancellation rather than timeout",
    "worker_restart_replay_preserves_child_outcome": "a parent worker restart replays child completion deterministically and does not schedule a duplicate child",
    "concurrent_child_fan_out": "a parent starts five children concurrently and aggregates all child results",
    "child_workflow_namespace_contract": "child workflow lineage records namespace identity and cross-namespace behavior is supported or linked to a documented root-cause finding",
}


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def env(name: str) -> str:
    return os.environ.get(name, "").strip()


def load_manifest() -> dict[str, Any]:
    if not MANIFEST_PATH.exists():
        return {}
    return json.loads(MANIFEST_PATH.read_text(encoding="utf-8"))


def normalize_cli_version(value: str) -> str:
    return value[1:] if value.startswith("v") and SEMVER_RE.match(value[1:]) else value


def derive_server_version(server_image: str, explicit_version: str) -> str:
    if explicit_version:
        return explicit_version
    match = SERVER_TAG_RE.search(server_image)
    return match.group(1) if match else ""


def is_placeholder(value: str) -> bool:
    return bool(value and PLACEHOLDER_RE.search(value.lower()))


def exact_version_failures(versions: dict[str, str], server_image: str) -> list[str]:
    failures: list[str] = []
    required = {
        "server": "DW_SERVER_VERSION or exact DW_SERVER_IMAGE tag",
        "cli": "DW_CLI_VERSION",
        "sdk-python": "DW_PYTHON_SDK_VERSION",
        "workflow": "DW_WORKFLOW_PHP_VERSION",
        "waterline": "DW_WATERLINE_VERSION",
    }
    for key, label in required.items():
        version = versions.get(key, "")
        if not version:
            failures.append(f"missing {label}")
            continue
        if is_placeholder(version) or not SEMVER_RE.match(version):
            failures.append(f"{label} must be an exact semver artifact version; got {version!r}")

    if server_image:
        if is_placeholder(server_image):
            failures.append(f"DW_SERVER_IMAGE must not use a rolling tag or placeholder; got {server_image!r}")
        tag_match = SERVER_TAG_RE.search(server_image)
        if tag_match and versions.get("server") and tag_match.group(1) != versions["server"]:
            failures.append(
                f"DW_SERVER_VERSION {versions['server']!r} does not match DW_SERVER_IMAGE tag {tag_match.group(1)!r}",
            )
        if "@sha256:" in server_image and not versions.get("server"):
            failures.append("DW_SERVER_VERSION is required when DW_SERVER_IMAGE is digest-pinned")

    return failures


def string_value(value: Any) -> str:
    return str(value).strip() if isinstance(value, (str, int, float, bool)) else ""


def truthy_flag(value: Any) -> bool:
    if value is True or value == 1:
        return True
    if isinstance(value, str):
        return value.strip().lower() in {"1", "true", "yes", "y", "on"}
    return False


def explicit_false_flag(value: Any) -> bool:
    if value is False or value == 0:
        return True
    if isinstance(value, str):
        return value.strip().lower() in {"0", "false", "no", "n", "off"}
    return False


def normalized_status(value: Any) -> str:
    status = string_value(value).lower()
    if status in {"pass", "passed", "success", "ok"}:
        return "pass"
    if status in {"fail", "failed", "failure"}:
        return "fail"
    if status in {"blocked", "runner_blocked", "error"}:
        return "runner_blocked"
    if status in {"not_covered", "missing", "not_exercised", "unsupported"}:
        return status
    return status


def artifact_version_for(versions: dict[str, str], artifact: str) -> str:
    aliases = {
        "workflow-php": ["workflow-php", "workflow"],
        "sdk-python": ["sdk-python", "sdk_python", "python"],
    }
    for key in aliases.get(artifact, [artifact]):
        value = versions.get(key, "")
        if value:
            return value
    return ""


def entry_source(entry: dict[str, Any]) -> str:
    for key in (
        "source",
        "install_source",
        "installSource",
        "artifact_source",
        "artifactSource",
        "resolved_source",
        "resolvedSource",
    ):
        value = string_value(entry.get(key))
        if value:
            return value
    return ""


def first_string(entry: dict[str, Any], keys: list[str]) -> str:
    for key in keys:
        value = string_value(entry.get(key))
        if value:
            return value
    return ""


def array_value(entry: dict[str, Any], keys: list[str]) -> list[Any]:
    for key in keys:
        value = entry.get(key)
        if isinstance(value, list):
            return value
    return []


def load_artifact_install_evidence(path: Path) -> Optional[dict[str, Any]]:
    if not path.exists():
        return None
    try:
        evidence = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:  # noqa: BLE001 - this script must route malformed evidence as runner output
        return {
            "schema": "durable-workflow.v2.child-workflow-runtime.artifact-install-evidence",
            "generated_at": now(),
            "local_product_source_checkouts_used": False,
            "artifacts": [],
            "evidence_load_error": f"{path}: {exc}",
        }
    return evidence if isinstance(evidence, dict) else {
        "schema": "durable-workflow.v2.child-workflow-runtime.artifact-install-evidence",
        "generated_at": now(),
        "local_product_source_checkouts_used": False,
        "artifacts": [],
        "evidence_load_error": f"{path}: expected a JSON object",
    }


def normalize_artifact_install_evidence(
    evidence: Optional[dict[str, Any]],
    artifact_versions: dict[str, str],
) -> dict[str, Any]:
    raw_artifacts = evidence.get("artifacts") if isinstance(evidence, dict) else []
    if not isinstance(raw_artifacts, list):
        raw_artifacts = []
    by_artifact: dict[str, dict[str, Any]] = {}
    for item in raw_artifacts:
        if not isinstance(item, dict):
            continue
        artifact = string_value(item.get("artifact") or item.get("name"))
        if artifact:
            by_artifact[artifact] = item

    artifacts: list[dict[str, Any]] = []
    for artifact in REQUIRED_INSTALL_ARTIFACTS:
        item = by_artifact.get(artifact, {})
        raw_version = string_value(
            item.get("version")
            or item.get("artifact_version")
            or item.get("artifactVersion")
            or item.get("resolved_version")
            or item.get("resolvedVersion"),
        )
        raw_source = entry_source(item)
        version = raw_version or artifact_version_for(artifact_versions, artifact)
        artifacts.append(
            {
                "artifact": artifact,
                "version": version,
                "version_provided": bool(raw_version),
                "source": raw_source or "not_exercised",
                "source_provided": bool(raw_source),
                "status": normalized_status(item.get("status") or item.get("result") or item.get("outcome")),
                "local_product_source_checkouts_used": truthy_flag(
                    item.get("local_product_source_checkouts_used")
                    or item.get("localProductSourceCheckoutsUsed"),
                ),
                "detail": string_value(item.get("detail") or item.get("observed_behavior")),
                "command": item.get("command") if isinstance(item, dict) else None,
                "output_sample": item.get("output_sample") or item.get("outputSample") or "",
            },
        )

    top_local = bool(
        isinstance(evidence, dict)
        and (
            truthy_flag(evidence.get("local_product_source_checkouts_used"))
            or truthy_flag(evidence.get("localProductSourceCheckoutsUsed"))
        ),
    )
    top_explicit_false = bool(
        isinstance(evidence, dict)
        and (
            explicit_false_flag(evidence.get("local_product_source_checkouts_used"))
            or explicit_false_flag(evidence.get("localProductSourceCheckoutsUsed"))
        ),
    )

    return {
        "schema": string_value(evidence.get("schema") if isinstance(evidence, dict) else "")
        or "durable-workflow.v2.child-workflow-runtime.artifact-install-evidence",
        "generated_at": string_value(evidence.get("generated_at") if isinstance(evidence, dict) else "") or now(),
        "local_product_source_checkouts_used": top_local
        or any(item["local_product_source_checkouts_used"] for item in artifacts),
        "local_product_source_checkouts_used_explicit_false": top_explicit_false,
        "evidence_load_error": string_value(evidence.get("evidence_load_error") if isinstance(evidence, dict) else ""),
        "artifacts": artifacts,
    }


def artifact_install_entry_by_name(evidence: dict[str, Any]) -> dict[str, dict[str, Any]]:
    entries: dict[str, dict[str, Any]] = {}
    artifacts = evidence.get("artifacts")
    if not isinstance(artifacts, list):
        return entries
    for item in artifacts:
        if not isinstance(item, dict):
            continue
        artifact = string_value(item.get("artifact") or item.get("name"))
        if artifact:
            entries[artifact] = item
    return entries


def install_source_is_forbidden(source: str) -> bool:
    normalized = source.lower()
    return any(token in normalized for token in FORBIDDEN_INSTALL_SOURCE_TOKENS)


def install_source_matches_artifact(artifact: str, version: str, source: str) -> bool:
    normalized = source.lower()
    if not source or source == "not_exercised" or is_placeholder(source) or install_source_is_forbidden(source):
        return False

    if artifact == "server" and "@sha256:" in normalized:
        return "durableworkflow/server" in normalized

    if version and version.lower() not in normalized:
        return False

    generic_sources = {
        "docker",
        "github_release",
        "github_release_installer",
        "published_install_script",
        "pypi",
        "packagist",
        "published_artifact",
    }
    if normalized in generic_sources:
        return False

    if artifact == "server":
        return "durableworkflow/server" in normalized
    if artifact == "cli":
        return "github" in normalized and ("release" in normalized or "/releases/" in normalized)
    if artifact == "sdk-python":
        return "pypi" in normalized or "pythonhosted.org" in normalized or "durable-workflow==" in normalized
    if artifact == "workflow-php":
        return "packagist" in normalized or "durable-workflow/workflow" in normalized
    if artifact == "waterline":
        return "packagist" in normalized or "durable-workflow/waterline" in normalized
    return False


def artifact_install_evidence_failures(
    evidence: dict[str, Any],
    artifact_versions: dict[str, str],
    evidence_was_supplied: bool,
) -> list[str]:
    failures: list[str] = []
    if not evidence_was_supplied:
        failures.append("artifact_install_evidence missing")
    if evidence.get("evidence_load_error"):
        failures.append(f"artifact_install_evidence load failed: {evidence['evidence_load_error']}")
    if evidence.get("local_product_source_checkouts_used"):
        failures.append("artifact_install_evidence.local_product_source_checkouts_used=true")
    if evidence_was_supplied and not evidence.get("local_product_source_checkouts_used_explicit_false"):
        failures.append("artifact_install_evidence.local_product_source_checkouts_used=false missing")

    entries = artifact_install_entry_by_name(evidence)
    for artifact in REQUIRED_INSTALL_ARTIFACTS:
        entry = entries.get(artifact)
        expected_version = artifact_version_for(artifact_versions, artifact)
        if entry is None:
            failures.append(f"{artifact}.artifact_install_evidence=missing")
            continue
        status = normalized_status(entry.get("status"))
        if status != "pass":
            failures.append(f"{artifact}.status={status or 'missing'}")

        version = string_value(entry.get("version"))
        if not truthy_flag(entry.get("version_provided")):
            failures.append(f"{artifact}.version=missing")
        elif not version or not SEMVER_RE.match(version) or is_placeholder(version):
            failures.append(f"{artifact}.version={version or 'missing'}")
        elif expected_version and version != expected_version:
            failures.append(f"{artifact}.version={version} does not match resolved artifact version {expected_version}")

        source = entry_source(entry)
        if not truthy_flag(entry.get("source_provided")):
            failures.append(f"{artifact}.source=missing")
        elif not install_source_matches_artifact(artifact, version, source):
            failures.append(f"{artifact}.source={source}")

        if truthy_flag(entry.get("local_product_source_checkouts_used") or entry.get("localProductSourceCheckoutsUsed")):
            failures.append(f"{artifact}.local_product_source_checkouts_used=true")

    return failures


def load_typed_failure_evidence(path_text: str) -> Optional[dict[str, Any]]:
    if not path_text:
        return None

    path = Path(path_text)
    if not path.exists():
        return {
            "schema": "durable-workflow.v2.child-workflow-runtime.typed-failure-evidence",
            "generated_at": now(),
            "local_product_source_checkouts_used": False,
            "failure_round_trip_cells": [],
            "evidence_load_error": f"{path}: missing",
        }

    try:
        evidence = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:  # noqa: BLE001 - malformed focused evidence must be routed as result data
        return {
            "schema": "durable-workflow.v2.child-workflow-runtime.typed-failure-evidence",
            "generated_at": now(),
            "local_product_source_checkouts_used": False,
            "failure_round_trip_cells": [],
            "evidence_load_error": f"{path}: {exc}",
        }

    return evidence if isinstance(evidence, dict) else {
        "schema": "durable-workflow.v2.child-workflow-runtime.typed-failure-evidence",
        "generated_at": now(),
        "local_product_source_checkouts_used": False,
        "failure_round_trip_cells": [],
        "evidence_load_error": f"{path}: expected a JSON object",
    }


def raw_typed_failure_cells(evidence: Optional[dict[str, Any]]) -> list[dict[str, Any]]:
    if not isinstance(evidence, dict):
        return []

    for key in ("failure_round_trip_cells", "failureRoundTripCells", "cells", "matrix"):
        value = evidence.get(key)
        if isinstance(value, list):
            return [item for item in value if isinstance(item, dict)]

    for key in ("failure_round_trip", "failureRoundTrip"):
        section = evidence.get(key)
        if not isinstance(section, dict):
            continue
        for section_key in ("failure_round_trip_cells", "failureRoundTripCells", "cells", "matrix"):
            value = section.get(section_key)
            if isinstance(value, list):
                return [item for item in value if isinstance(item, dict)]

    return []


def normalize_typed_failure_cell(cell: dict[str, Any]) -> dict[str, Any]:
    public_surfaces = array_value(cell, ["public_surfaces", "publicSurfaces", "observation_surfaces"])
    parent_history = array_value(
        cell,
        ["parent_history_observations", "parentHistoryObservations", "parent_history_excerpt", "parentHistoryExcerpt"],
    )
    child_history = array_value(
        cell,
        ["child_history_observations", "childHistoryObservations", "child_history_excerpt", "childHistoryExcerpt"],
    )
    observed_failure = cell.get("parent_observed_failure") or cell.get("parentObservedFailure") or {}
    if not isinstance(observed_failure, dict):
        observed_failure = {}

    return {
        "scenario": first_string(cell, ["scenario", "scenario_id", "scenarioId"]) or "child_failure_round_trip_matrix",
        "parent": first_string(cell, ["parent", "parent_runtime", "parentRuntime"]),
        "child": first_string(cell, ["child", "child_runtime", "childRuntime"]),
        "status": normalized_status(cell.get("status") or cell.get("result") or cell.get("outcome")),
        "exception_class": first_string(cell, ["exception_class", "exceptionClass", "error_class", "errorClass"]),
        "message": first_string(cell, ["message", "error_message", "errorMessage"]),
        "failure_kind": first_string(cell, ["failure_kind", "failureKind", "kind"]),
        "parent_workflow_id": first_string(cell, ["parent_workflow_id", "parentWorkflowId"]),
        "parent_run_id": first_string(cell, ["parent_run_id", "parentRunId"]),
        "child_workflow_id": first_string(cell, ["child_workflow_id", "childWorkflowId"]),
        "child_run_id": first_string(cell, ["child_run_id", "childRunId"]),
        "parent_history_observations": parent_history,
        "child_history_observations": child_history,
        "public_surfaces": public_surfaces,
        "parent_observed_failure": observed_failure,
        "local_product_source_checkouts_used": truthy_flag(
            cell.get("local_product_source_checkouts_used") or cell.get("localProductSourceCheckoutsUsed"),
        ),
        "owning_surface": first_string(cell, ["owning_surface", "owningSurface", "root_cause_surface", "rootCauseSurface"]),
        "observed_behavior": first_string(cell, ["observed_behavior", "observedBehavior", "detail"]),
    }


def normalize_typed_failure_evidence(evidence: Optional[dict[str, Any]]) -> dict[str, Any]:
    top_local = bool(
        isinstance(evidence, dict)
        and (
            truthy_flag(evidence.get("local_product_source_checkouts_used"))
            or truthy_flag(evidence.get("localProductSourceCheckoutsUsed"))
        ),
    )
    top_explicit_false = bool(
        isinstance(evidence, dict)
        and (
            explicit_false_flag(evidence.get("local_product_source_checkouts_used"))
            or explicit_false_flag(evidence.get("localProductSourceCheckoutsUsed"))
        ),
    )
    cells = [normalize_typed_failure_cell(item) for item in raw_typed_failure_cells(evidence)]
    versions = {}
    if isinstance(evidence, dict):
        raw_versions = evidence.get("artifact_versions") or evidence.get("artifactVersions") or {}
        if isinstance(raw_versions, dict):
            versions = {str(key): string_value(value) for key, value in raw_versions.items()}

    return {
        "schema": string_value(evidence.get("schema") if isinstance(evidence, dict) else "")
        or "durable-workflow.v2.child-workflow-runtime.typed-failure-evidence",
        "generated_at": string_value(evidence.get("generated_at") if isinstance(evidence, dict) else "") or now(),
        "local_product_source_checkouts_used": top_local
        or any(item["local_product_source_checkouts_used"] for item in cells),
        "local_product_source_checkouts_used_explicit_false": top_explicit_false,
        "artifact_versions": versions,
        "evidence_load_error": string_value(evidence.get("evidence_load_error") if isinstance(evidence, dict) else ""),
        "failure_round_trip_cells": cells,
    }


def typed_failure_evidence_failures(
    evidence: dict[str, Any],
    artifact_versions: dict[str, str],
    evidence_was_supplied: bool,
    install_evidence_pass: bool,
) -> list[str]:
    if not evidence_was_supplied:
        return []

    failures: list[str] = []
    if evidence.get("evidence_load_error"):
        failures.append(f"typed_failure_evidence load failed: {evidence['evidence_load_error']}")
    if not install_evidence_pass:
        failures.append("typed_failure_evidence requires passing published artifact install evidence")
    if evidence.get("local_product_source_checkouts_used"):
        failures.append("typed_failure_evidence.local_product_source_checkouts_used=true")
    if not evidence.get("local_product_source_checkouts_used_explicit_false"):
        failures.append("typed_failure_evidence.local_product_source_checkouts_used=false missing")

    reported_versions = evidence.get("artifact_versions")
    if isinstance(reported_versions, dict):
        for artifact in ("server", "cli", "sdk-python", "workflow", "waterline"):
            reported = string_value(reported_versions.get(artifact))
            expected = string_value(artifact_versions.get(artifact))
            if reported and expected and reported != expected:
                failures.append(f"typed_failure_evidence.{artifact}.version={reported} does not match {expected}")

    cells = evidence.get("failure_round_trip_cells")
    if not isinstance(cells, list) or not cells:
        failures.append("typed_failure_evidence.failure_round_trip_cells=missing")

    return failures


def cell_matches(cell: dict[str, Any], required_cell: dict[str, Any]) -> bool:
    return (
        cell.get("scenario") == required_cell.get("scenario")
        and cell.get("parent") == required_cell.get("parent")
        and cell.get("child") == required_cell.get("child")
    )


def find_cell(cells: list[dict[str, Any]], required_cell: dict[str, Any]) -> Optional[dict[str, Any]]:
    for cell in cells:
        if cell_matches(cell, required_cell):
            return cell
    return None


def typed_failure_cell_failures(cell: dict[str, Any]) -> list[str]:
    failures: list[str] = []
    if cell.get("local_product_source_checkouts_used"):
        failures.append("local_product_source_checkouts_used=true")

    status = normalized_status(cell.get("status"))
    if status != "pass":
        return failures

    for field in (
        "exception_class",
        "message",
        "failure_kind",
        "parent_workflow_id",
        "parent_run_id",
        "child_workflow_id",
        "child_run_id",
    ):
        if not string_value(cell.get(field)):
            failures.append(f"{field}=missing")

    for field in ("parent_history_observations", "child_history_observations", "public_surfaces"):
        value = cell.get(field)
        if not isinstance(value, list) or not value:
            failures.append(f"{field}=missing")

    return failures


def failure_round_trip_evidence_state(
    matrix: dict[str, Any],
    typed_failure_evidence: dict[str, Any],
    typed_failure_failures: list[str],
    typed_failure_evidence_was_supplied: bool,
    default_status: str,
) -> dict[str, Any]:
    required_cells = matrix.get("failure_round_trip_cells")
    if not isinstance(required_cells, list):
        required_cells = []
    supplied_cells = typed_failure_evidence.get("failure_round_trip_cells")
    if not isinstance(supplied_cells, list):
        supplied_cells = []

    evidence_is_usable = typed_failure_evidence_was_supplied and not typed_failure_failures
    cells: list[dict[str, Any]] = []
    missing_cells: list[dict[str, Any]] = []
    invalid_cells: list[dict[str, Any]] = []
    failed_cells: list[dict[str, Any]] = []

    for required_cell in required_cells:
        if not isinstance(required_cell, dict):
            continue
        item = dict(required_cell)
        matched = find_cell(supplied_cells, required_cell)
        if matched is None:
            item["status"] = default_status
            cells.append(item)
            missing_cells.append(dict(required_cell))
            continue

        item.update({key: value for key, value in matched.items() if value not in ("", [], {})})
        cell_failures = typed_failure_cell_failures(matched)
        if not evidence_is_usable:
            item["status"] = default_status
        elif cell_failures:
            item["status"] = "not_covered"
            invalid_cells.append({"cell": dict(required_cell), "failures": cell_failures})
        else:
            status = normalized_status(matched.get("status")) or "not_covered"
            item["status"] = status
            if status != "pass":
                failed_cells.append(dict(item))
        cells.append(item)

    all_cells_pass = bool(required_cells) and not missing_cells and not invalid_cells and not failed_cells and evidence_is_usable
    if all_cells_pass:
        status = "pass"
    elif failed_cells and evidence_is_usable:
        status = "fail"
    else:
        status = default_status

    return {
        "status": status,
        "cells": cells,
        "all_cells_pass": all_cells_pass,
        "missing_cells": missing_cells,
        "invalid_cells": invalid_cells,
        "failed_cells": failed_cells,
    }


def failure_round_trip_findings(
    state: dict[str, Any],
    typed_failure_failures: list[str],
    artifact_versions: dict[str, str],
    default_runner_blocked: bool,
) -> list[dict[str, Any]]:
    findings: list[dict[str, Any]] = []
    if typed_failure_failures:
        findings.append(
            finding(
                "child_failure_round_trip_matrix",
                DEFAULT_EXPECTED_BEHAVIOR["child_failure_round_trip_matrix"],
                artifact_versions,
                default_runner_blocked,
                "; ".join(typed_failure_failures),
            ),
        )

    missing_cells = state.get("missing_cells")
    if isinstance(missing_cells, list) and missing_cells:
        cell_labels = [
            f"{item.get('parent', 'unknown')}->{item.get('child', 'unknown')}"
            for item in missing_cells
            if isinstance(item, dict)
        ]
        findings.append(
            finding(
                "child_failure_round_trip_matrix",
                DEFAULT_EXPECTED_BEHAVIOR["child_failure_round_trip_matrix"],
                artifact_versions,
                False,
                "typed failure evidence did not include required failure round-trip cells: " + ", ".join(cell_labels),
            ),
        )

    invalid_cells = state.get("invalid_cells")
    if isinstance(invalid_cells, list) and invalid_cells:
        details = []
        for item in invalid_cells:
            if not isinstance(item, dict):
                continue
            cell = item.get("cell") if isinstance(item.get("cell"), dict) else {}
            failures = item.get("failures") if isinstance(item.get("failures"), list) else []
            details.append(
                f"{cell.get('parent', 'unknown')}->{cell.get('child', 'unknown')} ({'; '.join(map(str, failures))})",
            )
        findings.append(
            finding(
                "child_failure_round_trip_matrix",
                DEFAULT_EXPECTED_BEHAVIOR["child_failure_round_trip_matrix"],
                artifact_versions,
                False,
                "typed failure evidence cells were missing stable metadata: " + ", ".join(details),
            ),
        )

    failed_cells = state.get("failed_cells")
    if isinstance(failed_cells, list):
        for cell in failed_cells:
            if not isinstance(cell, dict):
                continue
            observed = string_value(cell.get("observed_behavior")) or (
                "a child workflow typed/domain failure was not observed by the parent with stable failure metadata"
            )
            findings.append(
                {
                    "scenario_id": "child_failure_round_trip_matrix",
                    "finding_type": "product_behavior_gap",
                    "owning_surface": string_value(cell.get("owning_surface")) or "workflow_runtime",
                    "artifact_versions": artifact_versions,
                    "expected_behavior": DEFAULT_EXPECTED_BEHAVIOR["child_failure_round_trip_matrix"],
                    "observed_behavior": observed,
                    "user_visible_reproduction_steps": [
                        "Install the exact published artifacts recorded in artifact_versions.",
                        f"Run a {cell.get('parent', 'parent')} parent workflow that starts a {cell.get('child', 'child')} child workflow which throws a typed/domain failure.",
                        "Observe the parent workflow result, parent history, and public CLI/SDK read surfaces for typed failure metadata.",
                    ],
                    "next_acceptance_criterion": (
                        "the parent observes the child failure as a typed/domain failure with exception class, "
                        "message, failure kind, workflow/run identifiers, and history observations"
                    ),
                    "priority": "P1",
                },
            )

    return findings


def artifact_sources_from_install_evidence(evidence: dict[str, Any]) -> dict[str, str]:
    entries = artifact_install_entry_by_name(evidence)
    sources = {
        artifact: entry_source(entries.get(artifact, {})) or "not_exercised"
        for artifact in REQUIRED_INSTALL_ARTIFACTS
    }
    sources["workflow"] = sources["workflow-php"]
    return sources


def scenario_defs(manifest: dict[str, Any]) -> list[dict[str, Any]]:
    scenarios = manifest.get("scenarios")
    if isinstance(scenarios, list) and scenarios:
        return [item for item in scenarios if isinstance(item, dict)]
    return [{"id": item, "expected_behavior": DEFAULT_EXPECTED_BEHAVIOR[item]} for item in FALLBACK_REQUIRED_SCENARIO_IDS]


def required_matrix(manifest: dict[str, Any]) -> dict[str, Any]:
    matrix = manifest.get("required_matrix")
    if isinstance(matrix, dict):
        return matrix
    return {
        "runtimes": ["workflow-php", "sdk-python"],
        "same_language_cells": [
            {"parent": "sdk-python", "child": "sdk-python", "scenario": "python_parent_python_child_baseline"},
            {"parent": "workflow-php", "child": "workflow-php", "scenario": "php_parent_php_child_baseline"},
        ],
        "cross_language_cells": [
            {"parent": "workflow-php", "child": "sdk-python", "scenario": "php_parent_python_child_cross_language"},
            {"parent": "sdk-python", "child": "workflow-php", "scenario": "python_parent_php_child_cross_language"},
        ],
        "failure_round_trip_cells": [
            {"parent": "sdk-python", "child": "sdk-python", "scenario": "child_failure_round_trip_matrix"},
            {"parent": "workflow-php", "child": "workflow-php", "scenario": "child_failure_round_trip_matrix"},
            {"parent": "workflow-php", "child": "sdk-python", "scenario": "child_failure_round_trip_matrix"},
            {"parent": "sdk-python", "child": "workflow-php", "scenario": "child_failure_round_trip_matrix"},
        ],
    }


def finding(
    scenario_id: str,
    expected_behavior: str,
    artifact_versions: dict[str, str],
    runner_blocked: bool,
    reason: str,
) -> dict[str, Any]:
    if runner_blocked:
        observed = f"child-workflows conformance could not execute before product evidence was collected: {reason}"
        next_step = "provide exact published artifact pins and rerun child-workflows conformance"
        priority = "P0"
        finding_type = "runner_blocked"
    else:
        observed = (
            "child-workflows published-artifact evidence did not execute this required scenario; "
            "the result is routed as a coverage gap instead of being counted as passing smoke coverage"
        )
        if reason:
            observed += f": {reason}"
        next_step = (
            "extend the host runner to execute this scenario against published artifacts, "
            "or replace this coverage-gap finding with a focused product finding from the observed runtime mismatch"
        )
        priority = "P1"
        finding_type = "conformance_runner_coverage_gap"

    return {
        "scenario_id": scenario_id,
        "finding_type": finding_type,
        "owning_surface": "conformance_harness",
        "artifact_versions": artifact_versions,
        "expected_behavior": expected_behavior,
        "observed_behavior": observed,
        "user_visible_reproduction_steps": [
            "Set exact DW_SERVER_VERSION, DW_CLI_VERSION, DW_PYTHON_SDK_VERSION, DW_WORKFLOW_PHP_VERSION, and DW_WATERLINE_VERSION values.",
            "Run scripts/conformance/child-workflows-published-artifacts.sh --result-dir <result-dir>.",
            "Inspect child-workflows-result.json for the scenario status and linked finding.",
        ],
        "next_acceptance_criterion": next_step,
        "priority": priority,
    }


def with_cell_status(cells: Any, status: str) -> list[dict[str, Any]]:
    if not isinstance(cells, list):
        return []
    result = []
    for cell in cells:
        if not isinstance(cell, dict):
            continue
        item = dict(cell)
        item["status"] = status
        result.append(item)
    return result


def finding_links_by_scenario(findings: list[dict[str, Any]]) -> dict[str, list[dict[str, Any]]]:
    links: dict[str, list[dict[str, Any]]] = {}
    for item in findings:
        scenario_id = string_value(item.get("scenario_id"))
        if not scenario_id:
            continue
        links.setdefault(scenario_id, []).append(item)
    return links


def main() -> int:
    manifest = load_manifest()
    scenarios = scenario_defs(manifest)
    matrix = required_matrix(manifest)
    suite_version = manifest.get("suite_version")
    if not isinstance(suite_version, int):
        suite_version = None

    server_image = env("DW_SERVER_IMAGE")
    server_version = derive_server_version(server_image, env("DW_SERVER_VERSION"))
    if server_version and not server_image:
        server_image = f"durableworkflow/server:{server_version}"

    workflow_version = env("DW_WORKFLOW_PHP_VERSION")
    artifact_versions = {
        "server": server_version,
        "cli": normalize_cli_version(env("DW_CLI_VERSION")),
        "sdk-python": env("DW_PYTHON_SDK_VERSION"),
        "workflow": workflow_version,
        "workflow-php": workflow_version,
        "waterline": env("DW_WATERLINE_VERSION"),
    }
    published_artifact_versions = {
        "server": artifact_versions["server"],
        "cli": artifact_versions["cli"],
        "sdk-python": artifact_versions["sdk-python"],
        "workflow": artifact_versions["workflow"],
        "waterline": artifact_versions["waterline"],
    }
    install_evidence_path = Path(
        env("DW_CHILD_WORKFLOWS_ARTIFACT_INSTALL_EVIDENCE")
        or str(RESULT_DIR / "artifact-install-evidence.json"),
    )
    raw_install_evidence = load_artifact_install_evidence(install_evidence_path)
    install_evidence_was_supplied = raw_install_evidence is not None
    artifact_install_evidence = normalize_artifact_install_evidence(raw_install_evidence, artifact_versions)
    artifact_sources = artifact_sources_from_install_evidence(artifact_install_evidence)
    pin_failures = exact_version_failures(artifact_versions, server_image)
    install_failures = artifact_install_evidence_failures(
        artifact_install_evidence,
        artifact_versions,
        install_evidence_was_supplied,
    )
    install_evidence_pass = not pin_failures and not install_failures
    typed_failure_evidence_path_text = env("DW_CHILD_WORKFLOWS_TYPED_FAILURE_EVIDENCE")
    typed_failure_evidence_path = Path(typed_failure_evidence_path_text) if typed_failure_evidence_path_text else None
    raw_typed_failure_evidence = load_typed_failure_evidence(typed_failure_evidence_path_text)
    typed_failure_evidence_was_supplied = raw_typed_failure_evidence is not None
    typed_failure_evidence = normalize_typed_failure_evidence(raw_typed_failure_evidence)
    typed_failure_failures = typed_failure_evidence_failures(
        typed_failure_evidence,
        artifact_versions,
        typed_failure_evidence_was_supplied,
        install_evidence_pass,
    )
    runner_blocked = bool(pin_failures)
    blocked_reason = "; ".join(pin_failures)
    non_install_status = "runner_blocked" if runner_blocked else "not_covered"
    finished_at = now()
    failure_round_trip_state = failure_round_trip_evidence_state(
        matrix,
        typed_failure_evidence,
        typed_failure_failures,
        typed_failure_evidence_was_supplied,
        non_install_status,
    )
    failure_round_trip_section = {
        "status": failure_round_trip_state["status"],
        "cells": failure_round_trip_state["cells"],
    }
    if typed_failure_evidence_was_supplied:
        failure_round_trip_section.update(
            {
                "typed_failure_evidence": typed_failure_evidence,
                "typed_failure_evidence_path": str(typed_failure_evidence_path),
                "typed_failure_evidence_failures": typed_failure_failures,
                "missing_failure_round_trip_cells": failure_round_trip_state["missing_cells"],
                "invalid_failure_round_trip_cells": failure_round_trip_state["invalid_cells"],
                "failed_failure_round_trip_cells": failure_round_trip_state["failed_cells"],
            },
        )

    findings: list[dict[str, Any]] = []
    scenario_results: list[dict[str, Any]] = []
    for scenario in scenarios:
        scenario_id = str(scenario.get("id", ""))
        if not scenario_id:
            continue
        expected_behavior = str(
            scenario.get("expected_behavior")
            or DEFAULT_EXPECTED_BEHAVIOR.get(scenario_id)
            or "required child-workflow conformance behavior is observed",
        )
        if scenario_id == "published_artifact_install_only" and install_evidence_pass:
            observed_outputs = {
                "server_image": server_image,
                "cli_release": artifact_versions["cli"],
                "workflow_php_package": f"durable-workflow/workflow:{artifact_versions['workflow']}",
                "sdk_python_package": f"durable-workflow=={artifact_versions['sdk-python']}",
                "waterline_artifact": f"durable-workflow/waterline:{artifact_versions['waterline']}",
                "artifact_sources": artifact_sources,
                "artifact_install_evidence": artifact_install_evidence,
                "artifact_install_evidence_path": str(install_evidence_path),
            }
            scenario_results.append(
                {
                    "scenario_id": scenario_id,
                    "status": "pass",
                    "expected_behavior": expected_behavior,
                    "observed_outputs": observed_outputs,
                },
            )
            continue

        if scenario_id == "child_failure_round_trip_matrix" and typed_failure_evidence_was_supplied:
            scenario_findings = failure_round_trip_findings(
                failure_round_trip_state,
                typed_failure_failures,
                published_artifact_versions,
                runner_blocked,
            )
            findings.extend(scenario_findings)
            if failure_round_trip_state["all_cells_pass"]:
                scenario_results.append(
                    {
                        "scenario_id": scenario_id,
                        "status": "pass",
                        "expected_behavior": expected_behavior,
                        "observed_outputs": {
                            "failure_round_trip": failure_round_trip_section,
                            "typed_failure_evidence": typed_failure_evidence,
                            "typed_failure_evidence_path": str(typed_failure_evidence_path),
                        },
                    },
                )
            else:
                scenario_status = (
                    "fail"
                    if failure_round_trip_state["status"] == "fail"
                    else ("runner_blocked" if runner_blocked else "not_covered")
                )
                scenario_results.append(
                    {
                        "scenario_id": scenario_id,
                        "status": scenario_status,
                        "expected_behavior": expected_behavior,
                        "observed_outputs": {
                            "coverage_status": scenario_status,
                            "failure_round_trip": failure_round_trip_section,
                            "typed_failure_evidence": typed_failure_evidence,
                            "typed_failure_evidence_path": str(typed_failure_evidence_path),
                            "typed_failure_evidence_failures": typed_failure_failures,
                        },
                        "linked_findings": scenario_findings,
                    },
                )
            continue

        scenario_reason = blocked_reason
        if scenario_id == "published_artifact_install_only" and not runner_blocked:
            scenario_reason = "published artifact install evidence did not pass: " + "; ".join(install_failures)

        scenario_finding = finding(
            scenario_id,
            expected_behavior,
            published_artifact_versions,
            runner_blocked,
            scenario_reason,
        )
        findings.append(scenario_finding)
        scenario_results.append(
            {
                "scenario_id": scenario_id,
                "status": "runner_blocked" if runner_blocked else "not_covered",
                "expected_behavior": expected_behavior,
                "observed_outputs": {
                    "coverage_status": "runner_blocked" if runner_blocked else "not_covered",
                    "observed_behavior": scenario_finding["observed_behavior"],
                    "next_acceptance_criterion": scenario_finding["next_acceptance_criterion"],
                    **(
                        {
                            "artifact_install_evidence": artifact_install_evidence,
                            "artifact_install_evidence_path": str(install_evidence_path),
                            "artifact_install_failures": install_failures,
                        }
                        if scenario_id == "published_artifact_install_only"
                        else {}
                    ),
                },
                "linked_findings": [scenario_finding],
            },
        )

    runtime_matrix = {
        "runtimes": list(matrix.get("runtimes", ["workflow-php", "sdk-python"])),
        "same_language_cells": with_cell_status(matrix.get("same_language_cells"), non_install_status),
        "cross_language_cells": with_cell_status(matrix.get("cross_language_cells"), non_install_status),
        "failure_round_trip_cells": failure_round_trip_state["cells"],
    }

    published_artifact_install = {
        "server_image": server_image,
        "cli_release": artifact_versions["cli"],
        "workflow_php_package": (
            f"durable-workflow/workflow:{artifact_versions['workflow']}"
            if artifact_versions["workflow"]
            else ""
        ),
        "sdk_python_package": (
            f"durable-workflow=={artifact_versions['sdk-python']}"
            if artifact_versions["sdk-python"]
            else ""
        ),
        "waterline_artifact": (
            f"durable-workflow/waterline:{artifact_versions['waterline']}"
            if artifact_versions["waterline"]
            else ""
        ),
        "artifact_sources": artifact_sources,
        "artifact_install_evidence": artifact_install_evidence,
        "artifact_install_evidence_path": str(install_evidence_path),
        "pin_failures": pin_failures,
        "install_failures": install_failures,
    }

    result = {
        "schema": "durable-workflow.v2.child-workflow-runtime.result",
        "schema_version": 1,
        "suite_schema": "durable-workflow.v2.platform-conformance.suite",
        "suite_version": suite_version,
        "category": "child_workflow_runtime_contract",
        "outcome": "non_passing_runner_blocked" if runner_blocked else "non_passing",
        "runner_blocked": runner_blocked,
        "started_at": STARTED_AT,
        "finished_at": finished_at,
        "generated_at": finished_at,
        "artifact_versions": artifact_versions,
        "published_artifact_versions": published_artifact_versions,
        "artifact_sources": artifact_sources,
        "artifact_install_evidence": artifact_install_evidence,
        "published_artifact_install": published_artifact_install,
        "runtime_matrix": runtime_matrix,
        "topology": {
            "task_queue": "cw-shared",
            "required_workers": ["workflow-php", "sdk-python"],
            "workflow_types": {
                "workflow-php": {"parent": "PhpParent", "child": "PhpChild"},
                "sdk-python": {"parent": "PythonParent", "child": "PythonChild"},
            },
        },
        "failure_round_trip": failure_round_trip_section,
        "cancellation_propagation": {
            "parent_to_child": {"status": non_install_status},
            "direct_child": {"status": non_install_status},
        },
        "replay_restart": {"status": non_install_status},
        "fan_out": {
            "status": non_install_status,
            "required_child_count": 5,
        },
        "namespace_behavior": {"status": non_install_status},
        "scenario_results": scenario_results,
        "findings": findings,
        "finding_links": finding_links_by_scenario(findings),
    }

    metadata = {
        "started_at": STARTED_AT,
        "finished_at": finished_at,
        "generated_at": finished_at,
        "artifact_versions": artifact_versions,
        "published_artifact_versions": published_artifact_versions,
        "artifact_sources": artifact_sources,
        "artifact_install_evidence_path": str(install_evidence_path),
        "artifact_install_evidence_supplied": install_evidence_was_supplied,
        "typed_failure_evidence_path": str(typed_failure_evidence_path) if typed_failure_evidence_path else "",
        "typed_failure_evidence_supplied": typed_failure_evidence_was_supplied,
        "scenario_manifest": str(MANIFEST_PATH),
    }

    record = {
        "experiment": "child-workflows",
        "outcome": "error" if runner_blocked else "fail",
        "runnerBlocked": runner_blocked,
        "artifactVersions": published_artifact_versions,
        "findings": [
            f"{item['scenario_id']}: {item['observed_behavior']}"
            for item in findings
        ],
        "resultPath": str(RESULT_DIR / "child-workflows-result.json"),
    }

    RESULT_DIR.mkdir(parents=True, exist_ok=True)
    (RESULT_DIR / "pins.json").write_text(json.dumps(artifact_versions, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (RESULT_DIR / "run-metadata.json").write_text(json.dumps(metadata, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (RESULT_DIR / "child-workflows-result.json").write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (RESULT_DIR / "child-workflows-record.json").write_text(json.dumps(record, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    print(json.dumps(result, indent=2, sort_keys=True))

    return 1


if __name__ == "__main__":
    raise SystemExit(main())
PY
