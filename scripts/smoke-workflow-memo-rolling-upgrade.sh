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
  if [[ "$database" == "mysql" ]]; then
    export DW_WORKFLOW_MEMO_MIGRATION_RECOVERY="raw-json"
  else
    unset DW_WORKFLOW_MEMO_MIGRATION_RECOVERY
  fi

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

case ",$databases," in
  *,mysql,*) ;;
  *) exit 0 ;;
esac

# MySQL does not wrap the published rc.47/rc.48 row rewrite in a schema
# transaction. Exercise that exact artifact with a trigger that aborts the
# fourth ordered update, then prove that the successor fails closed until the
# operator supplies the observed high-water ID.
project="$(printf '%s-%s' "$base_project" "mysql-envelope-recovery" | tr -c '[:alnum:]_-' '-')"
network="${project}_default"
export DW_MEMO_ROLLING_DB=mysql
export DW_MEMO_ROLLING_DB_HOST=mysql
export DW_MEMO_ROLLING_DB_PORT=3306
export DW_MEMO_SUCCESSOR_IMAGE="$successor_image"
export DW_MEMO_PREDECESSOR_IMAGE="${DW_MEMO_RAW_PREDECESSOR_IMAGE:-durableworkflow/server:2.0.0-rc.46}"
unset DW_WORKFLOW_MEMO_MIGRATION_RECOVERY

compose() {
  docker compose -p "$project" --profile mysql -f "$compose_file" "$@"
}

cleanup() {
  compose down -v --remove-orphans >/dev/null 2>&1 || true
}
trap cleanup EXIT

request() {
  docker run --rm --network "$network" "$curl_image" -fsS "$@"
}

mysql_client() {
  compose exec -T mysql mysql -uworkflow -pworkflow durable_workflow "$@"
}

mysql_value() {
  mysql_client --batch --skip-column-names -e "$1"
}

mysql_memo_value_identities() {
  mysql_value "SELECT CONCAT(id, ':', SHA2(value, 256)) FROM workflow_memos ORDER BY id"
}

compose up -d --wait mysql redis
compose run --rm predecessor-bootstrap
compose up -d --wait predecessor

request \
  -X POST http://predecessor:8080/api/worker/register \
  -H "Authorization: Bearer $token" \
  -H 'Content-Type: application/json' \
  -H 'X-Namespace: default' \
  -H 'X-Durable-Workflow-Protocol-Version: 1.0' \
  -d '{"worker_id":"memo-envelope-predecessor-worker","task_queue":"memo-envelope-recovery","runtime":"php","supported_workflow_types":["memo.envelope-recovery"]}' \
  >/dev/null

business_blob="$(compose run --rm --no-deps --entrypoint php successor-bootstrap -r '
require "vendor/autoload.php";
echo Workflow\V2\Support\MemoPayload::envelope("business bytes")["blob"];
')"

recovery_start_payload="$(python3 - "$business_blob" <<'PY'
import json
import sys

print(json.dumps({
    "workflow_id": "memo-envelope-recovery",
    "workflow_type": "memo.envelope-recovery",
    "task_queue": "memo-envelope-recovery",
    "memo": {
        "scalar": "migration-secret-scalar",
        "converted_envelope_looking": {"codec": "avro", "blob": sys.argv[1]},
        "list": [1, 2.5],
        "map": {"stage": "before", "attempt": 3},
        "float": 7.25,
        "raw_envelope_looking": {"codec": "avro", "blob": sys.argv[1]},
    },
}))
PY
)"

recovery_start="$(request \
  -X POST http://predecessor:8080/api/workflows \
  -H "Authorization: Bearer $token" \
  -H 'Content-Type: application/json' \
  -H 'X-Namespace: default' \
  -H 'X-Durable-Workflow-Control-Plane-Version: 2' \
  -d "$recovery_start_payload")"
recovery_run_id="$(json_value "$recovery_start" run_id)"

converted_cutoff="$(mysql_value 'SELECT id FROM workflow_memos ORDER BY id LIMIT 1 OFFSET 2')"
if [[ ! "$converted_cutoff" =~ ^[1-9][0-9]*$ ]]; then
  echo "Could not resolve the bounded predecessor rewrite cutoff." >&2
  exit 1
fi
raw_row_identities="$(mysql_memo_value_identities)"

mysql_client <<SQL
DELIMITER //
CREATE TRIGGER interrupt_published_memo_rewrite
BEFORE UPDATE ON workflow_memos
FOR EACH ROW
BEGIN
  IF NEW.id > $converted_cutoff THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'bounded published predecessor interruption';
  END IF;
END//
DELIMITER ;
SQL

export DW_MEMO_PREDECESSOR_IMAGE="${DW_MEMO_ENVELOPE_PREDECESSOR_IMAGE:-durableworkflow/server:2.0.0-rc.48}"
set +e
compose run --rm --no-deps predecessor-bootstrap php artisan migrate --force >/dev/null 2>&1
published_status="$?"
set -e
if [[ "$published_status" -eq 0 ]]; then
  echo "The published predecessor rewrite did not stop at the bounded MySQL prefix." >&2
  exit 1
fi

