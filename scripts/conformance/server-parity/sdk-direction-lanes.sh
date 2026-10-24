#!/usr/bin/env bash
# Run every published-SDK direction profile against one native server, in
# three concurrent lanes. Each profile already has its own containers, PHP and
# embedded databases, evidence and state directory, and its own workflow-ID
# prefix on the shared native server, so profiles in different lanes cannot
# see each other. Within a lane they run in order. Every profile runs even
# after another fails; the script fails if any did, after printing each log.
# The job's sdk-direction-cleanup steps stop and remove every profile's
# containers afterwards, as before.
set -euo pipefail
backend="${1:?Choose mysql, pgsql or sqlite}"
native_url="${2:?Set the independently isolated native URL}"
test -n "${RESOURCE_SCOPE:?Set the owning isolated network/resource scope}"
mkdir -p parity-evidence/sdk-direction-lanes
# Profile variant and its tag (evidence, log and container names). The lanes are
# balanced by the durations recorded in CI (about seven to eight minutes each).
lanes=(
  "update:sdk-updates update-failure:sdk-update-failures child:sdk-child-directions"
  "namespace-signal:sdk-namespace-signals timer:sdk-timers activity:sdk-directions"
  "selected-run-signal:sdk-selected-run-signals signal:sdk-signals query:sdk-queries"
)
run_lane() {
  local entry variant tag status
  for entry in $1; do
    variant=${entry%%:*} tag=${entry#*:} status=0
    bash scripts/conformance/server-parity/sdk-direction-profile.sh "$backend" "$native_url" "$variant" \
      > "parity-evidence/sdk-direction-lanes/$tag.log" 2>&1 || status=$?
    printf '%s\n' "$status" > "parity-evidence/sdk-direction-lanes/$tag.status"
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
    status=$(cat "parity-evidence/sdk-direction-lanes/$tag.status" 2>/dev/null || echo missing)
    echo "::group::$tag (exit $status)"
    cat "parity-evidence/sdk-direction-lanes/$tag.log"
    echo "::endgroup::"
    if [ "$status" != 0 ]; then
      echo "::error::SDK direction profile $tag exited $status"
      failed=1
    fi
  done
done
exit "$failed"
