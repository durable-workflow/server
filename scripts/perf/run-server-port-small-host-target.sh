#!/usr/bin/env bash
# Target side of the small-host benchmark (Server #325). Run it on the
# plan-sized host being measured, which runs only the server and its database.
#
#   run-server-port-small-host-target.sh up   PROFILE OUTPUT_DIR native-mysql|native-sqlite|php-mysql
#   run-server-port-small-host-target.sh down OUTPUT_DIR
#
# `up` records the machine and clock, starts the mode's Compose project with no
# container limits (the plan is the limit), waits for readiness on
# DW_SMALL_TARGET_BIND:8080 and starts a one-second host sampler. Then run the
# driver from the driver host. `down` stops the sampler, records the database
# audit, container restarts and OOM kills, and removes the project and its
# volumes. Set DW_SMALL_TARGET_BIND to this host's private (VPC) address.
set -Eeuo pipefail

action=${1:-}
here=$(cd "$(dirname "$0")" && pwd)
compose="$here/small-host-target.compose.yml"
# Optional extra Compose file (for a local stand-in run only, never on a plan
# host: a measured target has no container limits) and project-name prefix.
compose_args=(-f "$compose")
[[ -n ${DW_SMALL_TARGET_COMPOSE_OVERRIDE:-} ]] && compose_args+=(-f "$DW_SMALL_TARGET_COMPOSE_OVERRIDE")
prefix=${DW_SMALL_PROJECT_PREFIX:-dw-small}
log() { printf '%s %s\n' "$(date -u +%FT%T.%3NZ)" "$*" | tee -a "$out/target.log" >&2; }

ntp_state() {
  # Latencies compare the driver's clock with the server's closed_at, so both
  # hosts must be NTP-synchronized. Record what each time service reports.
  {
    printf 'unix_seconds=%s\n' "$(date -u +%s.%N)"
    timedatectl show -p NTPSynchronized -p NTP 2>/dev/null || echo 'timedatectl unavailable'
    chronyc tracking 2>/dev/null || true
    timedatectl timesync-status 2>/dev/null || true
  }
}

# One line per second: host CPU jiffies (total, idle, iowait, steal), memory
# (total, available), swap (total, free), swap traffic and OOM kills from
# /proc/vmstat, and PSI totals. Small and agent-free, so it costs the plan
# almost nothing.
host_sampler() {
  local file=$1 project=$2 containers=$3 pass=0 cgroup_driver
  declare -A names=()
  cgroup_driver=$(docker info --format '{{.CgroupDriver}}')
  printf 'unix_seconds\tcpu_total_jiffies\tcpu_idle_jiffies\tcpu_iowait_jiffies\tcpu_steal_jiffies\tmem_total_kib\tmem_available_kib\tswap_total_kib\tswap_free_kib\tpswpin\tpswpout\toom_kill\tpsi_cpu_some_us\tpsi_memory_some_us\tpsi_memory_full_us\tpsi_io_some_us\n' > "$file"
  while :; do
    now=$(date -u +%s.%N)
    read -r _ user nice system idle iowait irq softirq steal _ < /proc/stat
    total=$((user + nice + system + idle + iowait + irq + softirq + steal))
    mem=$(awk '/^MemTotal:/ {t=$2} /^MemAvailable:/ {a=$2} /^SwapTotal:/ {st=$2} /^SwapFree:/ {sf=$2} END {printf "%s\t%s\t%s\t%s", t, a, st, sf}' /proc/meminfo)
    vm=$(awk '/^pswpin / {i=$2} /^pswpout / {o=$2} /^oom_kill / {k=$2} END {printf "%s\t%s\t%s", i+0, o+0, k+0}' /proc/vmstat)
    psi() {
      local resource=$1 kind=$2
      awk -v kind="$kind" '$1 == kind {sub("total=", "", $5); print $5; f=1} END {if (!f) print ""}' "/proc/pressure/$resource" 2>/dev/null || echo ""
    }
    printf '%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\t%s\n' "$now" "$total" "$idle" "$iowait" "$steal" "$mem" "$vm" \
      "$(psi cpu some)" "$(psi memory some)" "$(psi memory full)" "$(psi io some)" >> "$file"
    # Per service: cumulative CPU microseconds and current memory, read from
    # cgroup files only (names refresh every ten seconds), so the server and
    # database shares of the plan can be told apart.
    if (( pass % 10 == 0 )); then
      names=()
      while read -r id name; do names[$id]=$name; done < <(docker ps --no-trunc \
        --filter "label=com.docker.compose.project=$project" --format '{{.ID}} {{.Label "com.docker.compose.service"}}')
    fi
    pass=$((pass + 1))
    for id in "${!names[@]}"; do
      if [[ $cgroup_driver == systemd ]]; then dir=/sys/fs/cgroup/system.slice/docker-$id.scope; else dir=/sys/fs/cgroup/docker/$id; fi
      [[ -r $dir/cpu.stat ]] || continue
      usage=$(awk '/^usage_usec/ {print $2}' "$dir/cpu.stat" 2>/dev/null) || continue
      memory=$(cat "$dir/memory.current" 2>/dev/null) || continue
      printf '%s\t%s\t%s\t%s\n' "$now" "${names[$id]}" "$usage" "$memory" >> "$containers"
    done
    sleep 1
  done
}

