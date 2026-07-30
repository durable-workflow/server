#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
COMPOSE_FILE="${DW_REPLAY_QUERY_COMPOSE_FILE:-$ROOT_DIR/docker-compose.published.yml}"
PROJECT_RAW="${DW_REPLAY_QUERY_COMPOSE_PROJECT:-dw-replay-query-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-replay-query}}"
PROJECT="$(printf '%s' "$PROJECT_RAW" | tr -c '[:alnum:]_-' '-')"
SERVER_PORT="${SERVER_PORT:-18082}"
TOKEN="${DW_AUTH_TOKEN:-dev-token}"
BASE_URL=""
NONROOT_CONTAINER="${PROJECT}-nonroot-http"

source "$ROOT_DIR/scripts/regression/apache-module-preflight.sh"

export SERVER_PORT
export APP_ENV="${APP_ENV:-local}"
export APP_DEBUG="${APP_DEBUG:-false}"
export LOG_LEVEL="${LOG_LEVEL:-info}"
export DW_AUTH_DRIVER="${DW_AUTH_DRIVER:-token}"
export DW_AUTH_TOKEN="$TOKEN"
export DW_AUTH_BACKWARD_COMPATIBLE="${DW_AUTH_BACKWARD_COMPATIBLE:-true}"

compose() {
  docker compose -p "$PROJECT" -f "$COMPOSE_FILE" "$@"
}

server_url_candidates=()

add_server_url_candidate() {
  local candidate="${1%/}"
  local existing

  if [ -z "$candidate" ]; then
    return 0
  fi
  for existing in "${server_url_candidates[@]}"; do
    if [ "$candidate" = "$existing" ]; then
      return 0
    fi
  done
  server_url_candidates+=("$candidate")
}

default_route_gateway() {
  python3 <<'PY'
from __future__ import annotations

import socket

try:
    with open("/proc/net/route", encoding="utf-8") as routes:
        next(routes, None)
        for line in routes:
            fields = line.split()
            if len(fields) >= 3 and fields[1] == "00000000" and fields[2] != "00000000":
                print(socket.inet_ntoa(bytes.fromhex(fields[2])[::-1]))
                break
except OSError:
    pass
PY
}

build_server_url_candidates() {
  local gateway

  add_server_url_candidate "${DW_REPLAY_QUERY_SERVER_URL:-}"
  add_server_url_candidate "http://127.0.0.1:${SERVER_PORT}"
  add_server_url_candidate "http://localhost:${SERVER_PORT}"

  # A containerized Actions job controls a sibling Docker daemon through its
  # socket. In that topology loopback belongs to the job container, while its
  # default gateway is the Docker host that owns the published server port.
  gateway="$(default_route_gateway)"
  if [ -n "$gateway" ]; then
    add_server_url_candidate "http://${gateway}:${SERVER_PORT}"
  fi

  gateway="$(docker network inspect bridge --format '{{(index .IPAM.Config 0).Gateway}}' 2>/dev/null || true)"
  if [ -n "$gateway" ] && [ "$gateway" != "<no value>" ]; then
    add_server_url_candidate "http://${gateway}:${SERVER_PORT}"
  fi

  add_server_url_candidate "http://host.docker.internal:${SERVER_PORT}"
}

wait_for_server_ready() {
  local attempt
  local candidate

  for attempt in $(seq 1 90); do
    for candidate in "${server_url_candidates[@]}"; do
      if curl --noproxy '*' -fsS --max-time 2 "${candidate}/api/ready" >/dev/null 2>&1; then
        printf '%s\n' "$candidate"
        return 0
      fi
    done
    sleep 1
  done

  printf 'Server was not reachable through any published-port candidate: %s\n' \
    "${server_url_candidates[*]}" >&2
  return 1
}

cleanup() {
  if [ -n "$NONROOT_CONTAINER" ]; then
    docker rm -f "$NONROOT_CONTAINER" >/dev/null 2>&1 || true
  fi
  compose down -v --remove-orphans >/dev/null 2>&1 || true
}

