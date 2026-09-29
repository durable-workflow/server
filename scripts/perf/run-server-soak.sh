#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
ARTIFACT_DIR="${DW_PERF_ARTIFACT_DIR:-$ROOT_DIR/build/perf}"
RUN_ID="${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-perf}-$(date +%s)"
PROJECT="${DW_PERF_COMPOSE_PROJECT:-dw-server-perf-$RUN_ID}"
choose_free_port() {
  python3 - <<'PY'
import socket

with socket.socket() as sock:
    sock.bind(("", 0))
    print(sock.getsockname()[1])
PY
}

SERVER_PORT="${DW_PERF_SERVER_PORT:-}"
SERVER_BIND="${DW_PERF_SERVER_BIND:-}"
SERVER_PORT_MAPPING="8080"
if [ -n "$SERVER_PORT" ]; then
  if [ -n "$SERVER_BIND" ]; then
    SERVER_PORT_MAPPING="${SERVER_BIND}:${SERVER_PORT}:8080"
  else
    SERVER_PORT_MAPPING="${SERVER_PORT}:8080"
  fi
fi
MYSQL_PORT="${DW_PERF_MYSQL_PORT:-13306}"
REDIS_PORT="${DW_PERF_REDIS_PORT:-16379}"
METRICS_PORT="${DW_PERF_METRICS_PORT:-$(choose_free_port)}"
AUTH_TOKEN="${DW_PERF_AUTH_TOKEN:-perf-token}"
POLL_TIMEOUT="${DW_PERF_POLL_TIMEOUT:-1}"
POLL_INTERVAL_MS="${DW_PERF_POLL_INTERVAL_MS:-50}"
POLL_SIGNAL_CHECK_INTERVAL_MS="${DW_PERF_POLL_SIGNAL_CHECK_INTERVAL_MS:-25}"
READINESS_DIAGNOSTICS="${DW_PERF_READINESS_DIAGNOSTICS:-0}"
PUBLISHED_SERVER_IMAGE="${DW_PERF_PUBLISHED_SERVER_IMAGE:-}"
HTTP_VARIANT="${DW_PERF_HTTP_VARIANT:-apache}"
PREBUILT_HTTP_IMAGE_ID="${DW_PERF_PREBUILT_HTTP_IMAGE_ID:-}"
FIXED_ENVELOPE="${DW_PERF_FIXED_ENVELOPE:-0}"
IMAGE_DISTRIBUTION_METRICS="${DW_PERF_IMAGE_DISTRIBUTION_METRICS:-0}"
if [[ "$HTTP_VARIANT" != apache && "$HTTP_VARIANT" != nginx-fpm && "$HTTP_VARIANT" != apache-event-fpm \
  && "$HTTP_VARIANT" != frankenphp && "$HTTP_VARIANT" != swoole && "$HTTP_VARIANT" != openswoole ]]; then
  echo "DW_PERF_HTTP_VARIANT must be apache, nginx-fpm, apache-event-fpm, frankenphp, swoole, or openswoole." >&2
  exit 2
fi
if [[ "$FIXED_ENVELOPE" != 0 && "$FIXED_ENVELOPE" != 1 ]]; then
  echo "DW_PERF_FIXED_ENVELOPE must be 0 or 1." >&2
  exit 2
fi
if [[ "$IMAGE_DISTRIBUTION_METRICS" != 0 && "$IMAGE_DISTRIBUTION_METRICS" != 1 ]]; then
  echo "DW_PERF_IMAGE_DISTRIBUTION_METRICS must be 0 or 1." >&2
  exit 2
fi
if [[ "$IMAGE_DISTRIBUTION_METRICS" == 1 && ( "$HTTP_VARIANT" != apache || -z "$PUBLISHED_SERVER_IMAGE" ) ]]; then
  echo "Image distribution metrics require Apache and an exact published Server image." >&2
  exit 2
fi
if [[ "$HTTP_VARIANT" != apache && ( -z "$PUBLISHED_SERVER_IMAGE" || "$FIXED_ENVELOPE" != 1 ) ]]; then
  echo "An experimental HTTP variant requires an exact published Server image and fixed envelope." >&2
  exit 2
