#!/usr/bin/env bash
# Smoke-test one platform of the native server image (Server #325) and write
# a receipt. Usage: native-image-smoke.sh <image reference> <platform> <receipt path>
#
# Checks:
# - the image config: non-root 1000:1000, SIGTERM, port 8080, entrypoint and
#   the PHP image's /app/database and /app/storage paths;
# - that the server refuses to start without the explicit opt-in;
# - a cold start on SQLite, PostgreSQL 17 and MySQL 8.0, with /api/ready and
#   an authenticated /api/cluster/info;
# - a graceful SIGTERM stop with exit code 0;
# - that the takeover commands answer, refusing a missing source rather
#   than creating one.
set -euo pipefail
# A failure inside $(serve ...) must stop the run too.
shopt -s inherit_errexit
test "$#" = 3 || { echo "usage: $0 <image> <platform> <receipt>" >&2; exit 2; }
image=$1
platform=$2
receipt=$3
mkdir -p "$(dirname "$receipt")"
probe=mirror.gcr.io/library/python@sha256:bf44cdfcb76cd3b41e879bc058fc37ec5872002ccfde7fcb765e218cde0cd79c
postgres=mirror.gcr.io/library/postgres@sha256:f1c3376c26f2609ab9f29f71f824103fe2fcd8ee0346485cb6122a4f93df6f94
# MySQL 8.0 as its multi-architecture index: linux/amd64 is the 62fb722c…
# image the Shared Server fixtures pin, linux/arm64 is 213bbfaf….
mysql=mirror.gcr.io/library/mysql@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b
# Every named container and the network derive from RESOURCE_SCOPE: the
# workflow sets it per run, attempt and job and removes them again in an
# always step. A local run gets its own scope.
scope_identity="${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-native-smoke}"
if test -z "${RESOURCE_SCOPE:-}"; then
  RESOURCE_SCOPE="dw-native-$scope_identity-$(date -u +%Y%m%d%H%M%S)-$$"
fi
export RESOURCE_SCOPE
token=native-smoke-token
password=native-smoke-disposable-password
state=$(mktemp -d)
chmod 0777 "$state"
# The workflow's always step removes the containers and the network.
trap 'rm -rf -- "$state"' EXIT
# CI smoke-tests the pushed digest; a local build is already present.
docker image inspect "$image" >/dev/null 2>&1 || docker pull --platform "$platform" "$image" >/dev/null
docker network create "$RESOURCE_SCOPE" >/dev/null

# The image config.
config=$(docker image inspect --format '{{json .Config}}' "$image")
jq -e '.User=="1000:1000" and .StopSignal=="SIGTERM" and (.ExposedPorts|has("8080/tcp"))
  and .Entrypoint==["/usr/local/bin/durable-workflow-server"]
  and .WorkingDir=="/app" and (.Volumes|keys)==["/app/database","/app/storage"]
  and (.Env|index("DB_DATABASE=/app/database/database.sqlite")!=null)
  and (.Env|index("DW_BIND_ADDRESS=0.0.0.0:8080")!=null)
  and (.Env|index("DW_RUST_MAINTENANCE_LOOP=1")!=null)
  and ([.Env[]|select(startswith("DW_RUST_EXPERIMENTAL="))]|length)==0' <<<"$config" >/dev/null

# A release build (labelled with its native- tag, its version built in)
# starts without the opt-in; a development build refuses to start without
# DW_RUST_EXPERIMENTAL=1.
release_tag=$(docker image inspect --format '{{index .Config.Labels "dev.durable-workflow.release.tag"}}' "$image")
optin=()
if [[ -z "$release_tag" || "$release_tag" == "<no value>" ]]; then
  status=0
  refusal=$(docker run --rm --platform "$platform" -e DW_AUTH_TOKEN=$token "$image" 2>&1) || status=$?
  test "$status" != 0
  grep -q 'requires DW_RUST_EXPERIMENTAL=1' <<<"$refusal"
  optin=(-e DW_RUST_EXPERIMENTAL=1)
fi

# GET <path> from <host> port 8080 inside the job's network, with the bearer
# token when a third argument is given; prints the body and fails on any
# non-2xx. The server answers plain HTTP on that private network.
get() {
  local host=$1 path=$2
  shift 2
  docker run --rm --network "$RESOURCE_SCOPE" --entrypoint python "$probe" -I -c '
import sys, http.client
connection = http.client.HTTPConnection(sys.argv[1], 8080, timeout=5)
headers = {"Authorization": "Bearer " + sys.argv[3]} if len(sys.argv) > 3 else {}
connection.request("GET", sys.argv[2], headers=headers)
response = connection.getresponse()
body = response.read().decode()
if not 200 <= response.status < 300:
    sys.exit("HTTP %d: %s" % (response.status, body[:200]))
print(body)
' "$host" "$path" "$@"
}

