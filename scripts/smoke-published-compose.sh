#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${DW_PUBLISHED_COMPOSE_FILE:-$ROOT_DIR/docker-compose.published.yml}"
PROJECT_RAW="${DW_PUBLISHED_COMPOSE_PROJECT:-dw-server-published-smoke-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-smoke}-${DOCKER_DEFAULT_PLATFORM:-native}}"
PROJECT="$(printf '%s' "$PROJECT_RAW" | tr -c '[:alnum:]_-' '-')"
SERVER_PORT="${SERVER_PORT:-18080}"
TOKEN="${DW_AUTH_TOKEN:-dev-token}"
WORKER_ID="${DW_SMOKE_WORKER_ID:-published-compose-smoke-worker}"
TASK_QUEUE="${DW_SMOKE_TASK_QUEUE:-published-compose-smoke}"
ENDPOINT_MODE="${DW_PUBLISHED_COMPOSE_ENDPOINT_MODE:-host}"
SERVER_PLATFORM="${DW_SERVER_PLATFORM:-${DOCKER_DEFAULT_PLATFORM:-}}"
PROFILE="${DW_PUBLISHED_COMPOSE_PROFILE:-local}"

export DW_SERVER_PLATFORM="$SERVER_PLATFORM"
unset DOCKER_DEFAULT_PLATFORM

BASE_URL="http://server:8080"
published=""

export SERVER_PORT

case "$PROFILE" in
  local)
    AUTH_TOKEN="$TOKEN"
    WORKER_AUTH_TOKEN="$TOKEN"
    export APP_ENV="${APP_ENV:-local}"
    export APP_DEBUG="${APP_DEBUG:-false}"
    export DW_AUTH_DRIVER="${DW_AUTH_DRIVER:-token}"
    export DW_AUTH_TOKEN="$TOKEN"
    export DW_AUTH_BACKWARD_COMPATIBLE="${DW_AUTH_BACKWARD_COMPATIBLE:-true}"
    ;;
  production)
    AUTH_TOKEN="${DW_ADMIN_TOKEN:-published-compose-admin-token}"
    WORKER_AUTH_TOKEN="${DW_WORKER_TOKEN:-published-compose-worker-token}"
    export APP_ENV="${APP_ENV:-production}"
    export APP_DEBUG="${APP_DEBUG:-false}"
    export DB_PASSWORD="${DB_PASSWORD:-published-compose-db-password}"
    export DB_ROOT_PASSWORD="${DB_ROOT_PASSWORD:-published-compose-root-password}"
    export DW_AUTH_DRIVER="${DW_AUTH_DRIVER:-token}"
    export DW_AUTH_BACKWARD_COMPATIBLE="${DW_AUTH_BACKWARD_COMPATIBLE:-false}"
    # The native server requires a shared token and accepts it for every role,
    # so the production profile sets a strong one; it must not be dev-token.
    export DW_AUTH_TOKEN="${DW_AUTH_TOKEN:-published-compose-shared-token}"
    export DW_WORKER_TOKEN="$WORKER_AUTH_TOKEN"
    export DW_OPERATOR_TOKEN="${DW_OPERATOR_TOKEN:-published-compose-operator-token}"
    export DW_ADMIN_TOKEN="$AUTH_TOKEN"
    ;;
  *)
    echo "Unsupported DW_PUBLISHED_COMPOSE_PROFILE: $PROFILE" >&2
    echo "Expected one of: local, production" >&2
    exit 2
    ;;
esac

compose() {
  docker compose -p "$PROJECT" -f "$COMPOSE_FILE" "$@"
}

OBSERVATION_DIR="${DW_QUALIFICATION_DIR:-$ROOT_DIR/qualification-state}"
mkdir -p "$OBSERVATION_DIR"
HTTP_DIR=$(mktemp -d)
chmod 0700 "$HTTP_DIR"

curl_json_with_retry() {
  local output="$1"
  shift

  for attempt in $(seq 1 60); do
    if [ "$ENDPOINT_MODE" = "container" ]; then
      if compose exec -T server curl -fsS --connect-timeout 2 --max-time 5 --max-filesize 1048576 "$@" >"$output"; then
        return 0
      fi
    elif curl -fsS --connect-timeout 2 --max-time 5 --max-filesize 1048576 "$@" >"$output"; then
      return 0
    fi

    if [ "$attempt" -eq 60 ]; then
      echo "Published Compose request did not succeed within its bound" >&2
      return 1
    fi

    sleep 1
  done
}

cleanup() {
  local body_status=$? cleanup_status=0
  trap - EXIT
  python3 "$ROOT_DIR/scripts/ci/native_qualification.py" cleanup --kind compose --scope "$PROJECT" \
    --output "$OBSERVATION_DIR/body-cleanup.json" || cleanup_status=$?
  rm -rf "$HTTP_DIR"
  if [[ "$body_status" -ne 0 ]]; then exit "$body_status"; fi
  exit "$cleanup_status"
}

trap cleanup EXIT

echo "Published Compose smoke"
echo "  project: $PROJECT"
echo "  compose: $COMPOSE_FILE"
echo "  image: ${DW_SERVER_IMAGE:-default from the Compose file}"
echo "  platform: ${DW_SERVER_PLATFORM:-docker-default}"
echo "  profile: $PROFILE"
echo "  port: $SERVER_PORT"
echo "  endpoint: $ENDPOINT_MODE"

