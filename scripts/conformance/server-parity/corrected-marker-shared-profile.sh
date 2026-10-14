#!/usr/bin/env bash
# The caller owns the isolated DB/network and already-running native server.
set -euo pipefail
backend="$1"
native_url=${2:-}
test -n "${RESOURCE_SCOPE:?Set the owning isolated network}"
profile=tests/Fixtures/ServerParityProfiles/corrected-markers
export DW_PARITY_CORRECTED_ARTIFACTS=${DW_PARITY_CORRECTED_ARTIFACTS:-$profile/artifacts-workflow-2.5.16.json}
export DW_PARITY_CORRECTED_STATE=${DW_PARITY_CORRECTED_STATE:-parity-corrected-state}
export DW_PARITY_CORRECTED_EVIDENCE=${DW_PARITY_CORRECTED_EVIDENCE:-parity-evidence/corrected-marker}
export DW_PARITY_CORRECTED_PREFIX=${DW_PARITY_CORRECTED_PREFIX:-corrected-shared-v1}
export DW_PARITY_CORRECTED_OWNERSHIP_DIR="$DW_PARITY_CORRECTED_EVIDENCE"
export DW_PARITY_TOKEN=parity-test-token
artifacts="$DW_PARITY_CORRECTED_ARTIFACTS"
state="$DW_PARITY_CORRECTED_STATE"
evidence="$DW_PARITY_CORRECTED_EVIDENCE"
image=$(jq -r '.server.images["linux/amd64"]' "$artifacts")
resource="$RESOURCE_SCOPE-corrected"
mkdir -p "$state" "$evidence"
chmod 0777 "$state" "$evidence"
database=()
case "$backend" in
  mysql) database=(-e DB_CONNECTION=mysql -e DB_HOST=parity-mysql -e DB_DATABASE=corrected_php_ref -e DB_USERNAME=root -e DB_PASSWORD=parity-disposable-password) ;;
  pgsql) database=(-e DB_CONNECTION=pgsql -e DB_HOST=parity-pg -e DB_DATABASE=corrected_php_ref -e DB_USERNAME=parity -e DB_PASSWORD=parity-disposable-password) ;;
  sqlite) database=(-e DB_CONNECTION=sqlite -e DB_DATABASE=/state/php.sqlite -v "$PWD/$state:/state") ;;
  *) exit 2 ;;
esac
test -n "$native_url"
test ! -e "$state/php.sqlite"
test ! -e "$state/current/artisan"
test ! -e "$state/original/artisan"
test ! -e "$evidence/php-database-owned.txt"
case "$backend" in
  mysql) docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" -h parity-mysql -uroot -pparity-disposable-password -e 'CREATE DATABASE corrected_php_ref CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' > "$evidence/database-create.txt" ;;
  pgsql) docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password --entrypoint createdb "$POSTGRES_IMAGE" -h parity-pg -U parity corrected_php_ref > "$evidence/database-create.txt" ;;
  sqlite) test ! -e "$state/php.sqlite"; touch "$state/php.sqlite"; chmod 0666 "$state/php.sqlite" ;;
esac
printf '%s\n' "$RESOURCE_SCOPE" > "$evidence/php-database-owned.txt"
engine=(-e CACHE_STORE=file -e QUEUE_CONNECTION=database -e DW_AUTH_TOKEN=parity-test-token
  -e DW_SERVER_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=)
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network "$RESOURCE_SCOPE" --entrypoint php "${database[@]}" "${engine[@]}" "$image" artisan server:bootstrap --force > "$evidence/php-bootstrap.txt"
docker run -d --name "$resource-php" --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network "$RESOURCE_SCOPE" --network-alias parity-corrected-php "${database[@]}" "${engine[@]}" "$image" > "$evidence/php-container.txt"
docker exec "$resource-php" sh -c 'for attempt in $(seq 1 30); do if curl -fsS http://127.0.0.1:8080/api/ready; then exit 0; fi; sleep 1; done; exit 1' > "$evidence/php-ready.json" 2> "$evidence/php-ready.stderr"
# Only the four deadline originals need the package expiry sweep. A global
# redispatch sweep would independently repair the deliberately idle neighbors
# used to prove that selected-run replay repair has no cross-run effect.
docker run -d --name "$resource-maintenance" --user=1000:1000 --cpus=0.25 --memory=256m --memory-swap=256m --network "$RESOURCE_SCOPE" --entrypoint sh "${database[@]}" "${engine[@]}" -e DW_PARITY_CORRECTED_PREFIX "$image" -c 'while :; do for field in start_to_close_timeout schedule_to_start_timeout schedule_to_close_timeout heartbeat_timeout; do php artisan workflow:v2:repair-pass --instance-id="$DW_PARITY_CORRECTED_PREFIX-activity-deadline-authority-$field" --json; done; sleep 1; done' > "$evidence/maintenance-container.txt"
for target in php rust; do
  url="$native_url"
  if test "$target" = php; then url=http://parity-corrected-php:8080; fi
  bash scripts/conformance/server-parity/corrected-marker-http-profile.sh "$target" "$url" portable
  bash scripts/conformance/server-parity/corrected-marker-http-profile.sh "$target" "$url" clock
  bash scripts/conformance/server-parity/corrected-marker-http-profile.sh "$target" "$url" deadlines
