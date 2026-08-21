#!/usr/bin/env bash

set -euo pipefail

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
sync_script="${script_dir}/sync-cli-readme-release.mjs"
evidence_path="${CLI_RELEASE_EVIDENCE_PATH:?CLI_RELEASE_EVIDENCE_PATH must identify trusted public channel evidence}"
version="$(node "$sync_script" --print-evidence "$evidence_path" version)"
commit="$(node "$sync_script" --print-evidence "$evidence_path" commit)"
release_installer_url="$(node "$sync_script" --print-evidence "$evidence_path" release-installer-url)"
release_base_url="${release_installer_url%/install.sh}"
verify_dir="$(mktemp -d)"
trap 'rm -rf -- "$verify_dir"' EXIT HUP INT TERM

assets_output="$(node "$sync_script" --print-evidence "$evidence_path" assets)"
mapfile -t assets <<<"$assets_output"

for asset in "${assets[@]}"; do
  curl -fsSLI --retry 3 --retry-all-errors --connect-timeout 10 \
    "${release_base_url}/${asset}" >/dev/null
done

curl -fsSL --retry 3 --retry-all-errors --connect-timeout 10 \
  "$release_installer_url" -o "$verify_dir/install.sh"

PATH="$verify_dir/bin:$PATH" VERSION="$version" DURABLE_WORKFLOW_INSTALL_DIR="$verify_dir/bin" \
  sh "$verify_dir/install.sh" >"$verify_dir/install.log"

reported_version="$("$verify_dir/bin/dw" --version)"
if [[ "$reported_version" != "dw ${version}" && "$reported_version" != "dw ${version} "* ]]; then
  printf 'expected installed CLI to report dw %s, got: %s\n' "$version" "$reported_version" >&2
  exit 1
fi

commit_prefix="${commit:0:12}"
if [[ "$reported_version" != *"(commit ${commit_prefix},"* ]]; then
  printf 'expected installed CLI to report release tag commit %s, got: %s\n' "$commit_prefix" "$reported_version" >&2
  exit 1
fi

printf 'Verified %s public CLI release assets for %s.\n' "${#assets[@]}" "$version"
printf 'Installed binary: %s\n' "$reported_version"
