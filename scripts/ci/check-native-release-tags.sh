#!/usr/bin/env bash
# Native release-candidate tags are their own Git tags, native-<version>
# (Server #325). Check that:
# - the PHP Release workflow's push-tag filter matches no native- tag, so a
#   native tag starts no PHP release, and still matches plain versions;
# - the tag verifier accepts native- only when asked for it;
# - the native publisher accepts only native- tags, and lets only a release
#   candidate (-rc.N) skip Docker Hub.
# Each script is stopped at its input checks, before any API or registry call.
set -euo pipefail
fail() { printf 'Native release tag check failed: %s\n' "$1" >&2; exit 1; }

# The tag patterns under release.yml's push trigger.
mapfile -t patterns < <(awk '
  /^  push:/ { push = 1; next }
  push && /^  [a-z_]+:/ { push = 0 }
  push && /^    tags:/ { tags = 1; next }
  tags && /^      - / { sub(/^      - /, ""); gsub(/\x27|"/, ""); print; next }
  tags { tags = 0 }
' .github/workflows/release.yml)
test "${#patterns[@]}" -ge 1 || fail "release.yml has no push tag patterns."

# A GitHub filter pattern as an anchored extended regular expression:
# [..] and + keep their meaning, * matches anything but /, the rest is literal.
matches() {
  local pattern=$1 tag=$2 regex
  regex=$(printf '%s' "$pattern" | sed -e 's/[.]/\\./g' -e 's#[*]#[^/]*#g')
  [[ $tag =~ ^${regex}$ ]]
}
for pattern in "${patterns[@]}"; do
  for tag in native-2.12.0-rc.1 native-2.11.4 native-3.0.0; do
    if matches "$pattern" "$tag"; then fail "release.yml tag pattern $pattern matches $tag."; fi
  done
done
for tag in 2.11.4 2.12.0-rc.1; do
  matched=0
  for pattern in "${patterns[@]}"; do
    if matches "$pattern" "$tag"; then matched=1; fi
  done
  test "$matched" = 1 || fail "no release.yml tag pattern matches the plain version $tag."
done

expect() {
  local message=$1; shift
  local output
  if output=$(env -i PATH="$PATH" "$@" 2>&1); then fail "expected a refusal containing: $message"; fi
  grep -qF -- "$message" <<<"$output" || fail "expected '$message', got: $output"
}
verify=scripts/ci/verify-release-tag-source.sh
# An invalid commit stops the verifier right after the tag format is accepted.
expect "RELEASE_COMMIT must be" RELEASE_TAG=2.12.0-rc.1 RELEASE_COMMIT=x bash "$verify"
expect "RELEASE_COMMIT must be" RELEASE_TAG=native-2.12.0-rc.1 RELEASE_TAG_PREFIX=native- RELEASE_COMMIT=x bash "$verify"
expect "must be an exact native-release version" RELEASE_TAG=2.12.0-rc.1 RELEASE_TAG_PREFIX=native- RELEASE_COMMIT=x bash "$verify"
expect "must be an exact release version" RELEASE_TAG=native-2.12.0-rc.1 RELEASE_COMMIT=x bash "$verify"
expect "may only be empty or native-" RELEASE_TAG=x-2.12.0 RELEASE_TAG_PREFIX=x- RELEASE_COMMIT=x bash "$verify"

publish=scripts/ci/native-release-publish.sh
images=(GHCR_IMAGE=ghcr.io/example/native DOCKERHUB_IMAGE=example/native)
expect "must be native-<exact release version>" RELEASE_TAG=2.12.0-rc.1 "${images[@]}" PUBLISH_DOCKERHUB=true bash "$publish"
# No digests stops the publisher right after the tag and registries are accepted.
expect "exact smoked digest" RELEASE_TAG=native-2.12.0-rc.1 "${images[@]}" PUBLISH_DOCKERHUB=true bash "$publish"
expect "exact smoked digest" RELEASE_TAG=native-2.12.0-rc.1 "${images[@]}" PUBLISH_DOCKERHUB=false bash "$publish"
expect "exact smoked digest" RELEASE_TAG=native-3.0.0 "${images[@]}" PUBLISH_DOCKERHUB=true bash "$publish"
# Only a release candidate may skip Docker Hub, and the choice must be explicit.
expect "only a release candidate (-rc.N) may skip Docker Hub" RELEASE_TAG=native-3.0.0 "${images[@]}" PUBLISH_DOCKERHUB=false bash "$publish"
expect "PUBLISH_DOCKERHUB must be true or false" RELEASE_TAG=native-2.12.0-rc.1 "${images[@]}" bash "$publish"
# Rolling tags: a final native release moves X.Y, X and latest forward only;
# a candidate moves none; a PHP release leaves latest to a newer native one.
# A fake docker records each call and answers inspect with the index digest.
scratch=$(mktemp -d); trap 'rm -rf "$scratch"' EXIT
cat > "$scratch/docker" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CALL_LOG"
if [[ "$*" == *"imagetools inspect"* ]]; then printf '%s\n' "$INDEX_DIGEST"; fi
FAKE
chmod +x "$scratch/docker"
index=sha256:$(printf '%064d' 0 | tr 0 a)
rolling() { # known tags, release tag -> moved aliases (as written to GITHUB_OUTPUT)
  : > "$scratch/calls"; : > "$scratch/out"
  env -i PATH="$PATH" DOCKER="$scratch/docker" CALL_LOG="$scratch/calls" GITHUB_OUTPUT="$scratch/out" \
    INDEX_DIGEST="$index" "${images[@]}" RELEASE_IMAGE_KNOWN_TAGS="$1" RELEASE_TAG="$2" \
    bash scripts/ci/native-release-rolling-tags.sh >/dev/null
  sed -n 's/^rolling_tags=//p' "$scratch/out"
}
want() { test "$2" = "$3" || fail "$1: expected rolling tags '$3', got '$2'."; }
want "first final" "$(rolling $'2.11.8\nnative-3.0.0-rc.2' native-3.0.0)" "3.0 3 latest"
want "candidate" "$(rolling $'2.11.8' native-3.0.1-rc.1)" ""
want "backport below a newer minor" "$(rolling $'native-3.0.0\nnative-3.1.0' native-3.0.1)" "3.0"
want "older patch" "$(rolling $'native-3.0.0\nnative-3.0.2' native-3.0.1)" ""
want "newer minor" "$(rolling $'native-3.0.2\n2.11.8' native-3.1.0)" "3.1 3 latest"
grep -q "ghcr.io/example/native@$index" "$scratch/calls" && grep -q "example/native@$index" "$scratch/calls" \
  || fail "rolling tags must be created from the verified index in both registries."
expect "INDEX_DIGEST must be the verified index digest" RELEASE_TAG=native-3.0.0 "${images[@]}" RELEASE_IMAGE_KNOWN_TAGS=x bash scripts/ci/native-release-rolling-tags.sh

# PHP releases: latest stays with a native final release above them, and
# moves as before when only a native candidate exists.
php_latest() { # known tags -> 'latest' if the PHP promoter writes latest
  : > "$scratch/calls"
  env -i PATH="$PATH" DOCKER="$scratch/docker" CALL_LOG="$scratch/calls" RELEASE_TAG=2.11.9 \
    VERIFIED_IMAGE_DIGEST="$index" RELEASE_IMAGE_KNOWN_TAGS="$1" \
    sh scripts/ci/promote-release-image-rolling-tags.sh >/dev/null
  grep -q ':latest' "$scratch/calls" && printf latest || true
}
want "PHP after a native final" "$(php_latest $'2.11.8\nnative-3.0.0')" ""
want "PHP after a native candidate only" "$(php_latest $'2.11.8\nnative-3.0.0-rc.2')" "latest"
printf 'Native release tags: %d release.yml pattern(s) checked; verifier, publisher and rolling tags behave as intended.\n' "${#patterns[@]}"
