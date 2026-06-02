#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: schedules-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Builds a schedules conformance result from published-artifact evidence only.
The runner never treats a Python CRUD smoke as complete schedule conformance:
unexecuted cadence, restart, public-client, and cross-language cells are
emitted as not_covered with focused findings.

The runner writes these files to the result directory:
  published-artifacts.json
  schedules-runtime-result.json
  schedules-runtime-record.json

Environment overrides:
  DW_SCHEDULES_RUN_ROOT             Scratch directory. Defaults to mktemp.
  DW_SCHEDULES_RESULT_DIR           Result directory. Defaults to run root.
  DW_SCHEDULES_KEEP_RUN_ROOT=1      Keep scratch directory after success.
  DW_SERVER_VERSION                 Published server version under test.
  DW_CLI_VERSION                    Published CLI version under test.
  DW_PYTHON_SDK_VERSION             Published PyPI durable-workflow version.
  DW_WORKFLOW_PHP_VERSION           Published durable-workflow/workflow version.
  DW_WATERLINE_VERSION              Published Waterline version under test.
  DW_SCHEDULES_SMOKE_EVIDENCE       Optional JSON from a published-artifact
                                    smoke or shard run. Scenario results in
                                    this file are merged into the output.
USAGE
}

keep_run_root="${DW_SCHEDULES_KEEP_RUN_ROOT:-0}"
result_dir="${DW_SCHEDULES_RESULT_DIR:-}"

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
  command -v "$1" >/dev/null 2>&1
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"

run_root="${DW_SCHEDULES_RUN_ROOT:-}"
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-schedules.XXXXXX")"
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

if ! require_command node; then
  printf '%s\n' 'required command not found: node' >&2
  exit 127
fi

export DW_SCHEDULES_RESULT_DIR="$result_dir"
export DW_SCHEDULES_RUN_ROOT="$run_root"
export DW_SCHEDULES_REPO_ROOT="$repo_root"

node "$script_dir/schedules-published-artifacts.mjs"
