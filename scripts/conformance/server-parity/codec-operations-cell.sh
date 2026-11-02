#!/usr/bin/env bash
# One disposable database cell for the declared CPU/deadline profile.
set -euo pipefail
if test "$#" != 5; then
  echo 'Expected DATABASE_ID UNIQUE_SCOPE BINARY_DIRECTORY SDK_DIRECTORY OUTPUT_DIRECTORY' >&2
  exit 2
fi
database_id=$1
[[ "$2" =~ ^[a-z0-9][a-z0-9-]{1,54}$ ]] || exit 2
scope_identity="${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-codec}"
if test -z "${RESOURCE_SCOPE:-}"; then
  RESOURCE_SCOPE="dw-codec-$scope_identity-$2"
fi
export RESOURCE_SCOPE
scope="$RESOURCE_SCOPE"
binary=$(realpath "$3")
sdk=$(realpath "$4")
output=$5
test ! -e "$output"
mkdir -p "$output/state"
output=$(realpath "$output")
source_root=$(git rev-parse --show-toplevel)
manifest=tests/Fixtures/ServerParityPending/codec-operations-databases.json
database=$(jq -ce --arg id "$database_id" '.databases[]|select(.id==$id)' "$manifest")
connection=$(jq -r '.connection' <<< "$database")
runtime=mirror.gcr.io/library/rust@sha256:ba81bc3eaa4422af576c0262515d96b0111a628a6ccc2c86557cf55c9a4bbee0
observer_image=$(jq -r '.server.images["linux/amd64"]' tests/Fixtures/ServerParityProfiles/corrected-markers/artifacts-workflow-2.5.16.json)
native_tree=$(git rev-parse HEAD:rust)
binary_sha=$(sha256sum "$binary/server" | cut -d ' ' -f1)
jq -e --arg tree "$native_tree" --arg sha "$binary_sha" \
  '.native_tree==$tree and .binary_sha256==$sha and .profile=="release" and .locked==true' \
  "$binary/build.json" >/dev/null
if test -n "${GITHUB_RUN_ID:-}"; then
  revision=$(git rev-parse HEAD)
  jq -e --arg revision "$revision" '.source_revision==$revision' "$binary/build.json" >/dev/null
fi
# Every image is pinned by digest, so pulling again is safe. The registry
# mirror sometimes fails to serve one blob for a few minutes ("could not fetch
# content descriptor"); retry before a cell fails on that.
pull_pinned() {
  local attempt
  for attempt in 1 2 3 4; do
    if docker pull --quiet "$1" >/dev/null; then
      return 0
    fi
    echo "::warning::Pull of $1 failed (attempt $attempt of 4)" >&2
    if test "$attempt" -lt 4; then sleep $((attempt * 15)); fi
  done
  return 1
}
pull_pinned "$runtime"
pull_pinned "$observer_image"
if test "$connection" != sqlite; then
  pull_pinned "$(jq -r '.image' <<< "$database")"
fi
native_server="$scope-native"
db="$scope-db"
probe="$scope-ready"
cleanup() {
  docker logs --tail 40 "$native_server" > "$output/server-log.txt" 2>&1 || true
  # Stop this cell's server and database on every exit, failure included, so
  # a failed cell never keeps them running while later cells qualify. The
  # job's always() step removes them.
  docker stop --time 10 "$native_server" >/dev/null 2>&1 || true
  docker stop --time 10 "$db" >/dev/null 2>&1 || true
}
trap cleanup EXIT
docker network create "$scope" >/dev/null
env_args=(-e "DB_CONNECTION=$connection")
if test "$connection" = sqlite; then
  env_args+=(-e DB_DATABASE=/state/native.sqlite -v "$output/state:/state")
