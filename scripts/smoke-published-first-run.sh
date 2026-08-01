#!/usr/bin/env bash

set -euo pipefail

IMAGE="${DW_SERVER_IMAGE:?Set DW_SERVER_IMAGE to the exact published Server image to verify}"
SCOPE_RAW="${DW_FIRST_RUN_SCOPE:-dw-server-first-run-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-smoke}}"
SCOPE="$(printf '%s' "$SCOPE_RAW" | tr -c '[:alnum:]_-' '-')"
CONTAINER="${SCOPE}-server"
BOOTSTRAP_CONTAINER="${SCOPE}-bootstrap"
DATABASE_VOLUME="${SCOPE}-database"
AUTH_TOKEN="${DW_FIRST_RUN_AUTH_TOKEN:-published-first-run-token}"
ARTIFACT_ROOT="${RUNNER_TEMP:-${TMPDIR:-/tmp}}"
ARTIFACT_DIR="$(mktemp -d "${ARTIFACT_ROOT%/}/dw-server-first-run.XXXXXX")"
HEALTH_JSON="${ARTIFACT_DIR}/health.json"
READY_JSON="${ARTIFACT_DIR}/ready.json"
DISCOVERY_JSON="${ARTIFACT_DIR}/discovery.json"
RECOVERED_READY_JSON="${ARTIFACT_DIR}/recovered-ready.json"
SERVER_LOG="${ARTIFACT_DIR}/server.log"
RECOVERED_SERVER_LOG="${ARTIFACT_DIR}/recovered-server.log"
HEALTH_LOG="${ARTIFACT_DIR}/docker-health.log"

cleanup() {
    docker rm -f "$CONTAINER" "$BOOTSTRAP_CONTAINER" >/dev/null 2>&1 || true
    docker volume rm -f "$DATABASE_VOLUME" >/dev/null 2>&1 || true
    rm -rf "$ARTIFACT_DIR"
}

trap cleanup EXIT

container_health() {
    docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}missing{{end}}' "$CONTAINER"
}

container_port() {
    docker port "$CONTAINER" 8080/tcp | awk -F: 'END {print $NF}'
}

wait_for_liveness() {
    local base_url="$1"

    for attempt in $(seq 1 60); do
        if curl --fail --silent --show-error --max-time 4 \
            --output "$HEALTH_JSON" "${base_url}/api/health"; then
            return 0
        fi

        if [ "$attempt" -eq 60 ]; then
            echo "Published Server did not become live at ${base_url}/api/health." >&2
            docker logs "$CONTAINER" >&2 || true
            return 1
        fi

        sleep 1
    done
}

wait_for_container_health() {
    local expected="$1"

    for attempt in $(seq 1 60); do
        if [ "$(container_health)" = "$expected" ]; then
            return 0
        fi

        if [ "$attempt" -eq 60 ]; then
            echo "Published Server health did not become ${expected}; current status: $(container_health)." >&2
            docker inspect --format '{{json .State.Health}}' "$CONTAINER" >&2 || true
            docker logs "$CONTAINER" >&2 || true
            return 1
        fi

        sleep 1
    done
}

if [ "${DW_FIRST_RUN_SKIP_PULL:-0}" != "1" ]; then
    docker pull "$IMAGE"
fi

docker volume create "$DATABASE_VOLUME" >/dev/null

docker run --detach \
    --name "$CONTAINER" \
    --publish 127.0.0.1::8080 \
    --volume "${DATABASE_VOLUME}:/app/database" \
    "$IMAGE" >/dev/null

SERVER_PORT="$(container_port)"
if [ -z "$SERVER_PORT" ]; then
    echo "Unable to resolve the published Server port." >&2
    exit 1
fi
BASE_URL="http://127.0.0.1:${SERVER_PORT}"

wait_for_liveness "$BASE_URL"

READY_STATUS="$(curl --silent --show-error --max-time 5 \
    --output "$READY_JSON" --write-out '%{http_code}' "${BASE_URL}/api/ready")"
if [ "$READY_STATUS" != "503" ]; then
    echo "Expected an unbootstrapped published Server to return readiness 503, got ${READY_STATUS}." >&2
    cat "$READY_JSON" >&2
    exit 1
