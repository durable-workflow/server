# Sourced by published-sdk-patch-profile.sh (Server #721).
#
# run_without_maintenance CONTAINER SCHEDULE -- COMMAND...
#
# Runs COMMAND while the maintenance container is stopped, then restarts it.
# Restoration is armed before the stop is attempted and stays armed until the
# container has stayed running for a sustained check, so a failed stop, a
# failed COMMAND or a restart that exits at once never leaves it stopped.
# SCHEDULE records the container's state before, during and after.

maintenance_poll_seconds="${DW_PARITY_MAINTENANCE_POLL_SECONDS:-1}"

maintenance_state() {
  docker inspect -f '{{.State.Status}}' "$1" 2>/dev/null || echo unknown
}

# Running at each of three checks, one poll interval apart.
maintenance_stays_running() {
  local check
  for check in 1 2 3; do
    test "$(maintenance_state "$1")" = running || return 1
    sleep "$maintenance_poll_seconds"
  done
  test "$(maintenance_state "$1")" = running
}

# Up to three starts, each followed by the sustained check.
maintenance_restore() {
  local attempt
  for attempt in 1 2 3; do
    docker start "$1" > /dev/null 2>&1 || true
    if maintenance_stays_running "$1"; then
      return 0
    fi
  done
  echo "maintenance container $1 did not stay running: $(maintenance_state "$1")" >&2
  return 1
}

run_without_maintenance() {
  local container="$1" schedule="$2"
  shift 2
  test "$1" = -- && shift
  echo "before: $(maintenance_state "$container")" > "$schedule"
  grep -qx 'before: running' "$schedule"
  # shellcheck disable=SC2064
  trap "maintenance_restore '$container' || true" EXIT
  docker stop --time 2 "$container" > /dev/null
  echo "during php record: $(maintenance_state "$container")" >> "$schedule"
  grep -qx 'during php record: exited' "$schedule"
  "$@"
  if ! maintenance_restore "$container"; then
    echo "after php record: $(maintenance_state "$container")" >> "$schedule"
    return 1
  fi
  trap - EXIT
  echo "after php record: running for ${maintenance_poll_seconds}s x3" >> "$schedule"
}
