#!/usr/bin/env bash
# Share the current run's debug server only. Native test builds keep their own path.
set -euo pipefail
[[ $# -eq 1 ]] || exit 2
root=$(/usr/bin/git rev-parse --show-toplevel)
cd "$root"
image=${NATIVE_RUST_IMAGE:?NATIVE_RUST_IMAGE must be the pinned workflow compiler}
node scripts/ci/native-debug-artifact.mjs identity >/dev/null
: "${RESOURCE_SCOPE:?RESOURCE_SCOPE must be unique to this workflow job}"
[[ "$RESOURCE_SCOPE" == "dw-native-debug-${GITHUB_RUN_ID:?}-${GITHUB_RUN_ATTEMPT:?}-${GITHUB_JOB:?}" ]]
output=$(realpath -m "$1")
case "$output" in "$root"/*) ;; *) echo 'native debug output must be inside this checkout' >&2; exit 2 ;; esac
[[ ! -e "$output" ]]
mkdir -p "$output" cargo-home rust-target
started=$SECONDS
docker run --rm --init --name "$RESOURCE_SCOPE-clean" \
  --label "dw.task=server-native-debug-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}" \
  --user="$(id -u):$(id -g)" --network=none --cpus=2 --memory=2g --memory-swap=2g \
  -e CARGO_HOME=/cargo -e CARGO_TARGET_DIR=/target \
  -v "$root:/source:ro" -v "$root/cargo-home:/cargo" -v "$root/rust-target:/target" -w /source/rust \
  "$image" cargo clean --locked --offline --package durable-workflow-server
if ! docker run --rm --init --name "$RESOURCE_SCOPE-build" \
  --label "dw.task=server-native-debug-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}" \
  --user="$(id -u):$(id -g)" --network=none --cpus=2 --memory=6g --memory-swap=6g \
  -e CARGO_HOME=/cargo -e CARGO_TARGET_DIR=/target -e CARGO_BUILD_JOBS=2 \
  -v "$root:/source:ro" -v "$root/cargo-home:/cargo" -v "$root/rust-target:/target" -w /source/rust \
  "$image" bash -c 'set -e; rustc --version; cargo build --locked --offline --bin durable-workflow-server' \
  > "$output.build.txt" 2>&1; then
  tail -n 40 "$output.build.txt" >&2
  exit 1
fi
cp rust-target/debug/durable-workflow-server "$output/server"
cat "$output.build.txt"
compiler=$(sed -n '/^rustc [0-9]/{p;q;}' "$output.build.txt")
node scripts/ci/native-debug-artifact.mjs create "$output" "$compiler" "$((SECONDS-started))"