fi

curl --fail --silent --show-error --max-time 5 \
    --output "$DISCOVERY_JSON" "${BASE_URL}/"

docker logs "$CONTAINER" >"$SERVER_LOG" 2>&1
wait_for_container_health unhealthy
docker inspect --format '{{range .State.Health.Log}}{{.Output}}{{end}}' "$CONTAINER" >"$HEALTH_LOG"

HEALTH_JSON="$HEALTH_JSON" \
READY_JSON="$READY_JSON" \
DISCOVERY_JSON="$DISCOVERY_JSON" \
SERVER_LOG="$SERVER_LOG" \
HEALTH_LOG="$HEALTH_LOG" \
python3 - <<'PY'
import json
import os
from pathlib import Path

health = json.loads(Path(os.environ["HEALTH_JSON"]).read_text())
ready = json.loads(Path(os.environ["READY_JSON"]).read_text())
discovery = json.loads(Path(os.environ["DISCOVERY_JSON"]).read_text())
server_log = Path(os.environ["SERVER_LOG"]).read_text()
docker_health_log = Path(os.environ["HEALTH_LOG"]).read_text()

assert health.get("status") == "serving", health
assert ready.get("status") == "not_ready", ready
checks = ready.get("checks", {})
assert checks.get("migrations", {}).get("status") in {"missing", "pending"}, checks
assert checks.get("default_namespace", {}).get("status") == "missing", checks
assert checks.get("auth", {}).get("status") == "missing", checks
assert "server-bootstrap" in json.dumps(checks), checks

assert discovery.get("service") == "Durable Workflow Server", discovery
assert discovery.get("links") == {
    "health": "/api/health",
    "readiness": "/api/ready",
    "cluster_info": "/api/cluster/info",
    "setup": "https://durable-workflow.com/docs/2.0/quickstart/",
}, discovery

for expected in ("server-bootstrap", "authentication", "/api/ready"):
    assert expected in server_log, server_log
assert "server-bootstrap" in docker_health_log, docker_health_log
PY

docker rm -f "$CONTAINER" >/dev/null

docker run --rm \
    --name "$BOOTSTRAP_CONTAINER" \
    --volume "${DATABASE_VOLUME}:/app/database" \
    --env DW_AUTH_DRIVER=token \
    --env "DW_AUTH_TOKEN=${AUTH_TOKEN}" \
    "$IMAGE" server-bootstrap

docker run --detach \
    --name "$CONTAINER" \
    --publish 127.0.0.1::8080 \
    --volume "${DATABASE_VOLUME}:/app/database" \
    --env DW_AUTH_DRIVER=token \
    --env "DW_AUTH_TOKEN=${AUTH_TOKEN}" \
    "$IMAGE" >/dev/null

SERVER_PORT="$(container_port)"
if [ -z "$SERVER_PORT" ]; then
    echo "Unable to resolve the recovered published Server port." >&2
    exit 1
fi
BASE_URL="http://127.0.0.1:${SERVER_PORT}"

wait_for_liveness "$BASE_URL"
wait_for_container_health healthy
curl --fail --silent --show-error --max-time 5 \
    --output "$RECOVERED_READY_JSON" "${BASE_URL}/api/ready"
docker logs "$CONTAINER" >"$RECOVERED_SERVER_LOG" 2>&1

RECOVERED_READY_JSON="$RECOVERED_READY_JSON" \
RECOVERED_SERVER_LOG="$RECOVERED_SERVER_LOG" \
AUTH_TOKEN="$AUTH_TOKEN" \
python3 - <<'PY'
import json
import os
from pathlib import Path

ready = json.loads(Path(os.environ["RECOVERED_READY_JSON"]).read_text())
assert ready.get("status") == "ready", ready
assert ready.get("checks", {}).get("auth", {}).get("status") == "ok", ready
assert os.environ["AUTH_TOKEN"] not in Path(os.environ["RECOVERED_SERVER_LOG"]).read_text()
PY

echo "Published Server first-run readiness smoke passed"
