#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: heartbeats-python-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]

Runs the focused published-artifact Python SDK heartbeat-loop contract and writes:
  pins.json
  run-metadata.json
  python-sdk-heartbeat-loop-evidence.json
  heartbeat-cadence-dataset.json
  heartbeat-request-response-captures.json
On persistent control-plane transport loss it also writes server-container.log.

Required exact artifact pins:
  DW_SERVER_VERSION
  DW_CLI_VERSION
  DW_PYTHON_SDK_VERSION

Optional overrides:
  DW_SERVER_IMAGE                     Exact durableworkflow/server image tag or digest.
  DW_HEARTBEATS_AUTH_TOKEN            Defaults to dev-token.
  DW_HEARTBEATS_NAMESPACE             Defaults to heartbeats-conformance.
  DW_HEARTBEATS_SHARED_SERVER_STATE   Reuse a verified wave bootstrap receipt;
                                       requires its prescribed Python namespace.
  DW_HEARTBEATS_PYTHON_IMAGE          Defaults to python:3.12-slim.
  DW_HEARTBEATS_SERVER_HOST           Host-side server address; defaults to 127.0.0.1.
  DW_HEARTBEATS_HEARTBEAT_SECONDS     Self-started server cadence; defaults to 2.
  DW_HEARTBEATS_STALE_AFTER_SECONDS   Self-started server stale window; defaults to 7.
  DW_HEARTBEATS_POST_STOP_DETAIL_ATTEMPTS
                                       Bounded post-stop worker-detail attempts; defaults to 3.
  DW_HEARTBEATS_POST_STOP_DETAIL_RETRY_MS
                                       Delay between focused read retries; defaults to 1000.
  DW_HEARTBEATS_FINAL_VISIBILITY_ATTEMPTS
                                       Bounded final API/CLI attempts; defaults to 3.
  DW_HEARTBEATS_FINAL_VISIBILITY_RETRY_MS
                                       Delay between transport retries; defaults to 1000.
  DW_HEARTBEATS_KEEP_RUN_ROOT         Set to 1 to retain the scratch installation.
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
      [[ -n "$result_dir" ]] || { printf '%s\n' '--result-dir requires a value' >&2; exit 2; }
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
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-heartbeats-python.XXXXXX")"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"

if ! command -v node >/dev/null 2>&1; then
  printf '%s\n' 'required command not found: node' >&2
  exit 1
fi

DW_HEARTBEATS_CELL=python \
RESULT_DIR="$result_dir" \
REPO_ROOT="$repo_root" \
exec node "$script_dir/heartbeats-published-artifacts.mjs"
