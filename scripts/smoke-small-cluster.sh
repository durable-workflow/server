#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
COMPOSE_FILE="${DW_SMALL_CLUSTER_COMPOSE_FILE:-$ROOT_DIR/docker-compose.small-cluster.yml}"
DATABASES="${DW_SMALL_CLUSTER_DATABASES:-mysql,pgsql}"
TOKEN="${DW_AUTH_TOKEN:-small-cluster-token}"
WORKER_ID="${DW_SMALL_CLUSTER_WORKER_ID:-small-cluster-worker}"
TASK_QUEUE="${DW_SMALL_CLUSTER_TASK_QUEUE:-small-cluster}"
WORKFLOW_TYPE="${DW_SMALL_CLUSTER_WORKFLOW_TYPE:-small.cluster.workflow}"
CURL_IMAGE="${DW_SMALL_CLUSTER_CURL_IMAGE:-curlimages/curl:8.10.1}"

normalize_project() {
  printf '%s' "$1" | tr -c '[:alnum:]_-' '-'
}

json_value() {
  local file="$1"
  local expr="$2"

  python3 - "$file" "$expr" <<'PY'
import json
import sys

data = json.load(open(sys.argv[1], encoding="utf-8"))
value = data

for part in sys.argv[2].split("."):
    if part == "":
        continue
    if isinstance(value, list):
        value = value[int(part)]
    else:
        value = value.get(part)

if value is None:
    print("")
elif isinstance(value, bool):
    print("true" if value else "false")
else:
    print(value)
PY
}

curl_json() {
  local output="$1"
  shift

  if [ -n "${DW_SMALL_CLUSTER_NETWORK:-}" ]; then
    docker run --rm --network "$DW_SMALL_CLUSTER_NETWORK" "$CURL_IMAGE" -fsS "$@" >"$output"
  else
    curl -fsS "$@" >"$output"
  fi

  cat "$output"
  echo
}

curl_json_with_retry() {
  local output="$1"
  shift

  for attempt in $(seq 1 90); do
    if [ -n "${DW_SMALL_CLUSTER_NETWORK:-}" ]; then
      if docker run --rm --network "$DW_SMALL_CLUSTER_NETWORK" "$CURL_IMAGE" -fsS "$@" >"$output"; then
        status=0
      else
        status="$?"
      fi
    else
      if curl -fsS "$@" >"$output"; then
        status=0
      else
        status="$?"
      fi
    fi

    if [ "$status" -eq 0 ]; then
      cat "$output"
      echo
      return 0
    fi

    if [ "$attempt" -eq 90 ]; then
      echo "Request failed after ${attempt} attempts: curl $*" >&2
      return 1
    fi

    sleep 1
  done
}

