#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ARTIFACT_DIR="${DW_PERF_ARTIFACT_DIR:-$ROOT_DIR/build/perf}"
RUN_ID="${GITHUB_RUN_ID:-local}-$(date +%s)"
PROJECT="${DW_PERF_COMPOSE_PROJECT:-dw-server-perf-$RUN_ID}"
SERVER_PORT="${DW_PERF_SERVER_PORT:-18080}"
MYSQL_PORT="${DW_PERF_MYSQL_PORT:-13306}"
REDIS_PORT="${DW_PERF_REDIS_PORT:-16379}"
METRICS_PORT="${DW_PERF_METRICS_PORT:-19090}"
AUTH_TOKEN="${DW_PERF_AUTH_TOKEN:-perf-token}"
POLL_TIMEOUT="${DW_PERF_POLL_TIMEOUT:-1}"
PROMETHEUS_CONTAINER="${PROJECT}-prometheus"
PROMETHEUS_CONFIG_DIR=""

mkdir -p "$ARTIFACT_DIR"

if [ -z "${APP_KEY:-}" ]; then
  APP_KEY="base64:$(openssl rand -base64 32)"
  export APP_KEY
fi

export APP_VERSION="${APP_VERSION:-2.0.0-perf}"
export DW_AUTH_DRIVER="${DW_AUTH_DRIVER:-token}"
export DW_AUTH_TOKEN="${DW_AUTH_TOKEN:-$AUTH_TOKEN}"
export DW_WORKER_TOKEN="${DW_WORKER_TOKEN:-}"
export DW_OPERATOR_TOKEN="${DW_OPERATOR_TOKEN:-}"
export DW_ADMIN_TOKEN="${DW_ADMIN_TOKEN:-}"
export DW_AUTH_BACKWARD_COMPATIBLE="${DW_AUTH_BACKWARD_COMPATIBLE:-true}"

OVERRIDE_FILE="$ARTIFACT_DIR/docker-compose.perf.yml"
cat > "$OVERRIDE_FILE" <<YAML
services:
  bootstrap:
    environment:
      LOG_LEVEL: warning
      DW_WORKER_POLL_TIMEOUT: "$POLL_TIMEOUT"
      DW_WORKER_POLL_INTERVAL_MS: "50"
      DW_WORKER_POLL_SIGNAL_CHECK_INTERVAL_MS: "25"
  server:
    ports: !override
      - "${SERVER_PORT}:8080"
    environment:
      LOG_LEVEL: warning
      DW_WORKER_POLL_TIMEOUT: "$POLL_TIMEOUT"
      DW_WORKER_POLL_INTERVAL_MS: "50"
      DW_WORKER_POLL_SIGNAL_CHECK_INTERVAL_MS: "25"
  worker:
    environment:
      LOG_LEVEL: warning
      DW_WORKER_POLL_TIMEOUT: "$POLL_TIMEOUT"
      DW_WORKER_POLL_INTERVAL_MS: "50"
      DW_WORKER_POLL_SIGNAL_CHECK_INTERVAL_MS: "25"
  scheduler:
    environment:
      LOG_LEVEL: warning
  mysql:
    ports: !override []
    healthcheck:
      test: ["CMD", "mysqladmin", "ping", "-h", "localhost"]
      interval: 5s
      timeout: 3s
      retries: 24
      start_period: 30s
  redis:
    ports: !override []
YAML

cleanup() {
  local status=$?

  docker logs "${PROJECT}-server-1" > "$ARTIFACT_DIR/server.log" 2>&1 || true
  docker logs "${PROJECT}-worker-1" > "$ARTIFACT_DIR/worker.log" 2>&1 || true
  docker logs "${PROJECT}-scheduler-1" > "$ARTIFACT_DIR/scheduler.log" 2>&1 || true
  docker logs "${PROJECT}-mysql-1" > "$ARTIFACT_DIR/mysql.log" 2>&1 || true
  docker logs "${PROJECT}-redis-1" > "$ARTIFACT_DIR/redis.log" 2>&1 || true

  docker rm -f "$PROMETHEUS_CONTAINER" >/dev/null 2>&1 || true
  if [ -n "$PROMETHEUS_CONFIG_DIR" ]; then
    rm -rf "$PROMETHEUS_CONFIG_DIR"
  fi

  docker compose -p "$PROJECT" -f "$ROOT_DIR/docker-compose.yml" -f "$OVERRIDE_FILE" down -v --remove-orphans || true
  exit "$status"
}
trap cleanup EXIT

