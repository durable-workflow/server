#!/usr/bin/env bash
# Installed timeout-cleanup acceptance (Server #1201): the contract vectors
# 1-12 from Workflow's docs/architecture/timeout-cleanup.md against a published
# native Server image (SQLite), with workers on a published SDK. Nothing here
# builds product source.
#
#   SERVER_IMAGE=durableworkflow/server@sha256:... PHP_SDK_VERSION=3.31.0 \
#     [WORKER_SDK=php|python|rust PYTHON_SDK_VERSION=... RUST_SDK_VERSION=...] \
#     scripts/conformance/timeout-cleanup/run.sh [evidence-dir]
#
# The driver always uses the PHP SDK's codec from Packagist. WORKER_SDK picks
# the workers: worker.php (Packagist), python/worker.py (PyPI) or rust/
# (crates.io); all three register the same workflow types and write the same
# observations. PYTHON_SDK_SOURCE or RUST_SDK_SOURCE, a source checkout,
# replaces the published package to try an unreleased SDK; such a run is not
# installed evidence.
#
# EXPECT_ADVERTISED=true|false also checks the Server's timeout_cleanup
# advertisement in /api/cluster/info. Containers, the network (unless NETWORK
# names an existing one) and the PHP image this creates are removed on exit.
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
evidence=$(mkdir -p "${1:-$PWD/timeout-cleanup-evidence}" && cd "${1:-$PWD/timeout-cleanup-evidence}" && pwd)
: "${SERVER_IMAGE:?Set SERVER_IMAGE to a published native Server image}"
: "${PHP_SDK_VERSION:?Set PHP_SDK_VERSION to a published PHP SDK version}"
php_base=${PHP_IMAGE:-php:8.3-cli-bookworm}
composer_image=${COMPOSER_IMAGE:-composer:2}
prefix=${NAME_PREFIX:-dw-timeout-cleanup-$$}
limits=(--init --cpus "${CONTAINER_CPUS:-2}" --memory "${CONTAINER_MEMORY:-2g}" --pids-limit 1024)
token=timeout-cleanup-token
user="$(id -u):$(id -g)"
work=$(mktemp -d)
php_image="$prefix-php:local"
worker_sdk=${WORKER_SDK:-php}
worker_image="$php_image"
worker_cmd=(php /harness/worker.php)
created_network=
if [[ -n "${NETWORK:-}" ]]; then
  network=$NETWORK
else
  network="$prefix-net"
  docker network create "$network" >/dev/null
  created_network=1
fi

containers=()
cleanup() {
  status=$?
  for container in "${containers[@]}"; do
    docker logs "$container" > "$evidence/$container.log" 2>&1 || true
    docker rm -f "$container" >/dev/null 2>&1 || true
  done
  docker image rm "$php_image" "$prefix-python:local" "$prefix-rust:local" >/dev/null 2>&1 || true
  [[ -n "$created_network" ]] && docker network rm "$network" >/dev/null 2>&1 || true
  rm -rf "$work"
  exit "$status"
}
trap cleanup EXIT

# The harness and the exact published SDK, installed without dev packages.
cp "$here/worker.php" "$here/vectors.php" "$work/"
printf '{"require":{"durable-workflow/sdk":"%s"},"config":{"platform-check":false}}\n' "$PHP_SDK_VERSION" > "$work/composer.json"
docker run --rm --name "$prefix-composer" "${limits[@]}" --user "$user" -e COMPOSER_HOME=/tmp/composer -v "$work:/app" -w /app "$composer_image" \
  install --no-dev --no-interaction --no-progress --ignore-platform-req=ext-pcntl >/dev/null
docker run --rm --name "$prefix-composer" "${limits[@]}" --user "$user" -v "$work:/app" -w /app "$composer_image" \
  show --format=json durable-workflow/sdk > "$evidence/php-sdk.json"
# Cooperative PHP workers need pcntl and posix.
printf 'FROM %s\nRUN docker-php-ext-install pcntl >/dev/null\n' "$php_base" | docker build -q -t "$php_image" - >/dev/null

