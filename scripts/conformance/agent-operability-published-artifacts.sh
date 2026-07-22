#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
  cat <<'USAGE'
Usage: agent-operability-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]

Writes a published-artifact agent-operability executable-loop result.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  agent-operability-result.json
  agent-operability-record.json
  agent-operability-findings.json

Environment overrides:
  DW_SERVER_VERSION                              Published server version under test.
  DW_SERVER_IMAGE                                Optional server image; exact tags are parsed as server pins.
  DW_CLI_VERSION                                 Published CLI version under test.
  DW_PYTHON_SDK_VERSION                          Published Python SDK version under test.
  DW_WORKFLOW_PHP_VERSION                        Published PHP workflow version under test.
  DW_WATERLINE_VERSION                           Published Waterline version under test.
  DW_SAMPLE_APP_REF                              Public sample-app ref or commit under test.
  DW_AGENT_OPERABILITY_RESULT_DIR                Result directory when --result-dir is omitted.
  DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA       JSON metadata from sample-app app:conformance, or a path to it.
  DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA_PATH  Path to sample-app app:conformance metadata.
  DW_AGENT_OPERABILITY_MCP_URL                   Live public /mcp/workflows endpoint to exercise directly.
  DW_AGENT_OPERABILITY_WORKFLOW                  Failure workflow key. Defaults to diagnostic_failure.
USAGE
}

result_dir="${DW_AGENT_OPERABILITY_RESULT_DIR:-}"

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
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-agent-operability.XXXXXX")"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"

timestamp() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

started_at="$(timestamp)"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"

RESULT_DIR="$result_dir" \
STARTED_AT="$started_at" \
REPO_ROOT="$repo_root" \
DW_SERVER_VERSION="${DW_SERVER_VERSION:-}" \
DW_SERVER_IMAGE="${DW_SERVER_IMAGE:-}" \
DW_CLI_VERSION="${DW_CLI_VERSION:-}" \
DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-}" \
DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-}" \
DW_WATERLINE_VERSION="${DW_WATERLINE_VERSION:-}" \
DW_SAMPLE_APP_REF="${DW_SAMPLE_APP_REF:-}" \
DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA="${DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA:-}" \
DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA_PATH="${DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA_PATH:-}" \
DW_AGENT_OPERABILITY_MCP_URL="${DW_AGENT_OPERABILITY_MCP_URL:-}" \
DW_AGENT_OPERABILITY_WORKFLOW="${DW_AGENT_OPERABILITY_WORKFLOW:-diagnostic_failure}" \
python3 - <<'PY'
from __future__ import annotations

import json
import os
import re
import time
import urllib.error
import urllib.request
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


RESULT_SCHEMA = "durable-workflow.v2.agent-operability.executable-loop.result"
EVIDENCE_SCHEMA = "durable-workflow.v2.agent-operability.executable-loop"
RECORD_SCHEMA = "durable-workflow.v2.agent-operability.executable-loop.record"
REQUIRED_ARTIFACTS = ["server", "cli", "sdk-python", "workflow", "waterline", "sample-app"]
REQUIRED_TOOLS = [
    "list_workflows",
    "start_workflow",
    "get_workflow_result",
    "get_workflow_history",
    "diagnose_workflow",
    "repair_workflow",
]
FAILURE_ROOT_CAUSES = {"activity_failure", "workflow_failure"}
PLACEHOLDER_VERSION_PATTERN = re.compile(
    r"<[^>]+>|\$\{[^}]+}|{{[^}]+}}|(^|[^a-z0-9])(latest|current|head|main|master|unresolved|placeholder)([^a-z0-9]|$)",
    re.IGNORECASE,
)
EXACT_SEMVER_RELEASE_PATTERN = re.compile(
    r"^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
    r"(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)"
    r"(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$",
)


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def env_text(name: str) -> str | None:
    value = os.environ.get(name)
    if value is None:
        return None
    value = value.strip()
    return value or None


