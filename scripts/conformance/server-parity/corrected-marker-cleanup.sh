#!/usr/bin/env bash
# Call only from the owning workflow's always-run cleanup before its DB/network.
set -u
backend="$1"
test -n "${RESOURCE_SCOPE:?Set the owning isolated network}"
artifacts=${DW_PARITY_CORRECTED_ARTIFACTS:-tests/Fixtures/ServerParityProfiles/corrected-markers/artifacts-workflow-2.5.16.json}
state=${DW_PARITY_CORRECTED_STATE:-parity-corrected-state}
evidence=${DW_PARITY_CORRECTED_EVIDENCE:-parity-evidence/corrected-marker}
image=$(jq -r '.server.images["linux/amd64"]' "$artifacts")
mkdir -p "$evidence"
docker stop --time 5 "$RESOURCE_SCOPE-corrected-php" "$RESOURCE_SCOPE-corrected-maintenance" >/dev/null 2>&1 || true
for role in php maintenance; do
  if docker inspect "$RESOURCE_SCOPE-corrected-$role" >/dev/null 2>&1; then
    docker logs "$RESOURCE_SCOPE-corrected-$role" > "$evidence/$role-server.log" 2>&1 || true
    docker inspect --format '{{json .State}}' "$RESOURCE_SCOPE-corrected-$role" > "$evidence/$role-state.json" || true
  fi
done
docker rm -f "$RESOURCE_SCOPE-corrected-php" "$RESOURCE_SCOPE-corrected-maintenance" >/dev/null 2>&1 || true
case "$backend" in
  mysql|pgsql)
    for role in php embedded; do
      test -f "$evidence/$role-database-owned.txt" || continue
      test "$(cat "$evidence/$role-database-owned.txt")" = "$RESOURCE_SCOPE" || continue
      db=corrected_php_ref
      if test "$role" = embedded; then db=embedded_corrected_ref; fi
      if test "$backend" = mysql; then
        docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" -h parity-mysql -uroot -pparity-disposable-password -e "DROP DATABASE IF EXISTS $db" >> "$evidence/database-cleanup.txt" 2>&1 || true
      else
        docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password --entrypoint dropdb "$POSTGRES_IMAGE" --if-exists -h parity-pg -U parity "$db" >> "$evidence/database-cleanup.txt" 2>&1 || true
      fi
    done ;;
  sqlite)
    for db in php embedded; do
      if test -f "$state/$db.sqlite"; then cp "$state/$db.sqlite" "$evidence/$db-final.sqlite"; fi
    done ;;
  *) exit 2 ;;
esac
if test -f "$evidence/php-database-owned.txt" && test "$(cat "$evidence/php-database-owned.txt")" = "$RESOURCE_SCOPE"; then
  docker run --rm --user=1000:1000 --cpus=1 --memory=256m --memory-swap=256m --network none --entrypoint sh -v "$PWD/$state:/state" "$image" -c 'find /state -mindepth 1 -depth -delete' > "$evidence/state-cleanup.txt" 2>&1 || true
fi
