#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: workflow-lifecycle-host-published-artifacts.sh [--result-dir DIR|--result-dir=DIR]

Runs workflow-lifecycle conformance from a Docker-capable host. The runner
starts the exact published server image with its HTTP and scheduler processes,
builds the Rust probe from the exact crates.io dependency in a pinned Rust
image, and then executes the published-image lifecycle result runner.

Required environment:
  DW_SERVER_VERSION       Exact server SemVer release.
  DW_RUST_SDK_VERSION     Exact durable-workflow crate version.
  DW_CLI_VERSION          Exact CLI version.
  DW_PYTHON_SDK_VERSION   Exact Python SDK version.
  DW_PHP_SDK_VERSION      Exact durable-workflow/sdk Packagist version.
  DW_WORKFLOW_PHP_VERSION Exact PHP workflow package version.
  DW_WATERLINE_VERSION    Exact Waterline version.

Optional environment:
  DW_SERVER_IMAGE         Defaults to durableworkflow/server:<server version>.
  DW_WORKFLOW_LIFECYCLE_RESULT_DIR
  DW_WORKFLOW_LIFECYCLE_RUST_IMAGE
  DW_WORKFLOW_LIFECYCLE_MYSQL_IMAGE
  DW_WORKFLOW_LIFECYCLE_REDIS_IMAGE
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

for command in docker node; do
  if ! command -v "$command" >/dev/null 2>&1; then
    printf 'required host command not found: %s\n' "$command" >&2
    exit 2
  fi
done

for variable in \
  DW_SERVER_VERSION DW_RUST_SDK_VERSION DW_CLI_VERSION \
  DW_PYTHON_SDK_VERSION DW_PHP_SDK_VERSION DW_WORKFLOW_PHP_VERSION DW_WATERLINE_VERSION; do
  if [[ -z "${!variable:-}" ]]; then
    printf 'required environment variable is empty: %s\n' "$variable" >&2
    exit 2
  fi
done

if [[ -z "$result_dir" ]]; then
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-workflow-lifecycle-host.XXXXXX")"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"

server_image="${DW_SERVER_IMAGE:-durableworkflow/server:${DW_SERVER_VERSION}}"
rust_image="${DW_WORKFLOW_LIFECYCLE_RUST_IMAGE:-rust:1.86.0-slim-bookworm}"
mysql_image="${DW_WORKFLOW_LIFECYCLE_MYSQL_IMAGE:-mysql:8.0}"
redis_image="${DW_WORKFLOW_LIFECYCLE_REDIS_IMAGE:-redis:7-alpine}"

if [[ "$server_image" != "durableworkflow/server:${DW_SERVER_VERSION}" \
  && "$server_image" != "docker.io/durableworkflow/server:${DW_SERVER_VERSION}" \
  && ! "$server_image" =~ ^(docker\.io/)?durableworkflow/server@sha256:[0-9a-fA-F]{64}$ ]]; then
  printf 'DW_SERVER_IMAGE must name the requested exact server tag or a digest: %s\n' "$server_image" >&2
  exit 2
fi

run_id="$(date -u +%s)-$$-${RANDOM}"
network_name="dw-lifecycle-${run_id}"
mysql_name="dw-lifecycle-mysql-${run_id}"
redis_name="dw-lifecycle-redis-${run_id}"
server_name="dw-lifecycle-server-${run_id}"
scheduler_name="dw-lifecycle-scheduler-${run_id}"
extractor_name="dw-lifecycle-extract-${run_id}"
artifact_root="$result_dir/published-runner-artifact"
server_follow_log="$result_dir/workflow-lifecycle-server-process.log"
scheduler_follow_log="$result_dir/workflow-lifecycle-scheduler-process.log"
server_log_pid=""
scheduler_log_pid=""

