#!/usr/bin/env bash
# Invoke from the owning workflow's always-run cleanup step, before its DB/network.
set -u
backend="$1"
test -n "${RESOURCE_SCOPE:?Set the owning isolated network/resource scope}"
profile=tests/Fixtures/ServerParityProfiles/version-range-repair
image=$(jq -r '.server.images["linux/amd64"] | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' "$profile/artifacts.json")
evidence="$PWD/parity-evidence/version-range-repair"
mkdir -p "$evidence"
docker stop --time 30 "$RESOURCE_SCOPE-range-server" || true
for stage in sdk tests bootstrap-php bootstrap-embedded server record-php record-embedded record-native compare; do
  docker logs "$RESOURCE_SCOPE-range-$stage" > "$evidence/$stage.log" 2>&1 || true
  docker inspect "$RESOURCE_SCOPE-range-$stage" > "$evidence/$stage-container.json" 2>&1 || true
done
docker rm -f "$RESOURCE_SCOPE-range-sdk" "$RESOURCE_SCOPE-range-tests" \
  "$RESOURCE_SCOPE-range-bootstrap-php" "$RESOURCE_SCOPE-range-bootstrap-embedded" "$RESOURCE_SCOPE-range-server" \
  "$RESOURCE_SCOPE-range-record-php" "$RESOURCE_SCOPE-range-record-embedded" "$RESOURCE_SCOPE-range-record-native" "$RESOURCE_SCOPE-range-compare" || true
case "$backend" in
  mysql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
      -h parity-mysql -uroot -pparity-disposable-password \
      -e 'DROP DATABASE IF EXISTS range_php_ref; DROP DATABASE IF EXISTS range_embedded_ref' > "$evidence/database-cleanup.txt" 2>&1 || true ;;
  pgsql)
    for db in range_php_ref range_embedded_ref; do
      docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
        --entrypoint dropdb "$POSTGRES_IMAGE" --if-exists -h parity-pg -U parity "$db" >> "$evidence/database-cleanup.txt" 2>&1 || true
    done ;;
esac
if test -d "$PWD/parity-range-state"; then
  docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network none --entrypoint sh \
    -v "$PWD/parity-range-state:/range-state" "$image" \
    -c 'find /range-state -mindepth 1 -maxdepth 1 -exec rm -r -- {} +' > "$evidence/state-cleanup.txt" 2>&1 || true
fi
