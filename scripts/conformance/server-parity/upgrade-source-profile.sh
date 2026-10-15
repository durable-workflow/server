#!/usr/bin/env bash
# A separate published PHP cohort; inventory is not migration or SDK takeover.
set -euo pipefail
test "$#" = 1 || exit 2
backend=$1
test -n "${RESOURCE_SCOPE:?Set the owning isolated network}"
docker network inspect "$RESOURCE_SCOPE" >/dev/null
# The frozen default remains PHP 2.8.0. A separate published-source observation
# may explicitly select another immutable official image; its identity is
# recorded in the outcome and does not retuple the default differential actors.
image=${DW_PARITY_UPGRADE_PHP_IMAGE:-ghcr.io/durable-workflow/server@sha256:cba7c4c6732529293ef6c2625a64785dcb21b4f7fe0013fdc74f1af466f534ac}
[[ "$image" =~ ^ghcr.io/durable-workflow/server@sha256:[0-9a-f]{64}$ ]] || exit 2
rust_image=mirror.gcr.io/library/rust@sha256:ba81bc3eaa4422af576c0262515d96b0111a628a6ccc2c86557cf55c9a4bbee0
resource="$RESOURCE_SCOPE-upgrade"
state=parity-upgrade-source-state
evidence=parity-evidence/upgrade-source
test -f rust-target/debug/durable-workflow-server
git diff --quiet
git diff --cached --quiet
revision=$(git rev-parse HEAD)
native_tree=$(git rev-parse HEAD:rust)
binary_sha256=$(sha256sum rust-target/debug/durable-workflow-server | cut -d ' ' -f1)
test ! -e "$state"
test ! -e "$evidence"
mkdir -p "$state" "$evidence"
chmod a+rwx "$state" "$evidence"
printf '%s\n' "$RESOURCE_SCOPE" > "$evidence/owner.txt"
trap 'printf "%s\n" "$?" > "$evidence/profile-status.txt"' EXIT
database=upgrade_source_ref
password=${DW_PARITY_UPGRADE_DB_PASSWORD:-parity-disposable-password}
case "$backend" in
  mysql) host=${DW_PARITY_UPGRADE_DB_HOST:-parity-mysql}; admin=(-e DB_CONNECTION=mysql -e DB_HOST="$host" -e DB_USERNAME=root -e DB_PASSWORD="$password") ;;
  pgsql) host=${DW_PARITY_UPGRADE_DB_HOST:-parity-pg}; admin=(-e DB_CONNECTION=pgsql -e DB_HOST="$host" -e DB_USERNAME=parity -e DB_PASSWORD="$password") ;;
  sqlite) admin=(-e DB_CONNECTION=sqlite); database=/state/database.sqlite ;;
  *) exit 2 ;;
esac
if test "$backend" = mysql; then
  docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
    -h "$host" -uroot -p"$password" \
    -e 'CREATE DATABASE upgrade_source_ref CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' > "$evidence/create.txt" 2>&1
elif test "$backend" = pgsql; then
  docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD="$password" \
    --entrypoint createdb "$POSTGRES_IMAGE" -h "$host" -U parity "$database" > "$evidence/create.txt" 2>&1
else
  touch "$state/database.sqlite"
  chmod 0666 "$state/database.sqlite"
fi
printf '%s\n' "$RESOURCE_SCOPE" > "$evidence/database-owned.txt"
configuration=("${admin[@]}" -e DB_DATABASE="$database" -e DW_AUTH_TOKEN=upgrade-fixture-token
  -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e DB_SQLITE_JOURNAL_MODE=DELETE)
php_run() {
  docker run --rm --name "$resource-control" --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
    --network "$RESOURCE_SCOPE" "${configuration[@]}" -v "$PWD/$state:/state" -v "$PWD:/source:ro" \
    --entrypoint php "$image" "$@"
}
php_run artisan server:bootstrap --force > "$evidence/bootstrap.txt" 2>&1
docker run -d --name "$resource-php" --network "$RESOURCE_SCOPE" --network-alias parity-upgrade-php \
  --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m "${configuration[@]}" \
  -v "$PWD/$state:/state" "$image" > "$evidence/container.txt"
