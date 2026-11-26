#!/usr/bin/env bash
# docker-workflow-isolation: transitive-scripts-run-remotely
#
# Controller for the Temporal comparison race (Server #1081), following
# run-vultr-soak.sh: it creates a private VPC and a disposable target/driver
# VM pair, runs scripts/perf/temporal_compare.py on the driver, copies the
# results to build/temporal-compare and deletes everything in its exit trap,
# verifying absence through the API.
#
#   run-vultr-temporal-compare.sh [race]   provision, race, tear down
#   run-vultr-temporal-compare.sh cleanup  delete leftover race VMs and VPCs
#
# DW_RACE_DRY_RUN=1 makes no Vultr call: it prints every call it would make,
# the cost computation, and the remote steps, then stops.
#
# Spend is capped before anything is created. Each VM must be deleted within
# DW_RACE_MAX_VM_MINUTES of creation, and before the job's own timeout
# (DW_RACE_JOB_TIMEOUT_MINUTES) less a margin, so this exit trap always runs.
# Every SSH and scp call after creation runs under `timeout` against that
# deadline less the teardown reserve. The controller refuses to start when
# the cap, billed in whole hours at the plans' published hourly prices, could
# exceed DW_RACE_MAX_SPEND_USD.
set -Eeuo pipefail

MODE="${1:-race}"
API_BASE="${VULTR_API_BASE:-https://api.vultr.com/v2}"
REGION="${VULTR_PERF_REGION:-ewr}"
TARGET_PLAN="${DW_RACE_TARGET_PLAN:-vhp-2c-4gb-amd}"
DRIVER_PLAN="vhp-4c-8gb-amd"
OS_ID="${VULTR_PERF_OS_ID:-2284}"
DRY_RUN="${DW_RACE_DRY_RUN:-0}"
MAX_VM_MINUTES="${DW_RACE_MAX_VM_MINUTES:-180}"
MAX_SPEND_USD="${DW_RACE_MAX_SPEND_USD:-0.35}"
RACES="${DW_RACE_RACES:-five-steps,five-local-steps}"
BACKENDS="${DW_RACE_BACKENDS:-sqlite,mysql,pgsql}"
REPS="${DW_RACE_REPS:-5}"
WORKFLOWS="${DW_RACE_WORKFLOWS:-200}"
SERVER_REF="${DW_RACE_SERVER_REF:-}"
ARTIFACT_DIR="${DW_RACE_ARTIFACT_DIR:-build/temporal-compare}"
LABEL_PREFIX="dw-temporal-compare"
SSH_USER="perf"
JOB_TIMEOUT_MINUTES="${DW_RACE_JOB_TIMEOUT_MINUTES:-210}"
# Minutes reserved at the end of a VM's lifetime: deleting and verifying
# both VMs takes at most about 11 minutes (per VM, 6 attempts of three calls
# capped at 15 seconds, 10 seconds apart), and copying the results comes
# before that.
TEARDOWN_MINUTES=15
COPY_MINUTES=3
# The trap must finish this long before the job timeout, VPC included.
JOB_MARGIN_MINUTES=10
SCRIPT_START="$(date -u +%s)"

: "${GITHUB_RUN_ID:?GITHUB_RUN_ID is required}"
: "${GITHUB_RUN_ATTEMPT:?GITHUB_RUN_ATTEMPT is required}"
RUN_LABEL="$LABEL_PREFIX-$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT"

# Published hourly prices (Vultr pricing page, High Performance AMD, read
# 2026-11-26: https://www.vultr.com/pricing/). Update with the page.
hourly_price() {
  case "$1" in
    vhp-1c-2gb-amd) echo 0.018 ;;
    vhp-2c-4gb-amd) echo 0.036 ;;
    vhp-4c-8gb-amd) echo 0.071 ;;
    *) return 1 ;;
  esac
}

fail_input() {
  echo "$1" >&2
  exit 2
}

if [[ "$MODE" != race && "$MODE" != cleanup ]]; then
  fail_input "Usage: $0 [race|cleanup]"
fi
if [[ "$DRY_RUN" != 0 && "$DRY_RUN" != 1 ]]; then
  fail_input "DW_RACE_DRY_RUN must be 0 or 1."
fi
if [[ "$DRY_RUN" != 1 ]]; then
  : "${VULTR_API_KEY:?VULTR_API_KEY is required}"
fi
if [[ ! "$REGION" =~ ^[a-z]{3,4}$ ]]; then
  fail_input "VULTR_PERF_REGION must be a Vultr region id."
fi

