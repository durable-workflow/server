#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
  cat <<'USAGE'
Usage: replay-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Runs deterministic replay conformance against published artifacts only.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  published-artifact-install.json
  python-replay-shard.json
  php-replay-shard.json
  rust-replay-shard.json
  replay-conformance-result.json
  replay-conformance-record.json

Environment overrides:
  DW_REPLAY_RUN_ROOT             Scratch directory. Defaults to mktemp.
  DW_REPLAY_RESULT_DIR           Result directory. Defaults to run root.
  DW_REPLAY_KEEP_RUN_ROOT=1      Keep scratch directory after success.
  DW_SERVER_IMAGE                Exact server image/tag/digest to test.
  DW_SERVER_VERSION              Exact server SemVer tag; required for digest-only DW_SERVER_IMAGE.
  DW_PHP_SDK_VERSION             Exact Packagist version for durable-workflow/sdk.
  DW_WORKFLOW_PHP_VERSION        Composer version for the embedded durable-workflow/workflow engine.
  DW_PYTHON_SDK_VERSION          PyPI version for durable-workflow.
  DW_RUST_SDK_VERSION            crates.io version for durable-workflow.
  DW_CLI_VERSION                 GitHub release tag for the official CLI installer.
  DW_WATERLINE_VERSION           Composer version for durable-workflow/waterline.
  DW_REPLAY_SKIP_DOCKER_PULL=1   Reuse a local server image instead of pulling.
  DW_REPLAY_SERVER_PORT          Host port for the published server. Defaults to a free port.
  DW_REPLAY_AUTH_TOKEN           Token used against the published server. Defaults to replay-token.
  DW_REPLAY_NAMESPACE            Namespace used by replay runtime cells. Defaults to replay-conformance.
USAGE
}

result_dir="${DW_REPLAY_RESULT_DIR:-}"
keep_run_root="${DW_REPLAY_KEEP_RUN_ROOT:-0}"

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

free_port() {
  python3 - <<'PY'
from __future__ import annotations

import socket

with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
    sock.bind(("127.0.0.1", 0))
    print(sock.getsockname()[1])
PY
}

wait_for_server() {
  local url="$1"

  python3 - "$url" <<'PY'
from __future__ import annotations

import sys
import time
import urllib.request

base_url = sys.argv[1].rstrip("/")
deadline = time.time() + 180
while time.time() < deadline:
    try:
        with urllib.request.urlopen(base_url + "/api/ready", timeout=5) as response:
            if 200 <= response.status < 500:
                print(f"ready {response.status}")
                raise SystemExit(0)
    except Exception as exc:
        print(f"{type(exc).__name__}: {exc}")
    time.sleep(2)

raise SystemExit(f"published server did not become ready at {base_url}/api/ready")
PY
}

require_command() {
  local name="$1"

  if ! command -v "$name" >/dev/null 2>&1; then
    if [[ "$name" == "python3" ]]; then
      blocked_result_without_python "Replay conformance runner requires missing command: $name"
    else
      blocked_result "Replay conformance runner requires missing command: $name"
    fi
    exit 1
  fi
}

tmp_parent="${DW_CONFORMANCE_TMPDIR:-${TMPDIR:-/tmp}}"
mkdir -p "$tmp_parent"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"

canonical_server_repo_root() {
  local candidate="$1"

  if [[ -n "$candidate" && -f "$candidate/docker-compose.published.yml" ]]; then
    cd "$candidate" && pwd -P
    return 0
  fi

  return 1
}

resolve_server_repo_root() {
  local candidate
  local git_root

  for candidate in "${DW_SERVER_REPO_ROOT:-}" "${SERVER_REPO_PATH:-}"; do
    if canonical_server_repo_root "$candidate"; then
      return 0
    fi
  done

  if git_root="$(git -C "$script_dir" rev-parse --show-toplevel 2>/dev/null)" \
    && canonical_server_repo_root "$git_root"; then
    return 0
  fi

  candidate="$script_dir"
  while [[ -n "$candidate" && "$candidate" != "/" ]]; do
    if canonical_server_repo_root "$candidate"; then
      return 0
    fi
    if canonical_server_repo_root "$candidate/repos/server"; then
      return 0
    fi
    candidate="$(dirname "$candidate")"
  done

  printf '%s\n' 'could not locate server checkout containing docker-compose.published.yml' >&2
  return 1
}

resolve_published_compose_file() {
  local root="$1"
  local directory

  directory="$(cd "$root" && pwd -P)"
  if [[ ! -f "$directory/docker-compose.published.yml" ]]; then
    printf 'server checkout is missing docker-compose.published.yml: %s\n' "$directory" >&2
    return 1
  fi

  printf '%s/docker-compose.published.yml\n' "$directory"
}

repo_root="$(resolve_server_repo_root)"
published_compose_file="$(resolve_published_compose_file "$repo_root")"
run_root="${DW_REPLAY_RUN_ROOT:-}"
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "$tmp_parent/dw-replay.XXXXXX")"
fi
mkdir -p "$run_root"
run_root="$(cd "$run_root" && pwd)"
run_label="$(printf '%s' "$(basename "$run_root")" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_-' '-')"
compose_project="dw-replay-${run_label}"
compose_cleanup_needed=0

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"
distribution_identity_file="$result_dir/executed-distribution-identities.json"

started_at="$(timestamp)"

cleanup() {
  local code=$?
  local cleanup_status=0
  local cleanup_reasons=()
  local cleanup_reason

  if [[ "$compose_cleanup_needed" == "1" ]]; then
    if ! docker compose -p "$compose_project" -f "$published_compose_file" down -v > "$result_dir/docker-compose-cleanup.log" 2>&1; then
      cleanup_status=1
      cleanup_reasons+=("published server compose cleanup failed; see docker-compose-cleanup.log")
    fi
  fi

  if [[ "$keep_run_root" != "1" && "$code" -eq 0 && "$result_dir" != "$run_root" && "${result_dir}/" != "${run_root}/"* ]]; then
    if ! rm -rf "$run_root" > "$result_dir/run-root-cleanup.log" 2>&1; then
      cleanup_status=1
      cleanup_reasons+=("replay run-root cleanup failed for $run_root; see run-root-cleanup.log")
      printf 'kept replay conformance run root: %s\n' "$run_root" >&2
    fi
  else
    printf 'kept replay conformance run root: %s\n' "$run_root" >&2
  fi

  if [[ "$cleanup_status" -ne 0 ]]; then
    cleanup_reason="$(IFS='; '; printf '%s' "${cleanup_reasons[*]}")"
    if command -v python3 >/dev/null 2>&1; then
      cleanup_failure_result "$cleanup_reason" "$code" || true
    fi
    exit 1
  fi

  exit "$code"
}
trap cleanup EXIT

json_escape_shell() {
  local value="$1"

  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  value="${value//$'\n'/\\n}"
  value="${value//$'\r'/\\r}"
  value="${value//$'\t'/\\t}"
  printf '%s' "$value"
}

COMPLETED_HISTORY_SCENARIOS=(
  "python_completed_history_activity_replay"
  "python_completed_history_signal_update_replay"
  "python_completed_history_wait_condition_replay"
  "python_completed_history_version_marker_replay"
  "python_completed_history_saga_compensation_replay"
  "php_completed_history_activity_replay"
  "php_completed_history_signal_update_replay"
  "php_completed_history_wait_condition_replay"
  "php_completed_history_version_marker_replay"
  "php_completed_history_saga_compensation_replay"
)
WORKER_RESTART_SCENARIOS=(
  "python_worker_restart_completed_query"
  "python_worker_restart_activity_state"
  "python_worker_restart_signal_update_state"
  "python_worker_restart_wait_condition_state"
  "python_worker_restart_version_marker_state"
  "python_worker_restart_saga_compensation_state"
  "php_worker_restart_completed_query"
  "php_worker_restart_activity_state"
  "php_worker_restart_signal_update_state"
  "php_worker_restart_wait_condition_state"
  "php_worker_restart_version_marker_state"
  "php_worker_restart_saga_compensation_state"
)
ADVERSARIAL_REPLAY_SCENARIOS=(
  "python_code_divergence_refusal"
  "php_code_divergence_refusal"
  "server_history_mutation_refusal"
  "malformed_history_refusal"
)
IN_FLIGHT_TIMING_SCENARIOS=(
  "python_in_flight_signal_restart_timing"
  "php_in_flight_signal_restart_timing"
)
RUST_REPLAY_SCENARIOS=(
  "rust_side_effect_replay_after_worker_restart"
  "rust_version_marker_replay_after_code_upgrade"
)
REPLAY_REQUIRED_SCENARIOS=(
  "published_artifact_install_only"
  "${COMPLETED_HISTORY_SCENARIOS[@]}"
  "${WORKER_RESTART_SCENARIOS[@]}"
  "${ADVERSARIAL_REPLAY_SCENARIOS[@]}"
  "${IN_FLIGHT_TIMING_SCENARIOS[@]}"
  "${RUST_REPLAY_SCENARIOS[@]}"
)

emit_shell_blocked_finding() {
  local scenario_id="$1"
  local escaped_reason="$2"

  cat <<JSON
{
        "scenario_id": "$scenario_id",
        "type": "runner_gap",
        "owning_surface": "conformance_harness",
        "summary": "$escaped_reason",
        "observed_behavior": {
          "host_environment_failure": true,
          "runner_blocked_reason": "$escaped_reason"
        },
        "expected_behavior": "replay conformance executes this required scenario against published artifacts",
        "next_acceptance_criterion": "rerun replay conformance on a host with the missing command or runtime available"
      }
JSON
}

emit_shell_blocked_scenario_results() {
  local escaped_reason="$1"
  local first=1
  local scenario

  for scenario in "${REPLAY_REQUIRED_SCENARIOS[@]}"; do
    if [[ "$first" == "1" ]]; then
      first=0
    else
      printf ',\n'
    fi
    cat <<JSON
    "$scenario": {
      "scenario_id": "$scenario",
      "status": "runner_blocked",
      "observed_outputs": {
        "host_environment_failure": true,
        "runner_blocked_reason": "$escaped_reason"
      },
      "linked_findings": [
        $(emit_shell_blocked_finding "$scenario" "$escaped_reason")
      ]
    }
JSON
  done
}

emit_shell_blocked_findings() {
  local escaped_reason="$1"
  local first=1
  local scenario

  for scenario in "${REPLAY_REQUIRED_SCENARIOS[@]}"; do
    if [[ "$first" == "1" ]]; then
      first=0
    else
      printf ',\n'
    fi
    printf '    '
    emit_shell_blocked_finding "$scenario" "$escaped_reason"
  done
}

emit_shell_blocked_finding_links() {
  local escaped_reason="$1"
  local first=1
  local scenario

  for scenario in "${REPLAY_REQUIRED_SCENARIOS[@]}"; do
    if [[ "$first" == "1" ]]; then
      first=0
    else
      printf ',\n'
    fi
    cat <<JSON
    "$scenario": [
      $(emit_shell_blocked_finding "$scenario" "$escaped_reason")
    ]
JSON
  done
}

emit_shell_json_string_array() {
  local first=1
  local value

  printf '['
  for value in "$@"; do
    if [[ "$first" == "1" ]]; then
      first=0
    else
      printf ', '
    fi
    printf '"%s"' "$value"
  done
  printf ']'
}

emit_shell_blocked_section() {
  local status="$1"
  shift

  printf '{"status": "%s", "scenarios": ' "$status"
  emit_shell_json_string_array "$@"
  printf '}'
}

blocked_result_without_python() {
  local reason="$1"
  local escaped_reason
  local now

  escaped_reason="$(json_escape_shell "$reason")"
  now="$(timestamp)"

  cat > "$result_dir/replay-conformance-result.json" <<JSON
{
  "schema": "durable-workflow.v2.replay-conformance.result",
  "schema_version": 1,
  "started_at": "$started_at",
  "finished_at": "$now",
  "generated_at": "$now",
  "outcome": "fail",
  "runner_blocked": true,
  "artifact_versions": {},
  "executed_distribution_identities": {},
  "artifact_sources": {},
  "runtime_matrix": {
    "runtimes": ["sdk-php", "sdk-python", "sdk-rust"],
    "coverage_scopes": ["sdk-php-runtime-shard", "sdk-python-runtime-shard", "sdk-rust-runtime-shard"]
  },
  "source_policy": {
    "artifact_source": "published_artifacts",
    "local_product_source_checkouts_used": false
  },
  "scenario_results": {
$(emit_shell_blocked_scenario_results "$escaped_reason")
  },
  "completed_history_replay": $(emit_shell_blocked_section runner_blocked "${COMPLETED_HISTORY_SCENARIOS[@]}"),
  "worker_restart_replay": $(emit_shell_blocked_section runner_blocked "${WORKER_RESTART_SCENARIOS[@]}"),
  "adversarial_replay": $(emit_shell_blocked_section runner_blocked "${ADVERSARIAL_REPLAY_SCENARIOS[@]}"),
  "in_flight_timing": $(emit_shell_blocked_section runner_blocked "${IN_FLIGHT_TIMING_SCENARIOS[@]}"),
  "findings": [
$(emit_shell_blocked_findings "$escaped_reason")
  ],
  "finding_links": {
$(emit_shell_blocked_finding_links "$escaped_reason")
  }
}
JSON

  cat > "$result_dir/replay-conformance-record.json" <<JSON
{
  "schema": "durable-workflow.v2.replay-conformance.record",
  "outcome": "fail",
  "runnerBlocked": true,
  "reason": "$escaped_reason",
  "artifactVersions": {},
  "generated_at": "$now"
}
JSON

  cat > "$result_dir/run-metadata.json" <<JSON
{
  "schema": "durable-workflow.v2.replay-conformance.run-metadata",
  "started_at": "$started_at",
  "finished_at": "$now",
  "runner_blocked": true,
  "runner_blocked_reason": "$escaped_reason"
}
JSON
}