ready=0
for attempt in $(seq 1 30); do
  if docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint curl "$image" \
    -fsS http://parity-upgrade-php:8080/api/ready > "$evidence/readiness.json"; then ready=1; break; fi
  sleep 1
done
test "$ready" = 1
docker run --rm --name "$resource-author" --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
  --network "$RESOURCE_SCOPE" -e DW_PARITY_TOKEN=upgrade-fixture-token -v "$PWD:/source:ro" --entrypoint php "$image" \
  /source/scripts/conformance/server-parity/upgrade-source-author.php http://parity-upgrade-php:8080 upgrade-source \
  > "$evidence/originals.json" 2> "$evidence/author.stderr"
docker stop --signal SIGTERM --time 10 "$resource-php" >/dev/null
docker inspect --format '{{json .State}}' "$resource-php" > "$evidence/stopped-state.json"
jq -e '.Running==false and .ExitCode==0 and .OOMKilled==false' "$evidence/stopped-state.json" >/dev/null
if test "$backend" = sqlite; then
  docker run --rm --name "$resource-control" --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
    --network=none -v "$PWD/$state:/state:ro" -v "$PWD:/source:ro" --entrypoint php "$image" \
    /source/scripts/conformance/server-parity/upgrade-source-sqlite-representations.php upgrade-source \
    > "$evidence/representations-comparison.json"
  jq -e '.outcome=="pass" and .source_mutated==false and .php_objects_executed==false
    and (.runs|length)==2 and (.tasks|length)==4 and (.activities|length)==1
    and (.history|length)==7 and (.queue_jobs|length)==1
    and .queue_jobs[0].canonical_timer_task_match==true
    and all(.runs[]; .typed_arguments_match==true)
    and .backup_verified==false and .stored_representation_compatibility_verified==false
    and .takeover_allowed==false' "$evidence/representations-comparison.json" >/dev/null
fi
if test "$backend" != sqlite; then
  php_run /source/scripts/conformance/server-parity/upgrade-source-controls.php roles > "$evidence/roles.txt"
  printf '%s\n' "$RESOURCE_SCOPE" > "$evidence/roles-owned.txt"
fi
reader=("${admin[@]}" -e DB_USERNAME=upgrade_source_reader -e DB_PASSWORD=parity-disposable-password)
if test "$backend" = sqlite; then reader=("${admin[@]}"); fi
inspect_source() {
  docker run --rm --name "$resource-inspect" --user=1000:1000 --cpus=1 --memory=256m --memory-swap=256m \
    --network "$RESOURCE_SCOPE" "${reader[@]}" -e DB_DATABASE="$database" -e DW_RUST_EXPERIMENTAL=1 \
    -e DW_RUST_UPGRADE_QUEUE_CONNECTION=database -e DW_RUST_UPGRADE_QUEUE_DEFAULT_CONNECTION=database \
    -e DW_RUST_UPGRADE_QUEUE_DEFAULT_QUEUE=default \
    -v "$PWD/$state:/state:ro" -v "$PWD/rust-target:/target:ro" \
    --entrypoint /target/debug/durable-workflow-server "$rust_image" "${1:-upgrade-inspect}"
}
snapshot() {
  if test "$backend" = sqlite; then
    find "$state" -maxdepth 1 -type f -print0 | sort -z | xargs -0 -r sha256sum
  else
    local helper=mysql
    if test "$backend" = pgsql; then helper=postgres; fi
    php_run "/source/scripts/conformance/server-parity/$helper-schema.php" snapshot "$database"
  fi
}
snapshot > "$evidence/before.json"
inspect_source > "$evidence/inspection.json" 2> "$evidence/inspection.stderr"
jq -e '.status=="inspected" and (.table_rows|length)==50 and .table_rows.workflow_runs==2
  and .table_rows.activity_executions==1 and .catalog_visibility=="configured_role"
  and .source_mutated==false and .takeover_allowed==false and .backup_verified==false
  and .php_quiescence_verified==false and .stored_representation_compatibility_verified==false' "$evidence/inspection.json" >/dev/null
