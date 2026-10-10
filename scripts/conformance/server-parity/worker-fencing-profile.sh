#!/usr/bin/env bash
# Exact source-reference and native cohort; reuse the already built binary.
set -euo pipefail
scope_identity="$GITHUB_RUN_ID-$GITHUB_RUN_ATTEMPT-$GITHUB_JOB"
case "$RESOURCE_SCOPE" in *"$scope_identity"*) ;; *) exit 2 ;; esac
if ! docker network inspect "$RESOURCE_SCOPE" > /dev/null 2>&1; then
  docker network create "$RESOURCE_SCOPE"
fi
mkdir -p parity-evidence
chmod a+w parity-evidence
backend="$1"
tuple=tests/Fixtures/ServerParityProfiles/worker-fencing/source-tuple.json
fixture=tests/Fixtures/ServerParityPending/worker-registration-fencing.json
php_ref=$(jq -r '.server.source_commit' "$tuple")
git fetch origin "$php_ref"
test "$(git rev-parse "$php_ref^{tree}")" = "$(jq -r '.server.source_tree' "$tuple")"
mkdir -p parity-fence-source parity-fence-state/php-cache parity-fence-state/embedded-cache
git archive "$php_ref" | tar -xf - -C parity-fence-source
printf '%s  %s\n' "$(jq -r '.server.composer_lock_sha256' "$tuple")" parity-fence-source/composer.lock | sha256sum -c -
sdk_path=scripts/conformance/server-parity/worker-fencing-sdk
printf '%s  %s\n' "$(jq -r '.sdk_php_composer_lock_sha256' "$tuple")" "$sdk_path/composer.lock" | sha256sum -c -
chmod a+w parity-fence-source "$sdk_path"
image=$(jq -r '.server.build_platform_image | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' "$tuple")
rust_image=mirror.gcr.io/library/rust@sha256:ba81bc3eaa4422af576c0262515d96b0111a628a6ccc2c86557cf55c9a4bbee0
docker run --rm --user=1000:1000 --entrypoint composer -e COMPOSER_HOME=/tmp/parity-composer \
  -v "$PWD/parity-fence-source:/app" -w /app "$image" install --no-dev --no-scripts --no-interaction --prefer-dist \
  > parity-evidence/fence-server-install.txt 2>&1
docker run --rm --user=1000:1000 --entrypoint composer -e COMPOSER_HOME=/tmp/parity-composer \
  -v "$PWD:/source" -w "/source/$sdk_path" "$image" install --no-dev --no-scripts --no-interaction --prefer-dist \
  > parity-evidence/fence-sdk-install.txt 2>&1
credentials=(-e DW_AUTH_DRIVER=token -e DW_AUTH_TOKEN=parity-fence-legacy)
runtime=(-e APP_ENV=testing -e APP_DEBUG=false -e APP_VERSION=source-worker-fencing \
  -e DW_SERVER_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
  -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e LOG_CHANNEL=stderr)