def write_json(path: Path, value: Any) -> None:
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def server_version() -> str:
    explicit = env_text("DW_SERVER_VERSION")
    if explicit:
        return explicit
    image = env_text("DW_SERVER_IMAGE")
    if image and ":" in image and "@" not in image:
        return image.rsplit(":", 1)[1]
    return "unresolved"


def artifact_versions() -> dict[str, str]:
    return {
        "server": server_version(),
        "cli": env_text("DW_CLI_VERSION") or "unresolved",
        "sdk-python": env_text("DW_PYTHON_SDK_VERSION") or "unresolved",
        "workflow": env_text("DW_WORKFLOW_PHP_VERSION") or "unresolved",
        "waterline": env_text("DW_WATERLINE_VERSION") or "unresolved",
        "sample-app": env_text("DW_SAMPLE_APP_REF") or "unresolved",
    }


def artifact_version_failures(versions: dict[str, str]) -> list[str]:
    failures: list[str] = []
    for artifact in REQUIRED_ARTIFACTS:
        value = versions.get(artifact)
        if not isinstance(value, str) or not value.strip():
            failures.append(f"{artifact}=missing")
            continue
        if PLACEHOLDER_VERSION_PATTERN.search(value.strip()):
            failures.append(f"{artifact}={value.strip()}")
        elif artifact == "server" and not EXACT_SEMVER_RELEASE_PATTERN.fullmatch(value.strip()):
            failures.append(f"{artifact}={value.strip()}")
    return failures


def explicit_false(value: Any) -> bool:
    if value is False:
        return True
    if isinstance(value, str):
        return value.strip().lower() in {"false", "0", "no"}
    return False


def truthy_flag(value: Any) -> bool:
    if value is True:
        return True
    if isinstance(value, str):
        return value.strip().lower() in {"true", "1", "yes"}
    return False


def nested_dict(value: Any, key: str) -> dict[str, Any]:
    if isinstance(value, dict) and isinstance(value.get(key), dict):
        return value[key]
    return {}


def artifact_map_value(values: dict[str, Any], artifact: str) -> str | None:
    aliases = {
        "sdk-python": ["sdk-python", "sdk_python", "python-sdk", "python_sdk"],
        "workflow": ["workflow", "workflow-php", "workflow_php"],
        "sample-app": ["sample-app", "sample_app"],
    }
    for key in aliases.get(artifact, [artifact]):
        value = values.get(key)
        if value is None:
            continue
        if isinstance(value, str):
            return value.strip() or None
        return str(value).strip() or None
    return None


def artifact_version_map_candidates(
    evidence: dict[str, Any] | None,
    metadata: dict[str, Any] | None,
) -> list[tuple[str, dict[str, Any]]]:
    candidates: list[tuple[str, dict[str, Any]]] = []
    seen: set[str] = set()

    def add(label: str, container: dict[str, Any] | None) -> None:
        if not isinstance(container, dict):
            return
        for key in ("artifactVersions", "artifact_versions", "published_artifact_versions", "resolved_artifact_versions"):
            value = container.get(key)
            if not isinstance(value, dict):
                continue
            fingerprint = json.dumps(value, sort_keys=True, separators=(",", ":"), default=str)
            if fingerprint in seen:
                continue
            seen.add(fingerprint)
            candidates.append((f"{label}.{key}", value))

    add("metadata", metadata)
    add("evidence", evidence)
    add("evidence.sample_app_metadata", nested_dict(evidence, "sample_app_metadata") if evidence else None)

    return candidates


def artifact_tuple_policy_failures(
    expected_versions: dict[str, str],
    candidates: list[tuple[str, dict[str, Any]]],
) -> list[str]:
    failures: list[str] = []
    for label, values in candidates:
        for artifact in REQUIRED_ARTIFACTS:
            expected = artifact_map_value(expected_versions, artifact)
            observed = artifact_map_value(values, artifact)
            if observed is None:
                failures.append(f"{label}.{artifact}=missing")
            elif expected is None or observed != expected:
                failures.append(f"{label}.{artifact} expected {expected or 'missing'} but observed {observed}")
    return failures


