#!/usr/bin/env bash
# Build once beside the frozen adapters, reusing the job's registry and target.
set -euo pipefail
profile=tests/Fixtures/ServerParityProfiles/published-sdk-directions
adapter=scripts/conformance/server-parity/sdk-directions
mkdir -p sdk-cargo sdk-target sdk-direction-adapters/python-packages
docker run --rm --user="$(id -u):$(id -g)" --cpus=2 --memory=2g --memory-swap=2g \
  -e CARGO_HOME=/cargo -e CARGO_TARGET_DIR=/target -e CARGO_BUILD_JOBS=2 \
  -e CARGO_INCREMENTAL=0 -e CARGO_PROFILE_DEV_DEBUG=0 \
  -v "$PWD:/source:ro" -v "$PWD/sdk-cargo:/cargo" -v "$PWD/sdk-target:/target" \
  -w "/source/$adapter/rust" \
  mirror.gcr.io/library/rust@sha256:300ec56abce8cc9448ddea2172747d048ed902a3090e6b57babb2bf19f754081 \
  cargo build --locked --bins > sdk-direction-adapters/rust-sdk-build.txt 2>&1
cp sdk-target/debug/server-parity-sdk-directions sdk-direction-adapters/rust-worker
cp sdk-target/debug/selected-run-signals sdk-direction-adapters/rust-selected-run-sender
cp "$adapter/rust/Cargo.lock" sdk-direction-adapters/Cargo.lock
sdk_version=$(jq -r '.sdk_rust' "$profile/artifacts.json")
mapfile -t vcs_sources < <(find sdk-cargo/registry/src -path "*/durable-workflow-$sdk_version/.cargo_vcs_info.json")
test "${#vcs_sources[@]}" = 1
cp "${vcs_sources[0]}" sdk-direction-adapters/rust-sdk-vcs.json
docker run --rm --user="$(id -u):$(id -g)" --cpus=1 --memory=512m --memory-swap=512m \
  -v "$PWD:/source:ro" -v "$PWD/sdk-direction-adapters:/adapters" \
  mirror.gcr.io/library/python@sha256:bf44cdfcb76cd3b41e879bc058fc37ec5872002ccfde7fcb765e218cde0cd79c \
  python -m pip install --disable-pip-version-check --no-cache-dir --no-compile --only-binary=:all: --require-hashes \
  --target /adapters/python-packages --report /adapters/python-install-report.json \
  -r "/source/$adapter/requirements.txt" > sdk-direction-adapters/python-install.txt 2>&1
image=$(jq -r '.server.images["linux/amd64"] | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' "$profile/artifacts.json")
docker run --rm --user="$(id -u):$(id -g)" --network=none --entrypoint node \
  -v "$PWD:/source:ro" -v "$PWD/sdk-direction-adapters:/adapters" -w /source "$image" \
  "$adapter/identity.mjs" /adapters "$profile/artifacts.json"
git rev-parse HEAD > sdk-direction-adapters/runner-revision.txt
sha256sum "$adapter"/rust/{Cargo.toml,Cargo.lock,src/main.rs,src/queries.rs,src/updates.rs,src/bin/selected-run-signals.rs} "$adapter"/{python.py,php.php,queries.py,queries.php,updates.py,updates.php,selected-run-signals.php,requirements.txt,identity.mjs} \
  > sdk-direction-adapters/source-hashes.sha256
cd sdk-direction-adapters
find . -type f ! -path ./SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS
sha256sum -c SHA256SUMS >/dev/null
