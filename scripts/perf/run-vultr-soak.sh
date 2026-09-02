#!/usr/bin/env bash
# docker-workflow-isolation: transitive-scripts-run-remotely
set -Eeuo pipefail

API_BASE="${VULTR_API_BASE:-https://api.vultr.com/v2}"
REGION="${VULTR_PERF_REGION:-ewr}"
PLAN="${VULTR_PERF_PLAN:-vhp-2c-4gb-amd}"
OS_ID="${VULTR_PERF_OS_ID:-2284}"
SSH_USER="perf"
INSTANCE_ID=""
INSTANCE_IP=""
WORK_DIR="$(mktemp -d)"
KEY_FILE="$WORK_DIR/id_ed25519"
KNOWN_HOSTS="$WORK_DIR/known_hosts"
PROVISION_LOG="${DW_PERF_ARTIFACT_DIR:-build/perf}/provisioning.log"

: "${VULTR_API_KEY:?VULTR_API_KEY is required}"
: "${GITHUB_SHA:?GITHUB_SHA is required}"
: "${GITHUB_RUN_ID:?GITHUB_RUN_ID is required}"
: "${GITHUB_RUN_ATTEMPT:?GITHUB_RUN_ATTEMPT is required}"

DURATION_SECONDS="${DW_PERF_DURATION_SECONDS:-7200}"
CONCURRENCY="${DW_PERF_CONCURRENCY:-24}"

if [[ ! "$GITHUB_SHA" =~ ^[0-9a-f]{40}$ ]]; then
  echo "GITHUB_SHA must be a full lowercase commit SHA." >&2
  exit 2
fi

if [[ "$PLAN" != "vhp-2c-4gb-amd" ]]; then
  echo "Refusing unexpected Vultr plan: $PLAN" >&2
  exit 2
fi

if [[ ! "$DURATION_SECONDS" =~ ^[0-9]+$ ]] \
  || ((DURATION_SECONDS < 3600 || DURATION_SECONDS > 14400)); then
  echo "DW_PERF_DURATION_SECONDS must be between 3600 and 14400." >&2
  exit 2
fi

if [[ ! "$CONCURRENCY" =~ ^[0-9]+$ ]] \
  || ((CONCURRENCY < 1 || CONCURRENCY > 128)); then
  echo "DW_PERF_CONCURRENCY must be between 1 and 128." >&2
  exit 2
fi

mkdir -p "$(dirname "$PROVISION_LOG")"
: > "$PROVISION_LOG"

log() {
  printf '%s %s\n' "$(date -u +'%Y-%m-%dT%H:%M:%SZ')" "$*" | tee -a "$PROVISION_LOG"
}

api() {
  local method="$1"
  local path="$2"
  shift 2

  curl --fail-with-body --silent --show-error \
    --connect-timeout 15 \
    --max-time 60 \
    --request "$method" \
    --header "Authorization: Bearer $VULTR_API_KEY" \
    --header "Content-Type: application/json" \
    "$@" \
    "$API_BASE$path"
}

destroy_instance() {
  local cleanup_status=0

  if [[ -z "$INSTANCE_ID" ]]; then
    return
  fi

  log "Deleting Vultr instance $INSTANCE_ID"
  if ! api DELETE "/instances/$INSTANCE_ID" --output /dev/null; then
    cleanup_status=1
    log "ERROR: Vultr did not accept deletion for instance $INSTANCE_ID"
    if [[ -n "${GITHUB_STEP_SUMMARY:-}" ]]; then
      printf '## Cleanup required\n\nVultr instance `%s` was not deleted by the workflow.\n' \
        "$INSTANCE_ID" >> "$GITHUB_STEP_SUMMARY"
    fi
  else
    log "Vultr accepted deletion for instance $INSTANCE_ID"
  fi

  return "$cleanup_status"
}

cleanup() {
  local status=$?
  trap - EXIT INT TERM

  if ! destroy_instance && [[ "$status" -eq 0 ]]; then
    status=1
  fi

  rm -rf "$WORK_DIR"
  exit "$status"
}
trap cleanup EXIT INT TERM

controller_ip="$(curl --fail --silent --show-error https://api.ipify.org)"
if [[ ! "$controller_ip" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]; then
  echo "Unable to determine the GitHub runner IPv4 address." >&2
  exit 2
fi

ssh-keygen -q -t ed25519 -N '' -f "$KEY_FILE"
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
  - git
  - jq
  - openssl
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

label="dw-server-perf-soak-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}"
payload="$WORK_DIR/instance.json"
jq -n \
  --arg region "$REGION" \
  --arg plan "$PLAN" \
  --arg label "$label" \
  --arg hostname "$label" \
  --arg user_data "$(base64 -w 0 "$cloud_init")" \
  --argjson os_id "$OS_ID" \
  '{
    region: $region,
    plan: $plan,
    os_id: $os_id,
    label: $label,
    hostname: $hostname,
    user_data: $user_data,
    enable_ipv6: false,
    activation_email: false
  }' > "$payload"

log "Creating disposable Vultr instance: region=$REGION plan=$PLAN os_id=$OS_ID"
response="$WORK_DIR/create-response.json"
trap '' INT TERM
api POST /instances --data-binary "@$payload" > "$response"
INSTANCE_ID="$(jq -er '.instance.id' "$response")"
trap cleanup EXIT INT TERM
log "Created Vultr instance $INSTANCE_ID"