maybe_start_prometheus() {
  if [ "${DW_PERF_REMOTE_WRITE_ENABLED:-true}" != "true" ]; then
    echo "Prometheus remote_write disabled for this run; writing local perf artifacts only."
    return
  fi

  if [ -z "${DW_PERF_REMOTE_WRITE_URL:-}" ] \
    || [ -z "${DW_PERF_REMOTE_WRITE_USERNAME:-}" ] \
    || [ -z "${DW_PERF_REMOTE_WRITE_PASSWORD:-}" ]; then
    echo "Prometheus remote_write is not configured; writing local perf artifacts only."
    return
  fi

  PROMETHEUS_CONFIG_DIR="$(mktemp -d)"
  cat > "$PROMETHEUS_CONFIG_DIR/prometheus.yml" <<YAML
global:
  scrape_interval: 15s
scrape_configs:
  - job_name: durable_workflow_server_perf
    static_configs:
      - targets:
          - host.docker.internal:${METRICS_PORT}
        labels:
          repository: "${GITHUB_REPOSITORY:-local}"
          workflow: "${GITHUB_WORKFLOW:-local}"
          run_id: "${GITHUB_RUN_ID:-local}"
          runner: "${RUNNER_NAME:-local}"
remote_write:
  - url: "${DW_PERF_REMOTE_WRITE_URL}"
    basic_auth:
      username: "${DW_PERF_REMOTE_WRITE_USERNAME}"
      password: "${DW_PERF_REMOTE_WRITE_PASSWORD}"
YAML

  docker run -d --rm \
    --name "$PROMETHEUS_CONTAINER" \
    --add-host=host.docker.internal:host-gateway \
    -v "$PROMETHEUS_CONFIG_DIR/prometheus.yml:/etc/prometheus/prometheus.yml:ro" \
    "${DW_PERF_PROMETHEUS_IMAGE:-prom/prometheus:v2.55.1}" \
    --config.file=/etc/prometheus/prometheus.yml \
    --storage.tsdb.retention.time=2h \
    --web.enable-lifecycle >/dev/null
}

server_base_url() {
  local base_url="http://127.0.0.1:${SERVER_PORT}"
  local docker_host_url
  local docker_host_ip
  local server_id
  local server_ip

  if curl -fsS --max-time 2 "$base_url/api/health" >/dev/null 2>&1; then
    echo "$base_url"
    return
  fi

  docker_host_ip="$(ip route 2>/dev/null | awk '/default/ {print $3; exit}')"
  if [ -n "$docker_host_ip" ]; then
    docker_host_url="http://${docker_host_ip}:${SERVER_PORT}"
    if curl -fsS --max-time 2 "$docker_host_url/api/health" >/dev/null 2>&1; then
      echo "$docker_host_url"
      return
    fi
  fi

  server_id="$(docker compose -p "$PROJECT" -f "$ROOT_DIR/docker-compose.yml" -f "$OVERRIDE_FILE" ps -q server)"
  server_ip="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$server_id" 2>/dev/null || true)"

  if [ -n "$server_ip" ]; then
    echo "http://${server_ip}:8080"
    return
  fi

  echo "$base_url"
}

cd "$ROOT_DIR"

echo "Starting perf stack with project ${PROJECT} on http://127.0.0.1:${SERVER_PORT}"
docker compose -p "$PROJECT" -f "$ROOT_DIR/docker-compose.yml" -f "$OVERRIDE_FILE" up -d --build --wait

maybe_start_prometheus
BASE_URL="$(server_base_url)"
echo "Running perf load against ${BASE_URL}"

DW_PERF_BASE_URL="$BASE_URL" \
DW_PERF_AUTH_TOKEN="$AUTH_TOKEN" \
DW_PERF_ARTIFACT_DIR="$ARTIFACT_DIR" \
DW_PERF_COMPOSE_PROJECT="$PROJECT" \
DW_PERF_METRICS_PORT="$METRICS_PORT" \
DW_PERF_POLL_TIMEOUT="$POLL_TIMEOUT" \
  "$ROOT_DIR/scripts/perf/server_soak.py"
