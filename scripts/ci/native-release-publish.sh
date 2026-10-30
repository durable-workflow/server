#!/usr/bin/env bash
# Give the smoked native platform digests (Server #325) one release tag, as
# one index, in each registry, and verify it. A release candidate (-rc.N)
# publishes to GHCR only; any other version to GHCR and Docker Hub.
# RELEASE_TAG is the native Git tag, native-<release version>; the image tag
# is that version without the native- prefix.
# Env: RELEASE_TAG, GHCR_IMAGE, DOCKERHUB_IMAGE, PUBLISH_DOCKERHUB (true or
# false, from the plan job), and DIGEST_AMD64 and
# DIGEST_ARM64 from the build jobs, which push by digest and fail unless that
# exact digest passed its smoke test. Writes digest= to $GITHUB_OUTPUT.
set -euo pipefail
fail() { printf 'Native release publication failed: %s\n' "$1" >&2; exit 1; }
[[ "${RELEASE_TAG:-}" =~ ^native-([0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z][0-9A-Za-z.-]*)?)$ ]] \
  || fail "RELEASE_TAG must be native-<exact release version>."
image_tag=${BASH_REMATCH[1]}
: "${GHCR_IMAGE:?}" "${DOCKERHUB_IMAGE:?}"
# Only a release candidate may skip Docker Hub.
case "${PUBLISH_DOCKERHUB:-}" in
  true) registries=("$GHCR_IMAGE" "$DOCKERHUB_IMAGE") ;;
  false)
    [[ "$image_tag" =~ -rc\.[0-9]+$ ]] || fail "only a release candidate (-rc.N) may skip Docker Hub."
    registries=("$GHCR_IMAGE") ;;
  *) fail "PUBLISH_DOCKERHUB must be true or false." ;;
esac

digests=("${DIGEST_AMD64:-}" "${DIGEST_ARM64:-}")
for digest in "${digests[@]}"; do
  [[ "$digest" =~ ^sha256:[0-9a-f]{64}$ ]] || fail "each platform needs an exact smoked digest."
done

# A release tag is immutable: refuse one that either registry already has.
# Only a registry's own "not found" counts as absent; any other failure to
# look (authentication, network) stops here rather than risk an overwrite.
for image in "${registries[@]}"; do
  if lookup=$(docker buildx imagetools inspect "$image:$image_tag" 2>&1); then
    fail "$image:$image_tag already exists."
  fi
  grep -qiE 'not found|manifest unknown|name unknown' <<<"$lookup" \
    || fail "could not confirm that $image:$image_tag is absent: $(head -c 300 <<<"$lookup")"
done

for image in "${registries[@]}"; do
  docker buildx imagetools create --tag "$image:$image_tag" \
    "$image@${digests[0]}" "$image@${digests[1]}"
done

# Both registries hold the same index, naming exactly the smoked digests.
index=""
for image in "${registries[@]}"; do
  raw=$(docker buildx imagetools inspect --raw "$image:$image_tag")
  digest=$(docker buildx imagetools inspect --format '{{.Manifest.Digest}}' "$image:$image_tag")
  if test -z "$index"; then index=$digest; fi
  test "$digest" = "$index" || fail "$image:$image_tag is $digest, not $index."
  jq -e --arg amd64 "${digests[0]}" --arg arm64 "${digests[1]}" '
    [.manifests[] | {digest, platform: "\(.platform.os)/\(.platform.architecture)"}] | sort_by(.platform)
    == [{digest: $amd64, platform: "linux/amd64"}, {digest: $arm64, platform: "linux/arm64"}]' <<<"$raw" >/dev/null \
    || fail "$image:$image_tag does not name exactly the smoked platform digests."
done

printf 'digest=%s\n' "$index" >> "${GITHUB_OUTPUT:-/dev/null}"
{
  printf '### Native %s\n\n' "$RELEASE_TAG"
  printf -- '- index `%s`, in %s\n' "$index" "$(printf '`%s` ' "${registries[@]/%/:$image_tag}")"
  printf -- '- linux/amd64 `%s`, linux/arm64 `%s`, each smoke-tested on its own architecture\n' "${digests[0]}" "${digests[1]}"
} >> "${GITHUB_STEP_SUMMARY:-/dev/null}"
printf '%s\n' "$index"
