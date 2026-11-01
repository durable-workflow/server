#!/usr/bin/env bash
# Run every deadline-health cell in turn on this runner. Each cell owns its
# resources under "$RESOURCE_SCOPE-<database>" and tries to stop its server
# and database when it exits, failed or not. Before removing a cell's state
# and starting the next cell, the runner confirms that both of those exact
# containers are stopped or absent. If it cannot, it keeps the state, runs no
# later cell and fails; the job's always() step removes every cell's
# containers and networks. A cell that failed with confirmed cleanup still
# lets the others run, and the script fails after all of them.
set -euo pipefail
if [[ -z "${RESOURCE_SCOPE:-}" ]]; then
  echo 'Set the owning isolated resource scope' >&2
  exit 2
fi
databases=(sqlite postgres-16 postgres-17 mysql-8.0 mysql-8.4 mariadb-10.11)

# Each named container is absent, or present and not running.
actors_stopped() {
  local name state
  for name in "$@"; do
    if ! state=$(docker inspect --format '{{.State.Running}}' "$name" 2>&1); then
      # The Docker CLI on the Ubuntu 26 runner image prints these in lower case.
      if [[ "${state,,}" == *"no such object"* || "${state,,}" == *"no such container"* ]]; then
        continue
      fi
      echo "::error::Could not confirm that $name stopped: $state" >&2
      return 1
    fi
    if [[ "$state" != false ]]; then
      echo "::error::$name is still running" >&2
      return 1
    fi
  done
}

mkdir -p codec-evidence
failed=0
halted=
for database in "${databases[@]}"; do
  if [[ -n "$halted" ]]; then
    printf 'skipped after %s\n' "$halted" > "codec-evidence/$database.status"
    continue
  fi
  scope="$RESOURCE_SCOPE-$database"
  status=0
  # The cell's unique scope admits only lowercase letters, digits and dashes.
  RESOURCE_SCOPE="$scope" bash scripts/conformance/server-parity/codec-operations-cell.sh \
    "$database" "cpu-${database//./-}" codec-native-build corrected-marker-adapters/php "codec-evidence/$database" || status=$?
  printf '%s\n' "$status" > "codec-evidence/$database.status"
  if ! actors_stopped "$scope-native" "$scope-db"; then
    echo "::error::Deadline-health cell $database left its cleanup unconfirmed; keeping its state and running no later cell" >&2
    halted="$database"
    failed=1
    continue
  fi
  if [[ -d "codec-evidence/$database/state" ]]; then
    rm -r -- "codec-evidence/$database/state"
  fi
  if [[ "$status" != 0 ]]; then
    echo "::error::Deadline-health cell $database exited $status" >&2
    failed=1
  fi
done
exit "$failed"
