#!/usr/bin/env bash
# Differential qualification of a native server image (Server #325): record
# the shared fixture corpus on SQLite against the published PHP reference,
# embedded PHP and the given native image, and compare the three records, as
# the Shared Server fixtures corpus part does for a native source build.
#
#   native-image-corpus.sh <native image reference>
#
# The reference is a digest-pinned image (name@sha256:...), such as a
# published native release-candidate index or a pull request's local build
# tag. The native server runs from the image itself: its entrypoint, its user
# and its maintenance loop, with SQLite in its /app/database volume.
#
# Containers and the network are named from RESOURCE_SCOPE exactly as the
# corpus part names them, dw-parity-<run>-<attempt>-<job>. The workflow
# removes them in an always step. Evidence lands in parity-evidence/, with a
# receipt naming the image.
set -euo pipefail
shopt -s inherit_errexit
test "$#" = 1 || { echo "usage: $0 <native image reference>" >&2; exit 2; }
native=$1
[[ $native =~ @sha256:[0-9a-f]{64}$ || $native =~ ^dw-native-[a-z0-9-]+:[a-z0-9.-]+$ ]] \
  || { echo "The native image must be pinned by digest (name@sha256:...) or be this job's local build." >&2; exit 2; }
scope="dw-parity-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-parity}"
[[ "${RESOURCE_SCOPE:-$scope}" == "$scope" ]] \
  || { echo "RESOURCE_SCOPE must be $scope, the corpus part's own container and network name." >&2; exit 2; }
export RESOURCE_SCOPE="$scope"
export DW_PARITY_RUNNER_REVISION
DW_PARITY_RUNNER_REVISION=$(git rev-parse HEAD)
image=$(jq -r '.server.images["linux/amd64"] | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' tests/Fixtures/ServerParityBaselines/php-2.11.8.json)

# CI's corpus selection: the reviewed corpus without service-calls, which
# records separately below with PHP's maintenance loop stopped (Server #796),
# plus the pending candidates it carries.
selection=(--exclude-fixture tests/Fixtures/ServerParity/service-calls.json)
for pending in patch-insertion-activity-pending patch-insertion-activity-completed patch-created-activity-pending \
  patch-created-activity-completed workflow-waiting-history-type workflow-waiting-history-message workflow-task-transient-retry; do
  selection+=(--include-fixture "tests/Fixtures/ServerParityPending/$pending.json")
done

