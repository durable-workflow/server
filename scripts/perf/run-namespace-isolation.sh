#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
RUN_ID="${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-$(date +%s)"
PROJECT="${DW_ISOLATION_COMPOSE_PROJECT:-dw-namespace-isolation-${RUN_ID}}"
ARTIFACT_DIR="${DW_ISOLATION_ARTIFACT_DIR:-$ROOT_DIR/build/namespace-isolation}"
SERVER_VERSION="${DW_ISOLATION_SERVER_VERSION:-2.0.3}"
SERVER_IMAGE="${DW_ISOLATION_SERVER_IMAGE:-durableworkflow/server:$SERVER_VERSION}"
SDK_VERSION="${DW_ISOLATION_PYTHON_SDK_VERSION:-2.0.0}"
TOKEN="${DW_ISOLATION_TOKEN:-namespace-isolation-token}"
SERVER_CPUS="${DW_ISOLATION_SERVER_CPUS:-1.0}"
SERVER_MEMORY="${DW_ISOLATION_SERVER_MEMORY:-512m}"
NOISY_PRODUCERS="${DW_ISOLATION_NOISY_PRODUCERS:-1}"
NOISY_REQUESTS_PER_MINUTE="${DW_ISOLATION_NOISY_REQUESTS_PER_MINUTE:-30}"
NOISY_CONCURRENT_REQUESTS="${DW_ISOLATION_NOISY_CONCURRENT_REQUESTS:-1}"
CONTROL_LATENCY_LIMIT_SECONDS="${DW_ISOLATION_CONTROL_LATENCY_LIMIT_SECONDS:-15}"
COMPOSE_FILE="$ARTIFACT_DIR/docker-compose.yml"
VENV="$ARTIFACT_DIR/venv"
HOST_PORT="${DW_ISOLATION_HOST_PORT:-$(python3 - <<'PY'
import socket

with socket.socket() as sock:
    sock.bind(("", 0))
    print(sock.getsockname()[1])
PY
)}"

mkdir -p "$ARTIFACT_DIR"

cleanup() {
  local status=$?
  for service in server scheduler mysql redis; do
    docker compose -p "$PROJECT" -f "$COMPOSE_FILE" logs --no-color "$service" > "$ARTIFACT_DIR/$service.log" 2>&1 || true
  done
  docker compose -p "$PROJECT" -f "$COMPOSE_FILE" down -v --remove-orphans || true
  exit "$status"
}
trap cleanup EXIT

