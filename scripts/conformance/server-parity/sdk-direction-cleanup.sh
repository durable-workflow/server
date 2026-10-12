#!/usr/bin/env bash
# Invoke from the owning workflow's always-run cleanup step, before its DB/network.
set -u
backend="$1"
tag=sdk-directions
cohort=published-sdk-directions
fixture_base=tests/Fixtures/ServerParityProfiles
case "${2:-activity}" in
  activity) ;;
  child) tag=sdk-child-directions; cohort=published-sdk-child-directions ;;
  timer) tag=sdk-timers; cohort=published-sdk-timers ;;
  signal) tag=sdk-signals; cohort=published-sdk-signals ;;
  query) tag=sdk-queries; cohort=published-sdk-queries ;;
  update) tag=sdk-updates; cohort=published-sdk-updates ;;
  update-failure) tag=sdk-update-failures; cohort=published-sdk-update-failures; fixture_base=tests/Fixtures/ServerParityPending ;;
  *) echo 'Choose activity, child, timer, signal, query, update or update-failure SDK directions' >&2; exit 2 ;;
esac
test -n "${RESOURCE_SCOPE:?Set the owning isolated network/resource scope}"
profile="$fixture_base/$cohort"
image=$(jq -r '.server.images["linux/amd64"] | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' "$profile/artifacts.json")
resource="$RESOURCE_SCOPE-$tag"
php_database="$(printf '%s' "$tag" | tr '-' '_')_php_ref"
embedded_database="$(printf '%s' "$tag" | tr '-' '_')_embedded_ref"
evidence="$PWD/parity-evidence/$cohort"
mkdir -p "$evidence"
docker stop --time 30 "$resource-server" || true
stages=(sdk tests bootstrap-php bootstrap-embedded server record-php record-embedded record-native compare)
for stage in "${stages[@]}"; do
  if docker inspect "$resource-$stage" >/dev/null 2>&1; then
    docker logs "$resource-$stage" > "$evidence/$stage.log" 2>&1 || true
    docker inspect "$resource-$stage" > "$evidence/$stage-container.json" 2>&1 || true
  fi
done
docker rm -f "$resource-sdk" "$resource-tests" \
  "$resource-bootstrap-php" "$resource-bootstrap-embedded" "$resource-server" \
  "$resource-record-php" "$resource-record-embedded" "$resource-compare" || true
docker rm -f "$resource-record-native" || true
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