mkdir -p parity-state parity-evidence
chmod 0777 parity-state parity-evidence
touch parity-state/php.sqlite parity-state/embedded.sqlite
chmod 0666 parity-state/*.sqlite
bash scripts/ci/run-composer.sh --rm -v "$PWD:/source" -w /source -- \
  install --working-dir=scripts/conformance/server-parity --no-interaction --no-progress
docker network create "$RESOURCE_SCOPE" >/dev/null

# The native image under test, as an operator runs it. The shared state
# directory is also its /state, where fixtures put their external payload
# storage roots (file:///state/...), as on every corpus server.
docker run -d --name "$RESOURCE_SCOPE-rust" --network "$RESOURCE_SCOPE" --network-alias=parity-rust \
  --cpus=1 --memory=256m --memory-swap=256m -e DW_RUST_EXPERIMENTAL=1 -e DW_AUTH_TOKEN=parity-test-token \
  -e DB_DATABASE=/app/database/rust.sqlite -v "$PWD/parity-state:/app/database" -v "$PWD/parity-state:/state" \
  "$native" >/dev/null

# Published PHP and embedded PHP, as the corpus part runs them.
for target in php embedded; do
  docker run --rm --user=1000:1000 --entrypoint php \
    -v "$PWD/parity-state:/state" -e DB_CONNECTION=sqlite -e DB_DATABASE="/state/$target.sqlite" \
    -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e DW_AUTH_TOKEN=parity-test-token \
    "$image" artisan server:bootstrap --force >> parity-evidence/bootstrap.txt
done
docker run -d --name "$RESOURCE_SCOPE-server" --network "$RESOURCE_SCOPE" --network-alias=parity-server \
  --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
  -v "$PWD/parity-state:/state" -e DB_CONNECTION=sqlite -e DB_DATABASE=/state/php.sqlite -e DB_SQLITE_BUSY_TIMEOUT=30000 \
  -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e DW_AUTH_TOKEN=parity-test-token "$image" >/dev/null
for target in server rust; do
  ready=0
  for _ in $(seq 1 30); do
    if docker run --rm --user=1000:1000 --entrypoint curl --network "$RESOURCE_SCOPE" "$image" \
      -fsS --connect-timeout 2 --max-time 5 "http://parity-$target:8080/api/ready" > "parity-evidence/$target-readiness.json"; then
      ready=1
      break
    fi
    sleep 1
  done
  test "$ready" = 1
done
docker run -d --name "$RESOURCE_SCOPE-php-maintenance" --network "$RESOURCE_SCOPE" \
  --user=1000:1000 --cpus=0.25 --memory=256m --memory-swap=256m --entrypoint sh \
  -v "$PWD/parity-state:/state" -e DB_CONNECTION=sqlite -e DB_DATABASE=/state/php.sqlite -e DB_SQLITE_BUSY_TIMEOUT=30000 \
  -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e DW_AUTH_TOKEN=parity-test-token \
  "$image" -c 'while :; do php artisan workflow:v2:repair-pass --json; php artisan schedule:evaluate --limit=100 --json; sleep 1; done' >/dev/null

mkdir -p parity-consumers
curl --fail --location --retry 3 --silent --output parity-consumers/dw.phar https://github.com/durable-workflow/cli/releases/download/2.2.0/dw.phar
printf '%s  %s\n' "$(jq -r '.visibility.cli.phar_sha256' tests/Fixtures/ServerParity/visibility-current-runs.json)" parity-consumers/dw.phar \
  | sha256sum -c > parity-evidence/cli-artifact.txt
export DW_PARITY_CLI_PHAR=/source/parity-consumers/dw.phar

http_flags=(--network "$RESOURCE_SCOPE" -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -w /source
  -e DW_PARITY_TOKEN=parity-test-token -e DW_PARITY_RUNNER_REVISION -e DW_PARITY_CLI_PHAR)
record_http() {
  local target=$1 url=$2
  docker run --rm --user=1000:1000 --entrypoint node "${http_flags[@]}" "$image" scripts/conformance/server-parity.mjs record \
    --mode http --url "$url" --target "$target" --output "/evidence/$target.json" "${selection[@]}"
}
record_embedded() {
  docker run --rm --user=1000:1000 --entrypoint node \
    -v "$PWD:/source:ro" -v "$PWD/parity-state:/state" -v "$PWD/parity-evidence:/evidence" -w /source \
    -e DB_CONNECTION=sqlite -e DB_DATABASE=/state/embedded.sqlite -e DW_MODE=embedded \
    -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e DW_PARITY_RUNNER_REVISION "$image" scripts/conformance/server-parity.mjs record \
    --mode embedded --application-root /app --target embedded --output /evidence/embedded.json "${selection[@]}"
}
status=0
record_http php http://parity-server:8080 || status=$?
record_embedded || status=$?
native_status=0
record_http rust http://parity-rust:8080 || native_status=$?
compare_status=0
docker run --rm --user=1000:1000 --entrypoint node -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -w /source "$image" \
  scripts/conformance/server-parity.mjs compare /evidence/php.json /evidence/rust.json /evidence/embedded.json "${selection[@]}" \
  > parity-evidence/comparison.json || compare_status=$?
service_calls_status=0
bash scripts/conformance/server-parity/update-validation-sqlite.sh http://parity-server:8080 http://parity-rust:8080 service-calls \
  || service_calls_status=$?

native_id=$(docker image inspect --format '{{.Id}}' "$native")
jq -n --arg native "$native" --arg id "$native_id" --arg php "$image" --arg revision "$DW_PARITY_RUNNER_REVISION" \
  --argjson reference "$status" --argjson nativeStatus "$native_status" --argjson compare "$compare_status" \
  --argjson serviceCalls "$service_calls_status" \
  '{schema:"durable-workflow.native-image-corpus.v1", native_image:$native, native_image_id:$id, php_reference:$php,
    runner_revision:$revision, backend:"sqlite",
    status:{reference_records:$reference, native_record:$nativeStatus, compare:$compare, service_calls:$serviceCalls}}' \
  > parity-evidence/native-image-corpus.json
cat parity-evidence/native-image-corpus.json
test "$status" -eq 0
test "$native_status" -eq 0
test "$compare_status" -eq 0
test "$service_calls_status" -eq 0
