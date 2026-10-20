#!/usr/bin/env bash
# Run one implementation of a server-port matched development comparison on a
# dedicated host, following the profile's workload, windows and idle checks.
#
#   run-server-port-matched.sh PROFILE OUTPUT_DIR php|native [--smoke]
#
# Set DW_PORT_SOURCE_ROOT, DW_PORT_SDK_ROOT and DW_PORT_NATIVE_BINARY as the
# v2 README describes. Run php and native sequentially, each into its own
# output directory, never together. --smoke shortens every window to five
# seconds and one repetition to check the setup; it is never a measurement.
# The output keeps raw probe results, one-second cgroup samples, health
# observations, window markers, machine facts and the final database audit.
set -Eeuo pipefail

profile=$(realpath "$1")
out=$2
impl=$3
smoke=${4:-}
test "$impl" = php || test "$impl" = native || { echo "Choose php or native." >&2; exit 2; }
: "${DW_PORT_SOURCE_ROOT:?Set the absolute source directory}"
: "${DW_PORT_SDK_ROOT:?Set the normally installed SDK directory}"
: "${DW_PORT_NATIVE_BINARY:?Set the absolute optimized binary path}"
compose="$DW_PORT_SOURCE_ROOT/scripts/perf/server-port-matched.compose.yml"
test ! -e "$out" || { echo "Refusing to overwrite $out." >&2; exit 2; }
mkdir -p "$out"
out=$(realpath "$out")
j() { jq -r "$1" "$profile"; }
log() { printf '%s %s\n' "$(date -u +%FT%T.%3NZ)" "$*" | tee -a "$out/run.log" >&2; }

# Inputs must be the ones the profile names.
for pair in .workload.adapter:.workload.adapter_sha256 .workload.command:.workload.command_sha256 \
  .idle.command:.idle.command_sha256 .idle.outcome_classifier:.idle.outcome_classifier_sha256; do
  file=$(j "${pair%%:*}") want=$(j "${pair##*:}")
  got=$(sha256sum "$DW_PORT_SOURCE_ROOT/$file" | cut -d ' ' -f1)
  test "$got" = "$want" || { echo "$file does not match the profile." >&2; exit 1; }
done
binary_sha=$(sha256sum "$DW_PORT_NATIVE_BINARY" | cut -d ' ' -f1)
want_binary=$(j '.artifacts.native_binary_sha256 // empty')
if test -n "$want_binary" && test "$binary_sha" != "$want_binary"; then
  echo "The native binary does not match the profile." >&2
  exit 1
fi

if test "$smoke" = --smoke; then
  warmup=5 offer=5 drain=60 repeats=1 idle_counts=6 idle_repeats=1
else
  warmup=$(j .workload.warmup_seconds) offer=$(j .workload.offer_seconds) drain=$(j .workload.drain_limit_seconds)
  repeats=$(j .workload.repeats) idle_counts=$(j '.idle.counts | join(" ")') idle_repeats=$(j .idle.repeats)
fi
describe=$(j .workload.describe_interval_ms)
rates=($(j '.workload.offered_rates_per_second | map(tostring) | join(" ")'))
idle_timeout=$(j .idle.requested_timeout_seconds)
idle_kind=$(j .idle.kind)

project="dw-port-$impl-$(date -u +%Y%m%dt%H%M%S)"
case "$impl" in
  php) profiles=(--profile php) services=(mysql redis php-bootstrap php-server php-worker php-scheduler)
       export DW_PORT_RUNTIME_URL=http://php-server:8080 ;;
  native) profiles=(--profile native) services=(mysql native-server)
          export DW_PORT_RUNTIME_URL=http://native-server:8080 ;;
esac
dc() { docker compose -p "$project" -f "$compose" "$@"; }
probe() { dc run --rm --no-deps probe "$@"; }

cgroup_driver=$(docker info --format '{{.CgroupDriver}}')
jq -n --arg impl "$impl" --arg project "$project" --arg profile "$profile" \
  --arg profile_sha "$(sha256sum "$profile" | cut -d ' ' -f1)" \
  --arg source "$(git -C "$DW_PORT_SOURCE_ROOT" rev-parse HEAD)" --arg binary "$binary_sha" \
  --arg smoke "$smoke" --arg kernel "$(uname -r)" --arg cpus "$(nproc)" \
  --arg memory "$(awk '/MemTotal/ {print $2 * 1024}' /proc/meminfo)" \
  --arg docker "$(docker version --format '{{.Server.Version}}')" --arg cgroup "$cgroup_driver" \
  --arg storage "$(docker info --format '{{.Driver}}')" \
  --arg filesystem "$(df -T "$(docker info --format '{{.DockerRootDir}}')" | awk 'NR==2 {print $2}')" \
  --arg cpu_model "$(awk -F': ' '/model name/ {print $2; exit}' /proc/cpuinfo)" \
  '{implementation:$impl,compose_project:$project,profile:$profile,profile_sha256:$profile_sha,source_revision:$source,
    native_binary_sha256:$binary,smoke:($smoke=="--smoke"),machine:{kernel:$kernel,logical_cpus:($cpus|tonumber),
    physical_memory_bytes:($memory|tonumber),cpu_model:$cpu_model,docker:$docker,cgroup_driver:$cgroup,
    docker_storage_driver:$storage,docker_root_filesystem:$filesystem}}' > "$out/identity.json"

samplers=()
cleanup() {
  status=$?
  for pid in "${samplers[@]}"; do kill "$pid" 2>/dev/null || true; done
  docker rm -f "$project-health" >/dev/null 2>&1 || true
  dc "${profiles[@]}" --profile clients ps -a --format json > "$out/containers-final.jsonl" 2>/dev/null || true
  dc "${profiles[@]}" --profile clients down --volumes --remove-orphans >> "$out/run.log" 2>&1 || true
  log "finished with status $status"
  exit "$status"
}
trap cleanup EXIT

