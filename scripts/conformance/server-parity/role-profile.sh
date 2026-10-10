#!/usr/bin/env bash
# A separate configured-token execution cohort using the already built binary
# and locked SDK adapter. No Cargo invocation or shared database takeover.
set -euo pipefail
test -n "$RESOURCE_SCOPE"
backend="$1"
image=$(jq -r '.server.images["linux/amd64"] | sub("^durableworkflow/server@"; "ghcr.io/durable-workflow/server@")' tests/Fixtures/ServerParityBaselines/php-2.5.14.json)
rust_image=mirror.gcr.io/library/rust@sha256:ba81bc3eaa4422af576c0262515d96b0111a628a6ccc2c86557cf55c9a4bbee0
fixture=tests/Fixtures/ServerParityPending/admission-role-tokens.json
credentials=(-e DW_AUTH_TOKEN=parity-role-legacy -e DW_WORKER_TOKEN=parity-role-worker
  -e DW_OPERATOR_TOKEN=parity-role-operator -e DW_ADMIN_TOKEN=parity-role-admin)
recorder=(-e DW_PARITY_TOKEN=parity-role-legacy -e DW_PARITY_WORKER_TOKEN=parity-role-worker
  -e DW_PARITY_OPERATOR_TOKEN=parity-role-operator -e DW_PARITY_ADMIN_TOKEN=parity-role-admin)
db_mount=()
case "$backend" in
  mysql)
    db_env=(-e DB_CONNECTION=mysql -e DB_HOST=parity-mysql -e DB_USERNAME=root -e DB_PASSWORD=parity-disposable-password)
    for name in role_php_ref role_native_ref role_embedded_ref; do
      docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint mysql "$MYSQL_IMAGE" \
        -h parity-mysql -uroot -pparity-disposable-password -e "CREATE DATABASE $name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
    done
    ;;
  pgsql)
    db_env=(-e DB_CONNECTION=pgsql -e DB_HOST=parity-pg -e DB_USERNAME=parity -e DB_PASSWORD=parity-disposable-password)
    for name in role_php_ref role_native_ref role_embedded_ref; do
      docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" -e PGPASSWORD=parity-disposable-password \
        --entrypoint psql "$POSTGRES_IMAGE" -h parity-pg -U parity -d postgres -c "CREATE DATABASE $name"
    done
    ;;
  sqlite)
    db_env=(-e DB_CONNECTION=sqlite)
    db_mount=(-v "$PWD/parity-state:/state")
    touch parity-state/role_php_ref.sqlite parity-state/role_embedded_ref.sqlite
    chmod 0666 parity-state/role_php_ref.sqlite parity-state/role_embedded_ref.sqlite
    ;;
  *) exit 2 ;;
esac
database() {
  if test "$backend" = sqlite; then printf '/state/%s.sqlite' "$1"; else printf '%s' "$1"; fi
}
cleanup() {
  for target in php native; do
    docker logs "$RESOURCE_SCOPE-role-$target" > "parity-evidence/roles-$target.log" 2>&1 || true
    docker rm -f "$RESOURCE_SCOPE-role-$target" || true
  done
}
trap cleanup EXIT
for setting in DW_AUTH_DRIVER=none DW_PRINCIPAL_TOKENS=[] DW_AUTH_BACKWARD_COMPATIBLE=false DW_RUNTIME_CREDENTIALS_ENABLED=true; do
  key="${setting%%=*}"
  if docker run --rm --user=1000:1000 --network=none --entrypoint /target/debug/durable-workflow-server \
    -v "$PWD/rust-target:/target:ro" -e DW_RUST_EXPERIMENTAL=1 -e DW_AUTH_TOKEN=parity-role-legacy \
    -e "$setting" "$rust_image" > "parity-evidence/roles-unqualified-$key.txt" 2>&1; then
    exit 1
  fi
  rg -Fq 'unsupported development authentication configuration' "parity-evidence/roles-unqualified-$key.txt"
done
for target in php embedded; do
  docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint php \
    "${db_mount[@]}" "${db_env[@]}" -e DB_DATABASE="$(database role_"$target"_ref)" \
    -e CACHE_STORE=file -e QUEUE_CONNECTION=database "${credentials[@]}" \
    "$image" artisan server:bootstrap --force > "parity-evidence/roles-$target-bootstrap.txt"
done
if test "$backend" != sqlite; then
  docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" \
    -e DW_RUST_EXPERIMENTAL=1 "${db_env[@]}" -e DB_DATABASE=role_native_ref \
    -v "$PWD/rust-target:/target:ro" --entrypoint /target/debug/durable-workflow-server \
    "$rust_image" schema-bootstrap > parity-evidence/roles-native-bootstrap.json
fi
docker run -d --name "$RESOURCE_SCOPE-role-php" --network "$RESOURCE_SCOPE" --network-alias=parity-role-php \
  --user=1000:1000 --cpus=1 --memory=512m --memory-swap=512m \
  "${db_mount[@]}" "${db_env[@]}" -e DB_DATABASE="$(database role_php_ref)" \
  -e CACHE_STORE=file -e QUEUE_CONNECTION=database "${credentials[@]}" "$image"
docker run -d --name "$RESOURCE_SCOPE-role-native" --network "$RESOURCE_SCOPE" --network-alias=parity-role-native \
  --user=1000:1000 --cpus=1 --memory=256m --memory-swap=256m \
  "${db_mount[@]}" "${db_env[@]}" -e DB_DATABASE="$(database role_native_ref)" \
  -e DW_RUST_EXPERIMENTAL=1 -e DW_BIND_ADDRESS=0.0.0.0:8080 "${credentials[@]}" \
  -v "$PWD/rust-target:/target:ro" --entrypoint /target/debug/durable-workflow-server "$rust_image"
for target in php native; do
  ready=0
  for attempt in $(seq 1 30); do
    if docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint curl "$image" \
      -fsS "http://parity-role-$target:8080/api/ready" > "parity-evidence/roles-$target-readiness.json"; then
      ready=1
      break
    fi
    sleep 1
  done
  test "$ready" = 1
done
export DW_PARITY_RUNNER_REVISION=$(git rev-parse HEAD)
for target in php native; do
  label="$target"
  if test "$target" = native; then label=rust; fi
  docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint node \
    -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -w /source \
    "${recorder[@]}" -e DW_PARITY_RUNNER_REVISION "$image" scripts/conformance/server-parity.mjs record \
    --mode http --url "http://parity-role-$target:8080" --target "$label" --prefix parity-role \
    --fixture "$fixture" --output "/evidence/roles-$label.json"
done
docker run --rm --user=1000:1000 --network "$RESOURCE_SCOPE" --entrypoint node \
  -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence" -w /source \
  "${db_mount[@]}" "${db_env[@]}" -e DB_DATABASE="$(database role_embedded_ref)" \
  -e DW_MODE=embedded -e CACHE_STORE=file -e QUEUE_CONNECTION=database -e DW_PARITY_RUNNER_REVISION \
  "$image" scripts/conformance/server-parity.mjs record --mode embedded --application-root /app \
  --target embedded --prefix parity-role --fixture "$fixture" --output /evidence/roles-embedded.json
docker run --rm --user=1000:1000 --network=none --entrypoint node \
  -v "$PWD:/source:ro" -v "$PWD/parity-evidence:/evidence:ro" -w /source "$image" \
  scripts/conformance/server-parity.mjs compare /evidence/roles-php.json /evidence/roles-embedded.json /evidence/roles-rust.json \
  --fixture "$fixture" > parity-evidence/roles-comparison.txt