blocked_result() {
  local reason="$1"
  python3 - "$result_dir" "$reason" "$started_at" "${REPLAY_REQUIRED_SCENARIOS[@]}" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path

result_dir = Path(sys.argv[1])
reason = sys.argv[2]
started_at = sys.argv[3]
required = sys.argv[4:]
now = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def load_json(path: Path) -> dict[str, object]:
    if not path.exists() or path.stat().st_size == 0:
        return {}
    try:
        value = json.loads(path.read_text(encoding="utf-8"))
    except (json.JSONDecodeError, OSError):
        return {}
    return value if isinstance(value, dict) else {}


pins = load_json(result_dir / "pins.json")
versions = dict(pins.get("artifact_versions") or {})
sources = dict(pins.get("artifact_sources") or {})
executed_distribution_identities = load_json(result_dir / "executed-distribution-identities.json")
namespace_setup = load_json(result_dir / "replay-namespace-setup.json")
observed_behavior: dict[str, object] = {"runner_blocked_reason": reason}
if namespace_setup:
    observed_behavior["namespace_setup"] = namespace_setup
finding = {
    "type": "runner_gap",
    "owning_surface": "conformance_harness",
    "summary": reason,
    "observed_behavior": observed_behavior,
    "next_acceptance_criterion": "rerun replay conformance on a host with the missing command or runtime available",
}
scenario_results = {
    scenario: {
        "scenario_id": scenario,
        "status": "runner_blocked",
        "published_artifact_versions": versions,
        "artifact_sources": sources,
        "observed_outputs": observed_behavior,
        "linked_findings": [finding],
    }
    for scenario in required
}
result = {
    "schema": "durable-workflow.v2.replay-conformance.result",
    "schema_version": 1,
    "started_at": started_at,
    "finished_at": now,
    "generated_at": now,
    "outcome": "fail",
    "runner_blocked": True,
    "artifact_versions": versions,
    "executed_distribution_identities": executed_distribution_identities,
    "artifact_sources": sources,
    "namespace_setup": namespace_setup,
    "source_policy": {
        "artifact_source": "published_artifacts",
        "local_product_source_checkouts_used": False,
    },
    "runtime_matrix": {
        "runtimes": ["sdk-php", "sdk-python", "sdk-rust"],
        "coverage_scopes": ["sdk-php-runtime-shard", "sdk-python-runtime-shard", "sdk-rust-runtime-shard"],
    },
    "scenario_results": scenario_results,
    "completed_history_replay": {"status": "runner_blocked", "scenarios": required[1:11] + required[-2:-1]},
    "worker_restart_replay": {"status": "runner_blocked", "scenarios": required[11:23] + required[-1:]},
    "adversarial_replay": {"status": "runner_blocked", "scenarios": required[23:27]},
    "in_flight_timing": {"status": "runner_blocked", "scenarios": required[27:29]},
    "findings": [finding],
    "finding_links": {scenario: [finding] for scenario in required},
}
record = {
    "schema": "durable-workflow.v2.replay-conformance.record",
    "outcome": "fail",
    "runnerBlocked": True,
    "reason": reason,
    "artifactVersions": versions,
    "generated_at": now,
}
result_dir.mkdir(parents=True, exist_ok=True)
(result_dir / "replay-conformance-result.json").write_text(
    json.dumps(result, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)
(result_dir / "replay-conformance-record.json").write_text(
    json.dumps(record, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)
(result_dir / "run-metadata.json").write_text(
    json.dumps(
        {
            "schema": "durable-workflow.v2.replay-conformance.run-metadata",
            "started_at": started_at,
            "finished_at": now,
            "runner_blocked": True,
            "runner_blocked_reason": reason,
            "namespace_setup": namespace_setup,
            "result_files": [
                name
                for name in [
                    "pins.json",
                    "executed-distribution-identities.json",
                    "replay-namespace-setup.json",
                    "replay-conformance-result.json",
                    "replay-conformance-record.json",
                ]
                if (result_dir / name).exists() or name.startswith("replay-conformance-")
            ],
        },
        indent=2,
        sort_keys=True,
    )
    + "\n",
    encoding="utf-8",
)
PY
}

capture_compose_diagnostics() {
  local prefix="${1:-docker-compose}"
  local compose_file="$published_compose_file"
  local service

  docker compose -p "$compose_project" -f "$compose_file" ps -a \
    > "$result_dir/${prefix}-ps.log" 2>&1 || true
  docker compose -p "$compose_project" -f "$compose_file" ps -a --format json \
    > "$result_dir/${prefix}-ps.json" 2> "$result_dir/${prefix}-ps-json.log" || true
  docker compose -p "$compose_project" -f "$compose_file" logs --no-color --tail=200 \
    > "$result_dir/${prefix}-logs.log" 2>&1 || true

  for service in bootstrap server mysql redis worker scheduler; do
    docker compose -p "$compose_project" -f "$compose_file" logs --no-color --tail=200 "$service" \
      > "$result_dir/${service}.log" 2>&1 || true
  done

  python3 - "$result_dir" "$compose_project" "$compose_file" "$prefix" <<'PY'
from __future__ import annotations

import json
import sys
from pathlib import Path
from typing import Any

result_dir = Path(sys.argv[1])
compose_project = sys.argv[2]
compose_file = sys.argv[3]
prefix = sys.argv[4]


def tail_text(path: Path, max_lines: int = 80, max_chars: int = 6000) -> str:
    if not path.exists():
        return ""
    text = path.read_text(encoding="utf-8", errors="replace")
    lines = text.splitlines()
    if len(lines) > max_lines:
        text = "\n".join(lines[-max_lines:])
    if len(text) > max_chars:
        text = text[-max_chars:]
    return text.strip()


def load_compose_ps(path: Path) -> list[dict[str, Any]]:
    if not path.exists() or path.stat().st_size == 0:
        return []
    raw = path.read_text(encoding="utf-8", errors="replace").strip()
    if raw == "":
        return []
    try:
        parsed = json.loads(raw)
        if isinstance(parsed, list):
            return [item for item in parsed if isinstance(item, dict)]
        if isinstance(parsed, dict):
            return [parsed]
    except json.JSONDecodeError:
        pass

    rows: list[dict[str, Any]] = []
    for line in raw.splitlines():
        line = line.strip()
        if not line:
            continue
        try:
            parsed = json.loads(line)
        except json.JSONDecodeError:
            continue
        if isinstance(parsed, dict):
            rows.append(parsed)
    return rows


service_logs = {}
for name in ["bootstrap", "server", "mysql", "redis", "worker", "scheduler"]:
    snippet = tail_text(result_dir / f"{name}.log")
    if snippet:
        service_logs[name] = {
            "file": f"{name}.log",
            "tail": snippet,
        }

diagnostics = {
    "schema": "durable-workflow.v2.replay-conformance.compose-startup-diagnostics",
    "compose_project": compose_project,
    "compose_file": Path(compose_file).name,
    "files": {
        "compose_up": f"{prefix}-up.log",
        "dependency_up": "docker-compose-dependencies-up.log",
        "server_bootstrap": "server-bootstrap.log",
        "compose_ps": f"{prefix}-ps.log",
        "compose_ps_json": f"{prefix}-ps.json",
        "compose_logs": f"{prefix}-logs.log",
    },
    "compose_up_tail": tail_text(result_dir / f"{prefix}-up.log"),
    "dependency_up_tail": tail_text(result_dir / "docker-compose-dependencies-up.log"),
    "server_bootstrap_tail": tail_text(result_dir / "server-bootstrap.log"),
    "compose_ps_tail": tail_text(result_dir / f"{prefix}-ps.log"),
    "compose_ps_json_error_tail": tail_text(result_dir / f"{prefix}-ps-json.log"),
    "compose_logs_tail": tail_text(result_dir / f"{prefix}-logs.log"),
    "service_status": load_compose_ps(result_dir / f"{prefix}-ps.json"),
    "service_logs": service_logs,
}
(result_dir / "compose-startup-diagnostics.json").write_text(
    json.dumps(diagnostics, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)
PY
}

published_server_topology_failure_result() {
  local reason="$1"
  local phase="${2:-docker_compose_up_server}"
  local server_image_value="${server_image:-}"
  local server_base_url_value="${server_base_url:-}"

  python3 - "$result_dir" "$started_at" "$reason" "$phase" "$server_image_value" "$server_base_url_value" "$compose_project" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

result_dir = Path(sys.argv[1])
started_at = sys.argv[2]
reason = sys.argv[3]
phase = sys.argv[4]
server_image = sys.argv[5]
server_base_url = sys.argv[6]
compose_project = sys.argv[7]

REQUIRED = [
    "published_artifact_install_only",
    "python_completed_history_activity_replay",
    "python_completed_history_signal_update_replay",
    "python_completed_history_wait_condition_replay",
    "python_completed_history_version_marker_replay",
    "python_completed_history_saga_compensation_replay",
    "php_completed_history_activity_replay",
    "php_completed_history_signal_update_replay",
    "php_completed_history_wait_condition_replay",
    "php_completed_history_version_marker_replay",
    "php_completed_history_saga_compensation_replay",
    "python_worker_restart_completed_query",
    "python_worker_restart_activity_state",
    "python_worker_restart_signal_update_state",
    "python_worker_restart_wait_condition_state",
    "python_worker_restart_version_marker_state",
    "python_worker_restart_saga_compensation_state",
    "php_worker_restart_completed_query",
    "php_worker_restart_activity_state",
    "php_worker_restart_signal_update_state",
    "php_worker_restart_wait_condition_state",
    "php_worker_restart_version_marker_state",
    "php_worker_restart_saga_compensation_state",
    "python_code_divergence_refusal",
    "php_code_divergence_refusal",
    "server_history_mutation_refusal",
    "malformed_history_refusal",
    "python_in_flight_signal_restart_timing",
    "php_in_flight_signal_restart_timing",
]


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def load_json(path: Path) -> dict[str, Any]:
    if not path.exists() or path.stat().st_size == 0:
        return {}
    try:
        parsed = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}
    return parsed if isinstance(parsed, dict) else {}


def section(scenarios: list[str]) -> dict[str, Any]:
    return {
        "status": "fail",
        "scenarios": scenarios,
        "scenario_statuses": {scenario: "fail" for scenario in scenarios},
        "passed": 0,
        "total": len(scenarios),
    }


pins = load_json(result_dir / "pins.json")
versions = dict(pins.get("artifact_versions") or {})
native_versions = dict(versions)
if "workflow-php" in native_versions:
    native_versions["workflow"] = native_versions.pop("workflow-php")
sources = dict(pins.get("artifact_sources") or {})
diagnostics = load_json(result_dir / "compose-startup-diagnostics.json")
finished_at = now()

finding = {
    "type": "published_server_topology_startup_failure",
    "owning_surface": "server",
    "summary": reason,
    "observed_behavior": {
        "phase": phase,
        "server_image": server_image,
        "server_url": server_base_url,
        "compose_project": compose_project,
        "diagnostics": diagnostics,
    },
    "expected_behavior": "the published server compose topology starts from the resolved Docker image before replay scenarios run",
    "next_acceptance_criterion": "publish a server image and compose topology that starts successfully, or route the failing service shown in compose-startup-diagnostics.json to its owning surface",
}
scenario_results = {}
for scenario in REQUIRED:
    scenario_results[scenario] = {
        "scenario_id": scenario,
        "status": "fail",
        "published_artifact_versions": versions,
        "artifact_sources": sources,
        "observed_outputs": {
            "blocked_before_replay_execution": True,
            "published_server_topology_started": False,
            "failure_phase": phase,
            "compose_diagnostics_file": "compose-startup-diagnostics.json",
            "compose_service_status_file": "docker-compose-ps.json",
            "compose_service_logs": diagnostics.get("service_logs", {}),
        },
        "linked_findings": [finding],
    }

result = {
    "schema": "durable-workflow.v2.replay-conformance.result",
    "schema_version": 1,
    "started_at": started_at,
    "finished_at": finished_at,
    "generated_at": finished_at,
    "outcome": "fail",
    "runner_blocked": False,
    "artifact_versions": native_versions,
    "executed_distribution_identities": {},
    "artifact_sources": sources,
    "source_policy": {
        "artifact_source": "published_artifacts",
        "local_product_source_checkouts_used": False,
    },
    "runtime_matrix": {
        "runtimes": ["sdk-php", "sdk-python", "sdk-rust"],
        "coverage_scopes": ["sdk-php-runtime-shard", "sdk-python-runtime-shard", "sdk-rust-runtime-shard"],
    },
    "scenario_results": scenario_results,
    "completed_history_replay": section(REQUIRED[1:11]),
    "worker_restart_replay": section(REQUIRED[11:23]),
    "adversarial_replay": section(REQUIRED[23:27]),
    "in_flight_timing": section(REQUIRED[27:]),
    "findings": [finding],
    "finding_links": {scenario: [finding] for scenario in REQUIRED},
}
record = {
    "schema": "durable-workflow.v2.replay-conformance.record",
    "outcome": "fail",
    "runnerBlocked": False,
    "reason": reason,
    "artifactVersions": versions,
    "started_at": started_at,
    "finished_at": finished_at,
    "result_file": "replay-conformance-result.json",
}
metadata = {
    "schema": "durable-workflow.v2.replay-conformance.run-metadata",
    "started_at": started_at,
    "finished_at": finished_at,
    "runner_blocked": False,
    "published_server_topology_started": False,
    "published_server_topology_failure": {
        "phase": phase,
        "reason": reason,
        "server_image": server_image,
        "server_url": server_base_url,
        "compose_project": compose_project,
        "diagnostics_file": "compose-startup-diagnostics.json",
    },
    "result_files": [
        "pins.json",
        "run-metadata.json",
        "compose-startup-diagnostics.json",
        "docker-compose-up.log",
        "docker-compose-dependencies-up.log",
        "server-bootstrap.log",
        "docker-compose-ps.log",
        "docker-compose-ps.json",
        "docker-compose-logs.log",
        "bootstrap.log",
        "server.log",
        "mysql.log",
        "redis.log",
        "replay-conformance-result.json",
        "replay-conformance-record.json",
    ],
}

(result_dir / "replay-conformance-result.json").write_text(
    json.dumps(result, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)
(result_dir / "replay-conformance-record.json").write_text(
    json.dumps(record, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)
(result_dir / "run-metadata.json").write_text(
    json.dumps(metadata, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)
PY
}

cleanup_failure_result() {
  local reason="$1"
  local previous_exit_code="${2:-0}"

  python3 - "$result_dir" "$started_at" "$reason" "$previous_exit_code" "$run_root" "$compose_project" "${REPLAY_REQUIRED_SCENARIOS[*]}" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

result_dir = Path(sys.argv[1])
started_at = sys.argv[2]
reason = sys.argv[3]
previous_exit_code = int(sys.argv[4])
run_root = sys.argv[5]
compose_project = sys.argv[6]
required_scenarios = sys.argv[7].split()


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def load_json(path: Path) -> dict[str, Any]:
    if not path.exists() or path.stat().st_size == 0:
        return {}
    try:
        parsed = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return {}
    return parsed if isinstance(parsed, dict) else {}


def list_value(value: Any) -> list[Any]:
    return value if isinstance(value, list) else []


finished_at = now()
result_path = result_dir / "replay-conformance-result.json"
record_path = result_dir / "replay-conformance-record.json"
metadata_path = result_dir / "run-metadata.json"
pins = load_json(result_dir / "pins.json")
result = load_json(result_path)
record = load_json(record_path)
metadata = load_json(metadata_path)
versions = dict(
    result.get("artifact_versions")
    or result.get("artifactVersions")
    or record.get("artifactVersions")
    or pins.get("artifact_versions")
    or {}
)
sources = dict(result.get("artifact_sources") or pins.get("artifact_sources") or {})
previous_outcome = str(result.get("outcome") or record.get("outcome") or "")
runner_blocked = bool(result.get("runner_blocked") or record.get("runnerBlocked"))
if previous_outcome in {"", "pass"}:
    runner_blocked = True

finding = {
    "type": "replay_runner_cleanup_failure",
    "owning_surface": "conformance_harness",
    "summary": reason,
    "observed_behavior": {
        "cleanup_failure": True,
        "previous_exit_code": previous_exit_code,
        "previous_outcome": previous_outcome or None,
        "run_root": run_root,
        "compose_project": compose_project,
        "logs": {
            "docker_compose_cleanup": "docker-compose-cleanup.log",
            "run_root_cleanup": "run-root-cleanup.log",
        },
    },
    "expected_behavior": "the replay conformance runner exits cleanly after writing passing evidence, or records cleanup failure as non-passing evidence",
    "next_acceptance_criterion": "rerun replay conformance on a host where cleanup completes, or repair the cleanup step named in this finding",
}

if not result:
    scenario_results = {
        scenario: {
            "scenario_id": scenario,
            "status": "runner_blocked",
            "observed_outputs": {
                "host_environment_failure": True,
                "runner_blocked_reason": reason,
            },
            "linked_findings": [finding],
        }
        for scenario in required_scenarios
    }
    result = {
        "schema": "durable-workflow.v2.replay-conformance.result",
        "schema_version": 1,
        "started_at": started_at,
        "artifact_versions": versions,
        "executed_distribution_identities": {},
        "artifact_sources": sources,
        "source_policy": {
            "artifact_source": "published_artifacts",
            "local_product_source_checkouts_used": False,
        },
        "scenario_results": scenario_results,
    }

findings = list_value(result.get("findings"))
findings.append(finding)
finding_links = result.get("finding_links")
if not isinstance(finding_links, dict):
    finding_links = {}
finding_links["runner_cleanup"] = [finding]

result.update({
    "finished_at": finished_at,
    "generated_at": finished_at,
    "outcome": "fail",
    "runner_blocked": runner_blocked,
    "artifact_versions": versions,
    "artifact_sources": sources,
    "findings": findings,
    "finding_links": finding_links,
    "cleanup": {
        "status": "fail",
        "reason": reason,
        "previous_exit_code": previous_exit_code,
    },
})

record.update({
    "schema": "durable-workflow.v2.replay-conformance.record",
    "outcome": "fail",
    "runnerBlocked": runner_blocked,
    "reason": reason,
    "artifactVersions": versions,
    "started_at": record.get("started_at") or result.get("started_at") or started_at,
    "finished_at": finished_at,
    "result_file": "replay-conformance-result.json",
    "cleanupFailure": {
        "reason": reason,
        "previousExitCode": previous_exit_code,
    },
})

metadata.update({
    "schema": "durable-workflow.v2.replay-conformance.run-metadata",
    "started_at": metadata.get("started_at") or result.get("started_at") or started_at,
    "finished_at": finished_at,
    "runner_blocked": runner_blocked,
    "cleanup": {
        "status": "fail",
        "reason": reason,
        "previous_exit_code": previous_exit_code,
        "run_root": run_root,
        "compose_project": compose_project,
    },
})

result_path.write_text(json.dumps(result, indent=2, sort_keys=True) + "\n", encoding="utf-8")
record_path.write_text(json.dumps(record, indent=2, sort_keys=True) + "\n", encoding="utf-8")
metadata_path.write_text(json.dumps(metadata, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
}

require_command python3
require_command curl
require_command docker
if ! docker compose version >/dev/null 2>&1; then
  blocked_result "Replay conformance runner requires docker compose to start the published server topology"
  exit 1
fi

cat > "$run_root/resolve-pins.py" <<'PY'
from __future__ import annotations

import json
import os
import re
import sys
import urllib.error
import urllib.request
from typing import Any


def env(name: str) -> str | None:
    value = os.environ.get(name)
    if value is None:
        return None
    value = value.strip()
    return value or None


def read_json(url: str) -> Any:
    request = urllib.request.Request(
        url,
        headers={"User-Agent": "durable-workflow-replay-conformance"},
    )
    with urllib.request.urlopen(request, timeout=45) as response:
        return json.loads(response.read().decode("utf-8"))


def semver_key(version: str) -> tuple[int, int, int, int, int]:
    match = re.fullmatch(r"v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)(?:-(alpha|beta|rc)\.(0|[1-9]\d*))?", version)
    if not match:
        return (-1, -1, -1, -1, -1)
    major, minor, patch, prerelease, ordinal = match.groups()
    phase = {"alpha": 0, "beta": 1, "rc": 2, None: 3}[prerelease]
    return int(major), int(minor), int(patch), phase, int(ordinal or 0)


def exact_server_tag(value: str) -> bool:
    exact = re.fullmatch(
        r"(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)\.(?:0|[1-9]\d*)"
        r"(?:-(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)"
        r"(?:\.(?:0|[1-9]\d*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*)?",
        value,
    ) is not None
    prerelease = value.partition("-")[2]
    rolling = {"latest", "current", "head", "main", "master", "dev", "snapshot", "unresolved", "placeholder"}
    return exact and not any(identifier.lower() in rolling for identifier in prerelease.split(".") if identifier)


def server_version_from_image(image: str) -> str | None:
    without_digest = image.split("@", 1)[0]
    last_path_part = without_digest.rsplit("/", 1)[-1]
    tag = last_path_part.rsplit(":", 1)[-1] if ":" in last_path_part else ""
    return tag if exact_server_tag(tag) else None


def selected_docker_tag() -> str:
    override = env("DW_SERVER_VERSION")
    image = env("DW_SERVER_IMAGE")
    if override:
        if not exact_server_tag(override):
            raise RuntimeError("DW_SERVER_VERSION must be an exact SemVer release")
        inferred = server_version_from_image(image) if image else None
        if inferred is not None and inferred != override:
            raise RuntimeError("DW_SERVER_VERSION does not match DW_SERVER_IMAGE tag")
        if image and "@" not in image and inferred is None:
            raise RuntimeError("DW_SERVER_IMAGE must use an exact SemVer tag or an image digest")
        return override
    if image:
        inferred = server_version_from_image(image)
        if inferred:
            return inferred
        if "@" in image:
            raise RuntimeError("DW_SERVER_VERSION must name the exact release for a digest-pinned server image")
        raise RuntimeError("DW_SERVER_IMAGE must use an exact SemVer tag or an image digest")
    payload = read_json("https://registry.hub.docker.com/v2/repositories/durableworkflow/server/tags?page_size=100")
    tags = [
        str(item.get("name", ""))
        for item in payload.get("results", [])
        if exact_server_tag(str(item.get("name", "")))
    ]
    if not tags:
        raise RuntimeError("could not resolve a concrete durableworkflow/server tag")
    return sorted(tags, key=semver_key)[-1]


def github_release_tag_candidates(override: str) -> list[str]:
    requested = override.strip()
    normalized = requested[1:] if requested.startswith("v") else requested
    return list(dict.fromkeys([requested, normalized, f"v{normalized}"]))


def github_release_by_tag(repo: str, tag: str) -> Any:
    return read_json(f"https://api.github.com/repos/{repo}/releases/tags/{tag}")


def github_release(repo: str, override: str | None, required_asset: str) -> tuple[str, str]:
    if override:
        release = None
        tag = ""
        for candidate in github_release_tag_candidates(override):
            try:
                release = github_release_by_tag(repo, candidate)
                tag = candidate
                break
            except urllib.error.HTTPError as exc:
                if exc.code == 404:
                    continue
                raise
        if release is None:
            raise RuntimeError(f"{repo} release {override!r} was not found")
    else:
        release = read_json(f"https://api.github.com/repos/{repo}/releases/latest")
        tag = str(release.get("tag_name", ""))
    for asset in release.get("assets", []):
        if asset.get("name") == required_asset and asset.get("browser_download_url"):
            resolved_tag = str(release.get("tag_name", tag))
            version = resolved_tag[1:] if resolved_tag.startswith("v") else resolved_tag
            return version, str(asset["browser_download_url"])
    raise RuntimeError(f"{repo} release {tag} does not expose {required_asset}")


def latest_pypi_version(package: str, override: str | None) -> str:
    if override:
        return override
    payload = read_json(f"https://pypi.org/pypi/{package}/json")
    version = str(payload.get("info", {}).get("version", ""))
    if not version:
        raise RuntimeError(f"could not resolve PyPI version for {package}")
    return version


def latest_packagist_version(package: str, override: str | None) -> str:
    if override:
        return override
    payload = read_json(f"https://repo.packagist.org/p2/{package}.json")
    versions = [
        str(item.get("version", ""))
        for item in payload.get("packages", {}).get(package, [])
        if semver_key(str(item.get("version", ""))) != (-1, -1, -1, -1, -1)
    ]
    if not versions:
        raise RuntimeError(f"could not resolve Packagist version for {package}")
    return sorted(versions, key=semver_key)[-1]


def latest_crates_io_version(package: str, override: str | None) -> str:
    if override:
        return override
    payload = read_json(f"https://crates.io/api/v1/crates/{package}")
    version = str(payload.get("crate", {}).get("newest_version", ""))
    if not version:
        raise RuntimeError(f"could not resolve crates.io version for {package}")
    return version


server_version = selected_docker_tag()
server_image = env("DW_SERVER_IMAGE") or f"durableworkflow/server:{server_version}"
cli_version, cli_install_url = github_release(
    "durable-workflow/cli",
    env("DW_CLI_VERSION"),
    "install.sh",
)
workflow_version = latest_packagist_version(
    "durable-workflow/workflow",
    env("DW_WORKFLOW_PHP_VERSION"),
)
php_sdk_version = latest_packagist_version(
    "durable-workflow/sdk",
    env("DW_PHP_SDK_VERSION"),
)
python_version = latest_pypi_version(
    "durable-workflow",
    env("DW_PYTHON_SDK_VERSION"),
)
rust_version = latest_crates_io_version(
    "durable-workflow",
    env("DW_RUST_SDK_VERSION"),
)
waterline_version = latest_packagist_version(
    "durable-workflow/waterline",
    env("DW_WATERLINE_VERSION"),
)

result = {
    "schema": "durable-workflow.v2.replay-conformance.pins",
    "server_image": server_image,
    "cli_install_url": cli_install_url,
    "artifact_versions": {
        "server": server_version,
        "cli": cli_version,
        "workflow-php": workflow_version,
        "sdk-php": php_sdk_version,
        "sdk-python": python_version,
        "sdk-rust": rust_version,
        "waterline": waterline_version,
    },
    "artifact_sources": {
        "server": "published_docker_image",
        "cli": "github_release_asset",
        "workflow-php": "packagist_package",
        "sdk-php": "packagist_package",
        "sdk-python": "pypi_package",
        "sdk-rust": "crates_io_package",
        "waterline": "packagist_package",
    },
}
json.dump(result, sys.stdout, indent=2, sort_keys=True)
print()
PY

if ! python3 "$run_root/resolve-pins.py" > "$result_dir/pins.json"; then
  blocked_result "Replay conformance runner could not resolve a complete published artifact tuple"
  exit 1
fi

server_image="$(python3 - "$result_dir/pins.json" <<'PY'
import json
import sys
from pathlib import Path
print(json.loads(Path(sys.argv[1]).read_text())["server_image"])
PY
)"

if [[ "${DW_REPLAY_SKIP_DOCKER_PULL:-0}" != "1" ]]; then
  if ! docker pull "$server_image" > "$result_dir/server-image-pull.log" 2>&1; then
    blocked_result "Replay conformance runner could not pull published server image $server_image; see server-image-pull.log"
    exit 1
  fi
fi

docker image inspect "$server_image" > "$result_dir/server-image-inspect.json" 2>&1 || true
docker image inspect --format '{{index .RepoDigests 0}}' "$server_image" > "$result_dir/server-image-digest.txt" 2>/dev/null || printf '%s\n' "$server_image" > "$result_dir/server-image-digest.txt"
if [[ ! -s "$result_dir/server-image-digest.txt" ]]; then
  printf '%s\n' "$server_image" > "$result_dir/server-image-digest.txt"
fi

cli_install_url="$(python3 - "$result_dir/pins.json" <<'PY'
import json
import sys
from pathlib import Path
print(json.loads(Path(sys.argv[1]).read_text())["cli_install_url"])
PY
)"
cli_version="$(python3 - "$result_dir/pins.json" <<'PY'
import json
import sys
from pathlib import Path
print(json.loads(Path(sys.argv[1]).read_text())["artifact_versions"]["cli"])
PY
)"
workflow_php_version="$(python3 - "$result_dir/pins.json" <<'PY'
import json
import sys
from pathlib import Path
print(json.loads(Path(sys.argv[1]).read_text())["artifact_versions"]["workflow-php"])
PY
)"
php_sdk_version="$(python3 - "$result_dir/pins.json" <<'PY'
import json
import sys
from pathlib import Path
print(json.loads(Path(sys.argv[1]).read_text())["artifact_versions"]["sdk-php"])
PY
)"
python_sdk_version="$(python3 - "$result_dir/pins.json" <<'PY'
import json
import sys
from pathlib import Path
print(json.loads(Path(sys.argv[1]).read_text())["artifact_versions"]["sdk-python"])
PY
)"
rust_sdk_version="$(python3 - "$result_dir/pins.json" <<'PY'
import json
import sys
from pathlib import Path
print(json.loads(Path(sys.argv[1]).read_text())["artifact_versions"]["sdk-rust"])
PY
)"
waterline_version="$(python3 - "$result_dir/pins.json" <<'PY'
import json
import sys
from pathlib import Path
print(json.loads(Path(sys.argv[1]).read_text())["artifact_versions"]["waterline"])
PY
)"
server_version="$(python3 - "$result_dir/pins.json" <<'PY'
import json
import sys
from pathlib import Path
print(json.loads(Path(sys.argv[1]).read_text())["artifact_versions"]["server"])
PY
)"

