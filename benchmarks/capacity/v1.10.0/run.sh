#!/usr/bin/env bash
set -euo pipefail
umask 077
suite="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
binding_language=${1:?Usage: run.sh php|python|rust 10|1000 OUTPUT_DIRECTORY [SERVER_IMAGE_DIGEST]}
width=${2:?Provide a declared workload width}
probe=${3:?Provide a new output directory}
profile="$suite/suite.json"
server_image=${4:-$(jq -er '.images.baseline' "$profile")}
if [[ ! "$server_image" =~ @sha256:[a-f0-9]{64}$ ]]; then
    printf '%s\n' 'Select an exact Server image digest.' >&2
    exit 2
fi
case "$width" in
    10) workers=$(jq -er '.workload.control_leaf_processes' "$profile") ;;
    1000) workers=$(jq -er '.workload.capacity_leaf_processes' "$profile") ;;
    *) printf '%s\n' 'Expected width 10 or 1000.' >&2; exit 2 ;;
esac
mysql_image=$(jq -er '.images.mysql' "$profile")
statement_capacity=$(jq -er '.mysql.statement_history_capacity' "$profile")
transaction_capacity=$(jq -er '.mysql.transaction_history_capacity' "$profile")
collector="$suite/collectors/mysql"
binding="$suite/bindings/$binding_language"
mysqlbinlog=$(realpath -- "${CAPACITY_MYSQLBINLOG:?Provide the matching MySQL binlog reader}")
mysql_version=$(jq -er '.mysql.version' "$profile")
case "$binding_language" in
    php)
        vendor=$(realpath -- "${CAPACITY_PHP_VENDOR:-$binding/vendor}")
        adapter_entrypoint=php
        adapter_program=(/benchmark/child_fanout.php)
        adapter_mounts=(--mount "type=bind,source=$binding/child_fanout.php,target=/benchmark/child_fanout.php,readonly"
            --mount "type=bind,source=$vendor,target=/benchmark/vendor,readonly")
        ;;
    python)
        vendor=$(realpath -- "${CAPACITY_PYTHON_SITE_PACKAGES:-$binding/vendor}")
        adapter_entrypoint=python
        adapter_program=(/benchmark/child_fanout.py)
        adapter_mounts=(--mount "type=bind,source=$binding/child_fanout.py,target=/benchmark/child_fanout.py,readonly"
            --mount "type=bind,source=$vendor,target=/benchmark/vendor,readonly")
        ;;
    rust)
        adapter_binary=$(realpath -- "${CAPACITY_RUST_ADAPTER:-$binding/target/release/durable-workflow-capacity-child-fanout}")
        adapter_entrypoint=/benchmark/child_fanout
        adapter_program=()
        adapter_mounts=(--mount "type=bind,source=$adapter_binary,target=/benchmark/child_fanout,readonly")
        ;;
    *) printf '%s\n' 'Expected php, python, or rust.' >&2; exit 2 ;;
esac
adapter_image=$(jq -er --arg binding "$binding_language" '.images[$binding]' "$profile")
sdk_version=$(jq -er --arg binding "$binding_language" '.sdk_versions[$binding]' "$profile")
mkdir -- "$probe"
base="general-capacity-child-$binding_language-$width-$(openssl rand -hex 8)"
network="$base-net"
database="$base-db"
server="$base-server"
export MYSQL_ROOT_PASSWORD="$(openssl rand -hex 28)"
export MYSQL_PASSWORD="$(openssl rand -hex 28)"
export DURABLE_WORKFLOW_TOKEN="$(openssl rand -hex 28)"
export DB_PASSWORD="$MYSQL_PASSWORD"
export DW_AUTH_TOKEN="$DURABLE_WORKFLOW_TOKEN"
export DURABLE_WORKFLOW_RUNTIME_URL="http://$server:8080"
jq -n --arg version "$(jq -r '.suite_version' "$profile")" --arg binding "$binding_language" \
    --arg server "$server_image" --arg mysql "$mysql_image" --arg adapter "$adapter_image" \
    --arg architecture "$(docker info --format '{{.Architecture}}')" --argjson width "$width" \
    --argjson leaf_processes "$workers" \
    '{suite_version:$version,binding:$binding,server_image:$server,mysql_image:$mysql,adapter_image:$adapter,
      architecture:$architecture,width:$width,leaf_processes:$leaf_processes}' > "$probe/identity.json"
