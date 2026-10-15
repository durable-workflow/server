#!/usr/bin/env bash
# The caller owns each isolated server, database, provider directory and cleanup.
set -euo pipefail
test "$#" = 7 || { echo 'Expected NETWORK SERVER URL SDK OUTPUT TARGET STATE' >&2; exit 2; }
network=$1
server=$2
url=$3
sdk=$(realpath "$4")
mkdir -p "$5"
output=$(realpath "$5")
chmod a+rwX "$output"
target=$6
state=$(realpath "$7")
test -n "${RESOURCE_SCOPE:?Set the unique owning resource scope}"
case "$target" in php|native) ;; *) exit 2 ;; esac
case "$server" in "$RESOURCE_SCOPE"-*) ;; *) exit 2 ;; esac
source_root=$(git rev-parse --show-toplevel)
profile=tests/Fixtures/ActivityMetadataConsumer/profile.json
image=$(jq -r '.php_server.image' "$profile")
test -f "$sdk/vendor/autoload.php"
cmp scripts/conformance/server-parity/activity-result-inspection/composer.lock "$sdk/composer.lock"
test -d "$state/objects"
test ! -L "$state/objects"
test ! -e "$state/objects-unavailable"
test ! -e "$output/identity.json"
docker inspect "$server" | jq -e --arg network "$network" --arg state "$state" \
  '.[0].State.Running == true and (.[0].NetworkSettings.Networks | has($network))
    and any(.[0].Mounts[]; .Destination=="/state" and .Source==$state and .RW==true)' >/dev/null
binary=''
if test "$target" = php; then
  docker inspect "$server" | jq -e --arg revision "$(jq -r '.php_server.source' "$profile")" \
    '.[0].Config.Labels["org.opencontainers.image.revision"]==$revision' >/dev/null
  test "$(docker inspect "$server" --format '{{.Image}}')" = "$(docker image inspect "$image" --format '{{.Id}}')"
else
  binary=$(docker exec "$server" sha256sum /target/debug/durable-workflow-server | cut -d ' ' -f1)
fi
jq -n --arg revision "$(git rev-parse HEAD)" --arg native "$(git rev-parse HEAD:rust)" \
  --arg binary "$binary" --arg target "$target" --arg image "$(docker inspect "$server" --format '{{.Image}}')" \
  --arg profile "$(sha256sum "$profile" | cut -d ' ' -f1)" \
  '{runner_revision:$revision,native_tree:$native,native_binary_sha256:$binary,
    target:$target,server_image:$image,profile_sha256:$profile,performance:"not_measured"}' > "$output/identity.json"
restore_objects() {
  if test -d "$state/objects-unavailable"; then
    test ! -e "$state/objects" || return 1
    mv -- "$state/objects-unavailable" "$state/objects"
  fi
}
finish() {
  result=$?
  trap - EXIT
  restore_objects || result=1
  printf '%s\n' "$result" > "$output/profile.status"
  exit "$result"
}
trap finish EXIT
while IFS= read -r namespace; do
  for scenario in ordinary retry-completed external-result; do
    name="$namespace-$scenario"
    original="$name.original.json"
    test ! -e "$output/$original"
    jq -n --arg namespace "$namespace" --arg workflow "$RESOURCE_SCOPE-$name" \
      --arg scenario "$scenario" --arg path "/evidence/$original" \
      '{namespace:$namespace,workflow_id:$workflow,case:$scenario,identity_path:$path}' > "$output/$original"
    chmod a+rw "$output/$original"
    phase() {
      timeout --signal=TERM --kill-after=5s 35s \
        docker run --rm --name "$RESOURCE_SCOPE-metadata-$target-$1" --user=1000:1000 \
        --cpus=1 --memory=512m --memory-swap=512m --network "$network" --entrypoint php \
        -e DW_PARITY_SDK_AUTOLOAD=/sdk/vendor/autoload.php \
        -v "$source_root:/source:ro" -v "$sdk:/sdk:ro" -v "$output:/evidence" -w /source "$image" \
        scripts/conformance/server-parity/activity-metadata-consumer/probe.php "$url" "$1" "/evidence/$original" \
        > "$output/$name-$1.json" 2> "$output/$name-$1.stderr"
      jq -e --arg phase "$1" '.outcome=="pass" and .phase==$phase' "$output/$name-$1.json" >/dev/null
    }
    phase author
    phase activity
    if test "$scenario" = external-result; then
      mv -- "$state/objects" "$state/objects-unavailable"
      phase unavailable
      restore_objects
    fi
    phase reader
    phase cold-read
  done
done < <(jq -r '.namespaces[]' "$profile")
jq -s --arg target "$target" '{outcome:"pass",target:$target,originals:length,
  cases:map({namespace,case,run_id,activity_id,attempts:(.attempt_ids|length),snapshots:(.snapshots|length)})}' \
  "$output/"*.original.json > "$output/summary.json"
test "$(jq -r .originals "$output/summary.json")" = "$(jq '.namespaces|length*3' "$profile")"
cat "$output/summary.json"
