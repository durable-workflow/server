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
  DW_HEARTBEATS_WAVE_MAX_SECONDS       Passing wall-time budget; defaults to 540.
  DW_HEARTBEATS_CELL_TIMEOUT_SECONDS   Per-cell timeout; defaults to the wave
                                       budget minus a 90-second orchestration
                                       and cleanup reserve (450 by default).
  DW_HEARTBEATS_RUST_PREPARATION_TIMEOUT_SECONDS
                                       Rust registry/download/build budget;
                                       defaults to the wave budget minus the
                                       orchestration reserve and a 90-second
                                       heartbeat-execution reserve (360 by
                                       default).
  DW_HEARTBEATS_CHILD_SETTLE_SECONDS   Bounded process-group teardown; defaults
                                       to 20.
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
command -v setsid >/dev/null 2>&1 || {
  printf '%s\n' 'required command not found: setsid' >&2
  exit 1
}

state_file="$result_dir/shared-server-state.json"
child_rows_file="$result_dir/.heartbeat-shared-wave-children.tsv"
child_result_file="$result_dir/heartbeat-shared-wave-children.json"
process_cleanup_log="$result_dir/heartbeat-shared-wave-process-cleanup.log"
: >"$process_cleanup_log"
started_at="$(date -u +'%Y-%m-%dT%H:%M:%SZ')"
child_settle_seconds="${DW_HEARTBEATS_CHILD_SETTLE_SECONDS:-20}"
maximum_seconds="${DW_HEARTBEATS_WAVE_MAX_SECONDS:-540}"
wave_orchestration_reserve_seconds=90
rust_execution_reserve_seconds=90
cleanup_done=0
cleanup_deferred_signal=""
launch_deferred_signal=""
declare -a cell_pids=()
declare -a cell_pgids=()
declare -a cell_names=()

[[ "$maximum_seconds" =~ ^[1-9][0-9]*$ ]] \
  && ((maximum_seconds > wave_orchestration_reserve_seconds + rust_execution_reserve_seconds)) || {
  printf 'DW_HEARTBEATS_WAVE_MAX_SECONDS must be an integer greater than %s\n' \
    "$((wave_orchestration_reserve_seconds + rust_execution_reserve_seconds))" >&2
  exit 2
}
cell_timeout="${DW_HEARTBEATS_CELL_TIMEOUT_SECONDS:-$((maximum_seconds - wave_orchestration_reserve_seconds))}"
rust_preparation_timeout="${DW_HEARTBEATS_RUST_PREPARATION_TIMEOUT_SECONDS:-$((maximum_seconds - wave_orchestration_reserve_seconds - rust_execution_reserve_seconds))}"

for bounded_timeout in "$cell_timeout" "$rust_preparation_timeout"; do
  [[ "$bounded_timeout" =~ ^[1-9][0-9]*$ ]] || {
    printf '%s\n' 'heartbeat cell and Rust preparation timeouts must be positive integers' >&2
    exit 2
  }
done
((cell_timeout <= maximum_seconds - wave_orchestration_reserve_seconds)) || {
  printf 'DW_HEARTBEATS_CELL_TIMEOUT_SECONDS must leave a %s-second orchestration and cleanup reserve within the wave wall-time budget\n' \
    "$wave_orchestration_reserve_seconds" >&2
  exit 2
}
((rust_preparation_timeout <= cell_timeout - rust_execution_reserve_seconds)) || {
  printf 'DW_HEARTBEATS_RUST_PREPARATION_TIMEOUT_SECONDS must leave a %s-second heartbeat-execution reserve within the Rust cell budget\n' \
    "$rust_execution_reserve_seconds" >&2
  exit 2
}
export DW_HEARTBEATS_RUST_PREPARATION_TIMEOUT_SECONDS="$rust_preparation_timeout"

