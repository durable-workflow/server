#!/usr/bin/env bash
# Three implementations in independent databases, with the exact published author.
# Reuse the owning shared-fixture job's isolated database service and network.
set -euo pipefail
backend="$1"
checkpoint="${3:-completed}"
case "$checkpoint" in
  completed) tag=range; prefix=range-repair-v1; fixture=range-marked-activity-completed ;;
  pending-reference) tag=range-pending; prefix=range-pending-v1; fixture=range-selected-timer-activity-pending ;;
  *) echo 'Choose completed or pending-reference' >&2; exit 2 ;;
esac
native_url="${2:?Set the independently isolated native URL}"
test -n "${RESOURCE_SCOPE:?Set the owning isolated network/resource scope}"
profile=tests/Fixtures/ServerParityProfiles/version-range-repair
image=$(jq -r '.server.images["linux/amd64"] | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' "$profile/artifacts.json")
cohort=version-range-repair
if test "$checkpoint" = pending-reference; then cohort=version-range-repair-pending; fi
manifest="tests/Fixtures/ServerParityProfiles/$cohort/artifacts.json"
php_alias="parity-$tag-php"
resource="$RESOURCE_SCOPE-$tag"
php_database="$(printf '%s' "$tag" | tr '-' '_')_php_ref"
embedded_database="$(printf '%s' "$tag" | tr '-' '_')_embedded_ref"
evidence="$PWD/parity-evidence/$cohort"
state="$PWD/parity-$tag-state"
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
      -e "CREATE DATABASE $php_database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE DATABASE $embedded_database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" \
      > "$evidence/database-create.txt" 2>&1
    database=(-e DB_CONNECTION=mysql -e DB_HOST=parity-mysql -e DB_USERNAME=root -e DB_PASSWORD=parity-disposable-password) ;;
  pgsql)
    for db in "$php_database" "$embedded_database"; do
      docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
        --entrypoint createdb "$POSTGRES_IMAGE" -h parity-pg -U parity "$db" >> "$evidence/database-create.txt" 2>&1
    done
    database=(-e DB_CONNECTION=pgsql -e DB_HOST=parity-pg -e DB_USERNAME=parity -e DB_PASSWORD=parity-disposable-password) ;;
  sqlite)
    touch "$state/$php_database" "$state/$embedded_database"
    chmod 0666 "$state/$php_database" "$state/$embedded_database"
    database=(-e DB_CONNECTION=sqlite -v "$state:/range-state") ;;
  *) echo 'Choose mysql, pgsql or sqlite' >&2; exit 2 ;;
esac
docker run --name "$resource-sdk" "${limits[@]}" --entrypoint composer -e COMPOSER_HOME=/tmp/composer -v "$state/sdk:/sdk" -w /sdk "$image" \
  install --no-interaction --no-progress --no-cache > "$evidence/sdk-install.txt" 2>&1
printf '%s  %s\n' "$(jq -r '.sdk_php_composer_lock_sha256' "$profile/artifacts.json")" "$state/sdk/composer.lock" \
  | sha256sum -c > "$evidence/sdk-lock.txt"
reader_tests=(tests/Unit/ServerParityRangeRepairContractTest.mjs)
if test "$checkpoint" = pending-reference; then reader_tests+=(tests/Unit/ServerParityPendingRangeRepairContractTest.mjs); fi
docker run --name "$resource-tests" "${limits[@]}" --network none --entrypoint node "${shared[@]}" "$image" \
  --test "${reader_tests[@]}" > "$evidence/comparator-tests.txt" 2>&1
for target in php embedded; do
  db="$php_database"
  if test "$target" = embedded; then db="$embedded_database"; fi
  if test "$backend" = sqlite; then db="/range-state/$db"; fi
  mode=service
  if test "$target" = embedded; then mode=embedded; fi
  docker run --name "$resource-bootstrap-$target" "${limits[@]}" --network "$RESOURCE_SCOPE" "${database[@]}" -e DB_DATABASE="$db" \
    -e DW_MODE="$mode" -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e DW_AUTH_TOKEN=parity-test-token \
    "$image" php artisan server:bootstrap --force > "$evidence/$target-bootstrap.txt" 2>&1
done
php_db="$php_database"
embedded_db="$embedded_database"
if test "$backend" = sqlite; then
  php_db="/range-state/$php_db"
  embedded_db="/range-state/$embedded_db"
fi
docker run -d --name "$resource-server" "${limits[@]}" --network "$RESOURCE_SCOPE" \
  --network-alias "$php_alias" "${database[@]}" -e DB_DATABASE="$php_db" \
  -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e DW_AUTH_TOKEN=parity-test-token "$image" \
  > "$evidence/server-id.txt"
ready=0
for attempt in $(seq 1 30); do
  if docker run --rm "${limits[@]}" --network "$RESOURCE_SCOPE" --entrypoint curl "$image" \
    -fsS "http://$php_alias:8080/api/ready" > "$evidence/php-readiness.json" 2> "$evidence/php-readiness-error.txt"; then
    ready=1
    break
  fi
  sleep 1
done
test "$ready" = 1
selection=(--prefix "$prefix" --artifacts "/source/$manifest"
  --fixture "$profile/$fixture.json")
docker run --name "$resource-record-php" "${limits[@]}" --network "$RESOURCE_SCOPE" --entrypoint node "${shared[@]}" -e DW_PARITY_TOKEN=parity-test-token \
  "$image" scripts/conformance/server-parity.mjs record --mode http --url "http://$php_alias:8080" \
  --target php "${selection[@]}" --output /evidence/php.json > "$evidence/php-record.txt" 2>&1
docker run --name "$resource-record-embedded" "${limits[@]}" --network "$RESOURCE_SCOPE" "${shared[@]}" "${database[@]}" -e DB_DATABASE="$embedded_db" \
  -e DW_MODE=embedded -e CACHE_STORE=file -e QUEUE_CONNECTION=database -w /app "$image" \
  node /source/scripts/conformance/server-parity.mjs record --mode embedded --application-root /app \
  --target embedded "${selection[@]}" --output /evidence/embedded.json > "$evidence/embedded-record.txt" 2>&1
if test "$checkpoint" = completed; then
  docker run --name "$resource-record-native" "${limits[@]}" --network "$RESOURCE_SCOPE" --entrypoint node "${shared[@]}" -e DW_PARITY_TOKEN=parity-test-token \
  "$image" scripts/conformance/server-parity.mjs record --mode http --url "$native_url" \
  --target rust "${selection[@]}" --output /evidence/rust.json > "$evidence/rust-record.txt" 2>&1
  recordings=(/evidence/php.json /evidence/embedded.json /evidence/rust.json)
else
  recordings=(/evidence/php.json /evidence/embedded.json)
fi
docker run --name "$resource-compare" "${limits[@]}" --network none --entrypoint node "${shared[@]}" "$image" scripts/conformance/server-parity.mjs compare \
  "${recordings[@]}" "${selection[@]}" > "$evidence/comparison.json" 2>&1
