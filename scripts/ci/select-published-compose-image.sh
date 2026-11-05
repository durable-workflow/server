#!/usr/bin/env bash
# The image the published Compose smoke runs (Server #325).
#
# A manual input wins. Otherwise docker-compose.published.yml must default to
# exactly one official durableworkflow/server:X.Y.Z image, which the smoke
# runs. A release commit moves that default to the release it will be tagged
# as, before the image is published; only then, and only on a pull request,
# does the smoke run the Compose file with the current latest release
# instead. Any other unpublished, unreadable or malformed default fails.
#
# Env: EVENT_NAME, INPUT_SERVER_IMAGE, GITHUB_ENV; COMPOSE_FILE and CURL
# override the file and the curl command.
set -euo pipefail
fail() { printf 'Published Compose image selection failed: %s\n' "$1" >&2; exit 1; }
compose="${COMPOSE_FILE:-docker-compose.published.yml}"
curl_bin="${CURL:-curl}"
: "${GITHUB_ENV:?}"

if [ -n "${INPUT_SERVER_IMAGE:-}" ]; then
  [[ "$INPUT_SERVER_IMAGE" =~ ^[a-zA-Z0-9][a-zA-Z0-9./_:@-]{0,255}$ ]] \
    || fail "manual image reference must be one bounded single-line reference."
  printf 'DW_SERVER_IMAGE=%s\n' "$INPUT_SERVER_IMAGE" >> "$GITHUB_ENV"
  exit 0
fi

[ -f "$compose" ] || fail "cannot read $compose."
[ "$(grep -c '^x-server-image:' "$compose" || true)" = 1 ] || fail "$compose must declare exactly one x-server-image."
default="$(sed -n 's/^x-server-image: &server-image \${DW_SERVER_IMAGE:-\(durableworkflow\/server:[0-9][0-9]*\.[0-9][0-9]*\.[0-9][0-9]*\)}$/\1/p' "$compose")"
[ -n "$default" ] || fail "$compose must default to one official durableworkflow/server:X.Y.Z image."

# Only a lookup curl completes is an answer; a failed one fails the smoke.
if ! status="$("$curl_bin" -sS -o /dev/null -w '%{http_code}' --connect-timeout 10 --max-time 20 \
  "https://hub.docker.com/v2/repositories/durableworkflow/server/tags/${default#durableworkflow/server:}" 2>/dev/null)"; then
  fail "the Docker Hub lookup for $default did not complete."
fi
if [ "$status" = 200 ]; then
  printf 'Smoking the Compose default %s.\n' "$default"
elif [ "$status" = 404 ] && [ "${EVENT_NAME:-}" = pull_request ]; then
  printf '::notice::%s is not published yet; smoking the Compose file with durableworkflow/server:latest.\n' "$default"
  printf 'DW_SERVER_IMAGE=durableworkflow/server:latest\n' >> "$GITHUB_ENV"
else
  fail "$default is not available to smoke (Docker Hub answered ${status:-nothing}, event ${EVENT_NAME:-unknown})."
fi