cleanup() {
  local exit_code=$?
  for log_pid in "$server_log_pid" "$scheduler_log_pid"; do
    if [[ -n "$log_pid" ]] && kill -0 "$log_pid" >/dev/null 2>&1; then
      kill "$log_pid" >/dev/null 2>&1 || true
      wait "$log_pid" >/dev/null 2>&1 || true
    fi
  done
  for container in "$server_name" "$scheduler_name" "$mysql_name" "$redis_name"; do
    if docker inspect "$container" >/dev/null 2>&1; then
      docker logs "$container" >"$result_dir/${container}.log" 2>&1 || true
      docker rm -f "$container" >/dev/null 2>&1 || true
    fi
  done
  docker rm -f "$extractor_name" >/dev/null 2>&1 || true
  docker network rm "$network_name" >/dev/null 2>&1 || true
  exit "$exit_code"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

common_server_env=(
  -e APP_ENV=production
  -e APP_DEBUG=false
  -e APP_KEY=base64:ZHVyYWJsZS13b3JrZmxvdy1saWZlY3ljbGUta2V5ISE=
  -e APP_VERSION="$DW_SERVER_VERSION"
  -e LOG_CHANNEL=stderr
  -e DB_CONNECTION=mysql
  -e DB_HOST="$mysql_name"
  -e DB_PORT=3306
  -e DB_DATABASE=durable_workflow
  -e DB_USERNAME=workflow
  -e DB_PASSWORD=workflow
  -e REDIS_HOST="$redis_name"
  -e QUEUE_CONNECTION=redis
  -e CACHE_STORE=redis
  -e DW_AUTH_DRIVER=token
  -e DW_AUTH_TOKEN=dev-token
  -e DW_TASK_DISPATCH_MODE=poll
  -e DW_V2_TASK_DISPATCH_MODE=poll
)

docker pull "$server_image"
docker pull "$rust_image"
docker pull "$mysql_image"
docker pull "$redis_image"
docker network create "$network_name" >/dev/null

docker run -d --name "$mysql_name" --network "$network_name" \
  -e MYSQL_DATABASE=durable_workflow \
  -e MYSQL_USER=workflow \
  -e MYSQL_PASSWORD=workflow \
  -e MYSQL_ROOT_PASSWORD=root \
  "$mysql_image" >/dev/null
docker run -d --name "$redis_name" --network "$network_name" "$redis_image" >/dev/null

for attempt in $(seq 1 60); do
  if docker exec "$mysql_name" mysqladmin ping -h 127.0.0.1 -uroot -proot --silent >/dev/null 2>&1 \
    && docker exec "$redis_name" redis-cli ping 2>/dev/null | grep -q PONG; then
    break
  fi
  if [[ "$attempt" == 60 ]]; then
    printf '%s\n' 'published topology dependencies did not become ready' >&2
    exit 1
  fi
  sleep 1
done

docker run --rm --network "$network_name" "${common_server_env[@]}" \
  "$server_image" server-bootstrap

docker run -d --name "$server_name" --network "$network_name" \
  "${common_server_env[@]}" \
  -e DW_SERVER_TOPOLOGY_SHAPE=standalone_server \
  -e DW_SERVER_PROCESS_CLASS=server_http_node \
  "$server_image" >/dev/null

docker run -d --name "$scheduler_name" --network "$network_name" \
  "${common_server_env[@]}" \
  -e DW_SERVER_TOPOLOGY_SHAPE=standalone_server \
  -e DW_SERVER_PROCESS_CLASS=scheduler_node \
  "$server_image" sh -c \
  'while true; do php artisan schedule:evaluate --limit=100 --json; php artisan activity:timeout-enforce --limit=100; sleep 1; done' \
  >/dev/null

docker logs --follow "$server_name" >"$server_follow_log" 2>&1 &
server_log_pid=$!
docker logs --follow "$scheduler_name" >"$scheduler_follow_log" 2>&1 &
scheduler_log_pid=$!

for attempt in $(seq 1 60); do
  if docker exec "$server_name" curl -fsS http://127.0.0.1:8080/api/ready >/dev/null 2>&1; then
    break
  fi
  if [[ "$attempt" == 60 ]]; then
    printf '%s\n' 'exact published server HTTP process did not become ready' >&2
    exit 1
  fi
  sleep 1
done

namespace_status="$(docker exec "$server_name" curl -sS \
  -o /tmp/workflow-lifecycle-namespace-response.json \
  -w '%{http_code}' \
  -X POST http://127.0.0.1:8080/api/namespaces \
  -H 'Accept: application/json' \
  -H 'Content-Type: application/json' \
  -H 'Authorization: Bearer dev-token' \
  -H 'X-Namespace: default' \
  -H 'X-Durable-Workflow-Control-Plane-Version: 2' \
  --data '{"name":"workflow-lifecycle-conformance","description":"Published workflow lifecycle conformance","retention_days":7}')"
if [[ "$namespace_status" != 200 && "$namespace_status" != 201 && "$namespace_status" != 409 ]]; then
  docker exec "$server_name" cat /tmp/workflow-lifecycle-namespace-response.json >&2 || true
  printf 'workflow lifecycle namespace setup failed with HTTP %s\n' "$namespace_status" >&2
  exit 1
fi

rm -rf "$artifact_root"
mkdir -p "$artifact_root/scripts" "$artifact_root/static/platform-conformance"
docker create --name "$extractor_name" "$server_image" >/dev/null
docker cp "$extractor_name:/app/scripts/conformance" "$artifact_root/scripts/"
docker cp \
  "$extractor_name:/app/static/platform-conformance/workflow-lifecycle-scenarios.json" \
  "$artifact_root/static/platform-conformance/workflow-lifecycle-scenarios.json"
docker rm "$extractor_name" >/dev/null

rust_exit=0
RESULT_DIR="$result_dir" \
REPO_ROOT="$artifact_root" \
DW_SERVER_IMAGE="$server_image" \
DW_WORKFLOW_LIFECYCLE_SERVER_URL="http://${server_name}:8080" \
DW_WORKFLOW_LIFECYCLE_RUST_DOCKER_NETWORK="$network_name" \
DW_WORKFLOW_LIFECYCLE_RUST_IMAGE="$rust_image" \
DW_WORKFLOW_LIFECYCLE_AUTH_TOKEN=dev-token \
DW_WORKFLOW_LIFECYCLE_NAMESPACE=workflow-lifecycle-conformance \
DW_WORKFLOW_LIFECYCLE_SERVER_HTTP_PROCESS=exact_published_image \
DW_WORKFLOW_LIFECYCLE_SCHEDULER_PROCESS=exact_published_image \
DW_WORKFLOW_LIFECYCLE_RUST_EXECUTOR=host_rust_container \
node "$artifact_root/scripts/conformance/workflow-lifecycle-rust-published-artifacts.mjs" \
  || rust_exit=$?

docker run --rm --network "$network_name" \
  "${common_server_env[@]}" \
  -v "$result_dir:/result" \
  -e DW_WORKFLOW_LIFECYCLE_RESULT_DIR=/result \
  -e DW_WORKFLOW_LIFECYCLE_SKIP_RUST_SDK_PROBE=1 \
  -e DW_WORKFLOW_LIFECYCLE_SERVER_URL="http://${server_name}:8080" \
  -e DW_SERVER_IMAGE="$server_image" \
  -e DW_SERVER_VERSION="$DW_SERVER_VERSION" \
  -e DW_RUST_SDK_VERSION="$DW_RUST_SDK_VERSION" \
  -e DW_CLI_VERSION="$DW_CLI_VERSION" \
  -e DW_PYTHON_SDK_VERSION="$DW_PYTHON_SDK_VERSION" \
  -e DW_PHP_SDK_VERSION="$DW_PHP_SDK_VERSION" \
  -e DW_PHP_SDK_CONFORMANCE_SERVER_LOG=/result/workflow-lifecycle-server-process.log \
  -e DW_PHP_SDK_CONFORMANCE_SCHEDULER_LOG=/result/workflow-lifecycle-scheduler-process.log \
  -e DW_WORKFLOW_PHP_VERSION="$DW_WORKFLOW_PHP_VERSION" \
  -e DW_WATERLINE_VERSION="$DW_WATERLINE_VERSION" \
  "$server_image" \
  scripts/conformance/workflow-lifecycle-published-artifacts.sh --result-dir /result

RESULT_DIR="$result_dir" RUST_EXIT="$rust_exit" node <<'NODE'
const fs = require('node:fs');
const path = require('node:path');
const result = JSON.parse(fs.readFileSync(path.join(process.env.RESULT_DIR, 'workflow-lifecycle-result.json'), 'utf8'));
const rust = result.scenario_results?.rust_sdk_lifecycle_surface;
if (Number(process.env.RUST_EXIT) !== 0 || result.outcome !== 'pass' || rust?.status !== 'pass') {
  process.exit(1);
}
NODE
