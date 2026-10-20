#!/usr/bin/env bash
# Multi-node native kill/restart qualification on one database family.
#
#   run-server-port-multinode.sh OUTPUT_DIR mysql|postgres|sqlite [--smoke]
#
# Three native nodes share one database behind HAProxy. Two published-SDK
# workers and the offered workload reach them only through the balancer.
# While work is offered, one node at a time is killed with SIGKILL and
# restarted, and one SDK worker is paused for longer than a workflow task
# lease, so its late completions are stale. Every workflow must complete
# once with its original output and an ordered history, and the database
# audit must find no duplicate committed outcome.
#
# Set DW_PORT_SOURCE_ROOT, DW_PORT_SDK_ROOT and DW_PORT_NATIVE_BINARY as the
# server-port v2 README describes. Run on a host with no other workload.
set -Eeuo pipefail

out=$1
db=$2
mode=${3:-}
case "$db" in mysql|postgres|sqlite) ;; *) echo "Choose mysql, postgres or sqlite." >&2; exit 2 ;; esac
case "$mode" in ""|--smoke) ;; *) echo "Unknown option $mode." >&2; exit 2 ;; esac
: "${DW_PORT_SOURCE_ROOT:?Set the absolute source directory}"
: "${DW_PORT_SDK_ROOT:?Set the normally installed SDK directory}"
: "${DW_PORT_NATIVE_BINARY:?Set the absolute native binary path}"
[[ ! -e $out ]] || { echo "Refusing to overwrite $out." >&2; exit 2; }
mkdir -p "$out"
out=$(realpath "$out")
compose="$DW_PORT_SOURCE_ROOT/scripts/perf/server-port-multinode.compose.yml"
# The SDK vendor directory is mounted over this ignored mount point.
mkdir -p "$DW_PORT_SOURCE_ROOT/benchmarks/capacity/v1/bindings/php/vendor"
log() { printf '%s %s\n' "$(date -u +%FT%T.%3NZ)" "$*" | tee -a "$out/run.log" >&2; }

if [[ $mode == --smoke ]]; then
  rate=0.5 offer=20 drain=120 windows=1 kill_every=8 down=3 pause_every=30
else
  # The drain outlasts the five-minute activity lease: an activity whose
  # completion a killed node dropped is redelivered only when that lease expires.
  rate=${DW_MN_RATE:-0.5} offer=${DW_MN_OFFER_SECONDS:-180} drain=${DW_MN_DRAIN_SECONDS:-420}
  windows=${DW_MN_WINDOWS:-3} kill_every=${DW_MN_KILL_EVERY:-15} down=${DW_MN_DOWN_SECONDS:-4}
  pause_every=${DW_MN_PAUSE_EVERY:-90}
fi
lease=${DW_MN_WORKFLOW_TASK_TIMEOUT:-60}
pause=$((lease + 10))
export DW_MN_WORKFLOW_TASK_TIMEOUT=$lease
export DW_MN_HAPROXY_DIGEST=${DW_MN_HAPROXY_DIGEST:-094401c230b9bf01a6a4dc96e455795e261483f3bd2f42f19d0e6f259bd43837}
export DW_MN_STATE_DIR="$out/state"
mkdir -p "$DW_MN_STATE_DIR"
chmod 0777 "$DW_MN_STATE_DIR"
case "$db" in
  mysql) export DW_MN_DB_CONNECTION=mysql DW_MN_DB_HOST=mysql DW_MN_DB_DATABASE=durable_workflow \
    DW_MN_DB_USERNAME=workflow DW_MN_DB_PASSWORD=multinode-mysql; database=(mysql) ;;
  postgres) export DW_MN_DB_CONNECTION=pgsql DW_MN_DB_HOST=postgres DW_MN_DB_DATABASE=durable_workflow \
    DW_MN_DB_USERNAME=workflow DW_MN_DB_PASSWORD=multinode-postgres; database=(postgres) ;;
  sqlite) export DW_MN_DB_CONNECTION=sqlite DW_MN_DB_DATABASE=/state/native.sqlite; database=() ;;
esac

project="dw-mn-$db-$(date -u +%Y%m%dt%H%M%S)"
dc() { docker compose -p "$project" -f "$compose" --profile "$db" "$@"; }
container() { dc --profile clients ps -a -q "$1"; }
nodes=(native-1 native-2 native-3)