if [[ "$MODE" == race ]]; then
  : "${GITHUB_SHA:?GITHUB_SHA is required}"
  if [[ ! "$GITHUB_SHA" =~ ^[0-9a-f]{40}$ ]]; then
    fail_input "GITHUB_SHA must be a full lowercase commit SHA."
  fi
  SERVER_REF="${SERVER_REF:-$GITHUB_SHA}"
  if [[ ! "$SERVER_REF" =~ ^[0-9a-f]{40}$ ]]; then
    fail_input "DW_RACE_SERVER_REF must be a full lowercase commit SHA."
  fi
  if ! hourly_price "$TARGET_PLAN" >/dev/null || [[ "$TARGET_PLAN" == "$DRIVER_PLAN" ]]; then
    fail_input "Refusing unexpected target plan: $TARGET_PLAN (vhp-2c-4gb-amd or vhp-1c-2gb-amd)."
  fi
  if [[ ! "$RACES" =~ ^(five-steps|five-local-steps)(,(five-steps|five-local-steps))?$ ]]; then
    fail_input "DW_RACE_RACES must be five-steps, five-local-steps or both, comma-separated."
  fi
  if [[ ! "$BACKENDS" =~ ^(sqlite|mysql|pgsql)(,(sqlite|mysql|pgsql)){0,2}$ ]]; then
    fail_input "DW_RACE_BACKENDS must be a comma-separated subset of sqlite,mysql,pgsql."
  fi
  for list in "$RACES" "$BACKENDS"; do
    if [[ "$(tr ',' '\n' <<<"$list" | sort | uniq -d)" != "" ]]; then
      fail_input "Refusing duplicate entries in '$list'."
    fi
  done
  if [[ ! "$REPS" =~ ^[0-9]+$ ]] || ((REPS < 1 || REPS > 9)); then
    fail_input "DW_RACE_REPS must be between 1 and 9."
  fi
  if [[ ! "$WORKFLOWS" =~ ^[0-9]+$ ]] || ((WORKFLOWS < 20 || WORKFLOWS > 1000)); then
    fail_input "DW_RACE_WORKFLOWS must be between 20 and 1000."
  fi
  if [[ ! "$MAX_VM_MINUTES" =~ ^[0-9]+$ ]] || ((MAX_VM_MINUTES < 60 || MAX_VM_MINUTES > 240)); then
    fail_input "DW_RACE_MAX_VM_MINUTES must be between 60 and 240."
  fi
  if [[ ! "$MAX_SPEND_USD" =~ ^[0-9]+(\.[0-9]{1,2})?$ ]]; then
    fail_input "DW_RACE_MAX_SPEND_USD must be a dollar amount such as 0.35."
  fi
  if [[ ! "$JOB_TIMEOUT_MINUTES" =~ ^[0-9]+$ ]] || ((JOB_TIMEOUT_MINUTES < MAX_VM_MINUTES + JOB_MARGIN_MINUTES + 5)); then
    fail_input "DW_RACE_JOB_TIMEOUT_MINUTES must be at least DW_RACE_MAX_VM_MINUTES plus $((JOB_MARGIN_MINUTES + 5)), so the exit trap runs before the job is stopped."
  fi

  # Expected minutes: provisioning and cloud-init (10), building the native
  # Server and the worker images on the driver (30), then per system 2
  # minutes of start, warmup and teardown plus 1.5 minutes per rep of 200
  # workflows. A race runs Temporal twice around the native backends.
  race_count=$(tr ',' '\n' <<<"$RACES" | wc -l)
  backend_count=$(tr ',' '\n' <<<"$BACKENDS" | wc -l)
  EXPECTED_MINUTES=$(awk -v r="$race_count" -v b="$backend_count" -v reps="$REPS" -v w="$WORKFLOWS" \
    'BEGIN { m = 40 + r * (b + 2) * (2 + reps * 1.5 * w / 200); printf "%d", (m == int(m)) ? m : int(m) + 1 }')
  if ((EXPECTED_MINUTES + COPY_MINUTES + TEARDOWN_MINUTES > MAX_VM_MINUTES)); then
    fail_input "Expected ${EXPECTED_MINUTES} minutes plus $((COPY_MINUTES + TEARDOWN_MINUTES)) for copying and teardown exceeds DW_RACE_MAX_VM_MINUTES=${MAX_VM_MINUTES}."
  fi
  target_price="$(hourly_price "$TARGET_PLAN")"
  driver_price="$(hourly_price "$DRIVER_PLAN")"
  # Vultr bills by the hour: count every started hour.
  billed_hours=$(((MAX_VM_MINUTES + 59) / 60))
  expected_hours=$(((EXPECTED_MINUTES + 59) / 60))
  WORST_CASE_USD=$(awk -v t="$target_price" -v d="$driver_price" -v h="$billed_hours" 'BEGIN { printf "%.3f", (t + d) * h }')
  EXPECTED_USD=$(awk -v t="$target_price" -v d="$driver_price" -v h="$expected_hours" 'BEGIN { printf "%.3f", (t + d) * h }')
  cat <<EOF
