#!/usr/bin/env bash
# Keep current query authority separate from the frozen query actors.
set -euo pipefail
test "$#" = 1 || exit 2
backend=$1
test -n "${RESOURCE_SCOPE:?Set the owning isolated network}"
docker network inspect "$RESOURCE_SCOPE" >/dev/null
profile=tests/Fixtures/ServerParityProfiles/query-authority/artifacts.json
image=$(jq -r '.server.image' "$profile")
rust_image=mirror.gcr.io/library/rust@sha256:ba81bc3eaa4422af576c0262515d96b0111a628a6ccc2c86557cf55c9a4bbee0
state=parity-query-authority-state
evidence=parity-evidence/query-authority
sdk="$state/sdk"
resource="$RESOURCE_SCOPE-query-authority"
test -f rust-target/debug/durable-workflow-server
test ! -e "$state"
test ! -e "$evidence"
mkdir -p "$sdk" "$state/native" "$evidence"/{php,rust}
mkdir -p "$state/php/storage/framework/"{cache/data,sessions,views} "$state/php/storage/logs"
cp scripts/conformance/server-parity/query-authority/composer.{json,lock} "$sdk/"
chmod -R a+rwX "$state" "$evidence"
jq -n --arg scope "$RESOURCE_SCOPE" --arg backend "$backend" --arg revision "$(git rev-parse HEAD)" \
  --arg image "$image" --arg binary "$(sha256sum rust-target/debug/durable-workflow-server | cut -d ' ' -f1)" \
  '{resource_scope:$scope,backend:$backend,runner_revision:$revision,php_image:$image,native_binary_sha256:$binary}' \
  > "$evidence/identity.json"
# The workflow's always-step retires partial setup and every admitted original.
trap 'printf "%s\n" "$?" > "$evidence/profile-status.txt"' EXIT
docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
  --entrypoint composer -e COMPOSER_HOME=/tmp/query-composer \
  -v "$PWD/$sdk:/sdk" -w /sdk "$image" install --no-dev --no-scripts --no-interaction --prefer-dist \
  > "$evidence/sdk-install.txt" 2>&1
case "$backend" in
  mysql) db=(-e DB_CONNECTION=mysql -e DB_HOST=parity-mysql -e DB_USERNAME=root -e DB_PASSWORD=parity-disposable-password) ;;
  pgsql) db=(-e DB_CONNECTION=pgsql -e DB_HOST=parity-pg -e DB_USERNAME=parity -e DB_PASSWORD=parity-disposable-password) ;;
  sqlite) db=(-e DB_CONNECTION=sqlite) ;;
  *) exit 2 ;;
esac
for target in php native; do
  database="query_authority_$target"
  if test "$backend" = mysql; then
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
      -h parity-mysql -uroot -pparity-disposable-password \
      -e "CREATE DATABASE $database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" > "$evidence/$target-create.txt"
    printf '%s\n' "$RESOURCE_SCOPE" > "$evidence/$target-database-owned.txt"
  elif test "$backend" = pgsql; then
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
      --entrypoint createdb "$POSTGRES_IMAGE" -h parity-pg -U parity "$database" > "$evidence/$target-create.txt"
    printf '%s\n' "$RESOURCE_SCOPE" > "$evidence/$target-database-owned.txt"
  else
    database=/state/database.sqlite
    if test "$target" = php; then touch "$state/php/database.sqlite"; chmod 0666 "$state/php/database.sqlite"; fi
  fi
  configuration=("${db[@]}" -e DB_DATABASE="$database" -e DW_AUTH_DRIVER=token \
    -e DW_AUTH_TOKEN=query-authority-fixture-token -e DW_QUERY_TASK_TIMEOUT=4)
  mount=(-v "$PWD/$state/$target:/state")
  if test "$target" = php; then
    php_runtime=(-e APP_ENV=testing -e APP_DEBUG=false -e CACHE_STORE=file -e QUEUE_CONNECTION=database \
      -e DW_SERVER_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
      -v "$PWD/$state/php/storage:/app/storage")
    docker run --rm --user=1000:1000 --cpus=1 --memory=768m --memory-swap=768m --network "$RESOURCE_SCOPE" \
      "${configuration[@]}" "${mount[@]}" "${php_runtime[@]}" --entrypoint php "$image" \
      artisan server:bootstrap --force > "$evidence/php-bootstrap.txt" 2>&1
    docker run -d --name "$resource-php" --network "$RESOURCE_SCOPE" --network-alias=parity-query-authority-php \
      --user=1000:1000 --cpus=1 --memory=768m --memory-swap=768m \
      "${configuration[@]}" "${mount[@]}" "${php_runtime[@]}" "$image" > "$evidence/php-container.txt"
  else
    native=(-e DW_RUST_EXPERIMENTAL=1 -v "$PWD/rust-target:/target:ro" --entrypoint /target/debug/durable-workflow-server)
    if test "$backend" != sqlite; then
      docker run --rm --user=1000:1000 --cpus=1 --memory=768m --memory-swap=768m --network "$RESOURCE_SCOPE" \
        "${configuration[@]}" "${mount[@]}" "${native[@]}" "$rust_image" schema-bootstrap \
        > "$evidence/native-bootstrap.json"
    fi
    docker run -d --name "$resource-native" --network "$RESOURCE_SCOPE" --network-alias=parity-query-authority-native \
      --user=1000:1000 --cpus=1 --memory=768m --memory-swap=768m "${configuration[@]}" "${mount[@]}" \
      -e DW_BIND_ADDRESS=0.0.0.0:8080 "${native[@]}" "$rust_image" > "$evidence/native-container.txt"
  fi
  ready=0
  for attempt in $(seq 1 30); do
    if docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint curl "$image" \
      -fsS "http://parity-query-authority-$target:8080/api/ready" > "$evidence/$target-readiness.json"; then ready=1; break; fi
    sleep 1
  done
  test "$ready" = 1
done
export DW_PARITY_TOKEN=query-authority-fixture-token
bash scripts/conformance/server-parity/query-authority-profile.sh "$RESOURCE_SCOPE" "$resource-php" \
  http://parity-query-authority-php:8080 "$sdk" "$evidence/php" php > "$evidence/php-tests.txt"
bash scripts/conformance/server-parity/query-authority-profile.sh "$RESOURCE_SCOPE" "$resource-native" \
  http://parity-query-authority-native:8080 "$sdk" "$evidence/rust" rust > "$evidence/rust-tests.txt"
jq -s --arg backend "$backend" \
  '{outcome:"pass",backend:$backend,originals:length,cases:map({target,mode,protocol,namespace,run_id,original_run_status,
    elapsed_seconds,worker_exit,history_sha256,sdk_source,runner_sha256})}' \
  <(for target in php rust; do
    jq -c --arg target "$target" '.+{target:$target}' "$evidence/$target/"*-1*.json
  done) > "$evidence/comparison.json"
test "$(jq -r .originals "$evidence/comparison.json")" = "$(jq '.cases|length*2' "$profile")"
cat "$evidence/comparison.json"
