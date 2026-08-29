#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: sagas-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Runs the public saga runtime contract against published artifacts only.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  sagas-result.json
  sagas-record.json

Environment overrides:
  DW_SAGAS_RUN_ROOT             Scratch directory. Defaults to mktemp.
  DW_SAGAS_RESULT_DIR           Result directory. Defaults to run root.
  DW_SAGAS_KEEP_RUN_ROOT=1      Keep scratch directory after success.
  DW_SERVER_IMAGE               Exact server image/tag/digest to test.
  DW_SERVER_VERSION             Exact server SemVer tag; required for digest-only DW_SERVER_IMAGE.
  DW_PHP_SDK_VERSION            Composer version for durable-workflow/sdk.
  DW_WORKFLOW_PHP_VERSION       Composer version for durable-workflow/workflow.
  DW_PYTHON_SDK_VERSION         PyPI version for durable-workflow.
  DW_CLI_VERSION                GitHub release tag for the official CLI installer.
  DW_WATERLINE_VERSION          Composer version for durable-workflow/waterline.
  DW_SAGAS_SKIP_DOCKER_PULL=1   Reuse local image instead of pulling.
  DW_SAGAS_SERVER_PORT          Host port for the published server. Defaults to a free port.
  DW_SAGAS_SERVER_BIND_HOST     Docker host interface for the server port. Defaults to 0.0.0.0.
  DW_SAGAS_SERVER_CONNECT_HOST  First host/address to probe. Defaults to 127.0.0.1.
  DW_SAGAS_SERVER_URL           Exact server URL to use; disables automatic endpoint probing.
  DW_SAGAS_WATERLINE_PORT       Host port for the published Waterline app. Defaults to a free port.
  DW_SAGAS_WATERLINE_BIND_HOST  Docker host interface for the Waterline port. Defaults to server bind host.
  DW_SAGAS_WATERLINE_CONNECT_HOST
                                First host/address to probe. Defaults to server connect host.
  DW_SAGAS_WATERLINE_URL        Exact Waterline URL to use; disables automatic endpoint probing.
USAGE
}

keep_run_root="${DW_SAGAS_KEEP_RUN_ROOT:-0}"
result_dir="${DW_SAGAS_RESULT_DIR:-}"

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

require_command() {
  local name="$1"

  if ! command -v "$name" >/dev/null 2>&1; then
    printf 'required command not found: %s\n' "$name" >&2
    return 1
  fi
}

timestamp() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
saga_scenario_manifest="${DW_SAGAS_SCENARIO_MANIFEST:-$repo_root/static/platform-conformance/saga-runtime-scenarios.json}"

read_saga_suite_version() {
  local version=""

  if [[ -f "$saga_scenario_manifest" ]]; then
    version="$(sed -n 's/^[[:space:]]*"suite_version"[[:space:]]*:[[:space:]]*\([0-9][0-9]*\).*/\1/p' "$saga_scenario_manifest" | head -n 1)"
  fi

  if [[ -z "$version" ]]; then
    version="${DW_SAGAS_SUITE_VERSION:-}"
  fi

  if [[ -n "$version" ]]; then
    printf '%s' "$version"
  else
    printf 'null'
  fi
}

saga_suite_version="$(read_saga_suite_version)"

run_root="${DW_SAGAS_RUN_ROOT:-}"
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-sagas.XXXXXX")"
fi
mkdir -p "$run_root"
run_label="$(basename "$run_root" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_.-' '-')"
php_worker_container="${DW_SAGAS_PHP_WORKER_CONTAINER:-dw-sagas-php-worker-${run_label}}"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"

finalize_saga_record_for_exit() {
  local code="$1"

  if [[ "$code" -eq 0 || ! -f "$result_dir/sagas-result.json" ]]; then
    return
  fi

  if ! command -v python3 >/dev/null 2>&1; then
    return
  fi

  python3 - "$result_dir" "$code" <<'PY' || true
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


def read_json(path: Path) -> dict[str, Any]:
    try:
        decoded = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return {}
    return decoded if isinstance(decoded, dict) else {}


def write_json(path: Path, value: dict[str, Any]) -> None:
    path.write_text(json.dumps(value, indent=2, sort_keys=True) + "\n", encoding="utf-8")


def outcome_token(value: Any) -> str:
    return str(value).strip().lower() if value is not None else ""


def declares_pass(value: dict[str, Any]) -> bool:
    return any(outcome_token(value.get(field)) in {"pass", "passed", "success", "full"} for field in ("outcome", "status", "verdict"))


def artifact_versions(result: dict[str, Any], record: dict[str, Any]) -> dict[str, Any]:
    for container in (result, record):
        for field in (
            "published_artifact_versions",
            "publishedArtifactVersions",
            "resolved_artifact_versions",
            "resolvedArtifactVersions",
            "artifactVersions",
            "artifact_versions",
        ):
            value = container.get(field)
            if isinstance(value, dict) and value:
                return value
    return {}


def append_unique(items: Any, item: Any, key: str) -> list[Any]:
    values = list(items) if isinstance(items, list) else []
    if isinstance(item, dict):
        identity = item.get(key)
        if not any(isinstance(existing, dict) and existing.get(key) == identity for existing in values):
            values.append(item)
    elif item not in values:
        values.append(item)
    return values


def replace_pass_aliases(value: dict[str, Any]) -> None:
    for field in ("status", "verdict"):
        if outcome_token(value.get(field)) in {"pass", "passed", "success", "full"}:
            value[field] = "error"


result_dir = Path(sys.argv[1])
exit_code = int(sys.argv[2])
result_path = result_dir / "sagas-result.json"
record_path = result_dir / "sagas-record.json"
result = read_json(result_path)
record = read_json(record_path)

if not declares_pass(result) and not declares_pass(record):
    raise SystemExit(0)

now = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
summary = f"runner_exit_status: saga conformance runner exited with status {exit_code} after writing a passing record"
finding = {
    "id": "sagas-runner-exit-status-mismatch",
    "severity": "P0",
    "surface": "conformance-runner",
    "scenario_id": "runner_exit_status",
    "owning_surface": "conformance_harness",
    "diagnostic_surface": "runner_process_exit_status",
    "next_routed_owner": "conformance_harness",
    "artifact_versions": artifact_versions(result, record),
    "observed_behavior": f"The runner process exited with status {exit_code} while sagas-result.json or sagas-record.json declared outcome=pass.",
    "expected_behavior": "A sagas conformance record declares outcome=pass only when the final runner process exit status is 0.",
    "next_acceptance_criterion": "make the runner exit path agree with the recorded outcome, or record the concrete failed saga scenario as non-passing before returning a non-zero exit",
    "summary": summary,
}

result["outcome"] = "error"
replace_pass_aliases(result)
result["runner_blocked"] = bool(result.get("runner_blocked") is True)
result["runner_exit_status"] = exit_code
result["runner_exit_status_recorded_at"] = now
result["findings"] = append_unique(result.get("findings"), finding, "id")
result["linked_findings"] = append_unique(result.get("linked_findings"), finding, "id")
write_json(result_path, result)

record["experiment"] = record.get("experiment") or "sagas"
record["outcome"] = "error"
replace_pass_aliases(record)
record["runnerBlocked"] = bool(record.get("runnerBlocked") is True)
record["artifactVersions"] = artifact_versions(result, record)
record["runnerExitStatus"] = exit_code
record["findings"] = append_unique(record.get("findings"), summary, "id")
record["linkedFindings"] = append_unique(record.get("linkedFindings"), finding, "id")
record["resultPath"] = str(result_path)
write_json(record_path, record)
PY
}

cleanup() {
  local code=$?
  local cleanup_status=0

  if [[ -n "${python_worker_pid:-}" ]]; then
    kill "$python_worker_pid" >/dev/null 2>&1 || true
  fi
  docker rm -f "$php_worker_container" >/dev/null 2>&1 || true
  if [[ -f "$run_root/compose.yml" ]]; then
    docker compose -f "$run_root/compose.yml" down -v >/dev/null 2>&1 || true
  fi
  if [[ "$keep_run_root" != "1" && "$code" -eq 0 && "$result_dir" != "$run_root" ]]; then
    if ! rm -rf "$run_root"; then
      cleanup_status=1
    fi
  fi

  if [[ "$code" -ne 0 ]]; then
    finalize_saga_record_for_exit "$code"
  elif [[ "$cleanup_status" -ne 0 ]]; then
    finalize_saga_record_for_exit 1
    exit 1
  fi

  exit "$code"
}
trap cleanup EXIT

saga_required_scenario_ids=(
  "published_artifact_install_only"
  "forward_success_path"
  "failure_at_d_reverse_compensation"
  "failure_at_c_reverse_compensation"
  "failure_at_a_no_compensation"
  "compensation_retry_idempotence"
  "compensation_failure_visibility"
  "mid_compensation_worker_restart"
  "php_workflow_python_compensation"
  "python_workflow_php_compensation"
  "typed_compensation_error_round_trip"
  "operator_visible_mid_compensation_status"
)

scenario_required_fields() {
  local scenario_id="$1"

  case "$scenario_id" in
    published_artifact_install_only)
      printf '%s\n' \
        "resolved_artifact_versions" \
        "artifact_sources" \
        "local_product_source_checkouts_used"
      ;;
    forward_success_path)
      printf '%s\n' \
        "forward_rows" \
        "compensation_rows" \
        "workflow_status" \
        "history_dumps"
      ;;
    failure_at_d_reverse_compensation)
      printf '%s\n' \
        "forward_rows" \
        "compensation_rows" \
        "compensation_order" \
        "workflow_status" \
        "history_dumps"
      ;;
    failure_at_c_reverse_compensation)
      printf '%s\n' \
        "forward_rows" \
        "compensation_rows" \
        "compensation_order" \
        "send_confirmation_invocations" \
        "workflow_status"
      ;;
    failure_at_a_no_compensation)
      printf '%s\n' \
        "forward_rows" \
        "compensation_rows" \
        "workflow_status"
      ;;
    compensation_retry_idempotence)
      printf '%s\n' \
        "retry_attempts" \
        "business_effect_count" \
        "workflow_status"
      ;;
    compensation_failure_visibility)
      printf '%s\n' \
        "failed_compensation_step" \
        "terminal_failure_shape" \
        "operator_visible_reason" \
        "workflow_status"
      ;;
    mid_compensation_worker_restart)
      printf '%s\n' \
        "restart_timing" \
        "resumed_compensation_step" \
        "duplicate_compensation_counts" \
        "history_dumps"
      ;;
    php_workflow_python_compensation|python_workflow_php_compensation)
      printf '%s\n' \
        "workflow_runtime" \
        "compensation_runtime" \
        "compensation_order" \
        "typed_result_shapes"
      ;;
    typed_compensation_error_round_trip)
      printf '%s\n' \
        "raised_error_type" \
        "observed_error_type" \
        "observed_error_message" \
        "terminal_failure_shape"
      ;;
    operator_visible_mid_compensation_status)
      printf '%s\n' \
        "completed_forward_steps" \
        "running_compensation_step" \
        "completed_compensations" \
        "pending_compensations" \
        "failed_compensations" \
        "operator_visibility_snapshots" \
        "waterline_operator_evidence"
      ;;
  esac
}

json_string() {
  local value="$1"

  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  value="${value//$'\n'/\\n}"
  value="${value//$'\r'/\\r}"
  value="${value//$'\t'/\\t}"
  printf '"%s"' "$value"
}

emit_required_null_fields() {
  local scenario_id="$1"
  local field

  while IFS= read -r field; do
    [[ -n "$field" ]] || continue
    printf ',\n      "%s": null' "$field"
  done < <(scenario_required_fields "$scenario_id")
}

emit_structured_findings_array() {
  local scenario_id="$1"
  local finding="$2"
  local artifact_versions_json="$3"
  local owning_surface="${4:-conformance_harness}"

  if [[ -z "$finding" ]]; then
    printf '[]'
    return
  fi

  printf '[\n        {\n'
  printf '          "scenario_id": '
  json_string "$scenario_id"
  printf ',\n          "owning_surface": '
  json_string "$owning_surface"
  printf ',\n          "artifact_versions": %s' "$artifact_versions_json"
  printf ',\n          "observed_behavior": '
  json_string "$finding"
  printf ',\n          "expected_behavior": '
  json_string "the saga scenario executes against published artifacts and records the compensation evidence required by the public contract"
  printf ',\n          "next_acceptance_criterion": '
  json_string "run this saga scenario against the complete published artifact set and record passing evidence or a focused product finding"
  printf '\n        }\n      ]'
}

emit_findings_array() {
  local finding="$1"

  if [[ -z "$finding" ]]; then
    printf '[]'
    return
  fi

  printf '[\n        '
  json_string "$finding"
  printf '\n      ]'
}

emit_blocked_install_scenario_result() {
  local status="$1"
  local finding="$2"
  local artifact_versions_json="$3"
  local artifact_sources_json="$4"

  cat <<JSON
    {
      "scenario_id": "published_artifact_install_only",
      "status": "$status",
      "resolved_artifact_versions": $artifact_versions_json,
      "artifact_sources": $artifact_sources_json,
      "local_product_source_checkouts_used": false,
      "findings": $(emit_structured_findings_array "published_artifact_install_only" "$finding" "$artifact_versions_json")
    }
JSON
}

emit_blocked_scenario_result() {
  local scenario_id="$1"
  local finding="$2"
  local artifact_versions_json="$3"

  printf '    {\n'
  printf '      "scenario_id": '
  json_string "$scenario_id"
  printf ',\n      "status": "runner_blocked"'
  emit_required_null_fields "$scenario_id"
  printf ',\n      "findings": '
  emit_structured_findings_array "$scenario_id" "$finding" "$artifact_versions_json"
  printf '\n    }'
}

emit_blocked_scenario_results() {
  local reason="$1"
  local artifact_versions_json="$2"
  local artifact_sources_json="$3"
  local install_status="runner_blocked"
  local install_finding="$reason"
  local scenario_id
  local first=1

  if [[ -f "$result_dir/run-metadata.json" ]]; then
    install_status="pass"
    install_finding=""
  fi

  for scenario_id in "${saga_required_scenario_ids[@]}"; do
    if [[ "$first" -eq 0 ]]; then
      printf ',\n'
    fi
    first=0

    if [[ "$scenario_id" == "published_artifact_install_only" ]]; then
      emit_blocked_install_scenario_result "$install_status" "$install_finding" "$artifact_versions_json" "$artifact_sources_json"
    else
      emit_blocked_scenario_result "$scenario_id" "scenario did not execute because the saga conformance runner was blocked: $reason" "$artifact_versions_json"
    fi
  done
}

blocked_result() {
  local reason="$1"
  local started="$2"
  local exit_status="${3:-1}"
  local finished
  local artifact_versions_json="{}"
  local artifact_sources_json="{}"
  finished="$(timestamp)"

  if command -v python3 >/dev/null 2>&1 && [[ -f "$result_dir/run-metadata.json" ]]; then
    artifact_versions_json="$(python3 -c 'import json,sys; print(json.dumps(json.load(open(sys.argv[1])).get("published_artifact_versions", {}), sort_keys=True))' "$result_dir/run-metadata.json" 2>/dev/null || printf '{}')"
    artifact_sources_json="$(python3 -c 'import json,sys; print(json.dumps(json.load(open(sys.argv[1])).get("artifact_sources", {}), sort_keys=True))' "$result_dir/run-metadata.json" 2>/dev/null || printf '{}')"
  elif command -v python3 >/dev/null 2>&1 && [[ -f "$result_dir/pins.json" ]]; then
    artifact_versions_json="$(python3 -c 'import json,sys; pins=json.load(open(sys.argv[1])); print(json.dumps({k:pins[k] for k in ("server","cli","workflow","workflow-php","sdk-php","sdk-python","waterline") if k in pins}, sort_keys=True))' "$result_dir/pins.json" 2>/dev/null || printf '{}')"
    artifact_sources_json="$(python3 -c 'import json,sys; print(json.dumps(json.load(open(sys.argv[1])).get("artifact_sources", {}), sort_keys=True))' "$result_dir/pins.json" 2>/dev/null || printf '{}')"
  fi

  {
    cat <<JSON
{
  "schema": "durable-workflow.v2.saga-runtime-conformance.result",
  "schema_version": 1,
  "suite_schema": "durable-workflow.v2.platform-conformance.suite",
  "suite_version": $saga_suite_version,
  "category": "saga_runtime_contract",
  "outcome": "error",
  "runner_blocked": true,
  "runner_exit_status": $exit_status,
  "started_at": "$started",
  "finished_at": "$finished",
  "generated_at": "$finished",
  "published_artifact_versions": $artifact_versions_json,
  "resolved_artifact_versions": $artifact_versions_json,
  "artifact_sources": $artifact_sources_json,
  "scenario_results": [
JSON
    emit_blocked_scenario_results "$reason" "$artifact_versions_json" "$artifact_sources_json"
    cat <<JSON
  ],
  "findings": [
    {
      "id": "runner-prerequisite-missing",
      "severity": "P0",
      "surface": "conformance-runner",
      "summary": $(json_string "$reason"),
      "scenario_id": "published_artifact_install_only",
      "owning_surface": "conformance_harness",
      "artifact_versions": $artifact_versions_json,
      "observed_behavior": $(json_string "$reason"),
      "expected_behavior": "the saga runner installs and executes every required scenario against the complete published artifact set",
      "next_acceptance_criterion": "register or repair the saga host runner so it exercises published artifacts and records per-scenario evidence"
    }
  ]
}
JSON
  } > "$result_dir/sagas-result.json"

  {
    cat <<JSON
{
  "experiment": "sagas",
  "outcome": "error",
  "runnerBlocked": true,
  "runnerExitStatus": $exit_status,
  "artifactVersions": $artifact_versions_json,
  "findings": [
    $(json_string "$reason")
  ],
  "resultPath": $(json_string "$result_dir/sagas-result.json")
}
JSON
  } > "$result_dir/sagas-record.json"
}

started_at="$(timestamp)"