failure_logs() {
  if [ -n "$NONROOT_CONTAINER" ]; then
    docker logs --tail 160 "$NONROOT_CONTAINER" 2>&1 \
      | tail -c 32768 \
      | redact_diagnostics >&2 || true
  fi
  compose ps --all >&2 || true
  compose logs --no-color --tail 160 server 2>&1 \
    | tail -c 32768 \
    | redact_diagnostics >&2 || true
  compose exec -T server sh -c \
    'test ! -f storage/logs/laravel.log || tail -c 16384 storage/logs/laravel.log' \
    2>&1 | redact_diagnostics >&2 || true
}

redact_diagnostics() {
  sed -E \
    -e 's/(Bearer[[:space:]]+)[A-Za-z0-9._~+\/=:-]+/\1[REDACTED]/Ig' \
    -e 's/("(password|token|secret|app_key)"[[:space:]]*:[[:space:]]*")[^"]*/\1[REDACTED]/Ig' \
    -e "s/((password|token|secret|app_key|authorization)[\"']?[[:space:]]*(=>|=|:)[[:space:]]*[\"']?)[^\"'[:space:],}]+/\\1[REDACTED]/Ig" \
    -e 's/((DB_PASSWORD|DW_AUTH_TOKEN|APP_KEY)=)[^[:space:]]+/\1[REDACTED]/Ig'
}

trap cleanup EXIT
trap failure_logs ERR

compose up -d --wait

SERVER_PORT="$(compose port server 8080 | awk -F: 'END {print $NF}')"
if [ -z "$SERVER_PORT" ]; then
  printf 'Unable to discover the published server port for %s.\n' "$PROJECT" >&2
  exit 1
fi
export SERVER_PORT

build_server_url_candidates
BASE_URL="$(wait_for_server_ready)"
printf 'Running concurrent replay/query regression against %s\n' "$BASE_URL"

http_runtime="$(compose exec -T server sh -c "tr '\\000' ' ' </proc/1/cmdline")"
case "$http_runtime" in
  *apache2*'-DFOREGROUND'*) ;;
  *)
    printf 'Standalone HTTP runtime is not foreground Apache: %s\n' "$http_runtime" >&2
    exit 1
    ;;
esac
verify_apache_mod_php compose exec -T server apache2ctl -M
printf 'Standalone HTTP runtime is foreground Apache with mod_php.\n'
if ! compose exec -T --user 1000:1000 server sh -c \
  'test -w storage/logs && test -w bootstrap/cache'; then
  printf 'Root-run Docker did not give Apache request workers writable Laravel state.\n' >&2
  exit 1
fi

server_image="$(compose images -q server)"
if [ -z "$server_image" ]; then
  printf 'Unable to resolve the built standalone server image.\n' >&2
  exit 1
fi
docker run --detach \
  --name "$NONROOT_CONTAINER" \
  --user 1000:1000 \
  --cap-drop ALL \
  --env APP_ENV=local \
  --env APP_DEBUG=false \
  --env DW_AUTH_DRIVER=token \
  --env DW_AUTH_TOKEN="$TOKEN" \
  "$server_image" \
  sh -c 'server-bootstrap && exec apache2-foreground' >/dev/null

nonroot_ready=0
for _ in $(seq 1 60); do
  if docker exec "$NONROOT_CONTAINER" curl -fsS http://127.0.0.1:8080/api/ready >/dev/null 2>&1; then
    nonroot_ready=1
    break
  fi
  if [ "$(docker inspect --format '{{.State.Running}}' "$NONROOT_CONTAINER" 2>/dev/null || true)" != "true" ]; then
    break
  fi
  sleep 1
done
if [ "$nonroot_ready" != "1" ]; then
  printf 'Standalone Apache did not become ready as UID/GID 1000 with capabilities dropped.\n' >&2
  exit 1
fi
if ! docker exec "$NONROOT_CONTAINER" curl -fsS \
  -X POST http://127.0.0.1:8080/api/namespaces \
  -H "Authorization: Bearer $TOKEN" \
  -H 'Content-Type: application/json' \
  -H 'X-Durable-Workflow-Control-Plane-Version: 2' \
  -d '{"name":"nonroot-runtime-smoke","description":"Non-root image write probe","retention_days":1}' \
  >/dev/null; then
  printf 'Non-root standalone Apache could not write through the default SQLite database.\n' >&2
  exit 1
