#!/usr/bin/env bash
# Driver side of the small-host benchmark (Server #325). Run it on a separate
# driver host in the target's private network, after the target side's `up`.
#
#   run-server-port-small-host.sh PROFILE OUTPUT_DIR TARGET_URL [--smoke]
#
# It finds the target's sustained capacity: after a warmup it offers each rate
# of the profile's ladder for one window, with enough probe processes and SDK
# workers that the driver is never the limit, and stops after a rate fails
# its bounds twice (the window and one confirmation). Then it repeats the
# lowest rate as a drift check, stops the SDK workers and holds idle long
# polls. Set DW_PORT_SOURCE_ROOT and DW_PORT_SDK_ROOT as the matched profile's
# v2 README describes. --smoke runs two short steps and one idle window to
# check the setup; it is never a measurement.
set -Eeuo pipefail

profile=$(realpath "$1")
out=$2
target=${3%/}
smoke=${4:-}
case "$smoke" in ""|--smoke) ;; *) echo "Unknown option $smoke." >&2; exit 2 ;; esac
[[ $target =~ ^https?://[^/]+$ ]] || { echo "TARGET_URL is the scheme, the target private address and port 8080, with no path." >&2; exit 2; }
: "${DW_PORT_SOURCE_ROOT:?Set the absolute source directory}"
: "${DW_PORT_SDK_ROOT:?Set the normally installed SDK directory}"
compose="$DW_PORT_SOURCE_ROOT/scripts/perf/small-host-driver.compose.yml"
# The read-only source mount needs the SDK mountpoint to exist (ignored by Git).
mkdir -p "$DW_PORT_SOURCE_ROOT/benchmarks/capacity/v1/bindings/php/vendor"
# Optional extra Compose file (local stand-in runs only) and project prefix.
compose_args=(-f "$compose")
[[ -n ${DW_SMALL_DRIVER_COMPOSE_OVERRIDE:-} ]] && compose_args+=(-f "$DW_SMALL_DRIVER_COMPOSE_OVERRIDE")
prefix=${DW_SMALL_PROJECT_PREFIX:-dw-small}
[[ ! -e $out ]] || { echo "Refusing to overwrite $out." >&2; exit 2; }
mkdir -p "$out"
out=$(realpath "$out")
j() { local filter=$1; jq -r "$filter" "$profile"; }
log() { printf '%s %s\n' "$(date -u +%FT%T.%3NZ)" "$*" | tee -a "$out/run.log" >&2; }

# Inputs must be the ones the profile names.
for pair in .workload.adapter:.workload.adapter_sha256 .workload.command:.workload.command_sha256 \
  .idle.command:.idle.command_sha256 .idle.outcome_classifier:.idle.outcome_classifier_sha256; do
  file=$(j "${pair%%:*}") want=$(j "${pair##*:}")
  got=$(sha256sum "$DW_PORT_SOURCE_ROOT/$file" | cut -d ' ' -f1)
  [[ $got == "$want" ]] || { echo "$file does not match the profile." >&2; exit 1; }
done

# The target must answer, and say which server it is. Checks run from a
# client container, so they take the same network path as the probes.
client_image=$(j .artifacts.client_runtime_image)
ccurl() { timeout 60 docker run --rm --entrypoint curl "$client_image" "$@"; }
ccurl -fsS --max-time 5 "$target/api/ready" > /dev/null || { echo "$target/api/ready did not answer." >&2; exit 1; }
ccurl -fsS --max-time 10 -H 'Authorization: Bearer port-test-token' -H 'X-Durable-Workflow-Control-Plane-Version: 2' \
  "$target/api/cluster/info" > "$out/target-cluster-info.json" || { echo "cluster info did not answer." >&2; exit 1; }

if [[ $smoke == --smoke ]]; then
  warmup=5 offer=10 drain=60 rates=($(j '.ladder.rates_per_second[0:2] | map(tostring) | join(" ")')) idle_counts=6
else
  warmup=$(j .ladder.warmup_seconds) offer=$(j .ladder.offer_seconds) drain=$(j .ladder.drain_limit_seconds)
  rates=($(j '.ladder.rates_per_second | map(tostring) | join(" ")')) idle_counts=$(j '.idle.counts | join(" ")')
fi
idle_repeats=$(j .idle.repeats)
describe=$(j .ladder.describe_interval_ms)
per_probe=$(j .ladder.max_rate_per_probe)
workers_per_probe=$(j .ladder.sdk_workers_per_probe)
min_workers=$(j .ladder.min_sdk_workers)
p95_bound=$(j .ladder.bounds.latency_p95_seconds)
in_window=$(j .ladder.bounds.min_in_window_completion_fraction)
idle_timeout=$(j .idle.requested_timeout_seconds)
idle_kind=$(j .idle.kind)

project="$prefix-driver-$(date -u +%Y%m%dt%H%M%S)"
export DW_SMALL_TARGET_URL=$target
dc() { docker compose -p "$project" "${compose_args[@]}" "$@"; }

ntp_state() {
  printf 'unix_seconds=%s\n' "$(date -u +%s.%N)"
  timedatectl show -p NTPSynchronized -p NTP 2>/dev/null || echo 'timedatectl unavailable'
  chronyc tracking 2>/dev/null || true
  timedatectl timesync-status 2>/dev/null || true
}
ntp_state > "$out/clock-start.txt"
cgroup_driver=$(docker info --format '{{.CgroupDriver}}')
docker_socket=""
case "${DOCKER_HOST:-unix:///var/run/docker.sock}" in
  unix://*) docker_socket=${DOCKER_HOST:-unix:///var/run/docker.sock}; docker_socket=${docker_socket#unix://} ;;
  *) docker_socket="" ;;
esac
jq -n --arg project "$project" --arg profile "$profile" --arg profile_sha "$(sha256sum "$profile" | cut -d ' ' -f1)" \
  --arg source "$(git -C "$DW_PORT_SOURCE_ROOT" rev-parse HEAD 2>/dev/null || echo unknown)" --arg target "$target" \
  --arg smoke "$smoke" --arg kernel "$(uname -r)" --arg cpus "$(nproc)" \
  --arg memory "$(awk '/MemTotal/ {print $2 * 1024}' /proc/meminfo)" \
  --arg docker "$(docker version --format '{{.Server.Version}}')" --arg cgroup "$cgroup_driver" \
  --arg cpu_model "$(awk -F': ' '/model name/ {print $2; exit}' /proc/cpuinfo)" --arg plan "${DW_SMALL_PROVIDER_PLAN:-}" \
  --slurpfile info "$out/target-cluster-info.json" \
  '{role:"driver",compose_project:$project,profile:$profile,profile_sha256:$profile_sha,source_revision:$source,
    target_url:$target,target_server:($info[0] | {version, server_id, implementation: (.implementation // .server // null)}),
    smoke:($smoke=="--smoke"),provider_plan:$plan,machine:{kernel:$kernel,logical_cpus:($cpus|tonumber),
    physical_memory_bytes:($memory|tonumber),cpu_model:$cpu_model,docker:$docker,cgroup_driver:$cgroup}}' > "$out/identity.json"

samplers=()
cleanup() {
  status=$?
  for pid in "${samplers[@]}"; do kill "$pid" 2>/dev/null || true; done
  docker logs "$project-health" > "$out/health.log" 2>&1 || true
  docker rm -f "$project-health" >/dev/null 2>&1 || true
  dc ps -a --format json > "$out/containers-final.jsonl" 2>/dev/null || true
  dc down --volumes --remove-orphans >> "$out/run.log" 2>&1 || true
  ntp_state > "$out/clock-end.txt"
  log "finished with status $status"
  exit "$status"
}
trap cleanup EXIT

cgroup_dir() {
  local id=$1
  if [[ $cgroup_driver == systemd ]]; then echo "/sys/fs/cgroup/system.slice/docker-$id.scope"
  else echo "/sys/fs/cgroup/docker/$id"; fi
}

# Driver-side load: host CPU and every client container, so a result can show
# the driver was not the limit.
sample() {
  local pass=0
  declare -A names=()
  while :; do
    if (( pass % 5 == 0 )); then
      names=()
      while read -r id name; do names[$id]=$name; done < <(docker ps --no-trunc \
        --filter "label=com.docker.compose.project=$project" \
        --format '{{.ID}} {{.Label "com.docker.compose.service"}}')
    fi
    pass=$((pass + 1))
    now=$(date -u +%s.%N)
    read -r _ user nice system idle iowait irq softirq steal _ < /proc/stat
    printf '%s\thost\t%s\t%s\t-\n' "$now" "$((user + nice + system + idle + iowait + irq + softirq + steal))" "$idle"
    for id in "${!names[@]}"; do
      dir=$(cgroup_dir "$id")
      if [[ -r $dir/cpu.stat ]]; then
        usage=$(awk '/^usage_usec/ {print $2}' "$dir/cpu.stat" 2>/dev/null) || continue
        memory=$(cat "$dir/memory.current" 2>/dev/null) || continue
      elif [[ -n $docker_socket ]]; then
        stats=$(curl -s --max-time 1 --unix-socket "$docker_socket" \
          "http://localhost/containers/$id/stats?stream=false&one-shot=true" 2>/dev/null) || continue
        read -r usage memory < <(jq -r '[((.cpu_stats.cpu_usage.total_usage // empty) / 1000 | floor),
          (.memory_stats.usage // empty)] | @tsv' <<<"$stats" 2>/dev/null) || continue
        [[ -n ${usage:-} && -n ${memory:-} ]] || continue
      else
        continue
      fi
      printf '%s\t%s\t%s\t%s\t%s\n' "$now" "${names[$id]}" "$usage" "$memory" "$id"
    done
    sleep 1
  done >> "$out/driver-samples.tsv"
}

mark() { local name=$1 edge=$2; printf '%s\t%s\t%s\n' "$(date -u +%s.%N)" "$name" "$edge" >> "$out/windows.tsv"; }

# Probes and workers for one offered rate: each probe offers at most
# max_rate_per_probe, and every probe brings sdk_workers_per_probe workers.
probes_for() { local rate=$1; awk -v r="$rate" -v m="$per_probe" 'BEGIN {p = int(r / m); if (p * m < r) p++; if (p < 1) p = 1; print p}'; }
workers_for() { local p=$1 w; w=$(( p * workers_per_probe )); (( w < min_workers )) && w=$min_workers; echo "$w"; }

# One window: run every probe concurrently, each at its share of the rate,
# then judge the window against the profile's bounds.
step() {
  local name=$1 rate=$2 seconds=$3 probes workers share started k pids=() verdict
  probes=$(probes_for "$rate")
  workers=$(workers_for "$probes")
  share=$(awk -v r="$rate" -v p="$probes" 'BEGIN {printf "%.4f", r / p}')
  dc up -d --no-recreate --scale sdk-worker="$workers" sdk-worker >> "$out/run.log" 2>&1
  started=$(date -u +%s)
  mark "$name" start
  for k in $(seq 1 "$probes"); do
    ( set +e
      timeout $((seconds + drain + 240)) docker compose -p "$project" "${compose_args[@]}" run --rm -T --no-deps probe \
        scripts/perf/standard-workflow-offered.php "$share" "$seconds" "$drain" "$describe" \
        > "$out/$name-probe-$k.json" 2> "$out/$name-probe-$k.stderr"
      echo $? > "$out/$name-probe-$k.exit" ) &
    pids+=($!)
  done
  wait "${pids[@]}" || true
  mark "$name" end
  # Pass only if every probe exited 0 (no errors, skipped or late offers,
  # pending work or poll errors), enough work closed inside the window, and
  # the slowest probe's p95 is within the bound.
  verdict=$(cat "$out/$name"-probe-*.json 2>/dev/null | jq -rs --argjson bound "$p95_bound" --argjson frac "$in_window" \
    --argjson expected "$probes" --arg exits "$(cat "$out/$name"-probe-*.exit | tr '\n' ' ')" '
    (map(.planned_starts) | add // 0) as $planned
    | (map(.closed_during_offer_window) | add // 0) as $closed
    | (map(.latency_p95_seconds // 1e9) | max // 1e9) as $p95
    | ($exits | split(" ") | map(select(. != "")) | all(. == "0")) as $clean
    | [ (if length != $expected then "missing probe results" else empty end),
        (if $clean | not then "a probe reported errors, late or skipped offers, or pending work" else empty end),
        (if $planned > 0 and ($closed / $planned) < $frac then "in-window completion \($closed)/\($planned)" else empty end),
        (if $p95 > $bound then "p95 \($p95)s over \($bound)s" else empty end) ] as $reasons
    | "\(if ($reasons | length) == 0 then "pass" else "fail" end)\t\($planned)\t\($closed)\t\($p95)\t\($reasons | join("; "))"')
  printf '%s\t%s\t%s\t%s\t%s\n' "$name" "$rate" "$probes" "$workers" "$verdict" >> "$out/ladder.tsv"
  log "$name rate=$rate probes=$probes workers=$workers: $(cut -f1,5 <<<"$verdict" | tr '\t' ' ')"
  local remaining=$(( started + seconds - $(date -u +%s) ))
  (( remaining > 0 )) && sleep "$remaining"
  [[ ${verdict%%$'\t'*} == pass ]]
}

log "driving $target from $project"
printf 'name\trate_per_second\tprobes\tsdk_workers\tverdict\tplanned\tclosed_in_window\tmax_probe_p95_seconds\treasons\n' > "$out/ladder.tsv"
sample & samplers+=($!)
image=$(dc config --format json | jq -r '.services.probe.image')
docker run -d --name "$project-health" --label "com.docker.compose.project=$project-observer" \
  --user 1000:1000 --cpus 0.1 --memory 64m --entrypoint sh "$image" -c \
  "while :; do for path in health ready; do printf '%s %s ' \"\$(date -u +%s.%N)\" \$path; \
   curl -s -o /dev/null -w '%{http_code} %{time_total}\n' --max-time 2 $target/api/\$path || echo 'error -'; done; sleep 1; done" \
  > /dev/null 2>> "$out/run.log"

step setup "${rates[0]}" 5 || log "setup window failed; the ladder continues so its evidence is kept"
step warmup "${rates[0]}" "$warmup" || true
sustained=""
for rate in "${rates[@]}"; do
  if step "rate-$rate" "$rate" "$offer"; then sustained=$rate; continue; fi
  if step "rate-$rate-confirm" "$rate" "$offer"; then sustained=$rate; continue; fi
  log "rate $rate failed twice; the ladder stops"
  break
done
step low-after "${rates[0]}" "$offer" || true
jq -n --arg sustained "$sustained" --argjson bound "$p95_bound" --argjson frac "$in_window" \
  '{sustained_rate_per_second: (if $sustained == "" then null else ($sustained|tonumber) end),
    bounds: {latency_p95_seconds: $bound, min_in_window_completion_fraction: $frac}}' > "$out/capacity.json"
log "sustained capacity: ${sustained:-none} workflows/s"

mark sdk-worker-stop start
dc stop -t 30 sdk-worker >> "$out/run.log" 2>&1 || true
mark sdk-worker-stop end

# Idle long polls with no work and no SDK worker.
for count in $idle_counts; do
  for repeat in $(seq 1 "$idle_repeats"); do
    sleep 10
    name="idle-$count-$repeat"
    mark "$name" start
    if timeout $((idle_timeout + 120)) docker compose -p "$project" "${compose_args[@]}" run --rm -T --no-deps probe \
      scripts/perf/idle-poll-probe.php "$count" "$idle_timeout" "$idle_kind" > "$out/$name.json" 2> "$out/$name.stderr"; then
      log "$name passed"
    else
      log "$name failed with status $?"
    fi
    mark "$name" end
  done
done
