#!/usr/bin/env bash
# The caller owns the isolated target, its original configuration and cleanup.
set -euo pipefail
test "$#" = 6 || { echo 'Expected NETWORK SERVER_CONTAINER URL SDK_DIRECTORY OUTPUT_DIRECTORY TARGET' >&2; exit 2; }
network=$1
server=$2
url=$3
sdk=$(realpath "$4")
mkdir -p "$5"
output=$(realpath "$5")
target=$6
case "$target" in php|rust) ;; *) exit 2 ;; esac
source_root=$(git rev-parse --show-toplevel)
profile=tests/Fixtures/ServerParityProfiles/query-authority/artifacts.json
image=$(jq -r '.server.image' "$profile")
test -n "${RESOURCE_SCOPE:?Set the unique owning resource scope}"
test -f "$sdk/vendor/autoload.php"
cmp scripts/conformance/server-parity/query-authority/composer.lock "$sdk/composer.lock"
docker inspect "$server" | jq -e --arg network "$network" \
  '.[0].State.Running == true and (.[0].NetworkSettings.Networks | has($network))' >/dev/null
if test "$target" = php; then
  docker inspect "$server" | jq -e --arg revision "$(jq -r '.server.source' "$profile")" \
    '.[0].Config.Labels["org.opencontainers.image.revision"] == $revision' >/dev/null
fi
native_binary_sha256=''
if test "$target" = rust; then
  native_binary_sha256=$(docker exec "$server" sha256sum /target/debug/durable-workflow-server | cut -d ' ' -f1)
fi
jq -n --arg revision "$(git rev-parse HEAD)" --arg native_tree "$(git rev-parse HEAD:rust)" \
  --arg server_image "$(docker inspect "$server" --format '{{.Image}}')" \
  --arg native_binary_sha256 "$native_binary_sha256" \
  --arg target "$target" --arg profile_sha256 "$(sha256sum "$profile" | cut -d ' ' -f1)" \
  '{runner_revision:$revision,native_tree:$native_tree,native_binary_sha256:$native_binary_sha256,
    target:$target,server_image:$server_image,
    profile_sha256:$profile_sha256,performance:"not_measured"}' > "$output/identity.json"
while IFS=$'\t' read -r mode protocol; do
  name="$mode-${protocol//./}"
  test ! -e "$output/$name.json"
  timeout --signal=TERM --kill-after=5s 40s \
    docker run --rm --name "$RESOURCE_SCOPE-query-$target-$name" --user="$(id -u):$(id -g)" \
    --cpus=1 --memory=512m --memory-swap=512m --network "$network" --entrypoint php \
    -e DW_PARITY_SDK_AUTOLOAD=/sdk/vendor/autoload.php -e DW_PARITY_TOKEN \
    -v "$source_root:/source:ro" -v "$sdk:/sdk:ro" -w /source "$image" \
    scripts/conformance/server-parity/query-authority/worker.php "$url" "$mode" "$protocol" \
    > "$output/$name.json" 2> "$output/$name.stderr"
  jq -e --arg mode "$mode" --arg protocol "$protocol" \
    '.outcome=="pass" and .mode==$mode and .protocol==$protocol and .worker_exit==0
      and .original_budget_seconds==30 and .elapsed_seconds<30' "$output/$name.json" >/dev/null
  jq '{outcome,mode,protocol,run_id,elapsed_seconds,worker_exit}' "$output/$name.json"
done < <(jq -r '.cases[] | [.mode,.protocol] | @tsv' "$profile")
