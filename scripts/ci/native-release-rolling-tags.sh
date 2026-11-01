#!/usr/bin/env bash
# Move the rolling tags of a final native release (Server #325) forward to its
# verified index, in each registry. A release candidate (-rc.N) moves none.
# Each alias moves only to the highest final native release it covers:
# - X.Y to the highest native-X.Y.* release,
# - X to the highest native-X.* release,
# - latest to the highest native release, when it is also above every PHP
#   release (plain X.Y.Z tags).
# A lower or older release leaves an alias where it is, so no alias moves
# backward. The PHP Release workflow leaves latest alone once a native final
# release is above it.
# Env: RELEASE_TAG (native-<version>), INDEX_DIGEST (the verified index),
# GHCR_IMAGE, DOCKERHUB_IMAGE, and the known tags as RELEASE_IMAGE_KNOWN_TAGS
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

if [[ -n "${RELEASE_IMAGE_KNOWN_TAGS:-}" ]]; then
  known=$RELEASE_IMAGE_KNOWN_TAGS
elif [[ -n "${RELEASE_IMAGE_TAG_SOURCE_URL:-}" ]]; then
  known=$(git ls-remote --tags --refs "$RELEASE_IMAGE_TAG_SOURCE_URL" | sed 's#.*refs/tags/##') \
    || fail "cannot list release tags from $RELEASE_IMAGE_TAG_SOURCE_URL."
else
  fail "set RELEASE_IMAGE_KNOWN_TAGS or RELEASE_IMAGE_TAG_SOURCE_URL."
fi

natives=$( { sed -n 's/^native-\([0-9]*\.[0-9]*\.[0-9]*\)$/\1/p' <<<"$known"; printf '%s\n' "$version"; } | sort -u -V)
php_latest=$(grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' <<<"$known" | sort -u -V | tail -n 1 || true)
major=${version%%.*}
minor=${version#*.}; minor="$major.${minor%%.*}"

highest() { grep -E "^$1" <<<"$natives" | tail -n 1; }
aliases=()
[[ "$(highest "${minor//./\\.}\\.")" == "$version" ]] && aliases+=("$minor")
[[ "$(highest "$major\\.")" == "$version" ]] && aliases+=("$major")
if [[ "$(tail -n 1 <<<"$natives")" == "$version" ]] \
  && [[ -z "$php_latest" || "$(printf '%s\n%s\n' "$php_latest" "$version" | sort -V | tail -n 1)" == "$version" ]]; then
  aliases+=(latest)
fi

if ((${#aliases[@]} == 0)); then
  printf 'Native %s is below a newer native release on every alias; no rolling tag moves.\n' "$version"
  printf 'rolling_tags=\n' >> "${GITHUB_OUTPUT:-/dev/null}"
  exit 0
fi

for image in "$GHCR_IMAGE" "$DOCKERHUB_IMAGE"; do
  args=()
  for alias in "${aliases[@]}"; do args+=(--tag "$image:$alias"); done
  "$docker_bin" buildx imagetools create "${args[@]}" "$image@$INDEX_DIGEST"
done

# Each moved alias names exactly the verified index.
for image in "$GHCR_IMAGE" "$DOCKERHUB_IMAGE"; do
  for alias in "${aliases[@]}"; do
    digest=$("$docker_bin" buildx imagetools inspect --format '{{.Manifest.Digest}}' "$image:$alias")
    [[ "$digest" == "$INDEX_DIGEST" ]] || fail "$image:$alias is $digest, not $INDEX_DIGEST."
  done
done

printf 'rolling_tags=%s\n' "${aliases[*]}" >> "${GITHUB_OUTPUT:-/dev/null}"
{
  printf -- '- rolling tags %s moved to `%s` in `%s` and `%s`\n' \
    "$(printf '`%s` ' "${aliases[@]}")" "$INDEX_DIGEST" "$GHCR_IMAGE" "$DOCKERHUB_IMAGE"
} >> "${GITHUB_STEP_SUMMARY:-/dev/null}"
printf 'Native %s: moved %s.\n' "$version" "${aliases[*]}"