fi
if [[ "$FIXED_ENVELOPE" == 1 && -z "$PUBLISHED_SERVER_IMAGE" ]]; then
  echo "The fixed comparison envelope requires an exact published Server image." >&2
  exit 2
fi
if [[ -n "$PREBUILT_HTTP_IMAGE_ID" \
  && ( "$HTTP_VARIANT" != frankenphp || ! "$PREBUILT_HTTP_IMAGE_ID" =~ ^sha256:[0-9a-f]{64}$ ) ]]; then
  echo "A prebuilt HTTP image ID requires FrankenPHP and an exact sha256 image ID." >&2
  exit 2
fi
if [[ "$READINESS_DIAGNOSTICS" != 0 && "$READINESS_DIAGNOSTICS" != 1 ]]; then
  echo "DW_PERF_READINESS_DIAGNOSTICS must be 0 or 1." >&2
  exit 2
fi
if [[ -n "$PUBLISHED_SERVER_IMAGE" \
  && ! "$PUBLISHED_SERVER_IMAGE" =~ ^durableworkflow/server@sha256:[0-9a-f]{64}$ ]]; then
  echo "DW_PERF_PUBLISHED_SERVER_IMAGE must be an exact durableworkflow/server sha256 digest." >&2
  exit 2
fi
LOAD_TIMEOUT_SECONDS="${DW_PERF_LOAD_TIMEOUT_SECONDS:-}"
PROMETHEUS_CONTAINER="${PROJECT}-prometheus"
PROMETHEUS_CONFIG_DIR=""

mkdir -p "$ARTIFACT_DIR"
export DW_PERF_DRAIN_SECONDS="${DW_PERF_DRAIN_SECONDS:-310}"

if [ -z "$LOAD_TIMEOUT_SECONDS" ]; then
  DURATION_SECONDS="${DW_PERF_DURATION_SECONDS:-120}"
  DRAIN_SECONDS="$DW_PERF_DRAIN_SECONDS"
  LOAD_TIMEOUT_SECONDS=$((DURATION_SECONDS + DRAIN_SECONDS + 300))
  if [ "$LOAD_TIMEOUT_SECONDS" -lt 300 ]; then
    LOAD_TIMEOUT_SECONDS=300
  fi
fi

if [ -z "${DW_SERVER_KEY:-}" ]; then
  DW_SERVER_KEY="base64:$(openssl rand -base64 32)"
  export DW_SERVER_KEY
fi

export APP_VERSION="${APP_VERSION:-2.0.0-perf}"
export DW_AUTH_DRIVER="${DW_AUTH_DRIVER:-token}"
export DW_AUTH_TOKEN="${DW_AUTH_TOKEN:-$AUTH_TOKEN}"
export DW_WORKER_TOKEN="${DW_WORKER_TOKEN:-}"
export DW_OPERATOR_TOKEN="${DW_OPERATOR_TOKEN:-}"
export DW_ADMIN_TOKEN="${DW_ADMIN_TOKEN:-}"
export DW_AUTH_BACKWARD_COMPATIBLE="${DW_AUTH_BACKWARD_COMPATIBLE:-true}"
export DW_PERF_STANDARD_WORKFLOWS="${DW_PERF_STANDARD_WORKFLOWS:-true}"

OVERRIDE_FILE="$ARTIFACT_DIR/docker-compose.perf.yml"
PUBLISHED_OVERRIDE_FILE="$ARTIFACT_DIR/docker-compose.published.yml"
published_image_id=""
server_php_version=""
cat > "$OVERRIDE_FILE" <<YAML
services:
  bootstrap:
    environment:
      LOG_LEVEL: warning
      DW_WORKER_POLL_TIMEOUT: "$POLL_TIMEOUT"
      DW_WORKER_POLL_INTERVAL_MS: "$POLL_INTERVAL_MS"
      DW_WORKER_POLL_SIGNAL_CHECK_INTERVAL_MS: "$POLL_SIGNAL_CHECK_INTERVAL_MS"
  server:
    ports: !override
      - "${SERVER_PORT_MAPPING}"
    environment:
      LOG_LEVEL: warning
      DW_REDIS_READINESS_DIAGNOSTICS: "$READINESS_DIAGNOSTICS"
      DW_WORKER_POLL_TIMEOUT: "$POLL_TIMEOUT"
      DW_WORKER_POLL_INTERVAL_MS: "$POLL_INTERVAL_MS"
      DW_WORKER_POLL_SIGNAL_CHECK_INTERVAL_MS: "$POLL_SIGNAL_CHECK_INTERVAL_MS"
  worker:
    environment:
      LOG_LEVEL: warning
      DW_WORKER_POLL_TIMEOUT: "$POLL_TIMEOUT"
      DW_WORKER_POLL_INTERVAL_MS: "$POLL_INTERVAL_MS"
      DW_WORKER_POLL_SIGNAL_CHECK_INTERVAL_MS: "$POLL_SIGNAL_CHECK_INTERVAL_MS"
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

