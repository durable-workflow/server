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
  for tag in native-2.12.0-rc.1 native-2.11.4 native-3.0.0 3.0.2 3.1.0-rc.1 10.0.0; do
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
expect "must be a native release version" RELEASE_TAG=2.12.0-rc.1 "${images[@]}" PUBLISH_DOCKERHUB=true bash "$publish"
expect "must be a native release version" RELEASE_TAG=2.11.9 "${images[@]}" PUBLISH_DOCKERHUB=true bash "$publish"
# A plain tag from major 3 passes the tag check and stops at the digests.
expect "exact smoked digest" RELEASE_TAG=3.0.2 "${images[@]}" PUBLISH_DOCKERHUB=true bash "$publish"
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
# Plain tags from major 3 are native; plain tags below 3 stay PHP releases.
want "first plain final" "$(rolling $'2.11.8\nnative-3.0.0\nnative-3.0.1' 3.0.2)" "3.0 3 latest"
want "plain behind a plain" "$(rolling $'2.11.8\nnative-3.0.1\n3.0.3' 3.0.2)" ""
want "plain candidate" "$(rolling $'2.11.8\nnative-3.0.1' 3.1.0-rc.1)" ""
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
# The GitHub Release, from protected main (native-github-release.yml): it
# verifies the tag, the run, both exact tags and the provenance, then marks
# a final Latest only when the live latest alias names its index in both
# registries. Fakes answer each read from the variables below.
commit=0123456789abcdef0123456789abcdef01234567
cat > "$scratch/gh" <<'FAKE'
#!/usr/bin/env bash
case "$1 $2" in
  "release view") [[ -n "${EXISTING:-}" ]] || exit 1; printf '%s\n' "$EXISTING"; exit 0 ;;
  "release create") printf '%s\n' "$*" >> "$GH_LOG"; exit 0 ;;
  "attestation verify")
    printf '%s\n' "$*" > "$GH_ATTEST_LOG"
    [[ "${ATTEST:-ok}" == ok ]] || exit 1
    printf '%s\n' "${ATTEST_URI:-https://github.com/example/server/actions/runs/${RUN_ID}/attempts/1}"; exit 0 ;;