case "$action" in
  up)
    profile=$(realpath "$2") out=$3 mode=$4
    case "$mode" in native-mysql|native-sqlite|php-mysql) ;; *) echo "Choose native-mysql, native-sqlite or php-mysql." >&2; exit 2 ;; esac
    : "${DW_SMALL_TARGET_BIND:?Set DW_SMALL_TARGET_BIND to the private address of this host}"
    [[ ! -e $out ]] || { echo "Refusing to overwrite $out." >&2; exit 2; }
    mkdir -p "$out"; out=$(realpath "$out")
    j() { local filter=$1; jq -r "$filter" "$profile"; }
    # Disposable per-run database and Redis passwords, never stored: the
    # services read them from their own environment.
    export DW_SMALL_DB_PASSWORD DW_SMALL_DB_ROOT_PASSWORD DW_SMALL_REDIS_PASSWORD
    DW_SMALL_DB_PASSWORD=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
    DW_SMALL_DB_ROOT_PASSWORD=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
    DW_SMALL_REDIS_PASSWORD=$(od -An -N16 -tx1 /dev/urandom | tr -d ' \n')
    export DW_SMALL_NATIVE_IMAGE DW_SMALL_PHP_IMAGE
    DW_SMALL_NATIVE_IMAGE=$(j .artifacts.native_image) DW_SMALL_PHP_IMAGE=$(j .artifacts.php_server)
    [[ $DW_SMALL_NATIVE_IMAGE =~ @sha256:[0-9a-f]{64}$ && $DW_SMALL_PHP_IMAGE =~ @sha256:[0-9a-f]{64}$ ]] \
      || { echo "The profile must name both server images by digest." >&2; exit 1; }
    project="$prefix-target-$mode-$(date -u +%Y%m%dt%H%M%S)"
    case "$mode" in
      native-mysql) services=(mysql native-mysql) ;;
      native-sqlite) services=(native-sqlite) ;;
      php-mysql) services=(mysql redis php-bootstrap php-server php-worker php-scheduler) ;;
      *) echo "Unknown mode $mode." >&2; exit 2 ;;
    esac
    jq -n --arg mode "$mode" --arg project "$project" --arg profile_sha "$(sha256sum "$profile" | cut -d ' ' -f1)" \
      --arg native "$DW_SMALL_NATIVE_IMAGE" --arg php "$DW_SMALL_PHP_IMAGE" --arg kernel "$(uname -r)" --arg cpus "$(nproc)" \
      --arg memory "$(awk '/MemTotal/ {print $2 * 1024}' /proc/meminfo)" \
      --arg swap "$(awk '/SwapTotal/ {print $2 * 1024}' /proc/meminfo)" \
      --arg cpu_model "$(awk -F': ' '/model name/ {print $2; exit}' /proc/cpuinfo)" \
      --arg docker "$(docker version --format '{{.Server.Version}}')" --arg compose "$(docker compose version --short)" \
      --arg cgroup "$(docker info --format '{{.CgroupDriver}}')" --arg storage "$(docker info --format '{{.Driver}}')" \
      --arg plan "${DW_SMALL_PROVIDER_PLAN:-}" --arg bind "$DW_SMALL_TARGET_BIND" \
      '{role:"target",mode:$mode,compose_project:$project,bind_address:$bind,profile_sha256:$profile_sha,
        server_image:(if ($mode|startswith("native")) then $native else $php end),
        database:(if $mode=="native-sqlite" then "sqlite" else "mysql" end),
        container_limits:"none; the plan is the limit",provider_plan:$plan,
        machine:{kernel:$kernel,logical_cpus:($cpus|tonumber),physical_memory_bytes:($memory|tonumber),
        swap_bytes:($swap|tonumber),cpu_model:$cpu_model,docker:$docker,compose:$compose,cgroup_driver:$cgroup,
        docker_storage_driver:$storage}}' > "$out/identity.json"
    ntp_state > "$out/clock-up.txt"
    printf '%s\n' "$project" > "$out/project"
    log "starting $mode in $project"
    if ! timeout 600 docker compose -p "$project" "${compose_args[@]}" --profile "$mode" up -d --wait "${services[@]}" >> "$out/target.log" 2>&1; then
      log "the target did not become ready; see target.log"
      exit 1
    fi
    host_sampler "$out/host-samples.tsv" "$project" "$out/container-samples.tsv" > /dev/null 2>&1 < /dev/null &
    echo $! > "$out/sampler.pid"
    disown || true
    # Check the published address from the host's own network namespace,
    # with the server image's bash (the native image has no curl).
    server_image=$(jq -r .server_image "$out/identity.json")
    timeout 60 docker run --rm --network host --entrypoint bash "$server_image" -c \
      "exec 3<>/dev/tcp/$DW_SMALL_TARGET_BIND/8080 && printf 'GET /api/ready HTTP/1.0\r\n\r\n' >&3 && head -n1 <&3 | grep -q ' 200 '" \
      || { log "ready did not answer on $DW_SMALL_TARGET_BIND:8080"; exit 1; }
    log "ready on http://$DW_SMALL_TARGET_BIND:8080; run the driver now"
    ;;
  down)
    out=$(realpath "$2")
    project=$(cat "$out/project")
    if [[ -f $out/sampler.pid ]]; then kill "$(cat "$out/sampler.pid")" 2>/dev/null || true; rm -f "$out/sampler.pid"; fi
    ntp_state > "$out/clock-down.txt"
    dc() { docker compose -p "$project" "${compose_args[@]}" --profile native-mysql --profile native-sqlite --profile php-mysql "$@"; }
    mode=$(jq -r .mode "$out/identity.json")
    export DW_SMALL_TARGET_BIND DW_SMALL_NATIVE_IMAGE DW_SMALL_PHP_IMAGE
    # Teardown only interpolates the Compose file; the running services keep
    # the passwords they started with.
    export DW_SMALL_DB_PASSWORD=unused DW_SMALL_DB_ROOT_PASSWORD=unused DW_SMALL_REDIS_PASSWORD=unused
    DW_SMALL_TARGET_BIND=$(jq -r .bind_address "$out/identity.json")
    DW_SMALL_NATIVE_IMAGE=$(jq -r .server_image "$out/identity.json") DW_SMALL_PHP_IMAGE=$DW_SMALL_NATIVE_IMAGE
    if [[ $mode != native-sqlite ]]; then
      dc exec -T mysql sh -c 'MYSQL_PWD=$MYSQL_PASSWORD exec mysql -u"$MYSQL_USER" "$MYSQL_DATABASE" --batch -e "$1"' audit "
        SELECT 'runs', status, COUNT(*) FROM workflow_runs GROUP BY status;
        SELECT 'tasks', task_type, status, COUNT(*) FROM workflow_tasks GROUP BY task_type, status;
        SELECT 'activities', status, COUNT(*) FROM activity_executions GROUP BY status;
        SELECT 'registrations', COUNT(*) FROM workflow_worker_registrations;" > "$out/database-audit.tsv" 2>> "$out/target.log" || true
    else
      echo 'sqlite: the native image has no sqlite3 client; the driver verified each history over the API' > "$out/database-audit.tsv"
    fi
    for id in $(dc ps -a -q); do
      docker inspect --format '{{index .Config.Labels "com.docker.compose.service"}} restarts={{.RestartCount}} oom={{.State.OOMKilled}} exit={{.State.ExitCode}} status={{.State.Status}}' "$id"
    done > "$out/container-states.txt"
    dc logs --no-color --timestamps > "$out/server-logs.txt" 2>&1 || true
    dc down --volumes --remove-orphans >> "$out/target.log" 2>&1 || true
    remaining=$(docker ps -a -q --filter "label=com.docker.compose.project=$project" | wc -l)
    volumes=$(docker volume ls -q --filter "label=com.docker.compose.project=$project" | wc -l)
    printf 'containers=%s volumes=%s\n' "$remaining" "$volumes" > "$out/teardown.txt"
    log "removed $project (containers=$remaining volumes=$volumes left)"
    ;;
  *) echo "Usage: $0 up PROFILE OUTPUT_DIR MODE | down OUTPUT_DIR" >&2; exit 2 ;;
esac
