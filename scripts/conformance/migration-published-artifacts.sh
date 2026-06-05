#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: migration-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Composes the public v1-to-v2 migration conformance result from published
artifact evidence only. The runner never treats a local product checkout as an
artifact under test. Missing migration cells are recorded as not_covered with
linked conformance-harness findings so storage-connection smoke cannot pass by
itself.

The runner writes these files to the result directory:
  migration-published-artifacts.json
  migration-conformance-result.json
  migration-conformance-record.json

Environment overrides:
  DW_MIGRATION_RUN_ROOT              Scratch directory. Defaults to mktemp.
  DW_MIGRATION_RESULT_DIR            Result directory. Defaults to run root.
  DW_MIGRATION_KEEP_RUN_ROOT=1       Keep scratch directory after success.
  DW_MIGRATION_EVIDENCE_JSON         Full-result or scenario-shard JSON from the host migration runner.
  DW_MIGRATION_EVIDENCE_DIR          Directory of sorted JSON evidence shards from the host migration runner.
  DW_MIGRATION_STORAGE_SMOKE_JSON    Advisory storage-connection smoke JSON.
  DW_MIGRATION_RESOLVE_PUBLIC_ARTIFACTS
                                      Resolve missing v1 artifact pins from public package registries. Defaults to 1.
  DW_MIGRATION_PUBLIC_ARTIFACTS_JSON Optional JSON fixture/cache for public artifact resolution.
  DW_SERVER_V1_VERSION               Exact latest supported v1 server artifact version.
  DW_SERVER_V1_ARTIFACT_SOURCE       Published source for the v1 server artifact.
  DW_SERVER_VERSION                  Exact target v2 server artifact version.
  DW_SERVER_ARTIFACT_SOURCE          Published source for the v2 server artifact.
  DW_CLI_V1_VERSION                  Exact latest supported v1 CLI artifact version.
  DW_CLI_V1_ARTIFACT_SOURCE          Published source for the v1 CLI artifact.
  DW_CLI_VERSION                     Exact published v2 CLI version.
  DW_CLI_ARTIFACT_SOURCE             Published source for the v2 CLI artifact.
  DW_WORKFLOW_PHP_V1_VERSION         Exact published v1 workflow package version.
  DW_WORKFLOW_PHP_V1_ARTIFACT_SOURCE Published source for the v1 workflow package.
  DW_WORKFLOW_PHP_VERSION            Exact published v2 workflow package version.
  DW_WORKFLOW_PHP_ARTIFACT_SOURCE    Published source for the v2 workflow package.
  DW_PYTHON_SDK_VERSION              Exact published Python SDK version.
  DW_PYTHON_SDK_ARTIFACT_SOURCE      Published source for the Python SDK package.
  DW_WATERLINE_V1_VERSION            Exact published v1 Waterline artifact version.
  DW_WATERLINE_V1_ARTIFACT_SOURCE    Published source for the v1 Waterline artifact.
  DW_WATERLINE_VERSION               Exact published v2 Waterline version.
  DW_WATERLINE_ARTIFACT_SOURCE       Published source for the v2 Waterline package.
  DW_SAMPLE_APP_V1_VERSION           Exact published v1-compatible sample-app tag or commit.
  DW_SAMPLE_APP_V1_ARTIFACT_SOURCE   Published source for the v1-compatible sample-app.
USAGE
}

keep_run_root="${DW_MIGRATION_KEEP_RUN_ROOT:-0}"
result_dir="${DW_MIGRATION_RESULT_DIR:-}"

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

run_root="${DW_MIGRATION_RUN_ROOT:-}"
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-migration.XXXXXX")"
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

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'required command not found: node' >&2
  exit 127
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"

export DW_MIGRATION_RESULT_DIR="$result_dir"
export DW_MIGRATION_RUN_ROOT="$run_root"
export DW_MIGRATION_REPO_ROOT="$repo_root"

node "$script_dir/migration-published-artifacts.mjs"