def read_metadata() -> dict[str, Any] | None:
    configured_path = env_text("DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA_PATH")
    configured_value = env_text("DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA")

    if configured_path:
        return json.loads(Path(configured_path).read_text(encoding="utf-8"))

    if configured_value:
        if not configured_value.lstrip().startswith(("{", "[")):
            maybe_path = Path(configured_value)
            if maybe_path.exists():
                return json.loads(maybe_path.read_text(encoding="utf-8"))
        return json.loads(configured_value)

    return None


def response_json(body: str) -> dict[str, Any]:
    stripped = body.strip()
    if not stripped:
        return {}
    if stripped.startswith("event:") or "\ndata:" in stripped or stripped.startswith("data:"):
        data_lines = []
        for line in stripped.splitlines():
            if line.startswith("data:"):
                data_lines.append(line[5:].strip())
        if data_lines:
            return json.loads("\n".join(data_lines))
    return json.loads(stripped)


def post_json(url: str, payload: dict[str, Any], session_id: str | None = None) -> dict[str, Any]:
    headers = {
        "Accept": "application/json, text/event-stream",
        "Content-Type": "application/json",
    }
    if session_id:
        headers["Mcp-Session-Id"] = session_id

    request = urllib.request.Request(
        url,
        data=json.dumps(payload).encode("utf-8"),
        headers=headers,
        method="POST",
    )

    try:
        with urllib.request.urlopen(request, timeout=30) as response:
            raw = response.read().decode("utf-8", errors="replace")
            return {
                "status": response.status,
                "headers": {key.lower(): value for key, value in response.headers.items()},
                "body": raw,
                "json": response_json(raw),
            }
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        try:
            parsed = response_json(raw)
        except Exception:
            parsed = {"raw": raw}
        return {
            "status": exc.code,
            "headers": {key.lower(): value for key, value in exc.headers.items()},
            "body": raw,
            "json": parsed,
        }


def structured(payload: dict[str, Any]) -> dict[str, Any]:
    body = payload.get("json")
    if not isinstance(body, dict):
        return {}
    result = body.get("result")
    if not isinstance(result, dict):
        return {}
    content = result.get("structuredContent")
    if isinstance(content, dict):
        return content
    for item in result.get("content", []):
        if not isinstance(item, dict) or not isinstance(item.get("text"), str):
            continue
        try:
            decoded = json.loads(item["text"])
        except json.JSONDecodeError:
            continue
        if isinstance(decoded, dict):
            return decoded
    return result


def tool_names(payload: dict[str, Any]) -> list[str]:
    body = payload.get("json")
    if not isinstance(body, dict):
        return []
    result = body.get("result")
    if not isinstance(result, dict):
        return []
    tools = result.get("tools")
    if not isinstance(tools, list):
        return []
    return [tool["name"] for tool in tools if isinstance(tool, dict) and isinstance(tool.get("name"), str)]


def workflow_keys(workflows: list[Any]) -> list[str]:
    return [workflow["key"] for workflow in workflows if isinstance(workflow, dict) and isinstance(workflow.get("key"), str)]


def workflow_definition(workflows: list[Any], key: str) -> dict[str, Any]:
    for workflow in workflows:
        if isinstance(workflow, dict) and workflow.get("key") == key:
            return workflow
    return {}


def history_has(content: dict[str, Any], *event_types: str) -> bool:
    events = content.get("events")
    if not isinstance(events, list):
        return False
    expected = set(event_types)
    return any(isinstance(event, dict) and event.get("event_type") in expected for event in events)


def next_action_codes(content: dict[str, Any]) -> list[str]:
    actions = content.get("next_actions")
    if not isinstance(actions, list):
        return []
    return [action["code"] for action in actions if isinstance(action, dict) and isinstance(action.get("code"), str)]


