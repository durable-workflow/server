#!/usr/bin/env bash
# Print bounded log tails of this job's containers, so a failed step is
# diagnosable from its own log. Reads RESOURCE_SCOPE; changes nothing.
set -uo pipefail
test -n "${RESOURCE_SCOPE:-}" || exit 0
echo "::group::Container log tails after failure"
docker ps -a --filter "name=^${RESOURCE_SCOPE}-" --format '{{.Names}}' | while read -r container; do
  echo "== $container"
  docker logs --tail 40 "$container" 2>&1 | cut -c1-400
done
echo "::endgroup::"
