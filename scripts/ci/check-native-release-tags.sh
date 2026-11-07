#!/usr/bin/env bash
# Native release-candidate tags are their own Git tags, native-<version>
# (Server #325). Check that:
# - the retired PHP Release workflow (server#1002) has no push trigger, so
#   no tag starts it, and its publish job never runs;
# - the tag verifier accepts native- only when asked for it;
# - the native publisher accepts only native- tags, and lets only a release
#   candidate (-rc.N) skip Docker Hub.
# Each script is stopped at its input checks, before any API or registry call.
set -euo pipefail
write_call="imagetools create"
failed="FAILED"
fail() { printf 'Native release tag check failed: %s\n' "$1" >&2; exit 1; }

# The retired PHP Release workflow (server#1002): no push trigger at all, so
# no tag of any kind starts it, and its publish job is `if: false`, so it never
# loads the registry logins or contents: write.
release_triggers=$(awk '
  /^on:/ { on = 1; next }
  on && /^[^ #]/ { on = 0 }
  on && /^  [a-z_]+:/ { sub(/^  /, ""); sub(/:.*/, ""); print }
' .github/workflows/release.yml)
test "$release_triggers" = workflow_dispatch \
  || fail "the retired release.yml must have only workflow_dispatch, not: $(echo $release_triggers)."
grep -qx '    if: false' <(sed -n '/^  publish:$/,/^  [a-z-]*:$/p' .github/workflows/release.yml) \
  || fail "the retired release.yml publish job must be if: false."

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
if [[ -n "${PUBLISH_LOOKUP_FAILURE:-}" && "$*" == *"imagetools inspect"* ]]; then
  printf '%s\n' "$PUBLISH_LOOKUP_FAILURE" >&2; exit 73
fi
if [[ "$*" == *"imagetools inspect --format {{json .Image}}"* ]]; then
  if [[ -n "${CURRENT_READ_FAILURE:-}" ]]; then
    if [[ "$CURRENT_READ_FAILURE" == mixed-subject ]]; then printf 'ERROR: %s: not found\nerror getting credentials: helper not found\n' "$ref" >&2
    else printf '%s\n' "$CURRENT_READ_FAILURE" >&2; fi
    exit 73
  fi
  if [[ "${RACE_REF:-}" == "$ref" ]]; then
    count=$(( $(cat "$RACE_COUNTER" 2>/dev/null || echo 0) + 1 )); echo "$count" > "$RACE_COUNTER"
    if ((count > 1)); then echo '{"config":{"Labels":{"dev.durable-workflow.release.tag":"3.9.0"}}}'; exit 0; fi
  fi
  label=$(awk -v r="$ref" '$1 == r { print $2 }' <<<"${ALIAS_STATE:-}")
  [[ -z "$label" ]] && { echo "ERROR: $ref: not found" >&2; exit 1; }
  [[ "$label" == unreadable ]] && { echo "ERROR: unauthorized" >&2; exit 1; }
  printf '{"linux/amd64":{"config":{"Labels":{"dev.durable-workflow.release.tag":"%s"}}}}\n' "$label"
elif [[ "$*" == *"imagetools create"* ]]; then
  touch "$CALL_LOG.written"
  if [[ "${FAIL_HUB_WRITE:-false}" == true && "$ref" == example/native@* ]]; then exit 73; fi
elif [[ "$*" == *"imagetools inspect"* ]]; then
  if [[ "${RACE_EXACT:-false}" == true && "$ref" == ghcr.io/example/native:3.0.0 ]]; then
    count=$(( $(cat "$RACE_COUNTER" 2>/dev/null || echo 0) + 1 )); echo "$count" > "$RACE_COUNTER"
    if ((count > 1)); then printf 'sha256:%064d\n' 0; exit 0; fi
  fi
  if [[ "${POST_WRITE_MISMATCH:-false}" == true && -f "$CALL_LOG.written" && "$ref" == ghcr.io/example/native:3.0 ]]; then
    printf 'sha256:%064d\n' 0; exit 0
  fi
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
want "exact tag mismatch" "$(rolling $'2.11.8' native-3.0.0 '' "sha256:$(printf '%064d' 0 | tr 0 b)")" "$failed"
grep -q "$write_call" "$scratch/calls" && fail "aliases were written after an exact-tag mismatch."
want "unreadable alias" "$(rolling $'2.11.8' native-3.0.0 'example/native:latest unreadable')" "$failed"
grep -q "$write_call" "$scratch/calls" && fail "aliases were written though an alias could not be read."
expect "INDEX_DIGEST must be the verified index digest" RELEASE_TAG=native-3.0.0 "${images[@]}" RELEASE_IMAGE_KNOWN_TAGS=x bash scripts/ci/native-release-rolling-tags.sh
# Detectable changes before the first write leave zero writes. A second
# registry failure or post-write mismatch records partial/uncertain state and
# never issues a rollback. These are fake registries, not network experiments.
rolling_control() {
  : > "$scratch/calls"; : > "$scratch/summary"; rm -f "$scratch/counter" "$scratch/calls.written"
  env -i PATH="$PATH" DOCKER="$scratch/docker" CALL_LOG="$scratch/calls" GITHUB_STEP_SUMMARY="$scratch/summary" \
    INDEX_DIGEST="$index" "${images[@]}" RELEASE_TAG=3.0.0 RELEASE_IMAGE_KNOWN_TAGS=3.0.0 \
    RACE_COUNTER="$scratch/counter" "$@" bash scripts/ci/native-release-rolling-tags.sh >/dev/null 2>&1
}
for race in 'RACE_REF=ghcr.io/example/native:3.0' RACE_EXACT=true; do
  if rolling_control "$race" ALIAS_STATE='ghcr.io/example/native:3.0 3.0.0'; then fail "changed pre-write state must refuse."; fi
  grep -q "$write_call" "$scratch/calls" && fail "pre-write race wrote aliases."
done
if rolling_control FAIL_HUB_WRITE=true; then fail "second-registry failure must stop."; fi
[[ "$(grep -c "$write_call" "$scratch/calls")" = 2 ]] || fail "partial run must attempt exactly two forward writes."
grep -q 'confirmed=ghcr.io/example/native; no rollback' "$scratch/summary" || fail "partial run lost confirmed registry record."
[[ "$(grep -c 'alias write attempted:' "$scratch/summary")" = 2 ]] || fail "each attempt must be persisted before a failed or hanging write."
[[ "$(grep -c 'alias write confirmed:' "$scratch/summary")" = 1 ]] || fail "only verified writes may be persisted as confirmed."
if rolling_control POST_WRITE_MISMATCH=true; then fail "post-write mismatch must stop."; fi
[[ "$(grep -c "$write_call" "$scratch/calls")" = 1 ]] || fail "post-write mismatch must not touch registry two or roll back."
grep -q 'no rollback' "$scratch/summary" || fail "uncertain run must record no rollback."
# Final Release ordering cannot run the writer. Exact live aliases pass;
# missing/older aliases refuse; uniformly newer final aliases may supersede.
for state in "" 'ghcr.io/example/native:3.0 2.11.8'; do
  if rolling_control VERIFY_ONLY=true ALIAS_STATE="$state"; then fail "unpromoted live state must refuse."; fi
  grep -q "$write_call" "$scratch/calls" && fail "live-state verification mutated aliases."
done
live_state=$'ghcr.io/example/native:3.0 3.0.0\nexample/native:3.0 3.0.0\nghcr.io/example/native:3 3.0.0\nexample/native:3 3.0.0\nghcr.io/example/native:latest 3.0.0\nexample/native:latest 3.0.0'
rolling_control VERIFY_ONLY=true ALIAS_STATE="$live_state" || fail "qualified same-index live state must pass."
grep -q "$write_call" "$scratch/calls" && fail "same-index live-state verification wrote."

# Credential/transport/auth/rate failures are never evidence of alias or
# version-tag absence, even when their text contains the words "not found".
read_failures=(
  'error getting credentials: credential helper not found'
  'error getting credentials: executable file not found in $PATH'
  'HTTP 401 Unauthorized: authentication required'
  'insufficient_scope: authorization failed'
  'dial tcp: registry host not found'
  'TLS CA file not found'
  'HTTP 429 Too Many Requests'
  'manifest unknown'
  'ERROR: unrelated.invalid/server:latest: not found'
  mixed-subject
)
for failure in "${read_failures[@]}"; do
  if rolling_control CURRENT_READ_FAILURE="$failure"; then fail "failed alias lookup was accepted as absent."; fi
  grep -q "$write_call" "$scratch/calls" && fail "failed alias lookup reached registry writes."
  : > "$scratch/calls"
  if env -i PATH="$scratch:$PATH" CALL_LOG="$scratch/calls" PUBLISH_LOOKUP_FAILURE="$failure" \
    "${images[@]}" RELEASE_TAG=3.0.0 PUBLISH_DOCKERHUB=true DIGEST_AMD64="$index" DIGEST_ARM64="$index" \
    bash "$publish" >/dev/null 2>&1; then fail "failed exact-tag lookup was accepted as absent."; fi
  grep -q "$write_call" "$scratch/calls" && fail "failed exact-tag lookup reached publication writes."
done
source scripts/ci/native-registry-absence.sh
native_registry_subject_missing ghcr.io/example/native:latest 'ERROR: ghcr.io/example/native:latest: not found' \
  || fail "the exact subject-bound not-found format must be recognized."
for output in 'not found' 'ERROR: example/native:latest: not found' 'ERROR: ghcr.io/example/native:latest: manifest unknown' \
  $'ERROR: ghcr.io/example/native:latest: not found\ncredential helper not found'; do
  if native_registry_subject_missing ghcr.io/example/native:latest "$output"; then fail "unbound/mixed absence diagnostic accepted."; fi
done

# Exercise the actual publisher with the product's canonical Docker Hub subject.
(
fail() { printf 'Native registry subject check failed: %s\n' "$1" >&2; exit 1; }
scratch=$(mktemp -d)
trap 'rm -rf "$scratch"' EXIT
mkdir "$scratch/bin"
cat > "$scratch/bin/docker" <<'FAKE'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >> "$CALL_LOG"
[[ "$1 $2" == 'buildx imagetools' ]] || exit 74
ref=${!#}
case "$3" in
  create)
    [[ $# == 7 && "$4" == --tag ]] || exit 74
    touch "$STATE"
    ;;
  inspect)
    case "$ref" in
      ghcr.io/durable-workflow/server:3.2.0|durableworkflow/server:3.2.0) ;;
      *) exit 74 ;;
    esac
    if [[ ! -e "$STATE" ]]; then
      if [[ "$ref" == durableworkflow/server:* ]]; then
        printf '%s\n' "${HUB_DIAGNOSTIC:-ERROR: docker.io/durableworkflow/server:3.2.0: not found}" >&2
      else
        printf 'ERROR: %s: not found\n' "$ref" >&2
      fi
      exit 1
    fi
    if [[ "$4" == --raw ]]; then
      printf '{"manifests":[{"digest":"%s","platform":{"os":"linux","architecture":"amd64"}},{"digest":"%s","platform":{"os":"linux","architecture":"arm64"}}]}\n' "$DIGEST_AMD64" "$DIGEST_ARM64"
    else
      [[ "$4" == --format && "$5" == '{{.Manifest.Digest}}' ]] || exit 74
      printf '%s\n' "$INDEX_DIGEST"
    fi
    ;;
  *) exit 74 ;;
esac
FAKE
chmod +x "$scratch/bin/docker"
index=sha256:$(printf '%064d' 0 | tr 0 a)
amd64=sha256:$(printf '%064d' 0 | tr 0 b)
arm64=sha256:$(printf '%064d' 0 | tr 0 c)
publish() {
  : > "$scratch/calls"
  rm -f "$scratch/written"
  env -i PATH="$scratch/bin:$PATH" CALL_LOG="$scratch/calls" STATE="$scratch/written" \
    INDEX_DIGEST="$index" DIGEST_AMD64="$amd64" DIGEST_ARM64="$arm64" \
    GHCR_IMAGE=ghcr.io/durable-workflow/server DOCKERHUB_IMAGE=durableworkflow/server \
    RELEASE_TAG=3.2.0 PUBLISH_DOCKERHUB=true GITHUB_OUTPUT="$scratch/output" \
    "$@" bash scripts/ci/native-release-publish.sh > "$scratch/result" 2>&1
}
if ! publish; then
  writes=$(grep -c 'imagetools create' "$scratch/calls" || true)
  fail "canonical Docker Hub absence refused (simulated_writes=$writes)."
fi
[[ $(grep -c 'imagetools create' "$scratch/calls") == 2 ]] || fail 'expected exactly two version-index writes.'
for image in ghcr.io/durable-workflow/server durableworkflow/server; do
  grep -Fxq "buildx imagetools create --tag $image:3.2.0 $image@$amd64 $image@$arm64" "$scratch/calls" \
    || fail 'version-index write changed its subject or children.'
done
grep -Fxq "digest=$index" "$scratch/output" || fail 'the verified two-registry index was not returned.'

diagnostics=(
  'ERROR: durableworkflow/server:3.2.0: not found'
  'ERROR: docker.io/durableworkflow/server-other:3.2.0: not found'
  'ERROR: docker.io/durableworkflow/server:3.2.1: not found'
  'ERROR: unrelated.invalid/durableworkflow/server:3.2.0: not found'
  'ERROR: docker.io/other/server:3.2.0: not found'
  'ERROR: docker.io/durableworkflow/server:3.2.0: manifest unknown'
  'HTTP 401 Unauthorized: authentication required'
  'error getting credentials: credential helper not found'
  $'ERROR: docker.io/durableworkflow/server:3.2.0: not found\nerror getting credentials: helper not found'
  $'ERROR: docker.io/durableworkflow/server:3.2.0: not found\nERROR: unrelated.invalid/server:3.2.0: not found'
  ' ERROR: docker.io/durableworkflow/server:3.2.0: not found'
  'ERROR: docker.io/durableworkflow/server:3.2.0: not found '
)
for diagnostic in "${diagnostics[@]}"; do
  if publish HUB_DIAGNOSTIC="$diagnostic"; then fail 'unbound absence reached publication.'; fi
  grep -Fq 'could not establish exact version-tag absence.' "$scratch/result" \
    || fail 'a negative control did not reach the absence predicate.'
  if grep -q 'imagetools create' "$scratch/calls"; then fail 'refused lookup reached an index write.'; fi
done
source scripts/ci/native-registry-absence.sh
native_registry_subject_missing docker.io/durableworkflow/server:3.2.0 \
  'ERROR: docker.io/durableworkflow/server:3.2.0: not found' || fail 'fully qualified exact subject refused.'
printf 'Native registry subjects: canonical official Docker Hub publication and %s zero-write refusals passed.\n' "${#diagnostics[@]}"
)

