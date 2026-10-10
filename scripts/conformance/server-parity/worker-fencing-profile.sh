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
tag="${2:-fence}"
case "$tag" in
  fence)
    tuple=tests/Fixtures/ServerParityProfiles/worker-fencing/source-tuple.json
    fixtures=(--fixture tests/Fixtures/ServerParityPending/worker-registration-fencing.json
      --fixture tests/Fixtures/ServerParityPending/worker-registration-live-lease.json
      --fixture tests/Fixtures/ServerParityPending/worker-sdk-reply-reconciliation.json) ;;
  pressure)
    tuple=tests/Fixtures/ServerParityProfiles/worker-pressure/source-tuple.json
    fixtures=(--fixture tests/Fixtures/ServerParityPending/worker-sdk-database-pressure.json
      --fixture tests/Fixtures/ServerParityPending/worker-sdk-persistent-pressure.json
      --fixture tests/Fixtures/ServerParityPending/worker-sdk-original-failure.json) ;;
  *) exit 2 ;;
esac
php_ref=$(jq -r '.server.source_commit' "$tuple")
git fetch origin "$php_ref"
test "$(git rev-parse "$php_ref^{tree}")" = "$(jq -r '.server.source_tree' "$tuple")"
mkdir -p parity-fence-source parity-${tag}-state/php-cache parity-${tag}-state/embedded-cache
git archive "$php_ref" | tar -xf - -C parity-fence-source
printf '%s  %s\n' "$(jq -r '.server.composer_lock_sha256' "$tuple")" parity-fence-source/composer.lock | sha256sum -c -
sdk_path=scripts/conformance/server-parity/worker-fencing-sdk
printf '%s  %s\n' "$(jq -r '.sdk_php_composer_lock_sha256' "$tuple")" "$sdk_path/composer.lock" | sha256sum -c -
chmod a+w parity-fence-source "$sdk_path"
image=$(jq -r '.server.build_platform_image | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' "$tuple")
rust_image=mirror.gcr.io/library/rust@sha256:ba81bc3eaa4422af576c0262515d96b0111a628a6ccc2c86557cf55c9a4bbee0
if ! test -f parity-fence-source/vendor/autoload.php; then
  docker run --rm --user=1000:1000 --entrypoint composer -e COMPOSER_HOME=/tmp/parity-composer \
    -v "$PWD/parity-fence-source:/app" -w /app "$image" install --no-dev --no-scripts --no-interaction --prefer-dist \
    > parity-evidence/${tag}-server-install.txt 2>&1
else
  printf '%s\n' 'Reusing the exact locked server dependencies from the preceding source cohort.' > "parity-evidence/${tag}-server-install.txt"
fi
if ! test -f "$sdk_path/vendor/autoload.php"; then
  docker run --rm --user=1000:1000 --entrypoint composer -e COMPOSER_HOME=/tmp/parity-composer \
    -v "$PWD:/source" -w "/source/$sdk_path" "$image" install --no-dev --no-scripts --no-interaction --prefer-dist \
    > parity-evidence/${tag}-sdk-install.txt 2>&1
else
  printf '%s\n' 'Reusing the exact locked sdk dependencies from the preceding source cohort.' > "parity-evidence/${tag}-sdk-install.txt"
fi
credentials=(-e DW_AUTH_DRIVER=token -e DW_AUTH_TOKEN=parity-${tag}-legacy)
runtime=(-e APP_ENV=testing -e APP_DEBUG=false -e APP_VERSION=source-worker-fencing \
  -e DW_SERVER_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
  -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e LOG_CHANNEL=stderr)
