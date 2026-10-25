#!/usr/bin/env bash
# Compile/install this separate published tuple once, then share runtime files.
set -euo pipefail
profile=tests/Fixtures/ServerParityProfiles/query-authority/artifacts.json
output=query-authority-adapters
cargo_home=${DW_PARITY_QUERY_CARGO:-sdk-cargo}
cargo_target=${DW_PARITY_QUERY_TARGET:-sdk-target}
test ! -e "$output"
mkdir -p "$output/python" "$cargo_home" "$cargo_target"
rust_image=$(jq -r '.client_images.rust' "$profile")
python_image=$(jq -r '.client_images.python' "$profile")
docker run --rm --user="$(id -u):$(id -g)" --cpus=2 --memory=2g --memory-swap=2g \
  -e CARGO_HOME=/cargo -e CARGO_TARGET_DIR=/target -e CARGO_BUILD_JOBS=2 \
  -e CARGO_INCREMENTAL=0 -e CARGO_PROFILE_DEV_DEBUG=0 \
  -v "$PWD:/source:ro" -v "$(realpath "$cargo_home"):/cargo" -v "$(realpath "$cargo_target"):/target" \
  -w /source/scripts/conformance/server-parity/query-authority/rust "$rust_image" \
  cargo build --locked >"$output/rust-build.txt" 2>&1
cp "$cargo_target/debug/server-parity-query-authority-client" "$output/rust-client"
cp scripts/conformance/server-parity/query-authority/rust/Cargo.lock "$output/Cargo.lock"
docker run --rm --user="$(id -u):$(id -g)" --cpus=1 --memory=512m --memory-swap=512m \
  -v "$PWD:/source:ro" -v "$PWD/$output:/adapters" "$python_image" \
  sh /source/scripts/ci/patched-pip.sh install --disable-pip-version-check --no-cache-dir --no-compile --only-binary=:all: --require-hashes \
  --target /adapters/python --report /adapters/python-install.json \
  -r /source/scripts/conformance/server-parity/query-authority/requirements.txt >"$output/python-install.txt" 2>&1
docker run --rm -i --user="$(id -u):$(id -g)" --network=none --cpus=1 --memory=256m --memory-swap=256m \
  -e PYTHONDONTWRITEBYTECODE=1 -e PYTHONPATH=/adapters/python \
  -v "$PWD:/source:ro" -v "$PWD/$output:/adapters:ro" "$python_image" python - >"$output/identity.json" <<'PY'
import hashlib, importlib.metadata, json, tomllib
from pathlib import Path
import durable_workflow
profile=json.loads(Path('/source/tests/Fixtures/ServerParityProfiles/query-authority/artifacts.json').read_text())
package=Path(durable_workflow.__file__).parent
hashes={str(path.relative_to(package)):hashlib.sha256(path.read_bytes()).hexdigest()
        for path in package.rglob('*') if path.is_file() and (path.suffix=='.py' or path.name=='py.typed')}
tree=hashlib.sha256(json.dumps(hashes,sort_keys=True,separators=(',',':')).encode()).hexdigest()
assert importlib.metadata.version('durable-workflow')==profile['sdk_python']['version']
assert len(hashes)==profile['sdk_python']['loaded_files'] and tree==profile['sdk_python']['loaded_tree_sha256']
packages=tomllib.loads(Path('/adapters/Cargo.lock').read_text())['package']
sdk=next(package for package in packages if package['name']=='durable-workflow')
assert sdk['version']==profile['sdk_rust']['version'] and sdk['checksum']==profile['sdk_rust']['crate_sha256']
assert sdk['source']=='registry+https://github.com/rust-lang/crates.io-index'
print(json.dumps({'outcome':'pass','python_version':profile['sdk_python']['version'],'python_loaded_tree_sha256':tree,
                  'rust_version':sdk['version'],'rust_crate_sha256':sdk['checksum']}))
PY
printf '%s\n' "$(git rev-parse HEAD)" >"$output/runner-revision.txt"
sha256sum scripts/conformance/server-parity/query-authority/{observer.mjs,python.py,requirements.txt} \
  scripts/conformance/server-parity/query-authority/rust/{Cargo.toml,Cargo.lock,src/main.rs} \
  "$profile" >"$output/source-hashes.sha256"
(cd "$output"; find . -type f ! -path ./SHA256SUMS -print0 | sort -z | xargs -0 sha256sum >SHA256SUMS)