# The GitHub Release, from protected main (native-github-release.yml): it
# verifies the tag, the run, both exact tags and the provenance, then marks
# a final Latest only when the live latest alias names its index in both
# registries. Fakes answer each read from the variables below.
commit=0123456789abcdef0123456789abcdef01234567
cat > "$scratch/gh" <<'FAKE'
#!/usr/bin/env bash
case "$1 $2" in
  "release view") [[ -n "${EXISTING:-}" ]] || exit 1; printf '%s\n' "$EXISTING"; exit 0 ;;
  "release create")
    printf '%s\n' "$*" >> "$GH_LOG"
    while [[ $# -gt 0 && "$1" != --notes ]]; do shift; done
    printf '%s' "${2:-}" > "$GH_NOTES"; exit 0 ;;
  "attestation verify")
    printf '%s\n' "$*" >> "$GH_ATTEST_LOG"
    [[ "${ATTEST:-ok}" == ok ]] || exit 1
    [[ "${ATTEST_FAIL_IMAGE:-}" != "$3" ]] || exit 1
    printf '%s\n' "${ATTEST_URI:-https://github.com/example/server/actions/runs/${RUN_ID}/attempts/1}"; exit 0 ;;
esac
path=$2
case "$path" in
  repos/example/server/git/ref/tags/*) printf '%s\n' "${TAG_OBJECT:-commit $COMMIT}" ;;
  repos/example/server/git/tags/*) printf 'commit %s\n' "$COMMIT" ;;
  repos/example/server/compare/*) printf '%s\n' "${COMPARE:-ahead}" ;;
  repos/example/server/releases/latest) printf '%s\n' "${LATEST_TAG:-}" ;;
  repos/example/server/actions/runs/*) printf '%s\n' "${RUN_LINE:-.github/workflows/native-release.yml workflow_dispatch completed success $RELEASE_TAG $COMMIT ${RUN_ATTEMPT:-1}}" ;;
  *) exit 1 ;;
esac
FAKE
cat > "$scratch/release-docker" <<'FAKE'
#!/usr/bin/env bash
ref=${!#}
if [[ "$ref" == *:latest ]]; then
  if [[ -n "${LATEST_READ_FAILURE:-}" ]]; then
    if [[ "$LATEST_READ_FAILURE" == mixed-subject ]]; then printf 'ERROR: %s: not found\ncredential helper not found\n' "$ref" >&2
    else printf '%s\n' "$LATEST_READ_FAILURE" >&2; fi
    exit 73
  fi
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
# The publication/Release unit controls stub only the independently tested
# qualification verifier; its actual provider/receipt/zero-write tests run below.
cat > "$scratch/python3" <<'FAKE'
#!/usr/bin/env bash
[[ "$1" == */native_alias_promote.py && "$2" == release-order ]] || exit 1
[[ -n "${PROMOTION_EVIDENCE:-}" ]] || exit 1
exit "${QUALIFICATION_STATUS:-0}"
FAKE
chmod +x "$scratch/python3"
newer_index=sha256:$(printf '%064d' 0 | tr 0 b)
gh_release() { # release tag, latest state [, VAR=value ...] -> the Latest choice
  local tag=$1 state=$2; shift 2
  : > "$scratch/gh_calls"; : > "$scratch/gh_attest"
  env -i PATH="$scratch:$PATH" GH="$scratch/gh" GH_LOG="$scratch/gh_calls" GH_ATTEST_LOG="$scratch/gh_attest" GH_NOTES="$scratch/gh_notes" \
    DOCKER="$scratch/release-docker" "${images[@]}" GITHUB_REPOSITORY=example/server \
    RELEASE_TAG="$tag" RELEASE_RUN_ID=41 RELEASE_RUN_ATTEMPT=1 PROMOTION_EVIDENCE=synthetic-selected-evidence RUN_ID=41 COMMIT="$commit" INDEX="$index" LATEST_STATE="$state" "$@" \
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
grep -q 'oci://ghcr.io/example/native@' "$scratch/gh_attest" && grep -q 'oci://example/native@' "$scratch/gh_attest" \
  || fail "both registry provenance subjects must verify."
backport=$(rolling $'2.11.8\nnative-3.0.1\n3.1.0' 3.0.2 $'ghcr.io/example/native:3 3.1.0\nexample/native:3 3.1.0\nghcr.io/example/native:latest 3.1.0\nexample/native:latest 3.1.0')
want "3.0.2 backport behind 3.1.0" "$backport" "3.0"
want_release "3.0.2 backport behind 3.1.0" "$(gh_release 3.0.2 "$theirs")" "--latest=false"
want_release "3.0.2 released after 3.1.0 took latest" "$(gh_release 3.0.2 "$theirs")" "--latest=false"
want_release "only GHCR names the index" "$(gh_release 3.0.2 $'ghcr.io/example/native '"$index"$'\nexample/native '"$newer_index")" "--latest=false"
want_release "no latest alias yet" "$(gh_release 3.0.2 "")" "--latest=false"
want_release "candidate" "$(gh_release 3.1.0-rc.1 "$ours")" "--prerelease --latest=false"
want_release "native- final that owns latest" "$(gh_release native-3.0.2 "$ours")" "--latest"
want_release "annotated tag" "$(gh_release 3.0.2 "$ours" TAG_OBJECT="tag 89abcdef0123456789abcdef0123456789abcdef")" "--latest"
# An existing Release is checked after every binding and the Latest
# decision: only exactly the Release this step would publish, with Latest
# where the live alias puts it, is left unchanged; anything else is refused.
gh_release 3.1.0 "$ours" >/dev/null; notes_final=$(cat "$scratch/gh_notes")
gh_release 3.1.0-rc.1 "$ours" >/dev/null; notes_candidate=$(cat "$scratch/gh_notes")
gh_release 3.0.2 "$theirs" >/dev/null; notes_backport=$(cat "$scratch/gh_notes")
existing() { # tag, title, draft, prerelease, notes
  jq -cn --arg tag "$1" --arg title "$2" --argjson draft "$3" --argjson pre "$4" --arg body "$5" \
    '{tagName:$tag,name:$title,isDraft:$draft,isPrerelease:$pre,body:$body}'
}
want_release "rerun of the same final, Latest" "$(gh_release 3.1.0 "$ours" LATEST_TAG=3.1.0 EXISTING="$(existing 3.1.0 3.1.0 false false "$notes_final")")" "none"
want_release "rerun of the same candidate" "$(gh_release 3.1.0-rc.1 "$ours" LATEST_TAG=3.0.2 EXISTING="$(existing 3.1.0-rc.1 3.1.0-rc.1 false true "$notes_candidate")")" "none"
want_release "rerun of the same backport, not Latest" "$(gh_release 3.0.2 "$theirs" LATEST_TAG=3.1.0 EXISTING="$(existing 3.0.2 3.0.2 false false "$notes_backport")")" "none"
want_release "existing draft" "$(gh_release 3.1.0 "$ours" LATEST_TAG=3.1.0 EXISTING="$(existing 3.1.0 3.1.0 true false "$notes_final")")" "$failed"
want_release "existing release with other notes" "$(gh_release 3.1.0 "$ours" LATEST_TAG=3.1.0 EXISTING="$(existing 3.1.0 3.1.0 false false "$notes_final extra")")" "$failed"
want_release "existing notes naming everything but ambiguous" "$(gh_release 3.1.0 "$ours" LATEST_TAG=3.1.0 EXISTING="$(existing 3.1.0 3.1.0 false false "$index $commit https://github.com/example/server/actions/runs/41")")" "$failed"
want_release "existing release with another title" "$(gh_release 3.1.0 "$ours" LATEST_TAG=3.1.0 EXISTING="$(existing 3.1.0 native-3.1.0 false false "$notes_final")")" "$failed"
want_release "existing final marked prerelease" "$(gh_release 3.1.0 "$ours" LATEST_TAG=3.1.0 EXISTING="$(existing 3.1.0 3.1.0 false true "$notes_final")")" "$failed"
want_release "existing final the alias owns but not Latest" "$(gh_release 3.1.0 "$ours" LATEST_TAG=3.0.2 EXISTING="$(existing 3.1.0 3.1.0 false false "$notes_final")")" "$failed"
want_release "existing backport marked Latest" "$(gh_release 3.0.2 "$theirs" LATEST_TAG=3.0.2 EXISTING="$(existing 3.0.2 3.0.2 false false "$notes_backport")")" "$failed"
# The bindings come first: an existing Release does not excuse a failed one.
want_release "existing release, unverifiable provenance" "$(gh_release 3.1.0 "$ours" ATTEST=fail LATEST_TAG=3.1.0 EXISTING="$(existing 3.1.0 3.1.0 false false "$notes_final")")" "$failed"
want_release "existing release, commit off main" "$(gh_release 3.1.0 "$ours" COMPARE=diverged LATEST_TAG=3.1.0 EXISTING="$(existing 3.1.0 3.1.0 false false "$notes_final")")" "$failed"
# Every binding fails closed, before any write.
want_release "unreadable latest alias" "$(gh_release 3.0.2 $'ghcr.io/example/native unreadable\nexample/native '"$index")" "$failed"
want_release "a PHP tag" "$(gh_release 2.11.9 "$ours")" "$failed"
want_release "no run id" "$(gh_release 3.0.2 "$ours" RELEASE_RUN_ID=)" "$failed"
want_release "tag commit not on main" "$(gh_release 3.0.2 "$ours" COMPARE=diverged)" "$failed"
want_release "tag names a tree" "$(gh_release 3.0.2 "$ours" TAG_OBJECT="tree $commit")" "$failed"
want_release "run failed" "$(gh_release 3.0.2 "$ours" RUN_LINE=".github/workflows/native-release.yml workflow_dispatch completed failure 3.0.2 $commit")" "$failed"
want_release "run of another workflow" "$(gh_release 3.0.2 "$ours" RUN_LINE=".github/workflows/release.yml workflow_dispatch completed success 3.0.2 $commit")" "$failed"
want_release "run from another ref" "$(gh_release 3.0.2 "$ours" RUN_LINE=".github/workflows/native-release.yml workflow_dispatch completed success main $commit")" "$failed"
want_release "run at another commit" "$(gh_release 3.0.2 "$ours" RUN_LINE=".github/workflows/native-release.yml workflow_dispatch completed success 3.0.2 fedcba9876543210fedcba9876543210fedcba98")" "$failed"
want_release "registries name different indexes" "$(gh_release 3.0.2 "$ours" HUB_EXACT="$newer_index")" "$failed"
want_release "provenance does not verify" "$(gh_release 3.0.2 "$ours" ATTEST=fail)" "$failed"
want_release "Docker Hub provenance does not verify" "$(gh_release 3.0.2 "$ours" ATTEST_FAIL_IMAGE="oci://example/native@$index")" "$failed"
[[ ! -s "$scratch/gh_calls" ]] || fail "Release was created after Docker Hub provenance failure."
want_release "provenance from another run" "$(gh_release 3.0.2 "$ours" ATTEST_URI=https://github.com/example/server/actions/runs/42/attempts/1)" "$failed"
want_release "provenance from a run id prefix" "$(gh_release 3.0.2 "$ours" ATTEST_URI=https://github.com/example/server/actions/runs/411/attempts/1)" "$failed"

want_release "missing exact publication attempt" "$(gh_release 3.0.2 "$ours" RELEASE_RUN_ATTEMPT=)" "$failed"
want_release "publication current attempt changed" "$(gh_release 3.0.2 "$ours" RELEASE_RUN_ATTEMPT=2)" "$failed"
want_release "provenance from another attempt" "$(gh_release 3.0.2 "$ours" ATTEST_URI=https://github.com/example/server/actions/runs/41/attempts/2)" "$failed"
want_release "qualification refusal" "$(gh_release 3.0.2 "$ours" QUALIFICATION_STATUS=1)" "$failed"
[[ ! -s "$scratch/gh_calls" ]] || fail "Release was created despite qualification refusal."
want_release "final missing qualification" "$(gh_release 3.0.2 "$ours" PROMOTION_EVIDENCE=)" "$failed"
want_release "candidate needs no alias qualification" "$(gh_release 3.1.0-rc.1 "$ours" PROMOTION_EVIDENCE= QUALIFICATION_STATUS=1)" "--prerelease --latest=false"

for failure in "${read_failures[@]}"; do
  want_release "failed latest read remains unknown" "$(gh_release 3.0.2 "$ours" LATEST_READ_FAILURE="$failure")" "$failed"
  [[ ! -s "$scratch/gh_calls" ]] || fail "failed latest lookup reached Release creation."
done
want_release "malformed successful latest digest" "$(gh_release 3.0.2 'ghcr.io/example/native not-a-digest')" "$failed"
[[ ! -s "$scratch/gh_calls" ]] || fail "malformed latest digest reached Release creation."

# Release identity: only a strict native- label admits a release image, and
# only when its server reports exactly that version.
identity=scripts/ci/native-release-identity.sh
[[ "$(bash "$identity" expect native-3.0.0)" = 3.0.0 ]] || fail "native-3.0.0 must admit 3.0.0."
[[ "$(bash "$identity" expect 3.0.2)" = 3.0.2 ]] || fail "3.0.2 must admit 3.0.2."
[[ "$(bash "$identity" expect 3.1.0-rc.1)" = 3.1.0-rc.1 ]] || fail "3.1.0-rc.1 must admit 3.1.0-rc.1."
if bash "$identity" expect 2.11.9 >/dev/null 2>&1; then fail "a plain 2.x tag is a PHP release, not native."; fi
[[ "$(bash "$identity" expect native-3.0.0-rc.3)" = 3.0.0-rc.3 ]] || fail "native-3.0.0-rc.3 must admit 3.0.0-rc.3."
[[ -z "$(bash "$identity" expect '')" ]] || fail "an unlabelled image is a development image."
[[ -z "$(bash "$identity" expect '<no value>')" ]] || fail "an unlabelled image is a development image."
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
  [[ ! -s "$scratch/calls" ]] || fail "$script wrote to a registry after a failed tag lookup."
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
[[ "$(php_stage lookup)" != 0 ]] || fail "a promoter whose second lookup fails must exit nonzero."
[[ ! -s "$scratch/calls" ]] || fail "a promoter whose second lookup fails wrote to a registry."
[[ "$(php_stage inventory)" = 0 ]] || fail "the promoter must succeed from the resolver's inventory."
[[ "$(cat "$scratch/git-count")" = 1 ]] || fail "the PHP stage must read the tags exactly once."
grep -q -- '--tag durableworkflow/server:2.11 --tag durableworkflow/server:2 ' "$scratch/calls" \
  || fail "the PHP release must still move 2 and 2.11."
grep -q ':latest' "$scratch/calls" && fail "the PHP release moved latest past native-3.0.0."
# Without any inventory the promoter writes nothing.
: > "$scratch/calls"
if env -i PATH="$PATH" DOCKER="$scratch/docker" CALL_LOG="$scratch/calls" RELEASE_TAG=2.11.9 \
  VERIFIED_IMAGE_DIGEST="$index" sh scripts/ci/promote-release-image-rolling-tags.sh >/dev/null 2>&1; then
  fail "the promoter must refuse without a tag inventory."
fi
[[ ! -s "$scratch/calls" ]] || fail "the promoter wrote without a tag inventory."
rm -f "$scratch/git" "$scratch/git-count"
# Both workflows hold the one rolling-alias lock, and the PHP promoter takes
# the resolver's inventory rather than reading the tags again.
# The GitHub Release job holds it too, so Latest is decided with no alias
# moving underneath it.
for workflow in .github/workflows/release.yml .github/workflows/native-alias-promotion.yml .github/workflows/native-github-release.yml; do
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
[[ -z "$unprotected" ]] || fail "native-release.yml jobs with a write scope outside dockerhub-native: $unprotected."
# The GitHub Release workflow: one job, from protected main only, in its own
# main-only Environment, the only contents: write in either workflow.
github_release=.github/workflows/native-github-release.yml
[[ "$(grep -c '^  [a-z0-9-]*:$' <(sed -n '/^jobs:/,$p' "$github_release"))" = 1 ]] || fail "$github_release must have one job."
grep -q "^    if: github.ref == 'refs/heads/main'$" "$github_release" || fail "$github_release must run only from main."
grep -q '^    environment: github-release$' "$github_release" || fail "$github_release must run in the github-release Environment."
grep -q '^  workflow_dispatch:$' "$github_release" && test "$(sed -n '/^on:/,/^permissions:/p' "$github_release" | grep -c '^  [a-z_]*:$')" = 1 \
  || fail "$github_release must be triggered only by workflow_dispatch."
[[ "$(grep -c '      contents: write' "$github_release")" = 1 ]] || fail "$github_release must hold contents: write once."
[[ "$(grep -c '^ *contents: write$' .github/workflows/native-release.yml)" = 0 ]] || fail "native-release.yml must not hold contents: write."
grep -q 'RELEASE_IMAGE_KNOWN_TAGS: ${{ steps.rolling.outputs.release_inventory }}' .github/workflows/release.yml \
  || fail "the PHP promoter must use the resolver's inventory."
awk '/- name: Promote rolling image aliases/,/run: scripts\/ci\/promote-release-image-rolling-tags.sh/' .github/workflows/release.yml \
  | grep -q RELEASE_IMAGE_TAG_SOURCE_URL && fail "the PHP promoter must not read the tags again."
# The dispatch verifier: the release tag's own ref, at its commit, on main.
dispatch=scripts/ci/verify-native-release-dispatch.sh
dispatch_scratch=$(mktemp -d)
cat > "$dispatch_scratch/gh" <<'FAKE'
#!/usr/bin/env bash
# compare: COMPARE_STATUS; the release commit's first parent: PARENT_SHA;
# docker-compose.published.yml at that parent: the file PARENT_COMPOSE names.
case "$*" in
  *"/compare/"*) echo "${COMPARE_STATUS:-ahead}" ;;
  *"/commits/"*) [ -n "${PARENT_SHA:-}" ] || exit 1; echo "$PARENT_SHA" ;;
  *"/contents/docker-compose.published.yml"*) [ -f "${PARENT_COMPOSE:-/nonexistent}" ] || exit 1; cat "$PARENT_COMPOSE" ;;
  *) exit 1 ;;
