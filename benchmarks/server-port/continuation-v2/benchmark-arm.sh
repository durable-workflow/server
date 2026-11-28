#!/usr/bin/env bash
set -euo pipefail

task=$1
worker_image=$2
server_image=$3
mysql_image=$4
shift 4
database=$task-db
server=$task-server
network=$task
client=$task-client
fixture_token=fixture-token
mysql_root_password=fixture-root-pass
db_flags=(-e DB_CONNECTION=mysql -e DB_HOST=database -e DB_PORT=3306 -e DB_DATABASE=fixture -e DB_USERNAME=workflow -e DB_PASSWORD=fixture-db-pass)
server_flags=(-e DW_AUTH_DRIVER=token -e "DW_AUTH_TOKEN=$fixture_token"
    -e DW_SERVER_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=
    -e DW_TASK_DISPATCH_MODE=poll -e DW_WORKER_POLL_TIMEOUT=5)
common_flags=(--network "$network" --label "durable-workflow.task=$task" --pids-limit 160 --init)
container_flags=("${common_flags[@]}" --cpus 3 --memory 2g)
php_flags=("${common_flags[@]}" --cpus 1 --memory 256m --read-only --tmpfs /tmp:rw,nosuid,nodev,size=24m --cap-drop ALL)
arm=$1
shape=$2
delay=$3
count=$4
report=$5

cleanup() {
    local status=$? containers container_id
    trap - EXIT
    if (( status != 0 )); then
        for owned in "$server" "$task-worker-1"; do
            docker logs --tail 20 "$owned" >&2 || true
        done
    fi
    containers=$(docker ps -aq --filter "label=durable-workflow.task=$task") || status=1
    for container_id in $containers; do
        docker rm -fv "$container_id" >/dev/null || status=1
    done
    docker network rm "$network" >/dev/null || status=1
    containers=$(docker ps -aq --filter "label=durable-workflow.task=$task") || status=1
    test -z "$containers" || status=1
    exit "$status"
}

sql() {
    docker exec --env "MYSQL_PWD=$mysql_root_password" "$database" mysql -uroot --batch --skip-column-names fixture -e "$1"
}

database_ready() {
    docker exec --env MYSQL_PWD=fixture-db-pass "$database" mysql --protocol=TCP --host=127.0.0.1 \
        --user=workflow --batch --skip-column-names fixture -e 'select 1'
}

wait_until() {
    local max_seconds=$1
    shift
    local deadline=$((SECONDS + max_seconds))
    until "$@" >/dev/null 2>&1; do
        if (( SECONDS >= deadline )); then
            printf 'Timed out waiting for %s\n' "$1" >&2
            return 1
        fi
        sleep 1
    done
}

ready() {
    docker exec "$server" bash -ec 'exec 3<>/dev/tcp/127.0.0.1/8080
        printf "GET /api/ready HTTP/1.0\r\nHost: localhost\r\n\r\n" >&3
        read -r line <&3
        [[ "$line" == *" 200 "* ]]'
}

worker_ready() {
    [[ "$(docker logs "$1" 2>/dev/null)" == *registered* ]]
}

cpu_us() {
    docker exec "$1" cat /sys/fs/cgroup/cpu.stat | awk '/^usage_usec /{print $2}'
}

workload() {
    docker run --rm --name "$client" "${php_flags[@]}" --user "$(id -u):$(id -g)" \
        --entrypoint php "$worker_image" benchmark.php "$@"
}

count_completed() {
    [[ "$(sql "select count(*) from workflow_runs where workflow_instance_id like '$1-%' and status='completed'")" == "$2" ]]
}

sql_totals="select sum(COUNT_STAR), sum(if(EVENT_NAME='statement/sql/commit', COUNT_STAR, 0))
    from performance_schema.events_statements_summary_by_account_by_event_name
    where USER='workflow' and EVENT_NAME like 'statement/sql/%'"

[[ "$arm" == off || "$arm" == on ]]
[[ "$shape" == batch || "$shape" == sequential ]]
[[ "$delay" == 0 || "$delay" == 20 ]]
[[ "$count" =~ ^[1-9][0-9]*$ ]]
docker network create --internal --label "durable-workflow.task=$task" "$network" >/dev/null
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
docker run --detach --name "$database" --network-alias database "${container_flags[@]}" \
    -e MYSQL_DATABASE=fixture -e MYSQL_USER=workflow -e MYSQL_PASSWORD=fixture-db-pass \
    -e "MYSQL_ROOT_PASSWORD=$mysql_root_password" "$mysql_image" \
    --log-bin-trust-function-creators=1 >/dev/null