cat > "$COMPOSE_FILE" <<YAML
services:
  bootstrap:
    image: "$SERVER_IMAGE"
    command: ["server-bootstrap"]
    environment: &server-env
      APP_ENV: local
      APP_DEBUG: "false"
      APP_VERSION: "$SERVER_VERSION"
      DW_SERVER_KEY: "base64:dGVzdGluZy1rZXktMTIzNDU2Nzg5MDEyMzQ1Njc4OTAxMg=="
      DB_CONNECTION: mysql
      DB_HOST: mysql
      DB_PORT: 3306
      DB_DATABASE: durable_workflow
      DB_USERNAME: workflow
      DB_PASSWORD: workflow
      REDIS_HOST: redis
      QUEUE_CONNECTION: redis
      CACHE_STORE: redis
      DW_AUTH_DRIVER: token
      DW_AUTH_TOKEN: "$TOKEN"
      DW_AUTH_BACKWARD_COMPATIBLE: "true"
      DW_WORKER_POLL_TIMEOUT: "2"
      DW_NAMESPACE_ADMISSION_OVERRIDES: '{"noisy":{"max_requests_per_minute":$NOISY_REQUESTS_PER_MINUTE,"max_concurrent_requests":$NOISY_CONCURRENT_REQUESTS},"control":{"max_requests_per_minute":6000,"max_concurrent_requests":8}}'
      DW_NAMESPACE_DURABLE_OVERRIDES: '{"noisy":{"max_workflow_instances":100,"max_workflow_runs":100,"max_open_workflow_runs":10,"max_schedules":12,"max_schedule_history_events":24,"max_worker_registrations":4,"max_workflow_history_events":800,"max_workflow_tasks":400,"max_pending_workflow_tasks":24,"max_workflow_timers":100,"max_pending_workflow_timers":10,"max_workflow_run_waits":100,"max_open_workflow_run_waits":10,"max_workflow_commands":400,"max_workflow_streams":20,"max_workflow_stream_items":100},"control":{"max_workflow_instances":1000,"max_workflow_runs":1000,"max_open_workflow_runs":100,"max_schedules":100,"max_schedule_history_events":1000,"max_worker_registrations":8,"max_workflow_history_events":10000,"max_workflow_tasks":5000,"max_pending_workflow_tasks":100,"max_workflow_timers":1000,"max_pending_workflow_timers":100,"max_workflow_run_waits":1000,"max_open_workflow_run_waits":100,"max_workflow_commands":5000,"max_workflow_streams":100,"max_workflow_stream_items":1000}}'
      DW_WORKFLOW_TASK_MAX_ACTIVE_LEASES_PER_NAMESPACE: "4"
      DW_ACTIVITY_TASK_MAX_ACTIVE_LEASES_PER_NAMESPACE: "4"
      DW_WORKER_LONG_POLL_MAX_CONCURRENT: "8"
      DW_WORKER_LONG_POLL_MAX_CONCURRENT_PER_NAMESPACE: "4"
      DW_QUERY_TASK_POLL_MAX_CONCURRENT: "4"
      DW_QUERY_TASK_POLL_MAX_CONCURRENT_PER_NAMESPACE: "2"
      DW_EXTERNAL_PAYLOAD_MAX_BYTES_PER_NAMESPACE: "65536"
      DW_EXTERNAL_PAYLOAD_MAX_OBJECTS_PER_NAMESPACE: "16"
      DW_SERVICE_BOUNDARY_NAMESPACE_RATE_LIMIT_PER_MINUTE: "60"
      DW_SERVICE_BOUNDARY_NAMESPACE_MAX_IN_FLIGHT: "2"
    depends_on:
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy

  server:
    image: "$SERVER_IMAGE"
    ports:
      - "0.0.0.0:${HOST_PORT}:8080"
    mem_limit: "$SERVER_MEMORY"
    cpus: $SERVER_CPUS
    environment: *server-env
    depends_on:
      bootstrap:
        condition: service_completed_successfully
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy
    healthcheck:
      test: ["CMD", "curl", "-f", "http://localhost:8080/api/ready"]
      interval: 2s
      timeout: 2s
      retries: 30

  scheduler:
    image: "$SERVER_IMAGE"
    command: ["sh", "-c", "while true; do php artisan schedule:evaluate --limit=100 --json; php artisan activity:timeout-enforce --limit=100; php artisan history:prune --limit=100; sleep 10; done"]
    mem_limit: 256m
    cpus: 0.25
    environment: *server-env
    depends_on:
      bootstrap:
        condition: service_completed_successfully
      mysql:
        condition: service_healthy
      redis:
        condition: service_healthy

  mysql:
    image: mysql:8.0
    mem_limit: 512m
    cpus: 0.5
    environment:
      MYSQL_DATABASE: durable_workflow
      MYSQL_USER: workflow
      MYSQL_PASSWORD: workflow
      MYSQL_ROOT_PASSWORD: root
    tmpfs:
      - /var/lib/mysql
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 2s
      timeout: 2s
      retries: 30

  redis:
    image: redis:7-alpine
    mem_limit: 128m
    cpus: 0.25
    tmpfs:
      - /data
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 2s
      timeout: 2s
      retries: 30
YAML

docker compose -p "$PROJECT" -f "$COMPOSE_FILE" up -d --wait
BASE_URL="http://127.0.0.1:$HOST_PORT"
if ! curl --fail --silent --max-time 2 "$BASE_URL/api/ready" >/dev/null; then
  DOCKER_HOST_GATEWAY="$(ip route show default 2>/dev/null | awk 'NR == 1 {print $3}')"
  BASE_URL="http://${DOCKER_HOST_GATEWAY}:$HOST_PORT"
  curl --fail --silent --max-time 5 "$BASE_URL/api/ready" >/dev/null
fi

python3 -m venv "$VENV"
"$VENV/bin/pip" install --quiet --disable-pip-version-check "durable-workflow==$SDK_VERSION"
"$VENV/bin/python" "$ROOT_DIR/scripts/perf/namespace_isolation.py" \
  --base-url "$BASE_URL" \
  --token "$TOKEN" \
  --compose-project "$PROJECT" \
  --duration-seconds "${DW_ISOLATION_DURATION_SECONDS:-120}" \
  --server-image "$SERVER_IMAGE" \
  --sdk-version "$SDK_VERSION" \
  --server-cpus "$SERVER_CPUS" \
  --server-memory "$SERVER_MEMORY" \
  --noisy-producers "$NOISY_PRODUCERS" \
  --noisy-requests-per-minute "$NOISY_REQUESTS_PER_MINUTE" \
  --noisy-concurrent-requests "$NOISY_CONCURRENT_REQUESTS" \
  --control-latency-limit-seconds "$CONTROL_LATENCY_LIMIT_SECONDS" \
  --artifact "$ARTIFACT_DIR/result.json"

echo "Namespace isolation result: $ARTIFACT_DIR/result.json"