# Keep scratch Composer apps removable by the host after containers exit.
container_user="$(id -u):$(id -g)"
waterline_composer_cache="$run_root/waterline-composer-cache"
mkdir -p "$waterline_composer_cache"
composer_env_args=(
  --user "$container_user"
  -e COMPOSER_HOME=/tmp/composer
  -e COMPOSER_CACHE_DIR=/tmp/composer-cache
  -e HOME=/tmp
  -v "$waterline_composer_cache:/tmp/composer-cache"
)

server_executed_digest="$(<"$result_dir/server-image-digest.txt")"
if ! python3 "$script_dir/distribution_identities.py" record-digest \
  "$distribution_identity_file" server "$server_version" manifest "${server_executed_digest##*@}"; then
  printf '%s\n' 'Replay conformance could not derive the pulled server manifest identity.' \
    > "$result_dir/server-distribution-identity.log"
fi

mkdir -p "$run_root/cli/bin"
if ! curl -fsSL "$cli_install_url" -o "$run_root/cli/install.sh"; then
  blocked_result "Replay conformance runner could not download the official CLI install asset"
  exit 1
fi
if ! python3 "$script_dir/distribution_identities.py" record-file \
  "$distribution_identity_file" cli "$cli_version" "$run_root/cli/install.sh" \
  --artifact-name install.sh; then
  printf '%s\n' 'Replay conformance could not identify the executed CLI installer bytes.' \
    > "$result_dir/cli-distribution-identity.log"
