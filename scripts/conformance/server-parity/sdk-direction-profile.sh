#!/usr/bin/env bash
# Three implementations in independent databases, with the exact published author.
# Reuse the owning shared-fixture job's isolated database service and network.
set -euo pipefail
backend="$1"
tag=sdk-directions
prefix=sdk-directions-v1
native_url="${2:?Set the independently isolated native URL}"
test -n "${RESOURCE_SCOPE:?Set the owning isolated network/resource scope}"
cohort=published-sdk-directions
fixture_pattern=sdk-*.json
reader_tests=(tests/Unit/ServerParitySdkDirectionContractTest.mjs)
case "${3:-activity}" in
  activity) ;;
  child)
    tag=sdk-child-directions
    prefix=sdk-child-directions-v1
    cohort=published-sdk-child-directions
    fixture_pattern=child-sdk-*.json
    reader_tests=(tests/Unit/ServerParitySdkChildDirectionContractTest.mjs)
    ;;
  *) echo 'Choose activity or child SDK directions' >&2; exit 2 ;;
esac
profile="tests/Fixtures/ServerParityProfiles/$cohort"
manifest="tests/Fixtures/ServerParityProfiles/$cohort/artifacts.json"
image=$(jq -r '.server.images["linux/amd64"] | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' "$manifest")
php_alias="parity-$tag-php"
resource="$RESOURCE_SCOPE-$tag"
php_database="$(printf '%s' "$tag" | tr '-' '_')_php_ref"
embedded_database="$(printf '%s' "$tag" | tr '-' '_')_embedded_ref"
evidence="$PWD/parity-evidence/$cohort"
state="$PWD/parity-$tag-state"
mkdir -p "$evidence"/{php,embedded,rust} "$state/sdk"
chmod 0777 "$evidence" "$evidence"/{php,embedded,rust} "$state" "$state/sdk"
cp tests/Fixtures/ServerParityProfiles/version-range-repair/sdk/{composer.json,composer.lock} "$state/sdk/"
(cd sdk-direction-adapters && sha256sum -c SHA256SUMS) > "$evidence/adapter-integrity.txt"
test "$(cat sdk-direction-adapters/runner-revision.txt)" = "$(git rev-parse HEAD)"
sha256sum -c sdk-direction-adapters/source-hashes.sha256 >> "$evidence/adapter-integrity.txt"
chmod 0755 sdk-direction-adapters/rust-worker
cp sdk-direction-adapters/{rust-sdk-build.txt,rust-sdk-vcs.json,python-install-report.json,python-install.txt,Cargo.lock,identity.json,runner-revision.txt,source-hashes.sha256} "$evidence/"
export DW_PARITY_RUNNER_REVISION
DW_PARITY_RUNNER_REVISION=$(git rev-parse HEAD)
limits=(--user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m)
shared=(-v "$PWD:/source:ro" -v "$evidence:/evidence" -v "$state/sdk:/sdk:ro" -w /source
  -v "$PWD/sdk-direction-adapters:/sdk-directions:ro"
  -e DW_PARITY_SDK_AUTOLOAD=/sdk/vendor/autoload.php -e DW_PARITY_RUNNER_REVISION
  -e PYTHONPATH=/sdk-directions/python-packages -e PYTHONDONTWRITEBYTECODE=1
  -e DW_PARITY_RUST_DIRECTION_WORKER=/sdk-directions/rust-worker
  -e DW_PARITY_DIRECTION_IDENTITY=/sdk-directions/identity.json)
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
printf '%s  %s\n' "$(jq -r '.sdk_php_composer_lock_sha256' "$manifest")" "$state/sdk/composer.lock" \
  | sha256sum -c > "$evidence/sdk-lock.txt"
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
selection=(--prefix "$prefix" --artifacts "/source/$manifest")
if test "$backend" != sqlite; then
  database_resource_limits=$(docker inspect --format '{"memory_bytes":{{.HostConfig.Memory}},"memory_swap_bytes":{{.HostConfig.MemorySwap}}}' "$RESOURCE_SCOPE-db")
  selection+=(--database-resource-limits "$database_resource_limits")
fi
for fixture in "$profile"/$fixture_pattern; do selection+=(--fixture "$fixture"); done
docker run --name "$resource-record-php" "${limits[@]}" --network "$RESOURCE_SCOPE" --entrypoint node "${shared[@]}" -e DW_PARITY_TOKEN=parity-test-token \
  "$image" scripts/conformance/server-parity.mjs record --mode http --url "http://$php_alias:8080" \
  --target php "${selection[@]}" --output /evidence/php/record.json > "$evidence/php-record.txt" 2>&1
docker run --name "$resource-record-embedded" "${limits[@]}" --network "$RESOURCE_SCOPE" "${shared[@]}" "${database[@]}" -e DB_DATABASE="$embedded_db" \
  -e DW_MODE=embedded -e CACHE_STORE=file -e QUEUE_CONNECTION=database -w /app "$image" \
  node /source/scripts/conformance/server-parity.mjs record --mode embedded --application-root /app \
  --target embedded "${selection[@]}" --output /evidence/embedded/record.json > "$evidence/embedded-record.txt" 2>&1
  docker run --name "$resource-record-native" "${limits[@]}" --network "$RESOURCE_SCOPE" --entrypoint node "${shared[@]}" -e DW_PARITY_TOKEN=parity-test-token \
  "$image" scripts/conformance/server-parity.mjs record --mode http --url "$native_url" \
  --target rust "${selection[@]}" --output /evidence/rust/record.json > "$evidence/rust-record.txt" 2>&1
  recordings=(/evidence/php/record.json /evidence/embedded/record.json /evidence/rust/record.json)
docker run --name "$resource-compare" "${limits[@]}" --network none --entrypoint node "${shared[@]}" "$image" scripts/conformance/server-parity.mjs compare \
  "${recordings[@]}" "${selection[@]}" > "$evidence/comparison.json" 2>&1
