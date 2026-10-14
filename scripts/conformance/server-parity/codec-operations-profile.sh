#!/usr/bin/env bash
# Run the declared workload against one already isolated native service.
set -euo pipefail
if test "$#" != 6; then
  echo 'Expected NETWORK SERVER_CONTAINER URL FRESH_PREFIX SDK_DIRECTORY OUTPUT_DIRECTORY' >&2
  exit 2
fi
network=$1
server=$2
url=$3
prefix=$4
sdk=$(realpath "$5")
output=$6
mkdir -p "$output"
output=$(realpath "$output")
source_root=$(git rev-parse --show-toplevel)
revision=$(git rev-parse HEAD)
spec=tests/Fixtures/ServerParityPending/codec-operations-load.json
artifacts=tests/Fixtures/ServerParityProfiles/corrected-markers/artifacts-workflow-2.5.16.json
image=$(jq -r '.server.images["linux/amd64"]' "$artifacts")
observer="$server-codec-observer"
stats_pid=''
finish() {
  if test -n "$stats_pid"; then kill "$stats_pid" 2>/dev/null || true; wait "$stats_pid" 2>/dev/null || true; fi
  docker rm -f "$observer" >/dev/null 2>&1 || true
}
trap finish EXIT
docker inspect "$server" --format '{{json .HostConfig}}' | jq \
  '{nano_cpus:.NanoCpus,memory_bytes:.Memory,memory_swap_bytes:.MemorySwap}' > "$output/limits.json"
docker inspect "$server" --format '{{json .State}}' | jq \
  '{Status,ExitCode,OOMKilled}' > "$output/initial-state.json"
jq -e '.Status=="running" and .OOMKilled==false' "$output/initial-state.json" >/dev/null
# The first Docker counter has no previous interval. Keep it as an idle sample,
# then retain real cumulative-counter deltas around the original deadlines.
curl --silent --show-error --no-buffer --max-time 170 --unix-socket /var/run/docker.sock \
  "http://localhost/v1.47/containers/$server/stats?stream=true" \
  > "$output/docker-stats.jsonl" 2>"$output/stats-error.txt" &
stats_pid=$!
set +e
docker run --rm --name "$observer" --user="$(id -u):$(id -g)" --cpus=1 --memory=512m --memory-swap=512m \
  --network "$network" --entrypoint node -e DW_PARITY_TOKEN \
  -e DW_PARITY_RUNNER_REVISION="$revision" -e DW_PARITY_SDK_AUTOLOAD=/sdk/vendor/autoload.php \
  -v "$source_root:/source:ro" -v "$sdk:/sdk:ro" -v "$output:/evidence" -w /source "$image" \
  scripts/conformance/server-parity/codec-deadline-profile.mjs "$url" "$prefix" "$spec" "$artifacts" /evidence \
  > "$output/profile.txt" 2>&1
status=$?
set -e
kill "$stats_pid" 2>/dev/null || true
wait "$stats_pid" 2>/dev/null || true
stats_pid=''
jq -c '{observed_at:.read,cpu_percent:(if (.precpu_stats.system_cpu_usage!=null)
  and ((.cpu_stats.system_cpu_usage//0)-(.precpu_stats.system_cpu_usage//0))>0
  then (((.cpu_stats.cpu_usage.total_usage//0)-(.precpu_stats.cpu_usage.total_usage//0))
    /((.cpu_stats.system_cpu_usage//0)-(.precpu_stats.system_cpu_usage//0))*(.cpu_stats.online_cpus//1)*100)
  else 0 end),memory_bytes:.memory_stats.usage}' "$output/docker-stats.jsonl" > "$output/stats.jsonl"
unlink "$output/docker-stats.jsonl"
jq -n --slurpfile limits "$output/limits.json" --slurpfile samples "$output/stats.jsonl" \
  '{resource_limits:$limits[0],samples:$samples}' > "$output/cpu.json"
docker inspect "$server" --format '{{json .State}}' | jq \
  '{Status,ExitCode,OOMKilled}' > "$output/final-state.json"
if test "$status" != 0; then cat "$output/profile.txt" >&2; exit "$status"; fi
jq -e --arg revision "$revision" '.runner_revision==$revision' "$output/record.json" >/dev/null
for checker in codec-operations-contract codec-operations-controls codec-deadline-controls; do
  docker run --rm --user="$(id -u):$(id -g)" --cpus=1 --memory=512m --memory-swap=512m --network none \
    --entrypoint node -v "$source_root:/source:ro" -v "$output:/evidence:ro" -w /source "$image" \
    "scripts/conformance/server-parity/$checker.mjs" /evidence/record.json "$spec" /evidence/cpu.json \
    > "$output/$checker.txt"
  cat "$output/$checker.txt"
done