fi
if ! docker exec "$NONROOT_CONTAINER" sh -c \
  'test "$(id -u)" = 1000 && test "$(id -g)" = 1000 && test -w database/database.sqlite && test -w storage/logs && test -w bootstrap/cache'; then
  printf 'Non-root standalone Apache identity or Laravel write access is invalid.\n' >&2
  exit 1
fi
printf 'Standalone Apache serves readiness and writes SQLite as UID/GID 1000 with capabilities dropped.\n'

opcache_enabled="$(compose exec -T server php -r 'echo extension_loaded("Zend OPcache") && ini_get("opcache.enable_cli") ? "1" : "0";')"
if [ "$opcache_enabled" != "1" ]; then
  printf 'Standalone image PHP CLI processes do not have OPcache enabled.\n' >&2
  exit 1
fi
printf 'Standalone image PHP OPcache is enabled.\n'

python3 - "$BASE_URL" "$TOKEN" <<'PY'
from __future__ import annotations

import json
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
import uuid
from typing import Any


BASE_URL, TOKEN = sys.argv[1:3]
NAMESPACE = "default"
WORKFLOW_TYPE = "ReplayQueryCounter"
suffix = uuid.uuid4().hex[:10]
WORKER_ID = f"replay-query-worker-{suffix}"
TASK_QUEUE = f"replay-query-queue-{suffix}"
WORKFLOW_ID = f"replay-query-workflow-{suffix}"
stage_lock = threading.Lock()


def stage(name: str, **details: Any) -> None:
    with stage_lock:
        print(json.dumps({
            "stage": name,
            "observed_at": time.time(),
            **details,
        }, sort_keys=True), flush=True)


def request_json(
    path: str,
    *,
    body: Any = None,
    worker: bool = False,
    namespace: str = NAMESPACE,
    timeout: float = 15.0,
) -> dict[str, Any]:
    headers = {
        "Accept": "application/json",
        "Authorization": f"Bearer {TOKEN}",
        "Content-Type": "application/json",
        "X-Namespace": namespace,
        "X-Durable-Workflow-Protocol-Version" if worker else "X-Durable-Workflow-Control-Plane-Version":
            "1.13" if worker else "2",
    }
    request = urllib.request.Request(
        BASE_URL.rstrip("/") + path,
        data=json.dumps(body).encode() if body is not None else None,
        headers=headers,
        method="POST" if body is not None else "GET",
    )
    try:
        with urllib.request.urlopen(request, timeout=timeout) as response:
            raw = response.read().decode()
            return {
                "status": response.status,
                "body": json.loads(raw) if raw.strip() else {},
            }
    except urllib.error.HTTPError as error:
        raw = error.read().decode(errors="replace")
        try:
            response_body = json.loads(raw) if raw.strip() else {}
        except json.JSONDecodeError:
            response_body = {"raw": raw}
        return {"status": error.code, "body": response_body}


def require_success(response: dict[str, Any], label: str) -> dict[str, Any]:
    if not 200 <= int(response.get("status") or 0) < 300:
        raise AssertionError(f"{label} failed: {response}")
    body = response.get("body")
    return body if isinstance(body, dict) else {}


def require_created(response: dict[str, Any], label: str) -> dict[str, Any]:
    if int(response.get("status") or 0) != 201:
        raise AssertionError(f"{label} did not return structured HTTP 201: {response}")
    body = response.get("body")
    if not isinstance(body, dict) or not body:
        raise AssertionError(f"{label} did not return a JSON object: {response}")
    return body


namespace_responses = []
for namespace_name in ["tenant-a", "tenant-b", "tenant-c", "shared"]:
    namespace_response = request_json(
        "/api/namespaces",
        body={
            "name": namespace_name,
            "description": f"Apache registration regression namespace {namespace_name}",
        },
        namespace="default",
        timeout=20,
    )
    require_created(namespace_response, f"namespace {namespace_name} registration")
    namespace_responses.append(namespace_response["status"])

endpoint_response = request_json(
    "/api/service-endpoints",
    body={
        "endpoint_name": "shared-greeter",
        "description": "Apache registration regression endpoint",
        "metadata": {"regression": "apache-service-registration"},
    },
    namespace="shared",
    timeout=20,
)
endpoint_body = require_created(endpoint_response, "service endpoint registration")
if endpoint_body.get("endpoint_name") != "shared-greeter":
    raise AssertionError(f"service endpoint response has the wrong identity: {endpoint_response}")

