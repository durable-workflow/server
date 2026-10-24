#!/usr/bin/env bash
# Run every published-SDK direction profile against one native server, in
# three concurrent lanes. Each profile already has its own containers, PHP and
# embedded databases, evidence and state directory, and its own workflow-ID
# prefix on the shared native server, so profiles in different lanes cannot
# see each other. Within a lane they run in order, and each profile's PHP
# server is stopped as soon as its attempt ends, so at most three run at once.
# Every profile runs even after another fails; the script fails if any did,
# after printing each log. The job's sdk-direction-cleanup steps remove every
# profile's containers afterwards, as before.
set -euo pipefail
backend="${1:?Choose mysql, pgsql or sqlite}"
native_url="${2:?Set the independently isolated native URL}"
if [[ -z "${RESOURCE_SCOPE:-}" ]]; then
  echo 'Set the owning isolated network/resource scope' >&2
  exit 2
fi
evidence=parity-evidence/sdk-direction-lanes
mkdir -p "$evidence"
# Profile variant and its tag (evidence, log and container names). The lanes are
# balanced by the durations recorded in CI (about seven to eight minutes each).
lanes=(
  "update:sdk-updates update-failure:sdk-update-failures child:sdk-child-directions"
  "namespace-signal:sdk-namespace-signals timer:sdk-timers activity:sdk-directions"
  "selected-run-signal:sdk-selected-run-signals signal:sdk-signals query:sdk-queries"
)
run_lane() {
  local lane=$1 entry variant tag status
  for entry in $lane; do
    variant=${entry%%:*} tag=${entry#*:} status=0
    bash scripts/conformance/server-parity/sdk-direction-profile.sh "$backend" "$native_url" "$variant" \
      > "$evidence/$tag.log" 2>&1 || status=$?
    printf '%s\n' "$status" > "$evidence/$tag.status"
    # The profile's status is already kept; a failed stop leaves the server to
    # the final cleanup.
    docker stop --time 30 "$RESOURCE_SCOPE-$tag-server" >/dev/null 2>&1 \
      || echo "::warning::The $tag PHP server was not stopped; the final cleanup removes it" >&2
  done
}
for lane in "${lanes[@]}"; do
  run_lane "$lane" &
done
wait
failed=0
for lane in "${lanes[@]}"; do
  for entry in $lane; do
    tag=${entry#*:}
    status=$(cat "$evidence/$tag.status" 2>/dev/null || echo missing)
    echo "::group::$tag (exit $status)"
    cat "$evidence/$tag.log"
    echo "::endgroup::"
    if [[ "$status" != 0 ]]; then
      echo "::error::SDK direction profile $tag exited $status" >&2
      failed=1
    fi
  done
done
exit "$failed"
