#!/usr/bin/env bash
# Separately installed current and genuine historical embedded actors.
set -euo pipefail
backend="$1"
test -n "$RESOURCE_SCOPE"
profile=tests/Fixtures/ServerParityProfiles/corrected-markers
adapters=${DW_PARITY_CORRECTED_ADAPTERS:-corrected-marker-adapters}
image=$(jq -r '.server.images["linux/amd64"]' "$profile/artifacts.json")
state=parity-corrected-state
evidence=parity-evidence/corrected-marker
mkdir -p "$state"/{current,original} "$evidence/embedded"
engine=(-e DW_MODE=embedded -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e LOG_CHANNEL=stderr
  -e DW_SERVER_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=)
case "$backend" in
  mysql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
      -h parity-mysql -uroot -pparity-disposable-password \
      -e 'CREATE DATABASE embedded_corrected_ref CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    database=(-e DB_CONNECTION=mysql -e DB_HOST=parity-mysql -e DB_DATABASE=embedded_corrected_ref
      -e DB_USERNAME=root -e DB_PASSWORD=parity-disposable-password) ;;
  pgsql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
      --entrypoint createdb "$POSTGRES_IMAGE" -h parity-pg -U parity embedded_corrected_ref
    database=(-e DB_CONNECTION=pgsql -e DB_HOST=parity-pg -e DB_DATABASE=embedded_corrected_ref
      -e DB_USERNAME=parity -e DB_PASSWORD=parity-disposable-password) ;;
  sqlite)
    touch "$state/embedded.sqlite"
    database=(-e DB_CONNECTION=sqlite -e DB_DATABASE=/state/embedded.sqlite -v "$PWD/$state:/state") ;;
  *) exit 2 ;;
esac
for role in original current; do
  if test "$role" = original; then
    inputs=embedded-original
    lock=$(jq -r '.embedded_original.composer_lock_sha256' "$profile/artifacts.json")
  else
    inputs=embedded
    lock=$(jq -r '.embedded_composer_lock_sha256' "$profile/artifacts.json")
  fi
  docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --entrypoint sh \
    -v "$PWD:/source:ro" -v "$PWD/$state/$role:/runtime" "${engine[@]}" \
    -w /runtime -e COMPOSER_HOME=/tmp/composer -e DW_PARITY_EMBEDDED_PROFILE="/source/$profile/$inputs" \
    -e DW_PARITY_EMBEDDED_LOCK_SHA256="$lock" "$image" \
    /source/scripts/conformance/server-parity/repeated-patch-install.sh \
    > "$evidence/embedded-$role-install.txt" 2>&1
  cp "$state/$role/composer.lock" "$evidence/embedded-$role-composer.lock"
done
runtime=(-v "$PWD/$state/current:/runtime" -v "$PWD/$state/original:/original-runtime"
  -e DW_PARITY_CORRECTED_ORIGINAL_EMBEDDED_ROOT=/original-runtime "${engine[@]}" "${database[@]}")
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
  --network "$RESOURCE_SCOPE" --entrypoint php "${runtime[@]}" -w /original-runtime "$image" \
  artisan server:bootstrap --force > "$evidence/embedded-bootstrap.txt" 2>&1
fixtures=()
for id in fresh-numeric fresh-zero deprecation-first genuine-version-one php-full-retry; do
  fixtures+=(--fixture "$profile/$id.json")
done
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
  --network "$RESOURCE_SCOPE" --entrypoint node -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" \
  -v "$PWD/$adapters/php:/sdk-php:ro" -v "$PWD/$adapters/bin:/sdk-adapters:ro" \
  -e DW_PARITY_SDK_AUTOLOAD=/sdk-php/vendor/autoload.php -e DW_PARITY_RUNNER_REVISION="$(git rev-parse HEAD)" \
  "${runtime[@]}" -w /source "$image" scripts/conformance/server-parity.mjs record \
  --mode embedded --application-root /runtime --target embedded --prefix corrected-markers-v1 \
  --artifacts "$profile/artifacts.json" "${fixtures[@]}" --output /evidence/corrected-marker/embedded/record.json
# Evidence survives any failed actor; job cleanup owns incomplete resources.
case "$backend" in
  mysql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
      -h parity-mysql -uroot -pparity-disposable-password -e 'DROP DATABASE embedded_corrected_ref' ;;
  pgsql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
      --entrypoint dropdb "$POSTGRES_IMAGE" -h parity-pg -U parity embedded_corrected_ref ;;
  sqlite) rm -- "$state/embedded.sqlite" ;;
esac
for role in current original; do
  docker run --rm --user=1000:1000 --network=none --entrypoint sh \
    -v "$PWD/$state/$role:/runtime" "$image" \
    -c 'find /runtime -mindepth 1 -maxdepth 1 -exec rm -r -- {} +'
done
