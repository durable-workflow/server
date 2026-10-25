#!/usr/bin/env bash
# The Shared Server fixtures result, from the repository root. NEEDS is the
# result job's needs context as JSON. The decision rules live in aggregate.mjs.
set -euo pipefail
exec node scripts/conformance/server-parity/aggregate.mjs
