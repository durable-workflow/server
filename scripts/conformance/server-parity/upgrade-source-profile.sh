#!/usr/bin/env bash
# A separate published PHP cohort: inventory and refusals, a verified backup,
# and the takeover of a restored copy that native then resumes.
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
# A labeled run observes another release beside the default one. Its state,
# evidence, containers and databases are separate, and it names the layout
# inspection must report for that release.
label=${DW_PARITY_UPGRADE_LABEL:-}
[[ $label =~ ^[a-z0-9]*$ ]] || exit 2
layout=${DW_PARITY_UPGRADE_EXPECTED_LAYOUT:-php-2.8.0}
resource="$RESOURCE_SCOPE-upgrade${label:+-$label}"
state=parity-upgrade-source-state${label:+-$label}
evidence=parity-evidence/upgrade-source${label:+-$label}
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
database=upgrade_source_ref${label:+_$label}
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
    -e "CREATE DATABASE $database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" > "$evidence/create.txt" 2>&1
elif test "$backend" = pgsql; then
  docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD="$password" \
    --entrypoint createdb "$POSTGRES_IMAGE" -h "$host" -U parity "$database" > "$evidence/create.txt" 2>&1
else
  touch "$state/database.sqlite"
  chmod 0666 "$state/database.sqlite"
fi
printf '%s\n' "$RESOURCE_SCOPE" > "$evidence/database-owned.txt"
# Every backend also takes over a copy and resumes the originals, so the
# timer is short.
timer_seconds=60
configuration=("${admin[@]}" -e DB_DATABASE="$database" -e DW_AUTH_TOKEN=upgrade-fixture-token
  -e CACHE_STORE=file -e QUEUE_CONNECTION=database)
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
  --network "$RESOURCE_SCOPE" -e DW_PARITY_TOKEN=upgrade-fixture-token -e DW_PARITY_UPGRADE_TIMER_SECONDS="$timer_seconds" -v "$PWD:/source:ro" --entrypoint php "$image" \
  /source/scripts/conformance/server-parity/upgrade-source-author.php http://parity-upgrade-php:8080 upgrade-source \
  > "$evidence/originals.json" 2> "$evidence/author.stderr"
docker stop --signal SIGTERM --time 10 "$resource-php" >/dev/null
docker inspect --format '{{json .State}}' "$resource-php" > "$evidence/stopped-state.json"
jq -e '.Running==false and .ExitCode==0 and .OOMKilled==false' "$evidence/stopped-state.json" >/dev/null
if [[ $backend == sqlite ]]; then
  # PHP Server keeps SQLite in WAL mode. Once PHP is stopped, the source is
  # switched to the rollback journal the upgrade commands need, as an operator
  # does first; its rows are unchanged.
  [[ "$(od -An -tu1 -j18 -N2 "$state/database.sqlite" | tr -s ' ')" == " 2 2" ]]
  docker run --rm --name "$resource-prepare" --user=1000:1000 --cpus=1 --memory=256m --memory-swap=256m \
    --network none -e DB_CONNECTION=sqlite -e DB_DATABASE=/state/database.sqlite -e DW_RUST_EXPERIMENTAL=1 \
    -v "$PWD/$state:/state" -v "$PWD/rust-target:/target:ro" \
    --entrypoint /target/debug/durable-workflow-server "$rust_image" upgrade-prepare-sqlite \
    > "$evidence/prepare.json" 2> "$evidence/prepare.stderr"
  jq -e '.status=="rollback_journal" and .previous_journal_mode=="wal" and .source_mutated==true' \
    "$evidence/prepare.json" >/dev/null
  [[ "$(od -An -tu1 -j18 -N2 "$state/database.sqlite" | tr -s ' ')" == " 1 1" ]]
