#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${DW_PUBLISHED_COMPOSE_FILE:-$ROOT_DIR/docker-compose.published.yml}"
PROJECT_RAW="${DW_PUBLISHED_COMPOSE_PROJECT:-dw-server-published-smoke-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${DOCKER_DEFAULT_PLATFORM:-native}}"
PROJECT="$(printf '%s' "$PROJECT_RAW" | tr -c '[:alnum:]_-' '-')"
SERVER_PORT="${SERVER_PORT:-18080}"
TOKEN="${DW_AUTH_TOKEN:-dev-token}"
WORKER_ID="${DW_SMOKE_WORKER_ID:-published-compose-smoke-worker}"
TASK_QUEUE="${DW_SMOKE_TASK_QUEUE:-published-compose-smoke}"
ENDPOINT_MODE="${DW_PUBLISHED_COMPOSE_ENDPOINT_MODE:-host}"
SERVER_PLATFORM="${DW_SERVER_PLATFORM:-${DOCKER_DEFAULT_PLATFORM:-}}"

export DW_SERVER_PLATFORM="$SERVER_PLATFORM"
unset DOCKER_DEFAULT_PLATFORM

if [ "$ENDPOINT_MODE" = "container" ]; then
  BASE_URL="http://server:8080"
else
  BASE_URL="http://127.0.0.1:${SERVER_PORT}"
fi

export SERVER_PORT
export DW_AUTH_TOKEN="$TOKEN"

compose() {
  docker compose -p "$PROJECT" -f "$COMPOSE_FILE" "$@"
}

curl_json_with_retry() {
  local output="$1"
  shift

  for attempt in $(seq 1 60); do
    if [ "$ENDPOINT_MODE" = "container" ]; then
      if compose exec -T server curl -fsS "$@" >"$output"; then
        cat "$output"
        echo
        return 0
      fi
    elif curl -fsS "$@" >"$output"; then
      cat "$output"
      echo
      return 0
    fi

    if [ "$attempt" -eq 60 ]; then
      echo "Request failed after ${attempt} attempts: curl $*" >&2
      compose ps >&2 || true
      compose logs server >&2 || true
      return 1
    fi

    sleep 1
  done
}

cleanup() {
  compose down -v --remove-orphans >/dev/null 2>&1 || true
}

trap cleanup EXIT

echo "Published Compose smoke"
echo "  project: $PROJECT"
echo "  compose: $COMPOSE_FILE"
echo "  image: ${DW_SERVER_IMAGE:-durableworkflow/server:${DW_SERVER_TAG:-0.2}}"
echo "  platform: ${DW_SERVER_PLATFORM:-docker-default}"
echo "  port: $SERVER_PORT"
echo "  endpoint: $ENDPOINT_MODE"

compose pull
compose up -d --wait

curl_json_with_retry /tmp/dw-server-compose-health.json "${BASE_URL}/api/health"
curl_json_with_retry /tmp/dw-server-compose-ready.json "${BASE_URL}/api/ready"
curl_json_with_retry /tmp/dw-server-compose-cluster.json \
  -H "Authorization: Bearer ${TOKEN}" \
  "${BASE_URL}/api/cluster/info"

curl_json_with_retry /tmp/dw-server-compose-worker.json \
  -X POST "${BASE_URL}/api/worker/register" \
  -H "Authorization: Bearer ${TOKEN}" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1.0" \
  -d "{\"worker_id\":\"${WORKER_ID}\",\"task_queue\":\"${TASK_QUEUE}\",\"runtime\":\"python\"}"

DW_SMOKE_WORKER_ID="$WORKER_ID" DW_SMOKE_TASK_QUEUE="$TASK_QUEUE" python3 - <<'PY'
import json
import os
from pathlib import Path

health = json.loads(Path("/tmp/dw-server-compose-health.json").read_text())
ready = json.loads(Path("/tmp/dw-server-compose-ready.json").read_text())
cluster = json.loads(Path("/tmp/dw-server-compose-cluster.json").read_text())
worker = json.loads(Path("/tmp/dw-server-compose-worker.json").read_text())

assert health.get("status") == "serving", health
assert ready.get("status") == "ready", ready
assert cluster.get("version"), cluster
assert worker.get("worker_id") == os.environ["DW_SMOKE_WORKER_ID"], worker
assert worker.get("registered") is True, worker
PY

echo "Published Compose smoke passed"
