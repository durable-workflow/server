#!/usr/bin/env bash
# Run from the owning job's always-step before its database/network retirement.
set -uo pipefail
backend=$1
test -n "${RESOURCE_SCOPE:?Set the owning isolated network}"
evidence=parity-evidence/external-consumers
state=parity-external-consumers-state
test -f "$evidence/identity.json" || exit 0
test "$(jq -r .resource_scope "$evidence/identity.json")" = "$RESOURCE_SCOPE" || exit 1
result=0
profile_status=$(cat "$evidence/profile-status.txt" 2>/dev/null || printf 'incomplete')
for target in php native; do
  if docker inspect "$RESOURCE_SCOPE-external-$target" > /dev/null 2>&1; then
    docker stop --signal=SIGTERM --timeout=10 "$RESOURCE_SCOPE-external-$target" >"$evidence/$target-stop.txt" 2>&1 || true
    docker logs "$RESOURCE_SCOPE-external-$target" > "$evidence/$target-server.log" 2>&1
    docker inspect --format '{{json .State}}' "$RESOURCE_SCOPE-external-$target" > "$evidence/$target-state.json"
    if ! jq -e '.ExitCode == 0 and .OOMKilled == false and .Running == false' "$evidence/$target-state.json" > /dev/null; then
      cat "$evidence/$target-state.json"
      result=1
    fi
    docker rm -f "$RESOURCE_SCOPE-external-$target" >/dev/null 2>&1 || true
    if docker inspect "$RESOURCE_SCOPE-external-$target" > /dev/null 2>&1; then result=1; fi
  elif test "$profile_status" = 0; then
    if ! jq -e '.ExitCode == 0 and .OOMKilled == false and .Running == false' "$evidence/$target-state.json" > /dev/null 2>&1; then
      printf '%s\n' "Completed profile has no successful $target shutdown receipt" >&2
      result=1
    fi
  fi
done
case "$backend" in
  mysql|pgsql)
    for target in php native; do
      test -f "$evidence/$target-database-owned.txt" || continue
      test "$(cat "$evidence/$target-database-owned.txt")" = "$RESOURCE_SCOPE" || { result=1; continue; }
      database="external_consumers_$target"
      if test "$backend" = mysql; then
        docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
          -h parity-mysql -uroot -pparity-disposable-password -e "DROP DATABASE IF EXISTS $database" \
          > "$evidence/$target-database-retire.txt" 2>&1 || result=1
      else
        docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
          --entrypoint dropdb "$POSTGRES_IMAGE" --if-exists -h parity-pg -U parity "$database" \
          > "$evidence/$target-database-retire.txt" 2>&1 || result=1
      fi
    done ;;
  sqlite) ;;
  *) exit 2 ;;
esac
if test -d "$state"; then rm -r -- "$state" || result=1; fi
exit "$result"