fi
cp "$run_root/cli/install.sh" "$result_dir/dw-install.sh"
chmod +x "$run_root/cli/install.sh" "$result_dir/dw-install.sh"
if ! PATH="$run_root/cli/bin${PATH:+:$PATH}" \
  VERSION="$cli_version" \
  DURABLE_WORKFLOW_INSTALL_DIR="$run_root/cli/bin" \
  DURABLE_WORKFLOW_BIN_NAME=dw \
  sh "$run_root/cli/install.sh" > "$result_dir/cli-install.log" 2>&1; then
  blocked_result "Replay conformance runner could not install the published CLI release $cli_version; see cli-install.log"
  exit 1
fi
dw_bin="$run_root/cli/bin/dw"
if [[ ! -x "$dw_bin" ]]; then
  blocked_result "Replay conformance runner installed CLI release $cli_version but no executable dw binary was created"
  exit 1
fi
if ! "$dw_bin" --version > "$result_dir/cli-version.log" 2>&1; then
  blocked_result "Replay conformance runner installed CLI release $cli_version but dw --version failed; see cli-version.log"
  exit 1
fi

auth_token="${DW_REPLAY_AUTH_TOKEN:-replay-token}"
replay_namespace="${DW_REPLAY_NAMESPACE:-replay-conformance}"
server_port="${DW_REPLAY_SERVER_PORT:-$(free_port)}"
server_base_url="http://127.0.0.1:${server_port}"
compose_cleanup_needed=1
if ! SERVER_PORT="$server_port" \
  DW_SERVER_IMAGE="$server_image" \
  DW_SERVER_TAG="$server_version" \
  DW_AUTH_TOKEN="$auth_token" \
  DW_WORKER_POLL_TIMEOUT="${DW_REPLAY_WORKER_POLL_TIMEOUT:-1}" \
  DW_WORKER_POLL_INTERVAL_MS="${DW_REPLAY_WORKER_POLL_INTERVAL_MS:-100}" \
  docker compose -p "$compose_project" -f "$published_compose_file" up -d mysql redis > "$result_dir/docker-compose-dependencies-up.log" 2>&1; then
  capture_compose_diagnostics docker-compose
  published_server_topology_failure_result "Replay conformance runner could not start the published server dependencies; see docker-compose-dependencies-up.log, docker-compose-ps.log, compose-startup-diagnostics.json, and service logs." "docker_compose_up_dependencies"
  exit 1
fi
if ! SERVER_PORT="$server_port" \
  DW_SERVER_IMAGE="$server_image" \
  DW_SERVER_TAG="$server_version" \
  DW_AUTH_TOKEN="$auth_token" \
  DW_WORKER_POLL_TIMEOUT="${DW_REPLAY_WORKER_POLL_TIMEOUT:-1}" \
  DW_WORKER_POLL_INTERVAL_MS="${DW_REPLAY_WORKER_POLL_INTERVAL_MS:-100}" \
  docker compose -p "$compose_project" -f "$published_compose_file" run --rm bootstrap > "$result_dir/server-bootstrap.log" 2>&1; then
  capture_compose_diagnostics docker-compose
  published_server_topology_failure_result "Replay conformance runner could not bootstrap the published server topology; see server-bootstrap.log, docker-compose-ps.log, compose-startup-diagnostics.json, and service logs." "server_bootstrap"
  exit 1
fi
if ! SERVER_PORT="$server_port" \
  DW_SERVER_IMAGE="$server_image" \
  DW_SERVER_TAG="$server_version" \
  DW_AUTH_TOKEN="$auth_token" \
  DW_WORKER_POLL_TIMEOUT="${DW_REPLAY_WORKER_POLL_TIMEOUT:-1}" \
  DW_WORKER_POLL_INTERVAL_MS="${DW_REPLAY_WORKER_POLL_INTERVAL_MS:-100}" \
  docker compose -p "$compose_project" -f "$published_compose_file" up -d --no-deps server > "$result_dir/docker-compose-up.log" 2>&1; then
  capture_compose_diagnostics docker-compose
  published_server_topology_failure_result "Replay conformance runner could not start the published server HTTP service; see docker-compose-up.log, docker-compose-ps.log, compose-startup-diagnostics.json, and service logs." "docker_compose_up_server"
  exit 1
fi
if ! wait_for_server "$server_base_url" > "$result_dir/server-ready.log" 2>&1; then
  capture_compose_diagnostics docker-compose
  published_server_topology_failure_result "Replay conformance runner started $server_image but it did not become ready; see server-ready.log, docker-compose-ps.log, compose-startup-diagnostics.json, and service logs." "server_ready_probe"
  exit 1
fi
if ! python3 - "$server_base_url" "$auth_token" "$replay_namespace" "$result_dir/replay-namespace-setup.json" "$compose_project" <<'PY' > "$result_dir/replay-namespace-setup.log" 2>&1
from __future__ import annotations

import json
import sys
import urllib.error
import urllib.request
from pathlib import Path
from typing import Any

base_url = sys.argv[1].rstrip("/")
token = sys.argv[2]
replay_namespace = sys.argv[3]
output = Path(sys.argv[4])
compose_project = sys.argv[5]
selected_namespaces = list(dict.fromkeys(["default", replay_namespace]))


def bounded_response(response: Any) -> tuple[dict[str, Any] | None, str, bool]:
    raw = response.read(4097)
    truncated = len(raw) > 4096
    text = raw[:4096].decode("utf-8", errors="replace")
    if token:
        text = text.replace(token, "[REDACTED]")
    payload = None
    if not truncated:
        try:
            decoded = json.loads(text)
            payload = decoded if isinstance(decoded, dict) else None
        except json.JSONDecodeError:
            pass
    return payload, text, truncated


def provision(namespace: str) -> dict[str, Any]:
    request = urllib.request.Request(
        base_url + "/api/namespaces",
        method="POST",
        data=json.dumps({
            "name": namespace,
            "description": "Published replay conformance namespace",
            "retention_days": 1,
        }).encode("utf-8"),
        headers={
            "Accept": "application/json",
            "Authorization": f"Bearer {token}",
            "Content-Type": "application/json",
            "X-Durable-Workflow-Control-Plane-Version": "2",
            "X-Namespace": "default",
        },
    )
    try:
        with urllib.request.urlopen(request, timeout=15) as response:
            status_code = response.status
            payload, excerpt, truncated = bounded_response(response)
    except urllib.error.HTTPError as exc:
        status_code = exc.code
        payload, excerpt, truncated = bounded_response(exc)
    except (OSError, TimeoutError, urllib.error.URLError) as exc:
        excerpt = str(exc)
        if token:
            excerpt = excerpt.replace(token, "[REDACTED]")
        return {
            "namespace": namespace,
            "status": "fail",
            "server_response": {
                "status_code": None,
                "exception_type": type(exc).__name__,
                "body_excerpt": excerpt[:4096],
                "truncated": len(excerpt) > 4096,
            },
        }

    reason = payload.get("reason") if payload else None
    response_namespace = payload.get("namespace") if payload else None
    created = status_code == 201 and payload is not None and payload.get("name") == namespace
    already_exists = (
        status_code == 409
        and reason == "namespace_already_exists"
        and response_namespace == namespace
    )
    return {
        "namespace": namespace,
        "status": "pass" if created or already_exists else "fail",
        "establishment": "created" if created else "already_exists" if already_exists else "rejected",
        "server_response": {
            "status_code": status_code,
            "reason": reason,
            "payload": payload,
            "body_excerpt": excerpt,
            "truncated": truncated,
        },
    }


requests = [provision(namespace) for namespace in selected_namespaces]
passed = all(item["status"] == "pass" for item in requests)
evidence = {
    "schema": "durable-workflow.v2.replay-conformance.namespace-setup",
    "outcome": "pass" if passed else "fail",
    "selected_namespace": replay_namespace,
    "selected_namespaces": selected_namespaces,
    "runtime_namespaces": {
        "sdk-php": replay_namespace,
        "sdk-python": None,
        "sdk-rust": "default",
    },
    "server_url": base_url,
    "isolation": {
        "compose_project": compose_project,
        "fresh_published_server_topology": True,
    },
    "requests": requests,
}
output.write_text(json.dumps(evidence, indent=2, sort_keys=True) + "\n", encoding="utf-8")
print(json.dumps(evidence, sort_keys=True))
raise SystemExit(0 if passed else 1)
PY
then
  blocked_result "Replay conformance runner could not establish selected namespace [$replay_namespace]; see replay-namespace-setup.json and replay-namespace-setup.log"
  exit 1
fi
if ! python3 - "$server_base_url" "$auth_token" "$result_dir/server-cluster-info.json" <<'PY' > "$result_dir/server-cluster-info.log" 2>&1
from __future__ import annotations

import json
import sys
import urllib.request
from pathlib import Path

base_url = sys.argv[1].rstrip("/")
token = sys.argv[2]
output = Path(sys.argv[3])
request = urllib.request.Request(
    base_url + "/api/cluster/info",
    headers={
        "Accept": "application/json",
        "Authorization": f"Bearer {token}",
        "X-Namespace": "default",
    },
)
with urllib.request.urlopen(request, timeout=15) as response:
    payload = json.loads(response.read().decode("utf-8"))

