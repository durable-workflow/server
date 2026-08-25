#!/usr/bin/env bash

set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
compose_file="${DW_MEMO_ROLLING_COMPOSE_FILE:-$repo_root/docker-compose.memo-rolling.yml}"
databases="${DW_MEMO_ROLLING_DATABASES:-mysql,pgsql}"
successor_image="${DW_MEMO_SUCCESSOR_IMAGE:-durable-workflow/server-memo-rolling:local}"
curl_image="${DW_MEMO_ROLLING_CURL_IMAGE:-curlimages/curl:8.10.1}"
base_project="${COMPOSE_PROJECT_NAME:-dw-memo-rolling-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-smoke}}"
token="memo-rolling-token"

json_value() {
  python3 - "$1" "$2" <<'PY'
import json
import sys

value = json.loads(sys.argv[1])
for part in sys.argv[2].split('.'):
    value = value[int(part)] if isinstance(value, list) else value.get(part)
print('' if value is None else value)
PY
}

docker build -t "$successor_image" "$repo_root"

IFS=',' read -r -a database_list <<<"$databases"
for database in "${database_list[@]}"; do
  case "$database" in
    mysql) database_port=3306 ;;
    pgsql) database_port=5432 ;;
    *) echo "Unsupported memo rolling database: $database" >&2; exit 2 ;;
  esac

  project="$(printf '%s-%s' "$base_project" "$database" | tr -c '[:alnum:]_-' '-')"
  network="${project}_default"
  export DW_MEMO_ROLLING_DB="$database"
  export DW_MEMO_ROLLING_DB_HOST="$database"
  export DW_MEMO_ROLLING_DB_PORT="$database_port"
  export DW_MEMO_SUCCESSOR_IMAGE="$successor_image"

  compose() {
    docker compose -p "$project" --profile "$database" -f "$compose_file" "$@"
  }

  cleanup() {
    compose down -v --remove-orphans >/dev/null 2>&1 || true
  }
  trap cleanup EXIT

  request() {
    docker run --rm --network "$network" "$curl_image" -fsS "$@"
  }

  compose up -d --wait "$database" redis
  compose run --rm predecessor-bootstrap
  compose up -d --wait predecessor

  request \
    -X POST http://predecessor:8080/api/worker/register \
    -H "Authorization: Bearer $token" \
    -H 'Content-Type: application/json' \
    -H 'X-Namespace: default' \
    -H 'X-Durable-Workflow-Protocol-Version: 1.0' \
    -d '{"worker_id":"memo-rolling-predecessor-worker","task_queue":"memo-rolling","runtime":"php","supported_workflow_types":["memo.rolling"]}' \
    >/dev/null

  original_start="$(request \
    -X POST http://predecessor:8080/api/workflows \
    -H "Authorization: Bearer $token" \
    -H 'Content-Type: application/json' \
    -H 'X-Namespace: default' \
    -H 'X-Durable-Workflow-Control-Plane-Version: 2' \
    -d '{"workflow_id":"memo-rolling-original","workflow_type":"memo.rolling","task_queue":"memo-rolling","memo":{"scalar":"legacy","list":[1,2.5],"map":{"stage":"before"},"float":7.25,"envelope_looking":{"codec":"avro","blob":"customer-data"}}}')"
  original_run_id="$(json_value "$original_start" run_id)"

  set +e
  compose run --rm --no-deps --entrypoint php successor-bootstrap -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
