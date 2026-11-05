#!/usr/bin/env bash
# Reconcile the rolling tags of a final native release (Server #325) with its
# attested index, in each registry. A release candidate (-rc.N) moves none.
#
# Safe to rerun on its own: it first checks that both exact tags already name
# INDEX_DIGEST, writes only aliases, and an alias that already names it is
# rewritten unchanged.
#
# Each alias moves only to the highest final release it covers, judged twice:
# - from the Git tags, read once with a checked status: X.Y follows the
#   highest native-X.Y.*, X the highest native-X.*, and latest the highest
#   native release when it is also above every PHP release (plain X.Y.Z);
# - from the registries, right before writing (the caller serializes alias
#   writes across releases): an alias whose current image carries a higher
#   release tag (dev.durable-workflow.release.tag) stays where it is, so an
#   older release that read stale Git tags still cannot move it backward.
# An alias whose current release cannot be read stops the run before any
# write. The PHP Release workflow leaves latest alone once a final native
# release is above it.
#
# Env: RELEASE_TAG (native-<version>), INDEX_DIGEST (the attested index),
# GHCR_IMAGE, DOCKERHUB_IMAGE, and the Git tags as RELEASE_IMAGE_KNOWN_TAGS
# (one per line) or RELEASE_IMAGE_TAG_SOURCE_URL (git ls-remote). DOCKER
# overrides the docker command.
set -eEuo pipefail
source scripts/ci/native-registry-absence.sh
fail() { printf 'Native rolling tags failed: %s\n' "$1" >&2; exit 1; }
docker_bin=${DOCKER:-docker}
manifest_digest_format='{{.Manifest.Digest}}'

# A plain tag is native only from major 3, above every PHP release; native-
# names the tags published before plain ones.
release_label=${RELEASE_TAG:-}
[[ "$release_label" == native-* || "$release_label" =~ ^([3-9]|[1-9][0-9]+)\. ]] \
  || fail "RELEASE_TAG must be a native release version: X.Y.Z from major 3, or native-<version>."
[[ "${release_label#native-}" =~ ^([0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z][0-9A-Za-z.-]*)?)$ ]] \
  || fail "RELEASE_TAG must be a native release version: X.Y.Z from major 3, or native-<version>."
version=${BASH_REMATCH[1]}
if [[ -n "${BASH_REMATCH[2]}" ]]; then
  printf 'Native %s is not a final release; no rolling tag moves.\n' "$version"
  printf 'rolling_tags=\n' >> "${GITHUB_OUTPUT:-/dev/null}"
  exit 0
fi
[[ "${INDEX_DIGEST:-}" =~ ^sha256:[0-9a-f]{64}$ ]] || fail "INDEX_DIGEST must be the verified index digest."
: "${GHCR_IMAGE:?}" "${DOCKERHUB_IMAGE:?}"
images=("$GHCR_IMAGE" "$DOCKERHUB_IMAGE")

# The published release first: both exact tags must name the attested index.
for image in "${images[@]}"; do
  digest=$("$docker_bin" buildx imagetools inspect --format "$manifest_digest_format" "$image:$version") \
    || fail "cannot read $image:$version."
  [[ "$digest" == "$INDEX_DIGEST" ]] || fail "$image:$version is $digest, not the attested $INDEX_DIGEST."
done

# The Git tags, read once with a checked status.
if [[ -n "${RELEASE_IMAGE_KNOWN_TAGS:-}" ]]; then
  known=$RELEASE_IMAGE_KNOWN_TAGS
elif [[ -n "${RELEASE_IMAGE_TAG_SOURCE_URL:-}" ]]; then
  listing=$(git ls-remote --tags --refs "$RELEASE_IMAGE_TAG_SOURCE_URL") \
    || fail "cannot list release tags from $RELEASE_IMAGE_TAG_SOURCE_URL."
  known=$(sed 's#.*refs/tags/##' <<<"$listing")
else
  fail "set RELEASE_IMAGE_KNOWN_TAGS or RELEASE_IMAGE_TAG_SOURCE_URL."
