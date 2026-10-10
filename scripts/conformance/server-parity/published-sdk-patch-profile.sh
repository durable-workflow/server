#!/usr/bin/env bash
# Explicit published consumer cohort, using the owning job's existing targets.
set -euo pipefail
backend="$1"
php_url="$2"
native_url="$3"
test -n "$RESOURCE_SCOPE"
profile=tests/Fixtures/ServerParityProfiles/published-sdk-patch-replay
image=$(jq -r '.server.images["linux/amd64"] | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' "$profile/artifacts.json")
mkdir -p parity-evidence/published-sdk-patch/{php,rust,embedded}
chmod 0777 parity-evidence/published-sdk-patch parity-evidence/published-sdk-patch/{php,rust,embedded}
(cd published-sdk-adapters && sha256sum -c SHA256SUMS) > parity-evidence/published-sdk-patch/adapter-integrity.txt
test "$(cat published-sdk-adapters/runner-revision.txt)" = "$(git rev-parse HEAD)"
sha256sum -c published-sdk-adapters/source-hashes.sha256 >> parity-evidence/published-sdk-patch/adapter-integrity.txt
chmod 0755 published-sdk-adapters/rust-worker
cp published-sdk-adapters/{rust-sdk-build.txt,python-install-report.json,python-install.txt,Cargo.lock,identity.json,runner-revision.txt,source-hashes.sha256} parity-evidence/published-sdk-patch/
fixtures=()
for language in rust python; do
  for checkpoint in pending completed; do
    fixtures+=(--fixture "$profile/patch-$language-activity-$checkpoint.json")
  done
done
shared=(-v "$PWD:/source:ro" -v "$PWD/published-sdk-adapters:/sdk-adapters:ro"
  -v "$PWD/parity-evidence:/evidence" -w /source -e PYTHONPATH=/sdk-adapters/python-packages
  -e DW_PARITY_RUST_PATCH_WORKER=/sdk-adapters/rust-worker
  -e PYTHONDONTWRITEBYTECODE=1 -e DW_PARITY_TOKEN=parity-test-token -e DW_PARITY_RUNNER_REVISION)
export DW_PARITY_RUNNER_REVISION=$(git rev-parse HEAD)
for target in php rust; do
  if test "$target" = php; then url="$php_url"; else url="$native_url"; fi
  docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
    --network "$RESOURCE_SCOPE" --entrypoint node "${shared[@]}" "$image" \
    scripts/conformance/server-parity.mjs record --mode http --url "$url" --target "$target" \
    --prefix published-sdk-v1 --artifacts "$profile/artifacts.json" "${fixtures[@]}" \
    --output "/evidence/published-sdk-patch/$target/record.json"
done
case "$backend" in
  mysql) embedded=(-e DB_CONNECTION=mysql -e DB_HOST=parity-mysql -e DB_DATABASE=embedded_ref
    -e DB_USERNAME=root -e DB_PASSWORD=parity-disposable-password) ;;
  pgsql) embedded=(-e DB_CONNECTION=pgsql -e DB_HOST=parity-pg -e DB_DATABASE=embedded_ref
    -e DB_USERNAME=parity -e DB_PASSWORD=parity-disposable-password) ;;
  sqlite) embedded=(-e DB_CONNECTION=sqlite -e DB_DATABASE=/state/embedded.sqlite -v "$PWD/parity-state:/state") ;;
  *) exit 2 ;;
esac
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
  --network "$RESOURCE_SCOPE" --entrypoint node "${shared[@]}" "${embedded[@]}" \
  -e DW_MODE=embedded -e CACHE_STORE=file -e QUEUE_CONNECTION=database "$image" \
  scripts/conformance/server-parity.mjs record --mode embedded --application-root /app --target embedded \
  --prefix published-sdk-v1 --artifacts "$profile/artifacts.json" "${fixtures[@]}" \
  --output /evidence/published-sdk-patch/embedded/record.json
docker run --rm --user=1000:1000 --network=none --entrypoint node "${shared[@]}" "$image" \
  scripts/conformance/server-parity.mjs compare /evidence/published-sdk-patch/php/record.json \
  /evidence/published-sdk-patch/rust/record.json /evidence/published-sdk-patch/embedded/record.json \
  "${fixtures[@]}" > parity-evidence/published-sdk-patch/comparison.json