docker network create --internal "$network" >/dev/null
cleanup() {
    local status=$?
    local attempt component remaining
    local cleaned=false
    local -a owned
    trap - EXIT
    if remaining=$(docker ps -a --filter "name=^/$base-" --format '{{.Names}}') && [[ -n "$remaining" ]]; then
        mapfile -t owned <<< "$remaining"
        for component in "${owned[@]}"; do
            docker logs "$component" > "$probe/$component.log" 2>&1 || true
        done
        docker stop -t 5 "${owned[@]}" >/dev/null 2>&1 || true
    fi
    # Docker can report a stopped container before completing its --rm removal.
    for ((attempt = 0; attempt < 20; attempt++)); do
        if remaining=$(docker ps -a --filter "name=^/$base-" --format '{{.Names}}') && [[ -z "$remaining" ]]; then
            cleaned=true
            break
        fi
        sleep 0.25
    done
    if [[ "$cleaned" == false ]]; then
        printf 'Could not verify removal of containers for %s.\n' "$base" >&2
        if (( status == 0 )); then status=1; fi
    fi
    if ! docker network rm "$network" >/dev/null; then
        printf 'Could not remove the network for %s.\n' "$base" >&2
        if (( status == 0 )); then status=1; fi
    fi
    exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
reader_version=$(docker run --rm --name "$base-reader-version" --init --pull never --network none \
    --cpus=1 --memory=160m --pids-limit=64 \
    --mount "type=bind,source=$mysqlbinlog,target=/fixture/mysqlbinlog,readonly" \
    --entrypoint /fixture/mysqlbinlog "$mysql_image" --version)
if [[ "$reader_version" != *" Ver $mysql_version "* ]]; then
    printf 'Expected the MySQL %s binlog reader.\n' "$mysql_version" >&2
    exit 2
fi
docker run --rm -d --name "$database" --init --pull never --network "$network" --cpus=3 --memory=5g --pids-limit=210 \
    --env MYSQL_ROOT_PASSWORD --env MYSQL_PASSWORD --env MYSQL_USER=capacity_worker --env MYSQL_DATABASE=fanout_fixture \
    --tmpfs /var/lib/mysql:rw,nosuid,nodev,size=860m,mode=1777 "$mysql_image" \
    --performance-schema=ON --performance-schema-events-transactions-history-long-size="$transaction_capacity" \
    --performance-schema-events-statements-history-long-size="$statement_capacity" --innodb-buffer-pool-size=192M \
    --innodb-redo-log-capacity=96M --gtid-mode=ON --enforce-gtid-consistency=ON --binlog-row-image=MINIMAL >/dev/null
mysql() {
    docker exec -i "$database" sh -ec 'export MYSQL_PWD="$MYSQL_ROOT_PASSWORD"; exec mysql -uroot -NBr "$@"' mysql "$@"
}
for attempt in $(seq 1 55); do
    if mysql -h127.0.0.1 -e 'SELECT 1' >/dev/null 2>&1; then break; fi
    sleep 1
done
mysql -h127.0.0.1 <<'SQL'
UPDATE performance_schema.setup_instruments SET ENABLED='YES', TIMED='YES' WHERE NAME='transaction';
UPDATE performance_schema.setup_consumers SET ENABLED='YES'
WHERE NAME IN ('events_statements_current','events_statements_history_long','events_transactions_current','events_transactions_history_long','statements_digest');
SQL
native_env=(--env DW_RUST_EXPERIMENTAL=1 --env DB_CONNECTION=mysql --env DB_HOST="$database" --env DB_PORT=3306
    --env DB_DATABASE=fanout_fixture --env DB_USERNAME=capacity_worker --env DB_PASSWORD
    --env DW_AUTH_TOKEN --env DW_HTTP_ACCESS_LOG=false)
native_schema() {
    local command=$1
    docker run --rm --name "$base-$command" --init --pull never --network "$network" \
        --cpus=2 --memory=2g --pids-limit=152 "${native_env[@]}" "$server_image" "$command"
}
if native_schema schema-bootstrap > "$probe/bootstrap.json" 2> "$probe/bootstrap.log"; then
    :
elif grep -Fxq native_child_checkpoint_trigger_privilege_required "$probe/bootstrap.log"; then
    native_schema schema-checkpoint-ddl | mysql
    native_schema schema-bootstrap > "$probe/bootstrap-resumed.json"
else
    printf 'Native schema bootstrap failed. See %s.\n' "$probe/bootstrap.log" >&2
    exit 1
fi
docker run --rm -d --name "$server" --init --pull never --network "$network" --cpus=4 --memory=3g --pids-limit=240 \
    "${native_env[@]}" "$server_image" > /dev/null
adapter_args=(--rm --init --pull never --network "$network" --cpus=0.65 --memory=384m --pids-limit=72 --cap-drop=all --security-opt no-new-privileges
    "${adapter_mounts[@]}"
    --env DURABLE_WORKFLOW_RUNTIME_URL --env DURABLE_WORKFLOW_TOKEN --env PYTHONPATH=/benchmark/vendor --entrypoint "$adapter_entrypoint")
native_ready=false
for attempt in $(seq 1 55); do
    if docker exec "$server" bash -c 'exec 3<>/dev/tcp/127.0.0.1/8080 && printf "GET /api/ready HTTP/1.0\r\nHost: localhost\r\n\r\n" >&3 && head -n 1 <&3 | grep -q " 200 "' 2>/dev/null; then
        native_ready=true
        break
    fi
    sleep 1
done
[[ "$native_ready" == true ]]
worker_nodes=("$base-parent")
docker run -d --name "$base-parent" "${adapter_args[@]}" "$adapter_image" "${adapter_program[@]}" parent > /dev/null
for worker in $(seq 1 "$workers"); do
    name="$base-leaf-$worker"
    docker run -d --name "$name" "${adapter_args[@]}" "$adapter_image" "${adapter_program[@]}" leaf > /dev/null
    worker_nodes+=("$name")
done
for attempt in $(seq 1 55); do
    ready=$(mysql fanout_fixture -e "
        SELECT CONCAT(COALESCE(SUM(task_queue='capacity-v1-10-parent'),0),':',
                      COALESCE(SUM(task_queue='capacity-v1-10-leaf'),0))
        FROM workflow_worker_registrations WHERE status='active'")
    if [[ "$ready" == "1:$workers" ]]; then break; fi
    sleep 1
done
[[ "$ready" == "1:$workers" ]]
mysql -e "SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_row_lock_waits','Innodb_row_lock_time')" > "$probe/before.tsv"
timeout --kill-after=7s 250s docker run --name "$base-client" "${adapter_args[@]}" "$adapter_image" "${adapter_program[@]}" run "$width" "capacity-$binding_language-$width" > "$probe/result.json" 2>"$probe/client.log"
for worker in "${worker_nodes[@]}"; do
    docker logs "$worker" > "$probe/$worker.log" 2>&1
done
docker stop -t 5 "${worker_nodes[@]}" >/dev/null
mysql -e "SHOW GLOBAL STATUS WHERE Variable_name IN ('Innodb_row_lock_waits','Innodb_row_lock_time')" > "$probe/after.tsv"
jq -e --arg sdk_version "$sdk_version" --argjson width "$width" \
    '.sdk_version == $sdk_version and .width == $width and .result == $width * ($width + 1) / 2' \
    "$probe/result.json" >/dev/null
run_id=$(jq -er '.run_id' "$probe/result.json")
[[ "$run_id" =~ ^[0-9A-Z]{26}$ ]]
mysql fanout_fixture <<SQL >"$probe/history.tsv"
SELECT event_type, COUNT(*) FROM workflow_history_events WHERE workflow_run_id='$run_id' GROUP BY event_type ORDER BY event_type;
SELECT 'child_calls', COUNT(*) FROM workflow_child_calls WHERE parent_workflow_run_id='$run_id' AND status='completed';
SELECT CONCAT('parent_task:',status), COUNT(*) FROM workflow_tasks WHERE workflow_run_id='$run_id' GROUP BY status;
SELECT 'fan_in_ms', TIMESTAMPDIFF(MICROSECOND,MIN(CASE WHEN event_type='ChildWorkflowScheduled' THEN COALESCE(recorded_at_utc,recorded_at,created_at) END),MAX(CASE WHEN event_type='ChildRunCompleted' THEN COALESCE(recorded_at_utc,recorded_at,created_at) END))/1000
  FROM workflow_history_events WHERE workflow_run_id='$run_id';
SELECT 'last_child_to_resume_ms', TIMESTAMPDIFF(MICROSECOND,
 (SELECT MAX(COALESCE(recorded_at_utc,recorded_at,created_at)) FROM workflow_history_events WHERE workflow_run_id='$run_id' AND event_type='ChildRunCompleted'),
 (SELECT MAX(leased_at) FROM workflow_tasks WHERE workflow_run_id='$run_id' AND task_type='workflow'))/1000;
SQL
awk -F '\t' -v width="$width" '
$1=="ChildWorkflowScheduled" && $2==width { scheduled=1 }
$1=="ChildRunCompleted" && $2==width { completed=1 }
$1=="WorkflowCompleted" && $2==1 { parent=1 }
$1=="child_calls" && $2==width { calls=1 }
$1=="parent_task:completed" && $2==2 { woke=1 }
$1=="fan_in_ms" && $2 ~ /^[0-9]+([.][0-9]+)?$/ { fan_in=1 }
$1=="last_child_to_resume_ms" && $2 ~ /^[0-9]+([.][0-9]+)?$/ { resume=1 }
END { exit !(scheduled&&completed&&parent&&calls&&woke&&fan_in&&resume) }
' "$probe/history.tsv"
mysql -e 'SELECT COUNT(*),@@performance_schema_events_statements_history_long_size FROM performance_schema.events_statements_history_long; SELECT COUNT(*),@@performance_schema_events_transactions_history_long_size FROM performance_schema.events_transactions_history_long' > "$probe/history-retention.tsv"
status_column=$(mysql -e "SELECT ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='fanout_fixture' AND TABLE_NAME='workflow_child_calls' AND COLUMN_NAME='status'")
[[ "$status_column" =~ ^[0-9]+$ ]]
{
    printf "%s\n" "SET @capacity_schema='fanout_fixture';"
    cat "$collector/child_close_statements.sql"
} | mysql > "$probe/statement-metrics.tsv"
mysql -e 'FLUSH BINARY LOGS'
mapfile -t logs < <(mysql -e 'SHOW BINARY LOGS' | cut -f1)
(( ${#logs[@]} >= 2 ))
{
    for log in "${logs[@]:0:${#logs[@]}-1}"; do
        [[ "$log" =~ ^binlog[.][0-9]+$ ]]
        docker exec "$database" cat "/var/lib/mysql/$log" |
            docker run --rm -i --name "$base-binlog" --init --pull never --network none --cpus=1 --memory=700m --pids-limit=72 \
                --tmpfs /tmp:rw,nosuid,nodev,size=400m,mode=1777 --mount "type=bind,source=$mysqlbinlog,target=/fixture/mysqlbinlog,readonly" \
                --entrypoint sh "$mysql_image" -ec 'cat > /tmp/binlog; exec /fixture/mysqlbinlog --base64-output=DECODE-ROWS --verbose /tmp/binlog'
    done
} | python3 "$collector/child_close_evidence.py" --schema fanout_fixture --status-column "$status_column" \
      --expected-closes "$width" --statements "$probe/statement-metrics.tsv" > "$probe/child-close-metrics.jsonl"
jq -c '{sdk_version,width,result,elapsed_ms}' "$probe/result.json"
cat "$probe/history.tsv"
wc -l "$probe/child-close-metrics.jsonl"