def drive_mcp(url: str, failure_workflow: str) -> dict[str, Any]:
    workflow_id = f"agent-operability-{int(time.time())}"
    initialize = post_json(
        url,
        {
            "jsonrpc": "2.0",
            "id": "agent-operability-initialize",
            "method": "initialize",
            "params": {
                "protocolVersion": "2024-11-05",
                "capabilities": {},
                "clientInfo": {"name": "agent-operability-conformance", "version": "1"},
            },
        },
    )
    session = initialize["headers"].get("mcp-session-id")
    post_json(url, {"jsonrpc": "2.0", "method": "notifications/initialized", "params": {}}, session)
    tools = post_json(url, {"jsonrpc": "2.0", "id": "agent-operability-tools", "method": "tools/list", "params": {}}, session)

    def call(name: str, arguments: dict[str, Any]) -> dict[str, Any]:
        return post_json(
            url,
            {
                "jsonrpc": "2.0",
                "id": f"agent-operability-{name}",
                "method": "tools/call",
                "params": {"name": name, "arguments": arguments},
            },
            session,
        )

    workflows = call("list_workflows", {"show_recent": True, "limit": 5})
    start = call(
        "start_workflow",
        {
            "workflow": failure_workflow,
            "instance_id": workflow_id,
            "business_key": "agent-operability-failure",
            "arguments": ["agent-operability-induced-failure"],
        },
    )
    result = {}
    failed = False
    for _ in range(30):
        result = call(
            "get_workflow_result",
            {"workflow_id": workflow_id, "include_recent_history": True, "history_limit": 10},
        )
        result_content = structured(result)
        if result_content.get("status") == "failed":
            failed = True
            break
        time.sleep(1)
    diagnosis = call("diagnose_workflow", {"workflow_id": workflow_id, "history_limit": 5})
    repair = call("repair_workflow", {"workflow_id": workflow_id})
    history = call("get_workflow_history", {"workflow_id": workflow_id, "limit": 25})

    workflow_content = structured(workflows)
    available = workflow_content.get("available_workflows")
    if not isinstance(available, list):
        available = []
    result_content = structured(result)
    diagnosis_content = structured(diagnosis)
    repair_content = structured(repair)
    history_content = structured(history)
    definition = workflow_definition(available, failure_workflow)

    return {
        "schema": EVIDENCE_SCHEMA,
        "version": 1,
        "source": "mcp-json-rpc",
        "endpoint": url,
        "local_product_source_checkouts_used": False,
        "discovery": {
            "tool_names": tool_names(tools),
            "available_workflow_keys": workflow_keys(available),
            "selected_failure_workflow": failure_workflow,
            "failure_workflow_requires": definition.get("requires") if isinstance(definition.get("requires"), list) else None,
            "failure_workflow_no_credentials": definition.get("requires") == [],
            "workflow_id_kind": workflow_content.get("workflow_id_kind"),
            "run_id_kind": workflow_content.get("run_id_kind"),
        },
        "change": {
            "kind": "guarded_operating_choice",
            "failure_workflow": failure_workflow,
            "business_key": "agent-operability-failure",
            "failure_arguments": ["agent-operability-induced-failure"],
        },
        "run": {
            "failure": {
                "workflow_id": workflow_id,
                "run_id": structured(start).get("run_id"),
                "start_status": start.get("status"),
                "result_status": result.get("status"),
                "workflow_status": result_content.get("status"),
                "failed": failed,
                "latest_failure_present": result_content.get("error") is not None,
            }
        },
        "diagnose": {
            "failure": {
                "status": diagnosis.get("status"),
                "diagnosis": diagnosis_content.get("diagnosis"),
                "root_cause_schema": ((diagnosis_content.get("root_cause") or {}).get("schema") if isinstance(diagnosis_content.get("root_cause"), dict) else None),
                "root_cause_category": ((diagnosis_content.get("root_cause") or {}).get("category") if isinstance(diagnosis_content.get("root_cause"), dict) else None),
                "root_cause_actionable": ((diagnosis_content.get("root_cause") or {}).get("actionable") if isinstance(diagnosis_content.get("root_cause"), dict) else None),
                "latest_failure_category": ((diagnosis_content.get("latest_failure") or {}).get("category") if isinstance(diagnosis_content.get("latest_failure"), dict) else None),
                "remediation_schema": ((diagnosis_content.get("remediation") or {}).get("schema") if isinstance(diagnosis_content.get("remediation"), dict) else None),
                "remediation_classification": ((diagnosis_content.get("remediation") or {}).get("classification") if isinstance(diagnosis_content.get("remediation"), dict) else None),
                "remediation_summary": ((diagnosis_content.get("remediation") or {}).get("summary") if isinstance(diagnosis_content.get("remediation"), dict) else None),
                "automatic_repair_allowed": (((diagnosis_content.get("remediation") or {}).get("automatic_repair") or {}).get("allowed") if isinstance((diagnosis_content.get("remediation") or {}).get("automatic_repair"), dict) else None),
                "next_action_codes": next_action_codes(diagnosis_content),
            }
        },
        "repair": {
            "failure": {
                "status": repair.get("status"),
                "decision": "request_safe_repair_after_diagnosis",
                "accepted": repair_content.get("accepted"),
                "safe_mutation_schema": ((repair_content.get("mutation") or {}).get("schema") if isinstance(repair_content.get("mutation"), dict) else None),
                "safe_mutation_applied": ((repair_content.get("mutation") or {}).get("applied") if isinstance(repair_content.get("mutation"), dict) else None),
                "safe_mutation_reason": ((repair_content.get("mutation") or {}).get("reason") if isinstance(repair_content.get("mutation"), dict) else None),
                "command_outcome": ((repair_content.get("command") or {}).get("outcome") if isinstance(repair_content.get("command"), dict) else None),
                "remediation_schema": ((repair_content.get("remediation") or {}).get("schema") if isinstance(repair_content.get("remediation"), dict) else None),
                "remediation_classification": ((repair_content.get("remediation") or {}).get("classification") if isinstance(repair_content.get("remediation"), dict) else None),
                "next_action_codes": next_action_codes(repair_content),
            }
        },
        "history": {
            "failure": {
                "status": history.get("status"),
                "event_count": history_content.get("history_event_count"),
                "returned_event_count": history_content.get("returned_event_count"),
                "failed_event_seen": history_has(history_content, "WorkflowFailed", "ActivityFailed"),
            }
        },
    }


