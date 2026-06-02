#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: worker-versioning-published-artifacts.sh [--result-dir DIR|--result-dir=DIR] [--keep-run-root[=1|true]]

Runs the public worker-versioning runtime routing cells against published
artifacts only. The runner records worker task delivery counts for v1-pinned
runs while v1 and v2 workers poll the same task queue.

The runner writes these files to the result directory:
  published-artifacts.json
  worker-versioning-result.json
  worker-versioning-record.json
  worker-versioning-http-captures.json

Environment overrides:
  DW_WV_RUN_ROOT             Scratch directory. Defaults to mktemp.
  DW_WV_RESULT_DIR           Result directory. Defaults to run root.
  DW_WV_KEEP_RUN_ROOT=1      Keep scratch directory after success.
  DW_WV_SERVER_URL           Existing published server URL to probe; disables compose startup.
  DW_SERVER_IMAGE            Exact server image/tag/digest to test.
  DW_SERVER_VERSION          Exact published server version under test.
  DW_CLI_VERSION             Published CLI version under test.
  DW_PYTHON_SDK_VERSION      Published PyPI durable-workflow version under test.
  DW_WORKFLOW_PHP_VERSION    Published durable-workflow/workflow version under test.
  DW_WATERLINE_VERSION       Published Waterline version under test.
  DW_WV_SERVER_PORT          Host port for the published server. Defaults to a free port.
  DW_WV_AUTH_TOKEN           Token used against the published server. Defaults to dev-token.
  DW_WV_NAMESPACE            Namespace used for probes. Defaults to worker-versioning-conformance.
  DW_WV_ARTIFACT_INSTALL_EVIDENCE
                              Optional JSON report proving CLI, Python SDK, PHP workflow,
                              and Waterline installs from published artifact channels.
  DW_WV_PUBLISHED_WORKER_EVIDENCE
                              Optional JSON report from a host topology that executed
                              replay/cache/cross-language cells with published workers.
                              When unset, this runner attempts to generate a Python
                              replay/cache shard and a PHP/Python cross-language shard
                              from published PyPI and Packagist artifacts.
  DW_WV_SKIP_PUBLISHED_WORKER_SHARD=1
                              Skip automatic published PHP/Python worker shard generation.
USAGE
}

keep_run_root="${DW_WV_KEEP_RUN_ROOT:-0}"
result_dir="${DW_WV_RESULT_DIR:-}"

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
  command -v "$1" >/dev/null 2>&1
}

is_exact_semver() {
  local version="$1"

  [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+([.-][0-9A-Za-z.-]+)?$ ]]
}

free_port() {
  node - <<'NODE'
const net = require('node:net');
const server = net.createServer();
server.listen(0, '127.0.0.1', () => {
  const address = server.address();
  console.log(address.port);
  server.close();
});
NODE
}