for _ in $(seq 1 90); do
  details="$WORK_DIR/instance-status.json"
  api GET "/instances/$INSTANCE_ID" > "$details"
  status="$(jq -r '.instance.status // ""' "$details")"
  server_status="$(jq -r '.instance.server_status // ""' "$details")"
  INSTANCE_IP="$(jq -r '.instance.main_ip // ""' "$details")"

  if [[ "$status" == "active" && "$server_status" == "ok" && "$INSTANCE_IP" != "0.0.0.0" && -n "$INSTANCE_IP" ]]; then
    break
  fi

  sleep 10
done

if [[ -z "$INSTANCE_IP" || "$INSTANCE_IP" == "0.0.0.0" ]]; then
  echo "Vultr instance did not receive an IPv4 address before timeout." >&2
  exit 1
fi

export RUNNER_NAME="vultr-$INSTANCE_ID"
export RUNNER_OS="Linux"
export RUNNER_ARCH="X64"

ssh_options=(
  -i "$KEY_FILE"
  -o BatchMode=yes
  -o ConnectTimeout=10
  -o StrictHostKeyChecking=accept-new
  -o "UserKnownHostsFile=$KNOWN_HOSTS"
)

log "Waiting for SSH and cloud-init on instance $INSTANCE_ID"
ssh_ready=false
for _ in $(seq 1 90); do
  if ssh "${ssh_options[@]}" "$SSH_USER@$INSTANCE_IP" true >/dev/null 2>&1; then
    ssh_ready=true
    break
  fi
  sleep 10
done

if [[ "$ssh_ready" != "true" ]]; then
  echo "Vultr instance did not become reachable over SSH before timeout." >&2
  exit 1
fi

ssh "${ssh_options[@]}" "$SSH_USER@$INSTANCE_IP" \
  'sudo cloud-init status --wait && docker version >/dev/null && docker compose version'

log "Checking out exact server commit $GITHUB_SHA"
ssh "${ssh_options[@]}" "$SSH_USER@$INSTANCE_IP" bash -s -- "$GITHUB_SHA" <<'REMOTE'
set -euo pipefail
sha="$1"
mkdir -p "$HOME/server"
cd "$HOME/server"
git init --quiet
git remote add origin https://github.com/durable-workflow/server.git
git fetch --quiet --depth=1 origin "$sha"
git checkout --quiet --detach FETCH_HEAD
test "$(git rev-parse HEAD)" = "$sha"
test -z "$(git status --porcelain --untracked-files=no)"
REMOTE

remote_env="$WORK_DIR/soak.env"
: > "$remote_env"
for name in \
  DW_PERF_DURATION_SECONDS \
  DW_PERF_CONCURRENCY \
  DW_PERF_NAMESPACES \
  DW_PERF_TASK_QUEUES \
  DW_PERF_REDIS_CACHE_DB \
  DW_PERF_MAX_SERVER_MEMORY_MB \
  DW_PERF_MAX_POLLING_KEYS \
  DW_PERF_MAX_FINAL_POLLING_KEYS \
  DW_PERF_MAX_SERVER_CACHE_KEYS_BY_POLICY \
  DW_PERF_MAX_FINAL_SERVER_CACHE_KEYS_BY_POLICY \
  DW_PERF_MAX_SERVER_MEMORY_SLOPE_MB_HOUR \
  DW_PERF_REMOTE_WRITE_ENABLED \
  DW_PERF_REMOTE_WRITE_URL \
  DW_PERF_REMOTE_WRITE_USERNAME \
  DW_PERF_REMOTE_WRITE_PASSWORD \
  DW_PERF_COMPOSE_PROJECT \
  DW_PERF_REQUIRE_TRUSTED_EVIDENCE \
  GITHUB_REPOSITORY \
  GITHUB_REF \
  GITHUB_SHA \
  GITHUB_WORKFLOW \
  GITHUB_EVENT_NAME \
  GITHUB_RUN_ID \
  GITHUB_RUN_ATTEMPT \
  GITHUB_JOB \
  RUNNER_NAME \
  RUNNER_OS \
  RUNNER_ARCH \
  DW_PERF_RUNNER_ENVIRONMENT
do
  printf 'export %s=%q\n' "$name" "${!name-}" >> "$remote_env"
done
printf 'export DW_PERF_SERVER_BIND=%q\n' '127.0.0.1' >> "$remote_env"
printf 'export DW_PERF_SERVER_PORT=%q\n' '18080' >> "$remote_env"
chmod 600 "$remote_env"
scp "${ssh_options[@]}" "$remote_env" "$SSH_USER@$INSTANCE_IP:soak.env" >/dev/null

log "Starting server soak on instance $INSTANCE_ID"
set +e
ssh "${ssh_options[@]}" "$SSH_USER@$INSTANCE_IP" \
  'chmod 600 "$HOME/soak.env" && source "$HOME/soak.env" && cd "$HOME/server" && scripts/perf/run-server-soak.sh'
soak_status=$?
set -e

mkdir -p build/perf
if ! ssh "${ssh_options[@]}" "$SSH_USER@$INSTANCE_IP" \
  'tar -C "$HOME/server/build/perf" -czf - .' | tar -C build/perf -xzf -; then
  log "WARNING: perf artifacts could not be copied from instance $INSTANCE_ID"
  if [[ "$soak_status" -eq 0 ]]; then
    soak_status=1
  fi
fi

log "Server soak finished with status $soak_status"
exit "$soak_status"
