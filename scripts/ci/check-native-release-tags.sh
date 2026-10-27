#!/usr/bin/env bash
# Native release-candidate tags are their own Git tags, native-<version>
# (Server #325). Check that:
# - the PHP Release workflow's push-tag filter matches no native- tag, so a
#   native tag starts no PHP release, and still matches plain versions;
# - the tag verifier accepts native- only when asked for it;
# - the native publisher accepts only native- tags.
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
expect "must be native-<exact release version>" RELEASE_TAG=2.12.0-rc.1 "${images[@]}" bash "$publish"
# No digests stops the publisher right after the native tag is accepted.
expect "exact smoked digest" RELEASE_TAG=native-2.12.0-rc.1 "${images[@]}" bash "$publish"
printf 'Native release tags: %d release.yml pattern(s) checked; verifier and publisher accept native- only as intended.\n' "${#patterns[@]}"