if [[ -n "$PUBLISHED_SERVER_IMAGE" ]]; then
  cat > "$PUBLISHED_OVERRIDE_FILE" <<'YAML'
services:
  bootstrap:
    build: !reset null
    image: ${DW_PERF_PUBLISHED_SERVER_IMAGE}
  server:
    build: !reset null
    image: ${DW_PERF_PUBLISHED_SERVER_IMAGE}
  worker:
    build: !reset null
    image: ${DW_PERF_PUBLISHED_SERVER_IMAGE}
  scheduler:
    build: !reset null
    image: ${DW_PERF_PUBLISHED_SERVER_IMAGE}
YAML
  if [[ "$IMAGE_DISTRIBUTION_METRICS" == 1 ]]; then
    docker_root="$(docker info --format '{{.DockerRootDir}}')"
    containerd_root="${DW_PERF_CONTAINERD_ROOT:-/var/lib/containerd}"
    image_count_before="$(docker image ls -q | wc -l | tr -d ' ')"
    if [[ "$image_count_before" != 0 ]]; then
      echo "Image distribution measurements require a host with no cached Docker images." >&2
      exit 1
    fi
    docker_bytes_before="$(sudo du -s -B1 "$docker_root" | awk '{print $1}')"
    containerd_bytes_before=0
    if [[ -d "$containerd_root" ]]; then
      containerd_bytes_before="$(sudo du -s -B1 "$containerd_root" | awk '{print $1}')"
    fi
    pull_started_ns="$(date +%s%N)"
  fi
  docker image pull "$PUBLISHED_SERVER_IMAGE"
  if [[ "$IMAGE_DISTRIBUTION_METRICS" == 1 ]]; then
    pull_completed_ns="$(date +%s%N)"
    docker_bytes_after="$(sudo du -s -B1 "$docker_root" | awk '{print $1}')"
    containerd_bytes_after=0
    if [[ -d "$containerd_root" ]]; then
      containerd_bytes_after="$(sudo du -s -B1 "$containerd_root" | awk '{print $1}')"
    fi
    docker_bytes_delta="$((docker_bytes_after - docker_bytes_before))"
    containerd_bytes_delta="$((containerd_bytes_after - containerd_bytes_before))"
    if (( docker_bytes_delta <= 0 && containerd_bytes_delta <= 0 )); then
      echo "Image pull caused no measured allocation in Docker or containerd roots; inspect the engine store before using disk results." >&2
      exit 1
    fi
    rootfs_allocated_bytes="$(docker run --rm --network none --read-only --user 0:0 --entrypoint du "$PUBLISHED_SERVER_IMAGE" -sx --block-size=1 / | awk 'NR == 1 {print $1}')"
    image_inspect_size_bytes="$(docker image inspect "$PUBLISHED_SERVER_IMAGE" --format '{{.Size}}')"
    if [[ ! "$rootfs_allocated_bytes" =~ ^[0-9]+$ || "$rootfs_allocated_bytes" == 0 || ! "$image_inspect_size_bytes" =~ ^[0-9]+$ ]]; then
      echo "Image rootfs or engine-reported size is missing." >&2
      exit 1
    fi
    jq -n \
      --arg image "$PUBLISHED_SERVER_IMAGE" \
      --arg platform "$(docker image inspect "$PUBLISHED_SERVER_IMAGE" --format '{{.Os}}/{{.Architecture}}')" \
      --arg storage_driver "$(docker info --format '{{.Driver}}')" \
      --arg docker_root "$docker_root" \
      --arg containerd_root "$containerd_root" \
      --argjson image_count_before "$image_count_before" \
      --argjson pull_elapsed_ms "$(((pull_completed_ns - pull_started_ns) / 1000000))" \
      --argjson docker_root_allocated_bytes_before "$docker_bytes_before" \
      --argjson docker_root_allocated_bytes_after "$docker_bytes_after" \
      --argjson docker_root_allocated_bytes_delta "$docker_bytes_delta" \
      --argjson containerd_root_allocated_bytes_before "$containerd_bytes_before" \
      --argjson containerd_root_allocated_bytes_after "$containerd_bytes_after" \
      --argjson containerd_root_allocated_bytes_delta "$containerd_bytes_delta" \
      --argjson docker_image_inspect_size_bytes "$image_inspect_size_bytes" \
      --argjson rootfs_visible_allocated_bytes "$rootfs_allocated_bytes" \
      '{
        image: $image,
        platform: $platform,
        storage_driver: $storage_driver,
        image_count_before: $image_count_before,
        pull_elapsed_ms: $pull_elapsed_ms,
        docker_root: $docker_root,
        docker_root_allocated_bytes_before: $docker_root_allocated_bytes_before,
        docker_root_allocated_bytes_after: $docker_root_allocated_bytes_after,
        docker_root_allocated_bytes_delta: $docker_root_allocated_bytes_delta,
        containerd_root: $containerd_root,
        containerd_root_allocated_bytes_before: $containerd_root_allocated_bytes_before,
        containerd_root_allocated_bytes_after: $containerd_root_allocated_bytes_after,
        containerd_root_allocated_bytes_delta: $containerd_root_allocated_bytes_delta,
        docker_image_inspect_size_bytes: $docker_image_inspect_size_bytes,
        rootfs_visible_allocated_bytes: $rootfs_visible_allocated_bytes,
        rootfs_measurement: "du -sx --block-size=1 / inside a read-only container",
        stack_ready_scope: "compose up --wait after backend image pulls"
      }' \
      > "$ARTIFACT_DIR/image-distribution.json"
  fi
  docker image inspect "$PUBLISHED_SERVER_IMAGE" | jq -e --arg image "$PUBLISHED_SERVER_IMAGE" \
    '.[0].RepoDigests | index($image) != null' >/dev/null
  server_source_sha="$(docker image inspect "$PUBLISHED_SERVER_IMAGE" \
    --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}')"
  if [[ ! "$server_source_sha" =~ ^[0-9a-f]{40}$ ]]; then
    echo "Published Server image lacks a full source revision label." >&2
    exit 1
  fi
  published_image_id="$(docker image inspect "$PUBLISHED_SERVER_IMAGE" --format '{{.Id}}')"
  server_php_version="$(docker run --rm --entrypoint php "$PUBLISHED_SERVER_IMAGE" -r 'echo PHP_VERSION;')"
  export DW_PERF_SERVER_SOURCE_SHA="$server_source_sha"
  export DW_PERF_SERVER_IMAGE="$PUBLISHED_SERVER_IMAGE"
