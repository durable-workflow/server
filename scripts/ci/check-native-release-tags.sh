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
# ALIAS_STATE lists "ref release-tag" for aliases that exist (an absent ref
# answers not found; "unreadable" fails the lookup); EXACT_DIGEST overrides
# what the exact tags name.
cat > "$scratch/docker" <<'FAKE'
#!/usr/bin/env bash
printf '%s\n' "$*" >> "$CALL_LOG"
ref=${!#}
if [[ "$*" == *"imagetools inspect --format {{json .Image}}"* ]]; then
  label=$(awk -v r="$ref" '$1 == r { print $2 }' <<<"${ALIAS_STATE:-}")
  [[ -z "$label" ]] && { echo "ERROR: $ref: not found" >&2; exit 1; }
  [[ "$label" == unreadable ]] && { echo "ERROR: unauthorized" >&2; exit 1; }
  printf '{"linux/amd64":{"config":{"Labels":{"dev.durable-workflow.release.tag":"%s"}}}}\n' "$label"
elif [[ "$*" == *"imagetools inspect"* ]]; then
  if [[ "$ref" =~ :[0-9]+\.[0-9]+\.[0-9]+$ ]]; then printf '%s\n' "${EXACT_DIGEST:-$INDEX_DIGEST}"; else printf '%s\n' "$INDEX_DIGEST"; fi
fi
FAKE
chmod +x "$scratch/docker"
index=sha256:$(printf '%064d' 0 | tr 0 a)
rolling() { # known tags, release tag [, alias state [, exact digest]] -> moved aliases
  : > "$scratch/calls"; : > "$scratch/out"
  env -i PATH="$PATH" DOCKER="$scratch/docker" CALL_LOG="$scratch/calls" GITHUB_OUTPUT="$scratch/out" \
    INDEX_DIGEST="$index" "${images[@]}" RELEASE_IMAGE_KNOWN_TAGS="$1" RELEASE_TAG="$2" \
    ALIAS_STATE="${3:-}" EXACT_DIGEST="${4:-}" \
    bash scripts/ci/native-release-rolling-tags.sh >/dev/null 2>&1 || { echo FAILED; return; }
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
want "latest held by PHP" "$(rolling $'2.11.8' native-3.0.0 $'ghcr.io/example/native:latest 2.11.8\nexample/native:latest 2.11.8')" "3.0 3 latest"
want "rerun after a partial write" "$(rolling $'2.11.8\nnative-3.0.0' native-3.0.0 $'ghcr.io/example/native:3.0 native-3.0.0\nghcr.io/example/native:3 native-3.0.0\nghcr.io/example/native:latest native-3.0.0\nexample/native:latest 2.11.8')" "3.0 3 latest"
# Two releases interleaved: 3.0.1 read the Git tags before native-3.1.0
# existed, and 3.1.0 has since moved 3 and latest. The older job, finishing
# last, still moves only 3.0.
newer_state=$'ghcr.io/example/native:3 native-3.1.0\nexample/native:3 native-3.1.0\nghcr.io/example/native:latest native-3.1.0\nexample/native:latest native-3.1.0'
want "older release finishing last" "$(rolling $'native-3.0.0' native-3.0.1 "$newer_state")" "3.0"
grep -q -- '--tag ghcr.io/example/native:3 \|--tag ghcr.io/example/native:latest\|--tag example/native:3 \|--tag example/native:latest' "$scratch/calls" \
  && fail "an older release moved 3 or latest backward."
# Exact tags that do not name the attested index, or an alias whose release
# cannot be read, stop the run before any alias write.
want "exact tag mismatch" "$(rolling $'2.11.8' native-3.0.0 '' "sha256:$(printf '%064d' 0 | tr 0 b)")" "FAILED"
grep -q 'imagetools create' "$scratch/calls" && fail "aliases were written after an exact-tag mismatch."
want "unreadable alias" "$(rolling $'2.11.8' native-3.0.0 'example/native:latest unreadable')" "FAILED"
grep -q 'imagetools create' "$scratch/calls" && fail "aliases were written though an alias could not be read."
expect "INDEX_DIGEST must be the verified index digest" RELEASE_TAG=native-3.0.0 "${images[@]}" RELEASE_IMAGE_KNOWN_TAGS=x bash scripts/ci/native-release-rolling-tags.sh

# Release identity: only a strict native- label admits a release image, and
# only when its server reports exactly that version.
identity=scripts/ci/native-release-identity.sh
test "$(bash "$identity" expect native-3.0.0)" = 3.0.0 || fail "native-3.0.0 must admit 3.0.0."
test "$(bash "$identity" expect native-3.0.0-rc.3)" = 3.0.0-rc.3 || fail "native-3.0.0-rc.3 must admit 3.0.0-rc.3."
test -z "$(bash "$identity" expect '')" || fail "an unlabelled image is a development image."
test -z "$(bash "$identity" expect '<no value>')" || fail "an unlabelled image is a development image."
for label in 3.0.0 native-3.0 native-3.0.0-beta native-3.0.0x 'native-3.0.0 '; do
  if bash "$identity" expect "$label" >/dev/null 2>&1; then fail "label '$label' must be refused."; fi
done
bash "$identity" check native-3.0.0 3.0.0 || fail "a matching release version must pass."
bash "$identity" check '' 0.1.0 || fail "a development image reports its crate version."
for reported in 3.0.1 3.0.0-rc.3 '' 0.1.0; do
  if bash "$identity" check native-3.0.0 "$reported" >/dev/null 2>&1; then
    fail "a native-3.0.0 label must not admit a binary reporting '$reported'."
  fi
done

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
# A failed tag lookup stops the PHP promoter and resolver before any write:
# it never reads as "no native release".
printf '#!/bin/sh\nexit 73\n' > "$scratch/git"; chmod +x "$scratch/git"
for script in promote-release-image-rolling-tags.sh resolve-release-image-rolling-tags.sh; do
  : > "$scratch/calls"
  if env -i PATH="$scratch:$PATH" DOCKER="$scratch/docker" CALL_LOG="$scratch/calls" RELEASE_TAG=2.11.9 \
    VERIFIED_IMAGE_DIGEST="$index" RELEASE_IMAGE_TAG_SOURCE_URL=https://example.invalid/server.git \
    sh "scripts/ci/$script" >/dev/null 2>&1; then
    fail "$script must fail when the tag lookup fails."
  fi
  test ! -s "$scratch/calls" || fail "$script wrote to a registry after a failed tag lookup."
done
rm -f "$scratch/git"

# One inventory for the PHP stage, taken once under the shared lock: the
# first lookup answers 2.11.9 and native-3.0.0, every later one fails (73).
cat > "$scratch/git" <<'FAKE'
#!/usr/bin/env bash
count=$(( $(cat "$GIT_COUNT" 2>/dev/null || echo 0) + 1 )); echo "$count" > "$GIT_COUNT"
(( count == 1 )) || { echo "fatal: lookup failed" >&2; exit 73; }
printf '%s\trefs/tags/%s\n' 1111 2.11.8 2222 2.11.9 3333 native-3.0.0
FAKE
chmod +x "$scratch/git"
php_stage() { # $1: how the promoter gets tags (inventory|lookup) -> promoter exit status
  : > "$scratch/calls"; : > "$scratch/out"; echo 0 > "$scratch/git-count"
  local base=(env -i PATH="$scratch:$PATH" DOCKER="$scratch/docker" CALL_LOG="$scratch/calls" GIT_COUNT="$scratch/git-count"
    RELEASE_TAG=2.11.9 VERIFIED_IMAGE_DIGEST="$index" RELEASE_IMAGE_TAG_SOURCE_URL=https://example.invalid/server.git)
  "${base[@]}" GITHUB_OUTPUT="$scratch/out" sh scripts/ci/resolve-release-image-rolling-tags.sh >/dev/null 2>&1 \
    || fail "the resolver's single lookup must succeed."
  inventory=$(sed -n '/^release_inventory<</,/^__release_image_release_inventory__$/p' "$scratch/out" | sed '1d;$d')
  grep -qx native-3.0.0 <<<"$inventory" || fail "the resolver must hand its inventory to the promoter."
  local status=0
  if [[ "$1" == inventory ]]; then
    "${base[@]}" RELEASE_IMAGE_KNOWN_TAGS="$inventory" sh scripts/ci/promote-release-image-rolling-tags.sh >/dev/null 2>&1 || status=$?
  else
    "${base[@]}" sh scripts/ci/promote-release-image-rolling-tags.sh >/dev/null 2>&1 || status=$?
  fi
  printf '%s\n' "$status"
}
test "$(php_stage lookup)" != 0 || fail "a promoter whose second lookup fails must exit nonzero."
test ! -s "$scratch/calls" || fail "a promoter whose second lookup fails wrote to a registry."
test "$(php_stage inventory)" = 0 || fail "the promoter must succeed from the resolver's inventory."
test "$(cat "$scratch/git-count")" = 1 || fail "the PHP stage must read the tags exactly once."
grep -q -- '--tag durableworkflow/server:2.11 --tag durableworkflow/server:2 ' "$scratch/calls" \
  || fail "the PHP release must still move 2 and 2.11."
grep -q ':latest' "$scratch/calls" && fail "the PHP release moved latest past native-3.0.0."
# Without any inventory the promoter writes nothing.
: > "$scratch/calls"
if env -i PATH="$PATH" DOCKER="$scratch/docker" CALL_LOG="$scratch/calls" RELEASE_TAG=2.11.9 \
  VERIFIED_IMAGE_DIGEST="$index" sh scripts/ci/promote-release-image-rolling-tags.sh >/dev/null 2>&1; then
  fail "the promoter must refuse without a tag inventory."
fi
test ! -s "$scratch/calls" || fail "the promoter wrote without a tag inventory."
rm -f "$scratch/git" "$scratch/git-count"
# Both workflows hold the one rolling-alias lock, and the PHP promoter takes
# the resolver's inventory rather than reading the tags again.
for workflow in .github/workflows/release.yml .github/workflows/native-release.yml; do
  test "$(grep -c '^      group: durable-workflow-server-rolling-aliases$' "$workflow")" = 1 \
    || fail "$workflow must hold the durable-workflow-server-rolling-aliases lock in one job."
done
grep -q 'RELEASE_IMAGE_KNOWN_TAGS: ${{ steps.rolling.outputs.release_inventory }}' .github/workflows/release.yml \
  || fail "the PHP promoter must use the resolver's inventory."
awk '/- name: Promote rolling image aliases/,/run: scripts\/ci\/promote-release-image-rolling-tags.sh/' .github/workflows/release.yml \
  | grep -q RELEASE_IMAGE_TAG_SOURCE_URL && fail "the PHP promoter must not read the tags again."
printf 'Native release tags: %d release.yml pattern(s) checked; verifier, publisher and rolling tags behave as intended.\n' "${#patterns[@]}"
