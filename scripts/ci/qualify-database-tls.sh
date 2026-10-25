#!/usr/bin/env bash
set -euo pipefail

: "${TLS_TEST_IMAGE:?Set the source test image or an immutable published image digest.}"
: "${TLS_TEST_PROJECT:?Set the unique qualification project.}"
TLS_TEST_MODE="${TLS_TEST_MODE:-source}"
case "$TLS_TEST_MODE" in
    source) ;;
    published)
        if [[ ! "$TLS_TEST_IMAGE" =~ @sha256:[0-9a-f]{64}$ ]]; then
            echo 'Published TLS qualification requires an immutable image digest.' >&2
            exit 2
        fi
        ;;
    *) echo 'TLS_TEST_MODE must be source or published.' >&2; exit 2 ;;
esac
if [[ "${GITHUB_ACTIONS:-false}" = true ]]; then
    tls_ci_scope="${GITHUB_RUN_ID:?}-${GITHUB_RUN_ATTEMPT:?}-${GITHUB_JOB:?}"
    if [[ "$TLS_TEST_PROJECT" != *"-$tls_ci_scope" ]]; then
        echo 'TLS_TEST_PROJECT must end with the original GitHub run, attempt and job scope.' >&2
        exit 2
    fi
fi
tls_parent="${TLS_TEST_ROOT:-${TMPDIR:-/tmp}}"
mkdir -p "$tls_parent"
TLS_TEST_ROOT="$(mktemp -d "$tls_parent/dw-server-tls.XXXXXX")"
TLS_TEST_UID="$(id -u)"
TLS_TEST_GID="$(id -g)"
TLS_TEST_PASSWORD="$(openssl rand -hex 24)"
TLS_TEST_ROOT_PASSWORD="$(openssl rand -hex 24)"
export TLS_TEST_PROJECT TLS_TEST_ROOT TLS_TEST_IMAGE TLS_TEST_UID TLS_TEST_GID TLS_TEST_PASSWORD TLS_TEST_ROOT_PASSWORD
tls_repository="$(cd -- "$(dirname -- "$0")/../.." && pwd)"
tls_compose="$tls_repository/scripts/ci/database-tls-compose.yaml"
mkdir -p "$TLS_TEST_ROOT/app" "$TLS_TEST_ROOT/certs"
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
    local -a files=(--file "$tls_compose" --file "$TLS_TEST_ROOT/no-ports.yaml")
    if [[ "$TLS_TEST_MODE" = published ]]; then
        files+=(--file "$TLS_TEST_ROOT/published.yaml")
    fi
    docker compose --project-name "$TLS_TEST_PROJECT" "${files[@]}" "$@"
}
cleanup() {
    local result=$?
    trap - EXIT
    compose --profile plaintext down --volumes --remove-orphans || result=1
    rm -rf -- "$TLS_TEST_ROOT" || result=1
    exit "$result"
}
trap cleanup EXIT

if [[ "$TLS_TEST_MODE" = source ]]; then
    # No local environment files or shared vendor state enter source tests.
    git -C "$tls_repository" archive HEAD | tar -xf - -C "$TLS_TEST_ROOT/app"
else
    # Use the installed application, vendor tree and probe shipped in the image.
    # No product-source mount or Composer operation can replace that runtime.
    cat > "$TLS_TEST_ROOT/published.yaml" <<'YAML'
services:
  php:
    working_dir: /app
    volumes: !override
      - "${TLS_TEST_ROOT}/certs:/certs:ro"
YAML
fi
docker run --rm --name "$TLS_TEST_PROJECT-certificates" \
    --label "com.docker.compose.project=$TLS_TEST_PROJECT" \
    --user "$TLS_TEST_UID:$TLS_TEST_GID" --entrypoint sh \
    --cpus 1 --memory 512m --memory-swap 512m \
    -v "$TLS_TEST_ROOT/certs:/certs" -w /certs "$TLS_TEST_IMAGE" -c '
        set -eu
        umask 077
        openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=fixture-ca -addext basicConstraints=critical,CA:TRUE -keyout ca.key -out ca.pem >/dev/null 2>&1
        openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj /CN=wrong-ca -addext basicConstraints=critical,CA:TRUE -keyout wrong-ca.key -out wrong-ca.pem >/dev/null 2>&1
        openssl req -new -newkey rsa:2048 -nodes -subj /CN=tls-pg -keyout server.key -out server.csr >/dev/null 2>&1
        printf "subjectAltName=DNS:tls-pg,DNS:tls-mysql,DNS:tls-maria\nbasicConstraints=CA:FALSE\n" > server.ext
        openssl x509 -req -in server.csr -CA ca.pem -CAkey ca.key -CAcreateserial -days 1 -extfile server.ext -out server.pem >/dev/null 2>&1
    '
if [[ "$TLS_TEST_MODE" = source ]]; then
    # Refuses before any package request unless the image's CA store is checked (GHSA-6q48-6c7w-9gw2).
    compose run --rm --no-deps php sh -c 'sh scripts/ci/composer-trust-guard.sh && composer install --no-dev --no-scripts --no-interaction --no-progress --prefer-dist'
fi
compose up --wait --wait-timeout 240 tls-pg tls-mysql tls-maria redis

probe() {
    local driver="$1" host="$2" port="$3" mode="$4" ca="$5" expected="$6"
    compose run --rm --no-deps \
        -e "DB_CONNECTION=$driver" -e "DB_HOST=$host" -e "DB_PORT=$port" \
        -e "DB_SSLMODE=$mode" -e "DB_SSLROOTCERT=$ca" \
        php php scripts/ci/database-tls-probe.php "$expected"
}
bootstrap() {
    local driver="$1" host="$2" port="$3" mode="$4" ca="$5"
    compose run --rm --no-deps \
        -e "DB_CONNECTION=$driver" -e "DB_HOST=$host" -e "DB_PORT=$port" \
        -e "DB_SSLMODE=$mode" -e "DB_SSLROOTCERT=$ca" \
        php php artisan server:bootstrap --force --quiet
}

for driver in pgsql mysql mariadb; do
    case "$driver" in
        pgsql) host=tls-pg; wrong=wrong-pg; port=5432 ;;
        mysql) host=tls-mysql; wrong=wrong-mysql; port=3306 ;;
        mariadb) host=tls-maria; wrong=wrong-maria; port=3306 ;;
        *) echo 'Unsupported TLS fixture driver.' >&2; exit 1 ;;
    esac
    bootstrap "$driver" "$host" "$port" verify-full /certs/ca.pem
    probe "$driver" "$host" "$port" verify-full /certs/ca.pem verified
    probe "$driver" "$host" "$port" require '' encrypted
    probe "$driver" "$host" "$port" verify-full /certs/wrong-ca.pem refused
    probe "$driver" "$wrong" "$port" verify-full /certs/ca.pem refused
done
probe pgsql tls-pg 5432 verify-full '' refused
probe pgsql tls-pg 5432 verify-full /certs/missing.pem refused
compose stop tls-pg tls-mysql tls-maria
compose up --wait --wait-timeout 240 plain-pg plain-mysql
for driver in pgsql mysql; do
    if [[ "$driver" = pgsql ]]; then host=plain-pg; port=5432; else host=plain-mysql; port=3306; fi
    bootstrap "$driver" "$host" "$port" disable ''
    probe "$driver" "$host" "$port" disable '' plaintext
    probe "$driver" "$host" "$port" require '' refused
done
