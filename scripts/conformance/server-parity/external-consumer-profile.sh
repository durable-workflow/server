#!/usr/bin/env bash
# Run the existing installed consumers on separate, disposable service databases.
set -euo pipefail
test "$#" = 1 || exit 2
backend=$1
test -n "${RESOURCE_SCOPE:?Set the owning isolated network}"
docker network inspect "$RESOURCE_SCOPE" > /dev/null
profile=benchmarks/server-port/v2/matched.json
image=$(jq -r '.artifacts.php_server' "$profile")
rust_image=mirror.gcr.io/library/rust@sha256:ba81bc3eaa4422af576c0262515d96b0111a628a6ccc2c86557cf55c9a4bbee0
sdk=corrected-marker-adapters/php
state=parity-external-consumers-state
evidence=parity-evidence/external-consumers
resource="$RESOURCE_SCOPE-external"
test -f rust-target/debug/durable-workflow-server
test -f "$sdk/vendor/autoload.php"
test "$(sha256sum "$sdk/composer.lock" | cut -d ' ' -f1)" = "$(jq -r '.artifacts.sdk_php_lock_sha256' "$profile")"
test ! -e "$state"
test ! -e "$evidence"
mkdir -p "$state"/{php,native}/objects "$evidence"/{php,native}
chmod -R a+rwX "$state" "$evidence"
jq -n --arg runner "$(git rev-parse HEAD)" --arg tree "$(git rev-parse HEAD:rust)" \
  --arg binary "$(sha256sum rust-target/debug/durable-workflow-server | cut -d ' ' -f1)" \
  --arg php "$image" --arg backend "$backend" \
  --arg lock "$(sha256sum "$sdk/composer.lock" | cut -d ' ' -f1)" \
  '{runner_revision:$runner,native_tree:$tree,native_binary_sha256:$binary,php_image:$php,
    backend:$backend,sdk_lock_sha256:$lock}' > "$evidence/identity.json"
created=()
servers=()
cleanup() {
  result=$?
  trap - EXIT
  set +e
  for target in "${servers[@]}"; do
    docker stop --signal SIGTERM --timeout 10 "$resource-$target" > "$evidence/$target-stop.txt" 2>&1 || result=1
    docker logs "$resource-$target" > "$evidence/$target-server.log" 2>&1
    docker inspect --format '{{json .State}}' "$resource-$target" > "$evidence/$target-state.json"
    if ! jq -e '.ExitCode == 0 and .OOMKilled == false and .Running == false' "$evidence/$target-state.json" > /dev/null; then
      cat "$evidence/$target-state.json"
      result=1
    fi
    docker rm -f "$resource-$target" > /dev/null || result=1
  done
  for name in "${created[@]}"; do
    if test "$backend" = mysql; then
      docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
        -h parity-mysql -uroot -pparity-disposable-password -e "DROP DATABASE $name" \
        > "$evidence/$name-retire.txt" 2>&1 || result=1
    else
      docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
        --entrypoint dropdb "$POSTGRES_IMAGE" -h parity-pg -U parity "$name" \
        > "$evidence/$name-retire.txt" 2>&1 || result=1
    fi
  done
  rm -r -- "$state" || result=1
  exit "$result"
}
trap cleanup EXIT
case "$backend" in
  mysql) db=(-e DB_CONNECTION=mysql -e DB_HOST=parity-mysql -e DB_USERNAME=root -e DB_PASSWORD=parity-disposable-password) ;;
  pgsql) db=(-e DB_CONNECTION=pgsql -e DB_HOST=parity-pg -e DB_USERNAME=parity -e DB_PASSWORD=parity-disposable-password) ;;
  sqlite) db=(-e DB_CONNECTION=sqlite) ;;
  *) exit 2 ;;