def evidence_from_metadata(metadata: dict[str, Any]) -> dict[str, Any] | None:
    surfaces = metadata.get("surfaces")
    if not isinstance(surfaces, dict):
        return None
    mcp = surfaces.get("mcp_workflow_api")
    if not isinstance(mcp, dict):
        return None
    evidence = mcp.get("agent_loop_evidence")
    if not isinstance(evidence, dict):
        return None
    evidence = dict(evidence)
    evidence.setdefault("schema", EVIDENCE_SCHEMA)
    evidence.setdefault("version", 1)
    evidence["source"] = "sample-app-conformance-metadata"
    evidence["sample_app_metadata"] = {
        "schema": metadata.get("schema"),
        "app_url": metadata.get("app_url"),
        "status": ((metadata.get("summary") or {}).get("status") if isinstance(metadata.get("summary"), dict) else None),
        "artifactVersions": metadata.get("artifactVersions"),
        "local_product_source_checkouts_used": metadata.get("local_product_source_checkouts_used"),
    }
    if "local_product_source_checkouts_used" in metadata:
        evidence.setdefault("local_product_source_checkouts_used", metadata.get("local_product_source_checkouts_used"))
    return evidence


def stage(status: str, summary: str, evidence: dict[str, Any] | None = None) -> dict[str, Any]:
    return {"status": status, "summary": summary, "evidence": evidence or {}}


