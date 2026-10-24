# Sourced by SQLite-job scripts that record with PHP's maintenance loop stopped
# (Server #721, #723).
#
# run_without_maintenance SCHEDULE -- COMMAND...
#
# Runs COMMAND while the SQLite job's PHP maintenance container is stopped,
# then restarts it. Restoration is armed before the stop is attempted and
# stays armed until the container has stayed running for a sustained check,
# so a failed stop, a failed COMMAND or a restart that exits at once never
# leaves it stopped. SCHEDULE records the container's state before, during
# and after.

maintenance_poll_seconds="${DW_PARITY_MAINTENANCE_POLL_SECONDS:-1}"
# The SQLite job's container, named from this job's identity.
maintenance_container="dw-parity-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-parity}-php-maintenance"

maintenance_state() {
  docker inspect -f '{{.State.Status}}' "$maintenance_container" 2>/dev/null || echo unknown
}

# Running at each of three checks, one poll interval apart.
maintenance_stays_running() {
  local check
  for check in 1 2 3; do
    test "$(maintenance_state)" = running || return 1
    sleep "$maintenance_poll_seconds"
  done
  test "$(maintenance_state)" = running
}

# Up to three starts, each followed by the sustained check.
maintenance_restore() {
  local attempt
  for attempt in 1 2 3; do
    docker start "$maintenance_container" >/dev/null 2>&1 || true
    if maintenance_stays_running; then
      return 0
    fi
  done
  echo "maintenance container $maintenance_container did not stay running: $(maintenance_state)" >&2
  return 1
}

run_without_maintenance() {
  local schedule="$1"
  shift
  test "$1" = -- && shift
  # The job started this container as "$RESOURCE_SCOPE-php-maintenance".
  test "$maintenance_container" = "$RESOURCE_SCOPE-php-maintenance"
  echo "before: $(maintenance_state)" > "$schedule"
  grep -qx 'before: running' "$schedule"
  trap 'maintenance_restore || true' EXIT
  docker stop --time 2 "$maintenance_container" >/dev/null
  echo "during php record: $(maintenance_state)" >> "$schedule"
  grep -qx 'during php record: exited' "$schedule"
  "$@"
  if ! maintenance_restore; then
    echo "after php record: $(maintenance_state)" >> "$schedule"
    return 1
  fi
  trap - EXIT
  echo "after php record: running for ${maintenance_poll_seconds}s x3" >> "$schedule"
}