wait_until 45 database_ready
[[ "$(sql 'select @@sync_binlog, @@innodb_flush_log_at_trx_commit')" == $'1\t1' ]]
docker run --rm --name "$task-schema" "${container_flags[@]}" "${db_flags[@]}" "${server_flags[@]}" "$server_image" schema-bootstrap >/dev/null
docker run --detach --name "$server" --network-alias server "${container_flags[@]}" "${db_flags[@]}" "${server_flags[@]}" "$server_image" >/dev/null
wait_until 30 ready

for worker_index in {1..6}; do
    worker="$task-worker-$worker_index"
    if [[ "$delay" == 20 ]]; then
        docker run --detach --name "$worker" "${php_flags[@]}" --cap-add NET_ADMIN --cap-add SETUID --cap-add SETGID \
            --entrypoint sh "$worker_image" -ec 'tc qdisc add dev eth0 root netem delay 20ms
                exec su-exec 1000:1000 php benchmark.php "$@"' \
            sh worker "bench-$worker_index" "$arm" >/dev/null
    else
        docker run --detach --name "$worker" "${php_flags[@]}" --user "$(id -u):$(id -g)" \
            --entrypoint php "$worker_image" benchmark.php worker "bench-$worker_index" "$arm" >/dev/null
    fi
done
for worker_index in {1..6}; do
    worker="$task-worker-$worker_index"
    wait_until 20 worker_ready "$worker"
    if [[ "$delay" == 20 ]]; then docker exec "$worker" tc qdisc show dev eth0 | grep -Eq 'netem .+ delay 20ms'; fi
done

workload batch warm 20
wait_until 50 count_completed warm 20
read -r statements_before commits_before < <(sql "$sql_totals")
server_before=$(cpu_us "$server")
mysql_before=$(cpu_us "$database")
workload "$shape" measured "$count"
wait_until 60 count_completed measured "$count"
server_after=$(cpu_us "$server")
mysql_after=$(cpu_us "$database")
read -r statements_after commits_after < <(sql "$sql_totals")

wall_us=$(sql "select timestampdiff(microsecond, min(created_at), max(closed_at))
    from workflow_runs where workflow_instance_id like 'measured-%'")
activities=$(sql "select count(*) from workflow_history_events h join workflow_runs r on r.id=h.workflow_run_id
    where r.workflow_instance_id like 'measured-%' and h.event_type='ActivityCompleted'")
[[ "$activities" == "$((5*count))" ]]
sql "select timestampdiff(microsecond, created_at, closed_at) from workflow_runs
    where workflow_instance_id like 'measured-%' order by created_at" > "$report.latencies"
sql "select json_unquote(json_extract(c.receipt, '$.activity_workflow_continuation_v2.answer.continuation.outcome')),
    coalesce(json_unquote(json_extract(c.receipt, '$.activity_workflow_continuation_v2.answer.continuation.reason')), '-'), count(*)
    from dw_task_completions c join workflow_tasks t on t.id=c.task_id join workflow_runs r on r.id=t.workflow_run_id
    where r.workflow_instance_id like 'measured-%' and json_contains_path(c.receipt, 'one', '$.activity_workflow_continuation_v2')
    group by 1, 2" > "$report.receipts"

if [[ "$arm" == on ]]; then
    [[ "$(awk '$1 == "accepted" {sum += $3} END {print sum + 0}' "$report.receipts")" -gt 0 ]]
else
    [[ ! -s "$report.receipts" ]]
fi
jq -cn --arg arm "$arm" --arg shape "$shape" --argjson delay_ms "$delay" --argjson workflows "$count" \
    --argjson wall_us "$wall_us" --argjson server_cpu_us "$((server_after-server_before))" --argjson mysql_cpu_us "$((mysql_after-mysql_before))" \
    --argjson statements "$((statements_after-statements_before))" --argjson commits "$((commits_after-commits_before))" \
    --argjson activity_completions "$activities" \
    '{$arm,$shape,$delay_ms,$workflows,$wall_us,$server_cpu_us,$mysql_cpu_us,$statements,$commits,$activity_completions}' | tee "$report.json"