if not isinstance(payload.get("replay_verification_contract"), dict):
    raise SystemExit("GET /api/cluster/info did not expose replay_verification_contract")

output.write_text(json.dumps(payload, indent=2, sort_keys=True) + "\n", encoding="utf-8")
print("cluster info replay_verification_contract exposed")
PY
then
  blocked_result "Replay conformance runner could not verify the published server replay contract; see server-cluster-info.log"
  exit 1
fi
if ! "$dw_bin" server:health --server "$server_base_url" --token "$auth_token" --output=json > "$result_dir/cli-server-health.json" 2> "$result_dir/cli-server-health.log"; then
  blocked_result "Replay conformance runner installed the published CLI but dw server:health could not reach the published server; see cli-server-health.log"
  exit 1
fi

if ! python3 -m venv "$run_root/python-venv"; then
  blocked_result "Replay conformance runner could not create a Python virtual environment"
  exit 1
fi
# shellcheck disable=SC1091
. "$run_root/python-venv/bin/activate"
set +e
python -m pip install --upgrade pip > "$result_dir/python-pip-upgrade.log" 2>&1
python_pip_status=$?
mkdir -p "$run_root/python-distributions"
python -m pip download --no-deps --dest "$run_root/python-distributions" \
  "durable-workflow==${python_sdk_version}" > "$result_dir/python-download.log" 2>&1
python_download_status=$?
if [[ "$python_download_status" -eq 0 ]]; then
  python3 "$script_dir/distribution_identities.py" record-unique \
    "$distribution_identity_file" sdk-python "$python_sdk_version" \
    "$run_root/python-distributions" '*' || python_download_status=1
fi
python_distribution="$(find "$run_root/python-distributions" -maxdepth 1 -type f -print -quit)"
if [[ "$python_download_status" -eq 0 && -n "$python_distribution" ]]; then
  python -m pip install "$python_distribution" > "$result_dir/python-install.log" 2>&1
  python_install_status=$?
else
  printf '%s\n' 'Python SDK distribution download or identity capture failed.' > "$result_dir/python-install.log"
  python_install_status=1
fi
python - <<'PY' > "$result_dir/python-import-probe.json" 2> "$result_dir/python-import-probe.log"
from __future__ import annotations

import importlib.metadata as metadata
import json

import durable_workflow

print(json.dumps({
    "status": "pass",
    "package": "durable-workflow",
    "import_name": durable_workflow.__name__,
    "version": metadata.version("durable-workflow"),
}, sort_keys=True))
PY
python_probe_status=$?
set -e

artifact_args=()
while IFS='=' read -r key value; do
  artifact_args+=(--artifact-version="${key}=${value}")
done < <(python3 - "$result_dir/pins.json" <<'PY'
import json
import sys
from pathlib import Path
versions = json.loads(Path(sys.argv[1]).read_text())["artifact_versions"]
for key, value in versions.items():
    print(f"{key}={value}")
PY
)
while IFS='=' read -r key value; do
  artifact_args+=(--artifact-source="${key}=${value}")
done < <(python3 - "$result_dir/pins.json" <<'PY'
import json
import sys
from pathlib import Path
sources = json.loads(Path(sys.argv[1]).read_text())["artifact_sources"]
for key, value in sources.items():
    print(f"{key}={value}")
PY
)

cat > "$run_root/write-runtime-surface-report.py" <<'PY'
from __future__ import annotations

import json
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

runtime = sys.argv[1]
status = sys.argv[2]
reason = sys.argv[3]
output = Path(sys.argv[4])
pins_path = Path(sys.argv[5])
pins = json.loads(pins_path.read_text(encoding="utf-8"))
versions = pins.get("artifact_versions") or {}
sources = pins.get("artifact_sources") or {}

scenario_ids = {
    "sdk-python": [
        "python_completed_history_activity_replay",
        "python_completed_history_signal_update_replay",
        "python_completed_history_wait_condition_replay",
        "python_completed_history_version_marker_replay",
        "python_completed_history_saga_compensation_replay",
        "python_worker_restart_completed_query",
        "python_worker_restart_activity_state",
        "python_worker_restart_signal_update_state",
        "python_worker_restart_wait_condition_state",
        "python_worker_restart_version_marker_state",
        "python_worker_restart_saga_compensation_state",
        "python_code_divergence_refusal",
        "python_in_flight_signal_restart_timing",
    ],
    "sdk-php": [
        "php_completed_history_activity_replay",
        "php_completed_history_signal_update_replay",
        "php_completed_history_wait_condition_replay",
        "php_completed_history_version_marker_replay",
        "php_completed_history_saga_compensation_replay",
        "php_worker_restart_completed_query",
        "php_worker_restart_activity_state",
        "php_worker_restart_signal_update_state",
        "php_worker_restart_wait_condition_state",
        "php_worker_restart_version_marker_state",
        "php_worker_restart_saga_compensation_state",
        "php_code_divergence_refusal",
        "php_in_flight_signal_restart_timing",
    ],
    "sdk-rust": [
        "rust_side_effect_replay_after_worker_restart",
        "rust_version_marker_replay_after_code_upgrade",
    ],
}[runtime]
scope = {
    "sdk-python": "sdk-python-runtime-shard",
    "sdk-php": "sdk-php-runtime-shard",
    "sdk-rust": "sdk-rust-runtime-shard",
}[runtime]
finding_type = "unsupported_public_surface" if status == "unsupported" else "replay_conformance_failure"
owner = runtime
finding = {
    "type": finding_type,
    "owning_surface": owner,
    "summary": reason,
    "observed_behavior": {
        "runtime": runtime,
        "surface_status": status,
        "surface_probe": reason,
    },
    "expected_behavior": "published runtime exposes the replay conformance surface required by the host runner contract",
    "next_acceptance_criterion": "publish the runtime replay conformance surface or route the unsupported public surface to its owning package",
}
now = datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")
scenario_results: list[dict[str, Any]] = []
for scenario_id in scenario_ids:
    scenario_results.append({
        "scenario_id": scenario_id,
        "status": status,
        "published_artifact_versions": versions,
        "implementation_identity": {
            "runtime": runtime,
            "package": "durable-workflow/workflow" if runtime == "workflow-php" else "durable-workflow",
            "version": versions.get(runtime),
        },
        "runtime_matrix": {"runtimes": [runtime]},
        "observed_outputs": {
            "surface_status": status,
            "surface_probe": reason,
        },
        "linked_findings": [finding],
    })

report = {
    "schema": "durable-workflow.v2.replay-conformance.result",
    "schema_version": 1,
    "coverage_scope": scope,
    "outcome": "fail",
    "started_at": now,
    "finished_at": now,
    "artifact_versions": versions,
    "artifact_sources": sources,
    "runtime_matrix": {"runtimes": [runtime]},
    "scenario_results": scenario_results,
    "findings": [finding],
    "finding_links": {scenario["scenario_id"]: [finding] for scenario in scenario_results},
}
output.write_text(json.dumps(report, indent=2, sort_keys=True) + "\n", encoding="utf-8")
print(json.dumps({"runtime": runtime, "status": status, "reason": reason}, sort_keys=True))
PY

if [[ "$python_pip_status" -eq 0 && "$python_install_status" -eq 0 && "$python_probe_status" -eq 0 ]]; then
  if ! command -v durable-workflow-replay-conformance >/dev/null 2>&1; then
    python3 "$run_root/write-runtime-surface-report.py" \
      sdk-python \
      unsupported \
      "Published durable-workflow==${python_sdk_version} does not expose durable-workflow-replay-conformance." \
      "$result_dir/python-replay-shard.json" \
      "$result_dir/pins.json" \
      > "$result_dir/python-replay-surface.json"
    python_shard_status=1
  else
    python3 - <<PY > "$result_dir/python-replay-surface.json"
import json
print(json.dumps({"runtime": "sdk-python", "status": "available", "command": "durable-workflow-replay-conformance", "version": "${python_sdk_version}"}, sort_keys=True))
PY
    set +e
    durable-workflow-replay-conformance --json \
      "${artifact_args[@]}" \
      --output "$result_dir/python-replay-shard.json" \
      > "$result_dir/python-replay-shard.log" 2>&1
    python_shard_status=$?
    set -e
    if [[ ! -s "$result_dir/python-replay-shard.json" ]]; then
      python3 "$run_root/write-runtime-surface-report.py" \
        sdk-python \
        fail \
        "Published durable-workflow==${python_sdk_version} exposed durable-workflow-replay-conformance but it did not emit a shard report; see python-replay-shard.log." \
        "$result_dir/python-replay-shard.json" \
        "$result_dir/pins.json" \
        > "$result_dir/python-replay-surface.json"
      python_shard_status=1
    fi
  fi
else
  python_shard_status=1
  printf '%s\n' 'Python replay shard install failed; see python-pip-upgrade.log, python-install.log, and python-import-probe.log.' > "$result_dir/python-replay-shard.log"
  python3 "$run_root/write-runtime-surface-report.py" \
    sdk-python \
    fail \
    "Published durable-workflow==${python_sdk_version} could not be installed and imported by the replay runner." \
    "$result_dir/python-replay-shard.json" \
    "$result_dir/pins.json" \
    > "$result_dir/python-replay-surface.json"
fi

deactivate || true

rust_root="$run_root/rust-sdk"
mkdir -p "$rust_root"
set +e
docker run --rm \
  --user "$container_user" \
  -e CARGO_HOME=/cargo \
  -e HOME=/tmp \
  -v "$rust_root:/cargo" \
  rust:1.86 \
  cargo install durable-workflow --version "$rust_sdk_version" --locked --root /cargo \
  > "$result_dir/rust-cargo-install.log" 2>&1
rust_install_status=$?
set -e
if [[ "$rust_install_status" -eq 0 ]]; then
  python3 "$script_dir/distribution_identities.py" record-unique \
    "$distribution_identity_file" sdk-rust "$rust_sdk_version" \
    "$rust_root/registry/cache" "**/durable-workflow-${rust_sdk_version}.crate" \
    || printf '%s\n' 'Rust SDK crate bytes could not be identified.' \
      > "$result_dir/rust-distribution-identity.log"
fi
if [[ "$rust_install_status" -eq 0 && -x "$rust_root/bin/durable-workflow-replay-conformance" ]]; then
  set +e
  docker run --rm --network host \
    --user "$container_user" \
    -e HOME=/tmp \
    -v "$rust_root:/cargo:ro" \
    -v "$result_dir:/result" \
    rust:1.86 \
    /cargo/bin/durable-workflow-replay-conformance \
      --server-url "$server_base_url" \
      --token "$auth_token" \
      --output /result/rust-replay-shard.json \
      "${artifact_args[@]}" \
    > "$result_dir/rust-replay-shard.log" 2>&1
  rust_shard_status=$?
  set -e
  if [[ ! -s "$result_dir/rust-replay-shard.json" ]]; then
    rust_shard_status=1
    python3 "$run_root/write-runtime-surface-report.py" \
      sdk-rust fail \
      "Published durable-workflow@${rust_sdk_version} did not emit a Rust replay shard; see rust-replay-shard.log." \
      "$result_dir/rust-replay-shard.json" \
      "$result_dir/pins.json" \
      > "$result_dir/rust-replay-surface.json"
  fi
else
  rust_shard_status=1
  python3 "$run_root/write-runtime-surface-report.py" \
    sdk-rust unsupported \
    "Published durable-workflow@${rust_sdk_version} does not expose an installable durable-workflow-replay-conformance binary." \
    "$result_dir/rust-replay-shard.json" \
    "$result_dir/pins.json" \
    > "$result_dir/rust-replay-surface.json"
fi

waterline_app="$run_root/waterline-app"
mkdir -p "$waterline_app"
set +e
docker run --rm "${composer_env_args[@]}" -v "$waterline_app:/app" -w /app composer:2 \
  composer require --no-interaction --no-progress \
    "durable-workflow/waterline:${waterline_version}@beta" \
    "durable-workflow/workflow:${workflow_php_version}@beta" \
    "durable-workflow/sdk:${php_sdk_version}@beta" \
  > "$result_dir/waterline-composer-install.log" 2>&1
waterline_install_status=$?
set -e
cat > "$run_root/waterline-probe.php" <<'PHP'
<?php
declare(strict_types=1);

require '/app/vendor/autoload.php';

$classes = [
    \Waterline\Waterline::class,
    \Waterline\WaterlineServiceProvider::class,
    \Waterline\Support\WorkflowPackageApiFloor::class,
];
$missing = [];
foreach ($classes as $class) {
    if (! class_exists($class)) {
        $missing[] = $class;
    }
}

$apiFloorMissing = class_exists(\Waterline\Support\WorkflowPackageApiFloor::class)
    ? \Waterline\Support\WorkflowPackageApiFloor::findMissing()
    : ['Waterline\Support\WorkflowPackageApiFloor'];