esac
for target in php native; do
  database="external_consumers_$target"
  if test "$backend" = mysql; then
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
      -h parity-mysql -uroot -pparity-disposable-password \
      -e "CREATE DATABASE $database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci" > "$evidence/$target-create.txt"
    created+=("$database")
  elif test "$backend" = pgsql; then
    docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
      --entrypoint createdb "$POSTGRES_IMAGE" -h parity-pg -U parity "$database" > "$evidence/$target-create.txt"
    created+=("$database")
  else
    database=/state/database.sqlite
    if test "$target" = php; then touch "$state/php/database.sqlite"; chmod 0666 "$state/php/database.sqlite"; fi
  fi
  configuration=("${db[@]}" -e DB_DATABASE="$database" -e DW_AUTH_TOKEN=external-fixture-token)
  mount=(-v "$PWD/$state/$target:/state")
  if test "$target" = php; then
    docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network "$RESOURCE_SCOPE" \
      "${configuration[@]}" "${mount[@]}" -e CACHE_STORE=file -e QUEUE_CONNECTION=database \
      --entrypoint php "$image" artisan server:bootstrap --force > "$evidence/php-bootstrap.txt" 2>&1
    docker run -d --name "$resource-php" --network "$RESOURCE_SCOPE" --network-alias=parity-external-php \
      --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m "${configuration[@]}" "${mount[@]}" \
      -e CACHE_STORE=file -e QUEUE_CONNECTION=database "$image" > "$evidence/php-container.txt"
  else
    native=(-e DW_RUST_EXPERIMENTAL=1 -v "$PWD/rust-target:/target:ro" --entrypoint /target/debug/durable-workflow-server)
    if test "$backend" != sqlite; then
      docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network "$RESOURCE_SCOPE" \
        "${configuration[@]}" "${mount[@]}" "${native[@]}" "$rust_image" schema-bootstrap > "$evidence/native-bootstrap.json"
    fi
    docker run -d --name "$resource-native" --network "$RESOURCE_SCOPE" --network-alias=parity-external-native \
      --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m "${configuration[@]}" "${mount[@]}" \
      -e DW_BIND_ADDRESS=0.0.0.0:8080 "${native[@]}" "$rust_image" > "$evidence/native-container.txt"
  fi
  servers+=("$target")
  ready=0
  for attempt in $(seq 1 30); do
    if docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint curl "$image" \
      -fsS "http://parity-external-$target:8080/api/ready" > "$evidence/$target-readiness.json"; then ready=1; break; fi
    sleep 1
  done
  test "$ready" = 1
done
for target in php native; do
  url="http://parity-external-$target:8080"
  consumer() {
    label=$1; script=$2; shift 2
    docker run --rm --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m --network "$RESOURCE_SCOPE" \
      --entrypoint php -v "$PWD:/source:ro" -v "$PWD/$evidence:/evidence" -w /source \
      -e DW_PARITY_SDK_AUTOLOAD="/source/$sdk/vendor/autoload.php" \
      -e DW_PARITY_EXTERNAL_ACTIVITY_COUNT "${image}" "scripts/conformance/server-parity/$script.php" \
      "$url" "$@" > "$evidence/$target/$label.json" 2> "$evidence/$target/$label.stderr"
  }
  consumer input external-input-worker external-input
  consumer workflow-author external-result-client external-workflow author
  consumer workflow-reader external-result-client external-workflow reader "/evidence/$target/workflow-author.json"
  consumer activity-author external-result-client external-activity activity-author
  consumer activity-reader external-result-client external-activity activity-reader "/evidence/$target/activity-author.json"
  for count in 1 2; do
    export DW_PARITY_EXTERNAL_ACTIVITY_COUNT=$count
    consumer "arguments-$count-author" external-arguments-worker "external-arguments-$count" author
    consumer "arguments-$count-activity" external-arguments-worker "external-arguments-$count" activity "/evidence/$target/arguments-$count-author.json"
    consumer "arguments-$count-reader" external-arguments-worker "external-arguments-$count" reader "/evidence/$target/arguments-$count-author.json"
  done
done
docker run --rm --user=1000:1000 --cpus=1 --memory=256m --memory-swap=256m --network=none --entrypoint php \
  -v "$PWD:/source:ro" -v "$PWD/$evidence:/evidence:ro" -w /source "$image" \
  scripts/conformance/server-parity/external-consumer-check.php /evidence "$profile" > "$evidence/comparison.json"
cat "$evidence/comparison.json"