else
  export DW_PERF_SERVER_SOURCE_SHA="${GITHUB_SHA:-}"
  export DW_PERF_SERVER_IMAGE=""
fi

jq -n --arg mode "$([[ -n "$PUBLISHED_SERVER_IMAGE" ]] && echo published || echo source_build)" \
  --arg image "$PUBLISHED_SERVER_IMAGE" --arg server_source_sha "$DW_PERF_SERVER_SOURCE_SHA" \
  --arg image_id "$published_image_id" --arg php_version "$server_php_version" \
  --arg runner_sha "${GITHUB_SHA:-}" \
  '{mode:$mode,image:$image,image_id:$image_id,php_version:$php_version,server_source_sha:$server_source_sha,runner_sha:$runner_sha}' \
  > "$ARTIFACT_DIR/server-image.json"

compose_files=("$ROOT_DIR/docker-compose.yml" "$OVERRIDE_FILE" "$ROOT_DIR/scripts/perf/standard-workflow.compose.yml")
if [[ -n "$PUBLISHED_SERVER_IMAGE" ]]; then
  compose_files+=("$PUBLISHED_OVERRIDE_FILE")
fi
if [[ "$FIXED_ENVELOPE" == 1 ]]; then
  compose_files+=("$ROOT_DIR/scripts/perf/soak-fixed-envelope.compose.yml")