service_response = request_json(
    "/api/service-endpoints/shared-greeter/services",
    body={
        "service_name": "Greeter",
        "description": "Apache registration regression service",
        "metadata": {"regression": "apache-service-registration"},
    },
    namespace="shared",
    timeout=20,
)
service_body = require_created(service_response, "service registration")
if service_body.get("service_name") != "greeter":
    raise AssertionError(f"service response has the wrong identity: {service_response}")

operation_response = request_json(
    "/api/service-endpoints/shared-greeter/services/Greeter/operations",
    body={
        "operation_name": "greet",
        "description": "Apache registration regression operation",
        "operation_mode": "async",
        "handler_binding_kind": "activity_execution",
        "handler_target_reference": "Greeter.greet",
        "handler_binding": {"activity_type": "Greeter.greet"},
        "retry_policy": {"maximum_attempts": 3, "initial_interval_seconds": 1},
        "metadata": {"regression": "apache-service-registration"},
    },
    namespace="shared",
    timeout=20,
)
operation_body = require_created(operation_response, "operation registration")
if operation_body.get("operation_name") != "greet":
    raise AssertionError(f"operation response has the wrong identity: {operation_response}")

stage(
    "apache_service_registration_completed",
    namespace_statuses=namespace_responses,
    endpoint_status=endpoint_response["status"],
    service_status=service_response["status"],
    operation_status=operation_response["status"],
)


def heartbeat() -> tuple[dict[str, Any], float]:
    started = time.monotonic()
    response = request_json(
        "/api/worker/heartbeat",
        body={
            "worker_id": WORKER_ID,
            "task_slots": {"workflow_available": 2, "activity_available": 0},
            "heartbeat_interval_seconds": 10,
        },
        worker=True,
        timeout=10,
    )
    return response, time.monotonic() - started


def poll_workflow_task() -> dict[str, Any]:
    response = request_json(
        "/api/worker/workflow-tasks/poll",
        body={
            "worker_id": WORKER_ID,
            "task_queue": TASK_QUEUE,
            "poll_request_id": f"workflow-{uuid.uuid4().hex}",
        },
        worker=True,
        timeout=15,
    )
    body = require_success(response, "workflow task poll")
    task = body.get("task")
    if not isinstance(task, dict):
        raise AssertionError(f"workflow task poll returned no task: {response}")
    return task


def complete_workflow_task(task: dict[str, Any], commands: list[dict[str, Any]]) -> None:
    response = request_json(
        f"/api/worker/workflow-tasks/{task['task_id']}/complete",
        body={
            "lease_owner": task["lease_owner"],
            "workflow_task_attempt": task["workflow_task_attempt"],
            "commands": commands,
        },
        worker=True,
        timeout=15,
    )
    require_success(response, "workflow task completion")


contract = {
    "queries": ["state"],
    "query_contracts": [{"name": "state", "parameters": []}],
    "signals": ["increment"],
    "signal_contracts": [{
        "name": "increment",
        "parameters": [{
            "name": "amount",
            "position": 0,
            "required": True,
            "variadic": False,
            "default_available": False,
            "default": None,
            "type": "int",
            "allows_null": False,
        }],
    }],
    "updates": [],
    "update_contracts": [],
}

register = request_json(
    "/api/worker/register",
    body={
        "worker_id": WORKER_ID,
        "task_queue": TASK_QUEUE,
        "runtime": "external",
        "sdk_version": "replay-query-concurrent-http-regression",
        "supported_workflow_types": [WORKFLOW_TYPE],
        "capabilities": ["query_tasks"],
        "workflow_command_contracts": {WORKFLOW_TYPE: contract},
    },
    worker=True,
    timeout=20,
)
require_success(register, "worker registration")

start = request_json(
    "/api/workflows",
    body={
        "workflow_id": WORKFLOW_ID,
        "workflow_type": WORKFLOW_TYPE,
        "task_queue": TASK_QUEUE,
    },
    timeout=20,
)
start_body = require_success(start, "workflow start")
run_id = start_body.get("run_id")
if not isinstance(run_id, str) or not run_id:
    raise AssertionError(f"workflow start did not return run_id: {start}")

