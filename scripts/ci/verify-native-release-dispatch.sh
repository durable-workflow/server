#!/usr/bin/env bash
# A native release runs only when dispatched from its own release tag ref, at
# that tag's commit, and that commit is on protected main (Server #325).
# Dispatching from the tag makes the build-provenance attestation's source
# digest the release commit itself; the tag ref alone would let tooling run
# from a commit main never accepted, so main must contain it.
set -euo pipefail
fail() {
    printf 'Native release dispatch refused: %s\n' "$1" >&2
    exit 1
}
release_tag="${RELEASE_TAG:-}"
release_commit="${RELEASE_COMMIT:-}"
dispatch_ref="${DISPATCH_REF:-}"
dispatch_sha="${DISPATCH_SHA:-}"
repository="${GITHUB_REPOSITORY:-}"
gh_cli="${GH_CLI:-gh}"
# Plain X.Y.Z(-rc.N) from major 3, or a native- tag published before plain ones.
[[ "$release_tag" =~ ^(native-[0-9]+|[3-9]|[1-9][0-9]+)\.[0-9]+\.[0-9]+(-rc\.[0-9]+)?$ ]] \
    || fail "RELEASE_TAG must be X.Y.Z or X.Y.Z-rc.N from major 3, or native-X.Y.Z(-rc.N)."
[[ "$release_commit" =~ ^[0-9a-f]{40}$ ]] \
    || fail "RELEASE_COMMIT must be a full lowercase Git commit SHA."
[ "$dispatch_ref" = "refs/tags/$release_tag" ] \
    || fail "dispatch from refs/tags/$release_tag, not ${dispatch_ref:-an empty ref}."
[ "$dispatch_sha" = "$release_commit" ] \
    || fail "the dispatched commit ${dispatch_sha:-(none)} is not RELEASE_COMMIT $release_commit."
[[ "$repository" =~ ^[0-9A-Za-z_.-]+/[0-9A-Za-z_.-]+$ ]] \
    || fail "GITHUB_REPOSITORY must identify the release repository."
# A final release's commit names its own image as the published Compose
# default, so the tagged docker-compose.published.yml installs that release.
# A candidate leaves the default on the last final release.
version="${release_tag#native-}"
if [[ "$version" != *-* ]]; then
    compose="${COMPOSE_FILE:-docker-compose.published.yml}"
    [ -f "$compose" ] || fail "cannot read $compose at the release commit."
    default="$(sed -n 's/^x-server-image: &server-image \${DW_SERVER_IMAGE:-\(.*\)}$/\1/p' "$compose")"
    [ "$default" = "durableworkflow/server:$version" ] \
        || fail "$compose defaults to ${default:-no image}, not durableworkflow/server:$version; move the default in the release commit."
fi
if ! status="$("$gh_cli" api "repos/$repository/compare/$release_commit...main" --jq .status 2>/dev/null)"; then
    fail "cannot compare $release_commit with main."
fi
case "$status" in
    ahead | identical) ;;
    *) fail "RELEASE_COMMIT $release_commit is not on main (compare status: ${status:-none})." ;;
esac
printf 'Dispatched from %s at %s, which main contains.\n' "$dispatch_ref" "$release_commit"
