#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: timers-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Writes the published-artifact timer conformance handoff result.

The handoff is intentionally non-passing until first-class timer scenario
shards exist. It runs from the published server image, records the exact
artifact tuple under test, and emits coverage-gap evidence rather than using a
local product source checkout as pass evidence.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  timer-runtime-result.json
  timer-runtime-record.json
  timer-runtime-findings.json
  timers-result.json
  timers-record.json

Environment overrides:
  DW_TIMERS_RESULT_DIR              Result directory. Defaults to run root.
  DW_TIMERS_RUN_ROOT                Scratch directory. Defaults to mktemp.
  DW_TIMERS_KEEP_RUN_ROOT=1         Keep scratch directory after success.
  DW_TIMERS_SCENARIO_MANIFEST       Scenario manifest path. Defaults to the server static mirror.
  DW_TIMERS_RUNNER_SOURCE           Exact source for the runner process. Defaults to DW_SERVER_IMAGE.
  DW_SERVER_IMAGE                   Exact server image tag or digest to test.
  DW_SERVER_VERSION                 Exact patch server Docker tag; required for digest-only DW_SERVER_IMAGE.
  DW_CLI_VERSION                    Exact CLI release version.
  DW_PYTHON_SDK_VERSION             Exact PyPI durable-workflow version.
  DW_WORKFLOW_PHP_VERSION           Exact Composer durable-workflow/workflow version.
  DW_WATERLINE_VERSION              Exact Waterline artifact version.
USAGE
}

keep_run_root="${DW_TIMERS_KEEP_RUN_ROOT:-0}"
result_dir="${DW_TIMERS_RESULT_DIR:-}"

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
scenario_manifest="${DW_TIMERS_SCENARIO_MANIFEST:-$repo_root/static/platform-conformance/timer-runtime-scenarios.json}"

run_root="${DW_TIMERS_RUN_ROOT:-}"
run_root_supplied=1
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-timers.XXXXXX")"
  run_root_supplied=0
fi
mkdir -p "$run_root"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"

cleanup() {
  local code=$?

  if [[ "$keep_run_root" != "1" && "$code" -eq 0 && "$result_dir" != "$run_root" && "$run_root_supplied" != "1" ]]; then
    rm -rf "$run_root"
  fi
}
trap cleanup EXIT

if ! require_command node; then
  printf '%s\n' 'required command not found: node' >&2
  exit 1
fi

node "$script_dir/timers-published-artifacts.mjs" "$result_dir" "$(timestamp)" "$scenario_manifest" "$repo_root"
