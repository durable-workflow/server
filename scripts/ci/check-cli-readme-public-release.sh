#!/usr/bin/env bash

set -euo pipefail

if (( $# != 0 )); then
  printf 'usage: %s\n' "${0##*/}" >&2
  exit 2
fi

script_dir="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
evidence_path="${CLI_RELEASE_EVIDENCE_PATH:?CLI_RELEASE_EVIDENCE_PATH must identify trusted public channel evidence}"

exec node "${script_dir}/sync-cli-readme-release.mjs" \
  --check --evidence "$evidence_path"
