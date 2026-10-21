#!/usr/bin/env bash
# Qualifies native database TLS against the same TLS-enabled PostgreSQL, MySQL
# and MariaDB fixtures as scripts/ci/qualify-database-tls.sh, with PHP's cases:
# a verified connection, an encrypted one, a wrong CA, a wrong hostname, a
# missing CA, plaintext with disable, and require against a plaintext server.
# Native refuses an invalid or failed TLS connection at startup with PHP's
# reason; an accepted one reports PHP's readiness snapshot. The database side
# confirms the transport: PostgreSQL's pg_stat_ssl, and MySQL/MariaDB accounts
# that require TLS.
# Usage: native-database-tls.sh NATIVE_BINARY
set -euo pipefail

binary="$(realpath "${1:?Pass the native server binary.}")"
repository="$(cd -- "$(dirname -- "$0")/../.." && pwd)"
TLS_TEST_IMAGE="${TLS_TEST_IMAGE:-ghcr.io/durable-workflow/server@sha256:28fa0f3968ea253c727aad7fe583bb61c788553f1e4b96ece6ea4570d20faf42}"
TLS_TEST_POSTGRES_IMAGE="${TLS_TEST_POSTGRES_IMAGE:-mirror.gcr.io/library/postgres@sha256:3645570cccdfa447589da9f57dd740faa29b30938e861289a5574b6ca6b03826}"
TLS_TEST_MYSQL_IMAGE="${TLS_TEST_MYSQL_IMAGE:-mirror.gcr.io/library/mysql@sha256:62fb722c78b24245ddff1796a0fcee4a49cc5b87e0aaaf20c92d1da9e0a2497b}"
TLS_TEST_MARIADB_IMAGE="${TLS_TEST_MARIADB_IMAGE:-mirror.gcr.io/library/mariadb@sha256:7db29378d4fdab73f8123bbc2b48905c90d1a4b00cf848b028f1e81e623257f2}"
RUST_RUNTIME_IMAGE="${RUST_RUNTIME_IMAGE:-mirror.gcr.io/library/rust@sha256:ba81bc3eaa4422af576c0262515d96b0111a628a6ccc2c86557cf55c9a4bbee0}"
TLS_TEST_PROJECT="${TLS_TEST_PROJECT:-dw-native-tls-$(date +%s)}"
tls_parent="${TLS_TEST_ROOT:-${TMPDIR:-/tmp}}"
mkdir -p "$tls_parent"
TLS_TEST_ROOT="$(mktemp -d "$tls_parent/dw-native-tls.XXXXXX")"
TLS_TEST_UID="$(id -u)"
TLS_TEST_GID="$(id -g)"
TLS_TEST_PASSWORD="$(openssl rand -hex 24)"
TLS_TEST_ROOT_PASSWORD="$(openssl rand -hex 24)"
export TLS_TEST_PROJECT TLS_TEST_ROOT TLS_TEST_IMAGE TLS_TEST_UID TLS_TEST_GID TLS_TEST_PASSWORD \
    TLS_TEST_ROOT_PASSWORD TLS_TEST_POSTGRES_IMAGE TLS_TEST_MYSQL_IMAGE TLS_TEST_MARIADB_IMAGE
network="${TLS_TEST_PROJECT}_default"
native="$TLS_TEST_PROJECT-native"
failures=0

cat > "$TLS_TEST_ROOT/no-ports.yaml" <<'YAML'
services:
  tls-pg: &no-ports
    ports: !override []
  tls-mysql: *no-ports
  tls-maria: *no-ports
  plain-pg: *no-ports
  plain-mysql: *no-ports
  redis: *no-ports
  php: *no-ports
YAML
compose() {
    docker compose --project-name "$TLS_TEST_PROJECT" --file "$repository/scripts/ci/database-tls-compose.yaml" \
        --file "$TLS_TEST_ROOT/no-ports.yaml" "$@"
}
cleanup() {
    local result=$?
    trap - EXIT
    docker rm -f "$native" >/dev/null 2>&1 || true
    compose --profile plaintext down --volumes --remove-orphans >/dev/null 2>&1 || result=1
    rm -rf -- "$TLS_TEST_ROOT" || result=1
    exit "$result"
}
trap cleanup EXIT