# The native published file refuses to render without an explicit shared
# token, and publishes the API on loopback only. The PHP 2.x file
# (docker-compose.php.yml) keeps its own defaults.
NATIVE_PUBLISHED=false
if [ "$(basename "$COMPOSE_FILE")" = "docker-compose.published.yml" ]; then
  NATIVE_PUBLISHED=true
fi
if [ "$NATIVE_PUBLISHED" = true ] && env -u DW_AUTH_TOKEN docker compose -p "$PROJECT" -f "$COMPOSE_FILE" config -q >/dev/null 2>&1; then
  echo "Compose rendered without DW_AUTH_TOKEN; it must refuse an unset token" >&2
  exit 1
fi

compose pull
compose up -d --wait
container=$(compose ps -q server)
python3 "$ROOT_DIR/scripts/ci/native_qualification.py" observe-image --kind compose \
  --image "${DW_SERVER_IMAGE:-}" --container "$container" --platform "$SERVER_PLATFORM" \
  --output "$OBSERVATION_DIR/image.json"

if [ "$ENDPOINT_MODE" = "host" ]; then
  published="$(compose port server 8080)"
  # The default host binding is loopback only.
  if [ "$NATIVE_PUBLISHED" = true ] && [ -z "${SERVER_BIND_ADDRESS:-}" ] && [ "${published%%:*}" != "127.0.0.1" ]; then
    echo "server port is published on ${published}, not loopback" >&2
    exit 1
  fi
  SERVER_PORT="$(printf '%s\n' "$published" | awk -F: 'END {print $NF}')"
  if [ -z "$SERVER_PORT" ]; then
    echo "Unable to discover the published server port for $PROJECT" >&2
    exit 1
  fi
  BASE_URL="http://127.0.0.1:${SERVER_PORT}"
fi

echo "  resolved endpoint: $BASE_URL"

curl_json_with_retry "$HTTP_DIR"/health.json "${BASE_URL}/api/health"
curl_json_with_retry "$HTTP_DIR"/ready.json "${BASE_URL}/api/ready"
curl_json_with_retry "$HTTP_DIR"/cluster.json \
  -H "Authorization: Bearer ${AUTH_TOKEN}" \
  "${BASE_URL}/api/cluster/info"

curl_json_with_retry "$HTTP_DIR"/worker.json \
  -X POST "${BASE_URL}/api/worker/register" \
  -H "Authorization: Bearer ${WORKER_AUTH_TOKEN}" \
  -H "Content-Type: application/json" \
  -H "X-Namespace: default" \
  -H "X-Durable-Workflow-Protocol-Version: 1.0" \
  -d "{\"worker_id\":\"${WORKER_ID}\",\"task_queue\":\"${TASK_QUEUE}\",\"runtime\":\"python\"}"

DW_SMOKE_HTTP_DIR="$HTTP_DIR" DW_SMOKE_WORKER_ID="$WORKER_ID" DW_SMOKE_NATIVE="$NATIVE_PUBLISHED" \
DW_SMOKE_ENDPOINT_MODE="$ENDPOINT_MODE" DW_SMOKE_BIND_ADDRESS="${published%%:*}" \
DW_SMOKE_OBSERVATION_DIR="$OBSERVATION_DIR" python3 - <<'OBSERVATIONS'
import json
import os
from pathlib import Path

root = Path(os.environ["DW_SMOKE_HTTP_DIR"])
try:
    health, ready, cluster, worker = [json.loads((root / name).read_bytes()) for name in
                                     ("health.json", "ready.json", "cluster.json", "worker.json")]
    identity = json.loads((Path(os.environ["DW_SMOKE_OBSERVATION_DIR"]) / "image.json").read_bytes())
    expected_version = identity.get("image_release_tag", "").removeprefix("native-")
    results = {
        "health_serving": health.get("status") == "serving",
        "ready": ready.get("status") == "ready",
        "cluster_version_matches_release": cluster.get("version") == expected_version if identity.get("eligible") else bool(cluster.get("version")),
        "worker_registered": worker.get("worker_id") == os.environ["DW_SMOKE_WORKER_ID"] and worker.get("registered") is True,
        "loopback_binding": os.environ["DW_SMOKE_ENDPOINT_MODE"] == "host" and os.environ["DW_SMOKE_BIND_ADDRESS"] == "127.0.0.1",
        "unset_token_refused": os.environ["DW_SMOKE_NATIVE"] == "true",
        "production_default_token_refused": None,
    }
    if not all(results[key] for key in ("health_serving", "ready", "cluster_version_matches_release", "worker_registered")):
        raise ValueError("smoke assertions")
    (Path(os.environ["DW_SMOKE_OBSERVATION_DIR"]) / "observations.json").write_text(json.dumps(results) + "\n")
except (ValueError, OSError, AttributeError):
    raise SystemExit("Published Compose response assertions failed") from None
OBSERVATIONS

if [ "$PROFILE" = "production" ]; then
  status="$(curl --connect-timeout 2 --max-time 5 -s -o /dev/null -w '%{http_code}' -H "Authorization: Bearer dev-token" "${BASE_URL}/api/cluster/info")"
  if [ "$status" != "401" ]; then
    echo "production profile accepted dev-token (HTTP $status)" >&2
    exit 1
  fi
  python3 - "$OBSERVATION_DIR/observations.json" <<'PRODUCTION'
import json
import sys
from pathlib import Path
path = Path(sys.argv[1])
value = json.loads(path.read_bytes())
value["production_default_token_refused"] = True
path.write_text(json.dumps(value) + "\n")
PRODUCTION
fi

echo "Published Compose smoke passed"
