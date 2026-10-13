#!/usr/bin/env bash
# One normally installed adapter graph beside the unchanged frozen SDK actors.
set -euo pipefail
adapter=scripts/conformance/server-parity/corrected-markers
profile=tests/Fixtures/ServerParityProfiles/corrected-markers
output=${DW_PARITY_CORRECTED_ADAPTERS:-corrected-marker-adapters}
image=$(jq -r '.server.images["linux/amd64"]' "$profile/artifacts.json")
mkdir -p "$output"/{php,python250,bin,cargo,target}
cp "$adapter/php/composer.json" "$adapter/php/composer.lock" "$output/php/"
docker run --rm --user="$(id -u):$(id -g)" --cpus=1 --memory=512m --memory-swap=512m --entrypoint composer \
  -e COMPOSER_HOME=/tmp/composer -v "$PWD/$output/php:/consumer" -w /consumer "$image" \
  install --no-dev --no-scripts --no-cache --no-interaction --no-progress --prefer-dist \
  > "$output/php-install.txt" 2>&1
docker run --rm --user="$(id -u):$(id -g)" --cpus=1 --memory=512m --memory-swap=512m \
  -v "$PWD:/source:ro" -v "$PWD/$output/python250:/packages" -v "$PWD/$output:/adapters" \
  mirror.gcr.io/library/python@sha256:bf44cdfcb76cd3b41e879bc058fc37ec5872002ccfde7fcb765e218cde0cd79c \
  python -m pip install --disable-pip-version-check --no-cache-dir --no-compile --only-binary=:all: --require-hashes \
  --target /packages --report /adapters/python250-install-report.json \
  -r "/source/$adapter/python250-requirements.txt" > "$output/python250-install.txt" 2>&1
docker run --rm --user="$(id -u):$(id -g)" --cpus=1 --memory=1536m --memory-swap=1536m \
  -e CARGO_HOME=/cargo -e CARGO_TARGET_DIR=/target -e CARGO_BUILD_JOBS=1 \
  -e CARGO_INCREMENTAL=0 -e CARGO_PROFILE_DEV_DEBUG=0 \
  -v "$PWD:/source:ro" -v "$PWD/$output/cargo:/cargo" -v "$PWD/$output/target:/target" \
  -w "/source/$adapter/rust" \
  mirror.gcr.io/library/rust@sha256:300ec56abce8cc9448ddea2172747d048ed902a3090e6b57babb2bf19f754081 \
  cargo build --locked > "$output/rust-build.txt" 2>&1
cp "$output/target/debug/server-parity-corrected-rust-markers" "$output/bin/rust-worker"
cp "$adapter/rust/Cargo.lock" "$output/bin/Cargo.lock"
docker run --rm --user="$(id -u):$(id -g)" --cpus=1 --memory=256m --memory-swap=256m \
  --network=none --entrypoint node -v "$PWD:/source:ro" -v "$PWD/$output:/adapters" -w /source "$image" \
  "$adapter/identity.mjs" /adapters "$profile/artifacts.json"
git rev-parse HEAD > "$output/runner-revision.txt"
sha256sum "$adapter"/rust/{Cargo.toml,Cargo.lock,src/main.rs} "$adapter"/{php.php,python250.py,python250-requirements.txt} \
  > "$output/source-hashes.sha256"
rm -r -- "$output/cargo" "$output/target"