wait_ready() {
  local host=$1
  for _ in $(seq 1 60); do
    if get "$host" /api/ready >/dev/null 2>&1; then
      return 0
    fi
    sleep 1
  done
  docker logs --tail 40 "$host" >&2 || true
  return 1
}

# Start the server on one backend, read readiness and cluster info, stop it
# with SIGTERM and require a clean exit. Prints the backend's receipt line.
serve() {
  local backend=$1; shift
  docker run -d --platform "$platform" --name "$RESOURCE_SCOPE-native-$backend" --network "$RESOURCE_SCOPE" \
    "${optin[@]}" -e DW_AUTH_TOKEN=$token "$@" "$image" >/dev/null
  wait_ready "$RESOURCE_SCOPE-native-$backend"
  local info
  info=$(get "$RESOURCE_SCOPE-native-$backend" /api/cluster/info "$token")
  started=$(date +%s)
  docker stop --time 20 "$RESOURCE_SCOPE-native-$backend" >/dev/null
  local exit_code
  exit_code=$(docker inspect --format '{{.State.ExitCode}}' "$RESOURCE_SCOPE-native-$backend")
  test "$exit_code" = 0
  jq -cn --arg backend "$backend" --argjson info "$info" --argjson seconds "$(( $(date +%s) - started ))" \
    '{backend:$backend, ready:true, cluster_info_keys:($info|keys|length), sigterm_exit:0, stop_seconds:$seconds}'
}

results=()
results+=("$(serve sqlite -v "$state:/app/database")")

docker run -d --name "$RESOURCE_SCOPE-postgres" --network "$RESOURCE_SCOPE" \
  -e POSTGRES_USER=native -e POSTGRES_PASSWORD=$password -e POSTGRES_DB=native "$postgres" >/dev/null
until docker exec "$RESOURCE_SCOPE-postgres" pg_isready -h 127.0.0.1 -U native -d native >/dev/null 2>&1; do sleep 1; done
results+=("$(serve pgsql -e DB_CONNECTION=pgsql -e DB_HOST="$RESOURCE_SCOPE-postgres" -e DB_PORT=5432 \
  -e DB_DATABASE=native -e DB_USERNAME=native -e DB_PASSWORD=$password)")

docker run -d --name "$RESOURCE_SCOPE-mysql" --network "$RESOURCE_SCOPE" \
  -e MYSQL_ROOT_PASSWORD=$password -e MYSQL_ROOT_HOST=% -e MYSQL_DATABASE=native "$mysql" >/dev/null
until docker exec "$RESOURCE_SCOPE-mysql" mysql --protocol=TCP -h127.0.0.1 -uroot -p$password -e 'select 1' native >/dev/null 2>&1; do sleep 2; done
results+=("$(serve mysql -e DB_CONNECTION=mysql -e DB_HOST="$RESOURCE_SCOPE-mysql" -e DB_PORT=3306 \
  -e DB_DATABASE=native -e DB_USERNAME=root -e DB_PASSWORD=$password)")

# The takeover commands are present and refuse a source that does not exist,
# without creating it in the mounted state directory.
status=0
missing=$(docker run --rm --platform "$platform" -v "$state:/app/database" "${optin[@]}" \
  -e DB_CONNECTION=sqlite -e DB_DATABASE=/app/database/missing.sqlite "$image" upgrade-inspect 2>&1) || status=$?
test "$status" != 0
grep -qx 'upgrade_inspection_unavailable' <<<"$missing"
test ! -e "$state/missing.sqlite"
test -e "$state/database.sqlite"

digest=$(docker image inspect --format '{{.Id}}' "$image")
printf '%s\n' "${results[@]}" | jq -s --arg image "$image" --arg platform "$platform" --arg id "$digest" \
  '{schema:"durable-workflow.native-image-smoke.v1", image:$image, platform:$platform, image_id:$id,
    config:{user:"1000:1000", stop_signal:"SIGTERM", port:8080, opt_in_required:true},
    backends:., takeover_commands:"present"}' > "$receipt"
cat "$receipt"