stop_file="$out/.stop"
loops=()
cleanup() {
  status=$?
  touch "$stop_file"
  for pid in "${loops[@]}"; do kill "$pid" 2>/dev/null || true; wait "$pid" 2>/dev/null || true; done
  docker unpause "$(container sdk-worker-1)" >/dev/null 2>&1 || true
  for node in "${nodes[@]}"; do docker logs "$(container "$node")" > "$out/$node.log" 2>&1 || true; done
  docker logs "$(container lb)" > "$out/lb.log" 2>&1 || true
  for worker in sdk-worker-1 sdk-worker-2; do docker logs "$(container "$worker")" > "$out/$worker.log" 2>&1 || true; done
  dc --profile clients ps -a --format json > "$out/containers-final.jsonl" 2>/dev/null || true
  dc --profile clients down --volumes --remove-orphans >> "$out/run.log" 2>&1 || true
  rm -rf "$DW_MN_STATE_DIR"
  log "finished with status $status"
  exit "$status"
}
trap cleanup EXIT

jq -n --arg db "$db" --arg project "$project" --arg source "$(git -C "$DW_PORT_SOURCE_ROOT" rev-parse HEAD)" \
  --arg dirty "$(git -C "$DW_PORT_SOURCE_ROOT" status --porcelain --untracked-files=no | wc -l)" \
  --arg binary "$(sha256sum "$DW_PORT_NATIVE_BINARY" | cut -d ' ' -f1)" --arg smoke "$mode" \
  --arg kernel "$(uname -r)" --arg cpus "$(nproc)" \
  --arg cpu_model "$(awk -F': ' '/model name/ {print $2; exit}' /proc/cpuinfo)" \
  --argjson schedule "$(jq -n --arg rate "$rate" --arg offer "$offer" --arg drain "$drain" --arg windows "$windows" \
    --arg kill_every "$kill_every" --arg down "$down" --arg pause_every "$pause_every" --arg pause "$pause" --arg lease "$lease" \
    '{rate:($rate|tonumber),offer_seconds:($offer|tonumber),drain_seconds:($drain|tonumber),windows:($windows|tonumber),
      node_kill_every_seconds:($kill_every|tonumber),node_down_seconds:($down|tonumber),
      worker_pause_every_seconds:($pause_every|tonumber),worker_pause_seconds:($pause|tonumber),
      workflow_task_lease_seconds:($lease|tonumber)}')" \
  '{database:$db,compose_project:$project,source_revision:$source,source_modified_files:($dirty|tonumber),native_binary_sha256:$binary,smoke:($smoke=="--smoke"),
    schedule:$schedule,machine:{kernel:$kernel,logical_cpus:($cpus|tonumber),cpu_model:$cpu_model}}' > "$out/identity.json"

