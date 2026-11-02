#!/usr/bin/env bash
# Create the GitHub Release for a published native release tag (Server #325).
#
# GitHub Latest follows the registry: a final release is marked Latest only
# when the rolling-tag job moved (or confirmed) the latest alias to it, so a
# backport behind a newer release never takes Latest from it. A release
# candidate is a prerelease and never Latest. An existing release is left
# unchanged, so a rerun cannot move Latest either.
#
# Env: RELEASE_TAG, RELEASE_COMMIT, VERSION, INDEX_DIGEST, RUN_URL, and
# ROLLING_TAGS (the aliases the rolling-tag job moved, space separated). GH
# overrides the gh command.
set -euo pipefail
fail() { printf 'GitHub Release failed: %s\n' "$1" >&2; exit 1; }
gh_bin=${GH:-gh}
: "${RELEASE_TAG:?}" "${RELEASE_COMMIT:?}" "${VERSION:?}" "${INDEX_DIGEST:?}" "${RUN_URL:?}"
[[ "${RELEASE_TAG#native-}" == "$VERSION" ]] || fail "RELEASE_TAG $RELEASE_TAG does not name VERSION $VERSION."

if "$gh_bin" release view "$RELEASE_TAG" >/dev/null 2>&1; then
  printf 'Release %s already exists; left unchanged.\n' "$RELEASE_TAG"
  exit 0
fi

notes=$(printf 'Durable Workflow Server %s.\n\n| | |\n| --- | --- |\n| Git tag | `%s` → `%s` |\n| Image index | `%s` in `durableworkflow/server` and `ghcr.io/durable-workflow/server` |\n| Release run | %s |\n' \
  "$VERSION" "$RELEASE_TAG" "$RELEASE_COMMIT" "$INDEX_DIGEST" "$RUN_URL")
args=(release create "$RELEASE_TAG" --verify-tag --title "$VERSION" --notes "$notes")
if [[ "$VERSION" == *-* ]]; then
  args+=(--prerelease --latest=false)
elif [[ " ${ROLLING_TAGS:-} " == *" latest "* ]]; then
  args+=(--latest)
else
  printf 'Release %s does not own the latest alias; GitHub Latest stays where it is.\n' "$RELEASE_TAG"
  args+=(--latest=false)
fi
"$gh_bin" "${args[@]}"