# Host contention and swap, before and after every window.
host_state() {
  printf '%s\t%s\t%s\t%s\t%s\n' "$(date -u +%s.%N)" "$1" "$(cut -d ' ' -f1-3 /proc/loadavg)" \
    "$(awk '/^pswpin|^pswpout/ {printf "%s=%s ", $1, $2}' /proc/vmstat)" \
    "$(docker ps -q | wc -l)" >> "$out/host-state.tsv"
}

cgroup_dir() {
  local id=$1
  if test "$cgroup_driver" = systemd; then echo "/sys/fs/cgroup/system.slice/docker-$id.scope"
  else echo "/sys/fs/cgroup/docker/$id"; fi
}

# One-second cumulative CPU and current memory for every project container.
sample() {
  while :; do
    now=$(date -u +%s.%N)
    for id in $(docker ps -q --no-trunc --filter "label=com.docker.compose.project=$project"); do
      dir=$(cgroup_dir "$id")
      test -r "$dir/cpu.stat" || continue
      name=$(docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}}' "$id" 2>/dev/null || echo unknown)
      usage=$(awk '/^usage_usec/ {print $2}' "$dir/cpu.stat")
      memory=$(cat "$dir/memory.current")
      oom=$(awk '/^oom_kill/ {print $2}' "$dir/memory.events")
      printf '%s\t%s\t%s\t%s\t%s\n' "$now" "$name" "$usage" "$memory" "$oom"
    done >> "$out/samples.tsv"
    sleep 1
  done
}

mark() { printf '%s\t%s\t%s\n' "$(date -u +%s.%N)" "$1" "$2" >> "$out/windows.tsv"; }

# One offer window. The runtime and observers stay up through its declared end.
window() {
  local name=$1 rate=$2 seconds=$3 started
  started=$(date -u +%s)
  host_state "$name:start"
  mark "$name" start
  if probe scripts/perf/standard-workflow-offered.php "$rate" "$seconds" "$drain" "$describe" \
    > "$out/$name.json" 2> "$out/$name.stderr"; then
    log "$name passed"
  else
    log "$name failed with status $?"
  fi
  remaining=$(( started + seconds - $(date -u +%s) ))
  if test "$remaining" -gt 0; then sleep "$remaining"; fi
  mark "$name" end
  host_state "$name:end"
}

log "starting $impl in $project"
host_state start
dc "${profiles[@]}" up -d --wait "${services[@]}" >> "$out/run.log" 2>&1
sample & samplers+=($!)
network="${project}_default"
image=$(dc --profile clients config --format json | jq -r '.services.probe.image')
test "$image" != null || { log "no probe image in the Compose configuration"; exit 1; }
docker run -d --name "$project-health" --label "com.docker.compose.project=$project-observer" \
  --network "$network" --user 1000:1000 --cpus 0.1 --memory 64m --entrypoint sh "$image" -c \
  "while :; do for path in health ready; do printf '%s %s ' \"\$(date -u +%s.%N)\" \$path; \
   curl -s -o /dev/null -w '%{http_code} %{time_total}\n' --max-time 2 $DW_PORT_RUNTIME_URL/api/\$path || echo 'error -'; done; sleep 1; done" \
  > /dev/null 2>> "$out/run.log"
dc --profile clients up -d sdk-worker >> "$out/run.log" 2>&1

window setup "${rates[0]}" 5
window warmup "${rates[0]}" "$warmup"
low=${rates[0]}
phases=("low-before:$low")
for rate in "${rates[@]:1}"; do phases+=("rate-$rate:$rate"); done
phases+=("low-after:$low")
for phase in "${phases[@]}"; do
  for repeat in $(seq 1 "$repeats"); do
    window "${phase%%:*}-$repeat" "${phase##*:}" "$offer"
  done
done

# Idle long polls with no work and no SDK worker, as the profile requires.
mark sdk-worker-stop start
dc --profile clients stop -t 30 sdk-worker >> "$out/run.log" 2>&1
docker inspect --format '{{.State.ExitCode}}' "$(dc --profile clients ps -a -q sdk-worker)" > "$out/sdk-worker-exit.txt"
mark sdk-worker-stop end
for count in $idle_counts; do
  for repeat in $(seq 1 "$idle_repeats"); do
    sleep 10
    name="idle-$count-$repeat"
    mark "$name" start
    if probe scripts/perf/idle-poll-probe.php "$count" "$idle_timeout" "$idle_kind" > "$out/$name.json" 2> "$out/$name.stderr"; then
      log "$name passed"
    else
      log "$name failed with status $?"
    fi
    mark "$name" end
  done
done

docker logs "$project-health" > "$out/health.log" 2>&1 || true
dc exec -T mysql mysql -uworkflow -pport-mysql durable_workflow --batch -e "
  SELECT 'runs', status, COUNT(*) FROM workflow_runs GROUP BY status;
  SELECT 'tasks', task_type, status, COUNT(*) FROM workflow_tasks GROUP BY task_type, status;
  SELECT 'activities', status, COUNT(*) FROM activity_executions GROUP BY status;
  SELECT 'registrations', COUNT(*) FROM workflow_worker_registrations;" > "$out/database-audit.tsv" 2>> "$out/run.log"
for id in $(dc "${profiles[@]}" --profile clients ps -a -q); do
  docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}} restarts={{.RestartCount}} oom={{.State.OOMKilled}} exit={{.State.ExitCode}}' "$id"
done > "$out/container-states.txt"
host_state end