worker_sdk_label="PHP SDK $PHP_SDK_VERSION (Packagist)"
case "$worker_sdk" in
  php) ;;
  python)
    cp -r "$here/python" "$work/python"
    if [[ -n "${PYTHON_SDK_SOURCE:-}" ]]; then
      cp -r "$PYTHON_SDK_SOURCE" "$work/python/sdk"
      requirement=/src/sdk
      worker_sdk_label="Python SDK source $(git -C "$PYTHON_SDK_SOURCE" rev-parse --short HEAD 2>/dev/null || echo checkout) (not installed evidence)"
    else
      : "${PYTHON_SDK_VERSION:?Set PYTHON_SDK_VERSION to a published Python SDK version}"
      requirement="durable-workflow==$PYTHON_SDK_VERSION"
      worker_sdk_label="Python SDK $PYTHON_SDK_VERSION (PyPI)"
    fi
    printf 'FROM %s\nCOPY . /src\nRUN pip install --no-cache-dir --quiet "%s" && pip show durable-workflow > /sdk-version.txt\n' \
      "${PYTHON_IMAGE:-python:3.12-slim-bookworm}" "$requirement" | docker build -q -t "$prefix-python:local" -f - "$work/python" >/dev/null
    docker run --rm --name "$prefix-pip" "$prefix-python:local" cat /sdk-version.txt > "$evidence/python-sdk.txt"
    worker_image="$prefix-python:local"
    worker_cmd=(python /harness/python/worker.py)
    ;;
  rust)
    cp -r "$here/rust" "$work/rust"
    if [[ -n "${RUST_SDK_SOURCE:-}" ]]; then
      cp -r "$RUST_SDK_SOURCE" "$work/rust/sdk"
      rm -rf "$work/rust/sdk/target"
      dependency='{ path = "sdk" }'
      worker_sdk_label="Rust SDK source $(git -C "$RUST_SDK_SOURCE" rev-parse --short HEAD 2>/dev/null || echo checkout) (not installed evidence)"
    else
      : "${RUST_SDK_VERSION:?Set RUST_SDK_VERSION to a published Rust SDK version}"
      dependency="\"=$RUST_SDK_VERSION\""
      worker_sdk_label="Rust SDK $RUST_SDK_VERSION (crates.io)"
    fi
    sed "s|^durable-workflow = .*|durable-workflow = $dependency|" "$here/rust/Cargo.toml" > "$work/rust/Cargo.toml"
    docker run --rm --name "$prefix-cargo" "${limits[@]}" --user "$user" -e CARGO_HOME=/work/.cargo -e CARGO_TARGET_DIR=/work/target \
      -v "$work/rust:/work" -w /work "${RUST_IMAGE:-rust:1.99.0-bookworm}" \
      bash -c 'cargo build --release --quiet && cargo tree --depth 1 --prefix none | grep "^durable-workflow "' > "$evidence/rust-sdk.txt"
    printf 'FROM %s\n' "${RUST_RUNTIME_IMAGE:-debian:bookworm-slim}" | docker build -q -t "$prefix-rust:local" - >/dev/null
    worker_image="$prefix-rust:local"
    worker_cmd=(/harness/rust/target/release/tc-worker)
    ;;
  *) echo "WORKER_SDK must be php, python or rust" >&2; exit 2 ;;
esac

mkdir -p "$work/state" "$evidence"
: > "$evidence/results.jsonl"
: > "$evidence/observations.jsonl"
docker run -d --name "$prefix-server" "${limits[@]}" --user "$user" --network "$network" --network-alias server \
  -e DW_RUST_EXPERIMENTAL=1 -e DW_AUTH_TOKEN="$token" -e DB_DATABASE=/state/server.sqlite \
  -e DW_BIND_ADDRESS=0.0.0.0:8080 -e DW_WORKFLOW_TASK_TIMEOUT=8 \
  -v "$work/state:/state" "$SERVER_IMAGE" >/dev/null
containers+=("$prefix-server")

php() {
  docker run --rm "${limits[@]}" --user "$user" --network "$network" -e TC_TOKEN="$token" \
    -e TC_RUN_SUFFIX="$suffix" -v "$work:/harness:ro" -v "$evidence:/evidence" "$php_image" php "/harness/$1" "${@:2}"
}

worker() {
  local name=$1; shift
  docker run -d --name "$prefix-$name" "${limits[@]}" --user "$user" --network "$network" -e TC_TOKEN="$token" \
    -e TC_WORKER_ID="$name" -e TC_SERVER_URL=http://server:8080 -e TC_OBSERVATIONS=/evidence/observations.jsonl \
    "$@" -v "$work:/harness:ro" -v "$evidence:/evidence" "$worker_image" "${worker_cmd[@]}" >/dev/null
  containers+=("$prefix-$name")
}