heartbeat_response, heartbeat_latency = heartbeat()
require_success(heartbeat_response, "pre-replay heartbeat")
if heartbeat_latency >= 5:
    raise AssertionError(f"pre-replay heartbeat took {heartbeat_latency:.3f}s")

replay_task = poll_workflow_task()
stage("replay_task_leased", task_id=replay_task.get("task_id"))

query_holder: dict[str, Any] = {}
responder_holder: dict[str, Any] = {}
query_started = threading.Event()
query_blocked_during_replay = threading.Event()
replay_completed = threading.Event()


def call_query() -> None:
    try:
        query_holder["sent_at"] = time.monotonic()
        query_started.set()
        stage("public_query_sent")
        query_holder["response"] = request_json(
            f"/api/workflows/{urllib.parse.quote(WORKFLOW_ID)}/query/state",
            body={},
            timeout=45,
        )
        query_holder["completed_at"] = time.monotonic()
    except BaseException as error:  # noqa: BLE001 - propagate through the main test thread.
        query_holder["error"] = error


def answer_query() -> None:
    try:
        responder_holder["poll_started_at"] = time.monotonic()
        deadline = time.monotonic() + 20
        task = None
        while time.monotonic() < deadline and task is None:
            poll = request_json(
                "/api/worker/query-tasks/poll",
                body={
                    "worker_id": WORKER_ID,
                    "task_queue": TASK_QUEUE,
                    "poll_request_id": f"query-{uuid.uuid4().hex}",
                    "timeout_seconds": 2,
                },
                worker=True,
                timeout=7,
            )
            poll_body = require_success(poll, "query task poll")
            candidate = poll_body.get("task")
            if isinstance(candidate, dict):
                if not query_blocked_during_replay.is_set():
                    raise AssertionError("query task was claimed before its replay blocker was observed")
                task = candidate
                continue

            poll_status = poll_body.get("poll_status")
            if poll_status == "workflow_task_leased":
                responder_holder["blocked_at"] = time.monotonic()
                responder_holder["blocked_status"] = poll_status
                query_blocked_during_replay.set()
                stage("query_enqueued_behind_replay", poll_status=poll_status)
                if not replay_completed.wait(timeout=20):
                    raise AssertionError("replay did not complete after the pending query was observed")
            elif poll_status == "workflow_task_pending":
                raise AssertionError("signal-resume work blocked the query before replay completion")

        if task is None:
            raise AssertionError("query task was not claimable after replay completion")

        responder_holder["claimed_at"] = time.monotonic()
        responder_holder["task"] = task
        stage("query_task_claimed", query_task_id=task.get("query_task_id"))
        complete = request_json(
            f"/api/worker/query-tasks/{task['query_task_id']}/complete",
            body={
                "lease_owner": task.get("lease_owner") or WORKER_ID,
                "query_task_attempt": task["query_task_attempt"],
                "result": 0,
            },
            worker=True,
            timeout=10,
        )
        require_success(complete, "query task completion")
    except BaseException as error:  # noqa: BLE001 - propagate through the main test thread.
        responder_holder["error"] = error
        query_blocked_during_replay.set()


query_thread = threading.Thread(target=call_query, daemon=True)
responder_thread = threading.Thread(target=answer_query, daemon=True)
query_thread.start()
if not query_started.wait(2):
    raise AssertionError("public query did not start while replay task was leased")
responder_thread.start()
if not query_blocked_during_replay.wait(10):
    raise AssertionError("query polling did not expose the query queued behind replay")
if responder_holder.get("error"):
    raise responder_holder["error"]

overlap_heartbeat_response, overlap_heartbeat_latency = heartbeat()
require_success(overlap_heartbeat_response, "replay/query overlap heartbeat")
if overlap_heartbeat_latency >= 5:
    raise AssertionError(f"replay/query overlap heartbeat took {overlap_heartbeat_latency:.3f}s")
stage("replay_query_heartbeat_acknowledged", latency_seconds=overlap_heartbeat_latency)

# Exercise the same worker registration through several standalone HTTP
# processes while the public query and replay task are both in flight.
heartbeat_barrier = threading.Barrier(7)
heartbeat_results: list[tuple[dict[str, Any], float]] = []
heartbeat_errors: list[BaseException] = []
heartbeat_lock = threading.Lock()


