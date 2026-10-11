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
chmod 0777 "$evidence" "$state" "$state/sdk"
cp "$profile/sdk/composer.json" "$profile/sdk/composer.lock" "$state/sdk/"
export DW_PARITY_RUNNER_REVISION
DW_PARITY_RUNNER_REVISION=$(git rev-parse HEAD)
limits=(--user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m)
shared=(-v "$PWD:/source:ro" -v "$evidence:/evidence" -v "$state/sdk:/sdk:ro" -w /source
  -e DW_PARITY_SDK_AUTOLOAD=/sdk/vendor/autoload.php -e DW_PARITY_RUNNER_REVISION)
case "$backend" in
  mysql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
      -h parity-mysql -uroot -pparity-disposable-password \
      -e 'CREATE DATABASE range_php_ref CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE range_embedded_ref CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' \
      > "$evidence/database-create.txt" 2>&1
    database=(-e DB_CONNECTION=mysql -e DB_HOST=parity-mysql -e DB_USERNAME=root -e DB_PASSWORD=parity-disposable-password) ;;
  pgsql)
    for db in range_php_ref range_embedded_ref; do
      docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
        --entrypoint createdb "$POSTGRES_IMAGE" -h parity-pg -U parity "$db" >> "$evidence/database-create.txt" 2>&1
    done
    database=(-e DB_CONNECTION=pgsql -e DB_HOST=parity-pg -e DB_USERNAME=parity -e DB_PASSWORD=parity-disposable-password) ;;
  sqlite)
    touch "$state/range_php_ref" "$state/range_embedded_ref"
    chmod 0666 "$state/range_php_ref" "$state/range_embedded_ref"
    database=(-e DB_CONNECTION=sqlite -v "$state:/range-state") ;;
  *) echo 'Choose mysql, pgsql or sqlite' >&2; exit 2 ;;
esac
docker run --name "$RESOURCE_SCOPE-range-sdk" "${limits[@]}" --entrypoint composer -e COMPOSER_HOME=/tmp/composer -v "$state/sdk:/sdk" -w /sdk "$image" \
  install --no-interaction --no-progress --no-cache > "$evidence/sdk-install.txt" 2>&1
printf '%s  %s\n' "$(jq -r '.sdk_php_composer_lock_sha256' "$profile/artifacts.json")" "$state/sdk/composer.lock" \
  | sha256sum -c > "$evidence/sdk-lock.txt"
docker run --name "$RESOURCE_SCOPE-range-tests" "${limits[@]}" --network none --entrypoint node "${shared[@]}" "$image" \
  --test tests/Unit/ServerParityRangeRepairContractTest.mjs > "$evidence/comparator-tests.txt" 2>&1
for target in php embedded; do
  db="range_${target}_ref"
  if test "$backend" = sqlite; then db="/range-state/$db"; fi
  mode=service
  if test "$target" = embedded; then mode=embedded; fi
  docker run --name "$RESOURCE_SCOPE-range-bootstrap-$target" "${limits[@]}" --network "$RESOURCE_SCOPE" "${database[@]}" -e DB_DATABASE="$db" \
    -e DW_MODE="$mode" -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e DW_AUTH_TOKEN=parity-test-token \
    "$image" php artisan server:bootstrap --force > "$evidence/$target-bootstrap.txt" 2>&1
done
php_db=range_php_ref
embedded_db=range_embedded_ref
if test "$backend" = sqlite; then
  php_db="/range-state/$php_db"
  embedded_db="/range-state/$embedded_db"
fi
docker run -d --name "$RESOURCE_SCOPE-range-server" "${limits[@]}" --network "$RESOURCE_SCOPE" \
  --network-alias parity-range-php "${database[@]}" -e DB_DATABASE="$php_db" \
  -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e DW_AUTH_TOKEN=parity-test-token "$image" \
  > "$evidence/server-id.txt"
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
selection=(--prefix range-repair-v1 --artifacts "/source/$profile/artifacts.json"
  --fixture "$profile/range-marked-activity-completed.json")
docker run --name "$RESOURCE_SCOPE-range-record-php" "${limits[@]}" --network "$RESOURCE_SCOPE" --entrypoint node "${shared[@]}" -e DW_PARITY_TOKEN=parity-test-token \
  "$image" scripts/conformance/server-parity.mjs record --mode http --url http://parity-range-php:8080 \
  --target php "${selection[@]}" --output /evidence/php.json > "$evidence/php-record.txt" 2>&1
docker run --name "$RESOURCE_SCOPE-range-record-embedded" "${limits[@]}" --network "$RESOURCE_SCOPE" "${shared[@]}" "${database[@]}" -e DB_DATABASE="$embedded_db" \
  -e DW_MODE=embedded -e CACHE_STORE=file -e QUEUE_CONNECTION=database -w /app "$image" \
  node /source/scripts/conformance/server-parity.mjs record --mode embedded --application-root /app \
  --target embedded "${selection[@]}" --output /evidence/embedded.json > "$evidence/embedded-record.txt" 2>&1
docker run --name "$RESOURCE_SCOPE-range-compare" "${limits[@]}" --network none --entrypoint node "${shared[@]}" "$image" scripts/conformance/server-parity.mjs compare \
  /evidence/php.json /evidence/embedded.json "${selection[@]}" > "$evidence/comparison.json" 2>&1