fi

# Native finals: native-X.Y.Z tags and plain X.Y.Z from major 3. PHP finals:
# plain X.Y.Z below major 3.
natives=$( { sed -n 's/^native-\([0-9]*\.[0-9]*\.[0-9]*\)$/\1/p' <<<"$known"
  grep -E '^([3-9]|[1-9][0-9]+)\.[0-9]+\.[0-9]+$' <<<"$known" || true
  printf '%s\n' "$version"; } | sort -u -V)
php_latest=$(grep -E '^[0-2]\.[0-9]+\.[0-9]+$' <<<"$known" | sort -u -V | tail -n 1 || true)
major=${version%%.*}
minor=${version#*.}; minor="$major.${minor%%.*}"
newer() { [[ "$1" != "$2" && "$(printf '%s\n%s\n' "$1" "$2" | sort -V | tail -n 1)" == "$1" ]]; }

highest() { grep -E "^$1" <<<"$natives" | tail -n 1; }
candidates=()
[[ "$(highest "${minor//./\\.}\\.")" == "$version" ]] && candidates+=("$minor")
[[ "$(highest "$major\\.")" == "$version" ]] && candidates+=("$major")
if [[ "$(tail -n 1 <<<"$natives")" == "$version" ]] && { [[ -z "$php_latest" ]] || ! newer "$php_latest" "$version"; }; then
  candidates+=(latest)
fi

# The release an alias names now: the release tag label of its image, without
# the native- prefix; empty when the alias does not exist yet.
current_release() {
  local ref=$1 output label
  if ! output=$("$docker_bin" buildx imagetools inspect --format '{{json .Image}}' "$ref" 2>&1); then
    native_registry_subject_missing "$ref" "$output" && return 0
    fail "cannot read a current alias."
  fi
  label=$(jq -r 'if has("linux/amd64") then .["linux/amd64"] else . end
    | .config.Labels["dev.durable-workflow.release.tag"] // empty' <<<"$output" 2>/dev/null) \
    || fail "cannot read the release of the current $ref."
  label=${label#native-}
  [[ "$label" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?$ ]] \
    || fail "a current alias carries no readable release tag."
  printf '%s\n' "$label"
}

# Final Release creation uses the same version rules, but cannot manufacture
# promotion: every applicable alias in BOTH registries must already name this
# index or a strictly newer final release. Candidates never enter this script.
if [[ "${VERIFY_ONLY:-false}" == true ]]; then
  for alias in "$minor" "$major" latest; do
    for image in "${images[@]}"; do
      current=$(current_release "$image:$alias")
      [[ -n "$current" ]] || fail "qualified live alias is absent."
      [[ "$current" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || fail "qualified live alias must be final."
      if newer "$current" "$version"; then continue; fi
      [[ "$current" == "$version" ]] || fail "qualified live alias is older."
      digest=$("$docker_bin" buildx imagetools inspect --format "$manifest_digest_format" "$image:$alias")
      [[ "$digest" == "$INDEX_DIGEST" ]] || fail "qualified live alias names another index."
    done
  done
  printf 'Qualified live alias state verified.\n'
  exit 0
fi

declare -A snapshots=()
aliases=()
for alias in "${candidates[@]}"; do
  keep=1
  for image in "${images[@]}"; do
    current=$(current_release "$image:$alias")
    state=absent
    if [[ -n "$current" ]]; then
      state=$("$docker_bin" buildx imagetools inspect --format "$manifest_digest_format" "$image:$alias")
      [[ "$state" =~ ^sha256:[0-9a-f]{64}$ ]] || fail "alias digest unreadable."
    fi
    snapshots["$image:$alias"]="$current $state"
    if [[ -n "$current" ]] && newer "$current" "$version"; then
      printf '%s:%s already names %s, newer than %s; it stays.\n' "$image" "$alias" "$current" "$version"
      keep=0
    fi
  done
  ((keep)) && aliases+=("$alias")
done

if ((${#aliases[@]} == 0)); then
  printf 'Native %s is below a newer native release on every alias; no rolling tag moves.\n' "$version"
  printf 'rolling_tags=\n' >> "${GITHUB_OUTPUT:-/dev/null}"
  exit 0
fi

# This repository lock coordinates owned writers. Registries offer no CAS:
# an external writer can still race AFTER these reads. A mismatch is a stop,
# with explicit partial/uncertain state and no automatic rollback.
confirmed=()
attempted=none
partial() {
  printf 'Native alias promotion stopped; attempted=%s confirmed=%s; no rollback.\n' \
    "$attempted" "${confirmed[*]:-none}" >&2
  printf -- '- alias promotion stopped; attempted=%s confirmed=%s; no rollback.\n' \
    "$attempted" "${confirmed[*]:-none}" >> "${GITHUB_STEP_SUMMARY:-/dev/null}"
}
trap partial ERR
for image in "${images[@]}"; do
  # Re-read BOTH exact tags and every selected alias immediately before EACH
  # registry write. Previously confirmed writes must still name this index.
  for checked in "${images[@]}"; do
    digest=$("$docker_bin" buildx imagetools inspect --format "$manifest_digest_format" "$checked:$version")
    [[ "$digest" == "$INDEX_DIGEST" ]] || { partial; fail "exact tag changed before write."; }
    for alias in "${aliases[@]}"; do
      if [[ " ${confirmed[*]:-} " == *" $checked "* ]]; then
        state=$("$docker_bin" buildx imagetools inspect --format "$manifest_digest_format" "$checked:$alias")
        [[ "$state" == "$INDEX_DIGEST" ]] || { partial; fail "confirmed alias changed."; }
      else
        current=$(current_release "$checked:$alias")
        state=absent
        if [[ -n "$current" ]]; then
          state=$("$docker_bin" buildx imagetools inspect --format "$manifest_digest_format" "$checked:$alias")
        fi
        [[ "$current $state" == "${snapshots["$checked:$alias"]}" ]] \
          || { partial; fail "alias changed before write."; }
      fi
    done
  done
  args=()
  for alias in "${aliases[@]}"; do args+=(--tag "$image:$alias"); done
  attempted=$image
  printf 'Native alias write attempted: %s at %s.\n' "$image" "$INDEX_DIGEST"
  printf -- '- alias write attempted: `%s` at `%s`\n' "$image" "$INDEX_DIGEST" >> "${GITHUB_STEP_SUMMARY:-/dev/null}"
  "$docker_bin" buildx imagetools create "${args[@]}" "$image@$INDEX_DIGEST"
  for alias in "${aliases[@]}"; do
    digest=$("$docker_bin" buildx imagetools inspect --format "$manifest_digest_format" "$image:$alias")
    [[ "$digest" == "$INDEX_DIGEST" ]] || { partial; fail "post-write alias mismatch."; }
  done
  confirmed+=("$image")
  printf 'Native alias write confirmed: %s at %s.\n' "$image" "$INDEX_DIGEST"
  printf -- '- alias write confirmed: `%s` at `%s`\n' "$image" "$INDEX_DIGEST" >> "${GITHUB_STEP_SUMMARY:-/dev/null}"
done

# Each moved alias names exactly the attested index.
for image in "${images[@]}"; do
  for alias in "${aliases[@]}"; do
    digest=$("$docker_bin" buildx imagetools inspect --format "$manifest_digest_format" "$image:$alias")
    [[ "$digest" == "$INDEX_DIGEST" ]] || fail "$image:$alias is $digest, not $INDEX_DIGEST."
  done
done

printf 'rolling_tags=%s\n' "${aliases[*]}" >> "${GITHUB_OUTPUT:-/dev/null}"
printf -- '- rolling tags %s moved to `%s` in `%s` and `%s`\n' \
  "$(printf '`%s` ' "${aliases[@]}")" "$INDEX_DIGEST" "$GHCR_IMAGE" "$DOCKERHUB_IMAGE" >> "${GITHUB_STEP_SUMMARY:-/dev/null}"
printf 'Native %s: moved %s.\n' "$version" "${aliases[*]}"