mkdir -p "$TLS_TEST_ROOT/certs" "$TLS_TEST_ROOT/bin"
cp "$binary" "$TLS_TEST_ROOT/bin/server"
docker run --rm --user "$TLS_TEST_UID:$TLS_TEST_GID" --entrypoint sh --cpus 1 --memory 512m \
    -v "$TLS_TEST_ROOT/certs:/certs" -w /certs "$TLS_TEST_IMAGE" -c '
        set -eu
        umask 077
        openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=fixture-ca -addext basicConstraints=critical,CA:TRUE -keyout ca.key -out ca.pem >/dev/null 2>&1
        openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=wrong-ca -addext basicConstraints=critical,CA:TRUE -keyout wrong-ca.key -out wrong-ca.pem >/dev/null 2>&1
        openssl req -new -newkey rsa:2048 -nodes -subj /CN=tls-pg -keyout server.key -out server.csr >/dev/null 2>&1
        printf "subjectAltName=DNS:tls-pg,DNS:tls-mysql,DNS:tls-maria\nbasicConstraints=CA:FALSE\n" > server.ext
        openssl x509 -req -in server.csr -CA ca.pem -CAkey ca.key -CAcreateserial -days 1 -extfile server.ext -out server.pem >/dev/null 2>&1
    '
compose up --wait --wait-timeout 240 tls-pg tls-mysql tls-maria

# MySQL and MariaDB accept the fixture account only over TLS, so a native
# connection that succeeds there is encrypted.
compose exec -T -e "MYSQL_PWD=$TLS_TEST_ROOT_PASSWORD" tls-mysql \
    mysql --protocol=tcp -h127.0.0.1 -uroot -e "ALTER USER 'dw_tls'@'%' REQUIRE SSL"
compose exec -T -e "MYSQL_PWD=$TLS_TEST_ROOT_PASSWORD" tls-maria \
    mariadb --protocol=tcp -h127.0.0.1 -uroot -e "ALTER USER 'dw_tls'@'%' REQUIRE SSL"

start_native() {
    docker rm -f "$native" >/dev/null 2>&1 || true
    docker run -d --name "$native" --network "$network" --user "$TLS_TEST_UID:$TLS_TEST_GID" \
        --cpus 1 --memory 512m -v "$TLS_TEST_ROOT/bin:/native:ro" -v "$TLS_TEST_ROOT/certs:/certs:ro" \
        -e DW_RUST_EXPERIMENTAL=1 -e DW_AUTH_TOKEN=native-tls-fixture-token -e DW_BIND_ADDRESS=0.0.0.0:8080 \
        -e DB_DATABASE=dw_tls -e DB_USERNAME=dw_tls -e "DB_PASSWORD=$TLS_TEST_PASSWORD" "$@" \
        --entrypoint /native/server "$RUST_RUNTIME_IMAGE" >/dev/null
}

# Prints the readiness body once native answers, or nothing if it exits.
readiness() {
    local attempt
    for attempt in $(seq 1 60); do
        if [[ "$(docker inspect -f '{{.State.Running}}' "$native" 2>/dev/null)" != true ]]; then
            return 0
        fi
        if docker run --rm --network "$network" --entrypoint curl "$TLS_TEST_IMAGE" -fsS \
            "http://$native:8080/api/ready" 2>/dev/null; then
            return 0
        fi
        sleep 1
    done
}

record() {
    local label="$1" pass="$2" detail="$3"
    printf '{"case":"%s","pass":%s,%s}\n' "$label" "$pass" "$detail"
    if [[ "$pass" != true ]]; then
        failures=$((failures + 1))
    fi
}

postgres_transport() {
    compose exec -T tls-pg psql -U dw_tls -d dw_tls -Atc \
        "SELECT count(*) || ':' || COALESCE(bool_and(s.ssl)::text, 'none') FROM pg_stat_ssl s JOIN pg_stat_activity a USING (pid) WHERE a.client_addr IS NOT NULL AND a.usename = 'dw_tls'" 2>/dev/null
}

# accepted LABEL EXPECTED_SNAPSHOT TRANSPORT_CHECK ENV...
accepted() {
    local label="$1" expected="$2" transport="$3"
    shift 3
    start_native "$@"
    local body tls observed=""
    body="$(readiness)"
    tls="$(jq -cS '.checks.database.tls // null' <<<"${body:-null}" 2>/dev/null || echo null)"
    if [[ "$transport" == pg ]]; then
        observed="$(postgres_transport)"
    fi
    local pass=false
    if [[ "$tls" == "$(jq -cS . <<<"$expected")" && ( "$transport" != pg || "$observed" =~ ^[1-9][0-9]*:true$ ) ]]; then
        pass=true
    fi
    if [[ "$pass" != true ]]; then
        docker logs --tail 5 "$native" 2>&1 | sed 's/^/  native: /' >&2
    fi
    record "$label" "$pass" "\"tls\":$tls,\"postgres_transport\":\"$observed\""
    docker rm -f "$native" >/dev/null
}

# refused LABEL REASON ENV...
refused() {
    local label="$1" reason="$2"
    shift 2
    start_native "$@"
    local status
    status="$(timeout 90 docker wait "$native" 2>/dev/null || echo running)"
    local logs
    logs="$(docker logs "$native" 2>&1 | tail -3)"
    local pass=false
    if [[ "$status" != running && "$status" != 0 && "$logs" == *"$reason"* ]]; then
        pass=true
    fi
    record "$label" "$pass" "\"exit\":\"$status\",\"expected_reason\":\"$reason\""
    docker rm -f "$native" >/dev/null
}