interrupted_row_identities="$(mysql_memo_value_identities)"
if [[ "$(mysql_value "SELECT COUNT(*) FROM migrations WHERE migration = '2026_08_25_000100_encode_workflow_memos_for_portable_runtime'")" != "0" ]]; then
  echo "The interrupted predecessor unexpectedly recorded migration completion." >&2
  exit 1
fi
python3 - "$raw_row_identities" "$interrupted_row_identities" "$converted_cutoff" <<'PY'
import sys


def identities(snapshot):
    rows = {}
    for line in snapshot.splitlines():
        row_id, digest = line.split(":", 1)
        rows[int(row_id)] = digest
    return rows


before = identities(sys.argv[1])
after = identities(sys.argv[2])
cutoff = int(sys.argv[3])

if before.keys() != after.keys():
    raise SystemExit("The interrupted predecessor changed the memo row set.")

for row_id in before:
    changed = before[row_id] != after[row_id]
    if changed != (row_id <= cutoff):
        raise SystemExit("The published predecessor did not rewrite exactly the bounded memo prefix.")
PY
mysql_value 'DROP TRIGGER interrupt_published_memo_rewrite' >/dev/null

partial_hash="$(mysql_value "SELECT SHA2(GROUP_CONCAT(CONCAT(id, ':', value) ORDER BY id SEPARATOR '|'), 256) FROM workflow_memos")"

set +e
ambiguous_output="$(compose run --rm --no-deps successor-bootstrap php artisan migrate --force 2>&1)"
ambiguous_status="$?"
set -e
if [[ "$ambiguous_status" -eq 0 ]] || ! grep -Fq 'workflow_memo_payload_migration_source_ambiguous' <<<"$ambiguous_output"; then
  echo "The successor did not fail closed for an unrecorded MySQL predecessor rewrite." >&2
  exit 1
fi
for disclosed in 'migration-secret-scalar' 'business bytes' '"stage":"before"' "$business_blob"; do
  if grep -Fq "$disclosed" <<<"$ambiguous_output"; then
    echo "The successor recovery diagnostic disclosed memo contents." >&2
    exit 1
  fi
done
if [[ "$partial_hash" != "$(mysql_value "SELECT SHA2(GROUP_CONCAT(CONCAT(id, ':', value) ORDER BY id SEPARATOR '|'), 256) FROM workflow_memos")" ]]; then
  echo "The fail-closed recovery attempt changed a memo row." >&2
  exit 1
fi

export DW_WORKFLOW_MEMO_MIGRATION_RECOVERY="envelope-prefix:$converted_cutoff"
recovery_output="$(compose run --rm successor-bootstrap 2>&1)"
for disclosed in 'migration-secret-scalar' 'business bytes' '"stage":"before"' "$business_blob"; do
  if grep -Fq "$disclosed" <<<"$recovery_output"; then
    echo "The successful recovery diagnostic disclosed memo contents." >&2
    exit 1
  fi
done
compose up -d --wait successor

recovered_view="$(request \
  -H "Authorization: Bearer $token" \
  -H 'X-Namespace: default' \
  -H 'X-Durable-Workflow-Control-Plane-Version: 2' \
  "http://successor:8080/api/workflows/memo-envelope-recovery/runs/$recovery_run_id")"

python3 - "$recovered_view" "$business_blob" <<'PY'
import json
import sys

view = json.loads(sys.argv[1])
blob = sys.argv[2]
memo = view["memo"]
assert memo["scalar"] == "migration-secret-scalar", view
assert memo["converted_envelope_looking"] == {"codec": "avro", "blob": blob}, view
assert memo["list"] == [1, 2.5], view
assert memo["map"] == {"stage": "before", "attempt": 3}, view
assert memo["float"] == 7.25 and isinstance(memo["float"], float), view
assert memo["raw_envelope_looking"] == {"codec": "avro", "blob": blob}, view
assert len(memo) == 6, view
PY

recovered_hash="$(mysql_value "SELECT SHA2(GROUP_CONCAT(CONCAT(id, ':', value, ':', portable_value, ':', portable_value_sequence) ORDER BY id SEPARATOR '|'), 256) FROM workflow_memos")"
retry_output="$(compose run --rm successor-bootstrap 2>&1)"
for disclosed in 'migration-secret-scalar' 'business bytes' '"stage":"before"' "$business_blob"; do
  if grep -Fq "$disclosed" <<<"$retry_output"; then
    echo "The retry diagnostic disclosed memo contents." >&2
    exit 1
  fi
done
retry_hash="$(mysql_value "SELECT SHA2(GROUP_CONCAT(CONCAT(id, ':', value, ':', portable_value, ':', portable_value_sequence) ORDER BY id SEPARATOR '|'), 256) FROM workflow_memos")"
if [[ "$recovered_hash" != "$retry_hash" ]]; then
  echo "Retry changed the recovered memo representation." >&2
  exit 1
fi
if [[ "$(mysql_value 'SELECT CONCAT(COUNT(*), ":", COUNT(DISTINCT `key`), ":", COUNT(portable_value_sequence)) FROM workflow_memos')" != "6:6:6" ]]; then
  echo "Recovered MySQL memos did not retain exactly one complete value per key." >&2
  exit 1
fi

echo "Published predecessor memo recovery passed for mysql"
cleanup
trap - EXIT
