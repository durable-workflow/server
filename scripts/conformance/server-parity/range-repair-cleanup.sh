#!/usr/bin/env bash
# Invoke from the owning workflow's always-run cleanup step, before its DB/network.
set -u
backend="$1"
checkpoint="${2:-completed}"
case "$checkpoint" in
  completed) tag=range; cohort=version-range-repair ;;
  pending-reference) tag=range-pending; cohort=version-range-repair-pending ;;
  *) echo 'Choose completed or pending-reference' >&2; exit 2 ;;
esac
test -n "${RESOURCE_SCOPE:?Set the owning isolated network/resource scope}"
profile=tests/Fixtures/ServerParityProfiles/version-range-repair
image=$(jq -r '.server.images["linux/amd64"] | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' "$profile/artifacts.json")
resource="$RESOURCE_SCOPE-$tag"
php_database="$(printf '%s' "$tag" | tr '-' '_')_php_ref"
embedded_database="$(printf '%s' "$tag" | tr '-' '_')_embedded_ref"
evidence="$PWD/parity-evidence/$cohort"
mkdir -p "$evidence"
docker stop --time 30 "$resource-server" || true
stages=(sdk tests bootstrap-php bootstrap-embedded server record-php record-embedded compare)
if test "$checkpoint" = completed; then stages+=(record-native); fi
containers=()
for stage in "${stages[@]}"; do
  containers+=("$resource-$stage")
  docker logs "$resource-$stage" > "$evidence/$stage.log" 2>&1 || true
  docker inspect "$resource-$stage" > "$evidence/$stage-container.json" 2>&1 || true
done
docker rm -f "${containers[@]}" || true
case "$backend" in
  mysql)
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
      -h parity-mysql -uroot -pparity-disposable-password \
      -e "DROP DATABASE IF EXISTS $php_database; DROP DATABASE IF EXISTS $embedded_database" > "$evidence/database-cleanup.txt" 2>&1 || true ;;
  pgsql)
    for db in "$php_database" "$embedded_database"; do
      docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
        --entrypoint dropdb "$POSTGRES_IMAGE" --if-exists -h parity-pg -U parity "$db" >> "$evidence/database-cleanup.txt" 2>&1 || true
    done ;;
esac
if test -d "$PWD/parity-$tag-state"; then
  docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network none --entrypoint sh \
    -v "$PWD/parity-$tag-state:/range-state" "$image" \
    -c 'find /range-state -mindepth 1 -maxdepth 1 -exec rm -r -- {} +' > "$evidence/state-cleanup.txt" 2>&1 || true
fi
