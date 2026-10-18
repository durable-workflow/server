#!/usr/bin/env bash
# Separately installed current and genuine historical embedded actors.
set -euo pipefail
backend="$1"
family=${2:-markers}
memo_fixture=${DW_PARITY_MEMO_FIXTURE_ID:-memo-initial-activity-replay}
case "$memo_fixture" in memo-initial-activity-replay|memo-activity-replay) ;; *) exit 2 ;; esac
case "$family" in
  markers) ids=(fresh-numeric fresh-zero deprecation-first genuine-version-one php-full-retry) ;;
  policy) ids=(php-full-retry-refusal) ;;
  range) ids=(rust-range-completed rust-range-timer-pending) ;;
  portable) ids=(fresh-numeric fresh-zero deprecation-first genuine-version-one php-full-retry php-full-retry-refusal rust-range-completed rust-range-timer-pending) ;;
  deadlines) ids=(activity-deadline-authority) ;;
  retries) ids=(activity-deadline-active-receipts activity-deadline-receipts activity-global-deadline-retry activity-heartbeat-renewal) ;;
  memos) ids=("$memo_fixture") ;;
  continuations)
    ids=(continue-initial-memo-activity-replay)
    if test "${DW_PARITY_CONTINUE_MEMO_UPSERTS:-0}" = 1; then ids+=(continue-upserted-memo-activity-replay); fi
    ;;
  *) exit 2 ;;
esac
test -n "$RESOURCE_SCOPE"
profile=tests/Fixtures/ServerParityProfiles/corrected-markers
artifacts=${DW_PARITY_CORRECTED_ARTIFACTS:-$profile/artifacts.json}
adapters=${DW_PARITY_CORRECTED_ADAPTERS:-corrected-marker-adapters}
image=$(jq -r '.server.images["linux/amd64"]' "$artifacts")
state=${DW_PARITY_CORRECTED_STATE:-parity-corrected-state}
evidence=${DW_PARITY_CORRECTED_EVIDENCE:-parity-evidence/corrected-marker}
prefix=${DW_PARITY_CORRECTED_PREFIX:-corrected-markers-v1}
mkdir -p "$state"/{current,original} "$evidence/embedded"
chmod 0777 "$state" "$state/current" "$state/original" "$evidence/embedded"
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
    test ! -e "$state/embedded.sqlite"
    touch "$state/embedded.sqlite"
    chmod 0666 "$state/embedded.sqlite"
    database=(-e DB_CONNECTION=sqlite -e DB_DATABASE=/state/embedded.sqlite -v "$PWD/$state:/state") ;;
  *) exit 2 ;;
esac
if test -n "${DW_PARITY_CORRECTED_OWNERSHIP_DIR:-}"; then
  printf '%s\n' "$RESOURCE_SCOPE" > "$DW_PARITY_CORRECTED_OWNERSHIP_DIR/embedded-database-owned.txt"
fi
roles=(original current)
record=record.json
bootstrap_root=/original-runtime
if test "$family" = deadlines; then roles=(current); record=deadlines-record.json; bootstrap_root=/runtime; fi
if test "$family" = retries; then roles=(current); record=retries-record.json; bootstrap_root=/runtime; fi
if test "$family" = memos; then roles=(current); record=memos-record.json; bootstrap_root=/runtime; fi
if test "$family" = continuations; then roles=(current); record=continuations-record.json; bootstrap_root=/runtime; fi
for role in "${roles[@]}"; do
  package_checks=()
  if test "$role" = original; then
    inputs="$profile/embedded-original"
    lock=$(jq -r '.embedded_original.composer_lock_sha256' "$artifacts")
  else
    inputs=$(jq -r --arg fallback "$profile/embedded" '.embedded_current_profile // $fallback' "$artifacts")
    lock=$(jq -r '.embedded_composer_lock_sha256' "$artifacts")
    if test -n "${DW_PARITY_EMBEDDED_RUNTIME_HASHES:-}"; then
      package_checks=(-e DW_PARITY_EMBEDDED_RUNTIME_HASHES="$DW_PARITY_EMBEDDED_RUNTIME_HASHES")
    fi
  fi
  docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --entrypoint sh \
    -v "$PWD:/source:ro" -v "$PWD/$state/$role:/runtime" "${engine[@]}" "${package_checks[@]}" \
    -w /runtime -e COMPOSER_HOME=/tmp/composer -e DW_PARITY_EMBEDDED_PROFILE="/source/$inputs" \
    -e DW_PARITY_EMBEDDED_LOCK_SHA256="$lock" "$image" \
    /source/scripts/conformance/server-parity/corrected-marker-embedded-install.sh \
    > "$evidence/embedded-$role-install.txt" 2>&1
  cp "$state/$role/composer.lock" "$evidence/embedded-$role-composer.lock"
done
runtime=(-v "$PWD/$state/current:/runtime" -v "$PWD/$state/original:/original-runtime"
  -e DW_PARITY_CORRECTED_ORIGINAL_EMBEDDED_ROOT=/original-runtime "${engine[@]}" "${database[@]}")
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
  --network "$RESOURCE_SCOPE" --entrypoint php "${runtime[@]}" -w "$bootstrap_root" "$image" \
  artisan server:bootstrap --force > "$evidence/embedded-bootstrap.txt" 2>&1
fixtures=()
for id in "${ids[@]}"; do
  path="$profile/$id.json"
  if test "$family" = deadlines || test "$family" = retries || test "$family" = memos || test "$family" = continuations; then path="tests/Fixtures/ServerParityPending/$id.json"; fi
  fixtures+=(--fixture "$path")
done
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
  --network "$RESOURCE_SCOPE" --entrypoint node -v "$PWD:/source:ro" -v "$PWD/$evidence:/evidence" \
  -v "$PWD/$adapters/php:/sdk-php:ro" -v "$PWD/$adapters/bin:/sdk-adapters:ro" \
  -e DW_PARITY_SDK_AUTOLOAD=/sdk-php/vendor/autoload.php -e DW_PARITY_RUNNER_REVISION="$(git rev-parse HEAD)" \
  "${runtime[@]}" -w /source "$image" scripts/conformance/server-parity.mjs record \
  --mode embedded --application-root /runtime --target embedded --prefix "$prefix" \
  --artifacts "$artifacts" "${fixtures[@]}" --output "/evidence/embedded/$record"
# Evidence survives any failed actor; job cleanup owns incomplete resources.
case "$backend" in
  mysql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
      -h parity-mysql -uroot -pparity-disposable-password -e 'DROP DATABASE embedded_corrected_ref' ;;
  pgsql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
      --entrypoint dropdb "$POSTGRES_IMAGE" -h parity-pg -U parity embedded_corrected_ref ;;
  sqlite)
    snapshot=original.sqlite
    if test "$family" = deadlines; then snapshot=deadlines-original.sqlite; fi
    if test "$family" = memos; then snapshot=memos-original.sqlite; fi
    if test "$family" = continuations; then snapshot=continuations-original.sqlite; fi
    cp "$state/embedded.sqlite" "$evidence/embedded/$snapshot"
    rm -- "$state/embedded.sqlite" ;;
esac
for role in "${roles[@]}"; do
  docker run --rm --user=1000:1000 --network=none --entrypoint sh \
    -v "$PWD/$state/$role:/runtime" "$image" \
    -c 'find /runtime -mindepth 1 -maxdepth 1 -exec rm -r -- {} +'
done