Cost plan for $RUN_LABEL (prices: https://www.vultr.com/pricing/, read 2026-11-26):
  target $TARGET_PLAN \$$target_price/h + driver $DRIVER_PLAN \$$driver_price/h in $REGION
  expected: $EXPECTED_MINUTES min (${RACES}; ${BACKENDS}; $REPS reps x $WORKFLOWS workflows) -> $expected_hours billed h -> \$$EXPECTED_USD
  worst case: VM lifetime cap $MAX_VM_MINUTES min -> $billed_hours billed h -> \$$WORST_CASE_USD (limit \$$MAX_SPEND_USD)
  enforcement: remote work stops $((COPY_MINUTES + TEARDOWN_MINUTES)) min before the cap; deletion is verified by the cap and at least $JOB_MARGIN_MINUTES min before the ${JOB_TIMEOUT_MINUTES}-min job timeout
EOF
  if awk -v w="$WORST_CASE_USD" -v m="$MAX_SPEND_USD" 'BEGIN { exit !(w > m) }'; then
    fail_input "Refusing to start: worst-case cost \$$WORST_CASE_USD exceeds DW_RACE_MAX_SPEND_USD=\$$MAX_SPEND_USD."
  fi
fi

WORK_DIR="$(mktemp -d)"
KEY_FILE="$WORK_DIR/id_ed25519"
KNOWN_HOSTS="$WORK_DIR/known_hosts"
PROVISION_LOG="$ARTIFACT_DIR/provisioning.log"
mkdir -p "$ARTIFACT_DIR"
: > "$PROVISION_LOG"
VPC_ID=""
INSTANCE_IDS=()
INSTANCE_LABELS=()

log() {
  printf '%s %s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "$*" | tee -a "$PROVISION_LOG" >&2
}

summary_note() {
  if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
    printf '%s\n' "$@" >> "$GITHUB_STEP_SUMMARY"
  fi
}

# One Vultr API call. In a dry run it prints the call and answers with a
# canned body, so the whole sequence, including cleanup, can be read.
api() {
  local method="$1" path="$2"
  shift 2
  if [[ "$DRY_RUN" == 1 ]]; then
    local body="" arg
    for arg in "$@"; do
      if [[ "$arg" == @* && -f "${arg#@}" ]]; then
        body=" $(jq -c 'if .user_data then .user_data = "<cloud-init, base64>" else . end' "${arg#@}")"
      fi
    done
    # DW_RACE_DRY_RUN_FAIL="METHOD /path-prefix" makes matching calls fail,
    # to show how the controller handles an API failure.
    if [[ -n "${DW_RACE_DRY_RUN_FAIL:-}" && "$method $path" == "$DW_RACE_DRY_RUN_FAIL"* ]]; then
      printf 'DRY-RUN Vultr API: %s %s -> simulated failure\n' "$method" "$path" >&2
      return 22
    fi
    printf 'DRY-RUN Vultr API: %s %s%s\n' "$method" "$path" "$body" >&2
    dry_run_answer "$method" "$path" "$@"
    return 0
  fi
  curl --fail-with-body --silent --show-error \
    --connect-timeout 15 \
    --max-time 60 \
    --request "$method" \
    --header "Authorization: Bearer $VULTR_API_KEY" \
    --header "Content-Type: application/json" \
    "$@" \
    "$API_BASE$path"
}

dry_run_answer() {
  local method="$1" path="$2" write_out=0 arg role
  shift 2
  for arg in "$@"; do
    [[ "$arg" == "%{http_code}" ]] && write_out=1
    if [[ "$arg" == @* && -f "${arg#@}" ]]; then role="$(jq -r '.label // "" | sub(".*-"; "")' "${arg#@}")"; fi
  done
  local octet=2
  [[ "$path" == *driver* ]] && octet=3
  case "$method $path" in
    "POST /vpcs") echo '{"vpc":{"id":"dry-run-vpc"}}' ;;
    "POST /instances") printf '{"instance":{"id":"dry-run-%s"}}\n' "$role" ;;
    "GET /instances/"*/vpcs) printf '{"vpcs":[{"id":"dry-run-vpc","ip_address":"10.0.0.%s","mac_address":"5a:00:00:00:00:0%s"}]}\n' "$octet" "$octet" ;;
    "GET /instances?"*) echo '{"instances":[],"meta":{"links":{"next":""}}}' ;;
    "GET /vpcs?"*) echo '{"vpcs":[],"meta":{"links":{"next":""}}}' ;;
    "GET /instances/"*|"GET /vpcs/"*)
      if ((write_out)); then echo 404
      elif [[ "$path" == /vpcs/* ]]; then echo '{"vpc":{"v4_subnet":"10.0.0.0","v4_subnet_mask":20}}'
      else printf '{"instance":{"status":"active","server_status":"ok","main_ip":"192.0.2.%s"}}\n' "$octet"
      fi ;;
    "DELETE "*) if ((write_out)); then echo 204; fi ;;
    *) echo '{}' ;;
  esac
}

http_status() {
  local method="$1" path="$2"
  api "$method" "$path" --no-fail-with-body --max-time 15 --output /dev/null --write-out '%{http_code}' || echo 000
}

# Race VMs (all, or one run's), from the paginated instance list.
list_race_instances() {
  local prefix="$1" cursor="" page
  while :; do
    # Any failed call or unreadable page fails the listing: an empty
    # answer must mean nothing is left, never that nothing could be read.
    page="$(api GET "/instances?per_page=500${cursor:+&cursor=$cursor}")" || return 1
    jq -e '.instances | type == "array"' <<<"$page" >/dev/null || return 1
    jq -r --arg p "$prefix" '.instances[]? | select(.label | startswith($p)) | "\(.id)\t\(.label)\t\(.date_created)"' <<<"$page" || return 1
    cursor="$(jq -r '.meta.links.next // ""' <<<"$page")" || return 1
    [[ -n "$cursor" ]] || break
  done
}

list_race_vpcs() {
  local prefix="$1" cursor="" page
  while :; do
    # Any failed call or unreadable page fails the listing: an empty
    # answer must mean nothing is left, never that nothing could be read.
    page="$(api GET "/vpcs?per_page=500${cursor:+&cursor=$cursor}")" || return 1
    jq -e '.vpcs | type == "array"' <<<"$page" >/dev/null || return 1
    jq -r --arg p "$prefix" '.vpcs[]? | select((.description // "") | startswith($p)) | "\(.id)\t\(.description)\t\(.date_created)"' <<<"$page" || return 1
    cursor="$(jq -r '.meta.links.next // ""' <<<"$page")" || return 1
    [[ -n "$cursor" ]] || break
  done
}

# Delete one resource and verify it is gone: a 404 for its own path, and
# no instance under its unique label (or no VPC with its id) in a listing.
delete_verified() {
  local kind="$1" id="$2" label="${3:-}" attempt delete_status lookup_status listed
  for attempt in 1 2 3 4 5 6; do
    # A failed response can still follow a successful provider-side deletion.
    delete_status="$(http_status DELETE "/$kind/$id")"
    lookup_status="$(http_status GET "/$kind/$id")"
    listed=0
    if [[ "$kind" == instances && -n "$label" && "$lookup_status" == 404 ]]; then
      listed="$(api GET "/instances?label=$label" --max-time 15 | jq -e '.instances | length')" || listed=unknown
    elif [[ "$kind" == vpcs && "$lookup_status" == 404 ]]; then
      listed="$(list_race_vpcs "$LABEL_PREFIX-" | awk -F '\t' -v id="$id" '$1 == id { n++ } END { print n + 0 }')" || listed=unknown
    fi
    if [[ "$lookup_status" == 404 && "$listed" == 0 ]]; then
      log "Verified Vultr $kind $id ${label:+($label) }is absent"
      return 0
    fi
    log "Cleanup attempt $attempt for $kind $id: delete=$delete_status lookup=$lookup_status listed=$listed"
    if [[ "$delete_status" == 401 || "$delete_status" == 403 || "$lookup_status" == 401 || "$lookup_status" == 403 ]]; then
      break
    fi
    [[ "$attempt" -lt 6 ]] && sleep 10
  done
  log "ERROR: Unable to verify deletion of Vultr $kind $id ${label:+($label)}"
  summary_note "## Cleanup required" "" "Vultr $kind \`$id\` ${label:+(label \`$label\`) }has not been confirmed absent. Run the Temporal Compare workflow with \`cleanup_only\`, or \`scripts/perf/run-vultr-temporal-compare.sh cleanup\`." ""
  return 1
}

# shellcheck disable=SC2329  # called from the exit trap
destroy_all() {
  local status=0 index
  for index in "${!INSTANCE_IDS[@]}"; do
    delete_verified instances "${INSTANCE_IDS[$index]}" "${INSTANCE_LABELS[$index]}" || status=1
  done
  if [[ -n "$VPC_ID" ]]; then
    delete_verified vpcs "$VPC_ID" || status=1
  fi
  return "$status"
}

# shellcheck disable=SC2329  # the exit trap
cleanup() {
  local status=$?
  trap - EXIT INT TERM
  if ! destroy_all && [[ "$status" -eq 0 ]]; then
    status=1
  fi
  rm -rf "$WORK_DIR"
  exit "$status"
}

# Leftovers from a run whose runner died before its trap ran. With an
# argument, only that run's resources; otherwise every race resource older
# than the VM lifetime cap, so a concurrent run is never touched.
sweep() {
  local only="${1:-}" now id label created age status=0 instances vpcs
  now="$(date -u +%s)"
  if ! instances="$(list_race_instances "${only:-$LABEL_PREFIX-}")" \
    || ! vpcs="$(list_race_vpcs "${only:-$LABEL_PREFIX-}")"; then
    log "ERROR: Could not list Vultr instances and VPCs, so leftovers cannot be checked"
    summary_note "## Cleanup check failed" "" "The Vultr instance or VPC listing failed. Race resources may remain; rerun the cleanup." ""
    return 1
  fi
  while IFS=$'\t' read -r id label created; do
    [[ -n "$id" ]] || continue
    age=$(((now - $(date -u -d "$created" +%s)) / 60))
    if [[ -z "$only" && "$age" -lt "$MAX_VM_MINUTES" ]]; then
      log "Keeping $label ($id): $age minutes old, within the lifetime cap"
      continue
    fi
    log "Deleting leftover race VM $label ($id), $age minutes old"
    delete_verified instances "$id" "$label" || status=1
  done <<<"$instances"
  while IFS=$'\t' read -r id label created; do
    [[ -n "$id" ]] || continue
    age=$(((now - $(date -u -d "$created" +%s)) / 60))
    if [[ -z "$only" && "$age" -lt "$MAX_VM_MINUTES" ]]; then
      continue
    fi
    log "Deleting leftover race VPC $label ($id)"
    delete_verified vpcs "$id" || status=1
  done <<<"$vpcs"
  return "$status"
}

if [[ "$MODE" == cleanup ]]; then
  trap 'rm -rf "$WORK_DIR"' EXIT
  # DW_RACE_CLEANUP_RUN=<run id>-<attempt> limits cleanup to one run, at any age.
  if [[ -n "${DW_RACE_CLEANUP_RUN:-}" ]]; then
    [[ "$DW_RACE_CLEANUP_RUN" =~ ^[0-9]+-[0-9]+$ ]] || fail_input "DW_RACE_CLEANUP_RUN must be <run id>-<attempt>."
    sweep "$LABEL_PREFIX-$DW_RACE_CLEANUP_RUN-"
  else
    sweep
  fi
  exit $?
fi

trap cleanup EXIT INT TERM
if ! sweep; then
  log "ERROR: Leftovers from an earlier run could not be checked or deleted; not creating new VMs"
  exit 1
fi

if [[ "$DRY_RUN" == 1 ]]; then
  controller_ip="192.0.2.1"
  ssh-keygen -q -t ed25519 -N '' -f "$KEY_FILE"
else
  controller_ip="$(curl --fail --silent --show-error https://api.ipify.org)"
  if [[ ! "$controller_ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
    echo "Unable to determine the GitHub runner IPv4 address." >&2
    exit 2
  fi
  ssh-keygen -q -t ed25519 -N '' -f "$KEY_FILE"
fi
public_key="$(cat "$KEY_FILE.pub")"

cloud_init="$WORK_DIR/cloud-init.yml"
cat > "$cloud_init" <<EOF
#cloud-config
package_update: true
package_upgrade: false
ssh_pwauth: false
disable_root: true
users:
  - default
  - name: $SSH_USER
    gecos: Durable Workflow perf runner
    groups: [adm, sudo]
    shell: /bin/bash
    sudo: ALL=(ALL) NOPASSWD:ALL
    ssh_authorized_keys:
      - $public_key
packages:
  - ca-certificates
  - curl
  - docker.io
  - docker-compose-v2
  - docker-buildx
  - git
  - jq
  - python3
  - ufw
write_files:
  - path: /etc/ssh/sshd_config.d/90-durable-workflow-perf.conf
    permissions: '0644'
    content: |
      PasswordAuthentication no
      KbdInteractiveAuthentication no
      PermitRootLogin no
runcmd:
  - systemctl enable --now docker
  - usermod -aG docker $SSH_USER
  - ufw default deny incoming
  - ufw default allow outgoing
  - ufw allow from $controller_ip to any port 22 proto tcp
  - ufw --force enable
  - systemctl reload ssh
EOF

# Deadlines, fixed before anything is created. VM_END: both VMs deleted and
# verified. WORK_END: the last SSH or scp call ends. RACE_END: the race ends,
# leaving time to copy its results.
CREATE_AT="$(date -u +%s)"
VM_END=$((CREATE_AT + MAX_VM_MINUTES * 60))
JOB_LIMIT=$((SCRIPT_START + (JOB_TIMEOUT_MINUTES - JOB_MARGIN_MINUTES) * 60))
((VM_END > JOB_LIMIT)) && VM_END=$JOB_LIMIT
WORK_END=$((VM_END - TEARDOWN_MINUTES * 60))
RACE_END=$((WORK_END - COPY_MINUTES * 60))
if (((RACE_END - CREATE_AT) / 60 < EXPECTED_MINUTES)); then
  echo "Only $(((RACE_END - CREATE_AT) / 60)) minutes would remain for an expected ${EXPECTED_MINUTES}-minute race; not creating VMs." >&2
  exit 2
fi
utc() { date -u -d "@$1" +'%Y-%m-%dT%H:%M:%SZ'; }
log "Deadlines: race by $(utc "$RACE_END"), last remote call by $(utc "$WORK_END"), VMs deleted by $(utc "$VM_END")"

log "Creating private VPC for $RUN_LABEL in $REGION"
vpc_payload="$WORK_DIR/vpc.json"
jq -n --arg region "$REGION" --arg description "$RUN_LABEL-vpc" '{region: $region, description: $description}' > "$vpc_payload"
trap '' INT TERM
VPC_ID="$(api POST /vpcs --data-binary "@$vpc_payload" | jq -er '.vpc.id')"
trap cleanup EXIT INT TERM
log "Created VPC $VPC_ID"
VPC_MASK="$(api GET "/vpcs/$VPC_ID" | jq -er '.vpc.v4_subnet_mask')"

create_instance() {
  local role="$1" plan="$2" label="$RUN_LABEL-$1" payload="$WORK_DIR/$1.json" id
  jq -n \
    --arg region "$REGION" --arg plan "$plan" --arg label "$label" --arg vpc "$VPC_ID" \
    --arg tag "$LABEL_PREFIX" --arg run "$RUN_LABEL" \
    --arg user_data "$(base64 -w 0 "$cloud_init")" --argjson os_id "$OS_ID" \
    '{region: $region, plan: $plan, os_id: $os_id, label: $label, hostname: $label,
      tags: [$tag, $run], attach_vpc: [$vpc], user_data: $user_data,
      enable_ipv6: false, activation_email: false}' > "$payload"
  log "Creating $role VM: region=$REGION plan=$plan os_id=$OS_ID label=$label"
  trap '' INT TERM
  id="$(api POST /instances --data-binary "@$payload" | jq -er '.instance.id')"
  INSTANCE_IDS+=("$id")
  INSTANCE_LABELS+=("$label")
  trap cleanup EXIT INT TERM
  log "Created $role VM $id"
}

create_instance target "$TARGET_PLAN"
create_instance driver "$DRIVER_PLAN"
TARGET_ID="${INSTANCE_IDS[0]}"
DRIVER_ID="${INSTANCE_IDS[1]}"

before() { (($(date -u +%s) < $1)); }

wait_active() {
  local id="$1" details status server_status ip
  for _ in $(seq 1 90); do
    before "$WORK_END" || break
    details="$(api GET "/instances/$id")"
    status="$(jq -r '.instance.status // ""' <<<"$details")"
    server_status="$(jq -r '.instance.server_status // ""' <<<"$details")"
    ip="$(jq -r '.instance.main_ip // ""' <<<"$details")"
    if [[ "$status" == active && "$server_status" == ok && -n "$ip" && "$ip" != 0.0.0.0 ]]; then
      echo "$ip"
      return 0
    fi
    sleep 10
  done
  echo "Vultr instance $id did not become active with an IPv4 address." >&2
  return 1
}

private_address() {
  api GET "/instances/$1/vpcs" | jq -er --arg vpc "$VPC_ID" '.vpcs[] | select(.id == $vpc) | "\(.ip_address) \(.mac_address)"'
}

TARGET_IP="$(wait_active "$TARGET_ID")"
DRIVER_IP="$(wait_active "$DRIVER_ID")"
read -r TARGET_PRIVATE TARGET_MAC <<<"$(private_address "$TARGET_ID")"
read -r DRIVER_PRIVATE DRIVER_MAC <<<"$(private_address "$DRIVER_ID")"
log "Target $TARGET_ID private $TARGET_PRIVATE; driver $DRIVER_ID private $DRIVER_PRIVATE"

if [[ "$DRY_RUN" == 1 ]]; then
  cat >&2 <<EOF
DRY-RUN remote steps (no SSH in a dry run):
  wait for SSH and cloud-init on target $TARGET_IP and driver $DRIVER_IP
  bring up the VPC interface (MAC $TARGET_MAC / $DRIVER_MAC) if cloud-init did not
  target firewall: allow SSH from the driver's private address $DRIVER_PRIVATE; race ports publish on $TARGET_PRIVATE only
  every SSH and scp call runs under timeout, ending by $(utc "$WORK_END")
  driver: check out harness $GITHUB_SHA and Server $SERVER_REF, then run until $(utc "$RACE_END"):
    python3 scripts/perf/temporal_compare.py run --output ~/race-out --races $RACES --backends $BACKENDS \\
      --reps $REPS --workflows $WORKFLOWS --native-source ~/server-under-test \\
      --target-docker-host ssh://$SSH_USER@$TARGET_PRIVATE --target-bind $TARGET_PRIVATE --target-address $TARGET_PRIVATE \\
      --target-plan $TARGET_PLAN --driver-plan $DRIVER_PLAN --region $REGION --run-id $GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT
  copy ~/race-out from the driver to $ARTIFACT_DIR
EOF
  log "Dry run complete; the exit trap now shows the delete and verify calls"
  exit 0
fi

ssh_options=(
  -i "$KEY_FILE"
  -o BatchMode=yes
  -o ConnectTimeout=10
  -o ServerAliveInterval=30
  -o ServerAliveCountMax=6
  -o StrictHostKeyChecking=accept-new
  -o "UserKnownHostsFile=$KNOWN_HOSTS"
)
# Every call after creation is bounded by a deadline, so a hung SSH session
# can never keep the VMs past their lifetime cap: the exit trap runs as soon
# as the foreground command returns.
time_left() {
  local left=$(($1 - $(date -u +%s)))
  if ((left <= 0)); then
    log "ERROR: The VM lifetime deadline has passed; no more remote commands"
    return 1
  fi
  echo "$left"
}
remote_until() {
  local end="$1" host="$2" left
  shift 2
  left="$(time_left "$end")" || return 124
  # shellcheck disable=SC2029  # remote commands are fixed strings or stdin
  timeout --kill-after=10 "$left" ssh "${ssh_options[@]}" "$SSH_USER@$host" "$@"
}
remote() {
  remote_until "$WORK_END" "$@"
}
scp_bounded() {
  local left
  left="$(time_left "$WORK_END")" || return 124
  timeout --kill-after=10 "$left" scp "${ssh_options[@]}" "$@"
}

for host in "$TARGET_IP" "$DRIVER_IP"; do
  log "Waiting for SSH and cloud-init on $host"
  ready=false
  for _ in $(seq 1 90); do
    before "$WORK_END" || break
    if remote "$host" true >/dev/null 2>&1; then ready=true; break; fi
    sleep 10
  done
  [[ "$ready" == true ]] || { echo "VM $host did not become reachable over SSH." >&2; exit 1; }
  remote "$host" 'sudo cloud-init status --wait >/dev/null; docker version >/dev/null && docker compose version >/dev/null'
done

# The VPC interface is configured by cloud-init on current images; if not,
# bring it up by its MAC address.
ensure_private() {
  local host="$1" address="$2" mac="$3"
  remote "$host" bash -s -- "$address" "$mac" "$VPC_MASK" <<'REMOTE'
set -euo pipefail
address="$1" mac="$2" mask="$3"
if ip -4 -o addr show | grep -qw "$address"; then exit 0; fi
iface="$(ip -o link | awk -v mac="$mac" 'tolower($0) ~ tolower(mac) { sub(":", "", $2); print $2; exit }')"
test -n "$iface"
sudo ip addr add "$address/$mask" dev "$iface"
sudo ip link set "$iface" up
ip -4 -o addr show | grep -qw "$address"
REMOTE
}
ensure_private "$TARGET_IP" "$TARGET_PRIVATE" "$TARGET_MAC"
ensure_private "$DRIVER_IP" "$DRIVER_PRIVATE" "$DRIVER_MAC"

# The driver reaches the target's Docker engine over SSH. The race ports
# are published only on the target's private address; Docker's published
# ports bypass ufw, so the VPC holding only these two VMs is the boundary.
log "Admitting SSH from the driver's private address to the target"
remote "$TARGET_IP" bash -s -- "$DRIVER_PRIVATE" <<'REMOTE'
set -euo pipefail
sudo ufw allow from "$1" to any port 22 proto tcp >/dev/null
REMOTE

log "Giving the driver SSH access to the target's Docker engine"
scp_bounded "$KEY_FILE" "$SSH_USER@$DRIVER_IP:.ssh/race_target" >/dev/null
remote "$DRIVER_IP" bash -s -- "$TARGET_PRIVATE" "$SSH_USER" <<'REMOTE'
set -euo pipefail
chmod 600 "$HOME/.ssh/race_target"
cat > "$HOME/.ssh/config" <<EOF
Host $1
  User $2
  IdentityFile ~/.ssh/race_target
  StrictHostKeyChecking accept-new
  ServerAliveInterval 30
EOF
chmod 600 "$HOME/.ssh/config"
timeout 60 ssh "$2@$1" docker version --format '{{.Server.Version}}' >/dev/null
REMOTE

log "Checking out harness $GITHUB_SHA and Server $SERVER_REF on the driver"
remote "$DRIVER_IP" bash -s -- "$GITHUB_SHA" "$SERVER_REF" <<'REMOTE'
set -euo pipefail
checkout() {
  mkdir -p "$2" && cd "$2"
  git init --quiet
  git remote add origin https://github.com/durable-workflow/server.git
  git fetch --quiet --depth=1 origin "$1"
  git checkout --quiet --detach FETCH_HEAD
  test "$(git rev-parse HEAD)" = "$1"
  test -z "$(git status --porcelain --untracked-files=no)"
}
checkout "$1" "$HOME/server"
checkout "$2" "$HOME/server-under-test"
REMOTE

remaining=$((RACE_END - $(date -u +%s) - 60))
if ((remaining < 600)); then
  echo "Less than ten minutes remain before the race deadline; not starting the race." >&2
  exit 1
fi
log "Starting the race on the driver (at most ${remaining}s)"
set +e
remote_until "$RACE_END" "$DRIVER_IP" bash -s -- "$remaining" "$TARGET_PRIVATE" "$RACES" "$BACKENDS" "$REPS" "$WORKFLOWS" \
  "$TARGET_PLAN" "$DRIVER_PLAN" "$REGION" "$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT" \
  "${GITHUB_REPOSITORY:-}" "${GITHUB_REF:-}" "$GITHUB_SHA" "${GITHUB_WORKFLOW:-}" "${GITHUB_EVENT_NAME:-}" \
  "$GITHUB_RUN_ID" "$GITHUB_RUN_ATTEMPT" "${GITHUB_JOB:-}" "vultr-$DRIVER_ID" <<'REMOTE'
set -euo pipefail
export GITHUB_REPOSITORY="${11}" GITHUB_REF="${12}" GITHUB_SHA="${13}" GITHUB_WORKFLOW="${14}" \
  GITHUB_EVENT_NAME="${15}" GITHUB_RUN_ID="${16}" GITHUB_RUN_ATTEMPT="${17}" GITHUB_JOB="${18}" \
  RUNNER_NAME="${19}" RUNNER_OS=Linux RUNNER_ARCH=X64
cd "$HOME/server"
timeout --signal=TERM --kill-after=60 "$1" python3 scripts/perf/temporal_compare.py run \
  --output "$HOME/race-out" --races "$3" --backends "$4" --reps "$5" --workflows "$6" \
  --native-source "$HOME/server-under-test" --target-docker-host "ssh://perf@$2" \
  --target-bind "$2" --target-address "$2" --target-plan "$7" --driver-plan "$8" --region "$9" \
  --run-id "${10}" --cargo-build-jobs 4
REMOTE
race_status=$?
set -e

# shellcheck disable=SC2016  # $HOME expands on the driver
if ! remote "$DRIVER_IP" 'tar -C "$HOME/race-out" -czf - .' | tar -C "$ARTIFACT_DIR" -xzf -; then
  log "WARNING: race results could not be copied from the driver"
  [[ "$race_status" -eq 0 ]] && race_status=1
fi
log "Race finished with status $race_status"
exit "$race_status"
