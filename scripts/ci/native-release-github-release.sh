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
# cannot move Latest either; any other existing Release is refused, except
# that REPAIR_LEGACY_NOTES=true replaces an exact identity-only body with the
# tagged notes. Any failed read stops the step before it writes.
#
# Env: RELEASE_TAG, RELEASE_RUN_ID, GITHUB_REPOSITORY, GHCR_IMAGE,
# DOCKERHUB_IMAGE, optional REPAIR_LEGACY_NOTES. GH and DOCKER override the
# gh and docker commands.
set -euo pipefail
source scripts/ci/native-registry-absence.sh
fail() { printf 'GitHub Release failed: %s\n' "$1" >&2; exit 1; }
# Reuse the same read-only authority checks as protected alias promotion.
source "$(dirname "${BASH_SOURCE[0]}")/native-release-verify-publication.sh"

# A future final Release cannot precede exact qualification and live alias
# promotion (or a strictly newer live successor). Old/missing evidence refuses;
# the operator must decide a concrete legacy case, not annotate an old run.
if [[ "$version" != *-* ]]; then
  python3 "$(dirname "${BASH_SOURCE[0]}")/native_alias_promote.py" release-order \
    || fail "qualified live-alias order is not proved."
fi

# The digest the latest alias names now; empty when it does not exist.
latest_digest() {
  local image="$1" output
  if ! output=$("$docker_bin" buildx imagetools inspect --format '{{.Manifest.Digest}}' "$image:latest" 2>&1); then
    native_registry_subject_missing "$image:latest" "$output" && return 0
    fail "cannot establish the current latest alias."
  fi
  [[ "$output" =~ ^sha256:[0-9a-f]{64}$ ]] || fail "latest alias returned no valid digest."
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

identity=$(printf 'Durable Workflow Server %s.\n\n| | |\n| --- | --- |\n| Git tag | `%s` → `%s` |\n| Image index | `%s` in `%s` and `%s` |\n| Release run | https://github.com/%s/actions/runs/%s |\n' \
  "$version" "$tag" "$commit" "$index" "$DOCKERHUB_IMAGE" "$GHCR_IMAGE" "$repo" "$run_id")
# A final release publishes its tagged notes (Server #1111): the identity
# table, then docs/releases/<version>.md read from the verified tag commit,
# never from later main. Relative links resolve against the immutable tag so
# they work on the Release page. Candidates and the frozen historical
# native-X.Y.Z tags keep the identity table alone. Missing or mismatched
# notes refuse before any write.
notes=$identity
if [[ "$version" != *-* && "$tag" != native-* ]]; then
  tagged=$("$gh_bin" api -H 'Accept: application/vnd.github.raw' \
    "repos/$repo/contents/docs/releases/$version.md?ref=$commit") \
    || fail "cannot read docs/releases/$version.md at $commit."
  [[ "$(head -n1 <<<"$tagged")" == "# Durable Workflow Server $version (native)" ]] \
    || fail "docs/releases/$version.md at $commit does not start with its release heading."
  # shellcheck disable=SC2016 # Perl, not shell, expands these.
  tagged=$(BASE="https://github.com/$repo/blob/$tag" perl -pe '
      sub resolve { my @out; for (split m{/}, "docs/releases/$_[0]") {
          if ($_ eq "..") { return undef unless @out; pop @out } elsif ($_ ne "." && $_ ne "") { push @out, $_ } }
        return join "/", @out }
      s{\]\((?![A-Za-z][A-Za-z0-9+.-]*:|#|/)([^)\s]+)\)}{ my $p = resolve($1);
        defined $p ? "]($ENV{BASE}/$p)" : die "link $1 leaves the repository\n" }ge' <<<"$tagged") \
    || fail "docs/releases/$version.md at $commit has a link outside the repository."
  notes=$(printf '%s\n\n%s\n' "$identity" "$tagged")
fi
# An existing Release is accepted only when it is exactly the Release this
# step would publish: published (not a draft), tag, plain title, candidate
# state and these exact notes, and Latest exactly when the live alias says
# so, read under the same lock. Any other Release stops the step.
#
# REPAIR_LEGACY_NOTES=true is the one edit: when everything else matches and
# the body is exactly the identity table an earlier writer published without
# notes, only the body is replaced with these notes and read back. Latest is
# passed as it already stands, so the repair cannot move it.
if existing=$("$gh_bin" release view "$tag" --repo "$repo" --json tagName,name,isDraft,isPrerelease,body 2>/dev/null); then
  candidate=false
  [[ "$version" == *-* ]] && candidate=true
  matches() { # expected body
    jq -e --arg tag "$tag" --arg title "$version" --argjson candidate "$candidate" --arg notes "$1" '
        .tagName == $tag and .name == $title and .isDraft == false and .isPrerelease == $candidate
        and ((.body // "") | gsub("\r"; "") | sub("\\s+$"; "")) == ($notes | sub("\\s+$"; ""))' \
      <<<"$existing" >/dev/null
  }
  repair=false
  if ! matches "$notes"; then
    if [[ "${REPAIR_LEGACY_NOTES:-}" == true && "$notes" != "$identity" ]] && matches "$identity"; then
      repair=true
    else
      fail "Release $tag exists but is not exactly this verified release (draft, tag, title, candidate state or notes)."
    fi
  fi
  current=$("$gh_bin" api "repos/$repo/releases/latest" --jq .tag_name) || fail "cannot read the Latest Release."
  if [[ "$latest" == true && "$current" != "$tag" ]] || [[ "$latest" != true && "$current" == "$tag" ]]; then
    fail "Release $tag exists, but Latest is $current where the latest alias calls for $([[ "$latest" == true ]] && echo "$tag" || echo "another release")."
  fi
  if [[ "$repair" == true ]]; then
    "$gh_bin" release edit "$tag" --repo "$repo" --notes "$notes" "--latest=$latest" >/dev/null \
      || fail "cannot replace the legacy notes of Release $tag."
    existing=$("$gh_bin" release view "$tag" --repo "$repo" --json tagName,name,isDraft,isPrerelease,body) \
      || fail "cannot read Release $tag back."
    matches "$notes" || fail "Release $tag did not read back with its tagged notes."
    after=$("$gh_bin" api "repos/$repo/releases/latest" --jq .tag_name) || fail "cannot read the Latest Release back."
    [[ "$after" == "$current" ]] || fail "repairing $tag moved Latest from $current to $after."
    printf 'Release %s now carries its tagged notes; Latest is still %s.\n' "$tag" "$current"
    exit 0
  fi
  printf 'Release %s is exactly this verified release; left unchanged.\n' "$tag"
  exit 0
fi

args=(release create "$tag" --repo "$repo" --verify-tag --title "$version" --notes "$notes")
if [[ "$version" == *-* ]]; then
  args+=(--prerelease --latest=false)
elif [[ "$latest" == true ]]; then
  args+=(--latest)
else
  args+=(--latest=false)
fi
"$gh_bin" "${args[@]}"
