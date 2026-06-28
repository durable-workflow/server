#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: workflow-lifecycle-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]

Writes the published-artifact workflow lifecycle conformance result.

The runner writes these files to the result directory:
  pins.json
  run-metadata.json
  workflow-lifecycle-result.json
  workflow-lifecycle-record.json
  workflow-lifecycle-findings.json
  lifecycle-result.json
  lifecycle-record.json

Environment overrides:
  DW_WORKFLOW_LIFECYCLE_RESULT_DIR  Result directory when --result-dir is omitted.
  DW_WORKFLOW_LIFECYCLE_EVIDENCE    Inline JSON evidence from the host runtime shard.
  DW_WORKFLOW_LIFECYCLE_EVIDENCE_PATH
                                    JSON evidence file. Defaults to
                                    workflow-lifecycle-evidence.json in the result directory.
  DW_SERVER_IMAGE                   Exact server image tag or digest under test.
  DW_SERVER_VERSION                 Exact server version under test.
  DW_CLI_VERSION                    Exact CLI release version.
  DW_PYTHON_SDK_VERSION             Exact PyPI durable-workflow version.
  DW_WORKFLOW_PHP_VERSION           Exact Composer durable-workflow/workflow version.
  DW_WATERLINE_VERSION              Exact Waterline artifact version.
USAGE
}

result_dir="${DW_WORKFLOW_LIFECYCLE_RESULT_DIR:-}"

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
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-workflow-lifecycle.XXXXXX")"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"

timestamp() {
  date -u '+%Y-%m-%dT%H:%M:%SZ'
}

started_at="$(timestamp)"
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
manifest_path="${DW_WORKFLOW_LIFECYCLE_SCENARIO_MANIFEST:-$repo_root/static/platform-conformance/workflow-lifecycle-scenarios.json}"

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'required command not found: node' >&2
  exit 1
fi

RESULT_DIR="$result_dir" \
STARTED_AT="$started_at" \
MANIFEST_PATH="$manifest_path" \
DW_SERVER_IMAGE="${DW_SERVER_IMAGE:-}" \
DW_SERVER_VERSION="${DW_SERVER_VERSION:-}" \
DW_CLI_VERSION="${DW_CLI_VERSION:-}" \
DW_PYTHON_SDK_VERSION="${DW_PYTHON_SDK_VERSION:-}" \
DW_WORKFLOW_PHP_VERSION="${DW_WORKFLOW_PHP_VERSION:-}" \
DW_WATERLINE_VERSION="${DW_WATERLINE_VERSION:-}" \
node "$script_dir/workflow-lifecycle-published-artifacts.mjs"