fi
if [[ "$HTTP_VARIANT" == swoole || "$HTTP_VARIANT" == openswoole ]]; then
  export DW_PROFILE_SERVER_IMAGE="$PUBLISHED_SERVER_IMAGE"
  compose_files+=("$ROOT_DIR/scripts/perf/octane-${HTTP_VARIANT}-experiment.compose.yml")
  compose_files+=("$ROOT_DIR/scripts/perf/soak-octane-experiment.compose.yml")
elif [[ "$HTTP_VARIANT" == frankenphp ]]; then
  export DW_PROFILE_SERVER_IMAGE="$PUBLISHED_SERVER_IMAGE"
  compose_files+=("$ROOT_DIR/scripts/perf/octane-frankenphp-experiment.compose.yml")
  compose_files+=("$ROOT_DIR/scripts/perf/soak-frankenphp-experiment.compose.yml")
elif [[ "$HTTP_VARIANT" == nginx-fpm || "$HTTP_VARIANT" == apache-event-fpm ]]; then
  export DW_PROFILE_SERVER_IMAGE="$PUBLISHED_SERVER_IMAGE"
  compose_files+=("$ROOT_DIR/scripts/perf/fpm-experiment.compose.yml")
  if [[ "$HTTP_VARIANT" == apache-event-fpm ]]; then
    compose_files+=("$ROOT_DIR/scripts/perf/apache-event-fpm-experiment.compose.yml")
  fi
  compose_files+=("$ROOT_DIR/scripts/perf/fpm-fixed-envelope.compose.yml")
  compose_files+=("$ROOT_DIR/scripts/perf/soak-fpm-experiment.compose.yml")
  if [[ "$HTTP_VARIANT" == nginx-fpm ]]; then
    compose_files+=("$ROOT_DIR/scripts/perf/soak-nginx-fpm-experiment.compose.yml")
  else
    compose_files+=("$ROOT_DIR/scripts/perf/soak-apache-event-fpm-experiment.compose.yml")
  fi
fi
compose=(docker compose -p "$PROJECT")
for compose_file in "${compose_files[@]}"; do
  compose+=(-f "$compose_file")
done
export DW_PERF_COMPOSE_FILES="$(IFS=:; echo "${compose_files[*]}")"

if [[ "$HTTP_VARIANT" == nginx-fpm || "$HTTP_VARIANT" == apache-event-fpm ]]; then
  if ! "${compose[@]}" config --format json | jq -e '
    . as $config
    | all([
        "DW_WORKER_POLL_TIMEOUT",
        "DW_WORKER_POLL_INTERVAL_MS",
        "DW_WORKER_POLL_SIGNAL_CHECK_INTERVAL_MS"
      ][];
      . as $key
      | $config.services.server.environment[$key] != null
        and $config.services.server.environment[$key] == $config.services.fpm.environment[$key]
    )
  ' >/dev/null; then
    echo "FPM poll controls differ from the HTTP proxy workload settings." >&2
    exit 2
  fi
fi

cleanup() {
  local status=$?

  docker logs "${PROJECT}-server-1" > "$ARTIFACT_DIR/server.log" 2>&1 || true
  if [[ "$HTTP_VARIANT" == nginx-fpm || "$HTTP_VARIANT" == apache-event-fpm ]]; then
    docker logs "${PROJECT}-fpm-1" > "$ARTIFACT_DIR/fpm.log" 2>&1 || true
  fi
  docker logs "${PROJECT}-worker-1" > "$ARTIFACT_DIR/worker.log" 2>&1 || true
  docker logs "${PROJECT}-scheduler-1" > "$ARTIFACT_DIR/scheduler.log" 2>&1 || true
  docker logs "${PROJECT}-mysql-1" > "$ARTIFACT_DIR/mysql.log" 2>&1 || true
  docker logs "${PROJECT}-redis-1" > "$ARTIFACT_DIR/redis.log" 2>&1 || true
  docker logs "${PROJECT}-soak-sdk-1" > "$ARTIFACT_DIR/soak-sdk.log" 2>&1 || true

  docker rm -f "$PROMETHEUS_CONTAINER" >/dev/null 2>&1 || true
  if [ -n "$PROMETHEUS_CONFIG_DIR" ]; then
    rm -rf "$PROMETHEUS_CONFIG_DIR"
  fi

  "${compose[@]}" --profile standard-soak down -v --remove-orphans || true
  exit "$status"
}
trap cleanup EXIT

