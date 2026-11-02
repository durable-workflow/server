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
# GitHub Releases follow the latest alias: the release step, holding the
# rolling-alias lock, marks a final Latest only when the live latest alias
# names its index in both registries. LATEST_STATE lists "image digest" for
# latest aliases that exist; "unreadable" fails the lookup.
cat > "$scratch/gh" <<'FAKE'
#!/usr/bin/env bash
if [[ "$1 $2" == "release view" ]]; then [[ -n "${EXISTING:-}" ]]; exit; fi
printf '%s\n' "$*" >> "$GH_LOG"
FAKE
cat > "$scratch/latest-docker" <<'FAKE'
#!/usr/bin/env bash
ref=${!#}
digest=$(awk -v r="${ref%:latest}" '$1 == r { print $2 }' <<<"${LATEST_STATE:-}")
[[ -z "$digest" ]] && { echo "ERROR: $ref: not found" >&2; exit 1; }
[[ "$digest" == unreadable ]] && { echo "ERROR: unauthorized" >&2; exit 1; }
printf '%s\n' "$digest"
FAKE
chmod +x "$scratch/gh" "$scratch/latest-docker"
newer_index=sha256:$(printf '%064d' 0 | tr 0 b)
gh_release() { # release tag, latest state [, existing] -> the Latest choice
  : > "$scratch/gh_calls"
  env -i PATH="$PATH" GH="$scratch/gh" GH_LOG="$scratch/gh_calls" DOCKER="$scratch/latest-docker" "${images[@]}" \
    RELEASE_TAG="$1" VERSION="${1#native-}" RELEASE_COMMIT=$(printf '%040d' 0) INDEX_DIGEST="$index" \
    RUN_URL=https://example.invalid/run LATEST_STATE="$2" EXISTING="${3:-}" \
    bash scripts/ci/native-release-github-release.sh >/dev/null 2>&1 || { echo FAILED; return; }
  grep -q -- '^release create ' "$scratch/gh_calls" || { echo none; return; }
  grep -q -- '--verify-tag --title ' "$scratch/gh_calls" || { echo FAILED; return; }
  tr '\n' ' ' < "$scratch/gh_calls" | grep -oE -- '--prerelease|--latest(=false)?( |$)' | tr -d ' ' | paste -sd' ' -
}
want_release() { test "$2" = "$3" || fail "$1: expected GitHub Release '$3', got '$2'."; }
ours=$'ghcr.io/example/native '"$index"$'\nexample/native '"$index"
theirs=$'ghcr.io/example/native '"$newer_index"$'\nexample/native '"$newer_index"
# Forward: 3.1.0 moved latest, so it is Latest.
forward=$(rolling $'2.11.8\nnative-3.0.1\n3.0.2' 3.1.0 $'ghcr.io/example/native:latest 3.0.2\nexample/native:latest 3.0.2')
want "forward 3.1.0" "$forward" "3.1 3 latest"
want_release "forward 3.1.0" "$(gh_release 3.1.0 "$ours")" "--latest"
# Backport: 3.0.2 behind 3.1.0 leaves latest at 3.1.0, and GitHub Latest too.
backport=$(rolling $'2.11.8\nnative-3.0.1\n3.1.0' 3.0.2 $'ghcr.io/example/native:3 3.1.0\nexample/native:3 3.1.0\nghcr.io/example/native:latest 3.1.0\nexample/native:latest 3.1.0')
want "3.0.2 backport behind 3.1.0" "$backport" "3.0"
want_release "3.0.2 backport behind 3.1.0" "$(gh_release 3.0.2 "$theirs")" "--latest=false"
# Interleaved: 3.0.2 moved latest, then 3.1.0 moved it on before 3.0.2's
# release step ran. The live alias names 3.1.0, so 3.0.2 is not Latest.
interleaved=$(rolling $'2.11.8\nnative-3.0.1' 3.0.2)
want "3.0.2 before 3.1.0" "$interleaved" "3.0 3 latest"
want_release "3.0.2 released after 3.1.0 took latest" "$(gh_release 3.0.2 "$theirs")" "--latest=false"
# Both registries must name the index; one behind is not enough.
want_release "only GHCR names the index" "$(gh_release 3.0.2 $'ghcr.io/example/native '"$index"$'\nexample/native '"$newer_index")" "--latest=false"
want_release "no latest alias yet" "$(gh_release 3.0.2 "")" "--latest=false"
want_release "unreadable latest alias" "$(gh_release 3.0.2 $'ghcr.io/example/native unreadable\nexample/native '"$index")" "FAILED"
want_release "candidate" "$(gh_release 3.1.0-rc.1 "$ours")" "--prerelease --latest=false"
want_release "native- final that owns latest" "$(gh_release native-3.0.2 "$ours")" "--latest"
want_release "rerun of an existing release" "$(gh_release 3.1.0 "$ours" yes)" "none"

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
# The native release step holds it too, so GitHub Latest is decided with no
# alias moving underneath it.
test "$(grep -c '^      group: durable-workflow-server-rolling-aliases$' .github/workflows/release.yml)" = 1 \
  || fail "release.yml must hold the durable-workflow-server-rolling-aliases lock in one job."
locked_jobs=$(awk '
  /^  [a-z0-9-]+:$/ { job = substr($1, 1, length($1) - 1) }
  /^      group: durable-workflow-server-rolling-aliases$/ { print job }
' .github/workflows/native-release.yml | paste -sd' ' -)
test "$locked_jobs" = "aliases release" \
  || fail "native-release.yml must hold the rolling-alias lock in aliases and release, not '$locked_jobs'."
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
aliases=$tag_gate && needs.plan.outputs.dockerhub == 'true'
release=$tag_gate"
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
test "$(grep -c '      contents: write' .github/workflows/native-release.yml)" = 1 \
  || fail "only the native Release job may hold contents: write."
printf 'Native release tags: %d release.yml pattern(s) checked; verifier, publisher and rolling tags behave as intended.\n' "${#patterns[@]}"
