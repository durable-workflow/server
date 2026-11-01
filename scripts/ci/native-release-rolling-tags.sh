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
set -euo pipefail
fail() { printf 'Native rolling tags failed: %s\n' "$1" >&2; exit 1; }
docker_bin=${DOCKER:-docker}

[[ "${RELEASE_TAG:-}" =~ ^native-([0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z][0-9A-Za-z.-]*)?)$ ]] \
  || fail "RELEASE_TAG must be native-<exact release version>."
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
  digest=$("$docker_bin" buildx imagetools inspect --format '{{.Manifest.Digest}}' "$image:$version") \
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

natives=$( { sed -n 's/^native-\([0-9]*\.[0-9]*\.[0-9]*\)$/\1/p' <<<"$known"; printf '%s\n' "$version"; } | sort -u -V)
php_latest=$(grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' <<<"$known" | sort -u -V | tail -n 1 || true)
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
    grep -qiE 'not found|manifest unknown|name unknown' <<<"$output" && return 0
    fail "cannot read the current $ref: $(head -c 300 <<<"$output")"
  fi
  label=$(jq -r 'if has("linux/amd64") then .["linux/amd64"] else . end
    | .config.Labels["dev.durable-workflow.release.tag"] // empty' <<<"$output" 2>/dev/null) \
    || fail "cannot read the release of the current $ref."
  label=${label#native-}
  [[ "$label" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z.-]+)?$ ]] \
    || fail "the current $ref carries no readable release tag ('$label')."
  printf '%s\n' "$label"
}

aliases=()
for alias in "${candidates[@]}"; do
  keep=1
  for image in "${images[@]}"; do
    current=$(current_release "$image:$alias")
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

for image in "${images[@]}"; do
  args=()
  for alias in "${aliases[@]}"; do args+=(--tag "$image:$alias"); done
  "$docker_bin" buildx imagetools create "${args[@]}" "$image@$INDEX_DIGEST"
done

# Each moved alias names exactly the attested index.
for image in "${images[@]}"; do
  for alias in "${aliases[@]}"; do
    digest=$("$docker_bin" buildx imagetools inspect --format '{{.Manifest.Digest}}' "$image:$alias")
    [[ "$digest" == "$INDEX_DIGEST" ]] || fail "$image:$alias is $digest, not $INDEX_DIGEST."
  done
done

printf 'rolling_tags=%s\n' "${aliases[*]}" >> "${GITHUB_OUTPUT:-/dev/null}"
printf -- '- rolling tags %s moved to `%s` in `%s` and `%s`\n' \
  "$(printf '`%s` ' "${aliases[@]}")" "$INDEX_DIGEST" "$GHCR_IMAGE" "$DOCKERHUB_IMAGE" >> "${GITHUB_STEP_SUMMARY:-/dev/null}"
printf 'Native %s: moved %s.\n' "$version" "${aliases[*]}"