done
# Reuse the published package enforcer in one bounded process. It cannot repair
# a retained parent task or touch a marker/range neighbor's original workflow.
docker stop --time 5 "$resource-maintenance"
docker run -d --name "$resource-retries-maintenance" --user=1000:1000 --cpus=0.25 --memory=256m --memory-swap=256m --network "$RESOURCE_SCOPE" --entrypoint php "${database[@]}" "${engine[@]}" -e DW_PARITY_CORRECTED_PREFIX -v "$PWD:/source:ro" "$image" /source/scripts/conformance/server-parity/activity-retries-maintenance.php > "$evidence/retries-maintenance-container.txt"
for target in php rust; do
  url="$native_url"
  if test "$target" = php; then url=http://parity-corrected-php:8080; fi
  bash scripts/conformance/server-parity/corrected-marker-http-profile.sh "$target" "$url" retries
  bash scripts/conformance/server-parity/corrected-marker-http-profile.sh "$target" "$url" memos
done
docker stop --time 5 "$RESOURCE_SCOPE-corrected-php" "$RESOURCE_SCOPE-corrected-retries-maintenance"
bash scripts/conformance/server-parity/corrected-marker-embedded-profile.sh "$backend" portable
bash scripts/conformance/server-parity/corrected-marker-embedded-profile.sh "$backend" deadlines
bash scripts/conformance/server-parity/corrected-marker-embedded-profile.sh "$backend" retries
bash scripts/conformance/server-parity/corrected-marker-embedded-profile.sh "$backend" memos
fixtures=()
for id in fresh-numeric fresh-zero deprecation-first genuine-version-one php-full-retry php-full-retry-refusal rust-range-completed rust-range-timer-pending; do fixtures+=(--fixture "$profile/$id.json"); done
shared=(-v "$PWD:/source:ro" -v "$PWD/$evidence:/evidence" -w /source)
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network none --entrypoint node "${shared[@]}" "$image" scripts/conformance/server-parity.mjs compare /evidence/php/portable-record.json /evidence/rust/portable-record.json /evidence/embedded/record.json --artifacts "$artifacts" "${fixtures[@]}" > "$evidence/portable-comparison.json"
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network none --entrypoint node "${shared[@]}" "$image" scripts/conformance/server-parity.mjs compare /evidence/php/clock-record.json /evidence/rust/clock-record.json --artifacts "$artifacts" --fixture "$profile/python-clock-zero.json" --fixture "$profile/python-clock-genuine-one.json" > "$evidence/clock-comparison.json"
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network none --entrypoint node "${shared[@]}" "$image" scripts/conformance/server-parity.mjs compare /evidence/php/deadlines-record.json /evidence/rust/deadlines-record.json /evidence/embedded/deadlines-record.json --artifacts "$artifacts" --fixture tests/Fixtures/ServerParityPending/activity-deadline-authority.json > "$evidence/deadlines-comparison.json"
retry_fixtures=()
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network none --entrypoint node "${shared[@]}" "$image" scripts/conformance/server-parity.mjs compare /evidence/php/memos-record.json /evidence/rust/memos-record.json /evidence/embedded/memos-record.json --artifacts "$artifacts" --fixture tests/Fixtures/ServerParityPending/memo-initial-activity-replay.json > "$evidence/memos-comparison.json"
for id in activity-deadline-active-receipts activity-deadline-receipts activity-global-deadline-retry activity-heartbeat-renewal; do retry_fixtures+=(--fixture "tests/Fixtures/ServerParityPending/$id.json"); done
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network none --entrypoint node "${shared[@]}" "$image" scripts/conformance/server-parity.mjs compare /evidence/php/retries-record.json /evidence/rust/retries-record.json /evidence/embedded/retries-record.json --artifacts "$artifacts" "${retry_fixtures[@]}" > "$evidence/retries-comparison.json"
for target in php rust embedded; do
  mode=http
  if test "$target" = embedded; then mode=embedded; fi
  docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network none --entrypoint node "${shared[@]}" "$image" scripts/conformance/server-parity/memo-readers.mjs "/evidence/$target/$DW_PARITY_CORRECTED_PREFIX-memo-initial-activity-replay-memo.json" tests/Fixtures/ServerParityPending/memo-initial-activity-replay.json "$artifacts" "$mode" > "$evidence/$target/memos-reader-controls.json"
  record="/evidence/$target/portable-record.json"
  if test "$target" = embedded; then record=/evidence/embedded/record.json; fi
  docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network none --entrypoint node "${shared[@]}" "$image" scripts/conformance/server-parity/corrected-markers/record-readers.mjs "$record" "/evidence/$target/reader-inputs" portable > "$evidence/$target/reader-controls.json"
  if test "$target" != embedded; then docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network none --entrypoint node "${shared[@]}" "$image" scripts/conformance/server-parity/corrected-markers/record-readers.mjs "/evidence/$target/clock-record.json" "/evidence/$target/clock-reader-inputs" clock > "$evidence/$target/clock-reader-controls.json"; fi
  docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network none --entrypoint node "${shared[@]}" "$image" scripts/conformance/server-parity/activity-deadline-readers.mjs "/evidence/$target/deadlines-record.json" > "$evidence/$target/deadlines-reader-controls.json"
  for id in activity-deadline-active-receipts activity-deadline-receipts; do
    docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network none --entrypoint node "${shared[@]}" "$image" scripts/conformance/server-parity/activity-deadline-receipts-readers.mjs "/evidence/$target/retries-record.json" "$id" > "$evidence/$target/$id-reader-controls.json"
  done
  for family in global-retry heartbeat-renewal; do
    docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network none --entrypoint node "${shared[@]}" "$image" "scripts/conformance/server-parity/activity-$family-readers.mjs" "/evidence/$target/retries-record.json" > "$evidence/$target/$family-reader-controls.json"
  done
done
