#!/usr/bin/env bash
# Genuine published legacy authors and a cold published reader, plus fresh
# deduplication and a one-marker control. Each embedded engine is installed.
set -euo pipefail
backend="$1"
php_url="$2"
native_url="$3"
test -n "$RESOURCE_SCOPE"
profile=tests/Fixtures/ServerParityProfiles/legacy-marker-aliases
image=$(jq -r '.server.images["linux/amd64"] | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' "$profile/artifacts.json")
mkdir -p parity-evidence/legacy-marker/{php,rust,embedded} parity-legacy-state/{embedded-app,original-app}
chmod 0777 parity-evidence/legacy-marker parity-evidence/legacy-marker/{php,rust,embedded} \
  parity-legacy-state parity-legacy-state/{embedded-app,original-app}
(cd legacy-marker-adapters && sha256sum -c SHA256SUMS) > parity-evidence/legacy-marker/adapter-integrity.txt
test "$(cat legacy-marker-adapters/runner-revision.txt)" = "$(git rev-parse HEAD)"
sha256sum -c legacy-marker-adapters/source-hashes.sha256 >> parity-evidence/legacy-marker/adapter-integrity.txt
chmod 0755 legacy-marker-adapters/rust-worker
cp legacy-marker-adapters/{rust-sdk-build.txt,python-install-report.json,python-install.txt,Cargo.lock,identity.json,runner-revision.txt,source-hashes.sha256} parity-evidence/legacy-marker/
export DW_PARITY_RUNNER_REVISION=$(git rev-parse HEAD)
shared=(-v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -w /source
  -v "$PWD/legacy-marker-adapters:/sdk-adapters:ro"
  -e PYTHONPATH=/sdk-adapters/python-packages -e DW_PARITY_RUST_PATCH_WORKER=/sdk-adapters/rust-worker
  -e DW_PARITY_TOKEN=parity-test-token -e DW_PARITY_RUNNER_REVISION)
fixtures=()
for id in fresh-rust-marker-deduplication legacy-one-marker-control legacy-two-markers-completed legacy-two-markers-pending; do
  fixtures+=(--fixture "$profile/$id.json")
done
docker run --rm --user=1000:1000 --network=none --entrypoint node "${shared[@]}" "$image" \
  --test tests/Unit/ServerParityLegacyMarkerContractTest.mjs > parity-evidence/legacy-marker/comparator-tests.txt
for target in php rust; do
  if test "$target" = php; then url="$php_url"; else url="$native_url"; fi
  docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
    --network "$RESOURCE_SCOPE" --entrypoint node "${shared[@]}" "$image" \
    scripts/conformance/server-parity.mjs record --mode http --url "$url" --target "$target" \
    --prefix legacy-marker-v1 --artifacts "$profile/artifacts.json" "${fixtures[@]}" \
    --output "/evidence/legacy-marker/$target/record.json"
done
case "$backend" in
  mysql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
      -h parity-mysql -uroot -pparity-disposable-password \
      -e 'CREATE DATABASE embedded_legacy_ref CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    embedded=(-e DB_CONNECTION=mysql -e DB_HOST=parity-mysql -e DB_DATABASE=embedded_legacy_ref
      -e DB_USERNAME=root -e DB_PASSWORD=parity-disposable-password) ;;
  pgsql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
      --entrypoint createdb "$POSTGRES_IMAGE" -h parity-pg -U parity embedded_legacy_ref
    embedded=(-e DB_CONNECTION=pgsql -e DB_HOST=parity-pg -e DB_DATABASE=embedded_legacy_ref
      -e DB_USERNAME=parity -e DB_PASSWORD=parity-disposable-password) ;;
  sqlite)
    touch parity-legacy-state/embedded.sqlite
    chmod 0666 parity-legacy-state/embedded.sqlite
    embedded=(-e DB_CONNECTION=sqlite -e DB_DATABASE=/state/embedded.sqlite -v "$PWD/parity-legacy-state:/state") ;;
  *) exit 2 ;;
esac
engine=(-e DW_MODE=embedded -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e LOG_CHANNEL=stderr
  -e DW_SERVER_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=)
for role in original replacement; do
  if test "$role" = original; then
    app=original-app; inputs=embedded-original; lock=$(jq -r '.embedded_original.composer_lock_sha256' "$profile/artifacts.json")
  else
    app=embedded-app; inputs=embedded; lock=$(jq -r '.embedded_composer_lock_sha256' "$profile/artifacts.json")
  fi
  docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --entrypoint sh \
    -v "$PWD:/source:ro" -v "$PWD/parity-legacy-state/$app:/runtime" "${engine[@]}" \
    -w /runtime -e COMPOSER_HOME=/tmp/composer -e DW_PARITY_EMBEDDED_PROFILE="/source/$profile/$inputs" \
    -e DW_PARITY_EMBEDDED_LOCK_SHA256="$lock" "$image" \
    /source/scripts/conformance/server-parity/repeated-patch-install.sh \
    > "parity-evidence/legacy-marker/embedded-$role-install.txt" 2>&1
  cp "parity-legacy-state/$app/composer.lock" "parity-evidence/legacy-marker/embedded-$role-composer.lock"
done
runtime=(-v "$PWD/parity-legacy-state/embedded-app:/runtime" -v "$PWD/parity-legacy-state/original-app:/original-runtime"
  -e DW_PARITY_ORIGINAL_EMBEDDED_APPLICATION_ROOT=/original-runtime "${engine[@]}")
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
  --network "$RESOURCE_SCOPE" --entrypoint php "${runtime[@]}" "${embedded[@]}" -w /original-runtime "$image" \
  artisan server:bootstrap --force > parity-evidence/legacy-marker/embedded-bootstrap.txt 2>&1
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
  --network "$RESOURCE_SCOPE" --entrypoint node "${shared[@]}" "${runtime[@]}" "${embedded[@]}" "$image" \
  scripts/conformance/server-parity.mjs record --mode embedded --application-root /runtime --target embedded \
  --prefix legacy-marker-v1 --artifacts "$profile/artifacts.json" "${fixtures[@]}" \
  --output /evidence/legacy-marker/embedded/record.json
docker run --rm --user=1000:1000 --network=none --entrypoint node "${shared[@]}" "$image" \
  scripts/conformance/server-parity.mjs compare /evidence/legacy-marker/php/record.json \
  /evidence/legacy-marker/rust/record.json /evidence/legacy-marker/embedded/record.json \
  --artifacts "$profile/artifacts.json" "${fixtures[@]}" > parity-evidence/legacy-marker/comparison.json
case "$backend" in
  mysql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
      -h parity-mysql -uroot -pparity-disposable-password -e 'DROP DATABASE embedded_legacy_ref' ;;
  pgsql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
      --entrypoint dropdb "$POSTGRES_IMAGE" -h parity-pg -U parity embedded_legacy_ref ;;
  sqlite) rm -- parity-legacy-state/embedded.sqlite ;;
esac
for app in embedded-app original-app; do
  docker run --rm --user=1000:1000 --network=none --entrypoint sh \
    -v "$PWD/parity-legacy-state/$app:/runtime" "$image" \
    -c 'find /runtime -mindepth 1 -maxdepth 1 -exec rm -r -- {} +'
done