db_mount=()
case "$backend" in
  mysql)
    db_env=(-e DB_CONNECTION=mysql -e DB_HOST=parity-mysql -e DB_USERNAME=root -e DB_PASSWORD=parity-disposable-password)
    for name in ${tag}_php_ref ${tag}_embedded_ref ${tag}_native_ref; do
      docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
        -h parity-mysql -uroot -pparity-disposable-password -e "CREATE DATABASE $name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    done ;;
  pgsql)
    db_env=(-e DB_CONNECTION=pgsql -e DB_HOST=parity-pg -e DB_USERNAME=parity -e DB_PASSWORD=parity-disposable-password)
    for name in ${tag}_php_ref ${tag}_embedded_ref ${tag}_native_ref; do
      docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
        --entrypoint psql "$POSTGRES_IMAGE" -h parity-pg -U parity -d postgres -c "CREATE DATABASE $name"
    done ;;
  sqlite)
    db_env=(-e DB_CONNECTION=sqlite)
    db_mount=(-v "$PWD/parity-${tag}-state:/state")
    touch parity-${tag}-state/${tag}_php_ref.sqlite parity-${tag}-state/${tag}_embedded_ref.sqlite
    chmod 0666 parity-${tag}-state/*.sqlite ;;
  *) exit 2 ;;
esac
database() {
  if test "$backend" = sqlite; then printf '/state/%s.sqlite' "$1"; else printf '%s' "$1"; fi
}
for target in php embedded; do
  storage="$PWD/parity-${tag}-state/$target-storage"
  mkdir -p "$storage/framework/cache/data" "$storage/framework/sessions" "$storage/framework/views" "$storage/logs"
done
# Prepare both host-owned trees before PHP creates UID1000-owned cache files.
# Hosted runner UID and container UID differ; never chmod generated files later.
chmod -R a+rwX parity-${tag}-state
for target in php embedded; do
  storage="$PWD/parity-${tag}-state/$target-storage"
  docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint php \
    -v "$PWD/parity-fence-source:/app:ro" -v "$storage:/app/storage" \
    -v "$PWD/parity-${tag}-state/$target-cache:/app/bootstrap/cache" "${db_mount[@]}" \
    "${db_env[@]}" -e DB_DATABASE="$(database ${tag}_"$target"_ref)" "${credentials[@]}" "${runtime[@]}" \
    -w /app "$image" artisan server:bootstrap --force > "parity-evidence/${tag}-$target-bootstrap.txt" 2>&1
done
if test "$tag" = pressure; then
  sample_php_session() {
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint php \
      -v "$PWD:/source:ro" -v "$PWD/parity-fence-source:/app:ro" \
      -v "$PWD/parity-${tag}-state/php-storage:/app/storage" \
      -v "$PWD/parity-${tag}-state/php-cache:/app/bootstrap/cache" "${db_mount[@]}" \
      "${db_env[@]}" -e DB_DATABASE="$(database ${tag}_php_ref)" "${credentials[@]}" "${runtime[@]}" \
      -w /app "$image" /source/scripts/conformance/server-parity/database-pressure-session.php
  }
  sample_php_session > parity-evidence/pressure-php-session-before.json
  if test "$backend" = pgsql; then
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
      --entrypoint psql "$POSTGRES_IMAGE" -h parity-pg -U parity -d postgres \
      -c "ALTER DATABASE ${tag}_php_ref SET lock_timeout='5s'"
  elif test "$backend" = mysql; then
    pressure_mysql() {
      docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
        -h parity-mysql -uroot -pparity-disposable-password -Nse "$1"
    }
    previous_mysql_wait=$(pressure_mysql "SELECT @@GLOBAL.innodb_lock_wait_timeout")
    [[ "$previous_mysql_wait" =~ ^[0-9]+$ ]]
    restore_pressure_mysql() {
      prior_exit=$?
      trap - EXIT
      if ! pressure_mysql "SET GLOBAL innodb_lock_wait_timeout=$previous_mysql_wait"; then exit 1; fi
      exit "$prior_exit"
    }
    trap restore_pressure_mysql EXIT
    pressure_mysql "SET GLOBAL innodb_lock_wait_timeout=5"
  fi
  sample_php_session > parity-evidence/pressure-php-session-after.json
fi
if test "$backend" != sqlite; then
  docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" \
    -e DW_RUST_EXPERIMENTAL=1 "${db_env[@]}" -e DB_DATABASE=${tag}_native_ref \
    -v "$PWD/rust-target:/target:ro" --entrypoint /target/debug/durable-workflow-server \
    "$rust_image" schema-bootstrap > parity-evidence/${tag}-native-bootstrap.json
fi
docker run -d --name "$RESOURCE_SCOPE-${tag}-native" --network "$RESOURCE_SCOPE" --network-alias=parity-${tag}-native \
  --user=1000:1000 --cpus=1 --memory=256m --memory-swap=256m \
  "${db_mount[@]}" "${db_env[@]}" -e DB_DATABASE="$(database ${tag}_native_ref)" \
  -e DW_RUST_EXPERIMENTAL=1 -e DW_BIND_ADDRESS=0.0.0.0:8080 "${credentials[@]}" \
  -v "$PWD/rust-target:/target:ro" --entrypoint /target/debug/durable-workflow-server "$rust_image"
docker run -d --name "$RESOURCE_SCOPE-${tag}-php" --network "$RESOURCE_SCOPE" --network-alias=parity-${tag}-php \
  --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --entrypoint php \
  -v "$PWD/parity-fence-source:/app:ro" -v "$PWD/parity-${tag}-state/php-storage:/app/storage" \
  -v "$PWD/parity-${tag}-state/php-cache:/app/bootstrap/cache" "${db_mount[@]}" "${db_env[@]}" \
  -e DB_DATABASE="$(database ${tag}_php_ref)" "${credentials[@]}" "${runtime[@]}" -w /app \
  "$image" artisan serve --host=0.0.0.0 --port=8080 --no-reload
for target in php native; do
  ready=0
  for attempt in $(seq 1 30); do
    if docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint curl "$image" \
      -fsS "http://parity-${tag}-$target:8080/api/ready" > "parity-evidence/${tag}-$target-readiness.json"; then ready=1; break; fi
    sleep 1
  done
  test "$ready" = 1
done
export DW_PARITY_RUNNER_REVISION=$(git rev-parse HEAD)
recorder=(-e DW_PARITY_RUNNER_REVISION -e DW_PARITY_SDK_AUTOLOAD="/source/$sdk_path/vendor/autoload.php")
pressure_recorder=()
if test "$tag" = pressure; then
  pressure_recorder=("${db_mount[@]}" "${db_env[@]}"
    -e DW_PARITY_PRESSURE_SESSION_BEFORE=/evidence/pressure-php-session-before.json
    -e DW_PARITY_PRESSURE_SESSION_AFTER=/evidence/pressure-php-session-after.json)
fi
docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint node \
  -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -w /source \
  "${recorder[@]}" "${pressure_recorder[@]}" -e DW_PARITY_PRESSURE_DATABASE="$(database ${tag}_php_ref)" \
  -e DW_PARITY_TOKEN=parity-${tag}-legacy "$image" scripts/conformance/server-parity.mjs record \
  --mode http --url http://parity-${tag}-php:8080 --target php --prefix parity-${tag} \
  "${fixtures[@]}" --artifacts "$tuple" --output /evidence/${tag}-php.json
docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint node \
  -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -w /source \
  "${recorder[@]}" "${pressure_recorder[@]}" -e DW_PARITY_PRESSURE_DATABASE="$(database ${tag}_native_ref)" \
  -e DW_PARITY_TOKEN=parity-${tag}-legacy "$image" scripts/conformance/server-parity.mjs record \
  --mode http --url http://parity-${tag}-native:8080 --target rust --prefix parity-${tag} \
  "${fixtures[@]}" --artifacts "$tuple" --output /evidence/${tag}-rust.json
docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint node \
  -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -v "$PWD/parity-fence-source:/app:ro" \
  -v "$PWD/parity-${tag}-state/embedded-storage:/app/storage" -v "$PWD/parity-${tag}-state/embedded-cache:/app/bootstrap/cache" \
  "${db_mount[@]}" "${db_env[@]}" -e DB_DATABASE="$(database ${tag}_embedded_ref)" "${credentials[@]}" "${runtime[@]}" \
  "${recorder[@]}" -e DW_MODE=embedded -w /source "$image" scripts/conformance/server-parity.mjs record \
  --mode embedded --application-root /app --target embedded --prefix parity-${tag} \
  "${fixtures[@]}" --artifacts "$tuple" --output /evidence/${tag}-embedded.json
docker run --rm --user=1000:1000 --network=none --entrypoint node \
  -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence:ro" -w /source "$image" \
  scripts/conformance/server-parity.mjs compare /evidence/${tag}-php.json /evidence/${tag}-embedded.json /evidence/${tag}-rust.json "${fixtures[@]}" \
  > parity-evidence/${tag}-comparison.txt
