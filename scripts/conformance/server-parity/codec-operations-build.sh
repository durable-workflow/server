#!/usr/bin/env bash
# Build one thin optimized artifact for all database cells; retain no compiler cache.
set -euo pipefail
test "$#" = 1 || exit 2
output=$1
test ! -e "$output"
mkdir -p "$output/cargo" "$output/target"
output=$(realpath "$output")
root=$(git rev-parse --show-toplevel)
scope_identity="${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-codec}"
if test -z "${RESOURCE_SCOPE:-}"; then
  RESOURCE_SCOPE="dw-codec-$scope_identity-$(date -u +%Y%m%d%H%M%S)-$$"
fi
export RESOURCE_SCOPE
build_container="$RESOURCE_SCOPE-build"
image=mirror.gcr.io/library/rust@sha256:ba81bc3eaa4422af576c0262515d96b0111a628a6ccc2c86557cf55c9a4bbee0
cleanup() {
  rm -r -- "$output/cargo" "$output/target"
}
trap cleanup EXIT
started=$SECONDS
docker run --rm --name "$build_container" --user="$(id -u):$(id -g)" --cpus=1 --memory=2g --memory-swap=2g \
  -e CARGO_HOME=/cargo -e CARGO_TARGET_DIR=/target -e CARGO_BUILD_JOBS=1 \
  -e CARGO_INCREMENTAL=0 -e CARGO_PROFILE_RELEASE_DEBUG=0 \
  -v "$root:/source:ro" -v "$output/cargo:/cargo" -v "$output/target:/target" \
  -w /source/rust "$image" bash -c 'rustc --version; cargo build --release --locked' > "$output/build.txt" 2>&1
cp "$output/target/release/durable-workflow-server" "$output/server"
compiler=$(sed -n '/^rustc [0-9]/{p;q;}' "$output/build.txt")
test -n "$compiler"
jq -n --arg revision "$(git rev-parse HEAD)" --arg tree "$(git rev-parse HEAD:rust)" \
  --arg sha "$(sha256sum "$output/server" | cut -d ' ' -f1)" \
  --arg lock "$(sha256sum rust/Cargo.lock | cut -d ' ' -f1)" --arg image "$image" \
  --arg compiler "$compiler" --argjson duration "$((SECONDS-started))" \
  '{source_revision:$revision,native_tree:$tree,binary_sha256:$sha,cargo_lock_sha256:$lock,compiler_image:$image,
    compiler:$compiler,profile:"release",locked:true,build_duration_seconds:$duration,
    resources:{nano_cpus:1000000000,memory_bytes:2147483648,memory_swap_bytes:2147483648},jobs:1,incremental:false}' \
  > "$output/build.json"
(cd "$output"; sha256sum server build.json > SHA256SUMS)
cat "$output/build.json"