fi
if [[ $backend == sqlite ]]; then
  docker run --rm --name "$resource-control" --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
    --network=none -v "$PWD/$state:/state:ro" -v "$PWD:/source:ro" --entrypoint php "$image" \
    /source/scripts/conformance/server-parity/upgrade-source-sqlite-representations.php upgrade-source \
    > "$evidence/representations-comparison.json"
  jq -e '.outcome=="pass" and .source_mutated==false and .php_objects_executed==false
    and (.runs|length)==4 and (.tasks|length)==7 and (.activities|length)==1
    and (.history|length)==13 and (.queue_jobs|length)==1
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
    # The source file and any sidecar it gains; the restored copy lives beside it.
    find "$state" -maxdepth 1 -type f -name 'database.sqlite*' -print0 | sort -z | xargs -0 -r sha256sum
  else
    local helper=mysql
    if test "$backend" = pgsql; then helper=postgres; fi
    php_run "/source/scripts/conformance/server-parity/$helper-schema.php" snapshot "$database"
  fi
}
snapshot > "$evidence/before.json"
inspect_source > "$evidence/inspection.json" 2> "$evidence/inspection.stderr"
# PHP 2.11.3 adds one table, the service-call dispatch outbox, and 2.11.8
# another, its pending external payload deletions.
tables=50
if [[ $layout == php-2.11.3* ]]; then tables=51; fi
if [[ $layout == php-2.11.8* ]]; then tables=52; fi
jq -e --arg layout "$layout" --argjson tables "$tables" '.status=="inspected" and .source_layout==$layout and (.table_rows|length)==$tables and .table_rows.workflow_runs==4
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
jq -e '.status=="inspected" and (.payload_inspection.columns|length)==8
  and .payload_inspection.references_metadata_supported==true
  and ([.payload_inspection.columns[].counts.php_v1_references]|add)==0
  and ([.payload_inspection.columns[].counts.avro_inline_values]|add)==11
  and ([.payload_inspection.columns[].counts.other_unvalidated]|add)==0
  and .payload_inspection.inline_values_verified==true
  and .payload_inspection.inline_validation_limits=={encoded_frame_bytes:65536,traversal_depth:64,traversal_visits:16384,scalar_allocation_bytes:65536}
  and .payload_inspection.nested_payloads_verified==false
  and .payload_inspection.provider_bytes_verified==false
  and .payload_inspection.registry_binding_verified==false
  and .source_mutated==false and .takeover_allowed==false and .backup_verified==false
  and .php_quiescence_verified==false and .stored_representation_compatibility_verified==false' "$evidence/payload-inspection.json" >/dev/null
fingerprint() {
  local target=$1
  docker run --rm --name "$resource-inspect" --user=1000:1000 --cpus=1 --memory=256m --memory-swap=256m \
    --network "$RESOURCE_SCOPE" "${reader[@]}" -e DB_DATABASE="$target" -e DW_RUST_EXPERIMENTAL=1 \
    -v "$PWD/$state:/state:ro" -v "$PWD/rust-target:/target:ro" \
    --entrypoint /target/debug/durable-workflow-server "$rust_image" upgrade-fingerprint
}
fingerprint "$database" > "$evidence/fingerprint.json" 2> "$evidence/fingerprint.stderr"
jq -e --arg layout "$layout" --argjson tables "$tables" '.status=="fingerprinted" and .source_layout==$layout and (.fingerprint|test("^sha256:[0-9a-f]{64}$"))
  and (.table_rows|length)==$tables and .table_rows.workflow_runs==4 and .table_rows.jobs==1
  and .source_mutated==false and .takeover_allowed==false and .backup_verified==false
  and .php_quiescence_verified==false and .stored_representation_compatibility_verified==false' "$evidence/fingerprint.json" >/dev/null
source_fingerprint=$(jq -r .fingerprint "$evidence/fingerprint.json")
fingerprint "$database" > "$evidence/fingerprint-repeat.json" 2>> "$evidence/fingerprint.stderr"
[[ "$(jq -r .fingerprint "$evidence/fingerprint-repeat.json")" == "$source_fingerprint" ]]
# Back up the stopped source and restore it into a separate database, as the
# backup procedure does. The restored copy must fingerprint equal, and one
# changed row in it must change the fingerprint.
restored=upgrade_restore_ref${label:+_$label}
case "$backend" in
  mysql)
    mysql_admin() {
      local tool=$1
      shift
      docker run --rm -i --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint "$tool" "$MYSQL_IMAGE" \
        -h "$host" -uroot -p"$password" "$@"
    }
    mysql_admin mysqldump --single-transaction --quick --no-tablespaces "$database" > "$state/backup.sql" 2> "$evidence/backup.stderr"
    printf '%s\n' "$RESOURCE_SCOPE" > "$evidence/restore-owned.txt"
    mysql_admin mysql -e "CREATE DATABASE $restored CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>> "$evidence/backup.stderr"
    mysql_admin mysql "$restored" < "$state/backup.sql" 2>> "$evidence/backup.stderr"
    mysql_admin mysql -e "GRANT SELECT ON $restored.* TO 'upgrade_source_reader'@'%'" 2>> "$evidence/backup.stderr"
    change_restored() { mysql_admin mysql "$restored" -e 'UPDATE jobs SET attempts=attempts+1' 2>> "$evidence/backup.stderr"; } ;;
  pgsql)
    pg_admin() {
      local tool=$1
      shift
      docker run --rm -i --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD="$password" \
        --entrypoint "$tool" "$POSTGRES_IMAGE" -h "$host" -U parity "$@"
    }
    pg_admin pg_dump -Fc -d "$database" > "$state/backup.dump" 2> "$evidence/backup.stderr"
    printf '%s\n' "$RESOURCE_SCOPE" > "$evidence/restore-owned.txt"
    pg_admin createdb "$restored" 2>> "$evidence/backup.stderr"
    pg_admin pg_restore --no-owner --exit-on-error -d "$restored" < "$state/backup.dump" 2>> "$evidence/backup.stderr"
    pg_admin psql -d "$restored" -v ON_ERROR_STOP=1 -c "GRANT CONNECT ON DATABASE $restored TO upgrade_source_reader" \
      -c 'GRANT USAGE ON SCHEMA public TO upgrade_source_reader' \
      -c 'GRANT SELECT ON ALL TABLES IN SCHEMA public TO upgrade_source_reader' > /dev/null 2>> "$evidence/backup.stderr"
    change_restored() { pg_admin psql -d "$restored" -v ON_ERROR_STOP=1 -c 'UPDATE jobs SET attempts=attempts+1' > /dev/null 2>> "$evidence/backup.stderr"; } ;;
  sqlite)
    # A stopped SQLite installation is backed up by copying its file.
    cp "$state/database.sqlite" "$state/restored.sqlite"
    chmod 0666 "$state/restored.sqlite"
    restored=/state/restored.sqlite
    change_restored() {
      php_run -r '(new PDO("sqlite:/state/restored.sqlite"))->exec("UPDATE jobs SET attempts=attempts+1") === 1 || exit(1);'
    } ;;
  *) exit 2 ;;