else
  image=$(jq -r '.image' <<< "$database")
  memory=$(jq -r '.memory_bytes' <<< "$database")
  if test "$connection" = pgsql; then
    docker run -d --name "$db" --user="$(id -u):$(id -g)" --cpus=1 \
      --memory="$memory" --memory-swap="$memory" --network "$scope" --network-alias codec-db \
      -v "$output/state:/var/lib/postgresql/data" -e POSTGRES_USER=parity \
      -e POSTGRES_PASSWORD=parity-disposable-password -e POSTGRES_DB=native_cpu "$image" > "$output/database-container.txt"
    env_args+=(-e DB_PORT=5432 -e DB_SSLMODE=disable)
    ready=(docker exec "$db" psql -U parity -d native_cpu -tAc 'select 1')
    sql=(docker exec -i "$db" psql -q -A -t -F $'\t' -U parity -d native_cpu -v ON_ERROR_STOP=1)
  else
    client=mysql
    [[ "$database_id" = mariadb-* ]] && client=mariadb
    docker run -d --name "$db" --user="$(id -u):$(id -g)" --cpus=1 \
      --memory="$memory" --memory-swap="$memory" --network "$scope" --network-alias codec-db \
      -v "$output/state:/var/lib/mysql" -e MYSQL_ROOT_PASSWORD=parity-root-disposable \
      -e MYSQL_DATABASE=native_cpu -e MYSQL_USER=parity -e MYSQL_PASSWORD=parity-disposable-password "$image" > "$output/database-container.txt"
    env_args+=(-e DB_PORT=3306 -e DB_SSLMODE=disabled)
    # Initialization temporarily serves the socket with networking disabled.
    # Require the final TCP listener used by the native server.
    ready=(docker exec "$db" "$client" --host=127.0.0.1 --protocol=TCP --user=parity --password=parity-disposable-password native_cpu --batch --skip-column-names --execute='select 1')
    sql=(docker exec -i "$db" "$client" --host=127.0.0.1 --protocol=TCP --user=parity --password=parity-disposable-password native_cpu --batch --skip-column-names)
  fi
  ready_at=$((SECONDS+120))
  until "${ready[@]}" > "$output/database-ready.txt" 2>&1; do
    if ((SECONDS>=ready_at)); then docker logs --tail 30 "$db" >&2; exit 1; fi
    sleep 1
  done
  printf 'SELECT VERSION();\n' | "${sql[@]}" > "$output/database-version.txt"
  docker inspect "$db" | jq '.[0]|{image:.Config.Image,resources:{nano_cpus:.HostConfig.NanoCpus,memory_bytes:.HostConfig.Memory,memory_swap_bytes:.HostConfig.MemorySwap},state:{status:.State.Status,oom_killed:.State.OOMKilled}}' > "$output/database.json"
  jq -e --arg image "$image" --argjson memory "$memory" \
    '.image==$image and .resources.nano_cpus==1000000000 and .resources.memory_bytes==$memory and .resources.memory_swap_bytes==$memory and .state.status=="running" and .state.oom_killed==false' "$output/database.json" >/dev/null
  env_args+=(-e DB_HOST=codec-db -e DB_DATABASE=native_cpu -e DB_USERNAME=parity -e DB_PASSWORD=parity-disposable-password)
fi
docker run -d --name "$native_server" --user="$(id -u):$(id -g)" --cpus=1 --memory=512m --memory-swap=512m \
  --network "$scope" --network-alias codec-native --entrypoint /bin-owned/server \
  -v "$binary:/bin-owned:ro" -e DW_RUST_EXPERIMENTAL=1 -e DW_AUTH_TOKEN=parity-test-token \
  -e DW_BIND_ADDRESS=0.0.0.0:8080 "${env_args[@]}" "$runtime" > "$output/server-container.txt"
# Startup is checked before any original is admitted. It never rebases an
# activity, call or cleanup budget, and never retries a failed workload.
docker run --rm --name "$probe" --user="$(id -u):$(id -g)" --cpus=1 --memory=128m --memory-swap=128m \
  --network "$scope" --entrypoint curl "$observer_image" --fail --silent --show-error \
  --max-time 2 --retry 60 --retry-delay 1 --retry-connrefused --retry-max-time 120 \
  http://codec-native:8080/api/ready > "$output/server-ready.json" 2> "$output/server-ready-errors.txt"
