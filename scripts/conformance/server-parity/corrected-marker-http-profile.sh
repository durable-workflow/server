#!/usr/bin/env bash
# Run the separate published corrected actors against one isolated HTTP target.
# The caller owns server bootstrap, readiness, database isolation and cleanup.
set -euo pipefail
target="$1"
url="$2"
family=${3:-all}
test -n "$RESOURCE_SCOPE"
case "$target" in php|rust) ;; *) exit 2 ;; esac
case "$family" in
  all) families=(markers range clock) ;;
  markers|range|clock|portable|deadlines|retries|memos) families=("$family") ;;
  *) exit 2 ;;
esac
profile=tests/Fixtures/ServerParityProfiles/corrected-markers
artifacts=${DW_PARITY_CORRECTED_ARTIFACTS:-$profile/artifacts.json}
adapters=${DW_PARITY_CORRECTED_ADAPTERS:-corrected-marker-adapters}
image=$(jq -r '.server.images["linux/amd64"]' "$artifacts")
evidence=${DW_PARITY_CORRECTED_EVIDENCE:-parity-evidence/corrected-marker}
prefix=${DW_PARITY_CORRECTED_PREFIX:-corrected-markers-v1}
mkdir -p "$evidence/$target"
chmod 0777 "$evidence/$target"
test "$(cat "$adapters/runner-revision.txt")" != ''
# A native-only correction may reuse the unchanged installed actors. Preserve
# their build revision separately; every compiled actor source must still match.
cp "$adapters/runner-revision.txt" "$evidence/$target/adapter-build-revision.txt"
cp "$adapters/identity.json" "$evidence/$target/adapter-identity.json"
sha256sum -c "$adapters/source-hashes.sha256" > "$evidence/$target/actor-integrity.txt"
printf '%s  %s\n' "$(jq -r '.binary_sha256' "$adapters/identity.json")" "$adapters/bin/rust-worker" \
  | sha256sum -c >> "$evidence/$target/actor-integrity.txt"
chmod 0755 "$adapters/bin/rust-worker"
shared=(-v "$PWD:/source:ro" -v "$PWD/$evidence:/evidence" -w /source
  -v "$PWD/$adapters/php:/sdk-php:ro" -v "$PWD/$adapters/php-range:/sdk-php-range:ro"
  -v "$PWD/$adapters/bin:/sdk-adapters:ro"
  -v "$PWD/$adapters/python250:/sdk-python250:ro"
  -v "$PWD/$adapters/python-current:/sdk-python-current:ro"
  -e PYTHONPATH=/sdk-python250 -e DW_PARITY_SDK_AUTOLOAD=/sdk-php/vendor/autoload.php
  -e DW_PARITY_CORRECTED_RANGE_PHP_ROOT=/sdk-php-range
  -e DW_PARITY_CORRECTED_PYTHON_CURRENT_ROOT=/sdk-python-current
  -e DW_PARITY_CORRECTED_RUST_WORKER=/sdk-adapters/rust-worker
  -e DW_PARITY_CORRECTED_RUST_LOCK=/sdk-adapters/Cargo.lock
  -e DW_PARITY_TOKEN -e DW_PARITY_RUNNER_REVISION="$(git rev-parse HEAD)")
# Separate records preserve completed families when a later boundary fails.
failed=0
for family in "${families[@]}"; do
  case "$family" in
    markers) ids=(fresh-numeric fresh-zero deprecation-first genuine-version-one php-full-retry php-full-retry-refusal) ;;
    range) ids=(rust-range-completed rust-range-timer-pending) ;;
    clock) ids=(python-clock-zero python-clock-genuine-one) ;;
    portable) ids=(fresh-numeric fresh-zero deprecation-first genuine-version-one php-full-retry php-full-retry-refusal rust-range-completed rust-range-timer-pending) ;;
    deadlines) ids=(activity-deadline-authority) ;;
    retries) ids=(activity-deadline-active-receipts activity-deadline-receipts activity-global-deadline-retry activity-heartbeat-renewal) ;;
    memos) ids=(memo-initial-activity-replay) ;;
  esac
  fixtures=()
  for id in "${ids[@]}"; do
    path="$profile/$id.json"
    if test "$family" = deadlines || test "$family" = retries || test "$family" = memos; then path="tests/Fixtures/ServerParityPending/$id.json"; fi
    fixtures+=(--fixture "$path")
  done
  status=0
  recorder="$RESOURCE_SCOPE-corrected-$target-$family-recorder"
  docker run --name "$recorder" --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
    --network "$RESOURCE_SCOPE" --entrypoint node "${shared[@]}" "$image" \
    scripts/conformance/server-parity.mjs record --mode http --url "$url" --target "$target" \
    --prefix "$prefix" --artifacts "$artifacts" "${fixtures[@]}" \
    --output "/evidence/$target/$family-record.json" || status=$?
  docker inspect --format '{{json .State}}' "$recorder" > "$evidence/$target/$family-recorder-state.json"
  printf '%s\n' "$status" > "$evidence/$target/$family-status.txt"
  if test "$status" != 0; then failed=1; fi
done
exit "$failed"