verified='{"mode":"verify-full","encrypted":true,"ca_verified":true,"hostname_verified":true,"evidence":"successful_driver_enforced_connection","scope":"rust_to_configured_database_endpoint"}'
encrypted='{"mode":"require","encrypted":true,"ca_verified":false,"hostname_verified":false,"evidence":"successful_driver_enforced_connection","scope":"rust_to_configured_database_endpoint"}'
plaintext='{"mode":"disable","encrypted":false,"ca_verified":null,"hostname_verified":null,"evidence":"not_verified","scope":"rust_to_configured_database_endpoint"}'
invalid=database_tls_configuration_invalid
failed=database_tls_connection_failed

for driver in pgsql mysql mariadb; do
    case "$driver" in
        pgsql) host=tls-pg; wrong=wrong-pg; port=5432; transport=pg ;;
        mysql) host=tls-mysql; wrong=wrong-mysql; port=3306; transport=account ;;
        mariadb) host=tls-maria; wrong=wrong-maria; port=3306; transport=account ;;
        *) echo 'Unsupported TLS fixture driver.' >&2; exit 1 ;;
    esac
    common=(-e "DB_CONNECTION=$driver" -e "DB_PORT=$port")
    accepted "$driver verified" "$verified" "$transport" "${common[@]}" -e "DB_HOST=$host" \
        -e DB_SSLMODE=verify-full -e DB_SSLROOTCERT=/certs/ca.pem
    accepted "$driver encrypted" "$encrypted" "$transport" "${common[@]}" -e "DB_HOST=$host" \
        -e DB_SSLMODE=require -e DB_SSLROOTCERT=
    # The Helm chart's existing names reach the same connection.
    accepted "$driver chart aliases" "$verified" "$transport" "${common[@]}" -e "DB_HOST=$host" \
        -e DB_SSL_MODE=verify-full -e DB_SSL_ROOT_CERT=/certs/ca.pem
    refused "$driver wrong CA" "$failed" "${common[@]}" -e "DB_HOST=$host" \
        -e DB_SSLMODE=verify-full -e DB_SSLROOTCERT=/certs/wrong-ca.pem
    refused "$driver wrong hostname" "$failed" "${common[@]}" -e "DB_HOST=$wrong" \
        -e DB_SSLMODE=verify-full -e DB_SSLROOTCERT=/certs/ca.pem
done
refused "pgsql missing CA" "$invalid" -e DB_CONNECTION=pgsql -e DB_HOST=tls-pg -e DB_PORT=5432 -e DB_SSLMODE=verify-full
refused "pgsql unreadable CA" "$invalid" -e DB_CONNECTION=pgsql -e DB_HOST=tls-pg -e DB_PORT=5432 \
    -e DB_SSLMODE=verify-full -e DB_SSLROOTCERT=/certs/missing.pem
refused "mysql chain-only verification" "$invalid" -e DB_CONNECTION=mysql -e DB_HOST=tls-mysql -e DB_PORT=3306 \
    -e DB_SSLMODE=verify-ca -e DB_SSLROOTCERT=/certs/ca.pem
# A URL's TLS parameters follow the same rules, and cannot weaken DB_SSLMODE.
url="postgres://dw_tls:$TLS_TEST_PASSWORD@tls-pg:5432/dw_tls"
accepted "pgsql URL verified" "$verified" pg -e DB_CONNECTION=pgsql -e "DB_URL=$url?sslmode=verify-full&sslrootcert=/certs/ca.pem"
refused "pgsql URL downgrade" "$invalid" -e DB_CONNECTION=pgsql -e "DB_URL=$url?sslmode=disable" -e DB_SSLMODE=verify-full \
    -e DB_SSLROOTCERT=/certs/ca.pem

compose stop tls-pg tls-mysql tls-maria >/dev/null
compose --profile plaintext up --wait --wait-timeout 240 plain-pg plain-mysql
for driver in pgsql mysql; do
    if [[ "$driver" == pgsql ]]; then host=plain-pg; port=5432; else host=plain-mysql; port=3306; fi
    accepted "$driver plaintext" "$plaintext" none -e "DB_CONNECTION=$driver" -e "DB_HOST=$host" -e "DB_PORT=$port" \
        -e DB_SSLMODE=disable -e DB_SSLROOTCERT=
    refused "$driver require without server TLS" "$failed" -e "DB_CONNECTION=$driver" -e "DB_HOST=$host" \
        -e "DB_PORT=$port" -e DB_SSLMODE=require -e DB_SSLROOTCERT=
done

if (( failures > 0 )); then
    echo "native database TLS qualification failed $failures case(s)" >&2
    exit 1
fi
echo '{"outcome":"pass"}'