on_error() {
  local code="${1:-$?}"
  local line="${2:-unknown}"
  local command="${3:-unknown}"
  command="${command//$run_root/<run-root>}"
  command="${command//$result_dir/<result-dir>}"

  if [[ "$code" -ne 0 && ! -f "$result_dir/sagas-result.json" ]]; then
    blocked_result "saga conformance runner exited before producing sagas-result.json (exit $code at line $line while running: $command)" "$started_at" "$code"
  fi

  exit "$code"
}
trap 'on_error "$?" "$LINENO" "$BASH_COMMAND"' ERR

missing=()
for command_name in docker python3 curl; do
  if ! command -v "$command_name" >/dev/null 2>&1; then
    missing+=("$command_name")
  fi
done

if [[ "${#missing[@]}" -gt 0 ]]; then
  blocked_result "saga conformance runner requires missing command(s): ${missing[*]}" "$started_at"
  exit 1
fi

choose_free_port() {
  local host="${1:-127.0.0.1}"

  python3 - "$host" <<'PY'
from __future__ import annotations

import socket
import sys

with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
    sock.bind((sys.argv[1], 0))
    print(sock.getsockname()[1])
PY
}

default_route_gateway() {
  python3 - <<'PY'
from __future__ import annotations

import socket

try:
    with open("/proc/net/route", encoding="utf-8") as handle:
        for line in handle.readlines()[1:]:
            fields = line.strip().split()
            if len(fields) < 3 or fields[1] != "00000000":
                continue
            gateway_hex = fields[2]
            if gateway_hex == "00000000":
                continue
            print(socket.inet_ntoa(bytes.fromhex(gateway_hex)[::-1]))
            break
except OSError:
    pass
PY
}

docker_bridge_gateway() {
  docker network inspect bridge --format '{{(index .IPAM.Config 0).Gateway}}' 2>/dev/null || true
}

server_url_override="${DW_SAGAS_SERVER_URL:-}"
server_bind_host="${DW_SAGAS_SERVER_BIND_HOST:-0.0.0.0}"
server_connect_host="${DW_SAGAS_SERVER_CONNECT_HOST:-127.0.0.1}"
server_port="${DW_SAGAS_SERVER_PORT:-$(choose_free_port "$server_bind_host")}"
server_url_candidates=()
waterline_url_override="${DW_SAGAS_WATERLINE_URL:-}"
waterline_bind_host="${DW_SAGAS_WATERLINE_BIND_HOST:-$server_bind_host}"
waterline_connect_host="${DW_SAGAS_WATERLINE_CONNECT_HOST:-$server_connect_host}"
waterline_port="${DW_SAGAS_WATERLINE_PORT:-$(choose_free_port "$waterline_bind_host")}"
waterline_url_candidates=()

add_server_url_candidate() {
  local candidate="$1"
  local existing

  [[ -n "$candidate" ]] || return
  for existing in "${server_url_candidates[@]}"; do
    if [[ "$existing" == "$candidate" ]]; then
      return
    fi
  done
  server_url_candidates+=("$candidate")
}

build_server_url_candidates() {
  local gateway

  if [[ -n "$server_url_override" ]]; then
    add_server_url_candidate "${server_url_override%/}"
    return
  fi

  add_server_url_candidate "http://${server_connect_host}:${server_port}"
  add_server_url_candidate "http://127.0.0.1:${server_port}"
  add_server_url_candidate "http://localhost:${server_port}"

  if [[ "$server_bind_host" != "0.0.0.0" && "$server_bind_host" != "127.0.0.1" && "$server_bind_host" != "localhost" ]]; then
    add_server_url_candidate "http://${server_bind_host}:${server_port}"
  fi

  gateway="$(default_route_gateway)"
  if [[ -n "$gateway" ]]; then
    add_server_url_candidate "http://${gateway}:${server_port}"
  fi

  gateway="$(docker_bridge_gateway)"
  if [[ -n "$gateway" && "$gateway" != "<no value>" ]]; then
    add_server_url_candidate "http://${gateway}:${server_port}"
  fi

  add_server_url_candidate "http://host.docker.internal:${server_port}"
}

build_server_url_candidates
server_base_url="${server_url_candidates[0]}"
server_api_url="${server_base_url%/}/api"

add_waterline_url_candidate() {
  local candidate="$1"
  local existing

  [[ -n "$candidate" ]] || return
  for existing in "${waterline_url_candidates[@]}"; do
    if [[ "$existing" == "$candidate" ]]; then
      return
    fi
  done
  waterline_url_candidates+=("$candidate")
}

build_waterline_url_candidates() {
  local gateway

  if [[ -n "$waterline_url_override" ]]; then
    add_waterline_url_candidate "${waterline_url_override%/}"
    return
  fi

  add_waterline_url_candidate "http://${waterline_connect_host}:${waterline_port}"
  add_waterline_url_candidate "http://127.0.0.1:${waterline_port}"
  add_waterline_url_candidate "http://localhost:${waterline_port}"

  if [[ "$waterline_bind_host" != "0.0.0.0" && "$waterline_bind_host" != "127.0.0.1" && "$waterline_bind_host" != "localhost" ]]; then
    add_waterline_url_candidate "http://${waterline_bind_host}:${waterline_port}"
  fi

  gateway="$(default_route_gateway)"
  if [[ -n "$gateway" ]]; then
    add_waterline_url_candidate "http://${gateway}:${waterline_port}"
  fi

  gateway="$(docker_bridge_gateway)"
  if [[ -n "$gateway" && "$gateway" != "<no value>" ]]; then
    add_waterline_url_candidate "http://${gateway}:${waterline_port}"
  fi

  add_waterline_url_candidate "http://host.docker.internal:${waterline_port}"
}

build_waterline_url_candidates
waterline_base_url="${waterline_url_candidates[0]}"

wait_for_server_ready() {
  local attempt
  local candidate
  local candidate_api_url

  for attempt in $(seq 1 90); do
    for candidate in "${server_url_candidates[@]}"; do
      candidate_api_url="${candidate%/}/api"
      if curl -fsS \
        -H "Accept: application/json" \
        -H "Authorization: Bearer sagas-token" \
        -H "X-Namespace: default" \
        "$candidate_api_url/ready" >/dev/null 2>&1; then
        server_base_url="${candidate%/}"
        server_api_url="$candidate_api_url"
        export DW_SAGAS_SERVER_URL="$server_base_url"
        export DW_SAGAS_SERVER_API_URL="$server_api_url"
        return 0
      fi
    done
    sleep 1
  done

  return 1
}

wait_for_waterline_ready() {
  local attempt
  local candidate

  for attempt in $(seq 1 90); do
    for candidate in "${waterline_url_candidates[@]}"; do
      if curl -fsS \
        -H "Accept: application/json" \
        -H "X-Durable-Workflow-Control-Plane-Version: 2" \
        "$candidate/waterline/api/v2/health" >/dev/null 2>&1; then
        waterline_base_url="${candidate%/}"
        export DW_SAGAS_WATERLINE_URL="$waterline_base_url"
        return 0
      fi
    done
    sleep 1
  done

  return 1
}

update_run_metadata_server_url() {
  python3 - "$result_dir/run-metadata.json" "$server_base_url" <<'PY'
from __future__ import annotations

import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
metadata = json.loads(path.read_text(encoding="utf-8"))
metadata["server_url"] = sys.argv[2]
path.write_text(json.dumps(metadata, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
  cp "$result_dir/run-metadata.json" "$run_root/run-metadata.json"
}

update_run_metadata_waterline_url() {
  python3 - "$result_dir/run-metadata.json" "$waterline_base_url" <<'PY'
from __future__ import annotations

import json
import sys
from pathlib import Path

path = Path(sys.argv[1])
metadata = json.loads(path.read_text(encoding="utf-8"))
metadata["waterline_url"] = sys.argv[2]
path.write_text(json.dumps(metadata, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
  cp "$result_dir/run-metadata.json" "$run_root/run-metadata.json"
}

cat > "$run_root/resolve-pins.py" <<'PY'
from __future__ import annotations

import json
import os
import re
import sys
import urllib.error
import urllib.request
from typing import Any


SERVER_PATCH_TAG_RE = re.compile(
    r"^(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
    r"(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)"
    r"(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?$",
)
SEMVER_TAG_RE = re.compile(r"^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.]+)?$")


def read_json(url: str) -> Any:
    request = urllib.request.Request(url, headers={"User-Agent": "durable-workflow-sagas-conformance"})
    with urllib.request.urlopen(request, timeout=45) as response:
        return json.loads(response.read().decode("utf-8"))


def env(name: str) -> str | None:
    value = os.environ.get(name)
    if value is None:
        return None
    value = value.strip()
    return value or None


def first_semver_package(packages: list[dict[str, Any]]) -> str:
    for package in packages:
        version = str(package.get("version", ""))
        if re.match(r"^v?\d+\.\d+\.\d+(?:[-+][0-9A-Za-z.]+)?$", version):
            return version
    raise RuntimeError("no semver package version found")


def packagist_version(name: str, override: str | None = None) -> str:
    if override:
        return override
    payload = read_json(f"https://repo.packagist.org/p2/{name}.json")
    return first_semver_package(payload["packages"][name])


def normalize_semver_tag(tag: str) -> str:
    tag = tag.strip()
    if not SEMVER_TAG_RE.match(tag):
        raise RuntimeError(f"no semver GitHub release tag found: {tag!r}")
    return tag.lstrip("v")


def asset_download_url(release: dict[str, Any], required_asset_name: str) -> str | None:
    for asset in release.get("assets", []):
        if str(asset.get("name", "")) == required_asset_name:
            url = str(asset.get("browser_download_url", "")).strip()
            return url or None
    return None


def url_is_downloadable(url: str) -> bool:
    headers = {"User-Agent": "durable-workflow-sagas-conformance"}
    for method in ("HEAD", "GET"):
        request_headers = dict(headers)
        if method == "GET":
            request_headers["Range"] = "bytes=0-0"
        request = urllib.request.Request(url, headers=request_headers, method=method)
        try:
            with urllib.request.urlopen(request, timeout=45) as response:
                return 200 <= response.status < 400
        except urllib.error.HTTPError:
            if method == "HEAD":
                continue
            return False
        except urllib.error.URLError:
            return False
    return False


def github_release_by_tag(repo: str, tag: str) -> dict[str, Any]:
    return read_json(f"https://api.github.com/repos/{repo}/releases/tags/{tag}")


def github_releases(repo: str) -> list[dict[str, Any]]:
    releases: list[dict[str, Any]] = []
    page = 1
    while True:
        payload = read_json(f"https://api.github.com/repos/{repo}/releases?per_page=100&page={page}")
        if not payload:
            return releases
        releases.extend(payload)
        page += 1


def github_release_with_downloadable_asset(
    repo: str,
    override: str | None,
    required_asset_name: str,
) -> tuple[str, str]:
    if override and override != "latest":
        requested_tag = override.strip()
        candidates = list(dict.fromkeys([requested_tag, requested_tag.lstrip("v")]))
        release: dict[str, Any] | None = None
        for tag in candidates:
            try:
                release = github_release_by_tag(repo, tag)
                break
            except urllib.error.HTTPError as exc:
                if exc.code == 404:
                    continue
                raise
        if release is None:
            raise RuntimeError(f"GitHub release {override!r} was not found for {repo}")
        resolved_tag = normalize_semver_tag(str(release.get("tag_name", requested_tag)))
        asset_url = asset_download_url(release, required_asset_name)
        if not asset_url or not url_is_downloadable(asset_url):
            raise RuntimeError(
                f"GitHub release {resolved_tag} for {repo} does not have a downloadable {required_asset_name} asset"
            )
        return resolved_tag, asset_url

    for release in github_releases(repo):
        tag = str(release.get("tag_name", ""))
        if not SEMVER_TAG_RE.match(tag):
            continue
        asset_url = asset_download_url(release, required_asset_name)
        if asset_url and url_is_downloadable(asset_url):
            return normalize_semver_tag(tag), asset_url

    raise RuntimeError(f"no semver GitHub release for {repo} has a downloadable {required_asset_name} asset")


def is_exact_server_patch_tag(version: str) -> bool:
    prerelease = version.partition("-")[2]
    rolling = {"latest", "current", "head", "main", "master", "dev", "snapshot", "unresolved", "placeholder"}
    return bool(SERVER_PATCH_TAG_RE.match(version)) and not any(
        identifier.lower() in rolling for identifier in prerelease.split(".") if identifier
    )


def server_tag_from_image(image: str) -> str | None:
    last_path_part = image.rsplit("/", 1)[-1]
    if ":" not in last_path_part:
        return None
    tag = last_path_part.rsplit(":", 1)[-1]
    return tag


def validate_server_version(version: str, source: str) -> str:
    if not is_exact_server_patch_tag(version):
        raise RuntimeError(f"{source} must be an exact SemVer Docker tag, not {version!r}")
    return version


def docker_hub_server_tags() -> list[str]:
    tags: list[str] = []
    url: str | None = "https://hub.docker.com/v2/repositories/durableworkflow/server/tags?page_size=100"
    while url:
        payload = read_json(url)
        for tag in payload.get("results", []):
            tags.append(str(tag.get("name", "")))
        next_url = payload.get("next")
        url = str(next_url) if next_url else None
    return tags


def docker_server_image() -> tuple[str, str]:
    explicit = env("DW_SERVER_IMAGE")
    if explicit:
        version = env("DW_SERVER_VERSION")
        image_name = explicit.split("@", 1)[0]
        image_tag = server_tag_from_image(image_name)
        exact_image_tag = image_tag if image_tag and is_exact_server_patch_tag(image_tag) else None
        if "@" not in explicit and exact_image_tag is None:
            raise RuntimeError("DW_SERVER_IMAGE must use an exact SemVer tag or an image digest")
        if version is None and exact_image_tag is not None:
            version = exact_image_tag
        if version is None:
            raise RuntimeError(
                "DW_SERVER_IMAGE must include an exact SemVer tag, "
                "or DW_SERVER_VERSION must name the exact release for digest-pinned images"
            )
        version = validate_server_version(version, "DW_SERVER_VERSION")
        if exact_image_tag is not None and version != exact_image_tag:
            raise RuntimeError(
                f"DW_SERVER_VERSION {version!r} does not match DW_SERVER_IMAGE tag {exact_image_tag!r}"
            )
        return explicit, version
    version = env("DW_SERVER_VERSION")
    if version is not None:
        version = validate_server_version(version, "DW_SERVER_VERSION")
    else:
        for name in docker_hub_server_tags():
            if is_exact_server_patch_tag(name):
                version = name
                break
        else:
            raise RuntimeError("no durableworkflow/server exact SemVer tag found")
    return f"durableworkflow/server:{version}", version


server_image, server_version = docker_server_image()
python_version = env("DW_PYTHON_SDK_VERSION") or read_json("https://pypi.org/pypi/durable-workflow/json")["info"]["version"]
php_sdk_version = packagist_version("durable-workflow/sdk", env("DW_PHP_SDK_VERSION"))
workflow_version = packagist_version("durable-workflow/workflow", env("DW_WORKFLOW_PHP_VERSION"))
cli_version, cli_installer_url = github_release_with_downloadable_asset(
    "durable-workflow/cli",
    env("DW_CLI_VERSION"),
    "install.sh",
)
waterline_version = packagist_version("durable-workflow/waterline", env("DW_WATERLINE_VERSION"))

pins = {
    "server": server_version,
    "server_image": server_image,
    "cli": cli_version,
    "cli_installer_url": cli_installer_url,
    "workflow": workflow_version,
    "workflow-php": workflow_version,
    "sdk-php": php_sdk_version,
    "sdk-python": python_version,
    "waterline": waterline_version,
    "artifact_sources": {
        "server": "docker",
        "cli": "github-release",
        "workflow": "packagist",
        "workflow-php": "packagist",
        "sdk-php": "packagist",
        "sdk-python": "pypi",
        "waterline": "packagist",
    },
}

json.dump(pins, sys.stdout, indent=2, sort_keys=True)
sys.stdout.write("\n")
PY

pin_resolution_log="$result_dir/resolve-pins.log"
if ! python3 "$run_root/resolve-pins.py" > "$result_dir/pins.json" 2> "$pin_resolution_log"; then
  pin_resolution_error="$(tr '\n' ' ' < "$pin_resolution_log" | cut -c 1-1000 || true)"
  if [[ -z "$pin_resolution_error" ]]; then
    pin_resolution_error="unknown error"
  fi
  blocked_result "published artifact pin resolution failed: $pin_resolution_error" "$started_at"
  exit 1
fi
cp "$result_dir/pins.json" "$run_root/pins.json"

server_image="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["server_image"])' "$run_root/pins.json")"
workflow_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["workflow-php"])' "$run_root/pins.json")"
php_sdk_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["sdk-php"])' "$run_root/pins.json")"
python_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["sdk-python"])' "$run_root/pins.json")"
cli_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["cli"])' "$run_root/pins.json")"
cli_installer_url="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["cli_installer_url"])' "$run_root/pins.json")"
waterline_version="$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["waterline"])' "$run_root/pins.json")"

if [[ "${DW_SAGAS_SKIP_DOCKER_PULL:-0}" != "1" ]]; then
  docker pull "$server_image"
fi
server_image_pin="$(docker image inspect --format '{{index .RepoDigests 0}}' "$server_image" 2>/dev/null || true)"
if [[ -z "$server_image_pin" || "$server_image_pin" == "<no value>" ]]; then
  server_image_pin="$server_image"
fi
docker tag "$server_image_pin" durable-workflow-sagas-server:run
printf '%s\n' "$server_image_pin" > "$result_dir/server-image-digest.txt"
cp "$result_dir/server-image-digest.txt" "$run_root/server-image-digest.txt"

python3 -m venv "$run_root/.venv"
# shellcheck disable=SC1091
. "$run_root/.venv/bin/activate"
python -m pip install --upgrade pip
python -m pip install "durable-workflow==$python_version" httpx

mkdir -p "$run_root/php-worker" "$run_root/cli/bin" "$run_root/waterline-app" "$run_root/logs"
docker run --rm -v "$run_root/php-worker:/app" composer:2 \
  composer require --no-interaction --no-progress \
    "durable-workflow/workflow:${workflow_version}@beta" \
    "durable-workflow/sdk:${php_sdk_version}@beta"
