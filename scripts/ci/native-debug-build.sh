#!/usr/bin/env bash
# Share the current run's debug server only. Native test builds keep their own path.
set -euo pipefail
test "$#" = 1 || exit 2
root=$(git rev-parse --show-toplevel)
cd "$root"
image=${NATIVE_RUST_IMAGE:?NATIVE_RUST_IMAGE must be the pinned workflow compiler}
node scripts/ci/native-debug-artifact.mjs identity >/dev/null
scope="dw-native-debug-${GITHUB_RUN_ID:?}-${GITHUB_RUN_ATTEMPT:?}-${GITHUB_JOB:?}"
output=$(realpath -m "$1")
case "$output" in "$root"/*) ;; *) echo 'native debug output must be inside this checkout' >&2; exit 2 ;; esac
test ! -e "$output"
clean_cid="$output.clean.cid"
build_cid="$output.build.cid"
test ! -e "$clean_cid"
test ! -e "$build_cid"
mkdir -p "$output" cargo-home rust-target
cleanup() {
  for receipt in "$clean_cid" "$build_cid"; do
    if test -s "$receipt"; then
      read -r container < "$receipt" || true
      if [[ "$container" =~ ^[a-f0-9]{64}$ ]]; then
        docker rm -f "$container" >/dev/null 2>&1 || true
      fi
    fi
    rm -f -- "$receipt"
  done
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
started=$SECONDS
docker run --rm --init --name "$scope-clean" --cidfile "$clean_cid" \
  --label "dw.task=server-native-debug-${GITHUB_RUN_ID}-${GITHUB_RUN_ATTEMPT}" \
  --user="$(id -u):$(id -g)" --network=none --cpus=2 --memory=2g --memory-swap=2g \
  -e CARGO_HOME=/cargo -e CARGO_TARGET_DIR=/target \
  -v "$root:/source:ro" -v "$root/cargo-home:/cargo" -v "$root/rust-target:/target" -w /source/rust \
  "$image" cargo clean --locked --offline --package durable-workflow-server
if ! docker run --rm --init --name "$scope-build" --cidfile "$build_cid" \
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
compiler=$(sed -n '/^rustc [0-9]/{p;q;}' "$output.build.txt")
node scripts/ci/native-debug-artifact.mjs create "$output" "$compiler" "$((SECONDS-started))"
