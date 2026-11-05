#!/usr/bin/env bash
# Shared read-only tag/source/run-attempt/exact-tag/provenance verification.
# Sourced by Release creation; direct invocation emits only validated identity.
# No registry, Git ref or Release mutation is present in this helper.
set -euo pipefail
fail() { printf 'Native publication refused: %s\n' "$1" >&2; exit 1; }
gh_bin=${GH:-gh}
docker_bin=${DOCKER:-docker}
: "${GITHUB_REPOSITORY:?}" "${GHCR_IMAGE:?}" "${DOCKERHUB_IMAGE:?}"
repo=$GITHUB_REPOSITORY
[[ "$repo" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || fail "invalid repository."
workflow=.github/workflows/native-release.yml

# A plain tag is native only from major 3; native- names the earlier ones.
tag=${RELEASE_TAG:-}
if ! [[ "$tag" =~ ^(native-[0-9]+|[3-9]|[1-9][0-9]+)\.[0-9]+\.[0-9]+(-rc\.[0-9]+)?$ ]]; then
  fail "RELEASE_TAG must be X.Y.Z or X.Y.Z-rc.N from major 3, or native-X.Y.Z(-rc.N)."
fi
version=${tag#native-}
run_id=${RELEASE_RUN_ID:-}
[[ "$run_id" =~ ^[1-9][0-9]*$ ]] || fail "RELEASE_RUN_ID must be the release run's numeric id."

run_attempt=${RELEASE_RUN_ATTEMPT:-}
[[ "$run_attempt" =~ ^[1-9][0-9]*$ ]] || fail "RELEASE_RUN_ATTEMPT must be the exact numeric attempt."

# The tag's commit, through an annotated tag object if there is one.
object=$("$gh_bin" api "repos/$repo/git/ref/tags/$tag" --jq '.object.type + " " + .object.sha') \
  || fail "cannot read tag $tag."
if [[ "$object" == tag\ * ]]; then
  object=$("$gh_bin" api "repos/$repo/git/tags/${object#tag }" --jq '.object.type + " " + .object.sha') \
    || fail "cannot read the tag object of $tag."
fi
[[ "$object" =~ ^commit\ ([0-9a-f]{40})$ ]] || fail "tag $tag does not name a commit."
commit=${BASH_REMATCH[1]}
status=$("$gh_bin" api "repos/$repo/compare/$commit...main" --jq .status) || fail "cannot compare $commit with main."
[[ "$status" == ahead || "$status" == identical ]] || fail "$commit is not on main (compare status: $status)."

# The release run.
run=$("$gh_bin" api "repos/$repo/actions/runs/$run_id" \
  --jq '[.path, .event, .status, .conclusion, .head_branch, .head_sha, (.run_attempt | tostring)] | join(" ")') \
  || fail "cannot read run $run_id."
expected="$workflow workflow_dispatch completed success $tag $commit $run_attempt"
[[ "$run" == "$expected" ]] || fail "release run identity, attempt or success does not match."

# Both exact tags name one index.
digest() {
  local subject="$1"
  "$docker_bin" buildx imagetools inspect --format '{{.Manifest.Digest}}' "$subject" || fail "cannot read $subject."
}
index=$(digest "$GHCR_IMAGE:$version")
[[ "$index" =~ ^sha256:[0-9a-f]{64}$ ]] || fail "exact version tag has no index digest."
hub=$(digest "$DOCKERHUB_IMAGE:$version")
[[ "$hub" == "$index" ]] || fail "exact version tags disagree."

# Its provenance: signed by the release workflow from the tag, at the
# commit, in this run.
for image in "$GHCR_IMAGE" "$DOCKERHUB_IMAGE"; do
  invocations=$("$gh_bin" attestation verify "oci://$image@$index" --repo "$repo" \
    --signer-workflow "github.com/$repo/$workflow" --source-ref "refs/tags/$tag" --source-digest "$commit" \
    --deny-self-hosted-runners --format json \
    --jq '.[].verificationResult.signature.certificate.runInvocationURI') \
    || fail "release index provenance does not verify."
  grep -Fxq "https://github.com/$repo/actions/runs/$run_id/attempts/$run_attempt" <<<"$invocations" \
    || fail "release provenance does not name the exact run attempt."
done

if [[ "${BASH_SOURCE[0]}" == "$0" ]]; then
  jq -nc --arg tag "$tag" --arg source "$commit" --arg index "$index" \
    --argjson run "$run_id" --argjson attempt "$run_attempt" \
    '{tag:$tag,source_commit:$source,index_digest:$index,run_id:$run,run_attempt:$attempt}'
fi