esac
FAKE
chmod +x "$dispatch_scratch/gh"
# A final release's commit names its own image as the published Compose
# default; each case reads a fixture of that file.
compose_for() { # version -> a docker-compose.published.yml naming it
  printf 'x-server-image: &server-image ${DW_SERVER_IMAGE:-durableworkflow/server:%s}\n' "$1" > "$dispatch_scratch/compose-$1.yml"
  printf '%s' "$dispatch_scratch/compose-$1.yml"
}
commit=0123456789abcdef0123456789abcdef01234567
other=fedcba9876543210fedcba9876543210fedcba98
ok_dispatch=(RELEASE_TAG=native-3.0.2 RELEASE_COMMIT=$commit DISPATCH_SHA=$commit GITHUB_REPOSITORY=example/server GH_CLI="$dispatch_scratch/gh" COMPOSE_FILE="$(compose_for 3.0.2)")
expect "dispatch from refs/tags/native-3.0.2, not refs/heads/main" "${ok_dispatch[@]}" DISPATCH_REF=refs/heads/main bash "$dispatch"
expect "dispatch from refs/tags/native-3.0.2, not refs/tags/native-3.0.1" "${ok_dispatch[@]}" DISPATCH_REF=refs/tags/native-3.0.1 bash "$dispatch"
expect "is not RELEASE_COMMIT" RELEASE_TAG=native-3.0.2 RELEASE_COMMIT=$commit DISPATCH_SHA=$other DISPATCH_REF=refs/tags/native-3.0.2 GITHUB_REPOSITORY=example/server GH_CLI="$dispatch_scratch/gh" bash "$dispatch"
expect "must be X.Y.Z or X.Y.Z-rc.N from major 3" "${ok_dispatch[@]}" RELEASE_TAG=2.0.2 DISPATCH_REF=refs/tags/2.0.2 bash "$dispatch"
for status in behind diverged; do
  expect "is not on main (compare status: $status)" "${ok_dispatch[@]}" DISPATCH_REF=refs/tags/native-3.0.2 COMPARE_STATUS=$status bash "$dispatch"