if ! curl -fsSL --retry 3 -o "$run_root/cli/install.sh" "$cli_installer_url"; then
  blocked_result "official CLI installer is not downloadable for release $cli_version at $cli_installer_url" "$started_at"
  exit 1
fi
if ! PATH="$run_root/cli/bin${PATH:+:$PATH}" \
  VERSION="$cli_version" \
  DURABLE_WORKFLOW_INSTALL_DIR="$run_root/cli/bin" \
  DURABLE_WORKFLOW_BIN_NAME=dw \
  sh "$run_root/cli/install.sh" > "$result_dir/cli-install.log" 2>&1; then
  blocked_result "official CLI installer failed for release $cli_version; see cli-install.log" "$started_at"
  exit 1
fi
if [[ ! -x "$run_root/cli/bin/dw" ]]; then
  blocked_result "official CLI installer did not create an executable dw binary for release $cli_version" "$started_at"
  exit 1
fi
if ! docker run --rm -v "$run_root/waterline-app:/app" composer:2 sh -lc "
  composer create-project --no-interaction --no-progress laravel/laravel . &&
  composer require --no-interaction --no-progress \
    'durable-workflow/waterline:${waterline_version}@beta' \
    'durable-workflow/workflow:${workflow_version}@beta' \
    'durable-workflow/sdk:${php_sdk_version}@beta'
" > "$result_dir/waterline-install.log" 2>&1; then
  blocked_result "published Waterline app install failed for durable-workflow/waterline $waterline_version with workflow $workflow_version and PHP SDK $php_sdk_version; see waterline-install.log" "$started_at"
  exit 1
fi

python3 - "$run_root/pins.json" "$result_dir/server-image-digest.txt" "$result_dir/run-metadata.json" "$saga_suite_version" "$server_base_url" "$waterline_base_url" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

pins = json.loads(Path(sys.argv[1]).read_text())
suite_version = json.loads(sys.argv[4])
metadata = {
    "experiment": "sagas",
    "schema": "durable-workflow.v2.saga-runtime-conformance.metadata",
    "suite_schema": "durable-workflow.v2.platform-conformance.suite",
    "suite_version": suite_version,
    "generated_at": datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z"),
    "published_artifact_versions": {
        "server": pins["server"],
        "cli": pins["cli"],
        "workflow": pins["workflow"],
        "workflow-php": pins["workflow-php"],
        "sdk-php": pins["sdk-php"],
        "sdk-python": pins["sdk-python"],
        "waterline": pins["waterline"],
    },
    "artifact_sources": pins["artifact_sources"],
    "server_image": pins["server_image"],
    "server_image_digest": Path(sys.argv[2]).read_text().strip(),
    "server_url": sys.argv[5],
    "waterline_url": sys.argv[6],
    "local_product_source_checkouts_used": False,
}
Path(sys.argv[3]).write_text(json.dumps(metadata, indent=2, sort_keys=True) + "\n")
PY
cp "$result_dir/run-metadata.json" "$run_root/run-metadata.json"

cat > "$run_root/compose.yml" <<YAML
x-server-environment: &server-environment
  DW_AUTH_DRIVER: token
  DW_AUTH_TOKEN: sagas-token
  DW_WORKER_POLL_TIMEOUT: "1"
  DW_WORKER_POLL_INTERVAL_MS: "100"
  DB_CONNECTION: sqlite
  DB_DATABASE: /app/database/database.sqlite
  QUEUE_CONNECTION: database

services:
  server:
    image: durable-workflow-sagas-server:run
    environment:
      <<: *server-environment
      DW_SERVER_TOPOLOGY_SHAPE: standalone_server
      DW_SERVER_PROCESS_CLASS: server_http_node
    ports:
      - "${server_bind_host}:${server_port}:8080"
    volumes:
      - server-db:/app/database
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/api/ready"]
      interval: 5s
      timeout: 3s
      retries: 24

  server-queue-worker:
    image: durable-workflow-sagas-server:run
    command: ["php", "artisan", "queue:work", "--sleep=1", "--tries=3", "--max-time=3600"]
    environment:
      <<: *server-environment
      DW_SERVER_TOPOLOGY_SHAPE: standalone_server
      DW_SERVER_PROCESS_CLASS: worker_node
    volumes:
      - server-db:/app/database
    depends_on:
      server:
        condition: service_healthy

  waterline:
    image: composer:2
    working_dir: /app
    command: ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8090"]
    environment:
      APP_ENV: local
      APP_DEBUG: "false"
      APP_KEY: "base64:UTyp33UhGolgzCK5CJmT+hNHcA+dJyp3+oINtX+VoPI="
      APP_URL: "http://localhost:${waterline_port}"
      DB_CONNECTION: sqlite
      DB_DATABASE: /app/database/database.sqlite
      QUEUE_CONNECTION: sync
      CACHE_STORE: array
      SESSION_DRIVER: array
      WATERLINE_ALLOW_UNAUTHENTICATED: "true"
      WATERLINE_ENGINE_SOURCE: v2
      WATERLINE_HEALTH_TASK_DISPATCH_MODE: poll
      WATERLINE_NAMESPACE: default
      DW_V2_TASK_DISPATCH_MODE: poll
    ports:
      - "${waterline_bind_host}:${waterline_port}:8090"
    volumes:
      - "$run_root/waterline-app:/app"
      - server-db:/app/database
    depends_on:
      server:
        condition: service_healthy

volumes:
  server-db:
YAML

cat > "$run_root/php-worker/worker.php" <<'PHP'
<?php
declare(strict_types=1);

use Workflow\Serializers\CodecRegistry;
use Workflow\Serializers\Serializer;
use Workflow\V2\Attributes\Type;
use Workflow\V2\Support\ActivityOptions;
use Workflow\V2\Support\WorkflowFiberRunner;
use Workflow\V2\Support\WorkflowStep;
use Workflow\V2\Workflow;

require __DIR__.'/vendor/autoload.php';

define('BASE_URL', getenv('DW_SAGAS_SERVER_API_URL') ?: 'http://127.0.0.1:8080/api');
const TOKEN = 'sagas-token';
const NAMESPACE_NAME = 'default';
const PROTOCOL_VERSION = '1.7';
const PHP_QUEUE = 'sagas-php';
const PYTHON_QUEUE = 'sagas-python';
const WORKER_ID = 'php-sagas-worker';
define('WORKER_STARTED_AT', gmdate('c'));
define('WORKER_STARTED_EPOCH', time());

#[Type('php.book-trip')]
final class PhpBookTripWorkflow extends Workflow
{
    public function handle(array $payload): array
    {
        $steps = steps();
        $completed = [];
        $compensations = [];
        $failStep = string_or_null($payload['fail_step'] ?? null);
        $failureMode = (string) ($payload['failure_mode'] ?? 'none');
        $compensationRuntime = (string) ($payload['compensation_runtime'] ?? 'workflow-php');
        $pauseAfterFirstCompensation = (bool) ($payload['pause_after_first_compensation'] ?? false);
        $pauseSeconds = max(1, min(120, (int) ($payload['pause_seconds'] ?? 5)));

        try {
            foreach ($steps as $step) {
                Workflow::activity(
                    $step['action'],
                    new ActivityOptions(queue: runtime_queue((string) ($payload['forward_runtime'] ?? 'workflow-php'))),
                    $payload
                );
                $completed[] = $step['action'];

                $compensation = $step['compensation'];
                if ($compensation !== '') {
                    $this->addCompensation(function () use ($compensation, $payload, $compensationRuntime, $pauseAfterFirstCompensation, $pauseSeconds, &$completed): void {
                        $options = new ActivityOptions(
                            queue: runtime_queue($compensationRuntime),
                            maxAttempts: compensation_max_attempts($compensation, $payload),
                            backoff: [0]
                        );
                        Workflow::activity($compensation, $options, $payload);
                        $completed[] = $compensation;

                        if ($pauseAfterFirstCompensation && $compensation === 'refund_card') {
                            Workflow::activity('pause_after_refund', new ActivityOptions(queue: runtime_queue($compensationRuntime)), $payload);
                            $completed[] = 'pause_after_refund';
                            Workflow::timer($pauseSeconds);
                        }
                    });
                    $compensations[] = $compensation;
                }

                if ($failStep === $step['action'] && $failureMode === 'after_forward') {
                    Workflow::activity(
                        'saga_planned_failure',
                        new ActivityOptions(queue: runtime_queue((string) ($payload['forward_runtime'] ?? 'workflow-php'))),
                        $payload
                    );
                }
            }

            return ['status' => 'completed', 'activity_log' => $completed, 'compensations' => $compensations];
        } catch (\Throwable $throwable) {
            if ($compensations === []) {
                throw $throwable;
            }

            try {
                $this->compensate();
            } catch (\Throwable $compensationFailure) {
                throw new \RuntimeException(
                    'compensation failed for '.failed_compensation_step($compensationFailure->getMessage()).': '.$compensationFailure->getMessage(),
                    previous: $compensationFailure
                );
            }

            return ['status' => 'compensated', 'activity_log' => $completed, 'compensations' => $compensations];
        }
    }
}

function steps(): array
{
    return [
        ['action' => 'reserve_flight', 'compensation' => 'cancel_flight'],
        ['action' => 'reserve_hotel', 'compensation' => 'cancel_hotel'],
        ['action' => 'charge_card', 'compensation' => 'refund_card'],
        ['action' => 'send_confirmation', 'compensation' => ''],
    ];
}

function string_or_null(mixed $value): ?string
{
    return is_string($value) && $value !== '' ? $value : null;
}

function runtime_queue(string $runtime): string
{
    return $runtime === 'sdk-python' ? PYTHON_QUEUE : PHP_QUEUE;
}

function compensation_max_attempts(string $activity, array $payload): ?int
{
    if ($activity === 'cancel_hotel' && ($payload['cancel_hotel_fail_once'] ?? false)) {
        return 2;
    }
    if ($activity === 'cancel_flight' && ($payload['cancel_flight_fail'] ?? false)) {
        return 1;
    }

    return null;
}

function failed_compensation_step(string $message): string
{
    foreach (['cancel_flight', 'cancel_hotel', 'refund_card'] as $step) {
        if (str_contains($message, $step)) {
            return $step;
        }
    }

    return 'unknown';
}

function request_json(string $method, string $path, ?array $body = null, int $timeout = 10, array $allowed = []): array
{
    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'Authorization: Bearer '.TOKEN,
        'X-Namespace: '.NAMESPACE_NAME,
        'X-Durable-Workflow-Protocol-Version: '.PROTOCOL_VERSION,
    ];
    $options = ['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'ignore_errors' => true,
        'timeout' => $timeout,
    ]];
    if ($body !== null) {
        $options['http']['content'] = json_encode($body, JSON_THROW_ON_ERROR);
    }
    unset($http_response_header);
    $response = file_get_contents(BASE_URL.$path, false, stream_context_create($options));
    $status = 0;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d+)/', $header, $matches) === 1) {
            $status = (int) $matches[1];
            break;
        }
    }
    if (($status >= 400 || $status === 0) && ! in_array($status, $allowed, true)) {
        throw new RuntimeException("$method $path failed with HTTP $status: ".($response ?: ''));
    }
    $decoded = $response === false || $response === '' ? [] : json_decode($response, true, flags: JSON_THROW_ON_ERROR);
    return is_array($decoded) ? $decoded : [];
}

function worker_status_payload(): array
{
    return [
        'worker_id' => WORKER_ID,
        'task_slots' => [
            'workflow_available' => 1,
            'activity_available' => 1,
            'session_available' => 0,
        ],
        'process_metrics' => [
            'memory_bytes' => memory_get_usage(true),
            'process_uptime_seconds' => max(0, time() - (int) WORKER_STARTED_EPOCH),
            'process_id' => getmypid() ?: 0,
            'host' => gethostname() ?: 'unknown',
            'process_started_at' => WORKER_STARTED_AT,
        ],
    ];
}

function worker_heartbeat_interval_seconds(array $response): int
{
    $advertised = max(10, (int) ($response['heartbeat_interval_seconds'] ?? 60));

    return max(5, min(10, intdiv($advertised, 2)));
}

function send_worker_heartbeat(): array
{
    return request_json('POST', '/worker/heartbeat', worker_status_payload(), 10, [404]);
}

function maybe_send_worker_heartbeat(int &$nextHeartbeatAt, int &$heartbeatEverySeconds): void
{
    if (time() < $nextHeartbeatAt) {
        return;
    }

    try {
        $heartbeat = send_worker_heartbeat();
        $heartbeatEverySeconds = worker_heartbeat_interval_seconds($heartbeat);
    } catch (\Throwable $throwable) {
        fwrite(STDERR, 'PHP saga worker heartbeat failed: '.$throwable::class.': '.$throwable->getMessage()."\n");
    }

    $nextHeartbeatAt = time() + $heartbeatEverySeconds;
}

function envelope(mixed $value, ?string $codec = null): array
{
    $codec = $codec ?: CodecRegistry::defaultCodec();
    return ['codec' => $codec, 'blob' => Serializer::serializeWithCodec($codec, $value)];
}

function decode_payload(mixed $value, ?string $codec = null): mixed
{
    if ($value === null) {
        return null;
    }
    if (is_array($value) && isset($value['codec'], $value['blob'])) {
        return Serializer::unserializeWithCodec((string) $value['codec'], (string) $value['blob']);
    }
    if (is_string($value)) {
        return Serializer::unserializeWithCodec($codec ?: CodecRegistry::defaultCodec(), $value);
    }
    return $value;
}

function task_codec(array $task): string
{
    $codec = $task['payload_codec'] ?? null;
    if (! is_string($codec) || $codec === '') {
        $codec = is_array($task['arguments'] ?? null) ? ($task['arguments']['codec'] ?? null) : null;
    }
    return is_string($codec) && $codec !== '' ? $codec : CodecRegistry::defaultCodec();
}

function history_events(array $task): array
{
    $events = $task['history_events'] ?? ($task['history']['events'] ?? []);
    return is_array($events) ? $events : [];
}

function complete_workflow_task(array $task, array $commands): void
{
    request_json('POST', '/worker/workflow-tasks/'.$task['task_id'].'/complete', [
        'lease_owner' => $task['lease_owner'],
        'workflow_task_attempt' => $task['workflow_task_attempt'] ?? 1,
        'commands' => $commands,
    ], 10, [409]);
}

function complete_activity_task(array $task, mixed $result, string $codec): void
{
    request_json('POST', '/worker/activity-tasks/'.$task['task_id'].'/complete', [
        'activity_attempt_id' => $task['activity_attempt_id'] ?? $task['attempt_id'] ?? '',
        'lease_owner' => $task['lease_owner'],
        'result' => envelope($result, $codec),
    ], 10, [409]);
}

function fail_activity_task(array $task, string $message, string $type = 'SagaActivityError'): void
{
    request_json('POST', '/worker/activity-tasks/'.$task['task_id'].'/fail', [
        'activity_attempt_id' => $task['activity_attempt_id'] ?? $task['attempt_id'] ?? '',
        'lease_owner' => $task['lease_owner'],
        'failure' => [
            'message' => $message,
            'type' => $type,
            'kind' => 'application',
        ],
    ], 10, [409]);
}

function terminal_failure_throwable(\Throwable $throwable): \Throwable
{
    $cursor = $throwable;
    $fallback = $throwable->getPrevious() instanceof \Throwable ? $throwable->getPrevious() : $throwable;

    while ($cursor instanceof \Throwable) {
        if ($cursor instanceof \Workflow\V2\Exceptions\RestoredWorkflowException) {
            return $cursor;
        }

        $next = $cursor->getPrevious();
        if (! $next instanceof \Throwable) {
            break;
        }
        $cursor = $next;
    }

    return $fallback;
}

function terminal_exception_payload(\Throwable $throwable): array
{
    $visible = terminal_failure_throwable($throwable);

    if ($visible instanceof \Workflow\V2\Exceptions\RestoredWorkflowException) {
        $payload = $visible->failurePayload();

        return [
            'class' => is_string($payload['class'] ?? null) ? $payload['class'] : $visible::class,
            'type' => is_string($payload['type'] ?? null) ? $payload['type'] : $visible::class,
            'message' => $visible->getMessage(),
        ];
    }

    return [
        'class' => $visible::class,
        'type' => $visible::class,
        'message' => $visible->getMessage(),
    ];
}

function fail_workflow_task(array $task, \Throwable $throwable): void
{
    $exception = terminal_exception_payload($throwable);

    complete_workflow_task($task, [[
        'type' => 'fail_workflow',
        'message' => $throwable->getMessage(),
        'exception_class' => is_string($exception['class'] ?? null) ? $exception['class'] : $throwable::class,
        'exception_type' => is_string($exception['type'] ?? null) ? $exception['type'] : $throwable::class,
        'exception' => $exception,
    ]]);
}

function fail_protocol_workflow_task(array $task, \Throwable $throwable, string $prefix): void
{
    try {
        request_json('POST', '/worker/workflow-tasks/'.$task['task_id'].'/fail', [
            'lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'] ?? 1,
            'failure' => [
                'message' => $prefix.': '.$throwable->getMessage(),
                'type' => $throwable::class,
                'stack_trace' => $throwable->getTraceAsString(),
            ],
        ], 10, [404, 409]);
    } catch (\Throwable $reportFailure) {
        fwrite(STDERR, sprintf(
            "failed to report workflow task failure for task %s: %s: %s\n",
            (string) ($task['task_id'] ?? 'unknown'),
            $reportFailure::class,
            $reportFailure->getMessage()
        ));
    }
}