write_environment_setup_failure() {
  local status="$1"
  local phase="$2"
  local message="$3"

  cat > "$ARTIFACT_DIR/environment-setup-failure.json" <<JSON
{
  "phase": "environment_setup",
  "setup_phase": "${phase}",
  "compose_project": "${PROJECT}",
  "status": ${status},
  "message": "${message}"
}
JSON

  echo "Wrote perf environment setup failure artifact to ${ARTIFACT_DIR}/environment-setup-failure.json" >&2
}

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
  local docker_internal_url="http://host.docker.internal:${SERVER_PORT}"
  local docker_host_url
  local docker_host_ip
  local server_id
  local server_ip

  if curl -fsS --max-time 2 "$base_url/api/health" >/dev/null 2>&1; then
    echo "$base_url"
    return
  fi

  if curl -fsS --max-time 2 "$docker_internal_url/api/health" >/dev/null 2>&1; then
    echo "$docker_internal_url"
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

  server_id="$("${compose[@]}" ps -q server)"
  server_ip="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' "$server_id" 2>/dev/null || true)"

  if [ -n "$server_ip" ]; then
    local server_container_url="http://${server_ip}:8080"
    if curl -fsS --max-time 2 "$server_container_url/api/health" >/dev/null 2>&1; then
      echo "$server_container_url"
      return
    fi
  fi

  echo "$base_url"
}

cd "$ROOT_DIR"

if [ -n "${DW_PERF_SERVER_PORT:-}" ]; then
  echo "Starting perf stack with project ${PROJECT} on http://127.0.0.1:${SERVER_PORT}"
else
  echo "Starting perf stack with project ${PROJECT} on a dynamic host port"
fi
setup_status=0
if [[ "$IMAGE_DISTRIBUTION_METRICS" == 1 ]]; then
  "${compose[@]}" pull mysql redis
  stack_started_ns="$(date +%s%N)"
fi
if [[ -n "$PREBUILT_HTTP_IMAGE_ID" ]]; then
  loaded_http_image_id="$(docker image inspect "${PROJECT}-octane-frankenphp:local" --format '{{.Id}}')" || loaded_http_image_id=""
  if [[ "$loaded_http_image_id" != "$PREBUILT_HTTP_IMAGE_ID" ]]; then
    write_environment_setup_failure 1 "prebuilt_http_image_identity" "transferred FrankenPHP image ID differs from the prebuilt image"
    exit 1
  fi
  "${compose[@]}" up -d --no-build --wait || setup_status=$?
else
  "${compose[@]}" up -d --build --wait || setup_status=$?
fi
if [[ "$IMAGE_DISTRIBUTION_METRICS" == 1 ]]; then
  stack_completed_ns="$(date +%s%N)"
  jq --argjson stack_setup_elapsed_ms "$(((stack_completed_ns - stack_started_ns) / 1000000))" \
    --argjson stack_ready "$([[ "$setup_status" == 0 ]] && echo true || echo false)" \
    '. + {stack_setup_elapsed_ms:$stack_setup_elapsed_ms,stack_ready:$stack_ready}' \
    "$ARTIFACT_DIR/image-distribution.json" > "$ARTIFACT_DIR/image-distribution.updated.json"
  mv "$ARTIFACT_DIR/image-distribution.updated.json" "$ARTIFACT_DIR/image-distribution.json"
fi
if [ "$setup_status" -eq 0 ] && [ "$DW_PERF_STANDARD_WORKFLOWS" = true ]; then
  "${compose[@]}" build soak-sdk || setup_status=$?