done
plain_dispatch=(RELEASE_TAG=3.0.2 RELEASE_COMMIT=$commit DISPATCH_SHA=$commit DISPATCH_REF=refs/tags/3.0.2 GITHUB_REPOSITORY=example/server GH_CLI="$dispatch_scratch/gh")
env -i PATH="$PATH" "${plain_dispatch[@]}" COMPOSE_FILE="$(compose_for 3.0.2)" bash "$dispatch" >/dev/null || fail "the dispatch verifier must accept a plain 3.x tag."
# The Compose default must name the final release being dispatched.
expect "defaults to durableworkflow/server:3.0.1, not durableworkflow/server:3.0.2" "${plain_dispatch[@]}" COMPOSE_FILE="$(compose_for 3.0.1)" bash "$dispatch"
: > "$dispatch_scratch/empty.yml"
expect "must default to exactly one durableworkflow/server:X.Y.Z image" "${plain_dispatch[@]}" COMPOSE_FILE="$dispatch_scratch/empty.yml" bash "$dispatch"
expect "cannot read" "${plain_dispatch[@]}" COMPOSE_FILE="$dispatch_scratch/missing.yml" bash "$dispatch"
# A candidate keeps its first parent's default, exactly.
parent=89abcdef0123456789abcdef0123456789abcdef
candidate=(RELEASE_TAG=3.1.0-rc.1 RELEASE_COMMIT=$commit DISPATCH_SHA=$commit DISPATCH_REF=refs/tags/3.1.0-rc.1 GITHUB_REPOSITORY=example/server
  GH_CLI="$dispatch_scratch/gh" PARENT_SHA=$parent PARENT_COMPOSE="$(compose_for 3.0.2)")