esac
fingerprint "$restored" > "$evidence/restored-fingerprint.json" 2>> "$evidence/fingerprint.stderr"
[[ "$(jq -r .fingerprint "$evidence/restored-fingerprint.json")" == "$source_fingerprint" ]]
change_restored
fingerprint "$restored" > "$evidence/changed-fingerprint.json" 2>> "$evidence/fingerprint.stderr"
changed_fingerprint=$(jq -r .fingerprint "$evidence/changed-fingerprint.json")
[[ $changed_fingerprint =~ ^sha256:[0-9a-f]{64}$ && $changed_fingerprint != "$source_fingerprint" ]]
# Backup-first takeover of a copy of the stopped source, as an operator's
# live database: its digest is the one the restored backup matched. Native
# then resumes the originals, and PHP must refuse the upgraded database.
target=()
case "$backend" in
  sqlite)
    cp "$state/database.sqlite" "$state/takeover.sqlite"
    chmod 0666 "$state/takeover.sqlite"
    target=(-e DB_CONNECTION=sqlite -e DB_DATABASE=/state/takeover.sqlite)
    target_network=none ;;
  pgsql)
    # The same backup restored once more, taken over with the owner role.
    takeover_database=upgrade_takeover_ref${label:+_$label}
    printf '%s\n' "$RESOURCE_SCOPE" > "$evidence/takeover-owned.txt"
    pg_admin createdb "$takeover_database" 2>> "$evidence/backup.stderr"
    pg_admin pg_restore --no-owner --exit-on-error -d "$takeover_database" < "$state/backup.dump" 2>> "$evidence/backup.stderr"
    target=(-e DB_CONNECTION=pgsql -e DB_HOST="$host" -e DB_DATABASE="$takeover_database"
      -e DB_USERNAME=parity -e DB_PASSWORD="$password")
    target_network=$RESOURCE_SCOPE ;;
  mysql)
    # The same backup restored once more, taken over as root, which can see
    # every session (the PROCESS privilege).
    takeover_database=upgrade_takeover_ref${label:+_$label}
    printf '%s\n' "$RESOURCE_SCOPE" > "$evidence/takeover-owned.txt"
    mysql_admin mysql -e "CREATE DATABASE $takeover_database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" 2>> "$evidence/backup.stderr"
    mysql_admin mysql "$takeover_database" < "$state/backup.sql" 2>> "$evidence/backup.stderr"
    target=(-e DB_CONNECTION=mysql -e DB_HOST="$host" -e DB_DATABASE="$takeover_database"
      -e DB_USERNAME=root -e DB_PASSWORD="$password")
    target_network=$RESOURCE_SCOPE ;;
  *) ;;
