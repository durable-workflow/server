#!/usr/bin/env bash
# Prints run=true or run=false for the Shared Server fixtures workflow, from
# the repository root. The decision rules live in changes.mjs.
set -euo pipefail
exec node scripts/conformance/server-parity/changes.mjs
