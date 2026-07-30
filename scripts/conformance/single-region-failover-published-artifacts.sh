#!/usr/bin/env bash
set -euo pipefail

usage() {
  cat <<'USAGE'
Usage: single-region-failover-published-artifacts.sh [--result-dir DIR] [--keep-stack]

Runs the bounded single-region failover rehearsal using public container
artifacts only. DW_SERVER_IMAGE is required and must name a concrete public
durableworkflow/server tag or digest. The runner pulls every image, resolves it
to an immutable repository digest, rejects Compose builds and source mounts,
and writes single-region-failover-result.json.

Required:
  DW_SERVER_IMAGE                 Public durableworkflow/server tag or digest.

Optional exact supporting artifacts:
  DW_FAILOVER_MYSQL_SOURCE_IMAGE  Defaults to mysql:8.0.42.
  DW_FAILOVER_REDIS_SOURCE_IMAGE  Defaults to redis:7.4.3-alpine.
  DW_FAILOVER_NGINX_SOURCE_IMAGE  Defaults to nginx:1.27.4-alpine.
  DW_FAILOVER_RESULT_DIR          Alternative to --result-dir.
  DW_FAILOVER_MODE                full or bounded (same required failure cells;
                                  bounded uses the contract's CI time limits).
  DW_FAILOVER_CONNECT_HOST        Hostname or IP used to reach all three
                                  published endpoints. Defaults to 127.0.0.1.
  DW_FAILOVER_SERVER_A_PORT       Published server A port. Defaults to 18084.
  DW_FAILOVER_SERVER_B_PORT       Published server B port. Defaults to 18085.
  DW_FAILOVER_LB_PORT             Published load-balancer port. Defaults to 18086.
  DW_FAILOVER_KEEP_STACK=1        Keep the Compose stack for inspection.
USAGE
}

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"
compose_file="$repo_root/docker-compose.failover-rehearsal.yml"
result_dir="${DW_FAILOVER_RESULT_DIR:-}"
keep_stack="${DW_FAILOVER_KEEP_STACK:-0}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --result-dir)
      result_dir="${2:?--result-dir requires a directory}"
      shift 2
      ;;
    --result-dir=*) result_dir="${1#*=}"; shift ;;
    --keep-stack) keep_stack=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'unknown argument: %s\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
done

if [[ -z "$result_dir" ]]; then
  result_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-single-region-failover.XXXXXX")"
fi
mkdir -p "$result_dir"
result_dir="$(cd "$result_dir" && pwd)"

emit_preflight_failure() {
  local reason="$1"
  DW_FAILOVER_PREFLIGHT_REASON="$reason" python3 - "$result_dir/single-region-failover-result.json" <<'PY'
import datetime, json, os, sys
now = datetime.datetime.now(datetime.timezone.utc).isoformat().replace('+00:00', 'Z')
reason = os.environ['DW_FAILOVER_PREFLIGHT_REASON']
runner_blocked = (
    reason.startswith('required command not found:')
    or reason.startswith('could not pull public')
    or reason == 'Docker Compose v2 is required'
)
payload = {
    'schema': 'durable-workflow.v2.single-region-failover.result',
    'version': 1,
    'outcome': 'fail',
    'started_at': now,
    'finished_at': now,
    'runner_blocked': runner_blocked,
    'artifacts': {'server_image_requested': os.environ.get('DW_SERVER_IMAGE')},
    'phase_outcomes': {
        'published_artifact_provenance': {
            'status': 'fail',
            'reason': reason,
        },
    },
    'local_product_source_checkouts_used': False,
}
with open(sys.argv[1], 'w', encoding='utf-8') as handle:
    json.dump(payload, handle, indent=2, sort_keys=True)
    handle.write('\n')
PY
  printf '%s\n' "$reason" >&2
  printf 'Result: %s\n' "$result_dir/single-region-failover-result.json" >&2
  exit 1
}

command -v python3 >/dev/null 2>&1 || {
  printf '%s\n' 'required command not found: python3' >&2
  exit 127
}

server_image="${DW_SERVER_IMAGE:-}"
[[ -n "$server_image" ]] || emit_preflight_failure 'DW_SERVER_IMAGE is required; local and implicit server images are forbidden'