[[ "$child_settle_seconds" =~ ^[1-9][0-9]*$ ]] \
  && ((child_settle_seconds <= 60)) || {
  printf '%s\n' 'DW_HEARTBEATS_CHILD_SETTLE_SECONDS must be an integer from 1 to 60' >&2
  exit 2
}

process_group_members() {
  local pgid="$1"
  local include_zombies="${2:-false}"
  local stat_file process_stat process_fields process_state process_parent
  local process_group pid command
  for stat_file in /proc/[0-9]*/stat; do
    [[ -r "$stat_file" ]] || continue
    process_stat="$(<"$stat_file")" 2>/dev/null || continue
    process_fields="${process_stat##*) }"
    read -r process_state process_parent process_group _ <<<"$process_fields"
    [[ "$process_group" == "$pgid" ]] || continue
    if [[ "$include_zombies" != true && "$process_state" =~ ^[ZX]$ ]]; then
      continue
    fi
    pid="${stat_file#/proc/}"
    pid="${pid%/stat}"
    command=""
    if [[ -r "/proc/$pid/cmdline" ]]; then
      command="$(tr '\0' ' ' <"/proc/$pid/cmdline" 2>/dev/null)" || command=""
    fi
    printf '%s\t%s\t%s\t%s\n' "$pid" "$process_parent" "$process_state" "$command"
  done
}

process_group_alive() {
  local pgid="$1"
  local member
  while IFS= read -r member; do
    [[ -n "$member" ]] && return 0
  done < <(process_group_members "$pgid")
  return 1
}

all_process_groups_settled() {
  local pgid
  for pgid in "$@"; do
    process_group_alive "$pgid" && return 1
  done
  return 0
}

signal_process_groups() {
  local signal="$1"
  local pgid
  shift
  for pgid in "$@"; do
    if process_group_alive "$pgid"; then
      kill "-$signal" -- "-$pgid" 2>/dev/null || true
    fi
  done
}

wait_for_cell() {
  local pid="$1"
  cell_wait_status=0
  while true; do
    if wait "$pid"; then
      cell_wait_status=0
      return 0
    else
      cell_wait_status=$?
    fi
    if ! kill -0 "$pid" 2>/dev/null; then
      return 0
    fi
  done
}

settle_process_groups() {
  local term_grace_seconds=$((child_settle_seconds < 5 ? 1 : 5))
  local term_deadline=$((SECONDS + term_grace_seconds))
  local final_deadline=$((SECONDS + child_settle_seconds))
  cell_forced_signal=""

  if all_process_groups_settled "$@"; then
    return 0
  fi

  cell_forced_signal="SIGTERM"
  signal_process_groups TERM "$@"
  while ((SECONDS < term_deadline)); do
    all_process_groups_settled "$@" && return 0
    sleep 0.1
  done

  cell_forced_signal="SIGKILL"
  signal_process_groups KILL "$@"
  while ((SECONDS < final_deadline)); do
    all_process_groups_settled "$@" && return 0
    sleep 0.1
  done
  all_process_groups_settled "$@"
}

settle_process_group() {
  settle_process_groups "$1"
}

record_unsettled_process_group() {
  local cell="$1"
  local pgid="$2"
  {
    printf 'cell=%s process_group_id=%s did not settle within %ss\n' \
      "$cell" "$pgid" "$child_settle_seconds"
    process_group_members "$pgid" true
  } | tee -a "$process_cleanup_log" >&2
}

write_child_result() {
  node "$script_dir/heartbeats-wave-children.mjs" \
    "$child_rows_file" "$child_result_file"
  rm -f -- "$child_rows_file"
}

defer_cleanup_signal() {
  cleanup_deferred_signal="$1"
}

