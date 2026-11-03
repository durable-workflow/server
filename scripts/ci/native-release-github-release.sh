#!/usr/bin/env bash
# Create the GitHub Release for a published native release (Server #325),
# from protected main after the tag's release run has finished.
#
# The release run itself holds no contents: write; this step, run from main
# in its own Environment, verifies what that run published before it
# writes. It binds independently:
# - the tag to its commit, which protected main must contain;
# - the run: native-release.yml, dispatched from that tag at that commit,
#   completed successfully;
# - the image: both registries' exact version tags name one index, whose
#   build-provenance attestation was signed by native-release.yml from that
#   tag's ref at that commit, in that run.
# GitHub Latest follows the registry: a final release is marked Latest only
# when the latest alias names its index in both registries, read live right
# before the release is created. The caller holds the rolling-alias lock
# across this step, so no alias moves between the read and the write. A
# release candidate is a prerelease and never Latest. An existing Release
# that records exactly this verified release is left unchanged, so a rerun
# cannot move Latest either; any other existing Release is refused. Any
# failed read stops the step before it writes.
#
# Env: RELEASE_TAG, RELEASE_RUN_ID, GITHUB_REPOSITORY, GHCR_IMAGE,
# DOCKERHUB_IMAGE. GH and DOCKER override the gh and docker commands.
set -euo pipefail
fail() { printf 'GitHub Release failed: %s\n' "$1" >&2; exit 1; }
gh_bin=${GH:-gh}
docker_bin=${DOCKER:-docker}
: "${GITHUB_REPOSITORY:?}" "${GHCR_IMAGE:?}" "${DOCKERHUB_IMAGE:?}"
repo=$GITHUB_REPOSITORY
workflow=.github/workflows/native-release.yml

# A plain tag is native only from major 3; native- names the earlier ones.
tag=${RELEASE_TAG:-}
if ! [[ "$tag" =~ ^(native-[0-9]+|[3-9]|[1-9][0-9]+)\.[0-9]+\.[0-9]+(-rc\.[0-9]+)?$ ]]; then
  fail "RELEASE_TAG must be X.Y.Z or X.Y.Z-rc.N from major 3, or native-X.Y.Z(-rc.N)."
fi
version=${tag#native-}
run_id=${RELEASE_RUN_ID:-}
[[ "$run_id" =~ ^[1-9][0-9]*$ ]] || fail "RELEASE_RUN_ID must be the release run's numeric id."

# The tag's commit, through an annotated tag object if there is one.
object=$("$gh_bin" api "repos/$repo/git/ref/tags/$tag" --jq '.object.type + " " + .object.sha') \
  || fail "cannot read tag $tag."
if [[ "$object" == tag\ * ]]; then
  object=$("$gh_bin" api "repos/$repo/git/tags/${object#tag }" --jq '.object.type + " " + .object.sha') \
    || fail "cannot read the tag object of $tag."
fi
[[ "$object" =~ ^commit\ ([0-9a-f]{40})$ ]] || fail "tag $tag does not name a commit ($object)."
commit=${BASH_REMATCH[1]}
status=$("$gh_bin" api "repos/$repo/compare/$commit...main" --jq .status) || fail "cannot compare $commit with main."
[[ "$status" == ahead || "$status" == identical ]] || fail "$commit is not on main (compare status: $status)."

# The release run.
run=$("$gh_bin" api "repos/$repo/actions/runs/$run_id" \
  --jq '[.path, .event, .status, .conclusion, .head_branch, .head_sha] | join(" ")') \
  || fail "cannot read run $run_id."
expected="$workflow workflow_dispatch completed success $tag $commit"
[[ "$run" == "$expected" ]] || fail "run $run_id is '$run', not '$expected'."

# Both exact tags name one index.
digest() {
  "$docker_bin" buildx imagetools inspect --format '{{.Manifest.Digest}}' "$1" || fail "cannot read $1."
}
index=$(digest "$GHCR_IMAGE:$version")
[[ "$index" =~ ^sha256:[0-9a-f]{64}$ ]] || fail "$GHCR_IMAGE:$version has no index digest ('$index')."
hub=$(digest "$DOCKERHUB_IMAGE:$version")
[[ "$hub" == "$index" ]] || fail "$DOCKERHUB_IMAGE:$version is $hub, not $index."

# Its provenance: signed by the release workflow from the tag, at the
# commit, in this run.
invocations=$("$gh_bin" attestation verify "oci://$GHCR_IMAGE@$index" --repo "$repo" \
  --signer-workflow "github.com/$repo/$workflow" --source-ref "refs/tags/$tag" --source-digest "$commit" \
  --deny-self-hosted-runners --format json \
  --jq '.[].verificationResult.signature.certificate.runInvocationURI') \
  || fail "the provenance of $index does not verify for $tag at $commit."
grep -qE "^https://github\.com/$repo/actions/runs/$run_id/attempts/[0-9]+$" <<<"$invocations" \
  || fail "the provenance of $index names no attempt of run $run_id."

# An existing Release is accepted only when its public record is this
# verified release: tag, plain title, candidate state, index, commit and run.
# It is never edited, so a rerun cannot move Latest; any other Release stops
# the step.
if existing=$("$gh_bin" release view "$tag" --repo "$repo" --json tagName,name,isPrerelease,body 2>/dev/null); then
  candidate=false
  [[ "$version" == *-* ]] && candidate=true
  jq -e --arg tag "$tag" --arg title "$version" --argjson candidate "$candidate" --arg index "$index" --arg commit "$commit" \
    --arg run "https://github.com/$repo/actions/runs/$run_id" '
      .tagName == $tag and .name == $title and .isPrerelease == $candidate
      and (.body | contains($index)) and (.body | contains($commit)) and (.body | contains($run))' \
    <<<"$existing" >/dev/null \
    || fail "Release $tag exists but does not record this verified release (tag, title, candidate state, index, commit and run)."
  printf 'Release %s already records this verified release; left unchanged.\n' "$tag"
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
if [[ "$version" != *-* ]]; then
  latest=true
  for image in "$GHCR_IMAGE" "$DOCKERHUB_IMAGE"; do
    current=$(latest_digest "$image")
    if [[ "$current" != "$index" ]]; then
      printf '%s:latest names %s, not this release; GitHub Latest stays where it is.\n' "$image" "${current:-nothing}"
      latest=false
    fi
  done
fi

notes=$(printf 'Durable Workflow Server %s.\n\n| | |\n| --- | --- |\n| Git tag | `%s` → `%s` |\n| Image index | `%s` in `%s` and `%s` |\n| Release run | https://github.com/%s/actions/runs/%s |\n' \
  "$version" "$tag" "$commit" "$index" "$DOCKERHUB_IMAGE" "$GHCR_IMAGE" "$repo" "$run_id")
args=(release create "$tag" --repo "$repo" --verify-tag --title "$version" --notes "$notes")
if [[ "$version" == *-* ]]; then
  args+=(--prerelease --latest=false)
elif [[ "$latest" == true ]]; then
  args+=(--latest)
else
  args+=(--latest=false)
fi
"$gh_bin" "${args[@]}"