db_mount=()
case "$backend" in
  mysql)
    db_env=(-e DB_CONNECTION=mysql -e DB_HOST=parity-mysql -e DB_USERNAME=root -e DB_PASSWORD=parity-disposable-password)
    for name in fence_php_ref fence_embedded_ref fence_native_ref; do
      docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
        -h parity-mysql -uroot -pparity-disposable-password -e "CREATE DATABASE $name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    done ;;
  pgsql)
    db_env=(-e DB_CONNECTION=pgsql -e DB_HOST=parity-pg -e DB_USERNAME=parity -e DB_PASSWORD=parity-disposable-password)
    for name in fence_php_ref fence_embedded_ref fence_native_ref; do
      docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
        --entrypoint psql "$POSTGRES_IMAGE" -h parity-pg -U parity -d postgres -c "CREATE DATABASE $name"
    done ;;
  sqlite)
    db_env=(-e DB_CONNECTION=sqlite)
    db_mount=(-v "$PWD/parity-fence-state:/state")
    touch parity-fence-state/fence_php_ref.sqlite parity-fence-state/fence_embedded_ref.sqlite
    chmod 0666 parity-fence-state/*.sqlite ;;
  *) exit 2 ;;
esac
database() {
  if test "$backend" = sqlite; then printf '/state/%s.sqlite' "$1"; else printf '%s' "$1"; fi
}
for target in php embedded; do
  storage="$PWD/parity-fence-state/$target-storage"
  mkdir -p "$storage/framework/cache/data" "$storage/framework/sessions" "$storage/framework/views" "$storage/logs"
done
# Prepare both host-owned trees before PHP creates UID1000-owned cache files.
# Hosted runner UID and container UID differ; never chmod generated files later.
chmod -R a+rwX parity-fence-state
for target in php embedded; do
  storage="$PWD/parity-fence-state/$target-storage"
  docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint php \
    -v "$PWD/parity-fence-source:/app:ro" -v "$storage:/app/storage" \
    -v "$PWD/parity-fence-state/$target-cache:/app/bootstrap/cache" "${db_mount[@]}" \
    "${db_env[@]}" -e DB_DATABASE="$(database fence_"$target"_ref)" "${credentials[@]}" "${runtime[@]}" \
    -w /app "$image" artisan server:bootstrap --force > "parity-evidence/fence-$target-bootstrap.txt" 2>&1
done
if test "$backend" != sqlite; then
  docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" \
    -e DW_RUST_EXPERIMENTAL=1 "${db_env[@]}" -e DB_DATABASE=fence_native_ref \
    -v "$PWD/rust-target:/target:ro" --entrypoint /target/debug/durable-workflow-server \
    "$rust_image" schema-bootstrap > parity-evidence/fence-native-bootstrap.json
fi
docker run -d --name "$RESOURCE_SCOPE-fence-native" --network "$RESOURCE_SCOPE" --network-alias=parity-fence-native \
  --user=1000:1000 --cpus=1 --memory=256m --memory-swap=256m \
  "${db_mount[@]}" "${db_env[@]}" -e DB_DATABASE="$(database fence_native_ref)" \
  -e DW_RUST_EXPERIMENTAL=1 -e DW_BIND_ADDRESS=0.0.0.0:8080 "${credentials[@]}" \
  -v "$PWD/rust-target:/target:ro" --entrypoint /target/debug/durable-workflow-server "$rust_image"
docker run -d --name "$RESOURCE_SCOPE-fence-php" --network "$RESOURCE_SCOPE" --network-alias=parity-fence-php \
  --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --entrypoint php \
  -v "$PWD/parity-fence-source:/app:ro" -v "$PWD/parity-fence-state/php-storage:/app/storage" \
  -v "$PWD/parity-fence-state/php-cache:/app/bootstrap/cache" "${db_mount[@]}" "${db_env[@]}" \
  -e DB_DATABASE="$(database fence_php_ref)" "${credentials[@]}" "${runtime[@]}" -w /app \
  "$image" artisan serve --host=0.0.0.0 --port=8080 --no-reload
for target in php native; do
  ready=0
  for attempt in $(seq 1 30); do
    if docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint curl "$image" \
      -fsS "http://parity-fence-$target:8080/api/ready" > "parity-evidence/fence-$target-readiness.json"; then ready=1; break; fi
    sleep 1
  done
  test "$ready" = 1
done
export DW_PARITY_RUNNER_REVISION=$(git rev-parse HEAD)
recorder=(-e DW_PARITY_RUNNER_REVISION -e DW_PARITY_SDK_AUTOLOAD="/source/$sdk_path/vendor/autoload.php")
docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint node \
  -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -w /source \
  "${recorder[@]}" -e DW_PARITY_TOKEN=parity-fence-legacy "$image" scripts/conformance/server-parity.mjs record \
  --mode http --url http://parity-fence-php:8080 --target php --prefix parity-fence \
  --fixture "$fixture" --artifacts "$tuple" --output /evidence/fence-php.json
docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint node \
  -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -w /source \
  "${recorder[@]}" -e DW_PARITY_TOKEN=parity-fence-legacy "$image" scripts/conformance/server-parity.mjs record \
  --mode http --url http://parity-fence-native:8080 --target rust --prefix parity-fence \
  --fixture "$fixture" --artifacts "$tuple" --output /evidence/fence-rust.json
docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint node \
  -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -v "$PWD/parity-fence-source:/app:ro" \
  -v "$PWD/parity-fence-state/embedded-storage:/app/storage" -v "$PWD/parity-fence-state/embedded-cache:/app/bootstrap/cache" \
  "${db_mount[@]}" "${db_env[@]}" -e DB_DATABASE="$(database fence_embedded_ref)" "${credentials[@]}" "${runtime[@]}" \
  "${recorder[@]}" -e DW_MODE=embedded -w /source "$image" scripts/conformance/server-parity.mjs record \
  --mode embedded --application-root /app --target embedded --prefix parity-fence \
  --fixture "$fixture" --artifacts "$tuple" --output /evidence/fence-embedded.json
docker run --rm --user=1000:1000 --network=none --entrypoint node \
  -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence:ro" -w /source "$image" \
  scripts/conformance/server-parity.mjs compare /evidence/fence-php.json /evidence/fence-embedded.json /evidence/fence-rust.json --fixture "$fixture" \
  > parity-evidence/fence-comparison.txt