cleanup_wave() {
  local status=0
  local index pid pgid
  cleanup_deferred_signal=""
  trap 'defer_cleanup_signal INT' INT
  trap 'defer_cleanup_signal TERM' TERM
  if ((cleanup_done == 1)); then
    trap 'on_signal INT' INT
    trap 'on_signal TERM' TERM
    return 0
  fi
  cleanup_done=1
  if ! settle_process_groups "${cell_pgids[@]}"; then
    status=1
  fi
  for index in "${!cell_pids[@]}"; do
    pid="${cell_pids[$index]}"
    pgid="${cell_pgids[$index]}"
    if process_group_alive "$pgid"; then
      record_unsettled_process_group "${cell_names[$index]}" "$pgid"
      status=1
      continue
    fi
    wait_for_cell "$pid" || true
  done
  if [[ -s "$state_file" ]]; then
    "$script_dir/heartbeats-shared-server.sh" stop "$state_file" \
      >"$result_dir/shared-server-stop.log" 2>&1 || status=$?
  fi
  trap 'on_signal INT' INT
  trap 'on_signal TERM' TERM
  if [[ -n "$cleanup_deferred_signal" ]]; then
    on_signal "$cleanup_deferred_signal"
  fi
  return "$status"
}

on_signal() {
  local signal="$1"
  trap - INT TERM
  cleanup_wave || true
  if [[ "$signal" == INT ]]; then
    exit 130
  fi
  exit 143
}

defer_launch_signal() {
  launch_deferred_signal="$1"
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
  local process_stat process_fields process_state process_parent
  shift 2
  launch_deferred_signal=""
  trap 'defer_launch_signal INT' INT
  trap 'defer_launch_signal TERM' TERM
  setsid timeout --foreground --signal=TERM --kill-after=15s "${cell_timeout}s" \
    env \
    DW_HEARTBEATS_NAMESPACE="$namespace" \
    DW_HEARTBEATS_SHARED_SERVER_STATE="$state_file" \
    "$@" \
    >"$result_dir/$cell/stdout.log" \
    2>"$result_dir/$cell/stderr.log" &
  local pid="$!"
  local pgid=""
  for _ in {1..20}; do
    if [[ -r "/proc/$pid/stat" ]]; then
      process_stat="$(<"/proc/$pid/stat")"
      process_fields="${process_stat##*) }"
      read -r process_state process_parent pgid _ <<<"$process_fields"
    fi
    [[ "$pgid" == "$pid" ]] && break
    sleep 0.05
  done
  if [[ "$pgid" != "$pid" ]]; then
    kill -KILL "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
    trap 'on_signal INT' INT
    trap 'on_signal TERM' TERM
    printf 'could not identify the %s cell process group\n' "$cell" >&2
    exit 1
  fi
  cell_pids+=("$pid")
  cell_pgids+=("$pgid")
  cell_names+=("$cell")
  trap 'on_signal INT' INT
  trap 'on_signal TERM' TERM
  if [[ -n "$launch_deferred_signal" ]]; then
    on_signal "$launch_deferred_signal"
  fi
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

: >"$child_rows_file"
for index in "${!cell_pids[@]}"; do
  pid="${cell_pids[$index]}"
  pgid="${cell_pgids[$index]}"
  wait_for_cell "$pid"
  cell_status="$cell_wait_status"
  if settle_process_group "$pgid"; then
    cell_settled=true
  else
    cell_settled=false
  fi
  printf '%s\n' "$cell_status" >"$result_dir/${cell_names[$index]}/exit-code"
  printf '%s\t%s\t%s\t%s\t%s\n' \
    "${cell_names[$index]}" "$pid" "$pgid" "$cell_status" \
    "$cell_settled:${cell_forced_signal:-none}" >>"$child_rows_file"
done
write_child_result
cell_pids=()
cell_pgids=()
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
CELL_TIMEOUT_SECONDS="$cell_timeout" \
RUST_PREPARATION_TIMEOUT_SECONDS="$rust_preparation_timeout" \
WAVE_ORCHESTRATION_RESERVE_SECONDS="$wave_orchestration_reserve_seconds" \
RUST_EXECUTION_RESERVE_SECONDS="$rust_execution_reserve_seconds" \
node "$script_dir/heartbeats-wave-result.mjs"
