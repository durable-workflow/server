#!/usr/bin/env bash
# Published PHP and embedded reference only; native range repair is unqualified.
# Reuse the owning shared-fixture job's isolated database service and network.
set -euo pipefail
backend="$1"
test -n "${RESOURCE_SCOPE:?Set the owning isolated network/resource scope}"
profile=tests/Fixtures/ServerParityProfiles/version-range-repair
image=$(jq -r '.server.images["linux/amd64"] | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' "$profile/artifacts.json")
evidence="$PWD/parity-evidence/version-range-repair"
state="$PWD/parity-range-state"
mkdir -p "$evidence" "$state/sdk"
cp "$profile/sdk/composer.json" "$profile/sdk/composer.lock" "$state/sdk/"
export DW_PARITY_RUNNER_REVISION
DW_PARITY_RUNNER_REVISION=$(git rev-parse HEAD)
limits=(--user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m)
shared=(-v "$PWD:/source:ro" -v "$evidence:/evidence" -v "$state/sdk:/sdk:ro" -w /source
  -e DW_PARITY_SDK_AUTOLOAD=/sdk/vendor/autoload.php -e DW_PARITY_RUNNER_REVISION)
containers=()
databases=0
server=0
run() {
  local name="$RESOURCE_SCOPE-range-$1"
  shift
  containers+=("$name")
  docker run --name "$name" "${limits[@]}" "$@"
}
cleanup() {
  local outcome=$?
  trap - EXIT
  if test "$server" = 1; then
    docker stop --timeout 30 "$RESOURCE_SCOPE-range-server" > "$evidence/server-stop.txt" 2>&1 || true
  fi
  for name in "${containers[@]}"; do
    docker logs "$name" > "$evidence/${name##*-range-}.log" 2>&1 || true
    docker inspect "$name" > "$evidence/${name##*-range-}-container.json" 2>&1 || true
    docker rm -f "$name" >/dev/null 2>&1 || true
  done
  if test "$databases" = 1; then
    case "$backend" in
      mysql)
        docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
          -h parity-mysql -uroot -pparity-disposable-password \
          -e 'DROP DATABASE IF EXISTS range_php_ref; DROP DATABASE IF EXISTS range_embedded_ref' > "$evidence/database-cleanup.txt" 2>&1 || true ;;
      pgsql)
        for db in range_php_ref range_embedded_ref; do
          docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
            --entrypoint dropdb "$POSTGRES_IMAGE" --if-exists -h parity-pg -U parity "$db" >> "$evidence/database-cleanup.txt" 2>&1 || true
        done ;;
    esac
  fi
  docker run --rm "${limits[@]}" --network none --entrypoint sh -v "$state:/range-state" "$image" \
    -c 'find /range-state -mindepth 1 -maxdepth 1 -exec rm -r -- {} +' > "$evidence/state-cleanup.txt" 2>&1 || true
  exit "$outcome"
}
trap cleanup EXIT
case "$backend" in
  mysql)
    databases=1
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
      -h parity-mysql -uroot -pparity-disposable-password \
      -e 'CREATE DATABASE range_php_ref CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE range_embedded_ref CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' \
      > "$evidence/database-create.txt" 2>&1
    database=(-e DB_CONNECTION=mysql -e DB_HOST=parity-mysql -e DB_USERNAME=root -e DB_PASSWORD=parity-disposable-password) ;;
  pgsql)
    databases=1
    for db in range_php_ref range_embedded_ref; do
      docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
        --entrypoint createdb "$POSTGRES_IMAGE" -h parity-pg -U parity "$db" >> "$evidence/database-create.txt" 2>&1
    done
    database=(-e DB_CONNECTION=pgsql -e DB_HOST=parity-pg -e DB_USERNAME=parity -e DB_PASSWORD=parity-disposable-password) ;;
  sqlite)
    touch "$state/range_php_ref" "$state/range_embedded_ref"
    database=(-e DB_CONNECTION=sqlite -v "$state:/range-state") ;;
  *) echo 'Choose mysql, pgsql or sqlite' >&2; exit 2 ;;
esac
run sdk --entrypoint composer -e COMPOSER_HOME=/tmp/composer -v "$state/sdk:/sdk" -w /sdk "$image" \
  install --no-interaction --no-progress --no-cache > "$evidence/sdk-install.txt" 2>&1
printf '%s  %s\n' "$(jq -r '.sdk_php_composer_lock_sha256' "$profile/artifacts.json")" "$state/sdk/composer.lock" \
  | sha256sum -c > "$evidence/sdk-lock.txt"
run tests --network none --entrypoint node "${shared[@]}" "$image" \
  --test tests/Unit/ServerParityRangeRepairContractTest.mjs > "$evidence/comparator-tests.txt" 2>&1
for target in php embedded; do
  db="range_${target}_ref"
  if test "$backend" = sqlite; then db="/range-state/$db"; fi
  mode=service
  if test "$target" = embedded; then mode=embedded; fi
  run "bootstrap-$target" --network "$RESOURCE_SCOPE" "${database[@]}" -e DB_DATABASE="$db" \
    -e DW_MODE="$mode" -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e DW_AUTH_TOKEN=parity-test-token \
    "$image" artisan server:bootstrap --force > "$evidence/$target-bootstrap.txt" 2>&1
done
php_db=range_php_ref
embedded_db=range_embedded_ref
if test "$backend" = sqlite; then
  php_db="/range-state/$php_db"
  embedded_db="/range-state/$embedded_db"
fi
containers+=("$RESOURCE_SCOPE-range-server")
docker run -d --name "$RESOURCE_SCOPE-range-server" "${limits[@]}" --network "$RESOURCE_SCOPE" \
  --network-alias parity-range-php "${database[@]}" -e DB_DATABASE="$php_db" \
  -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e DW_AUTH_TOKEN=parity-test-token "$image" \
  > "$evidence/server-id.txt"
server=1
ready=0
for attempt in $(seq 1 30); do
  if docker run --rm "${limits[@]}" --network "$RESOURCE_SCOPE" --entrypoint curl "$image" \
    -fsS http://parity-range-php:8080/api/ready > "$evidence/php-readiness.json" 2> "$evidence/php-readiness-error.txt"; then
    ready=1
    break
  fi
  sleep 1
done
test "$ready" = 1
selection=(--prefix range-repair-v1 --artifacts "$profile/artifacts.json"
  --fixture "$profile/range-marked-activity-completed.json")
run record-php --network "$RESOURCE_SCOPE" --entrypoint node "${shared[@]}" -e DW_PARITY_TOKEN=parity-test-token \
  "$image" scripts/conformance/server-parity.mjs record --mode http --url http://parity-range-php:8080 \
  --target php "${selection[@]}" --output /evidence/php.json > "$evidence/php-record.txt" 2>&1
run record-embedded --network "$RESOURCE_SCOPE" "${shared[@]}" "${database[@]}" -e DB_DATABASE="$embedded_db" \
  -e DW_MODE=embedded -e CACHE_STORE=file -e QUEUE_CONNECTION=database -w /app "$image" \
  node /source/scripts/conformance/server-parity.mjs record --mode embedded --application-root /app \
  --target embedded "${selection[@]}" --output /evidence/embedded.json > "$evidence/embedded-record.txt" 2>&1
run compare --network none --entrypoint node "${shared[@]}" "$image" scripts/conformance/server-parity.mjs compare \
  /evidence/php.json /evidence/embedded.json "${selection[@]}" > "$evidence/comparison.json" 2>&1