run_database_smoke() {
  local database="$1"
  local db_host="$database"
  local db_port="3306"
  local lb_port="${DW_SMALL_CLUSTER_LB_PORT:-18086}"
  local server_a_port="${DW_SMALL_CLUSTER_SERVER_A_PORT:-18084}"
  local server_b_port="${DW_SMALL_CLUSTER_SERVER_B_PORT:-18085}"
  local lb_url="http://load-balancer:8080"
  local server_a_url="http://server-a:8080"
  local server_b_url="http://server-b:8080"
  local project
  local workflow_id
  local run_id
  local task_id
  local lease_owner
  local attempt

  if [ "$database" = "pgsql" ]; then
    db_port="5432"
  fi

  project="$(normalize_project "dw-small-cluster-${database}-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}")"
  workflow_id="wf-small-cluster-${database}-$(date +%s)"

  export DW_SMALL_CLUSTER_DB="$database"
  export DW_SMALL_CLUSTER_DB_HOST="$db_host"
  export DW_SMALL_CLUSTER_DB_PORT="$db_port"
  export DW_SMALL_CLUSTER_LB_PORT="$lb_port"
  export DW_SMALL_CLUSTER_SERVER_A_PORT="$server_a_port"
  export DW_SMALL_CLUSTER_SERVER_B_PORT="$server_b_port"
  export DW_SMALL_CLUSTER_NETWORK="${project}_default"
  export DW_AUTH_TOKEN="$TOKEN"

  compose() {
    docker compose -p "$project" --profile "$database" -f "$COMPOSE_FILE" "$@"
  }

  cleanup() {
    compose down -v --remove-orphans >/dev/null 2>&1 || true
  }

  trap cleanup EXIT

  echo "Small cluster smoke"
  echo "  database: $database"
  echo "  project: $project"
  echo "  compose: $COMPOSE_FILE"
  echo "  network: $DW_SMALL_CLUSTER_NETWORK"
  echo "  load balancer: ${lb_url} (host port ${lb_port})"
  echo "  server-a: ${server_a_url} (host port ${server_a_port})"
  echo "  server-b: ${server_b_url} (host port ${server_b_port})"

  compose up -d --build --wait

  curl_json_with_retry "/tmp/dw-small-cluster-${database}-health.json" \
    "${lb_url}/api/health"
  curl_json_with_retry "/tmp/dw-small-cluster-${database}-ready.json" \
    "${lb_url}/api/ready"
  curl_json_with_retry "/tmp/dw-small-cluster-${database}-cluster.json" \
    -H "Authorization: Bearer ${TOKEN}" \
    "${lb_url}/api/cluster/info"

  curl_json "/tmp/dw-small-cluster-${database}-register.json" \
    -X POST "${lb_url}/api/worker/register" \
    -H "Authorization: Bearer ${TOKEN}" \
    -H "Content-Type: application/json" \
    -H "X-Namespace: default" \
    -H "X-Durable-Workflow-Protocol-Version: 1.0" \
    -d "{\"worker_id\":\"${WORKER_ID}-${database}\",\"task_queue\":\"${TASK_QUEUE}\",\"runtime\":\"python\",\"supported_workflow_types\":[\"${WORKFLOW_TYPE}\"]}"

  curl_json "/tmp/dw-small-cluster-${database}-start.json" \
    -X POST "${lb_url}/api/workflows" \
    -H "Authorization: Bearer ${TOKEN}" \
    -H "Content-Type: application/json" \
    -H "X-Namespace: default" \
    -H "X-Durable-Workflow-Control-Plane-Version: 2" \
    -d "{\"workflow_id\":\"${workflow_id}\",\"workflow_type\":\"${WORKFLOW_TYPE}\",\"task_queue\":\"${TASK_QUEUE}\",\"input\":[\"Ada\"]}"

  run_id="$(json_value "/tmp/dw-small-cluster-${database}-start.json" "run_id")"

  curl_json "/tmp/dw-small-cluster-${database}-poll.json" \
    -X POST "${server_a_url}/api/worker/workflow-tasks/poll" \
    -H "Authorization: Bearer ${TOKEN}" \
    -H "Content-Type: application/json" \
    -H "X-Namespace: default" \
    -H "X-Durable-Workflow-Protocol-Version: 1.0" \
    -d "{\"worker_id\":\"${WORKER_ID}-${database}\",\"task_queue\":\"${TASK_QUEUE}\"}"

  task_id="$(json_value "/tmp/dw-small-cluster-${database}-poll.json" "task.task_id")"
  lease_owner="$(json_value "/tmp/dw-small-cluster-${database}-poll.json" "task.lease_owner")"
  attempt="$(json_value "/tmp/dw-small-cluster-${database}-poll.json" "task.workflow_task_attempt")"

  if [ -z "$task_id" ] || [ -z "$lease_owner" ] || [ -z "$attempt" ]; then
    echo "Poll through server-a did not return a workflow task." >&2
    compose ps >&2 || true
    compose logs server-a server-b bootstrap scheduler >&2 || true
    return 1
  fi

  curl_json "/tmp/dw-small-cluster-${database}-complete.json" \
    -X POST "${server_b_url}/api/worker/workflow-tasks/${task_id}/complete" \
    -H "Authorization: Bearer ${TOKEN}" \
    -H "Content-Type: application/json" \
    -H "X-Namespace: default" \
    -H "X-Durable-Workflow-Protocol-Version: 1.0" \
    -d "{\"lease_owner\":\"${lease_owner}\",\"workflow_task_attempt\":${attempt},\"commands\":[{\"type\":\"complete_workflow\",\"result\":null}]}"

  curl_json "/tmp/dw-small-cluster-${database}-show-run.json" \
    -H "Authorization: Bearer ${TOKEN}" \
    -H "X-Namespace: default" \
    -H "X-Durable-Workflow-Control-Plane-Version: 2" \
    "${lb_url}/api/workflows/${workflow_id}/runs/${run_id}"

  DW_SMALL_CLUSTER_DATABASE="$database" \
  DW_SMALL_CLUSTER_WORKFLOW_ID="$workflow_id" \
  DW_SMALL_CLUSTER_RUN_ID="$run_id" \
  python3 - <<'PY'
import json
import os
from pathlib import Path

database = os.environ["DW_SMALL_CLUSTER_DATABASE"]

def read(name):
    return json.loads(Path(f"/tmp/dw-small-cluster-{database}-{name}.json").read_text())

health = read("health")
ready = read("ready")
cluster = read("cluster")
registered = read("register")
started = read("start")
polled = read("poll")
completed = read("complete")
show_run = read("show-run")

assert health.get("status") == "serving", health
assert ready.get("status") == "ready", ready
assert cluster.get("version"), cluster
assert cluster.get("server_id") in {"server-a", "server-b"}, cluster
assert registered.get("registered") is True, registered
assert started.get("workflow_id") == os.environ["DW_SMALL_CLUSTER_WORKFLOW_ID"], started
assert started.get("run_id") == os.environ["DW_SMALL_CLUSTER_RUN_ID"], started
assert polled.get("task", {}).get("workflow_id") == os.environ["DW_SMALL_CLUSTER_WORKFLOW_ID"], polled
assert completed.get("recorded") is True, completed
assert completed.get("run_status") == "completed", completed
assert show_run.get("status") == "completed", show_run
PY

  echo "Small cluster smoke passed for ${database}"
  cleanup
  trap - EXIT
}

IFS=',' read -r -a database_list <<<"$DATABASES"
for database in "${database_list[@]}"; do
  case "$database" in
    mysql|pgsql) run_database_smoke "$database" ;;
    *)
      echo "Unsupported DW_SMALL_CLUSTER_DATABASES entry: $database" >&2
      echo "Expected comma-separated entries from: mysql,pgsql" >&2
      exit 2
      ;;
  esac
done