def add_policy_failure(
    scenarios: dict[str, Any],
    findings: list[dict[str, Any]],
    stage_name: str,
    summary: str,
    expected: str,
    observed: Any,
) -> None:
    scenarios[stage_name] = stage("fail", summary, {"observed": observed})
    findings.append({
        "finding_type": "published_artifact_policy_gap",
        "owner": "conformance_harness",
        "stage": stage_name,
        "summary": summary,
        "expected_behavior": expected,
        "observed_behavior": observed,
        "next_acceptance_criterion": f"record passing published-artifact policy evidence for {stage_name}",
    })


def validate_published_artifact_policy(
    versions: dict[str, str],
    evidence: dict[str, Any] | None,
    metadata: dict[str, Any] | None,
    source: str | None,
) -> tuple[dict[str, Any], list[dict[str, Any]]]:
    scenarios: dict[str, Any] = {}
    findings: list[dict[str, Any]] = []

    version_failures = artifact_version_failures(versions)
    if version_failures:
        add_policy_failure(
            scenarios,
            findings,
            "artifact_versions",
            "Published artifact pins are missing or unresolved.",
            "server, cli, sdk-python, workflow, waterline, and sample-app have concrete published artifact versions or refs",
            {"artifact_versions": versions, "failures": version_failures},
        )
    else:
        scenarios["artifact_versions"] = stage(
            "pass",
            "All required published artifact pins are concrete.",
            {"artifact_versions": versions},
        )

    sample_metadata = nested_dict(evidence, "sample_app_metadata") if evidence else {}
    metadata_summary = nested_dict(metadata, "summary") if metadata else {}
    source_free_values = [
        evidence.get("local_product_source_checkouts_used") if evidence else None,
        sample_metadata.get("local_product_source_checkouts_used"),
        metadata.get("local_product_source_checkouts_used") if metadata else None,
        metadata_summary.get("local_product_source_checkouts_used"),
        nested_dict(metadata, "artifact_install_evidence").get("local_product_source_checkouts_used") if metadata else None,
    ]
    truthy_values = [value for value in source_free_values if truthy_flag(value)]
    explicit_false_present = any(explicit_false(value) for value in source_free_values)

    if truthy_values or not explicit_false_present:
        add_policy_failure(
            scenarios,
            findings,
            "sample_app_source_free",
            "Source-free sample-app evidence is missing or reports local product source checkout use.",
            "sample-app executable-loop evidence explicitly records local_product_source_checkouts_used=false",
            {
                "evidence_source": source,
                "local_product_source_checkouts_used_values": source_free_values,
                "truthy_values": truthy_values,
            },
        )
    else:
        scenarios["sample_app_source_free"] = stage(
            "pass",
            "Executable-loop evidence explicitly records local_product_source_checkouts_used=false.",
            {
                "evidence_source": source,
                "local_product_source_checkouts_used": False,
            },
        )

    artifact_tuple_candidates = artifact_version_map_candidates(evidence, metadata)
    tuple_failures = artifact_tuple_policy_failures(versions, artifact_tuple_candidates)
    if not artifact_tuple_candidates:
        add_policy_failure(
            scenarios,
            findings,
            "sample_app_artifact_tuple",
            "Sample-app executable-loop evidence is missing artifactVersions.",
            "sample-app metadata or evidence records artifactVersions for the same published artifact tuple as the runner pins",
            {
                "evidence_source": source,
                "artifact_versions": versions,
            },
        )
    elif tuple_failures:
        add_policy_failure(
            scenarios,
            findings,
            "sample_app_artifact_tuple",
            "Sample-app executable-loop evidence artifact versions do not match the current published artifact pins.",
            "sample-app metadata and evidence artifactVersions exactly match the pinned published artifact tuple",
            {
                "evidence_source": source,
                "artifact_versions": versions,
                "validated_sources": [label for label, _ in artifact_tuple_candidates],
                "failures": tuple_failures,
            },
        )
    else:
        scenarios["sample_app_artifact_tuple"] = stage(
            "pass",
            "Sample-app executable-loop evidence artifact versions match the current published artifact pins.",
            {
                "evidence_source": source,
                "artifact_versions": versions,
                "validated_sources": [label for label, _ in artifact_tuple_candidates],
            },
        )

    return scenarios, findings