case "$server_image" in
  durableworkflow/server:*|durableworkflow/server@sha256:*|docker.io/durableworkflow/server:*|docker.io/durableworkflow/server@sha256:*|index.docker.io/durableworkflow/server:*|index.docker.io/durableworkflow/server@sha256:*) ;;
  *) emit_preflight_failure "server image must use the public durableworkflow/server repository: $server_image" ;;
esac

if [[ "$server_image" =~ (^|[:/@._-])(latest|current|head|local|dev|snapshot)([:/@._-]|$) ]]; then
  emit_preflight_failure "rolling or local server image references are forbidden: $server_image"
fi

if [[ "$server_image" != *@sha256:* ]]; then
  server_tag="${server_image##*:}"
  if [[ ! "$server_tag" =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-((0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*)(\.(0|[1-9][0-9]*|[0-9A-Za-z-]*[A-Za-z-][0-9A-Za-z-]*))*))?$ ]]; then
    emit_preflight_failure "server image tag must be an exact SemVer release: $server_image"
  fi
fi

command -v docker >/dev/null 2>&1 || emit_preflight_failure 'required command not found: docker'
docker compose version >/dev/null 2>&1 || emit_preflight_failure 'Docker Compose v2 is required'

resolve_public_image() {
  local requested="$1"
  local label="$2"
  local resolved

  docker pull --quiet "$requested" >/dev/null || emit_preflight_failure "could not pull public $label image: $requested"
  resolved="$(docker image inspect --format '{{join .RepoDigests "\n"}}' "$requested" | head -n 1)"
  [[ "$resolved" == *@sha256:* ]] || emit_preflight_failure "$label image did not resolve to a public repository digest: $requested"
  printf '%s' "$resolved"
}

mysql_requested="${DW_FAILOVER_MYSQL_SOURCE_IMAGE:-mysql:8.0.42}"
redis_requested="${DW_FAILOVER_REDIS_SOURCE_IMAGE:-redis:7.4.3-alpine}"
nginx_requested="${DW_FAILOVER_NGINX_SOURCE_IMAGE:-nginx:1.27.4-alpine}"

export DW_FAILOVER_SERVER_IMAGE="$(resolve_public_image "$server_image" server)"
export DW_FAILOVER_MYSQL_IMAGE="$(resolve_public_image "$mysql_requested" mysql)"
export DW_FAILOVER_REDIS_IMAGE="$(resolve_public_image "$redis_requested" redis)"
export DW_FAILOVER_NGINX_IMAGE="$(resolve_public_image "$nginx_requested" load-balancer)"
export DW_FAILOVER_SERVER_VERSION="${server_image##*:}"
if [[ "$server_image" == *@sha256:* ]]; then
  export DW_FAILOVER_SERVER_VERSION="digest:${server_image##*@sha256:}"
fi

export DW_FAILOVER_SERVER_IMAGE_REQUESTED="$server_image"
export DW_FAILOVER_MYSQL_IMAGE_REQUESTED="$mysql_requested"
export DW_FAILOVER_REDIS_IMAGE_REQUESTED="$redis_requested"
export DW_FAILOVER_NGINX_IMAGE_REQUESTED="$nginx_requested"
export DW_FAILOVER_DOCKER_VERSION="$(docker version --format '{{.Server.Version}}')"
export DW_FAILOVER_COMPOSE_VERSION="$(docker compose version --short)"
export DW_FAILOVER_BASH_VERSION="$BASH_VERSION"
export DW_FAILOVER_RUNNER_VERSION="10"
export DW_FAILOVER_RESULT_DIR="$result_dir"
export DW_FAILOVER_COMPOSE_FILE="$compose_file"
export DW_FAILOVER_KEEP_STACK="$keep_stack"
export DW_FAILOVER_MODE="${DW_FAILOVER_MODE:-full}"
export DW_FAILOVER_PROJECT="${DW_FAILOVER_PROJECT:-dw-failover-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-rehearsal}-$$}"

python3 "$script_dir/single-region-failover-published-artifacts.py"