function report_waiting_workflow_task(array $task, WorkflowStep $step): void
{
    $yielded = $step->yielded !== null ? get_debug_type($step->yielded) : 'unknown workflow yield';

    try {
        request_json('POST', '/worker/workflow-tasks/'.$task['task_id'].'/fail', [
            'lease_owner' => $task['lease_owner'],
            'workflow_task_attempt' => $task['workflow_task_attempt'] ?? 1,
            'failure' => [
                'message' => 'workflow task waiting for scheduled history: '.$yielded.' has no completed history yet',
                'type' => 'WorkflowTaskWaitingForHistory',
            ],
        ], 10, [404, 409]);
    } catch (\Throwable $reportFailure) {
        fwrite(STDERR, sprintf(
            "failed to report waiting workflow task for task %s: %s: %s\n",
            (string) ($task['task_id'] ?? 'unknown'),
            $reportFailure::class,
            $reportFailure->getMessage()
        ));
    }
}

function workflow_class_for_task(array $task): string
{
    return match ($task['workflow_type'] ?? '') {
        'php.book-trip' => PhpBookTripWorkflow::class,
        default => throw new \RuntimeException('unknown PHP workflow type '.var_export($task['workflow_type'] ?? null, true)),
    };
}

function workflow_arguments(array $task, string $codec): array
{
    $arguments = decode_payload($task['arguments'] ?? null, $codec);
    if (is_array($arguments) && array_is_list($arguments)) {
        return $arguments;
    }

    return is_array($arguments) ? [$arguments] : [];
}

function handle_workflow_task(array $task): void
{
    $codec = task_codec($task);

    try {
        $runner = WorkflowFiberRunner::forClass(
            workflow_class_for_task($task),
            (string) ($task['workflow_id'] ?? $task['workflow_instance_id'] ?? ''),
            (string) ($task['run_id'] ?? $task['workflow_run_id'] ?? ''),
            workflow_arguments($task, $codec),
            $codec,
            history_events($task),
            NAMESPACE_NAME,
        );
        $step = $runner->step();
        if ($step->commands === []) {
            report_waiting_workflow_task($task, $step);
            return;
        }
    } catch (\Throwable $throwable) {
        try {
            fail_workflow_task($task, $throwable);
        } catch (\Throwable $completionFailure) {
            fail_protocol_workflow_task(
                $task,
                $completionFailure,
                'terminal workflow failure command was rejected'
            );
        }
        return;
    }

    try {
        complete_workflow_task($task, $step->commands);
    } catch (\Throwable $completionFailure) {
        fail_protocol_workflow_task(
            $task,
            $completionFailure,
            'workflow task completion failed after commands were produced'
        );
    }
}

function activity_input(array $task, string $codec): array
{
    $arguments = decode_payload($task['arguments'] ?? null, $codec);
    $payload = is_array($arguments) && array_is_list($arguments) ? ($arguments[0] ?? []) : $arguments;
    return is_array($payload) ? $payload : [];
}

function side_store_path(): string
{
    return getenv('SAGA_SIDE_STORE') ?: __DIR__.'/side-store.jsonl';
}

function business_effect_key(array $row): ?string
{
    $kind = is_string($row['kind'] ?? null) ? $row['kind'] : '';
    if (! in_array($kind, ['forward', 'compensation', 'marker'], true)) {
        return null;
    }

    $scenario = is_string($row['scenario_id'] ?? null) ? $row['scenario_id'] : '';
    $step = is_string($row['step'] ?? null) ? $row['step'] : '';
    if ($scenario === '' || $step === '') {
        return null;
    }

    $idempotencyKey = is_string($row['idempotency_key'] ?? null) ? $row['idempotency_key'] : '';
    if ($idempotencyKey !== '') {
        return $idempotencyKey;
    }

    return $scenario.'|'.$kind.'|'.$step;
}