fi
if [[ "$IMAGE_DISTRIBUTION_METRICS" == 1 && "$setup_status" -eq 0 ]]; then
  mysql_image_id="$(docker inspect "${PROJECT}-mysql-1" --format '{{.Image}}')"
  redis_image_id="$(docker inspect "${PROJECT}-redis-1" --format '{{.Image}}')"
  mysql_repo_digest="$(docker image inspect "$mysql_image_id" | jq -r '.[0].RepoDigests[0] // ""')"
  redis_repo_digest="$(docker image inspect "$redis_image_id" | jq -r '.[0].RepoDigests[0] // ""')"
  if [[ "$mysql_repo_digest" != *@sha256:* || "$redis_repo_digest" != *@sha256:* ]]; then
    echo "Image distribution measurements require resolved MySQL and Redis image digests." >&2
    exit 1
  fi
  jq \
    --arg mysql_image_id "$mysql_image_id" \
    --arg redis_image_id "$redis_image_id" \
    --arg mysql_repo_digest "$mysql_repo_digest" \
    --arg redis_repo_digest "$redis_repo_digest" \
    '. + {mysql_image_id:$mysql_image_id,redis_image_id:$redis_image_id,mysql_repo_digest:$mysql_repo_digest,redis_repo_digest:$redis_repo_digest}' \
    "$ARTIFACT_DIR/image-distribution.json" > "$ARTIFACT_DIR/image-distribution.updated.json"
  mv "$ARTIFACT_DIR/image-distribution.updated.json" "$ARTIFACT_DIR/image-distribution.json"
fi
if [ "$setup_status" -ne 0 ]; then
  echo "Perf environment setup failed before product smoke execution; docker compose could not build or start the stack." >&2
  write_environment_setup_failure "$setup_status" "docker_compose_up" "docker compose failed before server_soak.py started"
  "${compose[@]}" ps >&2 || true
  exit "$setup_status"
fi

if [[ -n "$PUBLISHED_SERVER_IMAGE" ]]; then
  for service in bootstrap server worker scheduler; do
    actual_image_id="$(docker inspect "${PROJECT}-${service}-1" --format '{{.Image}}')"
    if [[ "$service" == server && "$HTTP_VARIANT" != apache ]]; then
      if [[ "$HTTP_VARIANT" == nginx-fpm ]]; then
        expected_server_image="$(docker image inspect 'nginx@sha256:a8b39bd9cf0f83869a2162827a0caf6137ddf759d50a171451b335cecc87d236' --format '{{.Id}}')"
      elif [[ "$HTTP_VARIANT" == apache-event-fpm ]]; then
        expected_server_image="$(docker image inspect "${PROJECT}-apache-event-fpm:local" --format '{{.Id}}')"
      else
        expected_server_image="$(docker image inspect "${PROJECT}-octane-${HTTP_VARIANT}:local" --format '{{.Id}}')"
      fi
      if [[ "$actual_image_id" != "$expected_server_image" ]]; then
        echo "Experimental HTTP server does not match the selected derived/proxy image." >&2
        write_environment_setup_failure 1 "experimental_image_identity" "experimental HTTP image differs from selected derived/proxy image"
        exit 1
      fi
      if [[ "$HTTP_VARIANT" == swoole || "$HTTP_VARIANT" == openswoole ]]; then
        expected_revision="$(docker inspect "${PROJECT}-${service}-1" --format '{{ index .Config.Labels "org.opencontainers.image.revision" }}')"
        if [[ "$expected_revision" != "$server_source_sha" ]]; then
          write_environment_setup_failure 1 "experimental_image_revision" "experimental HTTP image lacks selected published source revision"
          exit 1
        fi
      fi
      continue
    fi
    if [[ "$actual_image_id" != "$published_image_id" ]]; then
      echo "Perf ${service} did not start from the selected published Server image." >&2
      write_environment_setup_failure 1 "published_image_identity" "runtime role image differs from selected published Server digest"
      exit 1
    fi
  done
fi
if [[ "$HTTP_VARIANT" == nginx-fpm || "$HTTP_VARIANT" == apache-event-fpm ]]; then
  expected_fpm_image="$(docker image inspect "${PROJECT}-fpm:local" --format '{{.Id}}')"
  actual_fpm_image="$(docker inspect "${PROJECT}-fpm-1" --format '{{.Image}}')"
  if [[ "$actual_fpm_image" != "$expected_fpm_image" ]]; then
    write_environment_setup_failure 1 "experimental_fpm_image_identity" "FPM image differs from selected derived image"
    exit 1
  fi
  jq -n --arg base_image "$PUBLISHED_SERVER_IMAGE" --arg image_id "$actual_fpm_image" \
    --arg php_version "$(docker exec "${PROJECT}-fpm-1" php -r 'echo PHP_VERSION;')" \
    '{base_image:$base_image,image_id:$image_id,php_version:$php_version}' > "$ARTIFACT_DIR/fpm-image.json"
