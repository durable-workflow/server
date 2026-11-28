#!/usr/bin/env bash
set -euo pipefail
umask 077

if [[ $# -lt 1 || $# -gt 2 || ( $# == 2 && "$2" != --smoke ) ]]; then
    printf 'Usage: %s OUTPUT_DIRECTORY [--smoke]\n' "$0" >&2
    exit 2
fi
if [[ "$(uname -s)" != Linux || "$(docker info --format '{{.CgroupVersion}}')" != 2 ]]; then
    printf 'Run on a Linux Docker host using cgroup v2.\n' >&2
    exit 2
fi

source_dir=$(cd -- "$(dirname -- "$0")" && pwd)
prefix=${DW_BENCHMARK_PREFIX:-dw-continuation-v2-$(date +%s)-$$}
[[ "$prefix" =~ ^[a-z0-9][a-z0-9-]{0,36}$ ]]
server_image=ghcr.io/durable-workflow/server@sha256:828491c2c5fda7346b5fe79f6e2776ea178b84a611f4bd55d648d84f7cb86209
mysql_image=mysql@sha256:7dcddc01f13bab2f15cde676d44d01f61fc9f99fe7785e86196dfc07d358ae2b
worker_image=$prefix:local

if docker image inspect "$worker_image" >/dev/null 2>&1; then
    printf 'The benchmark image tag already exists. Choose a new prefix.\n' >&2
    exit 2
fi
mkdir -- "$1"
output=$(cd -- "$1" && pwd)
mkdir "$output/cohort"
trap 'if docker image inspect "$worker_image" >/dev/null 2>&1; then docker image rm "$worker_image" >/dev/null; fi' EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

docker build --tag "$worker_image" "$source_dir" > "$output/build.log" 2>&1 || {
    tail -n 25 "$output/build.log" >&2
    exit 1
}
installed=$(docker run --rm --name "$prefix-identity" --network none --read-only --memory 128m \
    --entrypoint php "$worker_image" -r '
    require "vendor/autoload.php";
    $sdk = "durable-workflow/sdk";
    $version = Composer\InstalledVersions::getPrettyVersion($sdk);
    $source = Composer\InstalledVersions::getReference($sdk);
    if ($version !== "3.33.0" || $source !== "63a5654ee78dbcd2654fa263665f596769cc9ec2") { exit(1); }
    echo json_encode(["php" => PHP_VERSION, "sdk" => $version, "sdk_source" => $source], JSON_THROW_ON_ERROR);
')
tool_versions=$(docker run --rm --name "$prefix-tooling" --network none --read-only --memory 128m \
    --entrypoint apk "$worker_image" --no-network list --installed \
    iproute2-tc libcap2 libelf libmnl libxtables su-exec | cut -d' ' -f1)
source_sha256=$(cd "$source_dir" && sha256sum Dockerfile composer.json composer.lock benchmark.php benchmark-arm.sh run.sh summarize.py |
    sha256sum | cut -d' ' -f1)
cpu_model=$(awk -F ': *' '/^model name/{print $2; exit}' /proc/cpuinfo)
mode=cohort
batch_count=200
sequential_count=30
orders=('0 1 3 2 4 5 7 6' '5 4 6 7 1 0 2 3' '2 3 0 1 6 7 4 5')
if [[ "${2:-}" == --smoke ]]; then
    mode=smoke
    batch_count=6
    sequential_count=3
    orders=("${orders[0]}")
fi
cases=(batch:0:off batch:0:on batch:20:off batch:20:on sequential:0:off sequential:0:on sequential:20:off sequential:20:on)
jq -n --arg mode "$mode" --arg server_image "$server_image" --arg mysql_image "$mysql_image" \
    --arg worker_image_id "$(docker image inspect "$worker_image" --format '{{.Id}}')" \
    --arg composer_lock_sha256 "$(sha256sum "$source_dir/composer.lock" | cut -d' ' -f1)" \
    --arg source_sha256 "$source_sha256" --arg platform "$(uname -m)" --arg cpu_model "$cpu_model" \
    --argjson logical_cpus "$(getconf _NPROCESSORS_ONLN)" --arg kernel "$(uname -r)" \
    --arg docker "$(docker version --format '{{.Server.Version}}')" --arg started_at "$(date -u +%FT%TZ)" \
    --argjson installed "$installed" --arg tooling "$tool_versions" \
    '{$mode,$server_image,$mysql_image,$worker_image_id,$composer_lock_sha256,$source_sha256,$platform,$cpu_model,
      $logical_cpus,$kernel,$docker,$started_at,$installed,$tooling}' \
    > "$output/run.json"

for index in "${!orders[@]}"; do
    round=$((index + 1))
    read -ra order <<< "${orders[index]}"
    for case_index in "${order[@]}"; do
        IFS=: read -r shape delay arm <<< "${cases[case_index]}"
        count=$batch_count
        if [[ "$shape" == sequential ]]; then count=$sequential_count; fi
        name=$round-$shape-$delay-$arm
        partial="$output/.partial-$name"
        printf 'Round %s, %s workflows, %s, %s ms, %s\n' "$round" "$count" "$shape" "$delay" "$arm"
        timeout --signal=TERM --kill-after=20s 325s bash "$source_dir/benchmark-arm.sh" \
            "$prefix-$name" "$worker_image" "$server_image" "$mysql_image" \
            "$arm" "$shape" "$delay" "$count" "$partial"
        for suffix in latencies receipts json; do
            mv -- "$partial.$suffix" "$output/cohort/$name.$suffix"
        done
    done
done
python3 "$source_dir/summarize.py" "$output/cohort" | tee "$output/summary.md"
