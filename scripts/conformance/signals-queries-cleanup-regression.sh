#!/usr/bin/env bash
set -Eeuo pipefail

usage() {
  cat <<'USAGE'
Usage: signals-queries-cleanup-regression.sh

Runs the published signals/queries matrix until its PHP, Rust, and Waterline bind
mounts have all been populated, terminates that isolated run, and verifies that
the invoking user can remove every output and that no Docker resources remain.

The exact published tuple must be supplied through:
  DW_SERVER_VERSION
  DW_CLI_VERSION
  DW_PYTHON_SDK_VERSION
  DW_RUST_SDK_VERSION
  DW_WORKFLOW_PHP_VERSION
  DW_WATERLINE_VERSION

Optional:
  DW_SIGNALS_QUERIES_CLEANUP_REGRESSION_TIMEOUT_SECONDS  Defaults to 1800.
USAGE
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi
if [[ $# -ne 0 ]]; then
  usage >&2
  exit 2
fi

for version_var in \
  DW_SERVER_VERSION \
  DW_CLI_VERSION \
  DW_PYTHON_SDK_VERSION \
  DW_RUST_SDK_VERSION \
  DW_WORKFLOW_PHP_VERSION \
  DW_WATERLINE_VERSION
do
  if [[ -z "${!version_var:-}" ]]; then
    printf 'missing exact published tuple variable: %s\n' "$version_var" >&2
    exit 2
  fi
done

command -v docker >/dev/null
docker info >/dev/null

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
runner="$script_dir/signals-queries-published-artifacts.sh"
suffix="$(date -u +%Y%m%d%H%M%S)-$$"
scratch="$(mktemp -d "${TMPDIR:-/tmp}/dw-signals-queries-cleanup-regression.XXXXXX")"
result_dir="$scratch/results"
run_root="$result_dir/baseline-$suffix"
ready_file="$scratch/all-mounted-runtime-paths-ready"
runner_output="$scratch/runner-output.log"
project="dw-signals-queries-baseline-$suffix"
runner_pid=""

cleanup_on_exit() {
  local status=$?
  if [[ -n "$runner_pid" ]] && kill -0 "$runner_pid" 2>/dev/null; then
    kill -TERM "$runner_pid" 2>/dev/null || true
    wait "$runner_pid" 2>/dev/null || true
  fi
  if [[ "$status" -ne 0 ]]; then
    printf 'cleanup regression artifacts retained for diagnosis: %s\n' "$scratch" >&2
  fi
  return "$status"
}
trap cleanup_on_exit EXIT

mkdir -p "$result_dir"

DW_SIGNALS_QUERIES_BASELINE_RUN_ROOT="$run_root" \
DW_SIGNALS_QUERIES_RUN_ADVERSARIAL_PROBE=0 \
DW_SIGNALS_QUERIES_CLEANUP_TERMINATION_READY_FILE="$ready_file" \
"$runner" --result-dir "$result_dir" >"$runner_output" 2>&1 &
runner_pid=$!

timeout_seconds="${DW_SIGNALS_QUERIES_CLEANUP_REGRESSION_TIMEOUT_SECONDS:-1800}"
deadline=$((SECONDS + timeout_seconds))
while [[ ! -f "$ready_file" ]]; do
  if ! kill -0 "$runner_pid" 2>/dev/null; then
    wait "$runner_pid" || true
    printf 'runner exited before all mounted runtime paths were populated\n' >&2
    exit 1
  fi
  if (( SECONDS >= deadline )); then
    printf 'timed out waiting for all mounted runtime paths after %s seconds\n' "$timeout_seconds" >&2
    exit 1
  fi
  sleep 1
done

resource_label="$(tr -d '\r\n' < "$ready_file")"
if [[ "$resource_label" != com.durableworkflow.conformance.run=* ]]; then
  printf 'runner checkpoint did not identify its exact Docker resource label\n' >&2
  exit 1
fi

for mounted_path in \
  "$run_root/workflow-php/vendor" \
  "$run_root/sdk-rust/target" \
  "$result_dir/waterline-signals-queries-observer/vendor" \
  "$result_dir/waterline-signals-queries-composer-cache/files"
do
  if [[ ! -d "$mounted_path" ]]; then
    printf 'mounted runtime path was not populated: %s\n' "$mounted_path" >&2
    exit 1
  fi
done

kill -TERM "$runner_pid"
set +e
wait "$runner_pid"
runner_status=$?
set -e
runner_pid=""
if [[ "$runner_status" -ne 143 ]]; then
  printf 'terminated runner exited with %s instead of 143\n' "$runner_status" >&2
  exit 1
fi

for kind in container volume network; do
  list_args=(ls -q --filter "label=com.docker.compose.project=$project")
  if [[ "$kind" == "container" ]]; then
    list_args=(ls -a -q --filter "label=com.docker.compose.project=$project")
  fi
  remaining="$(docker "$kind" "${list_args[@]}")"
  if [[ -n "$remaining" ]]; then
    printf 'Compose %s resources remain for %s: %s\n' "$kind" "$project" "$remaining" >&2
    exit 1
  fi
done

labeled_containers="$(docker container ls -a -q --filter "label=$resource_label")"
if [[ -n "$labeled_containers" ]]; then
  printf 'runner-owned docker run containers remain after termination: %s\n' "$labeled_containers" >&2
  exit 1
fi

if [[ \
  -e "$run_root" \
  || -e "$result_dir/waterline-signals-queries-observer" \
  || -e "$result_dir/waterline-signals-queries-composer-cache" \
]]; then
  printf 'runner-created scratch trees remain after termination\n' >&2
  exit 1
fi

foreign_owner="$(find "$scratch" -xdev ! -uid "$(id -u)" -print -quit)"
if [[ -n "$foreign_owner" ]]; then
  printf 'output is not owned by the invoking host user: %s\n' "$foreign_owner" >&2
  exit 1
fi
unreadable="$(find "$scratch" -xdev -type f ! -readable -print -quit)"
if [[ -n "$unreadable" ]]; then
  printf 'output is not readable by the invoking host user: %s\n' "$unreadable" >&2
  exit 1
fi

rm -rf "$scratch"
if [[ -e "$scratch" ]]; then
  printf 'invoking host user could not remove regression output: %s\n' "$scratch" >&2
  exit 1
fi

trap - EXIT
printf '{"status":"pass","terminated_exit_code":143,"runtime_resources_remaining":0,"scratch_removed":true}\n'