inspect_source upgrade-queue-inspect > "$evidence/queue-inspection.json" 2> "$evidence/queue-inspection.stderr"
jq -e '.status=="inspected" and .queue_inspection.status=="known_subset_bound"
  and .queue_inspection.jobs==1 and .queue_inspection.timer_jobs==1
  and .queue_inspection.workflow_jobs==0 and .queue_inspection.activity_jobs==0
  and .source_mutated==false and .takeover_allowed==false and .backup_verified==false
  and .php_quiescence_verified==false and .stored_representation_compatibility_verified==false' "$evidence/queue-inspection.json" >/dev/null
inspect_source upgrade-payload-inspect > "$evidence/payload-inspection.json" 2> "$evidence/payload-inspection.stderr"
jq -e '.status=="inspected" and (.payload_inspection.columns|length)==4
  and .payload_inspection.references_metadata_supported==true
  and ([.payload_inspection.columns[].counts.php_v1_references]|add)==0
  and ([.payload_inspection.columns[].counts.avro_inline_values]|add)==3
  and ([.payload_inspection.columns[].counts.other_unvalidated]|add)==0
  and .payload_inspection.inline_values_verified==true
  and .payload_inspection.inline_validation_limits=={encoded_frame_bytes:65536,traversal_depth:64,traversal_visits:16384,scalar_allocation_bytes:65536}
  and .payload_inspection.nested_payloads_verified==false
  and .payload_inspection.provider_bytes_verified==false
  and .payload_inspection.registry_binding_verified==false
  and .source_mutated==false and .takeover_allowed==false and .backup_verified==false
  and .php_quiescence_verified==false and .stored_representation_compatibility_verified==false' "$evidence/payload-inspection.json" >/dev/null
snapshot > "$evidence/after.json"
cmp "$evidence/before.json" "$evidence/after.json"
refusal() {
  local label=$1 expected=$2
  snapshot > "$evidence/$label-before.json"
  if inspect_source > "$evidence/$label.json" 2> "$evidence/$label.stderr"; then exit 1; fi
  if test -n "$expected"; then grep -Fxq "$expected" "$evidence/$label.stderr"; fi
  snapshot > "$evidence/$label-after.json"
  cmp "$evidence/$label-before.json" "$evidence/$label-after.json"
}
if test "$backend" != sqlite; then
  reader+=( -e DB_USERNAME=upgrade_source_denied )
  refusal denied ''
  reader+=( -e DB_USERNAME=upgrade_source_reader )
fi
if test "$backend" = pgsql; then
  php_run /source/scripts/conformance/server-parity/upgrade-source-controls.php rls > "$evidence/rls-control.json"
  refusal rls upgrade_source_row_security_unqualified
  php_run /source/scripts/conformance/server-parity/upgrade-source-controls.php rls-reset > "$evidence/rls-reset.json"
fi
php_run /source/scripts/conformance/server-parity/upgrade-source-controls.php unknown > "$evidence/unknown-control.json"
refusal unknown upgrade_source_catalog_unqualified
php_run /source/scripts/conformance/server-parity/upgrade-source-controls.php marker > "$evidence/marker-control.json"
refusal marker upgrade_source_already_owned
test "$(git rev-parse HEAD)" = "$revision"
test "$(sha256sum rust-target/debug/durable-workflow-server | cut -d ' ' -f1)" = "$binary_sha256"
git diff --quiet
git diff --cached --quiet
jq -n --arg revision "$revision" --arg native "$native_tree" \
  --arg image "$image" --arg backend "$backend" --arg binary "$binary_sha256" \
  --slurpfile inventory "$evidence/inspection.json" --slurpfile originals "$evidence/originals.json" \
  '{outcome:"pass",scope:"read_only_source_inventory_and_refusals",backend:$backend,
    runner_revision:$revision,native_tree:$native,native_binary_sha256:$binary,php_image:$image,
    original_runs:2,source_state_unchanged_by_inspection:true,
    catalog_sha256:$inventory[0].catalog_sha256,table_count:($inventory[0].table_rows|length),
    originals:($originals[0].originals|to_entries|map({kind:.key,workflow_id:.value.workflow_id,
      run_id:.value.run_id,history_events:(.value.history|length),activity_attempt_id:.value.activity_task.activity_attempt_id})),
    takeover_allowed:false,backup_verified:false,php_quiescence_verified:false,
    stored_representation_compatibility_verified:false}' > "$evidence/comparison.json"
cat "$evidence/comparison.json"
