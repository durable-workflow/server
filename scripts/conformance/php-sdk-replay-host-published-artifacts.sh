#!/usr/bin/env bash
set -Eeuo pipefail

# Native has no language toolchains. Keep the existing PHP matrix and its
# worker/client process boundaries together in one published tooling container.
if [[ $# != 2 || "$1" != --result-dir || -z "$2" ]]; then
  echo 'Usage: php-sdk-replay-host-published-artifacts.sh --result-dir DIR' >&2
  exit 2
fi
server_image="${DW_SERVER_IMAGE:-}"
runner_image="${DW_REPLAY_PHP_RUNNER_IMAGE:-}"
mysql_image="${DW_WORKFLOW_LIFECYCLE_MYSQL_IMAGE:-}"
if [[ ! "${DW_SERVER_VERSION:-}" =~ ^3\.[0-9]+\.[0-9]+$ \
  || ! "${DW_PHP_SDK_VERSION:-}" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ \
  || ! "$server_image" =~ ^(docker.io/)?durableworkflow/server@sha256:[0-9a-f]{64}$ \
  || ! "$runner_image" =~ ^ghcr.io/durable-workflow/sample-app-devcontainer@sha256:[0-9a-f]{64}$ \
  || ! "$mysql_image" =~ ^(docker.io/library/)?mysql@sha256:[0-9a-f]{64}$ ]]; then
  echo 'PHP replay requires exact Native/SDK versions and digest-pinned Server, MySQL and published tooling images.' >&2
  exit 2
fi
scope="${DW_JOB_RESOURCE_PREFIX:-php-replay-$$}"
if [[ ! "$scope" =~ ^[A-Za-z0-9][A-Za-z0-9_.-]{0,90}$ ]]; then
  echo 'Invalid job resource prefix.' >&2
  exit 2
fi
script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd -P)"
mkdir -p "$2"
result_dir="$(cd "$2" && pwd -P)"
if [[ -e "$result_dir/php-sdk-conformance-result.json" ]]; then
  echo 'Refusing an existing PHP result.' >&2
  exit 2
fi
network="dw-php-replay-$scope"
server="dw-php-replay-server-$scope"
probe="dw-php-replay-probe-$scope"
mysql="dw-php-replay-mysql-$scope"
label="dw.conformance.php-replay=$scope"
creating=''
query() {
  local value
  value="$(docker "$@" 2>"$result_dir/php-replay-query.err")" || return 1
  test ! -s "$result_dir/php-replay-query.err" || return 1
  printf '%s' "$value"
}
# A failed create must not make an existing foreign object eligible for cleanup.
for name in "$server" "$probe" "$mysql"; do
  existing="$(query container ls -aq --filter "name=^/${name}$")"
  if [[ -n "$existing" ]]; then
    echo "Container name is already in use: $name" >&2; exit 2
  fi
done
existing="$(query network ls -q --filter "name=^${network}$")"
if [[ -n "$existing" ]]; then
  echo "Network name is already in use: $network" >&2; exit 2
fi

cleanup() {
  local status=$? residue=0 name owner remaining
  trap - EXIT
  # A timed-out create may still commit in the daemon. Do not report clean
  # custody from an immediate empty list after an unproved create.
  if [[ -n "$creating" ]]; then sleep 12; residue=1; fi
  for name in "$probe" "$server" "$mysql"; do
    if ! remaining="$(query container ls -aq --filter "name=^/${name}$")"; then residue=1; continue; fi
    if [[ -n "$remaining" ]]; then
      if ! owner="$(query container inspect --format '{{ index .Config.Labels "dw.conformance.php-replay" }}' "$name")"; then residue=1; continue; fi
      if [[ "$owner" == "$scope" ]]; then
        docker logs "$name" >"$result_dir/${name}.log" 2>&1 || residue=1
        if [[ "$name" == "$probe" ]]; then
          docker rm -fv "$probe" >/dev/null || residue=1
        elif [[ "$name" == "$server" ]]; then
          docker rm -fv "$server" >/dev/null || residue=1
        else
          docker rm -fv "$mysql" >/dev/null || residue=1
        fi
      else
        residue=1
      fi
    fi
  done
  if ! remaining="$(query network ls -q --filter "name=^${network}$")"; then residue=1; remaining=''; fi
  if [[ -n "$remaining" ]]; then
    if ! owner="$(query network inspect --format '{{ index .Labels "dw.conformance.php-replay" }}' "$network")"; then residue=1; owner=''; fi
    if [[ "$owner" == "$scope" ]]; then docker network rm "$network" >/dev/null || residue=1; else residue=1; fi
  fi
  if ! remaining="$(query container ls -aq --filter "label=$label")" || [[ -n "$remaining" ]]; then residue=1; fi
  if ! remaining="$(query network ls -q --filter "label=$label")" || [[ -n "$remaining" ]]; then residue=1; fi
  printf 'cleanup=%s\n' "$residue" >"$result_dir/php-replay-cleanup.txt"
  if (( residue )); then exit 70; fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

printf '{"server_version":"%s","server_image":"%s","php_sdk_version":"%s","tooling_image":"%s","mysql_image":"%s","backend":"mysql"}\n' \
  "$DW_SERVER_VERSION" "$server_image" "$DW_PHP_SDK_VERSION" "$runner_image" "$mysql_image" \
  >"$result_dir/php-replay-pins.json"
docker pull "$server_image"
docker pull "$runner_image"
docker pull "$mysql_image"
docker image inspect "$server_image" >"$result_dir/php-replay-server-image.json"
docker image inspect "$runner_image" >"$result_dir/php-replay-tooling-image.json"
docker image inspect "$mysql_image" >"$result_dir/php-replay-mysql-image.json"
docker network create --label "$label" "$network" >/dev/null
creating="$mysql"
docker create --name "$mysql" --label "$label" --network "$network" \
  --cpus=1 --memory=2g --pids-limit=512 \
  --tmpfs /var/lib/mysql:rw \
  -e MYSQL_DATABASE=durable_workflow -e MYSQL_USER=workflow \
  -e MYSQL_PASSWORD=workflow -e MYSQL_ROOT_PASSWORD=php-replay-root \
  "$mysql_image" >"$result_dir/php-replay-mysql-id.txt"
grep -Ex '[0-9a-f]{64}' "$result_dir/php-replay-mysql-id.txt" >/dev/null
creating=''
docker start "$mysql" >/dev/null
ready=0
deadline=$((SECONDS + 120))
while (( SECONDS < deadline )); do
  # The entrypoint's temporary init server does not listen on TCP.
  if timeout 5 docker exec "$mysql" mysqladmin --protocol=TCP -h127.0.0.1 \
    -uroot -pphp-replay-root ping --silent >"$result_dir/php-replay-mysql-ready.log" 2>&1; then
    ready=1; break
  fi
  sleep 1
done
test "$ready" = 1
creating="$server"
docker create --name "$server" --label "$label" --network "$network" \
  --cpus=1 --memory=2g --pids-limit=512 --cap-drop=ALL \
  --security-opt=no-new-privileges --read-only \
  --tmpfs /app/database:rw,uid=1000,gid=1000,mode=0700 \
  --tmpfs /app/storage:rw,uid=1000,gid=1000,mode=0700 \
  --tmpfs /tmp:rw,mode=1777 \
  -e DB_CONNECTION=mysql -e DB_HOST="$mysql" -e DB_PORT=3306 \
  -e DB_DATABASE=durable_workflow -e DB_USERNAME=workflow -e DB_PASSWORD=workflow \
  -e DW_BIND_ADDRESS=0.0.0.0:8080 -e DW_AUTH_DRIVER=token \
  -e DW_AUTH_TOKEN=php-replay-control -e DW_WORKER_TOKEN=php-replay-worker \
  "$server_image" >"$result_dir/php-replay-server-id.txt"
grep -Ex '[0-9a-f]{64}' "$result_dir/php-replay-server-id.txt" >/dev/null
creating=''
docker start "$server" >/dev/null

creating="$probe"
docker create --name "$probe" --label "$label" --network "$network" \
  --user "$(id -u):$(id -g)" --cpus=2 --memory=2g --pids-limit=512 \
  --cap-drop=ALL --security-opt=no-new-privileges --read-only --tmpfs /tmp:rw,exec,mode=1777 \
  --mount "type=bind,src=$script_dir,dst=/runner,readonly" \
  --mount "type=bind,src=$result_dir,dst=/result" \
  -e HOME=/tmp/php-replay-home \
  -e DW_SERVER_VERSION -e DW_SERVER_IMAGE -e DW_PHP_SDK_VERSION \
  -e DW_PHP_SDK_CONFORMANCE_SERVER_URL="http://$server:8080" \
  -e DW_PHP_SDK_CONFORMANCE_NAMESPACE=php-replay-conformance \
  -e DW_PHP_SDK_CONFORMANCE_TOKEN=php-replay-control \
  -e DW_PHP_SDK_CONFORMANCE_WORKER_TOKEN=php-replay-worker \
  -e DW_PHP_SDK_CONFORMANCE_REPLAY_MATRIX=1 \
  --entrypoint bash "$runner_image" -euc '
    mkdir -p -m 0700 "$HOME/bin"
    # The image PHP has a low-port file capability, which cannot execute with
    # our empty bounding set. Use the same bytes without extended attributes.
    original_php="$(readlink -f "$(command -v php)")"
    cp --no-preserve=all "$original_php" "$HOME/bin/php"
    chmod 0755 "$HOME/bin/php"
    cmp "$original_php" "$HOME/bin/php"
    test -z "$(getcap "$HOME/bin/php")"
    getcap "$original_php" > /result/php-replay-interpreter.txt
    sha256sum "$original_php" "$HOME/bin/php" >> /result/php-replay-interpreter.txt
    awk "/^(CapBnd|CapEff|NoNewPrivs):/" /proc/self/status >> /result/php-replay-interpreter.txt
    export PATH="$HOME/bin:$PATH"
    for command in php composer node python3 curl; do command -v "$command"; done > /result/php-replay-tools.txt
    php --version >> /result/php-replay-tools.txt
    composer --version >> /result/php-replay-tools.txt
    ready=0
    for attempt in $(seq 1 90); do
      if curl --fail --silent --max-time 2 "$DW_PHP_SDK_CONFORMANCE_SERVER_URL/api/ready" > /result/php-replay-ready.json; then ready=1; break; fi
      sleep 1
    done
    test "$ready" = 1
    curl --fail --silent --show-error --max-time 15 \
      -H "Authorization: Bearer $DW_PHP_SDK_CONFORMANCE_TOKEN" \
      -H "Content-Type: application/json" -H "X-Namespace: default" \
      -H "X-Durable-Workflow-Control-Plane-Version: 2" \
      --data "{\"name\":\"$DW_PHP_SDK_CONFORMANCE_NAMESPACE\",\"retention_days\":7}" \
      "$DW_PHP_SDK_CONFORMANCE_SERVER_URL/api/namespaces" > /result/php-replay-namespace.json
    bash /runner/php-sdk-published-artifacts.sh --result-dir /result
    python3 /runner/php-sdk-replay-result.py /result
  ' >"$result_dir/php-replay-probe-id.txt"
grep -Ex '[0-9a-f]{64}' "$result_dir/php-replay-probe-id.txt" >/dev/null
creating=''
docker start --attach "$probe" >"$result_dir/php-replay-shard.log" 2>&1
test "$(query container inspect --format '{{.State.Status}}' "$probe")" = exited
test "$(query container inspect --format '{{.State.ExitCode}}' "$probe")" = 0