log "starting $project"
if (( ${#database[@]} )); then dc up -d --wait "${database[@]}" >> "$out/run.log" 2>&1; fi
# All three nodes start at once against the empty database.
dc up -d --wait "${nodes[@]}" >> "$out/run.log" 2>&1
dc up -d lb >> "$out/run.log" 2>&1
dc --profile clients up -d sdk-worker-1 sdk-worker-2 >> "$out/run.log" 2>&1

# One node at a time is killed and restarted.
kill_nodes() {
  local i=0 node id
  while [[ ! -e $stop_file ]]; do
    sleep "$kill_every"
    [[ -e $stop_file ]] && break
    node=${nodes[$((i % 3))]}
    id=$(container "$node")
    printf '%s\tkill\t%s\n' "$(date -u +%s.%N)" "$node" >> "$out/chaos.tsv"
    docker kill -s KILL "$id" >/dev/null 2>&1 || true
    sleep "$down"
    docker start "$id" >/dev/null 2>&1 || true
    printf '%s\tstart\t%s\n' "$(date -u +%s.%N)" "$node" >> "$out/chaos.tsv"
    i=$((i + 1))
  done
}
# One SDK worker is frozen for longer than a workflow task lease, then
# resumes with the tasks it held: its completions are stale by then.
pause_worker() {
  local id
  while [[ ! -e $stop_file ]]; do
    sleep "$pause_every"
    [[ -e $stop_file ]] && break
    id=$(container sdk-worker-1)
    printf '%s\tpause\tsdk-worker-1\n' "$(date -u +%s.%N)" >> "$out/chaos.tsv"
    docker pause "$id" >/dev/null 2>&1 || true
    sleep "$pause"
    docker unpause "$id" >/dev/null 2>&1 || true
    printf '%s\tunpause\tsdk-worker-1\n' "$(date -u +%s.%N)" >> "$out/chaos.tsv"
  done
}
kill_nodes & loops+=($!)
pause_worker & loops+=($!)

failed=0
for window in $(seq 1 "$windows"); do
  name="window-$window"
  log "$name: $rate/s for ${offer}s"
  if dc --profile clients run --rm --no-deps probe scripts/perf/multinode-workload.php "$rate" "$offer" "$drain" \
    "mn-$db-w$window" > "$out/$name.json" 2> "$out/$name.stderr"; then
    log "$name passed"
  else
    log "$name failed"
    failed=1
  fi
done

touch "$stop_file"
for pid in "${loops[@]}"; do wait "$pid" 2>/dev/null || true; done
loops=()
for node in "${nodes[@]}"; do docker start "$(container "$node")" >/dev/null 2>&1 || true; done
docker unpause "$(container sdk-worker-1)" >/dev/null 2>&1 || true

# The balancer's request log shows what the chaos did: stale completions the
# servers refused, and requests a killed node never answered.
docker logs "$(container lb)" > "$out/lb.log" 2>&1 || true
count() { grep -E "$1" "$out/lb.log" | grep -cE "/[0-9-]+ $2 " || true; }
{
  printf 'requests\t%s\n' "$(grep -cE '"[A-Z]+ /api/' "$out/lb.log" || true)"
  printf 'workflow_task_completions_409\t%s\n' "$(count '/api/worker/workflow-tasks/[^ ]+/complete HTTP' 409)"
  printf 'activity_task_completions_409\t%s\n' "$(count '/api/worker/activity-tasks/[^ ]+/complete HTTP' 409)"
  printf 'responses_5xx\t%s\n' "$(count '"[A-Z]+ /api/' '5[0-9][0-9]')"
} > "$out/lb-summary.tsv"

audit_sql="SELECT 'runs', status, COUNT(*) FROM workflow_runs GROUP BY status;
SELECT 'tasks', task_type, status, COUNT(*) FROM workflow_tasks GROUP BY task_type, status;
SELECT 'attempts', status, COUNT(*) FROM activity_attempts GROUP BY status;
SELECT 'duplicate_workflow_completions', COUNT(*) FROM (SELECT workflow_run_id FROM workflow_history_events WHERE event_type='WorkflowCompleted' GROUP BY workflow_run_id HAVING COUNT(*) > 1) d;
SELECT 'duplicate_activity_completions', COUNT(*) FROM (SELECT activity_execution_id FROM activity_attempts WHERE status='completed' GROUP BY activity_execution_id HAVING COUNT(*) > 1) d;
SELECT 'duplicate_history_sequences', COUNT(*) FROM (SELECT workflow_run_id, sequence FROM workflow_history_events GROUP BY workflow_run_id, sequence HAVING COUNT(*) > 1) d;"
case "$db" in
  mysql) dc exec -T mysql sh -c 'MYSQL_PWD=$MYSQL_PASSWORD exec mysql -u"$MYSQL_USER" "$MYSQL_DATABASE" --batch --skip-column-names -e "$1"' audit "$audit_sql" ;;
  postgres) dc exec -T postgres psql -U workflow -d durable_workflow -At -F $'\t' -c "$audit_sql" ;;
  sqlite) dc --profile clients run --rm --no-deps -v "$DW_MN_STATE_DIR:/state" -e AUDIT_SQL="$audit_sql" probe -r '
    $pdo = new PDO("sqlite:/state/native.sqlite");
    foreach (array_filter(array_map("trim", explode(";", getenv("AUDIT_SQL")))) as $sql) {
        foreach ($pdo->query($sql, PDO::FETCH_NUM) as $row) { echo implode("\t", $row), "\n"; }
    }' ;;
esac > "$out/database-audit.tsv" 2>> "$out/run.log"
for kind in workflow activity; do
  awk -F'\t' -v k="duplicate_${kind}_completions" '$1==k && $2!=0 {bad=1} END {exit bad}' "$out/database-audit.tsv" || failed=1
done
awk -F'\t' '$1=="duplicate_history_sequences" && $2!=0 {bad=1} END {exit bad}' "$out/database-audit.tsv" || failed=1
awk -F'\t' '$1=="runs" && $2!="completed" {bad=1} END {exit bad}' "$out/database-audit.tsv" || failed=1
for node in "${nodes[@]}"; do docker logs "$(container "$node")" 2>&1 | grep -c "panicked" > "$out/$node-panics.txt" || true; done
for id in $(dc --profile clients ps -a -q); do
  docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}} restarts={{.RestartCount}} oom={{.State.OOMKilled}} exit={{.State.ExitCode}}' "$id"
done > "$out/container-states.txt"
log "audit: $(tr '\n' ' ' < "$out/database-audit.tsv")"
exit "$failed"
