#!/usr/bin/env bash
# Prints the tested-merge decision for $GITHUB_OUTPUT, from the repository
# root. The rules live in tested-merge.mjs.
set -euo pipefail
exec node scripts/ci/tested-merge.mjs "$@"