function append_side_store(array $row): void
{
    $row['runtime'] = 'workflow-php';
    $row['recorded_at'] = gmdate('c');
    $effectKey = business_effect_key($row);
    if ($effectKey !== null) {
        $row['idempotency_key'] = $effectKey;
    }

    $path = side_store_path();
    $handle = fopen($path, 'c+');
    if ($handle === false) {
        throw new RuntimeException('Unable to open saga side store '.$path);
    }

    try {
        flock($handle, LOCK_EX);

        if ($effectKey !== null) {
            rewind($handle);
            while (($line = fgets($handle)) !== false) {
                $decoded = json_decode(trim($line), true);
                if (! is_array($decoded)) {
                    continue;
                }

                if (business_effect_key($decoded) === $effectKey) {
                    return;
                }
            }
        }

        fseek($handle, 0, SEEK_END);
        fwrite($handle, json_encode($row, JSON_THROW_ON_ERROR)."\n");
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

function fail_once_state_path(string $scenario, string $activity): string
{
    return sys_get_temp_dir().'/dw-sagas-'.$scenario.'-'.$activity.'.failed-once';
}

function handle_activity_task(array $task): void
{
    $codec = task_codec($task);
    $activityType = (string) ($task['activity_type'] ?? '');
    $payload = activity_input($task, $codec);
    $scenario = (string) ($payload['scenario_id'] ?? 'unknown');

    if ($activityType === 'cancel_hotel' && ($payload['cancel_hotel_fail_once'] ?? false)) {
        $statePath = fail_once_state_path($scenario, $activityType);
        if (! is_file($statePath)) {
            file_put_contents($statePath, '1');
            append_side_store([
                'scenario_id' => $scenario,
                'kind' => 'compensation_attempt',
                'step' => $activityType,
                'activity_attempt_id' => $task['activity_attempt_id'] ?? $task['attempt_id'] ?? null,
            ]);
            fail_activity_task($task, 'cancel_hotel injected retryable failure', 'RetryableHotelCancelError');
            return;
        }
    }

    if ($activityType === 'cancel_flight' && ($payload['cancel_flight_fail'] ?? false)) {
        append_side_store([
            'scenario_id' => $scenario,
            'kind' => 'compensation_attempt',
            'step' => $activityType,
            'activity_attempt_id' => $task['activity_attempt_id'] ?? $task['attempt_id'] ?? null,
        ]);
        fail_activity_task($task, 'cancel_flight typed compensation failure', 'TypedCancelFlightError');
        return;
    }

    if (
        $activityType === (string) ($payload['fail_step'] ?? '')
        && ($payload['failure_mode'] ?? null) === 'before_forward'
    ) {
        fail_activity_task($task, $activityType.' planned saga failure before forward effect', 'PlannedSagaStepFailure');
        return;
    }

    if ($activityType === 'saga_planned_failure') {
        fail_activity_task($task, (string) ($payload['failure_message'] ?? 'planned saga failure'), 'PlannedSagaFailure');
        return;
    }

    $kind = in_array($activityType, ['cancel_flight', 'cancel_hotel', 'refund_card'], true) ? 'compensation' : 'forward';
    if ($activityType === 'pause_after_refund') {
        $kind = 'marker';
    }
    append_side_store([
        'scenario_id' => $scenario,
        'kind' => $kind,
        'step' => $activityType,
        'idempotency_key' => $task['idempotency_key'] ?? $task['activity_execution_id'] ?? null,
        'activity_execution_id' => $task['activity_execution_id'] ?? null,
        'activity_attempt_id' => $task['activity_attempt_id'] ?? $task['attempt_id'] ?? null,
    ]);
    complete_activity_task($task, ['activity' => $activityType, 'runtime' => 'workflow-php'], $codec);
}

$registration = request_json('POST', '/worker/register', array_merge(worker_status_payload(), [
    'task_queue' => PHP_QUEUE,
    'runtime' => 'php',
    'sdk_version' => 'durable-workflow-php/published-artifact',
    'supported_workflow_types' => ['php.book-trip'],
    'supported_activity_types' => [
        'reserve_flight',
        'reserve_hotel',
        'charge_card',
        'send_confirmation',
        'cancel_flight',
        'cancel_hotel',
        'refund_card',
        'pause_after_refund',
        'saga_planned_failure',
    ],
    'max_concurrent_workflow_tasks' => 1,
    'max_concurrent_activity_tasks' => 1,
]));
$heartbeatEverySeconds = worker_heartbeat_interval_seconds($registration);
$nextHeartbeatAt = time() + $heartbeatEverySeconds;

while (true) {
    maybe_send_worker_heartbeat($nextHeartbeatAt, $heartbeatEverySeconds);

    try {
        $workflowPoll = request_json('POST', '/worker/workflow-tasks/poll', [
            'worker_id' => WORKER_ID,
            'task_queue' => PHP_QUEUE,
        ], 6);
        if (is_array($workflowPoll['task'] ?? null)) {
            handle_workflow_task($workflowPoll['task']);
        }
    } catch (\Throwable $throwable) {
        fwrite(STDERR, 'PHP saga workflow poll loop error: '.$throwable::class.': '.$throwable->getMessage()."\n");
    }

    maybe_send_worker_heartbeat($nextHeartbeatAt, $heartbeatEverySeconds);

    try {
        $activityPoll = request_json('POST', '/worker/activity-tasks/poll', [
            'worker_id' => WORKER_ID,
            'task_queue' => PHP_QUEUE,
        ], 6);
        if (is_array($activityPoll['task'] ?? null)) {
            handle_activity_task($activityPoll['task']);
        }
    } catch (\Throwable $throwable) {
        fwrite(STDERR, 'PHP saga activity poll loop error: '.$throwable::class.': '.$throwable->getMessage()."\n");
    }

    maybe_send_worker_heartbeat($nextHeartbeatAt, $heartbeatEverySeconds);
    usleep(100000);
}
PHP

cat > "$run_root/python-worker.py" <<'PY'
from __future__ import annotations

import asyncio
import fcntl
import json
import os
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from durable_workflow import Client, Worker, activity, workflow
from durable_workflow.errors import ActivityFailed


PHP_QUEUE = "sagas-php"
PYTHON_QUEUE = "sagas-python"
SIDE_STORE = Path(os.environ["SAGA_SIDE_STORE"])
SERVER_URL = os.environ.get("DW_SAGAS_SERVER_URL", "http://127.0.0.1:8080").rstrip("/")


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def business_effect_key(row: dict[str, Any]) -> str | None:
    kind = row.get("kind")
    if kind not in {"forward", "compensation", "marker"}:
        return None
    scenario = row.get("scenario_id")
    step = row.get("step")
    if not isinstance(scenario, str) or not scenario or not isinstance(step, str) or not step:
        return None
    explicit = row.get("idempotency_key")
    if isinstance(explicit, str) and explicit:
        return explicit
    return f"{scenario}|{kind}|{step}"


def existing_business_effect_keys(handle: Any) -> set[str]:
    handle.seek(0)
    keys: set[str] = set()
    for line in handle:
        if not line.strip():
            continue
        try:
            decoded = json.loads(line)
        except json.JSONDecodeError:
            continue
        if not isinstance(decoded, dict):
            continue
        key = business_effect_key(decoded)
        if key is not None:
            keys.add(key)
    return keys


def append_row(row: dict[str, Any]) -> None:
    row = {**row, "runtime": "sdk-python", "recorded_at": now()}
    effect_key = business_effect_key(row)
    if effect_key is not None:
        row["idempotency_key"] = effect_key
    with SIDE_STORE.open("a+", encoding="utf-8") as handle:
        fcntl.flock(handle, fcntl.LOCK_EX)
        if effect_key is not None and effect_key in existing_business_effect_keys(handle):
            fcntl.flock(handle, fcntl.LOCK_UN)
            return
        handle.seek(0, os.SEEK_END)
        handle.write(json.dumps(row, sort_keys=True) + "\n")
        fcntl.flock(handle, fcntl.LOCK_UN)


def activity_metadata() -> dict[str, Any]:
    try:
        info = activity.context().info
    except RuntimeError:
        return {}
    return {
        "task_id": info.task_id,
        "activity_attempt_id": info.activity_attempt_id,
        "attempt_number": info.attempt_number,
    }


def runtime_queue(runtime: str) -> str:
    return PYTHON_QUEUE if runtime == "sdk-python" else PHP_QUEUE


class TypedCancelFlightError(RuntimeError):
    pass


def steps() -> list[dict[str, str]]:
    return [
        {"action": "reserve_flight", "compensation": "cancel_flight"},
        {"action": "reserve_hotel", "compensation": "cancel_hotel"},
        {"action": "charge_card", "compensation": "refund_card"},
        {"action": "send_confirmation", "compensation": ""},
    ]


def activity_kind(name: str) -> str:
    if name in {"cancel_flight", "cancel_hotel", "refund_card"}:
        return "compensation"
    if name == "pause_after_refund":
        return "marker"
    if name == "saga_planned_failure":
        return "marker"
    return "forward"


def fail_once_path(scenario: str, activity_type: str) -> Path:
    return SIDE_STORE.parent / f"{scenario}-{activity_type}.failed-once"


async def activity_body(activity_type: str, payload: dict[str, Any]) -> dict[str, str]:
    scenario = str(payload.get("scenario_id", "unknown"))
    metadata = activity_metadata()
    if activity_type == "cancel_hotel" and payload.get("cancel_hotel_fail_once"):
        path = fail_once_path(scenario, activity_type)
        if not path.exists():
            path.write_text("1", encoding="utf-8")
            append_row({"scenario_id": scenario, "kind": "compensation_attempt", "step": activity_type, **metadata})
            raise RuntimeError("cancel_hotel injected retryable failure")

    if activity_type == "cancel_flight" and payload.get("cancel_flight_fail"):
        append_row({"scenario_id": scenario, "kind": "compensation_attempt", "step": activity_type, **metadata})
        raise TypedCancelFlightError("cancel_flight typed compensation failure")

    if activity_type == str(payload.get("fail_step") or "") and payload.get("failure_mode") == "before_forward":
        raise RuntimeError(f"{activity_type} planned saga failure before forward effect")

    if activity_type == "saga_planned_failure":
        raise RuntimeError(str(payload.get("failure_message") or "planned saga failure"))

    append_row({"scenario_id": scenario, "kind": activity_kind(activity_type), "step": activity_type, **metadata})
    return {"activity": activity_type, "runtime": "sdk-python"}


def define_activity(name: str):
    @activity.defn(name=name)
    async def _activity(payload: dict[str, Any]) -> dict[str, str]:
        return await activity_body(name, payload)

    return _activity


reserve_flight = define_activity("reserve_flight")
reserve_hotel = define_activity("reserve_hotel")
charge_card = define_activity("charge_card")
send_confirmation = define_activity("send_confirmation")
cancel_flight = define_activity("cancel_flight")
cancel_hotel = define_activity("cancel_hotel")
refund_card = define_activity("refund_card")
pause_after_refund = define_activity("pause_after_refund")
saga_planned_failure = define_activity("saga_planned_failure")


@workflow.defn(name="python.book-trip")
class PythonBookTripWorkflow:
    def run(self, ctx, payload: dict[str, Any]):
        completed: list[str] = []
        compensations: list[str] = []
        fail_step = payload.get("fail_step")
        failure_mode = payload.get("failure_mode") or "none"
        compensation_runtime = str(payload.get("compensation_runtime") or "sdk-python")
        forward_runtime = str(payload.get("forward_runtime") or "sdk-python")
        pause = bool(payload.get("pause_after_first_compensation"))
        pause_seconds = max(1, min(120, int(payload.get("pause_seconds") or 5)))

        try:
            for step in steps():
                action = step["action"]
                compensation = step["compensation"]

                yield ctx.schedule_activity(action, [payload], queue=runtime_queue(forward_runtime))
                completed.append(action)

                if compensation:
                    compensations.append(compensation)

                if fail_step == action and failure_mode == "after_forward":
                    yield ctx.schedule_activity(
                        "saga_planned_failure",
                        [payload],
                        queue=runtime_queue(forward_runtime),
                    )

            return {"status": "completed", "activity_log": completed, "compensations": compensations}
        except ActivityFailed:
            if not compensations:
                raise

            for index, compensation in enumerate(reversed(compensations)):
                retry_policy = None
                if compensation == "cancel_hotel" and payload.get("cancel_hotel_fail_once"):
                    retry_policy = {"max_attempts": 2, "backoff_seconds": [0]}
                elif compensation == "cancel_flight" and payload.get("cancel_flight_fail"):
                    retry_policy = {"max_attempts": 1, "backoff_seconds": [0]}
                try:
                    yield ctx.schedule_activity(
                        compensation,
                        [payload],
                        queue=runtime_queue(compensation_runtime),
                        retry_policy=retry_policy,
                    )
                except ActivityFailed as exc:
                    failure_type = exc.exception_type or type(exc).__name__
                    raise RuntimeError(f"compensation failed for {compensation}: {failure_type}: {exc}") from exc
                completed.append(compensation)
                if pause and index == 0 and compensation == "refund_card":
                    yield ctx.schedule_activity(
                        "pause_after_refund",
                        [payload],
                        queue=runtime_queue(compensation_runtime),
                    )
                    completed.append("pause_after_refund")
                    yield ctx.sleep(pause_seconds)
            return {"status": "compensated", "activity_log": completed, "compensations": compensations}


async def main() -> None:
    client = Client(SERVER_URL, token="sagas-token", namespace="default")
    worker = Worker(
        client,
        task_queue=PYTHON_QUEUE,
        workflows=[PythonBookTripWorkflow],
        activities=[
            reserve_flight,
            reserve_hotel,
            charge_card,
            send_confirmation,
            cancel_flight,
            cancel_hotel,
            refund_card,
            pause_after_refund,
            saga_planned_failure,
        ],
        worker_id="python-sagas-worker",
        max_concurrent_workflow_tasks=1,
        max_concurrent_activity_tasks=1,
    )
    await worker.run()


if __name__ == "__main__":
    asyncio.run(main())
PY

cat > "$run_root/orchestrate.py" <<'PY'
from __future__ import annotations

import asyncio
import atexit
import contextlib
import json
import os
import signal
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

from durable_workflow import Client, serializer


RUN_ROOT = Path(os.environ["RUN_ROOT"])
RESULT_DIR = Path(os.environ["RESULT_DIR"])
SIDE_STORE = Path(os.environ["SAGA_SIDE_STORE"])
PYTHON_WORKER_PID = int(os.environ["PYTHON_WORKER_PID"])
SERVER_URL = os.environ.get("DW_SAGAS_SERVER_URL", "http://127.0.0.1:8080").rstrip("/")
WATERLINE_URL = os.environ.get("DW_SAGAS_WATERLINE_URL", "").rstrip("/")
PHP_WORKER_CONTAINER = os.environ.get("DW_SAGAS_PHP_WORKER_CONTAINER", "dw-sagas-php-worker")
PHP_WORKER_ID = "php-sagas-worker"
PYTHON_WORKER_ID = "python-sagas-worker"
ACTIVE_PYTHON_WORKER_PID = PYTHON_WORKER_PID
RESTARTED_PYTHON_WORKERS: list[subprocess.Popen[Any]] = []
PHP_WORKER_RESTART_OBSERVATIONS: list[dict[str, Any]] = []
PHP_WORKER_READY_OBSERVATIONS: list[dict[str, Any]] = []
PYTHON_WORKER_RESTART_OBSERVATIONS: list[dict[str, Any]] = []
PYTHON_WORKER_READY_OBSERVATIONS: list[dict[str, Any]] = []
SAGA_ACTIVITY_TYPES = [
    "reserve_flight",
    "reserve_hotel",
    "charge_card",
    "send_confirmation",
    "cancel_flight",
    "cancel_hotel",
    "refund_card",
    "pause_after_refund",
    "saga_planned_failure",
]
TERMINAL_STATUSES = {"completed", "failed", "terminated", "canceled", "cancelled"}
WAIT_RESULT_TIMEOUT_SECONDS = float(os.environ.get("DW_SAGAS_WAIT_RESULT_TIMEOUT", "45"))
WAIT_FOR_ACTIVITY_TIMEOUT_SECONDS = float(os.environ.get("DW_SAGAS_WAIT_FOR_ACTIVITY_TIMEOUT", "45"))
SCENARIO_REQUIRED_FIELDS = {
    "published_artifact_install_only": [
        "resolved_artifact_versions",
        "artifact_sources",
        "local_product_source_checkouts_used",
    ],
    "forward_success_path": [
        "forward_rows",
        "compensation_rows",
        "workflow_status",
        "history_dumps",
    ],
    "failure_at_d_reverse_compensation": [
        "forward_rows",
        "compensation_rows",
        "compensation_order",
        "workflow_status",
        "history_dumps",
    ],
    "failure_at_c_reverse_compensation": [
        "forward_rows",
        "compensation_rows",
        "compensation_order",
        "send_confirmation_invocations",
        "workflow_status",
    ],
    "failure_at_a_no_compensation": [
        "forward_rows",
        "compensation_rows",
        "workflow_status",
    ],
    "compensation_retry_idempotence": [
        "retry_attempts",
        "business_effect_count",
        "workflow_status",
    ],
    "compensation_failure_visibility": [
        "failed_compensation_step",
        "terminal_failure_shape",
        "operator_visible_reason",
        "workflow_status",
    ],
    "mid_compensation_worker_restart": [
        "restart_timing",
        "resumed_compensation_step",
        "duplicate_compensation_counts",
        "history_dumps",
    ],
    "php_workflow_python_compensation": [
        "workflow_runtime",
        "compensation_runtime",
        "compensation_order",
        "typed_result_shapes",
    ],
    "python_workflow_php_compensation": [
        "workflow_runtime",
        "compensation_runtime",
        "compensation_order",
        "typed_result_shapes",
    ],
    "typed_compensation_error_round_trip": [
        "raised_error_type",
        "observed_error_type",
        "observed_error_message",
        "terminal_failure_shape",
    ],
    "operator_visible_mid_compensation_status": [
        "completed_forward_steps",
        "running_compensation_step",
        "completed_compensations",
        "pending_compensations",
        "failed_compensations",
        "operator_visibility_snapshots",
        "waterline_operator_evidence",
    ],
}
SCENARIO_EXPECTED_BEHAVIOR = {
    "published_artifact_install_only": "all artifacts are resolved from complete published channels and no local product checkout is used as an artifact under test",
    "forward_success_path": "A, B, C, and D complete with no compensation rows and a completed terminal state",
    "failure_at_d_reverse_compensation": "after D fails, C, B, and A compensate in reverse order and the workflow reaches a documented terminal state",
    "failure_at_c_reverse_compensation": "after C fails, B and A compensate in reverse order and D is not invoked",
    "failure_at_a_no_compensation": "A failing before its forward effect records no completed forward rows and no compensation rows",
    "compensation_retry_idempotence": "a retrying compensation may retry the task but applies the underlying business undo exactly once",
    "compensation_failure_visibility": "a definitive compensation failure is visible in the terminal failure shape and operator surfaces",
    "mid_compensation_worker_restart": "after a worker restart, compensation resumes from the recorded step without duplicate compensation effects",
    "php_workflow_python_compensation": "a PHP workflow can call Python compensation handlers in the correct order and observe their result shapes",
    "python_workflow_php_compensation": "a Python workflow can call PHP compensation handlers in the correct order and observe their result shapes",
    "typed_compensation_error_round_trip": "a typed compensation error survives the worker boundary and is visible in the workflow failure shape",
    "operator_visible_mid_compensation_status": "operators can tell which forward steps completed and which compensations are running, completed, pending, or failed",
}
EXPECTED = {
    "forward_success_path": {
        "forward": ["reserve_flight", "reserve_hotel", "charge_card", "send_confirmation"],
        "compensation": [],
        "output_status": "completed",
    },
    "failure_at_d_reverse_compensation": {
        "forward": ["reserve_flight", "reserve_hotel", "charge_card"],
        "compensation": ["refund_card", "cancel_hotel", "cancel_flight"],
        "output_status": "compensated",
    },
    "failure_at_c_reverse_compensation": {
        "forward": ["reserve_flight", "reserve_hotel"],
        "compensation": ["cancel_hotel", "cancel_flight"],
        "output_status": "compensated",
    },
    "failure_at_c_after_forward_compensation": {
        "forward": ["reserve_flight", "reserve_hotel", "charge_card"],
        "compensation": ["refund_card", "cancel_hotel", "cancel_flight"],
        "output_status": "compensated",
    },
    "failure_at_a_no_compensation": {
        "forward": [],
        "compensation": [],
        "output_status": "workflow_failed",
    },
}


def ts() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def read_json(path: Path) -> dict[str, Any]:
    return json.loads(path.read_text(encoding="utf-8"))


def side_rows(scenario_id: str) -> list[dict[str, Any]]:
    if not SIDE_STORE.exists():
        return []
    rows: list[dict[str, Any]] = []
    for line in SIDE_STORE.read_text(encoding="utf-8").splitlines():
        if not line.strip():
            continue
        row = json.loads(line)
        if row.get("scenario_id") == scenario_id:
            rows.append(row)
    return rows


def all_side_rows() -> list[dict[str, Any]]:
    if not SIDE_STORE.exists():
        return []
    return [
        json.loads(line)
        for line in SIDE_STORE.read_text(encoding="utf-8").splitlines()
        if line.strip()
    ]


def steps_for(rows: list[dict[str, Any]], kind: str) -> list[str]:
    return [str(row.get("step")) for row in rows if row.get("kind") == kind]


def counts(items: list[str]) -> dict[str, int]:
    return dict(sorted(Counter(items).items()))


def evidence_key(entry: dict[str, Any], index: int) -> str:
    language = entry.get("language")
    if isinstance(language, str) and language:
        return language
    workflow_runtime = entry.get("workflow_runtime")
    compensation_runtime = entry.get("compensation_runtime")
    if isinstance(workflow_runtime, str) and isinstance(compensation_runtime, str):
        return f"{workflow_runtime}->{compensation_runtime}"
    workflow_id = entry.get("workflow_id")
    if isinstance(workflow_id, str) and workflow_id:
        return workflow_id
    return f"entry_{index + 1}"


def side_store_field(entry: dict[str, Any], field: str) -> Any:
    deltas = entry.get("side_store_deltas")
    if not isinstance(deltas, dict):
        return None
    return deltas.get(field)


def required_field_value(entry: dict[str, Any], field: str) -> Any:
    if field in entry:
        return entry[field]
    if field == "resolved_artifact_versions":
        return entry.get("published_artifact_versions")
    if field == "forward_rows":
        return side_store_field(entry, "forward_rows")
    if field in {"compensation_rows", "compensation_order"}:
        return side_store_field(entry, "compensation_rows")
    if field == "workflow_status":
        return entry.get("terminal_state") or entry.get("control_plane_state")
    if field == "history_dumps":
        history_dump = entry.get("history_dump")
        if history_dump is not None:
            return {"workflow_history": history_dump}
    if field == "send_confirmation_invocations":
        forward_rows = required_field_value(entry, "forward_rows")
        if isinstance(forward_rows, list):
            return forward_rows.count("send_confirmation")
    return None


def collect_required_field(entries: list[dict[str, Any]], field: str) -> Any:
    values: dict[str, Any] = {}
    for index, entry in enumerate(entries):
        value = required_field_value(entry, field)
        if value is not None:
            values[evidence_key(entry, index)] = value
    if not values:
        return None
    if len(values) == 1:
        return next(iter(values.values()))
    return values


def apply_manifest_fields(scenario: dict[str, Any], entries: list[dict[str, Any]]) -> None:
    missing = []
    for field in SCENARIO_REQUIRED_FIELDS.get(str(scenario["scenario_id"]), []):
        value = collect_required_field(entries, field)
        scenario[field] = value
        if value is None:
            missing.append(field)
    if missing:
        scenario_id = str(scenario["scenario_id"])
        scenario.setdefault("findings", []).append(
            finding(
                "scenario evidence missing required field(s): " + ", ".join(missing),
                "conformance_harness",
                scenario_id=scenario_id,
                next_acceptance_criterion="emit all scenario-specific evidence fields required by the saga scenario manifest",
            )
        )
        if scenario.get("status") == "pass":
            scenario["status"] = "fail"


def report_history_dumps(results: list[dict[str, Any]]) -> dict[str, Any]:
    dumps: dict[str, Any] = {}
    for index, result in enumerate(results):
        value = required_field_value(result, "history_dumps")
        if value is None:
            continue
        scenario_id = str(result.get("scenario_id") or "unknown")
        dumps[f"{scenario_id}:{evidence_key(result, index)}"] = value
    return dumps


def report_operator_visibility_snapshots(results: list[dict[str, Any]]) -> dict[str, Any]:
    snapshots: dict[str, Any] = {}
    for index, result in enumerate(results):
        value = result.get("operator_visibility_snapshots")
        if value is None:
            continue
        scenario_id = str(result.get("scenario_id") or "unknown")
        snapshots[f"{scenario_id}:{evidence_key(result, index)}"] = value
    return snapshots


def report_typed_error_shapes(results: list[dict[str, Any]]) -> list[dict[str, Any]]:
    shapes: list[dict[str, Any]] = []
    for result in results:
        if result.get("scenario_id") != "typed_compensation_error_round_trip":
            continue
        shapes.append(
            {
                "raised_error_type": result.get("raised_error_type"),
                "observed_error_type": result.get("observed_error_type"),
                "observed_error_message": result.get("observed_error_message"),
                "terminal_failure_shape": result.get("terminal_failure_shape"),
            }
        )
    return shapes


def stop_restarted_python_workers() -> None:
    for process in RESTARTED_PYTHON_WORKERS:
        if process.poll() is None:
            process.terminate()
    for process in RESTARTED_PYTHON_WORKERS:
        with contextlib.suppress(subprocess.TimeoutExpired):
            process.wait(timeout=10)
        if process.poll() is None:
            process.kill()


atexit.register(stop_restarted_python_workers)


def process_alive(pid: int | None) -> bool:
    if pid is None or pid <= 0:
        return False
    try:
        os.kill(pid, 0)
    except ProcessLookupError:
        return False
    except PermissionError:
        return True
    return True


def start_replacement_python_worker(log_name: str) -> subprocess.Popen[Any]:
    global ACTIVE_PYTHON_WORKER_PID

    log = open(RUN_ROOT / "logs" / log_name, "ab", buffering=0)
    process = subprocess.Popen(
        ["python", "-u", str(RUN_ROOT / "python-worker.py")],
        stdout=log,
        stderr=subprocess.STDOUT,
        env={**os.environ, "SAGA_SIDE_STORE": str(SIDE_STORE)},
    )
    ACTIVE_PYTHON_WORKER_PID = process.pid
    RESTARTED_PYTHON_WORKERS.append(process)
    return process


def python_worker_registration_snapshot(not_before: datetime | None = None) -> dict[str, Any]:
    try:
        body = control_plane_get(f"/workers/{PYTHON_WORKER_ID}", timeout=5)
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        return {
            "ready": False,
            "http_status": exc.code,
            "error": parse_json_stdout(raw),
        }
    except Exception as exc:
        return {
            "ready": False,
            "error": f"{type(exc).__name__}: {exc}",
        }

    workflow_types = body.get("supported_workflow_types")
    activity_types = body.get("supported_activity_types")
    if not isinstance(workflow_types, list):
        workflow_types = []
    if not isinstance(activity_types, list):
        activity_types = []

    missing = []
    if body.get("worker_id") != PYTHON_WORKER_ID:
        missing.append("worker_id")
    if body.get("task_queue") != PYTHON_QUEUE:
        missing.append("task_queue")
    if body.get("status") != "active":
        missing.append("active_status")
    if not observed_at_or_after(body.get("last_heartbeat_at"), not_before):
        missing.append("fresh_heartbeat")
    if "python.book-trip" not in workflow_types:
        missing.append("python.book-trip")
    if not process_alive(ACTIVE_PYTHON_WORKER_PID):
        missing.append("process_alive")
    for activity in SAGA_ACTIVITY_TYPES:
        if activity not in activity_types:
            missing.append(activity)

    return {
        "ready": missing == [],
        "missing": missing,
        "worker_id": body.get("worker_id"),
        "task_queue": body.get("task_queue"),
        "status": body.get("status"),
        "supported_workflow_types": workflow_types,
        "supported_activity_types": activity_types,
        "last_heartbeat_at": body.get("last_heartbeat_at"),
        "process_alive": process_alive(ACTIVE_PYTHON_WORKER_PID),
        "process_id": ACTIVE_PYTHON_WORKER_PID,
    }


def wait_for_python_worker_registration(
    reason: str,
    timeout: float = 20.0,
    not_before: datetime | None = None,
) -> None:
    deadline = time.monotonic() + timeout
    last_snapshot: dict[str, Any] | None = None
    while time.monotonic() < deadline:
        last_snapshot = python_worker_registration_snapshot(not_before)
        if last_snapshot.get("ready"):
            PYTHON_WORKER_READY_OBSERVATIONS.append(
                {
                    "at": ts(),
                    "reason": reason,
                    "worker_id": last_snapshot.get("worker_id"),
                    "task_queue": last_snapshot.get("task_queue"),
                    "last_heartbeat_at": last_snapshot.get("last_heartbeat_at"),
                    "process_id": last_snapshot.get("process_id"),
                }
            )
            return
        time.sleep(0.5)

    raise RuntimeError(
        f"Python saga worker {PYTHON_WORKER_ID} did not register active capabilities before {reason}; "
        f"last_registration_snapshot={last_snapshot}"
    )


def ensure_python_worker_running(reason: str) -> None:
    if process_alive(ACTIVE_PYTHON_WORKER_PID):
        try:
            wait_for_python_worker_registration(reason)
            return
        except RuntimeError:
            with contextlib.suppress(ProcessLookupError):
                os.kill(ACTIVE_PYTHON_WORKER_PID, signal.SIGTERM)
            time.sleep(1)
    restarted_at = datetime.now(timezone.utc).replace(microsecond=0)
    start_replacement_python_worker("python-worker-auto-restart.log")
    PYTHON_WORKER_RESTART_OBSERVATIONS.append(
        {
            "at": restarted_at.isoformat().replace("+00:00", "Z"),
            "reason": reason,
            "process_id": ACTIVE_PYTHON_WORKER_PID,
        }
    )
    wait_for_python_worker_registration(reason, not_before=restarted_at)


def ensure_workers_for_payload(workflow_type: str, payload: dict[str, Any]) -> None:
    runtimes = {
        str(payload.get("forward_runtime") or ("workflow-php" if workflow_type.startswith("php.") else "sdk-python")),
        str(payload.get("compensation_runtime") or ("workflow-php" if workflow_type.startswith("php.") else "sdk-python")),
    }
    if workflow_type.startswith("php.") or "workflow-php" in runtimes:
        ensure_php_worker_running(f"{workflow_type} payload worker startup")
    if workflow_type.startswith("python.") or "sdk-python" in runtimes:
        ensure_python_worker_running(f"{workflow_type} payload worker startup")


def compact_state(desc: dict[str, Any] | None) -> dict[str, Any]:
    if not isinstance(desc, dict):
        return {"status": None, "is_terminal": False}
    status = desc.get("status")
    return {
        "status": status,
        "is_terminal": bool(desc.get("is_terminal") or status in TERMINAL_STATUSES),
        "workflow_id": desc.get("workflow_id"),
        "run_id": desc.get("run_id"),
        "error": desc.get("error") or desc.get("failure") or desc.get("exception"),
    }


async def wait_result(
    client: Client,
    workflow_id: str,
    failures: list[str],
    timeout: float | None = None,
    *,
    php_worker_required: bool = False,
    python_worker_required: bool = False,
    wait_label: str | None = None,
) -> dict[str, Any]:
    timeout = WAIT_RESULT_TIMEOUT_SECONDS if timeout is None else timeout
    deadline = time.monotonic() + timeout
    last_desc: dict[str, Any] | None = None
    next_php_worker_probe = 0.0
    next_python_worker_probe = 0.0
    while time.monotonic() < deadline:
        if php_worker_required and time.monotonic() >= next_php_worker_probe:
            ensure_php_worker_running(wait_label or workflow_id)
            next_php_worker_probe = time.monotonic() + 2.0
        if python_worker_required and time.monotonic() >= next_python_worker_probe:
            ensure_python_worker_running(wait_label or workflow_id)
            next_python_worker_probe = time.monotonic() + 2.0
        try:
            desc = await client._request("GET", f"/workflows/{workflow_id}")
        except Exception as exc:
            failures.append(f"{workflow_id} describe failed while waiting for terminal state: {type(exc).__name__}: {exc}")
            return {
                "status": "workflow_result_unavailable",
                "error": f"{type(exc).__name__}: {exc}",
            }
        last_desc = desc
        status = desc.get("status")
        if desc.get("is_terminal") or status in TERMINAL_STATUSES:
            if status != "completed":
                return {
                    "status": "workflow_failed",
                    "terminal_state": compact_state(desc),
                    "error": desc.get("error") or desc.get("failure") or desc.get("exception"),
                }
            envelope = desc.get("output_envelope")
            if envelope is not None:
                try:
                    return serializer.decode_envelope(envelope)
                except Exception as exc:
                    failures.append(f"{workflow_id} output envelope decode failed: {type(exc).__name__}: {exc}")
                    return {
                        "status": "workflow_output_decode_failed",
                        "error": f"{type(exc).__name__}: {exc}",
                        "raw_output_envelope": envelope,
                    }
            output = desc.get("output")
            return output if isinstance(output, dict) else {"raw": output}
        await asyncio.sleep(0.5)
    failures.append(f"{workflow_id} timed out waiting for terminal state; last_state={compact_state(last_desc)}")
    return {}


async def terminal_state(client: Client, workflow_id: str) -> dict[str, Any]:
    try:
        return compact_state(await client._request("GET", f"/workflows/{workflow_id}"))
    except Exception as exc:
        return {"status": None, "is_terminal": False, "error": f"{type(exc).__name__}: {exc}"}


async def history(client: Client, workflow_id: str, run_id: str) -> dict[str, Any]:
    try:
        return await client.get_history(workflow_id, run_id)
    except Exception as exc:
        return {"error": f"{type(exc).__name__}: {exc}", "events": []}


def completed_activity_types(history_payload: dict[str, Any]) -> list[str]:
    events = history_payload.get("events")
    if not isinstance(events, list):
        events = ((history_payload.get("history") or {}).get("events") or [])
    activity_types: list[str] = []
    for event in events:
        if event.get("event_type") != "ActivityCompleted":
            continue
        payload = event.get("payload") or {}
        activity_type = payload.get("activity_type") or payload.get("activity_name")
        if isinstance(activity_type, str):
            activity_types.append(activity_type)
    return activity_types


def activity_failed_details(history_payload: dict[str, Any], activity_type: str) -> dict[str, Any]:
    events = history_payload.get("events")
    if not isinstance(events, list):
        events = ((history_payload.get("history") or {}).get("events") or [])
    for event in events:
        if not isinstance(event, dict) or event.get("event_type") != "ActivityFailed":
            continue
        payload = event.get("payload") or {}
        if not isinstance(payload, dict):
            continue
        observed_activity = payload.get("activity_type") or payload.get("activity_name")
        if observed_activity != activity_type:
            continue
        exception = payload.get("exception") if isinstance(payload.get("exception"), dict) else {}
        return {
            "activity_type": observed_activity,
            "exception_type": payload.get("exception_type") or exception.get("type"),
            "exception_class": payload.get("exception_class") or exception.get("class"),
            "message": payload.get("message") or exception.get("message"),
            "failure_category": payload.get("failure_category"),
            "non_retryable": payload.get("non_retryable"),
            "event": event,
        }
    return {}


async def start(client: Client, workflow_type: str, workflow_id: str, payload: dict[str, Any]):
    ensure_workers_for_payload(workflow_type, payload)
    return await client.start_workflow(
        workflow_type=workflow_type,
        workflow_id=workflow_id,
        task_queue=PHP_QUEUE if workflow_type.startswith("php.") else PYTHON_QUEUE,
        input=[payload],
    )


PHP_QUEUE = "sagas-php"
PYTHON_QUEUE = "sagas-python"


def base_payload(scenario_id: str) -> dict[str, Any]:
    return {
        "scenario_id": scenario_id,
        "order_id": scenario_id,
    }


def uses_php_worker(language: str, payload: dict[str, Any]) -> bool:
    return (
        language == "php"
        or payload.get("forward_runtime") == "workflow-php"
        or payload.get("compensation_runtime") == "workflow-php"
    )


def uses_python_worker(language: str, payload: dict[str, Any]) -> bool:
    return (
        language == "python"
        or payload.get("forward_runtime") == "sdk-python"
        or payload.get("compensation_runtime") == "sdk-python"
    )


def scenario_status(failures: list[str]) -> str:
    return "pass" if not failures else "fail"


def current_artifact_versions() -> dict[str, Any]:
    metadata_path = RESULT_DIR / "run-metadata.json"
    if not metadata_path.exists():
        return {}
    metadata = read_json(metadata_path)
    versions = metadata.get("published_artifact_versions")
    return versions if isinstance(versions, dict) else {}


def finding(
    summary: str,
    surface: str = "runtime",
    *,
    scenario_id: str | None = None,
    observed_behavior: str | None = None,
    expected_behavior: str | None = None,
    next_acceptance_criterion: str | None = None,
) -> dict[str, Any]:
    item: dict[str, Any] = {"severity": "P0", "surface": surface, "summary": summary}
    if scenario_id is None:
        return item

    item.update(
        {
            "scenario_id": scenario_id,
            "owning_surface": surface,
            "artifact_versions": current_artifact_versions(),
            "observed_behavior": observed_behavior or summary,
            "expected_behavior": expected_behavior
            or SCENARIO_EXPECTED_BEHAVIOR.get(
                scenario_id,
                "the scenario produces the saga compensation evidence required by the public contract",
            ),
            "next_acceptance_criterion": next_acceptance_criterion
            or "re-run this saga scenario against the complete published artifact set and record passing evidence",
        }
    )
    return item


def scenario_exception_result(
    scenario_id: str,
    label: str,
    exc: Exception,
    *,
    language: str | None = None,
) -> dict[str, Any]:
    summary = f"{label} raised before scenario evidence was fully collected: {type(exc).__name__}: {exc}"
    result: dict[str, Any] = {
        "scenario_id": scenario_id,
        "status": "fail",
        "failures": [summary],
        "exception": {
            "type": type(exc).__name__,
            "message": str(exc),
        },
    }
    if language is not None:
        result["language"] = language
    return result


async def capture_scenario(
    scenario_id: str,
    label: str,
    awaitable: Any,
    *,
    language: str | None = None,
) -> dict[str, Any]:
    try:
        return await awaitable
    except Exception as exc:
        return scenario_exception_result(scenario_id, label, exc, language=language)


def parse_json_stdout(stdout: str) -> Any:
    text = stdout.strip()
    if not text:
        return None
    try:
        return json.loads(text)
    except json.JSONDecodeError:
        decoder = json.JSONDecoder()
        for index, char in enumerate(text):
            if char not in "[{":
                continue
            try:
                value, _ = decoder.raw_decode(text[index:])
                return value
            except json.JSONDecodeError:
                continue
    return {"raw_stdout": text}


def cli_snapshot(label: str, args: list[str], timeout: float = 45.0) -> dict[str, Any]:
    command = [
        str(RUN_ROOT / "cli" / "bin" / "dw"),
        *args,
        f"--server={SERVER_URL}",
        "--namespace=default",
        "--token=sagas-token",
    ]
    try:
        completed = subprocess.run(command, capture_output=True, text=True, timeout=timeout, check=False)
    except Exception as exc:
        return {
            "label": label,
            "ok": False,
            "error": f"{type(exc).__name__}: {exc}",
            "command": args,
        }
    return {
        "label": label,
        "ok": completed.returncode == 0,
        "exit_code": completed.returncode,
        "command": args,
        "stdout": parse_json_stdout(completed.stdout),
        "stderr": completed.stderr.strip(),
    }


async def control_plane_snapshot(client: Client, label: str, path: str) -> dict[str, Any]:
    try:
        return {"label": label, "ok": True, "path": f"/api{path}", "body": await client._request("GET", path)}
    except Exception as exc:
        return {"label": label, "ok": False, "path": f"/api{path}", "error": f"{type(exc).__name__}: {exc}"}


def http_snapshot(label: str, path: str, timeout: float = 15.0) -> dict[str, Any]:
    request = urllib.request.Request(
        f"{SERVER_URL}{path}",
        headers={
            "Accept": "application/json",
            "Authorization": "Bearer sagas-token",
            "X-Namespace": "default",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            body = response.read().decode("utf-8")
            return {
                "label": label,
                "ok": 200 <= response.status < 300,
                "path": path,
                "http_status": response.status,
                "body": parse_json_stdout(body),
            }
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        return {
            "label": label,
            "ok": False,
            "path": path,
            "http_status": exc.code,
            "body": parse_json_stdout(body),
        }
    except Exception as exc:
        return {
            "label": label,
            "ok": False,
            "path": path,
            "error": f"{type(exc).__name__}: {exc}",
        }


def waterline_api_snapshot(label: str, path: str, timeout: float = 20.0) -> dict[str, Any]:
    if not WATERLINE_URL:
        return {
            "label": label,
            "ok": False,
            "path": path,
            "error": "DW_SAGAS_WATERLINE_URL was not resolved",
        }

    request = urllib.request.Request(
        f"{WATERLINE_URL}{path}",
        headers={
            "Accept": "application/json",
            "X-Durable-Workflow-Control-Plane-Version": "2",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            body = response.read().decode("utf-8")
            return {
                "label": label,
                "ok": 200 <= response.status < 300,
                "path": path,
                "http_status": response.status,
                "body": parse_json_stdout(body),
            }
    except urllib.error.HTTPError as exc:
        body = exc.read().decode("utf-8", errors="replace")
        return {
            "label": label,
            "ok": False,
            "path": path,
            "http_status": exc.code,
            "body": parse_json_stdout(body),
        }
    except Exception as exc:
        return {
            "label": label,
            "ok": False,
            "path": path,
            "error": f"{type(exc).__name__}: {exc}",
        }


def string_field(payload: Any, *fields: str) -> str | None:
    if not isinstance(payload, dict):
        return None
    for field in fields:
        value = payload.get(field)
        if isinstance(value, str) and value:
            return value
    return None


def waterline_list_item(body: Any, workflow_id: str, run_id: str) -> dict[str, Any] | None:
    data = body.get("data") if isinstance(body, dict) else None
    if not isinstance(data, list):
        return None
    for item in data:
        if not isinstance(item, dict):
            continue
        item_workflow_id = string_field(item, "workflow_instance_id", "instance_id")
        item_run_id = string_field(item, "run_id", "selected_run_id", "id")
        if item_workflow_id == workflow_id and item_run_id == run_id:
            return item
    return None


def waterline_activity_statuses(detail: Any) -> list[dict[str, Any]]:
    activities = detail.get("activities") if isinstance(detail, dict) else None
    if not isinstance(activities, list):
        return []
    statuses: list[dict[str, Any]] = []
    for activity in activities:
        if not isinstance(activity, dict):
            continue
        attempts = activity.get("attempts")
        statuses.append(
            {
                "type": activity.get("type"),
                "status": activity.get("status"),
                "row_status": activity.get("row_status"),
                "history_authority": activity.get("history_authority"),
                "attempt_statuses": [
                    attempt.get("status")
                    for attempt in attempts
                    if isinstance(attempt, dict)
                ]
                if isinstance(attempts, list)
                else [],
            }
        )
    return statuses


def waterline_current_compensation_marker(activity_statuses: list[dict[str, Any]]) -> str | None:
    compensation_types = {"refund_card", "cancel_hotel", "cancel_flight"}
    for activity in activity_statuses:
        if activity.get("type") == "pause_after_refund" and activity.get("status") in {"pending", "running", "completed"}:
            return "pause_after_refund"
    for status in ("running", "pending"):
        for activity in activity_statuses:
            if activity.get("type") in compensation_types and activity.get("status") == status:
                return str(activity["type"])
    for activity in reversed(activity_statuses):
        if activity.get("type") in compensation_types and activity.get("status") == "completed":
            return str(activity["type"])
    return None


def waterline_operator_evidence(workflow_id: str, run_id: str) -> dict[str, Any]:
    encoded_workflow_id = urllib.parse.quote(workflow_id, safe="")
    encoded_run_id = urllib.parse.quote(run_id, safe="")
    detail_path = f"/waterline/api/instances/{encoded_workflow_id}/runs/{encoded_run_id}?history_limit=all"

    health = waterline_api_snapshot("GET /waterline/api/v2/health", "/waterline/api/v2/health")
    running_list = waterline_api_snapshot("GET /waterline/api/flows/running", "/waterline/api/flows/running")
    selected_run_detail = waterline_api_snapshot("GET /waterline/api/instances/{workflowId}/runs/{runId}", detail_path)

    detail_body = selected_run_detail.get("body")
    list_body = running_list.get("body")
    list_item = waterline_list_item(list_body, workflow_id, run_id)
    activity_statuses = waterline_activity_statuses(detail_body)
    current_marker = waterline_current_compensation_marker(activity_statuses)
    visible_status = string_field(detail_body, "status", "status_bucket")
    visible_status_bucket = string_field(detail_body, "status_bucket")
    detail_workflow_id = string_field(detail_body, "workflow_instance_id", "instance_id")
    detail_run_id = string_field(detail_body, "run_id", "selected_run_id", "id")

    missing = []
    if not health.get("ok"):
        missing.append("health")
    if not running_list.get("ok"):
        missing.append("running_list")
    if not selected_run_detail.get("ok"):
        missing.append("selected_run_detail")
    if detail_workflow_id != workflow_id:
        missing.append("selected_run_workflow_id")
    if detail_run_id != run_id:
        missing.append("selected_run_run_id")
    if list_item is None:
        missing.append("running_list_item")
    if visible_status is None:
        missing.append("visible_status")
    if current_marker is None:
        missing.append("current_compensation_marker")

    return {
        "surface": "waterline",
        "ok": missing == [],
        "workflow_id": workflow_id,
        "run_id": run_id,
        "observed_workflow_id": detail_workflow_id,
        "observed_run_id": detail_run_id,
        "visible_status": visible_status,
        "visible_status_bucket": visible_status_bucket,
        "current_compensation_marker": current_marker,
        "activity_statuses": activity_statuses,
        "list_item": list_item,
        "missing": missing,
        "captures": {
            "health": health,
            "running_list": running_list,
            "selected_run_detail": selected_run_detail,
        },
    }


def control_plane_get(path: str, timeout: float = 10.0) -> dict[str, Any]:
    request = urllib.request.Request(
        f"{SERVER_URL}/api{path}",
        headers={
            "Accept": "application/json",
            "Authorization": "Bearer sagas-token",
            "X-Namespace": "default",
            "X-Durable-Workflow-Protocol-Version": "1.7",
            "X-Durable-Workflow-Control-Plane-Version": "2",
        },
    )
    with urllib.request.urlopen(request, timeout=timeout) as response:
        return json.loads(response.read().decode("utf-8"))


def parse_observed_time(value: Any) -> datetime | None:
    if not isinstance(value, str) or value == "":
        return None
    try:
        return datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None


def observed_at_or_after(value: Any, threshold: datetime | None) -> bool:
    if threshold is None:
        return True
    observed = parse_observed_time(value)
    return observed is not None and observed >= threshold


def php_worker_registration_snapshot(not_before: datetime | None = None) -> dict[str, Any]:
    try:
        body = control_plane_get(f"/workers/{PHP_WORKER_ID}", timeout=5)
    except urllib.error.HTTPError as exc:
        raw = exc.read().decode("utf-8", errors="replace")
        return {
            "ready": False,
            "http_status": exc.code,
            "error": parse_json_stdout(raw),
        }
    except Exception as exc:
        return {
            "ready": False,
            "error": f"{type(exc).__name__}: {exc}",
        }

    workflow_types = body.get("supported_workflow_types")
    activity_types = body.get("supported_activity_types")
    if not isinstance(workflow_types, list):
        workflow_types = []
    if not isinstance(activity_types, list):
        activity_types = []

    missing = []
    if body.get("worker_id") != PHP_WORKER_ID:
        missing.append("worker_id")
    if body.get("task_queue") != PHP_QUEUE:
        missing.append("task_queue")
    if body.get("status") != "active":
        missing.append("active_status")
    if not observed_at_or_after(body.get("last_heartbeat_at"), not_before):
        missing.append("fresh_heartbeat")
    if "php.book-trip" not in workflow_types:
        missing.append("php.book-trip")
    for activity in SAGA_ACTIVITY_TYPES:
        if activity not in activity_types:
            missing.append(activity)

    return {
        "ready": missing == [],
        "missing": missing,
        "worker_id": body.get("worker_id"),
        "task_queue": body.get("task_queue"),
        "status": body.get("status"),
        "supported_workflow_types": workflow_types,
        "supported_activity_types": activity_types,
        "last_heartbeat_at": body.get("last_heartbeat_at"),
    }


async def operator_snapshots(client: Client, workflow_id: str, run_id: str) -> dict[str, Any]:
    control_plane = {
        "workflow": await control_plane_snapshot(client, "GET /api/workflows/{workflowId}", f"/workflows/{workflow_id}"),
        "run": await control_plane_snapshot(client, "GET /api/workflows/{workflowId}/runs/{runId}", f"/workflows/{workflow_id}/runs/{run_id}"),
        "history": await control_plane_snapshot(client, "GET /api/workflows/{workflowId}/runs/{runId}/history", f"/workflows/{workflow_id}/runs/{run_id}/history"),
        "history_export": await control_plane_snapshot(client, "GET /api/workflows/{workflowId}/runs/{runId}/history/export", f"/workflows/{workflow_id}/runs/{run_id}/history/export"),
    }
    cli = {
        "describe": cli_snapshot(
            "dw workflow:describe <workflow-id>",
            ["workflow:describe", workflow_id, f"--run-id={run_id}", "--json"],
        ),
        "show_run": cli_snapshot(
            "dw workflow:show-run <workflow-id> <run-id>",
            ["workflow:show-run", workflow_id, run_id, "--json"],
        ),
        "history": cli_snapshot(
            "dw workflow:history <workflow-id> <run-id>",
            ["workflow:history", workflow_id, run_id, "--output=json"],
        ),
        "history_export": cli_snapshot(
            "dw workflow:history-export <workflow-id> <run-id>",
            ["workflow:history-export", workflow_id, run_id],
        ),
    }
    return {
        "control_plane": control_plane,
        "cli": cli,
        "waterline": waterline_operator_evidence(workflow_id, run_id),
    }


async def run_basic_scenario(
    client: Client,
    scenario_id: str,
    language: str,
    payload: dict[str, Any],
    row_scenario_id: str | None = None,
    expected_id: str | None = None,
) -> dict[str, Any]:
    failures: list[str] = []
    workflow_type = f"{language}.book-trip"
    row_id = row_scenario_id or scenario_id
    workflow_id = f"sagas-{language}-{row_id}"
    php_worker_required = uses_php_worker(language, payload)
    python_worker_required = uses_python_worker(language, payload)
    if php_worker_required:
        ensure_php_worker_running(f"{language} {scenario_id}")
    if python_worker_required:
        ensure_python_worker_running(f"{language} {scenario_id}")
    handle = await start(client, workflow_type, workflow_id, payload)
    output = await wait_result(
        client,
        workflow_id,
        failures,
        php_worker_required=php_worker_required,
        python_worker_required=python_worker_required,
        wait_label=f"{language} {scenario_id}",
    )
    state = await terminal_state(client, workflow_id)
    history_payload = await history(client, workflow_id, handle.run_id)
    rows = side_rows(row_id)
    actual_forward = steps_for(rows, "forward")
    actual_compensation = steps_for(rows, "compensation")

    expected_key = expected_id or scenario_id
    expected = EXPECTED[expected_key]
    if actual_forward != expected["forward"]:
        failures.append(f"{language} {scenario_id} forward rows expected {expected['forward']}, got {actual_forward}")
    if actual_compensation != expected["compensation"]:
        failures.append(f"{language} {scenario_id} compensation rows expected {expected['compensation']}, got {actual_compensation}")
    if output.get("status") != expected["output_status"]:
        failures.append(f"{language} {scenario_id} output.status expected {expected['output_status']}, got {output.get('status')}")
    if scenario_id == "failure_at_c_reverse_compensation" and "send_confirmation" in actual_forward:
        failures.append(f"{language} failure_at_c_reverse_compensation invoked send_confirmation")

    return {
        "scenario_id": scenario_id,
        "language": language,
        "status": scenario_status(failures),
        "failures": failures,
        "workflow_id": workflow_id,
        "run_id": handle.run_id,
        "observed_output": output,
        "terminal_state": state,
        "workflow_status": state,
        "forward_rows": actual_forward,
        "compensation_rows": actual_compensation,
        "compensation_order": actual_compensation,
        "send_confirmation_invocations": actual_forward.count("send_confirmation"),
        "side_store_deltas": {
            "forward_rows": actual_forward,
            "compensation_rows": actual_compensation,
            "counts": counts(actual_forward + actual_compensation),
        },
        "history_activity_completed": completed_activity_types(history_payload),
        "history_dump": history_payload,
        "history_dumps": {"workflow_history": history_payload},
    }


async def wait_for_activity(client: Client, workflow_id: str, run_id: str, activity_type: str) -> bool:
    deadline = time.monotonic() + WAIT_FOR_ACTIVITY_TIMEOUT_SECONDS
    while time.monotonic() < deadline:
        activity_types = completed_activity_types(await history(client, workflow_id, run_id))
        if activity_type in activity_types:
            return True
        await asyncio.sleep(0.5)
    return False


def restart_python_worker() -> subprocess.Popen[Any]:
    restarted_at = datetime.now(timezone.utc).replace(microsecond=0)
    with contextlib.suppress(ProcessLookupError):
        os.kill(ACTIVE_PYTHON_WORKER_PID, signal.SIGTERM)
    time.sleep(1)
    process = start_replacement_python_worker("python-worker-restart.log")
    PYTHON_WORKER_RESTART_OBSERVATIONS.append(
        {
            "at": restarted_at.isoformat().replace("+00:00", "Z"),
            "reason": "mid_compensation_worker_restart",
            "process_id": process.pid,
        }
    )
    wait_for_python_worker_registration("mid_compensation_worker_restart", not_before=restarted_at)
    return process


def php_worker_container_running() -> bool:
    completed = subprocess.run(
        ["docker", "inspect", "-f", "{{.State.Running}}", PHP_WORKER_CONTAINER],
        capture_output=True,
        text=True,
        check=False,
    )
    return completed.returncode == 0 and completed.stdout.strip() == "true"


def capture_php_worker_logs(label: str) -> None:
    safe_label = "".join(char if char.isalnum() or char in "-_" else "-" for char in label)[:80] or "php-worker"
    log_path = RUN_ROOT / "logs" / f"php-worker-{safe_label}.log"
    try:
        with log_path.open("ab", buffering=0) as log:
            subprocess.run(
                ["docker", "logs", PHP_WORKER_CONTAINER],
                stdout=log,
                stderr=subprocess.STDOUT,
                timeout=10,
                check=False,
            )
    except Exception as exc:
        with log_path.open("ab", buffering=0) as log:
            log.write(f"php worker log capture failed: {type(exc).__name__}: {exc}\n".encode("utf-8"))


def restart_php_worker(reason: str = "restart_requested") -> datetime:
    capture_php_worker_logs(reason)
    restarted_at = datetime.now(timezone.utc).replace(microsecond=0)
    PHP_WORKER_RESTART_OBSERVATIONS.append(
        {
            "at": restarted_at.isoformat().replace("+00:00", "Z"),
            "reason": reason,
            "container": PHP_WORKER_CONTAINER,
        }
    )
    subprocess.run(["docker", "rm", "-f", PHP_WORKER_CONTAINER], check=False)
    subprocess.run(
        [
            "docker",
            "run",
            "-d",
            "--name",
            PHP_WORKER_CONTAINER,
            "--network",
            "host",
            "-e",
            f"SAGA_SIDE_STORE=/run-root/{SIDE_STORE.name}",
            "-e",
            f"DW_SAGAS_SERVER_API_URL={SERVER_URL}/api",
            "-v",
            f"{RUN_ROOT}:/run-root",
            "-v",
            f"{RUN_ROOT / 'php-worker'}:/work",
            "-w",
            "/work",
            "composer:2",
            "php",
            "worker.php",
        ],
        check=True,
    )
    return restarted_at


def wait_for_php_worker_registration(
    reason: str,
    timeout: float = 20.0,
    not_before: datetime | None = None,
) -> None:
    deadline = time.monotonic() + timeout
    last_snapshot: dict[str, Any] | None = None
    while time.monotonic() < deadline:
        last_snapshot = php_worker_registration_snapshot(not_before)
        if last_snapshot.get("ready"):
            PHP_WORKER_READY_OBSERVATIONS.append(
                {
                    "at": ts(),
                    "reason": reason,
                    "worker_id": last_snapshot.get("worker_id"),
                    "task_queue": last_snapshot.get("task_queue"),
                    "last_heartbeat_at": last_snapshot.get("last_heartbeat_at"),
                }
            )
            return
        time.sleep(0.5)

    capture_php_worker_logs(f"{reason}-registration-timeout")
    raise RuntimeError(
        f"PHP saga worker {PHP_WORKER_ID} did not register active capabilities before {reason}; "
        f"last_registration_snapshot={last_snapshot}"
    )


def ensure_php_worker_running(reason: str) -> None:
    if php_worker_container_running():
        try:
            wait_for_php_worker_registration(reason)
            return
        except RuntimeError:
            restarted_at = restart_php_worker(f"{reason}-stale-registration")
            wait_for_php_worker_registration(reason, not_before=restarted_at)
            return

    restarted_at = restart_php_worker(reason)
    deadline = time.monotonic() + 10
    while time.monotonic() < deadline:
        if php_worker_container_running():
            wait_for_php_worker_registration(reason, not_before=restarted_at)
            return
        time.sleep(0.5)

    capture_php_worker_logs(f"{reason}-restart-failed")
    raise RuntimeError(f"PHP saga worker container {PHP_WORKER_CONTAINER} did not stay running before {reason}")


async def run_retry_idempotence(client: Client, language: str) -> dict[str, Any]:
    scenario_id = f"compensation_retry_idempotence_{language}"
    payload = {
        **base_payload(scenario_id),
        "fail_step": "charge_card",
        "failure_mode": "before_forward",
        "cancel_hotel_fail_once": True,
    }
    result = await run_basic_scenario(
        client,
        "failure_at_c_reverse_compensation",
        language,
        {**payload, "scenario_id": scenario_id},
        row_scenario_id=scenario_id,
    )
    rows = side_rows(scenario_id)
    attempts = [row for row in rows if row.get("kind") == "compensation_attempt" and row.get("step") == "cancel_hotel"]
    effects = [row for row in rows if row.get("kind") == "compensation" and row.get("step") == "cancel_hotel"]
    failures = []
    if len(attempts) != 1:
        failures.append(f"{language} cancel_hotel retry attempts expected 1 injected failed attempt, got {len(attempts)}")
    if len(effects) != 1:
        failures.append(f"{language} cancel_hotel business effect expected exactly once, got {len(effects)}")
    failures.extend(result["failures"])
    return {
        **result,
        "scenario_id": "compensation_retry_idempotence",
        "language": language,
        "status": scenario_status(failures),
        "failures": failures,
        "retry_attempts": len(attempts) + len(effects),
        "business_effect_count": len(effects),
    }


async def run_compensation_failure(client: Client, language: str) -> dict[str, Any]:
    scenario_id = f"compensation_failure_visibility_{language}"
    evidence_scenario_id = "compensation_failure_visibility"
    payload = {
        **base_payload(scenario_id),
        "fail_step": "charge_card",
        "failure_mode": "after_forward",
        "cancel_flight_fail": True,
    }
    workflow_type = f"{language}.book-trip"
    workflow_id = f"sagas-{language}-{scenario_id}"
    failures: list[str] = []
    php_worker_required = uses_php_worker(language, payload)
    python_worker_required = uses_python_worker(language, payload)
    if php_worker_required:
        ensure_php_worker_running(f"{language} compensation_failure_visibility")
    if python_worker_required:
        ensure_python_worker_running(f"{language} compensation_failure_visibility")
    handle = await start(client, workflow_type, workflow_id, payload)
    output = await wait_result(
        client,
        workflow_id,
        failures,
        php_worker_required=php_worker_required,
        python_worker_required=python_worker_required,
        wait_label=f"{language} compensation_failure_visibility",
    )
    state = await terminal_state(client, workflow_id)
    history_payload = await history(client, workflow_id, handle.run_id)
    visible = json.dumps(output, sort_keys=True) + json.dumps(state, sort_keys=True)
    if "cancel_flight" not in visible:
        failures.append(f"{language} terminal compensation failure did not name cancel_flight")
    if output.get("status") == "completed":
        failures.append(f"{language} terminal compensation failure reported success")
    return {
        "scenario_id": evidence_scenario_id,
        "language": language,
        "status": scenario_status(failures),
        "failures": failures,
        "workflow_id": workflow_id,
        "run_id": handle.run_id,
        "failed_compensation_step": "cancel_flight",
        "terminal_failure_shape": output,
        "operator_visible_reason": state,
        "workflow_status": state,
        "history_activity_completed": completed_activity_types(history_payload),
        "history_dump": history_payload,
        "history_dumps": {"workflow_history": history_payload},
    }


async def run_recovery(client: Client, language: str) -> dict[str, Any]:
    scenario_id = f"mid_compensation_worker_restart_{language}"
    payload = {
        **base_payload(scenario_id),
        "fail_step": "charge_card",
        "failure_mode": "after_forward",
        "pause_after_first_compensation": True,
    }
    workflow_type = f"{language}.book-trip"
    workflow_id = f"sagas-{language}-{scenario_id}"
    failures: list[str] = []
    php_worker_required = uses_php_worker(language, payload)
    python_worker_required = uses_python_worker(language, payload)
    if php_worker_required:
        ensure_php_worker_running(f"{language} mid_compensation_worker_restart")
    if python_worker_required:
        ensure_python_worker_running(f"{language} mid_compensation_worker_restart")
    handle = await start(client, workflow_type, workflow_id, payload)
    observed_pause = await wait_for_activity(client, workflow_id, handle.run_id, "pause_after_refund")
    restart_state = await terminal_state(client, workflow_id)
    if not observed_pause:
        failures.append(f"{language} recovery did not reach pause_after_refund before restart")
    elif restart_state.get("is_terminal"):
        failures.append(f"{language} recovery reached terminal state before worker restart point: {restart_state}")
    else:
        if language == "python":
            restart_python_worker()
        else:
            restarted_at = restart_php_worker("mid_compensation_worker_restart")
            wait_for_php_worker_registration("mid_compensation_worker_restart", not_before=restarted_at)
    output = await wait_result(
        client,
        workflow_id,
        failures,
        php_worker_required=php_worker_required,
        python_worker_required=python_worker_required,
        wait_label=f"{language} mid_compensation_worker_restart",
    )
    rows = side_rows(scenario_id)
    actual_forward = steps_for(rows, "forward")
    compensation = steps_for(rows, "compensation")
    expected = EXPECTED["failure_at_c_after_forward_compensation"]
    if actual_forward != expected["forward"]:
        failures.append(f"{language} recovery forward rows expected {expected['forward']}, got {actual_forward}")
    if compensation != expected["compensation"]:
        failures.append(f"{language} recovery compensation expected {expected['compensation']}, got {compensation}")
    if output.get("status") != expected["output_status"]:
        failures.append(f"{language} recovery output.status expected {expected['output_status']}, got {output.get('status')}")
    duplicates = {step: count for step, count in counts(compensation).items() if count > 1}
    if duplicates:
        failures.append(f"{language} recovery duplicate compensation counts: {duplicates}")
    history_payload = await history(client, workflow_id, handle.run_id)
    final_state = await terminal_state(client, workflow_id)
    return {
        "scenario_id": "mid_compensation_worker_restart",
        "language": language,
        "status": scenario_status(failures),
        "failures": failures,
        "workflow_id": workflow_id,
        "run_id": handle.run_id,
        "restart_timing": {"observed_pause_after_refund": observed_pause, "pre_restart_state": restart_state},
        "resumed_compensation_step": "cancel_hotel",
        "duplicate_compensation_counts": duplicates,
        "observed_output": output,
        "workflow_status": final_state,
        "forward_rows": actual_forward,
        "compensation_order": compensation,
        "side_store_deltas": {"forward_rows": actual_forward, "compensation_rows": compensation},
        "history_activity_completed": completed_activity_types(history_payload),
        "history_dump": history_payload,
        "history_dumps": {"workflow_history": history_payload},
    }


async def run_cross_language(client: Client, scenario_id: str, workflow_language: str, compensation_runtime: str) -> dict[str, Any]:
    payload = {
        **base_payload(scenario_id),
        "fail_step": "charge_card",
        "failure_mode": "after_forward",
        "compensation_runtime": compensation_runtime,
    }
    result = await run_basic_scenario(
        client,
        "failure_at_c_reverse_compensation",
        workflow_language,
        {**payload, "scenario_id": scenario_id},
        row_scenario_id=scenario_id,
        expected_id="failure_at_c_after_forward_compensation",
    )
    compensation_runtimes = [row.get("runtime") for row in side_rows(scenario_id) if row.get("kind") == "compensation"]
    expected_runtime = "sdk-python" if compensation_runtime == "sdk-python" else "workflow-php"
    failures = list(result["failures"])
    if any(runtime != expected_runtime for runtime in compensation_runtimes):
        failures.append(f"{scenario_id} expected compensation runtime {expected_runtime}, got {compensation_runtimes}")
    return {
        **result,
        "scenario_id": scenario_id,
        "workflow_runtime": "workflow-php" if workflow_language == "php" else "sdk-python",
        "compensation_runtime": expected_runtime,
        "compensation_order": result.get("compensation_order"),
        "typed_result_shapes": [row for row in side_rows(scenario_id) if row.get("kind") == "compensation"],
        "status": scenario_status(failures),
        "failures": failures,
    }


async def run_operator_visibility(client: Client) -> dict[str, Any]:
    scenario_id = "operator_visible_mid_compensation_status"
    payload = {
        **base_payload(scenario_id),
        "fail_step": "charge_card",
        "failure_mode": "after_forward",
        "pause_after_first_compensation": True,
        "pause_seconds": 90,
    }
    workflow_id = f"sagas-python-{scenario_id}"
    failures: list[str] = []
    ensure_python_worker_running("operator_visible_mid_compensation_status")
    handle = await start(client, "python.book-trip", workflow_id, payload)
    observed_pause = await wait_for_activity(client, workflow_id, handle.run_id, "pause_after_refund")
    control_plane_state = await terminal_state(client, workflow_id)
    history_payload = await history(client, workflow_id, handle.run_id)
    snapshots = await operator_snapshots(client, workflow_id, handle.run_id)
    rows = side_rows(scenario_id)
    completed_forward = steps_for(rows, "forward")
    completed_compensation = steps_for(rows, "compensation")
    waterline_evidence = snapshots["waterline"]
    visible = json.dumps(snapshots, sort_keys=True)
    for token in ["charge_card", "refund_card"]:
        if token not in visible:
            failures.append(f"operator visibility snapshot does not include {token}")
    if not observed_pause:
        failures.append("operator visibility scenario did not reach mid-compensation marker")
    for label, snapshot in snapshots["cli"].items():
        if not snapshot.get("ok"):
            failures.append(f"CLI {label} visibility snapshot failed: {snapshot}")
    operator_findings: list[dict[str, Any]] = []
    if not waterline_evidence.get("ok"):
        failures.append(f"Waterline operator visibility snapshot failed: {waterline_evidence.get('missing')}")
        operator_findings.append(
            finding(
                "Waterline operator visibility did not expose the paused mid-compensation saga run",
                "waterline_operator_visibility",
                scenario_id=scenario_id,
                observed_behavior=json.dumps(waterline_evidence, sort_keys=True),
                next_acceptance_criterion="boot the published Waterline app against the saga run database and capture selected-run detail plus list evidence for the paused compensation run",
            )
        )
    if waterline_evidence.get("observed_workflow_id") != workflow_id or waterline_evidence.get("observed_run_id") != handle.run_id:
        failures.append(
            "Waterline operator visibility did not identify the same workflow id/run id selected by the saga runner"
        )
    if waterline_evidence.get("current_compensation_marker") != "pause_after_refund":
        failures.append(
            f"Waterline current compensation marker expected pause_after_refund, got {waterline_evidence.get('current_compensation_marker')!r}"
        )
    status = scenario_status(failures)
    return {
        "scenario_id": scenario_id,
        "status": status,
        "failures": failures,
        "findings": operator_findings,
        "completed_forward_steps": completed_forward,
        "running_compensation_step": "pause_after_refund" if observed_pause else None,
        "completed_compensations": completed_compensation,
        "pending_compensations": ["cancel_hotel", "cancel_flight"] if observed_pause else [],
        "failed_compensations": [],
        "operator_visibility_snapshots": snapshots,
        "waterline_operator_evidence": waterline_evidence,
        "control_plane_state": control_plane_state,
        "workflow_status": control_plane_state,
        "history_dump": history_payload,
        "history_dumps": {"workflow_history": history_payload},
    }


async def run_typed_error(client: Client) -> dict[str, Any]:
    scenario_id = "typed_compensation_error_round_trip_python"
    payload = {
        **base_payload(scenario_id),
        "fail_step": "charge_card",
        "failure_mode": "after_forward",
        "cancel_flight_fail": True,
    }
    failures: list[str] = []
    workflow_id = f"sagas-python-{scenario_id}"
    ensure_python_worker_running("typed_compensation_error_round_trip")
    handle = await start(client, "python.book-trip", workflow_id, payload)
    output = await wait_result(
        client,
        workflow_id,
        failures,
        python_worker_required=True,
        wait_label="typed_compensation_error_round_trip",
    )
    state = await terminal_state(client, workflow_id)
    history_payload = await history(client, workflow_id, handle.run_id)
    failure_details = activity_failed_details(history_payload, "cancel_flight")
    result = {
        "scenario_id": "typed_compensation_error_round_trip",
        "language": "python",
        "status": scenario_status(failures),
        "failures": failures,
        "workflow_id": workflow_id,
        "run_id": handle.run_id,
        "failed_compensation_step": "cancel_flight",
        "terminal_failure_shape": output,
        "operator_visible_reason": state,
        "workflow_status": state,
        "activity_failure_shape": failure_details,
        "history_activity_completed": completed_activity_types(history_payload),
        "history_dump": history_payload,
        "history_dumps": {"workflow_history": history_payload},
    }
    failures = list(result["failures"])
    observed_error_type = failure_details.get("exception_type")
    observed_error_message = failure_details.get("message")
    if observed_error_type != "TypedCancelFlightError":
        failures.append(f"typed compensation error expected ActivityFailed exception_type TypedCancelFlightError, got {observed_error_type!r}")
    terminal_shape = json.dumps({"output": output, "state": state}, sort_keys=True)
    if "TypedCancelFlightError" not in terminal_shape:
        failures.append("typed compensation error type did not survive to the terminal workflow failure shape")
    return {
        **result,
        "scenario_id": "typed_compensation_error_round_trip",
        "raised_error_type": "TypedCancelFlightError",
        "observed_error_type": observed_error_type,
        "observed_error_message": observed_error_message,
        "status": scenario_status(failures),
        "failures": failures,
    }


def fold_scenarios(results: list[dict[str, Any]]) -> list[dict[str, Any]]:
    grouped: dict[str, list[dict[str, Any]]] = {}
    for result in results:
        grouped.setdefault(str(result["scenario_id"]), []).append(result)
    folded: list[dict[str, Any]] = []
    for scenario_id, entries in grouped.items():
        failures: list[str] = []
        entry_findings: list[Any] = []
        for entry in entries:
            failures.extend(entry.get("failures") or [])
            entry_findings.extend(entry.get("findings") or [])
        statuses = {str(entry.get("status")) for entry in entries}
        if failures or "fail" in statuses:
            status = "fail"
        elif "runner_blocked" in statuses:
            status = "runner_blocked"
        elif "unsupported" in statuses:
            status = "unsupported"
        elif "not_covered" in statuses:
            status = "not_covered"
        elif statuses == {"pass"}:
            status = "pass"
        else:
            status = "fail"
        scenario_findings: list[Any] = [
            finding(failure, "runtime", scenario_id=scenario_id)
            for failure in failures
        ] + entry_findings
        folded.append(
            {
                "scenario_id": scenario_id,
                "status": status,
                "started_at": entries[0].get("started_at"),
                "finished_at": entries[-1].get("finished_at"),
                "evidence": entries,
                "findings": scenario_findings,
            }
        )
        apply_manifest_fields(folded[-1], entries)
    return folded


async def main() -> None:
    started_at = os.environ["STARTED_AT"]
    metadata = read_json(RESULT_DIR / "run-metadata.json")
    client = Client(SERVER_URL, token="sagas-token", namespace="default")
    results: list[dict[str, Any]] = []

    install_scenario = {
        "scenario_id": "published_artifact_install_only",
        "status": "pass",
        "started_at": started_at,
        "finished_at": ts(),
        "published_artifact_versions": metadata["published_artifact_versions"],
        "resolved_artifact_versions": metadata["published_artifact_versions"],
        "artifact_sources": metadata["artifact_sources"],
        "local_product_source_checkouts_used": False,
    }
    results.append(install_scenario)

    basic_payloads = {
        "forward_success_path": {},
        "failure_at_d_reverse_compensation": {"fail_step": "send_confirmation", "failure_mode": "before_forward"},
        "failure_at_c_reverse_compensation": {"fail_step": "charge_card", "failure_mode": "before_forward"},
        "failure_at_a_no_compensation": {"fail_step": "reserve_flight", "failure_mode": "before_forward"},
    }
    for scenario_id, overrides in basic_payloads.items():
        for language in ("php", "python"):
            row_id = f"{scenario_id}_{language}"
            result = await capture_scenario(
                scenario_id,
                f"{language} {scenario_id}",
                run_basic_scenario(
                    client,
                    scenario_id,
                    language,
                    {**base_payload(row_id), **overrides},
                    row_scenario_id=row_id,
                ),
                language=language,
            )
            result["started_at"] = started_at
            result["finished_at"] = ts()
            results.append(result)

    for language in ("php", "python"):
        for runner, scenario_id in (
            (run_retry_idempotence, "compensation_retry_idempotence"),
            (run_compensation_failure, "compensation_failure_visibility"),
            (run_recovery, "mid_compensation_worker_restart"),
        ):
            result = await capture_scenario(
                scenario_id,
                f"{language} {scenario_id}",
                runner(client, language),
                language=language,
            )
            result["started_at"] = started_at
            result["finished_at"] = ts()
            results.append(result)

    for result in (
        await capture_scenario(
            "php_workflow_python_compensation",
            "PHP workflow with Python compensation",
            run_cross_language(client, "php_workflow_python_compensation", "php", "sdk-python"),
        ),
        await capture_scenario(
            "python_workflow_php_compensation",
            "Python workflow with PHP compensation",
            run_cross_language(client, "python_workflow_php_compensation", "python", "workflow-php"),
        ),
        await capture_scenario(
            "typed_compensation_error_round_trip",
            "typed compensation error round trip",
            run_typed_error(client),
        ),
        await capture_scenario(
            "operator_visible_mid_compensation_status",
            "operator visible mid-compensation status",
            run_operator_visibility(client),
        ),
    ):
        result["started_at"] = started_at
        result["finished_at"] = ts()
        results.append(result)

    scenario_results = fold_scenarios(results)
    findings = []
    for scenario in scenario_results:
        for scenario_finding in scenario.get("findings") or []:
            if isinstance(scenario_finding, dict):
                item = dict(scenario_finding)
                item["summary"] = f"{scenario['scenario_id']}: {item.get('summary', 'scenario finding')}"
                findings.append(item)
            else:
                findings.append(
                    finding(
                        f"{scenario['scenario_id']}: {scenario_finding}",
                        scenario_id=str(scenario["scenario_id"]),
                    )
                )

    required_ids = {
        "published_artifact_install_only",
        "forward_success_path",
        "failure_at_d_reverse_compensation",
        "failure_at_c_reverse_compensation",
        "failure_at_a_no_compensation",
        "compensation_retry_idempotence",
        "compensation_failure_visibility",
        "mid_compensation_worker_restart",
        "php_workflow_python_compensation",
        "python_workflow_php_compensation",
        "typed_compensation_error_round_trip",
        "operator_visible_mid_compensation_status",
    }
    covered_ids = {str(item["scenario_id"]) for item in scenario_results}
    for missing in sorted(required_ids - covered_ids):
        missing_scenario = {
            "scenario_id": missing,
            "status": "not_covered",
            "findings": [
                finding(
                    "scenario did not execute",
                    "coverage",
                    scenario_id=missing,
                    next_acceptance_criterion="add this scenario to the published-artifact saga runner and record its required evidence",
                )
            ],
        }
        apply_manifest_fields(missing_scenario, [])
        scenario_results.append(missing_scenario)
        for missing_finding in missing_scenario["findings"]:
            if isinstance(missing_finding, dict):
                item = dict(missing_finding)
                item["summary"] = f"{missing}: {item.get('summary', 'scenario finding')}"
                findings.append(item)
            else:
                findings.append(finding(f"{missing}: {missing_finding}", "coverage", scenario_id=missing))

    outcome = "pass" if all(item.get("status") == "pass" for item in scenario_results) else "fail"
    runner_exit_status = 0 if outcome == "pass" else 1
    finished_at = ts()
    report = {
        "schema": "durable-workflow.v2.saga-runtime-conformance.result",
        "schema_version": 1,
        "suite_schema": "durable-workflow.v2.platform-conformance.suite",
        "suite_version": metadata["suite_version"],
        "category": "saga_runtime_contract",
        "outcome": outcome,
        "runner_blocked": False,
        "runner_exit_status": runner_exit_status,
        "runner_exit_status_recorded_at": finished_at,
        "started_at": started_at,
        "finished_at": finished_at,
        "generated_at": finished_at,
        "published_artifact_versions": metadata["published_artifact_versions"],
        "resolved_artifact_versions": metadata["published_artifact_versions"],
        "artifact_sources": metadata["artifact_sources"],
        "implementation_identity": {
            "server_image": metadata["server_image"],
            "server_image_digest": metadata["server_image_digest"],
            "server_url": metadata.get("server_url"),
            "waterline_url": metadata.get("waterline_url"),
        },
        "runtime_matrix": {
            "workflow_runtimes": ["workflow-php", "sdk-python"],
            "activity_runtimes": ["workflow-php", "sdk-python"],
            "cross_language_cells": [
                "php_workflow_python_compensation",
                "python_workflow_php_compensation",
            ],
        },
        "topology": {
            "server": f"published Docker image exposed at {metadata.get('server_url')}",
            "server_queue_worker": "same published Docker image running php artisan queue:work against the shared database queue",
            "php_worker": "composer:2 container with durable-workflow/workflow package",
            "python_worker": "venv with durable-workflow PyPI package",
            "cli": "official GitHub release installer and standalone dw binary",
            "waterline": f"generated Laravel host app with durable-workflow/waterline package exposed at {metadata.get('waterline_url')} against the shared saga run database",
        },
        "book_trip_inputs": basic_payloads,
        "side_store_deltas": all_side_rows(),
        "history_dumps": report_history_dumps(results),
        "php_worker_restart_observations": PHP_WORKER_RESTART_OBSERVATIONS,
        "php_worker_ready_observations": PHP_WORKER_READY_OBSERVATIONS,
        "python_worker_restart_observations": PYTHON_WORKER_RESTART_OBSERVATIONS,
        "python_worker_ready_observations": PYTHON_WORKER_READY_OBSERVATIONS,
        "worker_restart_observations": [
            result
            for result in results
            if result.get("scenario_id") == "mid_compensation_worker_restart"
        ],
        "operator_visibility_snapshots": report_operator_visibility_snapshots(results),
        "cross_language_matrix": [
            result
            for result in results
            if result.get("scenario_id")
            in {"php_workflow_python_compensation", "python_workflow_php_compensation"}
        ],
        "typed_error_shapes": report_typed_error_shapes(results),
        "scenario_results": scenario_results,
        "observed_outputs": results,
        "linked_findings": findings,
        "findings": findings,
    }
    (RESULT_DIR / "sagas-result.json").write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    (RESULT_DIR / "sagas-record.json").write_text(
        json.dumps(
            {
                "experiment": "sagas",
                "outcome": outcome,
                "runnerBlocked": False,
                "runnerExitStatus": runner_exit_status,
                "artifactVersions": metadata["published_artifact_versions"],
                "findings": [item["summary"] for item in findings],
                "resultPath": str(RESULT_DIR / "sagas-result.json"),
            },
            indent=2,
            sort_keys=True,
        )
        + "\n",
        encoding="utf-8",
    )
    print(json.dumps(report, indent=2, sort_keys=True))
    if outcome != "pass":
        raise SystemExit(1)


if __name__ == "__main__":
    asyncio.run(main())
PY

touch "$run_root/side-store.jsonl"
export SAGA_SIDE_STORE="$run_root/side-store.jsonl"
export RUN_ROOT="$run_root"
export RESULT_DIR="$result_dir"
export STARTED_AT="$started_at"
export DW_SAGAS_SERVER_URL="$server_base_url"
export DW_SAGAS_SERVER_API_URL="$server_api_url"
export DW_SAGAS_WATERLINE_URL="$waterline_base_url"
export DW_SAGAS_PHP_WORKER_CONTAINER="$php_worker_container"

printf '%s\n' "$server_base_url" > "$result_dir/server-url.txt"
printf '%s\n' "${server_url_candidates[@]}" > "$result_dir/server-url-candidates.txt"
printf '%s\n' "$waterline_base_url" > "$result_dir/waterline-url.txt"
printf '%s\n' "${waterline_url_candidates[@]}" > "$result_dir/waterline-url-candidates.txt"
cp "$result_dir/server-url.txt" "$run_root/server-url.txt"
cp "$result_dir/server-url-candidates.txt" "$run_root/server-url-candidates.txt"
cp "$result_dir/waterline-url.txt" "$run_root/waterline-url.txt"
cp "$result_dir/waterline-url-candidates.txt" "$run_root/waterline-url-candidates.txt"

docker compose -f "$run_root/compose.yml" run --rm server server-bootstrap
docker compose -f "$run_root/compose.yml" up -d --wait

if ! wait_for_server_ready; then
  docker compose -f "$run_root/compose.yml" ps > "$result_dir/docker-compose-ps.log" 2>&1 || true
  docker compose -f "$run_root/compose.yml" logs server > "$result_dir/server.log" 2>&1 || true
  blocked_result "saga conformance server passed container startup but was not reachable from the host at any candidate endpoint listed in server-url-candidates.txt; see docker-compose-ps.log and server.log" "$started_at"
  exit 1
fi

printf '%s\n' "$server_base_url" > "$result_dir/server-url.txt"
cp "$result_dir/server-url.txt" "$run_root/server-url.txt"
update_run_metadata_server_url

if ! wait_for_waterline_ready; then
  docker compose -f "$run_root/compose.yml" ps > "$result_dir/docker-compose-ps.log" 2>&1 || true
  docker compose -f "$run_root/compose.yml" logs waterline > "$result_dir/waterline.log" 2>&1 || true
  blocked_result "published Waterline app was installed but did not become reachable against the saga run database at any candidate endpoint listed in waterline-url-candidates.txt; see waterline.log" "$started_at"
  exit 1
fi

printf '%s\n' "$waterline_base_url" > "$result_dir/waterline-url.txt"
cp "$result_dir/waterline-url.txt" "$run_root/waterline-url.txt"
update_run_metadata_waterline_url

server_queue_worker_cid="$(docker compose -f "$run_root/compose.yml" ps -q server-queue-worker)"
server_queue_worker_running="$(docker inspect -f '{{.State.Running}}' "$server_queue_worker_cid" 2>/dev/null || true)"
if [[ -z "$server_queue_worker_cid" || "$server_queue_worker_running" != "true" ]]; then
  docker compose -f "$run_root/compose.yml" logs server-queue-worker > "$result_dir/server-queue-worker.log" 2>&1 || true
  blocked_result "saga conformance server queue worker failed to start; timer-backed recovery scenarios cannot execute without server-queue-worker.log evidence" "$started_at"
  exit 1
fi

docker rm -f "$php_worker_container" >/dev/null 2>&1 || true
docker run -d --name "$php_worker_container" --network host \
  -e "SAGA_SIDE_STORE=/run-root/side-store.jsonl" \
  -e "DW_SAGAS_SERVER_API_URL=$server_api_url" \
  -v "$run_root:/run-root" \
  -v "$run_root/php-worker:/work" \
  -w /work \
  composer:2 php worker.php

# shellcheck disable=SC1091
. "$run_root/.venv/bin/activate"
python -u "$run_root/python-worker.py" > "$run_root/logs/python-worker.log" 2>&1 &
python_worker_pid=$!
export PYTHON_WORKER_PID="$python_worker_pid"

set +e
python "$run_root/orchestrate.py" > "$run_root/logs/orchestrate.log" 2>&1
orchestrate_status=$?
set -e

cp "$run_root/logs/"* "$result_dir/" 2>/dev/null || true
docker logs "$php_worker_container" > "$result_dir/php-worker.log" 2>&1 || true
docker compose -f "$run_root/compose.yml" logs server > "$result_dir/server.log" 2>&1 || true
docker compose -f "$run_root/compose.yml" logs server-queue-worker > "$result_dir/server-queue-worker.log" 2>&1 || true
docker compose -f "$run_root/compose.yml" logs waterline > "$result_dir/waterline.log" 2>&1 || true

if [[ ! -f "$result_dir/sagas-result.json" ]]; then
  blocked_result "saga conformance orchestrator exited without producing sagas-result.json; see orchestrate.log" "$started_at"
  exit 1
fi

exit "$orchestrate_status"
