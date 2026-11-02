#!/usr/bin/env bash
# Create the GitHub Release for a published native release tag (Server #325).
#
# GitHub Latest follows the registry: a final release is marked Latest only
# when the latest alias names its attested index in both registries, read
# live right before the release is created. The caller holds the rolling
# alias lock across this step, so no alias moves between the read and the
# write; a backport behind a newer release, or a release whose alias a newer
# one has since taken, is created without Latest. An alias that cannot be
# read stops the step before any write. A release candidate is a prerelease
# and never Latest. An existing release is left unchanged, so a rerun cannot
# move Latest either.
#
# Env: RELEASE_TAG, RELEASE_COMMIT, VERSION, INDEX_DIGEST, RUN_URL,
# GHCR_IMAGE, DOCKERHUB_IMAGE. GH and DOCKER override the gh and docker
# commands.
set -euo pipefail
fail() { printf 'GitHub Release failed: %s\n' "$1" >&2; exit 1; }
gh_bin=${GH:-gh}
docker_bin=${DOCKER:-docker}
: "${RELEASE_TAG:?}" "${RELEASE_COMMIT:?}" "${VERSION:?}" "${RUN_URL:?}" "${GHCR_IMAGE:?}" "${DOCKERHUB_IMAGE:?}"
[[ "${INDEX_DIGEST:-}" =~ ^sha256:[0-9a-f]{64}$ ]] || fail "INDEX_DIGEST must be the verified index digest."
[[ "${RELEASE_TAG#native-}" == "$VERSION" ]] || fail "RELEASE_TAG $RELEASE_TAG does not name VERSION $VERSION."

if "$gh_bin" release view "$RELEASE_TAG" >/dev/null 2>&1; then
  printf 'Release %s already exists; left unchanged.\n' "$RELEASE_TAG"
  exit 0
fi

# The digest the latest alias names now; empty when it does not exist.
latest_digest() {
  local output
  if ! output=$("$docker_bin" buildx imagetools inspect --format '{{.Manifest.Digest}}' "$1:latest" 2>&1); then
    grep -qiE 'not found|manifest unknown|name unknown' <<<"$output" && return 0
    fail "cannot read the current $1:latest: $(head -c 300 <<<"$output")"
  fi
  printf '%s\n' "$output"
}

latest=false
if [[ "$VERSION" != *-* ]]; then
  latest=true
  for image in "$GHCR_IMAGE" "$DOCKERHUB_IMAGE"; do
    current=$(latest_digest "$image")
    if [[ "$current" != "$INDEX_DIGEST" ]]; then
      printf '%s:latest names %s, not this release; GitHub Latest stays where it is.\n' "$image" "${current:-nothing}"
      latest=false
    fi
  done
fi

notes=$(printf 'Durable Workflow Server %s.\n\n| | |\n| --- | --- |\n| Git tag | `%s` → `%s` |\n| Image index | `%s` in `%s` and `%s` |\n| Release run | %s |\n' \
  "$VERSION" "$RELEASE_TAG" "$RELEASE_COMMIT" "$INDEX_DIGEST" "$DOCKERHUB_IMAGE" "$GHCR_IMAGE" "$RUN_URL")
args=(release create "$RELEASE_TAG" --verify-tag --title "$VERSION" --notes "$notes")
if [[ "$VERSION" == *-* ]]; then
  args+=(--prerelease --latest=false)
elif [[ "$latest" == true ]]; then
  args+=(--latest)
else
  args+=(--latest=false)
fi
"$gh_bin" "${args[@]}"
