#!/usr/bin/env bash
# Only the owning workflow's always-run step calls this, before its DB/network.
set -euo pipefail
test "$#" = 1 || exit 2
backend=$1
test -n "${RESOURCE_SCOPE:?Set the owning isolated network}"
label=${DW_PARITY_UPGRADE_LABEL:-}
[[ $label =~ ^[a-z0-9]*$ ]] || exit 2
evidence=parity-evidence/upgrade-source${label:+-$label}
state=parity-upgrade-source-state${label:+-$label}
test -f "$evidence/owner.txt" || exit 0
test "$(cat "$evidence/owner.txt")" = "$RESOURCE_SCOPE" || exit 2
resource="$RESOURCE_SCOPE-upgrade${label:+-$label}"
source=upgrade_source_ref${label:+_$label}
restored=upgrade_restore_ref${label:+_$label}
image=${DW_PARITY_UPGRADE_PHP_IMAGE:-ghcr.io/durable-workflow/server@sha256:cba7c4c6732529293ef6c2625a64785dcb21b4f7fe0013fdc74f1af466f534ac}
[[ "$image" =~ ^ghcr.io/durable-workflow/server@sha256:[0-9a-f]{64}$ ]] || exit 2
for role in author control inspect php; do
  if docker inspect "$resource-$role" >/dev/null 2>&1; then
    docker stop --signal SIGTERM --time 5 "$resource-$role" >/dev/null
    docker rm "$resource-$role" >/dev/null
  fi
done
password=${DW_PARITY_UPGRADE_DB_PASSWORD:-parity-disposable-password}
if test -f "$evidence/database-owned.txt" && test "$(cat "$evidence/database-owned.txt")" = "$RESOURCE_SCOPE"; then
case "$backend" in
  mysql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
      -h "${DW_PARITY_UPGRADE_DB_HOST:-parity-mysql}" -uroot -p"$password" \
      -e "DROP DATABASE IF EXISTS $source" \
      > "$evidence/database-cleanup.txt" 2>&1 ;;
  pgsql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD="$password" \
      --entrypoint psql "$POSTGRES_IMAGE" -h "${DW_PARITY_UPGRADE_DB_HOST:-parity-pg}" -U parity -d postgres -v ON_ERROR_STOP=1 \
      -c "DROP DATABASE IF EXISTS $source" \
      > "$evidence/database-cleanup.txt" 2>&1 ;;
  sqlite) ;;
  *) exit 2 ;;
esac
fi
if [[ -f "$evidence/restore-owned.txt" && "$(cat "$evidence/restore-owned.txt")" == "$RESOURCE_SCOPE" ]]; then
case "$backend" in
  mysql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
      -h "${DW_PARITY_UPGRADE_DB_HOST:-parity-mysql}" -uroot -p"$password" \
      -e "DROP DATABASE IF EXISTS $restored" \
      >> "$evidence/database-cleanup.txt" 2>&1 ;;
  pgsql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD="$password" \
      --entrypoint psql "$POSTGRES_IMAGE" -h "${DW_PARITY_UPGRADE_DB_HOST:-parity-pg}" -U parity -d postgres -v ON_ERROR_STOP=1 \
      -c "DROP DATABASE IF EXISTS $restored" \
      >> "$evidence/database-cleanup.txt" 2>&1 ;;
  *) ;;
esac
fi
if test -f "$evidence/roles-owned.txt" && test "$(cat "$evidence/roles-owned.txt")" = "$RESOURCE_SCOPE"; then
  if test "$backend" = mysql; then
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
      -h "${DW_PARITY_UPGRADE_DB_HOST:-parity-mysql}" -uroot -p"$password" \
      -e "DROP USER IF EXISTS 'upgrade_source_reader'@'%'; DROP USER IF EXISTS 'upgrade_source_denied'@'%';" >> "$evidence/database-cleanup.txt" 2>&1
  elif test "$backend" = pgsql; then
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD="$password" \
      --entrypoint psql "$POSTGRES_IMAGE" -h "${DW_PARITY_UPGRADE_DB_HOST:-parity-pg}" -U parity -d postgres -v ON_ERROR_STOP=1 \
      -c 'DROP ROLE IF EXISTS upgrade_source_reader' -c 'DROP ROLE IF EXISTS upgrade_source_denied' >> "$evidence/database-cleanup.txt" 2>&1
  fi
fi
if test -d "$state"; then
  docker run --rm --user=1000:1000 --network none --cpus=1 --memory=128m --memory-swap=128m \
    -v "$PWD/$state:/state" --entrypoint sh "$image" -c 'find /state -mindepth 1 -depth -delete'
  rmdir "$state"
fi
printf 'pass\n' > "$evidence/cleanup-status.txt"
