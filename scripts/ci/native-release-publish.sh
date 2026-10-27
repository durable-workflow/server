#!/usr/bin/env bash
# Give the smoked native platform digests (Server #325) one release tag in
# both registries, as one index, and verify it.
# Env: RELEASE_TAG, GHCR_IMAGE, DOCKERHUB_IMAGE, and DIGEST_AMD64 and
# DIGEST_ARM64 from the build jobs, which push by digest and fail unless that
# exact digest passed its smoke test. Writes digest= to $GITHUB_OUTPUT.
set -euo pipefail
fail() { printf 'Native release publication failed: %s\n' "$1" >&2; exit 1; }
[[ "${RELEASE_TAG:-}" =~ ^[0-9]+\.[0-9]+\.[0-9]+([-+][0-9A-Za-z][0-9A-Za-z.-]*)?$ ]] || fail "RELEASE_TAG must be an exact release version."
: "${GHCR_IMAGE:?}" "${DOCKERHUB_IMAGE:?}"

digests=("${DIGEST_AMD64:-}" "${DIGEST_ARM64:-}")
for digest in "${digests[@]}"; do
  [[ "$digest" =~ ^sha256:[0-9a-f]{64}$ ]] || fail "each platform needs an exact smoked digest."
done

# A release tag is immutable: refuse one that either registry already has.
# Only a registry's own "not found" counts as absent; any other failure to
# look (authentication, network) stops here rather than risk an overwrite.
for image in "$GHCR_IMAGE" "$DOCKERHUB_IMAGE"; do
  if lookup=$(docker buildx imagetools inspect "$image:$RELEASE_TAG" 2>&1); then
    fail "$image:$RELEASE_TAG already exists."
  fi
  grep -qiE 'not found|manifest unknown|name unknown' <<<"$lookup" \
    || fail "could not confirm that $image:$RELEASE_TAG is absent: $(head -c 300 <<<"$lookup")"
done

for image in "$GHCR_IMAGE" "$DOCKERHUB_IMAGE"; do
  docker buildx imagetools create --tag "$image:$RELEASE_TAG" \
    "$image@${digests[0]}" "$image@${digests[1]}"
done

# Both registries hold the same index, naming exactly the smoked digests.
index=""
for image in "$GHCR_IMAGE" "$DOCKERHUB_IMAGE"; do
  raw=$(docker buildx imagetools inspect --raw "$image:$RELEASE_TAG")
  digest=$(docker buildx imagetools inspect --format '{{.Manifest.Digest}}' "$image:$RELEASE_TAG")
  if test -z "$index"; then index=$digest; fi
  test "$digest" = "$index" || fail "$image:$RELEASE_TAG is $digest, not $index."
  jq -e --arg amd64 "${digests[0]}" --arg arm64 "${digests[1]}" '
    [.manifests[] | {digest, platform: "\(.platform.os)/\(.platform.architecture)"}] | sort_by(.platform)
    == [{digest: $amd64, platform: "linux/amd64"}, {digest: $arm64, platform: "linux/arm64"}]' <<<"$raw" >/dev/null \
    || fail "$image:$RELEASE_TAG does not name exactly the smoked platform digests."
done

printf 'digest=%s\n' "$index" >> "${GITHUB_OUTPUT:-/dev/null}"
{
  printf '### Native %s\n\n' "$RELEASE_TAG"
  printf -- '- index `%s`, in `%s` and `%s`\n' "$index" "$GHCR_IMAGE:$RELEASE_TAG" "$DOCKERHUB_IMAGE:$RELEASE_TAG"
  printf -- '- linux/amd64 `%s`, linux/arm64 `%s`, each smoke-tested on its own architecture\n' "${digests[0]}" "${digests[1]}"
} >> "${GITHUB_STEP_SUMMARY:-/dev/null}"
printf '%s\n' "$index"
