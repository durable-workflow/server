#!/usr/bin/env bash
# Retire only this profile, including failed setup, before its database is stopped.
set -uo pipefail
backend=$1
test -n "${RESOURCE_SCOPE:?Set the owning isolated network}"
evidence=parity-evidence/query-authority
state=parity-query-authority-state
profile=tests/Fixtures/ServerParityProfiles/query-authority/artifacts.json
test -f "$evidence/identity.json" || exit 0
test "$(jq -r .resource_scope "$evidence/identity.json")" = "$RESOURCE_SCOPE" || exit 1
test "$(jq -r .backend "$evidence/identity.json")" = "$backend" || exit 1
result=0
profile_status=$(cat "$evidence/profile-status.txt" 2>/dev/null || printf incomplete)
for target in php rust; do
  while IFS=$'\t' read -r mode protocol; do
    name="$RESOURCE_SCOPE-query-$target-$mode-${protocol//./}"
    if docker inspect "$name" >/dev/null 2>&1; then docker rm -f "$name" >/dev/null || result=1; fi
  done < <(jq -r '.cases[]|[.mode,.protocol]|@tsv' "$profile")
done
for target in php native; do
  name="$RESOURCE_SCOPE-query-authority-$target"
  if docker inspect "$name" >/dev/null 2>&1; then
    docker stop --signal=SIGTERM --timeout=10 "$name" > "$evidence/$target-stop.txt" 2>&1 || true
    docker logs "$name" > "$evidence/$target-server.log" 2>&1
    docker inspect --format '{{json .State}}' "$name" > "$evidence/$target-state.json"
    if ! jq -e '.ExitCode==0 and .OOMKilled==false and .Running==false' "$evidence/$target-state.json" >/dev/null; then
      cat "$evidence/$target-state.json"
      result=1
    fi
    docker rm -f "$name" >/dev/null || result=1
    if docker inspect "$name" >/dev/null 2>&1; then result=1; fi
  elif test "$profile_status" = 0; then
    if ! jq -e '.ExitCode==0 and .OOMKilled==false and .Running==false' "$evidence/$target-state.json" >/dev/null 2>&1; then
      printf '%s\n' "Completed query profile has no successful $target shutdown receipt" >&2
      result=1
    fi
  fi
done
case "$backend" in
  mysql|pgsql)
    for target in php native; do
      test -f "$evidence/$target-database-owned.txt" || continue
      test "$(cat "$evidence/$target-database-owned.txt")" = "$RESOURCE_SCOPE" || { result=1; continue; }
      database="query_authority_$target"
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
if test -d "$state"; then
  image=$(jq -r '.server.image' "$profile")
  docker run --rm --user=1000:1000 --network=none --cpus=1 --memory=128m --memory-swap=128m \
    --entrypoint sh -v "$PWD/$state:/state" "$image" \
    -c 'find /state -mindepth 1 -depth -delete' || result=1
  rmdir -- "$state" || result=1
fi
exit "$result"