def concurrent_heartbeat() -> None:
    try:
        heartbeat_barrier.wait(timeout=3)
        sample = heartbeat()
        with heartbeat_lock:
            heartbeat_results.append(sample)
    except BaseException as error:  # noqa: BLE001 - propagate through the main test thread.
        with heartbeat_lock:
            heartbeat_errors.append(error)


heartbeat_threads = [threading.Thread(target=concurrent_heartbeat, daemon=True) for _ in range(6)]
for thread in heartbeat_threads:
    thread.start()
heartbeat_barrier.wait(timeout=3)

time.sleep(0.75)
signal_sent_at = time.monotonic()
signal = request_json(
    f"/api/workflows/{urllib.parse.quote(WORKFLOW_ID)}/signal/increment",
    body={"input": {"amount": 5}},
    timeout=20,
)
if int(signal.get("status") or 0) != 202:
    raise AssertionError(f"signal was not accepted during replay: {signal}")
stage("signal_accepted_during_replay")

time.sleep(0.3)
complete_workflow_task(replay_task, [{
    "type": "open_condition_wait",
    "condition_key": "replay-query-regression-barrier",
    "timeout_seconds": 60,
}])
replay_completed_at = time.monotonic()
replay_completed.set()
stage("replay_task_completed")

responder_thread.join(timeout=25)
query_thread.join(timeout=25)
if responder_thread.is_alive() or query_thread.is_alive():
    raise AssertionError("query responder or public query transport timed out")
if responder_holder.get("error"):
    raise responder_holder["error"]
if query_holder.get("error"):
    raise query_holder["error"]

query_response = query_holder.get("response")
query_body = require_success(query_response, "public replay-time query")
if query_body.get("result") != 0:
    raise AssertionError(f"public replay-time query returned an untyped or stale result: {query_response}")
if not replay_completed_at <= float(responder_holder["claimed_at"]):
    raise AssertionError("query task was claimed before the replay lease completed")
if not float(responder_holder["claimed_at"]) <= float(query_holder["completed_at"]):
    raise AssertionError("public query completed before its query task was claimed")

signal_task = poll_workflow_task()
signal_claimed_at = time.monotonic()
signal_name = signal_task.get("signal_name")
if signal_name != "increment":
    event_names = [
        event.get("payload", {}).get("signal_name")
        for event in signal_task.get("history_events", [])
        if isinstance(event, dict) and event.get("event_type") == "SignalReceived"
    ]
    if "increment" not in event_names:
        raise AssertionError(f"signal resume task did not expose the accepted signal: {signal_task}")
if not float(query_holder["completed_at"]) <= signal_claimed_at:
    raise AssertionError("signal resume task was leased before the replay-time query completed")

complete_workflow_task(signal_task, [{
    "type": "open_condition_wait",
    "condition_key": "replay-query-regression-after-signal",
    "timeout_seconds": 60,
}])
signal_applied_at = time.monotonic()
if not signal_sent_at < replay_completed_at <= signal_applied_at:
    raise AssertionError("accepted replay-time signal was not applied after replay completion")
stage("signal_applied_after_replay", task_id=signal_task.get("task_id"))

for thread in heartbeat_threads:
    thread.join(timeout=12)
if heartbeat_errors or len(heartbeat_results) != len(heartbeat_threads):
    raise AssertionError(f"concurrent heartbeat transport failed: {heartbeat_errors}")
for response, latency in heartbeat_results:
    require_success(response, "concurrent heartbeat")
    if latency >= 5:
        raise AssertionError(f"concurrent heartbeat took {latency:.3f}s")

print(json.dumps({
    "status": "pass",
    "workflow_id": WORKFLOW_ID,
    "run_id": run_id,
    "replay_task_id": replay_task.get("task_id"),
    "query_task_id": responder_holder.get("task", {}).get("query_task_id"),
    "signal_task_id": signal_task.get("task_id"),
    "query_result": query_body.get("result"),
    "replay_query_heartbeat_seconds": overlap_heartbeat_latency,
    "max_concurrent_heartbeat_seconds": max(latency for _, latency in heartbeat_results),
    "ordering": "query_enqueued_during_replay_then_claimed_before_signal_resume",
}, sort_keys=True))
PY