env -i PATH="$PATH" "${candidate[@]}" COMPOSE_FILE="$(compose_for 3.0.2)" bash "$dispatch" >/dev/null \
  || fail "a candidate that keeps its parent's Compose default must pass."
printf 'x-server-image: &server-image ${DW_SERVER_IMAGE:-durableworkflow/server:3.0.2}\nx-server-image: &server-image ${DW_SERVER_IMAGE:-durableworkflow/server:3.0.2}\n' > "$dispatch_scratch/duplicate.yml"
printf 'x-server-image: &server-image ${DW_SERVER_IMAGE:-durableworkflow/server:latest}\n' > "$dispatch_scratch/latest.yml"
printf 'x-server-image: &server-image ${DW_SERVER_IMAGE:-example/server:3.0.2}\n' > "$dispatch_scratch/unofficial.yml"
expect "a candidate keeps the Compose default" "${candidate[@]}" COMPOSE_FILE="$(compose_for 3.1.0)" bash "$dispatch"
expect "a candidate keeps the Compose default" "${candidate[@]}" COMPOSE_FILE="$(compose_for 3.0.9)" bash "$dispatch"
for bad in duplicate latest unofficial empty; do
  expect "must default to exactly one durableworkflow/server:X.Y.Z image" "${candidate[@]}" COMPOSE_FILE="$dispatch_scratch/$bad.yml" bash "$dispatch"
  expect "must default to exactly one durableworkflow/server:X.Y.Z image" "${plain_dispatch[@]}" COMPOSE_FILE="$dispatch_scratch/$bad.yml" bash "$dispatch"
