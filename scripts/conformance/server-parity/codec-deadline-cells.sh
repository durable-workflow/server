#!/usr/bin/env bash
# Run every deadline-health cell in turn on this runner. Each cell owns its
# resources under "$RESOURCE_SCOPE-<database>". They are retired after every
# cell, failed or not, before the next cell starts; the job's always() step
# repeats that for a runner that never got there. A failed cell still lets
# the others run, and the script fails after all of them.
set -euo pipefail
if [[ -z "${RESOURCE_SCOPE:-}" ]]; then
  echo 'Set the owning isolated resource scope' >&2
  exit 2
fi
mkdir -p codec-evidence
failed=0
for database in sqlite postgres-16 postgres-17 mysql-8.0 mysql-8.4 mariadb-10.11; do
  status=0
  # The cell's unique scope admits only lowercase letters, digits and dashes.
  RESOURCE_SCOPE="$RESOURCE_SCOPE-$database" bash scripts/conformance/server-parity/codec-operations-cell.sh \
    "$database" "cpu-${database//./-}" codec-native-build corrected-marker-adapters/php "codec-evidence/$database" || status=$?
  RESOURCE_SCOPE="$RESOURCE_SCOPE-$database" bash scripts/conformance/server-parity/codec-operations-cleanup.sh cell || true
  if [[ -d "codec-evidence/$database/state" ]]; then
    rm -r -- "codec-evidence/$database/state"
  fi
  printf '%s\n' "$status" > "codec-evidence/$database.status"
  if [[ "$status" != 0 ]]; then
    echo "::error::Deadline-health cell $database exited $status" >&2
    failed=1
  fi
done
exit "$failed"