esac
if (( ${#target[@]} )); then
  docker run --rm --name "$resource-takeover" --user=1000:1000 --cpus=1 --memory=256m --memory-swap=256m \
    --network "$target_network" "${target[@]}" -e DW_RUST_EXPERIMENTAL=1 \
    -e DW_UPGRADE_BACKUP_FINGERPRINT="$source_fingerprint" \
    -e DW_RUST_UPGRADE_QUEUE_CONNECTION=database -e DW_RUST_UPGRADE_QUEUE_DEFAULT_CONNECTION=database \
    -e DW_RUST_UPGRADE_QUEUE_DEFAULT_QUEUE=default \
    -v "$PWD/$state:/state" -v "$PWD/rust-target:/target:ro" \
    --entrypoint /target/debug/durable-workflow-server "$rust_image" upgrade-takeover \
    > "$evidence/takeover.json" 2> "$evidence/takeover.stderr"
  jq -e --arg backend "$backend" --arg layout "$layout" --arg fingerprint "$source_fingerprint" '.status=="taken_over"
    and (.backend==$backend or ($backend=="mysql" and .backend=="mariadb"))
    and .source_layout==$layout and .fingerprint==$fingerprint
    and .backup_fingerprint_matched==true
    and .converted.incarnations_copied==(if $layout=="frozen" then 0 else 1 end)
    and .stored_representation_compatibility_verified==false' "$evidence/takeover.json" >/dev/null
  docker run -d --name "$resource-native" --network "$RESOURCE_SCOPE" --network-alias parity-upgrade-native \
    --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m -e DW_RUST_EXPERIMENTAL=1 \
    -e DW_BIND_ADDRESS=0.0.0.0:8080 -e DW_AUTH_DRIVER=token -e DW_AUTH_TOKEN=upgrade-fixture-token \
    "${target[@]}" -v "$PWD/$state:/state" -v "$PWD/rust-target:/target:ro" \
    --entrypoint /target/debug/durable-workflow-server "$rust_image" > "$evidence/native-container.txt"
  ready=0
  for attempt in $(seq 1 30); do
    if docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint curl "$image" \
      -fsS http://parity-upgrade-native:8080/api/ready > "$evidence/native-readiness.json"; then ready=1; break; fi
    sleep 1
  done
  [[ $ready == 1 ]]
  docker run --rm --name "$resource-resume" --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
    --network "$RESOURCE_SCOPE" -e DW_PARITY_TOKEN=upgrade-fixture-token -v "$PWD:/source:ro" --entrypoint php "$image" \
    /source/scripts/conformance/server-parity/upgrade-takeover-resume.php http://parity-upgrade-native:8080 \
    "/source/$evidence/originals.json" 150 > "$evidence/resume.json" 2> "$evidence/resume.stderr"
  jq -e '.outcome=="pass" and .activity.stale_attempt_status==409
    and .activity.events[-1]=="WorkflowCompleted" and .timer.fired_after_seconds>=0
    and .timer.events[-1]=="WorkflowCompleted" and .signal.events[-1]=="WorkflowCompleted"
    and .lease.attempt==2 and .lease.redelivered_after_seconds>=0 and .lease.stale_attempt_status==409
    and .lease.events[-1]=="WorkflowCompleted"' "$evidence/resume.json" >/dev/null
  docker stop --signal SIGTERM --time 10 "$resource-native" >/dev/null
  if docker run --rm --name "$resource-control" --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
    --network "$target_network" "${target[@]}" -e DW_AUTH_TOKEN=upgrade-fixture-token \
    -e CACHE_STORE=file -e QUEUE_CONNECTION=database -v "$PWD/$state:/state" --entrypoint php "$image" \
    artisan server:assert-php-database > "$evidence/php-refusal.txt" 2>&1; then exit 1; fi
  grep -q php_database_refused "$evidence/php-refusal.txt"
fi
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
  --slurpfile fingerprint "$evidence/fingerprint.json" \
  '{outcome:"pass",scope:"read_only_source_inventory_and_refusals",backend:$backend,
    runner_revision:$revision,native_tree:$native,native_binary_sha256:$binary,php_image:$image,
    original_runs:2,source_state_unchanged_by_inspection:true,source_layout:$inventory[0].source_layout,
    source_fingerprint:$fingerprint[0].fingerprint,restored_backup_fingerprint_equal:true,
    changed_restored_row_changes_fingerprint:true,
    catalog_sha256:$inventory[0].catalog_sha256,table_count:($inventory[0].table_rows|length),
    originals:($originals[0].originals|to_entries|map({kind:.key,workflow_id:.value.workflow_id,
      run_id:.value.run_id,history_events:(.value.history|length),activity_attempt_id:.value.activity_task.activity_attempt_id})),
    takeover_allowed:false,backup_verified:false,php_quiescence_verified:false,
    stored_representation_compatibility_verified:false}' > "$evidence/comparison.json"
cat "$evidence/comparison.json"