done
expect "a candidate keeps the Compose default" "${candidate[@]}" PARENT_COMPOSE="$dispatch_scratch/duplicate.yml" COMPOSE_FILE="$(compose_for 3.0.2)" bash "$dispatch"
expect "cannot read the first parent" RELEASE_TAG=3.1.0-rc.1 RELEASE_COMMIT=$commit DISPATCH_SHA=$commit DISPATCH_REF=refs/tags/3.1.0-rc.1 \
  GITHUB_REPOSITORY=example/server GH_CLI="$dispatch_scratch/gh" COMPOSE_FILE="$(compose_for 3.0.2)" bash "$dispatch"
expect "cannot read docker-compose.published.yml at $parent" "${candidate[@]}" PARENT_COMPOSE=/nonexistent COMPOSE_FILE="$(compose_for 3.0.2)" bash "$dispatch"
# This repository's own file names the release its commit is tagged as.
grep -q '^x-server-image: &server-image ${DW_SERVER_IMAGE:-durableworkflow/server:[0-9][0-9.]*}$' docker-compose.published.yml \
  || fail "docker-compose.published.yml must default to one exact durableworkflow/server release."
expect "must be X.Y.Z or X.Y.Z-rc.N from major 3" RELEASE_TAG=2.11.9 RELEASE_COMMIT=$commit DISPATCH_SHA=$commit DISPATCH_REF=refs/tags/2.11.9 GITHUB_REPOSITORY=example/server GH_CLI="$dispatch_scratch/gh" bash "$dispatch"
for status in ahead identical; do
  env -i PATH="$PATH" "${ok_dispatch[@]}" DISPATCH_REF=refs/tags/native-3.0.2 COMPARE_STATUS=$status bash "$dispatch" >/dev/null \
    || fail "the dispatch verifier must accept a release commit main contains ($status)."
