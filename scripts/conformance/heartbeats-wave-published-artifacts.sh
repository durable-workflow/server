#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: heartbeats-wave-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]

Runs the PHP, Python, Rust, and Waterline published-artifact heartbeat cells in
parallel against one clean exact published-server bootstrap. Independent cell
evidence is retained under php/, python/, rust/, and waterline/.

Required exact artifact pins:
  DW_SERVER_VERSION
  DW_CLI_VERSION
  DW_PHP_SDK_VERSION
  DW_PYTHON_SDK_VERSION
  DW_RUST_SDK_VERSION
  DW_WORKFLOW_PHP_VERSION
  DW_WATERLINE_VERSION

Required runner handoff:
  DW_HEARTBEATS_WATERLINE_RUNNER       Path to the Waterline published-artifact
                                       worker-status runner.

Optional:
  DW_HEARTBEATS_CELL_TIMEOUT_SECONDS   Per-cell timeout; defaults to 330.
  DW_HEARTBEATS_WAVE_MAX_SECONDS       Passing wall-time bound; defaults to 360.
USAGE
}

result_dir="${DW_HEARTBEATS_RESULT_DIR:-}"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --result-dir)
      result_dir="${2:?--result-dir requires a value}"
      shift 2
      ;;
    --result-dir=*)
      result_dir="${1#--result-dir=}"
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
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-heartbeats-wave.XXXXXX")"
fi
mkdir -p "$result_dir"/{php,python,rust,waterline}
result_dir="$(cd "$result_dir" && pwd)"

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
waterline_runner="${DW_HEARTBEATS_WATERLINE_RUNNER:-}"
[[ -f "$waterline_runner" ]] || {
  printf '%s\n' 'DW_HEARTBEATS_WATERLINE_RUNNER must name the Waterline published-artifact runner' >&2
  exit 2
}
for pin in \
  DW_SERVER_VERSION \
  DW_CLI_VERSION \
  DW_PHP_SDK_VERSION \
  DW_PYTHON_SDK_VERSION \
  DW_RUST_SDK_VERSION \
  DW_WORKFLOW_PHP_VERSION \
  DW_WATERLINE_VERSION; do
  [[ -n "${!pin:-}" ]] || { printf '%s is required\n' "$pin" >&2; exit 2; }
done

state_file="$result_dir/shared-server-state.json"
started_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
cell_timeout="${DW_HEARTBEATS_CELL_TIMEOUT_SECONDS:-330}"
maximum_seconds="${DW_HEARTBEATS_WAVE_MAX_SECONDS:-360}"
cleanup_done=0
declare -a cell_pids=()
declare -a cell_names=()

cleanup_wave() {
  local status=0
  if ((cleanup_done == 1)); then
    return 0
  fi
  cleanup_done=1
  for pid in "${cell_pids[@]:-}"; do
    if kill -0 "$pid" 2>/dev/null; then
      kill -TERM "$pid" 2>/dev/null || true
    fi
  done
  for pid in "${cell_pids[@]:-}"; do
    wait "$pid" 2>/dev/null || true
  done
  if [[ -s "$state_file" ]]; then
    "$script_dir/heartbeats-shared-server.sh" stop "$state_file" \
      >"$result_dir/shared-server-stop.log" 2>&1 || status=$?
  fi
  return "$status"
}

on_signal() {
  local signal="$1"
  cleanup_wave || true
  if [[ "$signal" == INT ]]; then
    exit 130
  fi
  exit 143
}

trap 'cleanup_wave || true' EXIT
trap 'on_signal INT' INT
trap 'on_signal TERM' TERM

"$script_dir/heartbeats-shared-server.sh" start "$state_file" \
  >"$result_dir/shared-server-start.log" 2>&1

namespace_for() {
  node -e '
    const fs = require("node:fs");
    const state = JSON.parse(fs.readFileSync(process.argv[1], "utf8"));
    process.stdout.write(state.cell_isolation[process.argv[2]].namespace);
  ' "$state_file" "$1"
}

run_cell() {
  local cell="$1"
  local namespace="$2"
  shift 2
  timeout --signal=TERM --kill-after=15s "${cell_timeout}s" \
    env \
    DW_HEARTBEATS_NAMESPACE="$namespace" \
    DW_HEARTBEATS_SHARED_SERVER_STATE="$state_file" \
    "$@" \
    >"$result_dir/$cell/stdout.log" \
    2>"$result_dir/$cell/stderr.log" &
  cell_pids+=("$!")
  cell_names+=("$cell")
}

run_cell php "$(namespace_for php)" \
  "$script_dir/heartbeats-published-artifacts.sh" --result-dir "$result_dir/php"
run_cell python "$(namespace_for python)" \
  "$script_dir/heartbeats-python-published-artifacts.sh" --result-dir "$result_dir/python"
run_cell rust "$(namespace_for rust)" \
  "$script_dir/heartbeats-rust-published-artifacts.sh" --result-dir "$result_dir/rust"
run_cell waterline "$(namespace_for waterline)" \
  DW_WATERLINE_WORKER_STATUS_AUTH_TOKEN="${DW_HEARTBEATS_AUTH_TOKEN:-dev-token}" \
  DW_WATERLINE_WORKER_STATUS_NAMESPACE="$(namespace_for waterline)" \
  DW_WATERLINE_WORKER_STATUS_SHARED_SERVER_STATE="$state_file" \
  bash "$waterline_runner" --result-dir "$result_dir/waterline"

for index in "${!cell_pids[@]}"; do
  if wait "${cell_pids[$index]}"; then
    cell_status=0
  else
    cell_status=$?
  fi
  printf '%s\n' "$cell_status" >"$result_dir/${cell_names[$index]}/exit-code"
done
cell_pids=()
cell_names=()

set +e
STATE_FILE="$state_file" \
RESULT_FILE="$result_dir/heartbeat-shared-wave-isolation.json" \
timeout --signal=TERM --kill-after=5s 30s \
node "$script_dir/heartbeats-wave-observer.mjs" \
  >"$result_dir/shared-wave-observer.log" 2>&1
printf '%s\n' "$?" >"$result_dir/shared-wave-observer-exit-code"
set -e

cleanup_wave || true
trap - EXIT INT TERM

RESULT_DIR="$result_dir" \
STATE_FILE="$state_file" \
STARTED_AT="$started_at" \
MAXIMUM_SECONDS="$maximum_seconds" \
node "$script_dir/heartbeats-wave-result.mjs"
