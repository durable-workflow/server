#!/usr/bin/env bash
# Two explicit repeated-patch controls on the job's existing HTTP targets,
# with a separately installed published embedded engine and database.
set -euo pipefail
backend="$1"
php_url="$2"
native_url="$3"
test -n "$RESOURCE_SCOPE"
profile=tests/Fixtures/ServerParityProfiles/repeated-patch-clock
image=$(jq -r '.server.images["linux/amd64"] | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' "$profile/artifacts.json")
lock=$(jq -r '.embedded_composer_lock_sha256' "$profile/artifacts.json")
mkdir -p parity-evidence/repeated-patch/{php,rust,embedded} parity-repeated-state/embedded-app
chmod 0777 parity-evidence/repeated-patch parity-evidence/repeated-patch/{php,rust,embedded} parity-repeated-state parity-repeated-state/embedded-app
shared=(-v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -w /source
  -e DW_PARITY_TOKEN=parity-test-token -e DW_PARITY_RUNNER_REVISION)
export DW_PARITY_RUNNER_REVISION=$(git rev-parse HEAD)
fixtures=(--fixture "$profile/patch-repeated-activity-pending.json" --fixture "$profile/patch-repeated-activity-completed.json")
docker run --rm --user=1000:1000 --network=none --entrypoint node "${shared[@]}" "$image" \
  --test tests/Unit/ServerParityRepeatedPatchClockTest.mjs > parity-evidence/repeated-patch/comparator-tests.txt
for target in php rust; do
  if test "$target" = php; then url="$php_url"; else url="$native_url"; fi
  docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
    --network "$RESOURCE_SCOPE" --entrypoint node "${shared[@]}" "$image" \
    scripts/conformance/server-parity.mjs record --mode http --url "$url" --target "$target" \
    --prefix repeated-patch-v1 --artifacts "$profile/artifacts.json" "${fixtures[@]}" \
    --output "/evidence/repeated-patch/$target/record.json"
done
case "$backend" in
  mysql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
      -h parity-mysql -uroot -pparity-disposable-password \
      -e 'CREATE DATABASE embedded_repeated_ref CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    embedded=(-e DB_CONNECTION=mysql -e DB_HOST=parity-mysql -e DB_DATABASE=embedded_repeated_ref
      -e DB_USERNAME=root -e DB_PASSWORD=parity-disposable-password) ;;
  pgsql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
      --entrypoint createdb "$POSTGRES_IMAGE" -h parity-pg -U parity embedded_repeated_ref
    embedded=(-e DB_CONNECTION=pgsql -e DB_HOST=parity-pg -e DB_DATABASE=embedded_repeated_ref
      -e DB_USERNAME=parity -e DB_PASSWORD=parity-disposable-password) ;;
  sqlite)
    touch parity-repeated-state/embedded.sqlite
    chmod 0666 parity-repeated-state/embedded.sqlite
    embedded=(-e DB_CONNECTION=sqlite -e DB_DATABASE=/state/embedded.sqlite -v "$PWD/parity-repeated-state:/state") ;;
  *) exit 2 ;;
esac
runtime=(-v "$PWD/parity-repeated-state/embedded-app:/runtime" -e DW_MODE=embedded
  -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e LOG_CHANNEL=stderr
  -e DW_SERVER_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=)
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --entrypoint sh \
  -v "$PWD:/source:ro" "${runtime[@]}" -w /runtime -e COMPOSER_HOME=/tmp/composer \
  -e DW_PARITY_EMBEDDED_PROFILE="/source/$profile/embedded" -e DW_PARITY_EMBEDDED_LOCK_SHA256="$lock" \
  "$image" /source/scripts/conformance/server-parity/repeated-patch-install.sh \
  > parity-evidence/repeated-patch/embedded-install.txt 2>&1
cp parity-repeated-state/embedded-app/composer.lock parity-evidence/repeated-patch/embedded-composer.lock
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
  --network "$RESOURCE_SCOPE" --entrypoint php "${runtime[@]}" "${embedded[@]}" -w /runtime "$image" \
  artisan server:bootstrap --force > parity-evidence/repeated-patch/embedded-bootstrap.txt 2>&1
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
  --network "$RESOURCE_SCOPE" --entrypoint node "${shared[@]}" "${runtime[@]}" "${embedded[@]}" "$image" \
  scripts/conformance/server-parity.mjs record --mode embedded --application-root /runtime --target embedded \
  --prefix repeated-patch-v1 --artifacts "$profile/artifacts.json" "${fixtures[@]}" \
  --output /evidence/repeated-patch/embedded/record.json
docker run --rm --user=1000:1000 --network=none --entrypoint node "${shared[@]}" "$image" \
  scripts/conformance/server-parity.mjs compare /evidence/repeated-patch/php/record.json \
  /evidence/repeated-patch/rust/record.json /evidence/repeated-patch/embedded/record.json \
  --artifacts "$profile/artifacts.json" "${fixtures[@]}" > parity-evidence/repeated-patch/comparison.json
