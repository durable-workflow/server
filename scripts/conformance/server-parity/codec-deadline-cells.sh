#!/usr/bin/env bash
# Run every deadline-health cell in turn on this runner. Each cell owns its
# resources under "$RESOURCE_SCOPE-<database>" and stops its server and
# database when it exits, failed or not, so the next cell starts with none of
# them running; its state is removed after that. The job's always() step
# removes every cell's containers and networks. A failed cell still lets the
# others run, and the script fails after all of them.
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