elif [[ "$HTTP_VARIANT" == frankenphp ]]; then
  jq -n --arg base_image "$PUBLISHED_SERVER_IMAGE" \
    --arg image_id "$(docker inspect "${PROJECT}-server-1" --format '{{.Image}}')" \
    --arg php_version "$(docker exec "${PROJECT}-server-1" php -r 'echo PHP_VERSION;')" \
    '{base_image:$base_image,image_id:$image_id,php_version:$php_version}' > "$ARTIFACT_DIR/http-derived-image.json"
fi
http_image_id="$(docker inspect "${PROJECT}-server-1" --format '{{.Image}}')"
jq --arg variant "$HTTP_VARIANT" --arg fixed_envelope "$FIXED_ENVELOPE" \
  --arg http_image_id "$http_image_id" \
  '. + {http_variant: $variant, fixed_envelope: ($fixed_envelope == "1"), http_image_id: $http_image_id}' \
  "$ARTIFACT_DIR/server-image.json" > "$ARTIFACT_DIR/server-image.updated.json"
mv "$ARTIFACT_DIR/server-image.updated.json" "$ARTIFACT_DIR/server-image.json"

PUBLISHED_SERVER_PORT="$("${compose[@]}" port server 8080 | awk -F: 'END {print $NF}')"
if [ -z "$PUBLISHED_SERVER_PORT" ]; then
  echo "Unable to discover published server port for ${PROJECT}" >&2
  write_environment_setup_failure 1 "published_port_discovery" "server port discovery failed before server_soak.py started"
  exit 1
fi
SERVER_PORT="$PUBLISHED_SERVER_PORT"

maybe_start_prometheus
BASE_URL="$(server_base_url)"
echo "Running perf load against ${BASE_URL}"

status=0
if command -v timeout >/dev/null 2>&1; then
  DW_PERF_BASE_URL="$BASE_URL" \
  DW_PERF_AUTH_TOKEN="$AUTH_TOKEN" \
  DW_PERF_ARTIFACT_DIR="$ARTIFACT_DIR" \
  DW_PERF_COMPOSE_PROJECT="$PROJECT" \
  DW_PERF_METRICS_PORT="$METRICS_PORT" \
  DW_PERF_POLL_TIMEOUT="$POLL_TIMEOUT" \
    timeout --kill-after=30s "${LOAD_TIMEOUT_SECONDS}s" "$ROOT_DIR/scripts/perf/server_soak.py" || status=$?
else
  echo "timeout command is unavailable; running perf load without shell deadline." >&2
  DW_PERF_BASE_URL="$BASE_URL" \
  DW_PERF_AUTH_TOKEN="$AUTH_TOKEN" \
  DW_PERF_ARTIFACT_DIR="$ARTIFACT_DIR" \
  DW_PERF_COMPOSE_PROJECT="$PROJECT" \
  DW_PERF_METRICS_PORT="$METRICS_PORT" \
  DW_PERF_POLL_TIMEOUT="$POLL_TIMEOUT" \
    "$ROOT_DIR/scripts/perf/server_soak.py" || status=$?
fi

if [ "$status" -eq 124 ] || [ "$status" -eq 137 ]; then
  echo "Perf load timed out after ${LOAD_TIMEOUT_SECONDS}s; writing timeout diagnostics." >&2
  cat > "$ARTIFACT_DIR/load-timeout.json" <<JSON
{
  "base_url": "${BASE_URL}",
  "compose_project": "${PROJECT}",
  "load_timeout_seconds": ${LOAD_TIMEOUT_SECONDS},
  "status": ${status}
}
JSON
  "${compose[@]}" ps >&2 || true
  docker logs --tail=120 "${PROJECT}-server-1" >&2 || true
  docker logs --tail=120 "${PROJECT}-worker-1" >&2 || true
fi

exit "$status"