done
# The published Compose smoke's image: one official default, smoked as is
# when Docker Hub has it; latest only for a pull request whose default is not
# published yet; anything else fails.
select=scripts/ci/select-published-compose-image.sh
printf '#!/usr/bin/env bash\nprintf "%%s" "${HUB_STATUS:-200}"\nexit "${CURL_EXIT:-0}"\n' > "$dispatch_scratch/curl"
chmod +x "$dispatch_scratch/curl"
selected() { # event, compose file [, VAR=value ...] -> the selected image, "default" or FAILED
  local event=$1 compose=$2; shift 2
  : > "$dispatch_scratch/github_env"
  env -i PATH="$PATH" EVENT_NAME="$event" COMPOSE_FILE="$compose" CURL="$dispatch_scratch/curl" GITHUB_ENV="$dispatch_scratch/github_env" "$@" \
    bash "$select" >/dev/null 2>&1 || { echo FAILED; return; }
  sed -n 's/^DW_SERVER_IMAGE=//p' "$dispatch_scratch/github_env" | grep . || echo default
}
want_image() { test "$2" = "$3" || fail "$1: expected '$3', got '$2'."; }
want_image "published default" "$(selected pull_request "$(compose_for 3.0.2)")" default
want_image "unpublished default on a pull request" "$(selected pull_request "$(compose_for 3.0.3)" HUB_STATUS=404)" "durableworkflow/server:latest"
want_image "unpublished default on dispatch" "$(selected workflow_dispatch "$(compose_for 3.0.3)" HUB_STATUS=404)" FAILED
want_image "Docker Hub error" "$(selected pull_request "$(compose_for 3.0.3)" HUB_STATUS=503)" FAILED
want_image "Docker Hub unreachable" "$(selected pull_request "$(compose_for 3.0.3)" HUB_STATUS=000)" FAILED
want_image "a lookup that printed 200 but failed" "$(selected pull_request "$(compose_for 3.0.2)" HUB_STATUS=200 CURL_EXIT=7)" FAILED
want_image "a lookup that printed 404 but failed" "$(selected pull_request "$(compose_for 3.0.3)" HUB_STATUS=404 CURL_EXIT=28)" FAILED
for bad in duplicate latest unofficial empty; do
  want_image "$bad default" "$(selected pull_request "$dispatch_scratch/$bad.yml")" FAILED
done
want_image "missing file" "$(selected pull_request "$dispatch_scratch/missing.yml")" FAILED
want_image "manual input" "$(selected workflow_dispatch "$dispatch_scratch/duplicate.yml" INPUT_SERVER_IMAGE=ghcr.io/durable-workflow/server@sha256:abc)" "ghcr.io/durable-workflow/server@sha256:abc"
grep -q -- '--connect-timeout 10 --max-time 20' "$select" || fail "the Docker Hub lookup must be bounded."
grep -q '^      - scripts/ci/select-published-compose-image.sh$' .github/workflows/published-compose.yml \
  || fail "a change to the selector must run the published Compose smoke."
grep -q '^        run: scripts/ci/select-published-compose-image.sh$' .github/workflows/published-compose.yml \
  || fail "the published Compose smoke must select its image with select-published-compose-image.sh."
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
publish=$tag_gate"
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
printf 'Native release tags: retired release.yml cannot start or publish; verifier, publisher and rolling tags behave as intended.\n'
