#!/usr/bin/env bash

set -euo pipefail

fail() {
    printf 'Release tag source verification failed: %s\n' "$1" >&2
    exit 1
}

release_tag="${RELEASE_TAG:-}"
release_commit="${RELEASE_COMMIT:-}"
repository="${GITHUB_REPOSITORY:-}"
gh_cli="${GH_CLI:-gh}"

[[ "$release_tag" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z][0-9A-Za-z.-]*)?$ ]] \
    || fail "RELEASE_TAG must be an exact release version."
[[ "$release_commit" =~ ^[0-9a-f]{40}$ ]] \
    || fail "RELEASE_COMMIT must be a full lowercase Git commit SHA."
[[ "$repository" =~ ^[0-9A-Za-z_.-]+/[0-9A-Za-z_.-]+$ ]] \
    || fail "GITHUB_REPOSITORY must identify the release repository."

if ! target="$("$gh_cli" api "repos/$repository/git/ref/tags/$release_tag" \
    --jq '.object.type + " " + .object.sha' 2>/dev/null)"; then
    fail "public tag $release_tag does not exist."
fi

read -r object_type object_sha <<< "$target"
depth=0
while [ "$object_type" = tag ]; do
    [[ "$object_sha" =~ ^[0-9a-f]{40}$ ]] \
        || fail "public tag $release_tag has an invalid annotated-tag target."
    depth=$((depth + 1))
    [ "$depth" -le 16 ] || fail "public tag $release_tag has an invalid annotated-tag chain."
    if ! target="$("$gh_cli" api "repos/$repository/git/tags/$object_sha" \
        --jq '.object.type + " " + .object.sha' 2>/dev/null)"; then
        fail "public tag $release_tag has an unreadable annotated-tag target."
    fi
    read -r object_type object_sha <<< "$target"
done

[ "$object_type" = commit ] \
    || fail "public tag $release_tag does not resolve to a commit."
[[ "$object_sha" =~ ^[0-9a-f]{40}$ ]] \
    || fail "public tag $release_tag resolves to an invalid commit identity."
[ "$object_sha" = "$release_commit" ] \
    || fail "public tag $release_tag points to $object_sha, not planned commit $release_commit."

printf 'Verified public tag %s at planned commit %s.\n' "$release_tag" "$release_commit"