def validate_evidence(evidence: dict[str, Any] | None, failure_workflow: str) -> tuple[dict[str, Any], list[dict[str, Any]]]:
    scenarios: dict[str, Any] = {}
    findings: list[dict[str, Any]] = []

    def fail(stage_name: str, summary: str, expected: str, observed: Any) -> None:
        scenarios[stage_name] = stage("fail", summary, {"observed": observed})
        findings.append({
            "finding_type": "product_behavior_gap" if evidence else "conformance_runner_coverage_gap",
            "owner": "sample-app" if evidence else "conformance_harness",
            "stage": stage_name,
            "summary": summary,
            "expected_behavior": expected,
            "observed_behavior": observed,
            "next_acceptance_criterion": f"record passing executable-loop evidence for {stage_name}",
        })

    if not evidence:
        fail(
            "evidence_source",
            "No sample-app conformance metadata or live MCP endpoint was provided.",
            "runner receives public sample-app metadata or a live /mcp/workflows endpoint",
            "missing DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA_PATH, DW_AGENT_OPERABILITY_SAMPLE_APP_METADATA, and DW_AGENT_OPERABILITY_MCP_URL",
        )
        return scenarios, findings

    discovery = evidence.get("discovery") if isinstance(evidence.get("discovery"), dict) else {}
    tools = set(discovery.get("tool_names") if isinstance(discovery.get("tool_names"), list) else [])
    workflows = set(discovery.get("available_workflow_keys") if isinstance(discovery.get("available_workflow_keys"), list) else [])
    missing_tools = sorted(set(REQUIRED_TOOLS) - tools)
    if missing_tools or failure_workflow not in workflows or discovery.get("failure_workflow_no_credentials") is not True:
        fail(
            "discover",
            "Discovery did not expose the full MCP tool set and no-credential failure workflow.",
            "tools/list exposes all workflow tools and list_workflows exposes diagnostic_failure without credentials",
            {
                "missing_tools": missing_tools,
                "failure_workflow_present": failure_workflow in workflows,
                "failure_workflow_no_credentials": discovery.get("failure_workflow_no_credentials"),
            },
        )
    else:
        scenarios["discover"] = stage("pass", "MCP discovery exposed workflow tools and the no-credential diagnostic failure workflow.", discovery)

    change = evidence.get("change") if isinstance(evidence.get("change"), dict) else {}
    if change.get("failure_workflow") != failure_workflow or not change.get("failure_arguments"):
        fail(
            "change",
            "The guarded change/choice stage did not select the diagnostic failure workflow with explicit inputs.",
            "evidence records the selected failure workflow and induced-failure arguments",
            change,
        )
    else:
        scenarios["change"] = stage("pass", "The agent selected an explicit no-credential diagnostic failure workflow and arguments.", change)

    run = (evidence.get("run") or {}).get("failure") if isinstance(evidence.get("run"), dict) else {}
    if not isinstance(run, dict) or run.get("failed") is not True or run.get("workflow_status") != "failed":
        fail(
            "run",
            "The workflow execution stage did not observe the induced failure reaching failed status.",
            "start_workflow followed by get_workflow_result records workflow_status=failed",
            run,
        )
    else:
        scenarios["run"] = stage("pass", "The induced failure workflow ran and reached failed status.", run)

    diagnose = (evidence.get("diagnose") or {}).get("failure") if isinstance(evidence.get("diagnose"), dict) else {}
    if (
        not isinstance(diagnose, dict)
        or diagnose.get("root_cause_schema") != "durable-workflow.v2.agent-root-cause"
        or diagnose.get("root_cause_category") not in FAILURE_ROOT_CAUSES
        or diagnose.get("remediation_schema") != "durable-workflow.v2.agent-remediation"
        or not diagnose.get("remediation_classification")
    ):
        fail(
            "diagnose",
            "The diagnostic stage did not record structured root-cause and remediation evidence for the induced failure.",
            "diagnose_workflow returns agent-root-cause and agent-remediation objects with a failure category",
            diagnose,
        )
    else:
        scenarios["diagnose"] = stage("pass", "The induced failure produced structured root-cause and remediation evidence.", diagnose)

    repair = (evidence.get("repair") or {}).get("failure") if isinstance(evidence.get("repair"), dict) else {}
    if (
        not isinstance(repair, dict)
        or repair.get("safe_mutation_schema") != "durable-workflow.v2.safe-mutation"
        or not repair.get("decision")
        or not repair.get("remediation_classification")
    ):
        fail(
            "repair",
            "The repair stage did not record a safe-mutation envelope and decision.",
            "repair_workflow returns safe-mutation evidence plus a repair decision or refusal",
            repair,
        )
    else:
        scenarios["repair"] = stage("pass", "The repair path returned a safe-mutation envelope and explicit decision.", repair)

    return scenarios, findings