wait_for_server() {
  local url="$1"

  node - <<'NODE' "$url"
const baseUrl = process.argv[2].replace(/\/+$/, '');
const readyUrl = `${baseUrl}/api/ready`;
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

(async () => {
  for (let attempt = 0; attempt < 120; attempt += 1) {
    try {
      const response = await fetch(readyUrl);
      if (response.ok) {
        process.exit(0);
      }
    } catch {
    }

    await sleep(1000);
  }

  console.error(`published server did not become ready at ${readyUrl}`);
  process.exit(1);
})();
NODE
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"

run_root="${DW_WV_RUN_ROOT:-}"
if [[ -z "$run_root" ]]; then
  run_root="$(mktemp -d "${TMPDIR:-/tmp}/dw-worker-versioning.XXXXXX")"
fi
mkdir -p "$run_root"

if [[ -z "$result_dir" ]]; then
  result_dir="$run_root"
fi
mkdir -p "$result_dir"

run_label="$(printf '%s' "$(basename "$run_root")" | tr '[:upper:]' '[:lower:]' | tr -c 'a-z0-9_-' '-')"
compose_project="dw-worker-versioning-${run_label}"
server_url="${DW_WV_SERVER_URL:-}"
server_started=0
compose_cleanup_needed=0
server_artifact_source="published_server_url"

cleanup() {
  local code=$?

  if [[ "$server_started" == "1" || "$compose_cleanup_needed" == "1" ]]; then
    docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" down -v >/dev/null 2>&1 || true
  fi

  if [[ "$keep_run_root" != "1" && "$code" -eq 0 && "$result_dir" != "$run_root" ]]; then
    rm -rf "$run_root"
  fi
}
trap cleanup EXIT

write_blocked_result() {
  local reason="$1"

  DW_WV_BLOCKED_REASON="$reason" \
  DW_WV_RESULT_DIR="$result_dir" \
  DW_WV_RUN_ROOT="$run_root" \
  DW_WV_REPO_ROOT="$repo_root" \
  node "$script_dir/worker-versioning-published-artifacts.mjs"
}

if ! require_command node; then
  printf '%s\n' 'required command not found: node' >&2
  exit 127
fi

if [[ -z "$server_url" ]]; then
  if ! require_command docker; then
    write_blocked_result 'worker-versioning conformance runner requires docker unless DW_WV_SERVER_URL points at an already running published server'
    exit 0
  fi

  if ! docker compose version >/dev/null 2>&1; then
    write_blocked_result 'worker-versioning conformance runner requires docker compose to start the published server topology'
    exit 0
  fi

  server_port="${DW_WV_SERVER_PORT:-$(free_port)}"
  server_url="http://127.0.0.1:${server_port}"
  server_image="${DW_SERVER_IMAGE:-}"
  if [[ -z "$server_image" ]]; then
    if [[ -z "${DW_SERVER_VERSION:-}" ]]; then
      write_blocked_result 'DW_SERVER_VERSION or DW_SERVER_IMAGE is required so worker-versioning conformance can run an exact published server artifact'
      exit 0
    fi
    server_image="durableworkflow/server:${DW_SERVER_VERSION}"
  fi

  if [[ "$server_image" == *@sha256:* && -z "${DW_SERVER_VERSION:-}" ]]; then
    write_blocked_result 'DW_SERVER_VERSION is required when DW_SERVER_IMAGE is digest-pinned so the run record carries a concrete server artifact version'
    exit 0
  fi

  if [[ "$server_image" != *@sha256:* ]]; then
    image_tag="${server_image##*:}"
    if [[ "$image_tag" == "$server_image" ]] || ! is_exact_semver "$image_tag"; then
      write_blocked_result "DW_SERVER_IMAGE must use an exact patch semver tag or an image digest; got ${server_image}"
      exit 0
    fi
    if [[ -n "${DW_SERVER_VERSION:-}" && "${DW_SERVER_VERSION}" != "$image_tag" ]]; then
      write_blocked_result "DW_SERVER_VERSION ${DW_SERVER_VERSION} does not match DW_SERVER_IMAGE tag ${image_tag}"
      exit 0
    fi
    export DW_SERVER_VERSION="${DW_SERVER_VERSION:-$image_tag}"
  fi
  server_artifact_source="docker"
  compose_cleanup_needed=1

  if ! docker image pull "$server_image" >"$result_dir/docker-image-pull.log" 2>&1; then
    write_blocked_result "published server image pull failed for ${server_image}; see docker-image-pull.log"
    exit 0
  fi

  docker image inspect "$server_image" >"$result_dir/docker-image-inspect.json" 2>&1 || true

  if ! SERVER_PORT="$server_port" \
    DW_SERVER_IMAGE="$server_image" \
    DW_SERVER_TAG="${DW_SERVER_VERSION:-}" \
    DW_AUTH_TOKEN="${DW_WV_AUTH_TOKEN:-dev-token}" \
    DW_WORKER_POLL_TIMEOUT="${DW_WV_WORKER_POLL_TIMEOUT:-1}" \
    DW_WORKER_POLL_INTERVAL_MS="${DW_WV_WORKER_POLL_INTERVAL_MS:-100}" \
    docker compose -p "$compose_project" -f "$repo_root/docker-compose.published.yml" up -d server >"$result_dir/docker-compose-up.log" 2>&1; then
    write_blocked_result "published server failed to start from ${server_image}; see docker-compose-up.log"
    exit 0
  fi
  server_started=1

  if ! wait_for_server "$server_url"; then
    write_blocked_result "published server did not become ready at ${server_url}/api/ready"
    exit 0
  fi
fi

export DW_WV_SERVER_URL="$server_url"
export DW_WV_SERVER_ARTIFACT_SOURCE="$server_artifact_source"
export DW_WV_RESULT_DIR="$result_dir"
export DW_WV_RUN_ROOT="$run_root"
export DW_WV_REPO_ROOT="$repo_root"

if [[ -z "${DW_WV_PUBLISHED_WORKER_EVIDENCE:-}" ]]; then
  export DW_WV_PUBLISHED_WORKER_EVIDENCE="$result_dir/published-worker-execution-evidence.json"
fi

if [[ "${DW_WV_SKIP_PUBLISHED_WORKER_SHARD:-0}" != "1" ]]; then
  node "$script_dir/worker-versioning-published-workers.mjs"
fi

node "$script_dir/worker-versioning-published-artifacts.mjs"
