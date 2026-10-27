#!/usr/bin/env bash
set -euo pipefail

repository="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
scanner="${1:-$repository/scripts/check-public-boundary.sh}"
sandbox="$(mktemp -d)"
trap 'rm -r "$sandbox"' EXIT

git init --quiet --initial-branch=main "$sandbox/clean"
cd "$sandbox/clean"
git config user.name 'Boundary Test'
git config user.email 'boundary@example.invalid'
git commit --quiet --allow-empty -m 'Initial clean commit'
first="$(git rev-parse HEAD)"
git commit --quiet --allow-empty -m 'Second clean commit'
second="$(git rev-parse HEAD)"

check() {
  local expected="$1" label="$2" range="$3" result=0
  PUBLIC_BOUNDARY_GIT_RANGE="$range" bash "$scanner" > "$sandbox/output" 2>&1 || result=$?
  if [[ "$result" != "$expected" ]]; then
    printf 'FAIL %s: expected %s, got %s\n' "$label" "$expected" "$result" >&2
    cat "$sandbox/output" >&2
    exit 1
  fi
  printf 'PASS %s\n' "$label"
}

check 0 default-clean ''
check 0 manual-clean "-1 $second"
check 0 push-clean "$first..$second"
check 0 pr-clean "$first..$second"
check 0 root-commit "-1 $first"

# Construct the forbidden metadata marker without placing it in public source.
marker="$(printf '\x4c\x6f\x6f\x70\x2d\x49\x44\x3a')"
git commit --quiet --allow-empty -m "$marker synthetic metadata test"
forbidden="$(git rev-parse HEAD)"
check 1 default-forbidden ''
check 1 manual-forbidden "-1 $forbidden"
check 1 push-forbidden "$second..$forbidden"
check 1 pr-forbidden "$second..$forbidden"
check 1 invalid-range 'unavailable-ref..HEAD'
check 1 invalid-selected-commit '-1 unavailable-ref'
check 1 empty-range 'HEAD..HEAD'
check 1 original-empty-manual-range "..$forbidden"

# The old tip remains fetchable but is no longer advertised after a force push.
# Its shared ancestor has forbidden metadata that is outside the push range.
git switch --quiet -c old-tip
git commit --quiet --allow-empty -m 'Old branch tip'
old_tip="$(git rev-parse HEAD)"
git switch --quiet main
git commit --quiet --allow-empty -m 'First introduced commit'
git commit --quiet --allow-empty -m 'Second introduced commit'
new_tip="$(git rev-parse HEAD)"
git clone --quiet --bare . "$sandbox/remote.git"
git --git-dir="$sandbox/remote.git" update-ref -d refs/heads/old-tip
git --git-dir="$sandbox/remote.git" config uploadpack.allowAnySHA1InWant true
git clone --quiet --no-local --single-branch --branch main "file://$sandbox/remote.git" "$sandbox/push"
cd "$sandbox/push"
if git cat-file -e "$old_tip^{commit}" 2>/dev/null; then
  printf 'FAIL divergent-push: old tip was already present\n' >&2
  exit 1
fi

# Execute the workflow's actual fetch command with the fixture event SHA.
fetch_command="$(sed -n 's/^[[:space:]]*\(git fetch --no-tags .*\)$/\1/p' "$repository/.github/workflows/public-boundary.yml")"
before_expression='${{ github.event.before }}'
fetch_command="${fetch_command//"$before_expression"/$old_tip}"
bash -c "$fetch_command" > "$sandbox/fetch-output" 2>&1
check 0 divergent-push "$old_tip..$new_tip"
if [[ "$(git rev-list --count "$old_tip..$new_tip")" != 2 ]]; then
  printf 'FAIL divergent-push: range includes commits outside this push\n' >&2
  exit 1
fi

printf 'Public boundary metadata cases passed.\n'