App\Support\WorkflowMemoPayloadMigration::ensureExpandedSchema();
$count = App\Support\WorkflowMemoPayloadMigration::backfillBatch(2);
fwrite(STDERR, "interrupted after {$count} memo rows\n");
exit($count === 2 ? 42 : 43);
'
  interruption_status="$?"
  set -e
  test "$interruption_status" -eq 42

  compose run --rm successor-bootstrap
  compose up -d --wait successor

  predecessor_view="$(request \
    -H "Authorization: Bearer $token" \
    -H 'X-Namespace: default' \
    -H 'X-Durable-Workflow-Control-Plane-Version: 2' \
    "http://predecessor:8080/api/workflows/memo-rolling-original/runs/$original_run_id")"

  predecessor_write="$(request \
    -X POST http://predecessor:8080/api/workflows \
    -H "Authorization: Bearer $token" \
    -H 'Content-Type: application/json' \
    -H 'X-Namespace: default' \
    -H 'X-Durable-Workflow-Control-Plane-Version: 2' \
    -d '{"workflow_id":"memo-rolling-predecessor-write","workflow_type":"memo.rolling","task_queue":"memo-rolling-unpolled","memo":{"writer":"predecessor"}}')"
  predecessor_write_run_id="$(json_value "$predecessor_write" run_id)"

  successor_view="$(request \
    -H "Authorization: Bearer $token" \
    -H 'X-Namespace: default' \
    -H 'X-Durable-Workflow-Control-Plane-Version: 2' \
    "http://successor:8080/api/workflows/memo-rolling-predecessor-write/runs/$predecessor_write_run_id")"

  request \
    -X POST http://successor:8080/api/worker/register \
    -H "Authorization: Bearer $token" \
    -H 'Content-Type: application/json' \
    -H 'X-Namespace: default' \
    -H 'X-Durable-Workflow-Protocol-Version: 1.16' \
    -d '{"worker_id":"memo-rolling-worker","task_queue":"memo-rolling","runtime":"php","supported_workflow_types":["memo.rolling"],"capabilities":["memo_upserts"]}' \
    >/dev/null

  poll="$(request \
    -X POST http://successor:8080/api/worker/workflow-tasks/poll \
    -H "Authorization: Bearer $token" \
    -H 'Content-Type: application/json' \
    -H 'X-Namespace: default' \
    -H 'X-Durable-Workflow-Protocol-Version: 1.16' \
    -d '{"worker_id":"memo-rolling-worker","task_queue":"memo-rolling"}')"
  task_id="$(json_value "$poll" task.task_id)"
  attempt="$(json_value "$poll" task.workflow_task_attempt)"
  lease_owner="$(json_value "$poll" task.lease_owner)"
  memo_entries="$(compose run --rm --no-deps --entrypoint php successor-bootstrap -r 'require "vendor/autoload.php"; echo json_encode(Workflow\V2\Support\MemoPayload::mapEnvelope(["scalar" => "successor", "updated" => true]), JSON_THROW_ON_ERROR);')"

  completion="$(python3 - "$lease_owner" "$attempt" "$memo_entries" <<'PY'
import json
import sys

print(json.dumps({
    "lease_owner": sys.argv[1],
    "workflow_task_attempt": int(sys.argv[2]),
    "commands": [
        {"type": "upsert_memo", "entries": json.loads(sys.argv[3])},
        {"type": "continue_as_new", "workflow_type": "memo.rolling", "arguments": "wwHioz3/VYAiNwwCCgxBZGEgdjIA"},
    ],
}))
PY
)"

  request \
    -X POST "http://successor:8080/api/worker/workflow-tasks/$task_id/complete" \
    -H "Authorization: Bearer $token" \
    -H 'Content-Type: application/json' \
    -H 'X-Namespace: default' \
    -H 'X-Durable-Workflow-Protocol-Version: 1.16' \
    -d "$completion" \
    >/dev/null

  runs="$(request \
    -H "Authorization: Bearer $token" \
    -H 'X-Namespace: default' \
    -H 'X-Durable-Workflow-Control-Plane-Version: 2' \
    http://successor:8080/api/workflows/memo-rolling-original/runs)"
  continued_run_id="$(json_value "$runs" runs.1.run_id)"
  continued_view="$(request \
    -H "Authorization: Bearer $token" \
    -H 'X-Namespace: default' \
    -H 'X-Durable-Workflow-Control-Plane-Version: 2' \
    "http://predecessor:8080/api/workflows/memo-rolling-original/runs/$continued_run_id")"

  python3 - "$predecessor_view" "$successor_view" "$continued_view" <<'PY'
import json
import sys

predecessor_view, successor_view, continued_view = map(json.loads, sys.argv[1:])
assert predecessor_view["memo"]["scalar"] == "legacy", predecessor_view
assert predecessor_view["memo"]["float"] == 7.25, predecessor_view
assert isinstance(predecessor_view["memo"]["float"], float), predecessor_view
assert predecessor_view["memo"]["envelope_looking"] == {"codec": "avro", "blob": "customer-data"}, predecessor_view
assert successor_view["memo"] == {"writer": "predecessor"}, successor_view
assert continued_view["memo"]["scalar"] == "successor", continued_view
assert continued_view["memo"]["updated"] is True, continued_view
assert continued_view["memo"]["envelope_looking"] == {"codec": "avro", "blob": "customer-data"}, continued_view
PY

  echo "Memo rolling upgrade smoke passed for $database"
  cleanup
  trap - EXIT
done