esac
path=$2
case "$path" in
  repos/example/server/git/ref/tags/*) printf '%s\n' "${TAG_OBJECT:-commit $COMMIT}" ;;
  repos/example/server/git/tags/*) printf 'commit %s\n' "$COMMIT" ;;
  repos/example/server/compare/*) printf '%s\n' "${COMPARE:-ahead}" ;;
  repos/example/server/actions/runs/*) printf '%s\n' "${RUN_LINE:-.github/workflows/native-release.yml workflow_dispatch completed success $RELEASE_TAG $COMMIT}" ;;
  *) exit 1 ;;
esac
FAKE
cat > "$scratch/release-docker" <<'FAKE'
#!/usr/bin/env bash
ref=${!#}
if [[ "$ref" == *:latest ]]; then
  digest=$(awk -v r="${ref%:latest}" '$1 == r { print $2 }' <<<"${LATEST_STATE:-}")
  [[ -z "$digest" ]] && { echo "ERROR: $ref: not found" >&2; exit 1; }
  [[ "$digest" == unreadable ]] && { echo "ERROR: unauthorized" >&2; exit 1; }
  printf '%s\n' "$digest"
elif [[ "$ref" == example/native:* ]]; then
  printf '%s\n' "${HUB_EXACT:-$INDEX}"
else
  printf '%s\n' "${GHCR_EXACT:-$INDEX}"
fi
FAKE
chmod +x "$scratch/gh" "$scratch/release-docker"
newer_index=sha256:$(printf '%064d' 0 | tr 0 b)
gh_release() { # release tag, latest state [, VAR=value ...] -> the Latest choice
  local tag=$1 state=$2; shift 2
  : > "$scratch/gh_calls"; : > "$scratch/gh_attest"
  env -i PATH="$PATH" GH="$scratch/gh" GH_LOG="$scratch/gh_calls" GH_ATTEST_LOG="$scratch/gh_attest" \
    DOCKER="$scratch/release-docker" "${images[@]}" GITHUB_REPOSITORY=example/server \
    RELEASE_TAG="$tag" RELEASE_RUN_ID=41 RUN_ID=41 COMMIT="$commit" INDEX="$index" LATEST_STATE="$state" "$@" \
    bash scripts/ci/native-release-github-release.sh >/dev/null 2>&1 || { echo FAILED; return; }
  grep -q -- '^release create ' "$scratch/gh_calls" || { echo none; return; }
  grep -q -- '--verify-tag --title ' "$scratch/gh_calls" || { echo FAILED; return; }
  tr '\n' ' ' < "$scratch/gh_calls" | grep -oE -- '--prerelease|--latest(=false)?( |$)' | tr -d ' ' | paste -sd' ' -
}
want_release() { test "$2" = "$3" || fail "$1: expected GitHub Release '$3', got '$2'."; }
ours=$'ghcr.io/example/native '"$index"$'\nexample/native '"$index"
theirs=$'ghcr.io/example/native '"$newer_index"$'\nexample/native '"$newer_index"
# Latest follows the live alias.
forward=$(rolling $'2.11.8\nnative-3.0.1\n3.0.2' 3.1.0 $'ghcr.io/example/native:latest 3.0.2\nexample/native:latest 3.0.2')
want "forward 3.1.0" "$forward" "3.1 3 latest"
want_release "forward 3.1.0" "$(gh_release 3.1.0 "$ours")" "--latest"
grep -q -- "--source-ref refs/tags/3.1.0 --source-digest $commit" "$scratch/gh_attest" \
  || fail "the provenance must be checked against the tag ref and its commit."
grep -q -- "--signer-workflow github.com/example/server/.github/workflows/native-release.yml" "$scratch/gh_attest" \
  || fail "the provenance must name native-release.yml as its signer."
backport=$(rolling $'2.11.8\nnative-3.0.1\n3.1.0' 3.0.2 $'ghcr.io/example/native:3 3.1.0\nexample/native:3 3.1.0\nghcr.io/example/native:latest 3.1.0\nexample/native:latest 3.1.0')
want "3.0.2 backport behind 3.1.0" "$backport" "3.0"
want_release "3.0.2 backport behind 3.1.0" "$(gh_release 3.0.2 "$theirs")" "--latest=false"
want_release "3.0.2 released after 3.1.0 took latest" "$(gh_release 3.0.2 "$theirs")" "--latest=false"
want_release "only GHCR names the index" "$(gh_release 3.0.2 $'ghcr.io/example/native '"$index"$'\nexample/native '"$newer_index")" "--latest=false"
want_release "no latest alias yet" "$(gh_release 3.0.2 "")" "--latest=false"
want_release "candidate" "$(gh_release 3.1.0-rc.1 "$ours")" "--prerelease --latest=false"
want_release "native- final that owns latest" "$(gh_release native-3.0.2 "$ours")" "--latest"
want_release "annotated tag" "$(gh_release 3.0.2 "$ours" TAG_OBJECT="tag 89abcdef0123456789abcdef0123456789abcdef")" "--latest"
# An existing Release is checked after every binding: one recording exactly
# this verified release is left unchanged; any other is refused unedited.
existing() { # tag, title, prerelease, index, commit, run
  jq -cn --arg tag "$1" --arg title "$2" --argjson pre "$3" --arg body "index $4 commit $5 run https://github.com/example/server/actions/runs/$6" \
    '{tagName:$tag,name:$title,isPrerelease:$pre,body:$body}'
}
want_release "rerun of the same release" "$(gh_release 3.1.0 "$ours" EXISTING="$(existing 3.1.0 3.1.0 false "$index" "$commit" 41)")" "none"
want_release "rerun of the same candidate" "$(gh_release 3.1.0-rc.1 "$ours" EXISTING="$(existing 3.1.0-rc.1 3.1.0-rc.1 true "$index" "$commit" 41)")" "none"
want_release "existing release of another run" "$(gh_release 3.1.0 "$ours" EXISTING="$(existing 3.1.0 3.1.0 false "$index" "$commit" 42)")" "FAILED"
want_release "existing release of another index" "$(gh_release 3.1.0 "$ours" EXISTING="$(existing 3.1.0 3.1.0 false "$newer_index" "$commit" 41)")" "FAILED"
want_release "existing release of another commit" "$(gh_release 3.1.0 "$ours" EXISTING="$(existing 3.1.0 3.1.0 false "$index" fedcba9876543210fedcba9876543210fedcba98 41)")" "FAILED"
want_release "existing release with another title" "$(gh_release 3.1.0 "$ours" EXISTING="$(existing 3.1.0 native-3.1.0 false "$index" "$commit" 41)")" "FAILED"
want_release "existing final marked prerelease" "$(gh_release 3.1.0 "$ours" EXISTING="$(existing 3.1.0 3.1.0 true "$index" "$commit" 41)")" "FAILED"
# The bindings come first: an existing Release does not excuse a failed one.
want_release "existing release, unverifiable provenance" "$(gh_release 3.1.0 "$ours" ATTEST=fail EXISTING="$(existing 3.1.0 3.1.0 false "$index" "$commit" 41)")" "FAILED"
want_release "existing release, commit off main" "$(gh_release 3.1.0 "$ours" COMPARE=diverged EXISTING="$(existing 3.1.0 3.1.0 false "$index" "$commit" 41)")" "FAILED"
# Every binding fails closed, before any write.
want_release "unreadable latest alias" "$(gh_release 3.0.2 $'ghcr.io/example/native unreadable\nexample/native '"$index")" "FAILED"
want_release "a PHP tag" "$(gh_release 2.11.9 "$ours")" "FAILED"
want_release "no run id" "$(gh_release 3.0.2 "$ours" RELEASE_RUN_ID=)" "FAILED"
want_release "tag commit not on main" "$(gh_release 3.0.2 "$ours" COMPARE=diverged)" "FAILED"
want_release "tag names a tree" "$(gh_release 3.0.2 "$ours" TAG_OBJECT="tree $commit")" "FAILED"
want_release "run failed" "$(gh_release 3.0.2 "$ours" RUN_LINE=".github/workflows/native-release.yml workflow_dispatch completed failure 3.0.2 $commit")" "FAILED"
want_release "run of another workflow" "$(gh_release 3.0.2 "$ours" RUN_LINE=".github/workflows/release.yml workflow_dispatch completed success 3.0.2 $commit")" "FAILED"
want_release "run from another ref" "$(gh_release 3.0.2 "$ours" RUN_LINE=".github/workflows/native-release.yml workflow_dispatch completed success main $commit")" "FAILED"
want_release "run at another commit" "$(gh_release 3.0.2 "$ours" RUN_LINE=".github/workflows/native-release.yml workflow_dispatch completed success 3.0.2 fedcba9876543210fedcba9876543210fedcba98")" "FAILED"
want_release "registries name different indexes" "$(gh_release 3.0.2 "$ours" HUB_EXACT="$newer_index")" "FAILED"
want_release "provenance does not verify" "$(gh_release 3.0.2 "$ours" ATTEST=fail)" "FAILED"
want_release "provenance from another run" "$(gh_release 3.0.2 "$ours" ATTEST_URI=https://github.com/example/server/actions/runs/42/attempts/1)" "FAILED"
want_release "provenance from a run id prefix" "$(gh_release 3.0.2 "$ours" ATTEST_URI=https://github.com/example/server/actions/runs/411/attempts/1)" "FAILED"

# Release identity: only a strict native- label admits a release image, and
# only when its server reports exactly that version.
identity=scripts/ci/native-release-identity.sh
test "$(bash "$identity" expect native-3.0.0)" = 3.0.0 || fail "native-3.0.0 must admit 3.0.0."
test "$(bash "$identity" expect 3.0.2)" = 3.0.2 || fail "3.0.2 must admit 3.0.2."
test "$(bash "$identity" expect 3.1.0-rc.1)" = 3.1.0-rc.1 || fail "3.1.0-rc.1 must admit 3.1.0-rc.1."
if bash "$identity" expect 2.11.9 >/dev/null 2>&1; then fail "a plain 2.x tag is a PHP release, not native."; fi
test "$(bash "$identity" expect native-3.0.0-rc.3)" = 3.0.0-rc.3 || fail "native-3.0.0-rc.3 must admit 3.0.0-rc.3."
test -z "$(bash "$identity" expect '')" || fail "an unlabelled image is a development image."
test -z "$(bash "$identity" expect '<no value>')" || fail "an unlabelled image is a development image."
for label in 2.11.9 3.0 3.0.0-beta native-3.0 native-3.0.0-beta native-3.0.0x 'native-3.0.0 '; do
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
# The GitHub Release job holds it too, so Latest is decided with no alias
# moving underneath it.
for workflow in .github/workflows/release.yml .github/workflows/native-release.yml .github/workflows/native-github-release.yml; do
  test "$(grep -c '^      group: durable-workflow-server-rolling-aliases$' "$workflow")" = 1 \
    || fail "$workflow must hold the durable-workflow-server-rolling-aliases lock in one job."
done
# Every native-release.yml job that can publish runs in the tag-restricted
# Environment, and none may write releases.
unprotected=$(awk '
  function flush() { if (job != "" && writes && env != "dockerhub-native") print job }
  /^jobs:/ { jobs = 1; next }
  jobs && /^  [a-z0-9-]+:$/ { flush(); job = substr($1, 1, length($1) - 1); writes = 0; env = ""; next }
  jobs && /^    environment: / { env = $2 }
  jobs && /^      [a-z-]+: write$/ { writes = 1 }
  END { flush() }
' .github/workflows/native-release.yml | paste -sd' ' -)
test -z "$unprotected" || fail "native-release.yml jobs with a write scope outside dockerhub-native: $unprotected."
# The GitHub Release workflow: one job, from protected main only, in its own
# main-only Environment, the only contents: write in either workflow.
github_release=.github/workflows/native-github-release.yml
test "$(grep -c '^  [a-z0-9-]*:$' <(sed -n '/^jobs:/,$p' "$github_release"))" = 1 || fail "$github_release must have one job."
grep -q "^    if: github.ref == 'refs/heads/main'$" "$github_release" || fail "$github_release must run only from main."
grep -q '^    environment: github-release$' "$github_release" || fail "$github_release must run in the github-release Environment."
grep -q '^  workflow_dispatch:$' "$github_release" && test "$(sed -n '/^on:/,/^permissions:/p' "$github_release" | grep -c '^  [a-z_]*:$')" = 1 \
  || fail "$github_release must be triggered only by workflow_dispatch."
test "$(grep -c '      contents: write' "$github_release")" = 1 || fail "$github_release must hold contents: write once."
test "$(grep -c '^ *contents: write$' .github/workflows/native-release.yml)" = 0 || fail "native-release.yml must not hold contents: write."
grep -q 'RELEASE_IMAGE_KNOWN_TAGS: ${{ steps.rolling.outputs.release_inventory }}' .github/workflows/release.yml \
  || fail "the PHP promoter must use the resolver's inventory."
awk '/- name: Promote rolling image aliases/,/run: scripts\/ci\/promote-release-image-rolling-tags.sh/' .github/workflows/release.yml \
  | grep -q RELEASE_IMAGE_TAG_SOURCE_URL && fail "the PHP promoter must not read the tags again."
# The dispatch verifier: the release tag's own ref, at its commit, on main.
dispatch=scripts/ci/verify-native-release-dispatch.sh
dispatch_scratch=$(mktemp -d)
printf '#!/bin/sh\necho "${COMPARE_STATUS:-ahead}"\n' > "$dispatch_scratch/gh"
chmod +x "$dispatch_scratch/gh"
commit=0123456789abcdef0123456789abcdef01234567
other=fedcba9876543210fedcba9876543210fedcba98
ok_dispatch=(RELEASE_TAG=native-3.0.2 RELEASE_COMMIT=$commit DISPATCH_SHA=$commit GITHUB_REPOSITORY=example/server GH_CLI="$dispatch_scratch/gh")
expect "dispatch from refs/tags/native-3.0.2, not refs/heads/main" "${ok_dispatch[@]}" DISPATCH_REF=refs/heads/main bash "$dispatch"
expect "dispatch from refs/tags/native-3.0.2, not refs/tags/native-3.0.1" "${ok_dispatch[@]}" DISPATCH_REF=refs/tags/native-3.0.1 bash "$dispatch"
expect "is not RELEASE_COMMIT" RELEASE_TAG=native-3.0.2 RELEASE_COMMIT=$commit DISPATCH_SHA=$other DISPATCH_REF=refs/tags/native-3.0.2 GITHUB_REPOSITORY=example/server GH_CLI="$dispatch_scratch/gh" bash "$dispatch"
expect "must be X.Y.Z or X.Y.Z-rc.N from major 3" "${ok_dispatch[@]}" RELEASE_TAG=2.0.2 DISPATCH_REF=refs/tags/2.0.2 bash "$dispatch"
for status in behind diverged; do
  expect "is not on main (compare status: $status)" "${ok_dispatch[@]}" DISPATCH_REF=refs/tags/native-3.0.2 COMPARE_STATUS=$status bash "$dispatch"
done
plain_dispatch=(RELEASE_TAG=3.0.2 RELEASE_COMMIT=$commit DISPATCH_SHA=$commit DISPATCH_REF=refs/tags/3.0.2 GITHUB_REPOSITORY=example/server GH_CLI="$dispatch_scratch/gh")
env -i PATH="$PATH" "${plain_dispatch[@]}" bash "$dispatch" >/dev/null || fail "the dispatch verifier must accept a plain 3.x tag."
expect "must be X.Y.Z or X.Y.Z-rc.N from major 3" RELEASE_TAG=2.11.9 RELEASE_COMMIT=$commit DISPATCH_SHA=$commit DISPATCH_REF=refs/tags/2.11.9 GITHUB_REPOSITORY=example/server GH_CLI="$dispatch_scratch/gh" bash "$dispatch"
for status in ahead identical; do
  env -i PATH="$PATH" "${ok_dispatch[@]}" DISPATCH_REF=refs/tags/native-3.0.2 COMPARE_STATUS=$status bash "$dispatch" >/dev/null \
    || fail "the dispatch verifier must accept a release commit main contains ($status)."
done
rm -rf "$dispatch_scratch"
# Each job's own condition, exactly: plan has none, so a wrong ref fails in
# its verifier rather than skipping every job; every publishing job is gated
# on the release tag's ref.
job_conditions=$(awk '
  /^jobs:/ { jobs = 1; next }
  jobs && /^  [a-z0-9-]+:$/ { if (job != "") print job "=" cond; job = substr($1, 1, length($1) - 1); cond = "none"; next }
  jobs && /^    if: / { sub(/^    if: /, ""); cond = $0 }
  END { if (job != "") print job "=" cond }
' .github/workflows/native-release.yml)
tag_gate="github.ref == format('refs/tags/{0}', inputs.tag)"
expected_conditions="plan=none
build-amd64=$tag_gate
build-arm64=$tag_gate
publish=$tag_gate
aliases=$tag_gate && needs.plan.outputs.dockerhub == 'true'"
test "$job_conditions" = "$expected_conditions" \
  || fail "native-release.yml job conditions changed; expected:
$expected_conditions
got:
$job_conditions"
grep -q 'run: scripts/ci/verify-native-release-dispatch.sh' .github/workflows/native-release.yml \
  || fail "the plan job must run the dispatch verifier."
# The PHP Release workflow refuses a native tag, pushed or dispatched, and only
# the native Release job may write releases.
grep -q 'name: Refuse a native release tag' .github/workflows/release.yml \
  || fail "release.yml must refuse a 3.x or native- tag."
printf 'Native release tags: %d release.yml pattern(s) checked; verifier, publisher and rolling tags behave as intended.\n' "${#patterns[@]}"