cp "$binary/build.json" "$output/build.json"
DW_PARITY_TOKEN=parity-test-token bash scripts/conformance/server-parity/codec-operations-profile.sh \
  "$scope" "$native_server" http://codec-native:8080 "$scope" "$sdk" "$output"
docker stop --time 10 "$native_server" >/dev/null
if test "$connection" != sqlite; then
  docker inspect "$db" --format '{{json .State}}' | jq '{Status,ExitCode,OOMKilled}' > "$output/database-final-state.json"
  jq -e '.Status=="running" and .OOMKilled==false' "$output/database-final-state.json" >/dev/null
fi
cat > "$output/audit.sql" <<'SQL'
SELECT 'run',id,status FROM workflow_runs ORDER BY id;
SELECT 'history',workflow_run_id,event_type,COUNT(*) FROM workflow_history_events GROUP BY workflow_run_id,event_type ORDER BY workflow_run_id,event_type;
SELECT 'task',workflow_run_id,task_type,attempt_count,repair_count FROM workflow_tasks ORDER BY workflow_run_id,task_type;
SQL
if test "$connection" = sqlite; then
  find "$output/state" -type f ! -name '*-shm' ! -empty -print0 | sort -z | xargs -0 sha256sum > "$output/sqlite-state-before.sha256"
  docker run --rm --user="$(id -u):$(id -g)" --network=none --entrypoint php \
    -v "$output:/evidence" "$observer_image" -r '
      $db=new PDO("sqlite:/evidence/state/native.sqlite",null,null,
        [PDO::SQLITE_ATTR_OPEN_FLAGS=>PDO::SQLITE_OPEN_READONLY]);
      $db->exec("PRAGMA query_only=ON");$db->beginTransaction();
      foreach(explode(";",file_get_contents("/evidence/audit.sql")) as $sql) {
        if(trim($sql)==="")continue;
        foreach($db->query($sql,PDO::FETCH_NUM) as $row)echo implode("\t",$row),"\n";
      }$db->rollBack();' > "$output/database-audit.tsv"
  # WAL readers may create/update their disposable shared-memory lock file.
  # PRAGMA query_only forbids SQL mutation; the main DB and WAL bytes must also
  # remain exact. A new zero-byte WAL contains no database bytes. Never use
  # immutable mode to bypass an uncheckpointed WAL or ignore a nonempty WAL.
  find "$output/state" -type f ! -name '*-shm' ! -empty -print0 | sort -z | xargs -0 sha256sum > "$output/sqlite-state-after.sha256"
  cmp "$output/sqlite-state-before.sha256" "$output/sqlite-state-after.sha256"
elif test "$connection" = pgsql; then
  { printf 'BEGIN READ ONLY;\n'; cat "$output/audit.sql"; printf 'COMMIT;\n'; } \
    | "${sql[@]}" > "$output/database-audit.tsv"
else
  { printf 'SET TRANSACTION READ ONLY;\nSTART TRANSACTION;\n'; cat "$output/audit.sql"; printf 'COMMIT;\n'; } \
    | "${sql[@]}" > "$output/database-audit.tsv"
fi
docker run --rm --user="$(id -u):$(id -g)" --cpus=1 --memory=256m --memory-swap=256m --network=none \
  --entrypoint node -v "$source_root:/source:ro" -v "$output:/evidence:ro" -w /source "$observer_image" \
  scripts/conformance/server-parity/codec-operations-database-audit.mjs /evidence \
  > "$output/database-audit.json"
cat "$output/database-audit.json"
docker run --rm --user="$(id -u):$(id -g)" --cpus=1 --memory=256m --memory-swap=256m --network=none \
  --entrypoint node -v "$source_root:/source:ro" -v "$output:/evidence:ro" -w /source "$observer_image" \
  scripts/conformance/server-parity/codec-operations-database-controls.mjs /evidence \
  > "$output/database-controls.json"
cat "$output/database-controls.json"
if test "$connection" != sqlite; then docker stop --time 10 "$db" >/dev/null; fi
rm -r -- "$output/state"
