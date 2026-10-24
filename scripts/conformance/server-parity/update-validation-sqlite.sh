#!/usr/bin/env bash
# Server #723. The SQLite job records the update-validation fixture apart from
# its main corpus, for all three targets, and compares the three records.
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
set -euo pipefail
php_url="$1"
native_url="$2"
test -n "$RESOURCE_SCOPE"
test -n "$DW_PARITY_RUNNER_REVISION"
fixture=tests/Fixtures/ServerParity/update-validation.json
image=$(jq -r '.server.images["linux/amd64"] | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' tests/Fixtures/ServerParityBaselines/php-2.11.1.json)
. scripts/conformance/server-parity/php-maintenance-window.sh

http_record() {
  docker run --rm --user=1000:1000 --entrypoint node --network "$RESOURCE_SCOPE" \
    -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -w /source \
    -e DW_PARITY_TOKEN=parity-test-token -e DW_PARITY_RUNNER_REVISION "$image" scripts/conformance/server-parity.mjs record \
    --mode http --url "$1" --target "$2" --output "/evidence/update-validation-$2.json" --fixture "$fixture"
}

run_without_maintenance parity-evidence/update-validation-maintenance-schedule.txt -- http_record "$php_url" php
docker run --rm --user=1000:1000 --entrypoint node \
  -v "$PWD:/source:ro" -v "$PWD/parity-state:/state" -v "$PWD/parity-evidence:/evidence" -w /source \
  -e DB_CONNECTION=sqlite -e DB_DATABASE=/state/embedded.sqlite -e DW_MODE=embedded \
  -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e DW_PARITY_RUNNER_REVISION "$image" scripts/conformance/server-parity.mjs record \
  --mode embedded --application-root /app --target embedded --output /evidence/update-validation-embedded.json --fixture "$fixture"
http_record "$native_url" rust
docker run --rm --user=1000:1000 --entrypoint node \
  -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -w /source "$image" \
  scripts/conformance/server-parity.mjs compare \
  /evidence/update-validation-php.json /evidence/update-validation-rust.json /evidence/update-validation-embedded.json \
  --fixture "$fixture" > parity-evidence/update-validation-comparison.json
