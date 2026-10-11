#!/usr/bin/env bash
set -euo pipefail

scanner="${1:-$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)/scripts/check-public-boundary.sh}"
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

printf 'Public boundary metadata cases passed.\n'