echo json_encode([
    'status' => $missing === [] ? 'pass' : 'fail',
    'package' => 'durable-workflow/waterline',
    'classes_checked' => $classes,
    'missing_classes' => $missing,
    'workflow_package_api_floor_missing' => $apiFloorMissing,
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), PHP_EOL;

exit($missing === [] ? 0 : 1);
PHP
if [[ "$waterline_install_status" -eq 0 ]]; then
  set +e
  docker run --rm \
    "${composer_env_args[@]}" \
    -v "$waterline_app:/app" \
    -v "$run_root/waterline-probe.php:/probe.php:ro" \
    -w /app \
    composer:2 php /probe.php \
    > "$result_dir/waterline-probe.json" 2> "$result_dir/waterline-probe.log"
  waterline_probe_status=$?
  set -e
else
  waterline_probe_status=1
  printf '%s\n' 'Waterline Composer install failed; see waterline-composer-install.log.' > "$result_dir/waterline-probe.log"
fi

if [[ "$waterline_install_status" -ne 0 || "$waterline_probe_status" -ne 0 ]]; then
  blocked_result "Replay conformance runner could not install and probe published Waterline ${waterline_version}; see waterline-composer-install.log and waterline-probe.log"
  exit 1
fi
if ! python3 "$script_dir/distribution_identities.py" record-unique \
  "$distribution_identity_file" workflow "$workflow_php_version" \
  "$waterline_composer_cache/files/durable-workflow/workflow" '**/*' \
  --artifact-name durable-workflow/workflow; then
  printf '%s\n' 'Workflow Composer archive bytes could not be identified.' \
    > "$result_dir/workflow-distribution-identity.log"
fi
if ! python3 "$script_dir/distribution_identities.py" record-unique \
  "$distribution_identity_file" waterline "$waterline_version" \
  "$waterline_composer_cache/files/durable-workflow/waterline" '**/*' \
  --artifact-name durable-workflow/waterline; then
  printf '%s\n' 'Waterline Composer archive bytes could not be identified.' \
    > "$result_dir/waterline-distribution-identity.log"
fi

php_sdk_probe_dir="$result_dir/php-sdk-replay-probe"
mkdir -p "$php_sdk_probe_dir"
set +e
docker run --rm --network host \
  -e DW_PHP_SDK_VERSION="$php_sdk_version" \
  -e DW_SERVER_VERSION="$server_version" \
  -e DW_SERVER_IMAGE="$server_image" \
  -e DW_PHP_SDK_CONFORMANCE_SERVER_URL="$server_base_url" \
  -e DW_PHP_SDK_CONFORMANCE_TOKEN="$auth_token" \
  -e DW_PHP_SDK_CONFORMANCE_NAMESPACE="$replay_namespace" \
  -e DW_PHP_SDK_CONFORMANCE_REPLAY_MATRIX=1 \
  -v "$php_sdk_probe_dir:/result" \
  "$server_image" scripts/conformance/php-sdk-published-artifacts.sh --result-dir /result \
  > "$result_dir/php-replay-shard.log" 2>&1
php_sdk_cell_status=$?
set -e
if [[ -d "$php_sdk_probe_dir/composer-cache/files/durable-workflow/sdk" ]]; then
  python3 "$script_dir/distribution_identities.py" record-unique \
    "$distribution_identity_file" sdk-php "$php_sdk_version" \
    "$php_sdk_probe_dir/composer-cache/files/durable-workflow/sdk" '**/*' \
    --artifact-name durable-workflow/sdk \
    || printf '%s\n' 'PHP SDK Composer archive bytes could not be identified.' \
      > "$result_dir/php-sdk-distribution-identity.log"
fi

php_sdk_result="$php_sdk_probe_dir/php-sdk-conformance-result.json"
php_shard_status=1
if [[ -s "$php_sdk_result" ]]; then
  set +e
  python3 - "$php_sdk_result" "$result_dir/php-replay-shard.json" "$result_dir/php-replay-surface.json" "$result_dir/pins.json" "$script_dir" <<'PY'
from __future__ import annotations

import hashlib
import json
import sys
from pathlib import Path
from typing import Any

sys.path.insert(0, sys.argv[5])
from php_sdk_replay_scenarios import expand_php_replay_scenarios

source_path = Path(sys.argv[1])
shard_path = Path(sys.argv[2])
surface_path = Path(sys.argv[3])
pins = json.loads(Path(sys.argv[4]).read_text(encoding="utf-8"))
source_bytes = source_path.read_bytes()
source = json.loads(source_bytes)
assertions = source.get("assertions") if isinstance(source.get("assertions"), dict) else {}
source_document = {
    "bytes": len(source_bytes),
    "sha256": hashlib.sha256(source_bytes).hexdigest(),
}

install_pass = (
    source.get("local_product_source_checkouts_used") is False
    and (source.get("artifact_versions") or {}).get("sdk-php") == (pins.get("artifact_versions") or {}).get("sdk-php")
    and bool(source.get("package_provenance"))
    and bool(source.get("apache_avro_provenance"))
)
def scenario(scenario_id: str, passed: bool, required: list[str]) -> dict[str, Any]:
    observed = {
        "sdk_php_result_summary": {
            "schema": source.get("schema"),
            "outcome": source.get("outcome"),
            "runner_blocked": source.get("runner_blocked"),
            "artifact_versions": source.get("artifact_versions") or {},
            "package_provenance": source.get("package_provenance") or {},
            "apache_avro_provenance": source.get("apache_avro_provenance") or {},
            "assertions": assertions,
            "source_document": source_document,
        },
        "required_assertions": {name: assertions.get(name) for name in required},
        "local_product_source_checkouts_used": False,
    }
    return {
        "scenario_id": scenario_id,
        "status": "pass" if passed else "fail",
        "observed_outputs": observed,
        "linked_findings": [] if passed else list(source.get("findings") or []),
    }

scenario_results = {
    "published_artifact_install_only": scenario(
        "published_artifact_install_only",
        install_pass,
        ["exact_sdk_version", "sdk_dist_provenance", "apache_avro_dependency"],
    ),
}
expanded_scenarios, expanded_scenario_contract = expand_php_replay_scenarios(source)
scenario_results.update(expanded_scenarios)
shard = {
    "schema": "durable-workflow.v2.replay-conformance.sdk-php-shard",
    "runtime": "sdk-php",
    "outcome": "pass" if all(item["status"] == "pass" for item in scenario_results.values()) else "fail",
    "runner_blocked": bool(source.get("runner_blocked")),
    "artifact_versions": pins.get("artifact_versions") or {},
    "artifact_sources": pins.get("artifact_sources") or {},
    "source_policy": {
        "artifact_source": "published_artifacts",
        "local_product_source_checkouts_used": False,
    },
    "scenario_results": scenario_results,
    "expanded_scenario_contract": expanded_scenario_contract,
    "process_boundary": source.get("process_boundary") or {},
    "package_provenance": source.get("package_provenance") or {},
    "apache_avro_provenance": source.get("apache_avro_provenance") or {},
    "callback_counts": source.get("callback_counts") or {},
    "activity_callback_cardinality": source.get("activity_callback_cardinality") or {},
    "history_assertions": source.get("history_assertions") or {},
    "findings": source.get("findings") or [],
}
surface = {
    "runtime": "sdk-php",
    "status": "available",
    "command": "scripts/conformance/php-sdk-published-artifacts.sh",
    "version": (pins.get("artifact_versions") or {}).get("sdk-php"),
    "source_free": source.get("local_product_source_checkouts_used") is False,
}
shard_path.write_text(json.dumps(shard, indent=2, sort_keys=True) + "\n", encoding="utf-8")
surface_path.write_text(json.dumps(surface, indent=2, sort_keys=True) + "\n", encoding="utf-8")
PY
  php_shard_status=$?
  set -e
fi

if [[ "$php_shard_status" -ne 0 ]]; then
  python3 "$run_root/write-runtime-surface-report.py" \
    sdk-php \
    fail \
    "The exact Packagist durable-workflow/sdk ${php_sdk_version} replay cell did not emit a reusable shard; see php-replay-shard.log." \
    "$result_dir/php-replay-shard.json" \
    "$result_dir/pins.json" \
    > "$result_dir/php-replay-surface.json"
fi

python3 - "$result_dir" "$result_dir/pins.json" "$server_image" "$server_base_url" "$auth_token" "$dw_bin" "$result_dir/server-image-digest.txt" "$python_pip_status" "$python_install_status" "$python_probe_status" "$php_sdk_cell_status" "$php_shard_status" "$rust_install_status" "$rust_shard_status" <<'PY'
from __future__ import annotations

import hashlib
import json
import sys
from pathlib import Path

result_dir = Path(sys.argv[1])
pins = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
server_image = sys.argv[3]
server_base_url = sys.argv[4]
auth_token = sys.argv[5]
dw_bin = sys.argv[6]
server_digest = Path(sys.argv[7]).read_text(encoding="utf-8").strip()
python_pip_status = int(sys.argv[8])
python_install_status = int(sys.argv[9])
python_probe_status = int(sys.argv[10])
php_sdk_cell_status = int(sys.argv[11])
php_shard_status = int(sys.argv[12])
rust_install_status = int(sys.argv[13])
rust_shard_status = int(sys.argv[14])

def load(path: str) -> object:
    file = result_dir / path
    if not file.exists() or file.stat().st_size == 0:
        return None
    try:
        return json.loads(file.read_text(encoding="utf-8"))
    except json.JSONDecodeError:
        return file.read_text(encoding="utf-8", errors="replace")[-2000:]

def text_tail(path: str) -> str | None:
    file = result_dir / path
    if not file.exists():
        return None
    return file.read_text(encoding="utf-8", errors="replace")[-2000:]


def file_identity(path: str) -> dict[str, object]:
    file = result_dir / path
    if not file.exists() or file.stat().st_size == 0:
        return {}
    contents = file.read_bytes()
    return {
        "bytes": len(contents),
        "sha256": hashlib.sha256(contents).hexdigest(),
    }


def bounded_runtime_report(path: str) -> dict[str, object]:
    report = load(path)
    summary: dict[str, object] = {
        "source_document": file_identity(path),
    }
    if not isinstance(report, dict):
        summary["parseable"] = False
        return summary

    summary["parseable"] = True
    for key in (
        "schema",
        "outcome",
        "runner_blocked",
        "artifact_versions",
        "artifact_sources",
        "local_product_source_checkouts_used",
        "source_policy",
        "worker_startup",
    ):
        if key in report:
            summary[key] = report[key]

    for scenario_key in ("scenario_results", "replay_scenario_results"):
        scenarios = report.get(scenario_key)
        if not isinstance(scenarios, dict):
            continue
        summary[scenario_key] = {
            str(scenario_id)[:160]: {
                "status": scenario.get("status"),
                "executed_runtime_cell": scenario.get("executed_runtime_cell"),
            }
            for scenario_id, scenario in list(scenarios.items())[:128]
            if isinstance(scenario, dict)
        }

    findings = report.get("findings")
    if isinstance(findings, list):
        retained_findings = []
        for finding in findings[:16]:
            if not isinstance(finding, dict):
                continue
            retained = {
                key: value[:512] if isinstance(value, str) else value
                for key, value in finding.items()
                if key in {"scenario_id", "type", "finding_type", "owning_surface", "summary", "reason"}
                and isinstance(value, (str, int, float, bool, type(None)))
            }
            observed = finding.get("observed_evidence")
            if isinstance(observed, dict):
                retained["observed_evidence"] = {
                    key: value[:512] if isinstance(value, str) else value
                    for key, value in observed.items()
                    if key in {"classification", "owning_surface", "exception_type", "contract", "message"}
                    and isinstance(value, (str, int, float, bool, type(None)))
                }
            retained_findings.append(retained)
        summary["findings"] = retained_findings

    return summary


versions = pins.get("artifact_versions") or {}
sources = pins.get("artifact_sources") or {}
namespace_setup = load("replay-namespace-setup.json")
php_sdk_result = load("php-sdk-replay-probe/php-sdk-conformance-result.json")
php_sdk_result_identity = file_identity("php-sdk-replay-probe/php-sdk-conformance-result.json")
php_sdk_install_pass = (
    isinstance(php_sdk_result, dict)
    and php_sdk_result.get("local_product_source_checkouts_used") is False
    and (php_sdk_result.get("artifact_versions") or {}).get("sdk-php") == versions.get("sdk-php")
    and bool(php_sdk_result.get("package_provenance"))
    and bool(php_sdk_result.get("apache_avro_provenance"))
)
php_sdk_result_summary = bounded_runtime_report(
    "php-sdk-replay-probe/php-sdk-conformance-result.json"
)
php_sdk_result_summary.update({
    "package_provenance": (
        php_sdk_result.get("package_provenance") or {}
        if isinstance(php_sdk_result, dict)
        else {}
    ),
    "apache_avro_provenance": (
        php_sdk_result.get("apache_avro_provenance") or {}
        if isinstance(php_sdk_result, dict)
        else {}
    ),
    "assertions": (
        php_sdk_result.get("assertions") or {}
        if isinstance(php_sdk_result, dict)
        else {}
    ),
    "source_document": php_sdk_result_identity,
})
artifacts = [
    {
        "artifact": "server",
        "version": versions.get("server"),
        "source": sources.get("server"),
        "status": "pass",
        "image": server_image,
        "image_digest": server_digest,
        "probe": {
            "server_url": server_base_url,
            "ready_log": "server-ready.log",
            "cluster_info": load("server-cluster-info.json"),
            "namespace_setup": namespace_setup,
        },
    },
    {
        "artifact": "cli",
        "version": versions.get("cli"),
        "source": sources.get("cli"),
        "status": "pass",
        "install_script": pins.get("cli_install_url"),
        "binary": dw_bin,
        "probe": {
            "version_log": text_tail("cli-version.log"),
            "server_health": load("cli-server-health.json"),
        },
    },
    {
        "artifact": "sdk-python",
        "version": versions.get("sdk-python"),
        "source": sources.get("sdk-python"),
        "status": "pass" if python_pip_status == 0 and python_install_status == 0 and python_probe_status == 0 else "fail",
        "probe": {
            "pip_upgrade_exit_code": python_pip_status,
            "install_exit_code": python_install_status,
            "import_exit_code": python_probe_status,
            "import_probe": load("python-import-probe.json"),
        },
    },
    {
        "artifact": "workflow-php",
        "version": versions.get("workflow-php"),
        "source": sources.get("workflow-php"),
        "status": "pass",
        "probe": {
            "role": "embedded_server_engine",
            "verified_by": "waterline-composer-install",
        },
    },
    {
        "artifact": "sdk-php",
        "version": versions.get("sdk-php"),
        "source": sources.get("sdk-php"),
        "status": "pass" if php_sdk_install_pass else "fail",
        "probe": {
            "package": "durable-workflow/sdk",
            "cell_exit_code": php_sdk_cell_status,
            "shard_exit_code": php_shard_status,
            "result": php_sdk_result_summary,
        },
    },
    {
        "artifact": "sdk-rust",
        "version": versions.get("sdk-rust"),
        "source": sources.get("sdk-rust"),
        "status": "pass" if rust_install_status == 0 and rust_shard_status == 0 else "fail",
        "probe": {
            "cargo_install_exit_code": rust_install_status,
            "replay_shard_exit_code": rust_shard_status,
            "replay_shard": bounded_runtime_report("rust-replay-shard.json"),
        },
    },
    {
        "artifact": "waterline",
        "version": versions.get("waterline"),
        "source": sources.get("waterline"),
        "status": "pass",
        "probe": load("waterline-probe.json"),
    },
]
evidence = {
    "schema": "durable-workflow.v2.replay-conformance.published-artifact-install",
    "local_product_source_checkouts_used": False,
    "server_url": server_base_url,
    "auth_token_configured": auth_token != "",
    "namespace_setup": namespace_setup,
    "artifacts": artifacts,
}
(result_dir / "published-artifact-install.json").write_text(
    json.dumps(evidence, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)
PY

cat > "$run_root/merge-replay-shards.py" <<'PY'
from __future__ import annotations

import hashlib
import json
import re
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any

result_dir = Path(sys.argv[1])
started_at = sys.argv[2]
python_status = int(sys.argv[3])
php_status = int(sys.argv[4])
rust_status = int(sys.argv[5])
identity_status = int(sys.argv[6])

PORTABLE_RESULT_LIMIT_BYTES = 4 * 1024 * 1024
PORTABLE_RESULT_TARGET_BYTES = 3 * 1024 * 1024
PORTABLE_EVIDENCE_CELL_LIMIT_BYTES = 64 * 1024
PORTABLE_EVIDENCE_STRING_LIMIT_BYTES = 4096
PORTABLE_EVIDENCE_COLLECTION_LIMIT = 64
PORTABLE_FINDING_LIMIT = 64
PORTABLE_FINDING_LINK_LIMIT = 16
PORTABLE_SENSITIVE_KEY_PARTS = (
    "authorization",
    "cookie",
    "credential",
    "password",
    "privatekey",
    "secret",
)
PORTABLE_SENSITIVE_KEY_SUFFIXES = (
    "apikey",
    "passphrase",
    "token",
)
PORTABLE_UNBOUNDED_VALUE_KEYS = {
    "customerpayload",
    "debuglog",
    "ephemeraldiagnosticpayload",
    "eventhistory",
    "historyevents",
    "rawpayload",
    "stacktrace",
    "workflowpayload",
}
PORTABLE_PRIORITY_KEYS = {
    "artifact",
    "artifactversions",
    "code",
    "exceptionclass",
    "expectedbehavior",
    "message",
    "nextacceptancecriterion",
    "observedoutcome",
    "outcome",
    "owningsurface",
    "reason",
    "rejectionreason",
    "requiredassertions",
    "runtime",
    "scenarioid",
    "sha256",
    "source",
    "status",
    "statuscode",
    "summary",
    "title",
    "type",
    "version",
}

REQUIRED = [
    "published_artifact_install_only",
    "python_completed_history_activity_replay",
    "python_completed_history_signal_update_replay",
    "python_completed_history_wait_condition_replay",
    "python_completed_history_version_marker_replay",
    "python_completed_history_saga_compensation_replay",
    "php_completed_history_activity_replay",
    "php_completed_history_signal_update_replay",
    "php_completed_history_wait_condition_replay",
    "php_completed_history_version_marker_replay",
    "php_completed_history_saga_compensation_replay",
    "python_worker_restart_completed_query",
    "python_worker_restart_activity_state",
    "python_worker_restart_signal_update_state",
    "python_worker_restart_wait_condition_state",
    "python_worker_restart_version_marker_state",
    "python_worker_restart_saga_compensation_state",
    "php_worker_restart_completed_query",
    "php_worker_restart_activity_state",
    "php_worker_restart_signal_update_state",
    "php_worker_restart_wait_condition_state",
    "php_worker_restart_version_marker_state",
    "php_worker_restart_saga_compensation_state",
    "python_code_divergence_refusal",
    "php_code_divergence_refusal",
    "server_history_mutation_refusal",
    "malformed_history_refusal",
    "python_in_flight_signal_restart_timing",
    "php_in_flight_signal_restart_timing",
    "rust_side_effect_replay_after_worker_restart",
    "rust_version_marker_replay_after_code_upgrade",
]
PYTHON_SCENARIOS = {scenario for scenario in REQUIRED if scenario.startswith("python_")}
PHP_SCENARIOS = {scenario for scenario in REQUIRED if scenario.startswith("php_")}
RUST_SCENARIOS = {scenario for scenario in REQUIRED if scenario.startswith("rust_")}
SHARED_SCENARIOS = {
    "published_artifact_install_only",
    "server_history_mutation_refusal",
    "malformed_history_refusal",
}


def now() -> str:
    return datetime.now(timezone.utc).replace(microsecond=0).isoformat().replace("+00:00", "Z")


def load_json(path: Path) -> dict[str, Any] | None:
    if not path.exists():
        return None
    return json.loads(path.read_text(encoding="utf-8"))


def portable_key_token(value: Any) -> str:
    return re.sub(r"[^a-z0-9]", "", str(value).lower())


def portable_sensitive_key(value: Any) -> bool:
    token = portable_key_token(value)
    return any(part in token for part in PORTABLE_SENSITIVE_KEY_PARTS) or any(
        token.endswith(suffix) for suffix in PORTABLE_SENSITIVE_KEY_SUFFIXES
    )


def portable_unbounded_value_key(value: Any) -> bool:
    token = portable_key_token(value)
    return token in PORTABLE_UNBOUNDED_VALUE_KEYS or (
        token.endswith("payload") and token != "payloadcodec"
    )


def portable_json_bytes(value: Any) -> bytes:
    return json.dumps(
        value,
        default=str,
        separators=(",", ":"),
        sort_keys=True,
    ).encode("utf-8")


def portable_value_summary(value: Any, reason: str) -> dict[str, Any]:
    encoded = portable_json_bytes(value)
    summary: dict[str, Any] = {
        "retained": False,
        "reason": reason,
        "original_bytes": len(encoded),
        "sha256": hashlib.sha256(encoded).hexdigest(),
    }
    if isinstance(value, str) and reason == "string_limit":
        summary["head"] = value[:1024]
        summary["tail"] = value[-2048:]
    return summary


def portable_value(value: Any, *, depth: int = 0) -> Any:
    if value is None or isinstance(value, (bool, int, float)):
        return value
    if isinstance(value, str):
        if len(value.encode("utf-8")) <= PORTABLE_EVIDENCE_STRING_LIMIT_BYTES:
            return value
        return portable_value_summary(value, "string_limit")
    if depth >= 8:
        return portable_value_summary(value, "depth_limit")
    if isinstance(value, list):
        retained = [
            portable_value(item, depth=depth + 1)
            for item in value[:PORTABLE_EVIDENCE_COLLECTION_LIMIT]
        ]
        if len(value) > PORTABLE_EVIDENCE_COLLECTION_LIMIT:
            retained.append({
                "retained": False,
                "reason": "collection_limit",
                "omitted_items": len(value) - PORTABLE_EVIDENCE_COLLECTION_LIMIT,
                "sha256": hashlib.sha256(portable_json_bytes(value)).hexdigest(),
            })
        return retained
    if isinstance(value, dict):
        keys = sorted(
            value,
            key=lambda key: (
                portable_key_token(key) not in PORTABLE_PRIORITY_KEYS,
                str(key),
            ),
        )
        retained: dict[str, Any] = {}
        omitted_keys = 0
        sensitive_keys = 0
        for key in keys:
            key_text = str(key)
            if portable_sensitive_key(key_text):
                sensitive_keys += 1
                continue
            if len(retained) >= PORTABLE_EVIDENCE_COLLECTION_LIMIT:
                omitted_keys += 1
                continue
            if portable_unbounded_value_key(key_text):
                retained[key_text] = portable_value_summary(value[key], "unbounded_payload")
                continue
            retained[key_text] = portable_value(value[key], depth=depth + 1)
        if omitted_keys or sensitive_keys:
            retained["_portable_evidence_omitted"] = {
                "reason": "collection_or_sensitive_key_limit",
                "omitted_keys": omitted_keys,
                "sensitive_keys": sensitive_keys,
                "sha256": hashlib.sha256(portable_json_bytes(value)).hexdigest(),
            }
        return retained
    return portable_value(str(value), depth=depth)


def bounded_portable_cell(value: Any) -> Any:
    retained = portable_value(value)
    if len(portable_json_bytes(retained)) <= PORTABLE_EVIDENCE_CELL_LIMIT_BYTES:
        return retained
    return portable_value_summary(value, "evidence_cell_limit")


def portable_finding_ref(value: Any) -> Any:
    if not isinstance(value, dict):
        return bounded_portable_cell(value)
    return {
        key: bounded_portable_cell(value[key])
        for key in (
            "id",
            "type",
            "scenario_id",
            "owner",
            "owning_contract",
            "owning_surface",
            "title",
            "summary",
            "reason",
            "message",
            "blocker_kind",
        )
        if key in value
    }


def portable_scenario_result(scenario_id: str, value: dict[str, Any]) -> dict[str, Any]:
    retained: dict[str, Any] = {
        "scenario_id": scenario_id,
        "status": value.get("status"),
    }
    for key in (
        "published_artifact_versions",
        "artifact_versions",
        "artifact_sources",
        "implementation_identity",
        "runtime_matrix",
    ):
        if key in value:
            retained[key] = bounded_portable_cell(value[key])

    observed = value.get("observed_outputs")
    if observed is not None:
        retained["observed_outputs"] = bounded_portable_cell(observed)
    diagnostics = value.get("replay_diagnostics")
    if diagnostics is not None and diagnostics != observed:
        retained["replay_diagnostics"] = bounded_portable_cell(diagnostics)

    linked = value.get("linked_findings") or value.get("finding_links")
    if isinstance(linked, list) and linked:
        retained["linked_findings"] = [
            portable_finding_ref(finding)
            for finding in linked[:PORTABLE_FINDING_LINK_LIMIT]
        ]

    copied = set(retained) | {
        "observed_outputs",
        "replay_diagnostics",
        "linked_findings",
        "finding_links",
    }
    for key in sorted(value):
        if key in copied or portable_sensitive_key(key):
            continue
        retained[key] = bounded_portable_cell(value[key])
    return retained


def portable_finding(value: dict[str, Any]) -> dict[str, Any]:
    return {
        key: bounded_portable_cell(value[key])
        for key in (
            "id",
            "type",
            "scenario_id",
            "owner",
            "owning_contract",
            "owning_surface",
            "title",
            "summary",
            "reason",
            "message",
            "blocker_kind",
            "observed_behavior",
            "evidence",
            "expected_behavior",
            "next_acceptance_criterion",
        )
        if key in value
    }


def portable_finding_links(values: dict[str, list[dict[str, Any]]]) -> dict[str, list[Any]]:
    return {
        str(scenario_id): [
            portable_finding_ref(finding)
            for finding in linked[:PORTABLE_FINDING_LINK_LIMIT]
        ]
        for scenario_id, linked in list(sorted(values.items()))[:PORTABLE_EVIDENCE_COLLECTION_LIMIT]
        if isinstance(linked, list)
    }


def encoded_result_bytes(value: dict[str, Any]) -> bytes:
    return (json.dumps(value, indent=2, sort_keys=True) + "\n").encode("utf-8")


def scenario_map(report: dict[str, Any] | None) -> dict[str, dict[str, Any]]:
    if report is None:
        return {}
    raw = report.get("scenario_results") or {}
    if isinstance(raw, list):
        return {
            str(item.get("scenario_id") or item.get("id")): dict(item)
            for item in raw
            if isinstance(item, dict) and (item.get("scenario_id") or item.get("id"))
        }
    if isinstance(raw, dict):
        mapped: dict[str, dict[str, Any]] = {}
        for key, value in raw.items():
            if isinstance(value, dict):
                copied = dict(value)
                copied.setdefault("scenario_id", key)
                mapped[str(key)] = copied
        return mapped
    return {}


def section_summary(results: dict[str, dict[str, Any]], scenarios: list[str]) -> dict[str, Any]:
    statuses = {scenario: results.get(scenario, {}).get("status", "not_covered") for scenario in scenarios}
    if all(status == "pass" for status in statuses.values()):
        status = "pass"
    elif any(status == "runner_blocked" for status in statuses.values()):
        status = "runner_blocked"
    else:
        status = "fail"
    return {
        "status": status,
        "scenarios": scenarios,
        "scenario_statuses": statuses,
        "passed": sum(1 for status in statuses.values() if status == "pass"),
        "total": len(statuses),
    }


def finding(scenario_id: str, status: str, summary: str, evidence: dict[str, Any] | None = None) -> dict[str, Any]:
    finding_type = "replay_conformance_failure"
    owning_surface = "replay_runtime"
    if status == "not_covered":
        finding_type = "conformance_runner_coverage_gap"
        owning_surface = "conformance_harness"
    elif status == "runner_blocked":
        finding_type = "runner_gap"
        owning_surface = "conformance_harness"
    elif status == "unsupported":
        finding_type = "unsupported_public_surface"
        owning_surface = "sdk-php" if scenario_id.startswith("php_") else "sdk-python"

    return {
        "scenario_id": scenario_id,
        "type": finding_type,
        "owning_surface": owning_surface,
        "summary": summary,
        "observed_behavior": evidence or {},
        "expected_behavior": "scenario passes with published artifacts and actionable replay diagnostics",
        "next_acceptance_criterion": "rerun replay conformance and record passing evidence for this scenario",
    }


pins = load_json(result_dir / "pins.json") or {}
versions = dict(pins.get("artifact_versions") or {})
native_versions = dict(versions)
if "workflow-php" in native_versions:
    native_versions["workflow"] = native_versions.pop("workflow-php")
sources = dict(pins.get("artifact_sources") or {})
artifact_install_evidence = load_json(result_dir / "published-artifact-install.json") or {}
executed_distribution_identities = load_json(result_dir / "executed-distribution-identities.json") or {}
namespace_setup = load_json(result_dir / "replay-namespace-setup.json") or {}
python_report = load_json(result_dir / "python-replay-shard.json")
php_report = load_json(result_dir / "php-replay-shard.json")
rust_report = load_json(result_dir / "rust-replay-shard.json")
python_results = scenario_map(python_report)
php_results = scenario_map(php_report)
rust_results = scenario_map(rust_report)

results: dict[str, dict[str, Any]] = {}
findings: list[dict[str, Any]] = []
finding_links: dict[str, list[dict[str, Any]]] = {}

artifact_install_artifacts = artifact_install_evidence.get("artifacts") if isinstance(artifact_install_evidence, dict) else None
artifact_install_statuses = {
    str(item.get("artifact")): str(item.get("status"))
    for item in artifact_install_artifacts or []
    if isinstance(item, dict) and item.get("artifact")
}
required_install_artifacts = ["server", "cli", "sdk-php", "sdk-python", "sdk-rust", "workflow-php", "waterline"]
artifact_install_pass = (
    isinstance(artifact_install_evidence, dict)
    and artifact_install_evidence != {}
    and artifact_install_evidence.get("local_product_source_checkouts_used") is False
    and all(artifact_install_statuses.get(artifact) == "pass" for artifact in required_install_artifacts)
)
install_pass = (
    versions != {}
    and sources != {}
    and artifact_install_pass
)
results["published_artifact_install_only"] = {
    "scenario_id": "published_artifact_install_only",
    "status": "pass" if install_pass else "fail",
    "observed_outputs": {
        "resolved_artifact_versions": versions,
        "artifact_sources": sources,
        "local_product_source_checkouts_used": False,
        "artifact_install_evidence": artifact_install_evidence,
        "artifact_install_statuses": artifact_install_statuses,
        "python_shard_status": python_results.get("published_artifact_install_only", {}).get("status"),
        "php_shard_status": php_results.get("published_artifact_install_only", {}).get("status"),
        "rust_shard_status": rust_status,
    },
    "runtime_matrix": {"runtimes": ["sdk-php", "sdk-python", "sdk-rust"]},
}

for scenario in REQUIRED:
    if scenario == "published_artifact_install_only":
        continue
    candidates: list[dict[str, Any]] = []
    if scenario in PYTHON_SCENARIOS or scenario in SHARED_SCENARIOS:
        if scenario in python_results:
            candidates.append(python_results[scenario])
    if scenario in PHP_SCENARIOS or scenario in SHARED_SCENARIOS:
        if scenario in php_results:
            candidates.append(php_results[scenario])
    if scenario in RUST_SCENARIOS and scenario in rust_results:
        candidates.append(rust_results[scenario])

    if not candidates:
        if scenario in PYTHON_SCENARIOS and python_report is None:
            status = "runner_blocked"
        elif scenario in PHP_SCENARIOS and php_report is None:
            status = "runner_blocked"
        elif scenario in RUST_SCENARIOS and rust_report is None:
            status = "runner_blocked"
        else:
            status = "not_covered"
        generated = finding(
            scenario,
            status,
            f"Replay conformance shard did not report {scenario}.",
            {
                "python_shard_exit_code": python_status,
                "php_shard_exit_code": php_status,
                "rust_shard_exit_code": rust_status,
            },
        )
        results[scenario] = {
            "scenario_id": scenario,
            "status": status,
            "observed_outputs": generated["observed_behavior"],
            "linked_findings": [generated],
        }
        findings.append(generated)
        finding_links[scenario] = [generated]
        continue

    if len(candidates) == 1:
        merged = dict(candidates[0])
    else:
        pass_count = sum(1 for item in candidates if item.get("status") == "pass")
        merged = dict(candidates[0])
        merged["status"] = "pass" if pass_count == len(candidates) else "fail"
        merged["observed_outputs"] = {
            "shard_statuses": [item.get("status") for item in candidates],
            "shard_outputs": [
                item.get("observed_outputs") or item.get("replay_diagnostics") or {}
                for item in candidates
            ],
        }
        merged["replay_diagnostics"] = merged["observed_outputs"]
    merged["scenario_id"] = scenario
    results[scenario] = merged

for scenario_id, scenario in results.items():
    if scenario.get("status") == "pass":
        continue
    linked = scenario.get("linked_findings") or scenario.get("finding_links") or []
    if linked:
        finding_links[scenario_id] = linked if isinstance(linked, list) else [linked]
        continue
    generated = finding(
        scenario_id,
        str(scenario.get("status") or "fail"),
        f"Replay conformance scenario {scenario_id} did not pass.",
        scenario.get("observed_outputs") or scenario.get("replay_diagnostics") or {},
    )
    scenario["linked_findings"] = [generated]
    findings.append(generated)
    finding_links[scenario_id] = [generated]

for report in (python_report, php_report, rust_report):
    if not report:
        continue
    raw_findings = report.get("findings") or []
    if isinstance(raw_findings, list):
        findings.extend(item for item in raw_findings if isinstance(item, dict))

finished_at = now()
outcome = (
    "pass"
    if identity_status == 0 and all(results[scenario].get("status") == "pass" for scenario in REQUIRED)
    else "fail"
)
runner_blocked = any(results[scenario].get("status") == "runner_blocked" for scenario in REQUIRED)
if identity_status != 0:
    identity_finding = {
        "type": "executed_distribution_identity_missing",
        "owning_surface": "conformance_harness",
        "summary": "Replay execution did not retain normalized identities for every consumed distribution.",
        "observed_behavior": {
            "validation_log": "executed-distribution-identities-validation.log",
        },
        "expected_behavior": "passing replay evidence identifies every package, crate, release asset, and OCI manifest actually executed",
        "next_acceptance_criterion": "retain the consumed distribution bytes and rerun replay conformance",
    }
    findings.append(identity_finding)
    finding_links["executed_distribution_identities"] = [identity_finding]

retained_results = {
    scenario: portable_scenario_result(scenario, results[scenario])
    for scenario in REQUIRED
}
retained_findings = [
    portable_finding(value)
    for value in findings[:PORTABLE_FINDING_LIMIT]
]
retained_finding_links = portable_finding_links(finding_links)
portable_evidence_contract = {
    "schema": "durable-workflow.v1.portable-native-evidence",
    "max_result_bytes": PORTABLE_RESULT_LIMIT_BYTES,
    "projection_target_bytes": PORTABLE_RESULT_TARGET_BYTES,
    "max_evidence_cell_bytes": PORTABLE_EVIDENCE_CELL_LIMIT_BYTES,
    "max_string_bytes": PORTABLE_EVIDENCE_STRING_LIMIT_BYTES,
    "scenario_evidence": "complete_required_statuses_and_bounded_diagnostics",
    "sensitive_values": "omitted",
    "unbounded_values": "sha256_summary",
}
result = {
    "schema": "durable-workflow.v2.replay-conformance.result",
    "schema_version": 1,
    "started_at": started_at,
    "finished_at": finished_at,
    "generated_at": finished_at,
    "outcome": outcome,
    "runner_blocked": runner_blocked,
    "artifact_versions": native_versions,
    "executed_distribution_identities": executed_distribution_identities,
    "artifact_sources": sources,
    "namespace_setup": namespace_setup,
    "source_policy": {
        "artifact_source": "published_artifacts",
        "local_product_source_checkouts_used": False,
    },
    "runtime_matrix": {
        "runtimes": ["sdk-php", "sdk-python", "sdk-rust"],
        "coverage_scopes": ["sdk-php-runtime-shard", "sdk-python-runtime-shard", "sdk-rust-runtime-shard"],
        "shards": {
            "sdk-php": {
                "reported": php_report is not None,
                "exit_code": php_status,
                "outcome": (php_report or {}).get("outcome"),
            },
            "sdk-python": {
                "reported": python_report is not None,
                "exit_code": python_status,
                "outcome": (python_report or {}).get("outcome"),
            },
            "sdk-rust": {
                "reported": rust_report is not None,
                "exit_code": rust_status,
                "outcome": (rust_report or {}).get("outcome"),
            },
        },
    },
    "scenario_results": retained_results,
    "completed_history_replay": section_summary(results, REQUIRED[1:11] + ["rust_side_effect_replay_after_worker_restart"]),
    "worker_restart_replay": section_summary(results, REQUIRED[11:23] + ["rust_version_marker_replay_after_code_upgrade"]),
    "adversarial_replay": section_summary(results, REQUIRED[23:27]),
    "in_flight_timing": section_summary(results, REQUIRED[27:29]),
    "findings": retained_findings,
    "finding_links": retained_finding_links,
    "portable_evidence_contract": portable_evidence_contract,
}
projected_result_bytes = len(encoded_result_bytes(result))
if projected_result_bytes > PORTABLE_RESULT_TARGET_BYTES:
    result = {
        "schema": result["schema"],
        "schema_version": result["schema_version"],
        "started_at": result["started_at"],
        "finished_at": result["finished_at"],
        "generated_at": result["generated_at"],
        "outcome": result["outcome"],
        "runner_blocked": result["runner_blocked"],
        "artifact_versions": result["artifact_versions"],
        "executed_distribution_identities": result["executed_distribution_identities"],
        "artifact_sources": result["artifact_sources"],
        "source_policy": result["source_policy"],
        "runtime_matrix": result["runtime_matrix"],
        "scenario_results": {
            scenario: {
                "scenario_id": scenario,
                "status": retained_results[scenario].get("status"),
                **(
                    {"linked_findings": retained_results[scenario]["linked_findings"]}
                    if retained_results[scenario].get("linked_findings")
                    else {}
                ),
            }
            for scenario in REQUIRED
        },
        "completed_history_replay": result["completed_history_replay"],
        "worker_restart_replay": result["worker_restart_replay"],
        "adversarial_replay": result["adversarial_replay"],
        "in_flight_timing": result["in_flight_timing"],
        "findings": [portable_finding_ref(value) for value in retained_findings],
        "finding_links": retained_finding_links,
        "portable_evidence_contract": portable_evidence_contract,
        "portable_projection": {
            "reason": "projection_target",
            "projected_bytes": projected_result_bytes,
            "projection_target_bytes": PORTABLE_RESULT_TARGET_BYTES,
        },
    }
if len(encoded_result_bytes(result)) > PORTABLE_RESULT_LIMIT_BYTES:
    raise RuntimeError("portable replay result exceeded its result budget after projection")
record = {
    "schema": "durable-workflow.v2.replay-conformance.record",
    "outcome": outcome,
    "runnerBlocked": runner_blocked,
    "artifactVersions": versions,
    "started_at": started_at,
    "finished_at": finished_at,
    "result_file": "replay-conformance-result.json",
}
metadata = {
    "schema": "durable-workflow.v2.replay-conformance.run-metadata",
    "started_at": started_at,
    "finished_at": finished_at,
    "runner_blocked": runner_blocked,
    "published_artifact_install": artifact_install_evidence,
    "namespace_setup": namespace_setup,
    "python_shard_exit_code": python_status,
    "php_shard_exit_code": php_status,
    "rust_shard_exit_code": rust_status,
    "executed_distribution_identities_status": identity_status,
    "result_files": [
        "pins.json",
        "run-metadata.json",
        "published-artifact-install.json",
        "replay-namespace-setup.json",
        "python-replay-shard.json",
        "php-replay-shard.json",
        "rust-replay-shard.json",
        "replay-conformance-result.json",
        "replay-conformance-record.json",
    ],
}

(result_dir / "replay-conformance-result.json").write_text(
    json.dumps(result, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)
(result_dir / "replay-conformance-record.json").write_text(
    json.dumps(record, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)
(result_dir / "run-metadata.json").write_text(
    json.dumps(metadata, indent=2, sort_keys=True) + "\n",
    encoding="utf-8",
)
print(json.dumps(result, indent=2, sort_keys=True))
raise SystemExit(0 if outcome == "pass" and not runner_blocked else 1)
PY

set +e
python3 "$script_dir/distribution_identities.py" validate \
  "$distribution_identity_file" workflow waterline server cli sdk-php sdk-python sdk-rust \
  > "$result_dir/executed-distribution-identities-validation.log" 2>&1
identity_status=$?
python3 "$run_root/merge-replay-shards.py" \
  "$result_dir" \
  "$started_at" \
  "$python_shard_status" \
  "$php_shard_status" \
  "$rust_shard_status" \
  "$identity_status" \
  > "$result_dir/replay-conformance-merge.log" 2>&1
merge_status=$?
set -e

exit "$merge_status"