def main() -> int:
    result_dir = Path(os.environ["RESULT_DIR"])
    started_at = os.environ["STARTED_AT"]
    failure_workflow = env_text("DW_AGENT_OPERABILITY_WORKFLOW") or "diagnostic_failure"
    versions = artifact_versions()
    write_json(result_dir / "pins.json", {
        "schema": "durable-workflow.v2.agent-operability.pins",
        "artifact_versions": versions,
        "workflow": failure_workflow,
    })

    metadata = read_metadata()
    evidence = evidence_from_metadata(metadata) if metadata is not None else None
    source = "sample-app-metadata" if evidence else None
    mcp_url = env_text("DW_AGENT_OPERABILITY_MCP_URL")

    if evidence is None and mcp_url:
        evidence = drive_mcp(mcp_url, failure_workflow)
        source = "live-mcp"

    scenario_results, findings = validate_evidence(evidence, failure_workflow)
    policy_results, policy_findings = validate_published_artifact_policy(versions, evidence, metadata, source)
    scenario_results = {**policy_results, **scenario_results}
    findings = [*policy_findings, *findings]
    outcome = "pass" if findings == [] and set(["discover", "change", "run", "diagnose", "repair"]).issubset(scenario_results) else "fail"
    finished_at = now()
    result = {
        "schema": RESULT_SCHEMA,
        "version": 1,
        "generated_at": finished_at,
        "started_at": started_at,
        "finished_at": finished_at,
        "outcome": outcome,
        "runner_blocked": False,
        "artifact_versions": versions,
        "evidence_source": source,
        "evidence": evidence,
        "scenario_results": scenario_results,
        "findings": findings,
    }

    result_path = result_dir / "agent-operability-result.json"
    write_json(result_path, result)
    write_json(result_dir / "agent-operability-findings.json", findings)
    write_json(result_dir / "run-metadata.json", {
        "schema": "durable-workflow.v2.agent-operability.runner-metadata",
        "started_at": started_at,
        "finished_at": finished_at,
        "result_path": str(result_path),
        "runner_repository": "server",
        "runner_path": "scripts/conformance/agent-operability-published-artifacts.sh",
        "local_product_source_checkouts_used": False,
    })

    record = {
        "schema": RECORD_SCHEMA,
        "experiment": "agent-operability",
        "outcome": outcome,
        "runnerBlocked": False,
        "artifactVersions": versions,
        "resultPath": str(result_path),
        "findings": [finding["summary"] for finding in findings] or [
            "Executable agent loop verified Discover -> Change -> Run -> Diagnose -> Repair against public MCP/sample-app evidence."
        ],
        "notes": (
            f"result_schema={RESULT_SCHEMA}; evidence_source={source}; "
            "machine_readable_fields=discovery,change,run,diagnose,repair"
        ),
    }
    write_json(result_dir / "agent-operability-record.json", record)
    print(json.dumps(result, indent=2, sort_keys=True))

    return 0 if outcome == "pass" else 1


if __name__ == "__main__":
    raise SystemExit(main())
PY