for _ in $(seq 60); do
  if docker exec "$prefix-server" bash -c 'exec 3<>/dev/tcp/127.0.0.1/8080 && printf "GET /api/ready HTTP/1.0\r\n\r\n" >&3 && head -n1 <&3 | grep -q " 200 "' 2>/dev/null; then
    break
  fi
  sleep 1
done
suffix=$(date +%s)

worker main -e TC_QUEUE=timeout-cleanup
worker plain -e TC_QUEUE=tc-plain-queue -e TC_COOPERATIVE=0
worker doomed -e TC_QUEUE=tc-replace -e TC_DIE_ON_CLEANUP=1
sleep 5

docker run --rm --name "$prefix-discovery" "${limits[@]}" --network "$network" "$php_image" php -r '
  $context = stream_context_create(["http" => ["header" => "Authorization: Bearer '"$token"'\r\nX-Namespace: default\r\nX-Durable-Workflow-Control-Plane-Version: 2\r\n"]]);
  echo file_get_contents("http://server:8080/api/cluster/info", false, $context);' > "$evidence/cluster-info.json"
advertised=$(jq -c '.capabilities.timeout_cleanup // null' "$evidence/cluster-info.json")
echo "Server timeout_cleanup advertisement: $advertised"
failed=0
if [[ -n "${EXPECT_ADVERTISED:-}" ]]; then
  expected=null
  [[ "$EXPECT_ADVERTISED" == true ]] && expected='{"supported":true,"version":1}'
  if [[ "$advertised" == "$expected" ]] \
    && [[ "$(jq -c '.worker_protocol.server_capabilities.timeout_cleanup // null' "$evidence/cluster-info.json")" == "$expected" ]]; then
    echo '{"vector":"discovery","check":"advertisement matches EXPECT_ADVERTISED","passed":true}' >> "$evidence/results.jsonl"
  else
    echo "{\"vector\":\"discovery\",\"check\":\"advertisement matches EXPECT_ADVERTISED\",\"passed\":false,\"detail\":$advertised}" >> "$evidence/results.jsonl"
    failed=1
  fi
fi

php vectors.php main || failed=1

# Vector 5: the Server is paused from before the work deadline until after
# it, so the engine first notices it late.
resume_at=$(php vectors.php late-start | tail -n1)
docker pause "$prefix-server" >/dev/null
date +%s.%N > "$evidence/paused-at"
while (( $(date +%s) < resume_at )); do sleep 1; done
docker unpause "$prefix-server" >/dev/null
date +%s.%N > "$evidence/unpaused-at"
php vectors.php late-check || failed=1

# Vector 6: the worker holding the cleanup turn dies; a fresh process with
# the same code finishes it once the task lease expires.
php vectors.php replace-start || failed=1
timeout 60 docker wait "$prefix-doomed" > "$evidence/doomed-exit-code" || failed=1
worker replacement -e TC_QUEUE=tc-replace
php vectors.php replace-check || failed=1

passed=$(jq -s '[.[] | select(.passed)] | length' "$evidence/results.jsonl")
total=$(jq -s 'length' "$evidence/results.jsonl")
{
  echo "### Installed timeout cleanup vectors"
  echo
  echo "- Server image: \`$SERVER_IMAGE\` ($(docker image inspect "$SERVER_IMAGE" --format '{{index .RepoDigests 0}}' 2>/dev/null || echo unresolved))"
  echo "- Workers: $worker_sdk_label"
  echo "- Driver codec: \`$(jq -r '.name + " " + (.versions[0] // "")' "$evidence/php-sdk.json")\` from Packagist, source \`$(jq -r '.source.reference // "unknown"' "$evidence/php-sdk.json")\`"
  echo "- Server advertisement: \`$advertised\`"
  echo "- Checks passed: $passed of $total"
  echo
  echo "| Vector | Check | Result |"
  echo "| --- | --- | --- |"
  jq -r '"| \(.vector) | \(.check) | \(if .passed then "pass" else "FAIL" end) |"' "$evidence/results.jsonl"
} > "$evidence/summary.md"
[[ -n "${GITHUB_STEP_SUMMARY:-}" ]] && cat "$evidence/summary.md" >> "$GITHUB_STEP_SUMMARY"
cat "$evidence/summary.md"
[[ "$failed" == 0 && "$passed" == "$total" ]]
