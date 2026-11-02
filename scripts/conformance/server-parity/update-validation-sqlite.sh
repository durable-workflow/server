#!/usr/bin/env bash
#   update-validation-sqlite.sh <php url> <native url> [fixture name]
#
# Server #723. The SQLite job records the update-validation fixture apart from
# its main corpus, for all three targets, and compares the three records. The
# optional third argument names another fixture recorded the same way:
# service-calls, whose published PHP record under SQLite writer contention has
# refused the first call to a limited operation with 429 (Server #796).
#
# The published PHP record runs with PHP's maintenance loop stopped. On SQLite
# the API server and that loop share one database file. Under the loop's writer
# contention, published PHP 2.11.1 loses its own validation-timeout and
# lease-reclaim writes to "database is locked", and its validation poll answers
# without the typed backend_lock_pressure refusal. Locally 10 of 15 runs failed
# with the loop running and 5 of 5 passed with it stopped. This fixture tests
# update validation, not writer contention, and needs no repair pass or
# schedule. The loop restarts right after the PHP record. The MySQL and
# PostgreSQL jobs record this fixture in their main corpus with their loops
# running.
#
# Server #754. Even with the loop stopped, the probe's own concurrent updates
# can cost published PHP a write: 2.11.1 claims a validation task with a read
# and then a write in one deferred SQLite transaction, and when another writer
# commits in between, SQLite refuses the upgrade at once ("database is
# locked"), whatever the busy timeout. Current PHP retries that write; the
# published reference cannot. So when the PHP record fails and the PHP server
# logged that error during it, the PHP record runs once more under a fresh
# workflow prefix, and embedded and Rust record under that prefix too, as
# compare requires. Any other PHP failure, and any embedded or Rust failure,
# fails at once. A retry leaves a warning and the first attempt's files.
set -euo pipefail
php_url="$1"
native_url="$2"
name="${3:-update-validation}"
[[ "$name" =~ ^[a-z0-9-]+$ ]]
[[ -n "$RESOURCE_SCOPE" ]]
[[ -n "$DW_PARITY_RUNNER_REVISION" ]]
fixture="tests/Fixtures/ServerParity/$name.json"
test -f "$fixture"
image=$(jq -r '.server.images["linux/amd64"] | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' tests/Fixtures/ServerParityBaselines/php-2.11.8.json)
. scripts/conformance/server-parity/php-maintenance-window.sh

prefix=parity-v1
http_record() {
  local url="$1" target="$2"
  docker run --rm --user=1000:1000 --entrypoint node --network "$RESOURCE_SCOPE" \
    -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -w /source \
    -e DW_PARITY_TOKEN=parity-test-token -e DW_PARITY_RUNNER_REVISION "$image" scripts/conformance/server-parity.mjs record \
    --mode http --url "$url" --target "$target" --output "/evidence/$name-$target.json" --fixture "$fixture" \
    --prefix "$prefix"
}

# run_without_maintenance runs its command under errexit's exemption, so the
# command keeps the record's status instead of returning it. The record's own
# start and end bound the PHP log lines that can justify a retry.
php_status=0
php_record() {
  php_status=0
  record_started=$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)
  http_record "$php_url" php || php_status=$?
  record_finished=$(date -u +%Y-%m-%dT%H:%M:%S.%NZ)
}

run_without_maintenance "parity-evidence/$name-maintenance-schedule.txt" -- php_record
if ((php_status != 0)); then
  locked="parity-evidence/$name-php.attempt-1.locked.txt"
  { docker logs --since "$record_started" --until "$record_finished" "$RESOURCE_SCOPE-server" 2>&1 || true; } \
    | grep -m 5 'database is locked' | cut -c 1-400 > "$locked" || true
  if [[ ! -s "$locked" ]]; then
    rm -f "$locked"
    exit "$php_status"
  fi
  for file in "$name-php.json" "$name-php.failure-excerpt.txt"; do
    if [[ -e "parity-evidence/$file" ]]; then
      mv "parity-evidence/$file" "parity-evidence/${file/$name-php./$name-php.attempt-1.}"
    fi
  done
  excerpt=$(tr '\n' ' ' < "parity-evidence/$name-php.attempt-1.failure-excerpt.txt" 2>/dev/null || true)
  echo "::warning title=Published PHP $name record retried::The PHP record failed (${excerpt:-no excerpt}) after published PHP logged 'database is locked'; recording all three targets under a fresh prefix (Server #754)."
  prefix=parity-v1-retry
  run_without_maintenance "parity-evidence/$name-maintenance-schedule-retry.txt" -- php_record
  ((php_status == 0)) || exit "$php_status"
fi
docker run --rm --user=1000:1000 --entrypoint node \
  -v "$PWD:/source:ro" -v "$PWD/parity-state:/state" -v "$PWD/parity-evidence:/evidence" -w /source \
  -e DB_CONNECTION=sqlite -e DB_DATABASE=/state/embedded.sqlite -e DW_MODE=embedded \
  -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e DW_PARITY_RUNNER_REVISION "$image" scripts/conformance/server-parity.mjs record \
  --mode embedded --application-root /app --target embedded --output "/evidence/$name-embedded.json" --fixture "$fixture" \
  --prefix "$prefix"
http_record "$native_url" rust
docker run --rm --user=1000:1000 --entrypoint node \
  -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -w /source "$image" \
  scripts/conformance/server-parity.mjs compare \
  "/evidence/$name-php.json" "/evidence/$name-rust.json" "/evidence/$name-embedded.json" \
  --fixture "$fixture" > "parity-evidence/$name-comparison.json"
