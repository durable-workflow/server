#!/usr/bin/env bash
# The release identity of a native image (Server #325), from its
# dev.durable-workflow.release.tag label.
#   expect <label>            prints the release version the label admits, or
#                             nothing for an unlabelled development image;
#                             refuses any other label.
#   check <label> <version>   refuses a release image whose server reports a
#                             version other than its label's.
# A release image starts without DW_RUST_EXPERIMENTAL, so the label alone
# must not admit it: the version the binary reports has to match.
set -euo pipefail
fail() { printf 'Native release identity failed: %s\n' "$1" >&2; exit 1; }

expected() {
  local label=$1
  if [[ -z "$label" || "$label" == "<no value>" ]]; then
    return 0
  fi
  [[ "$label" =~ ^native-([0-9]+\.[0-9]+\.[0-9]+(-rc\.[0-9]+)?)$ ]] \
    || fail "release tag label '$label' is not native-<X.Y.Z> or native-<X.Y.Z>-rc.<N>."
  printf '%s\n' "${BASH_REMATCH[1]}"
}

case "${1:-}" in
  expect)
    test "$#" = 2 || fail "usage: $0 expect <label>"
    expected "$2"
    ;;
  check)
    test "$#" = 3 || fail "usage: $0 check <label> <reported version>"
    want=$(expected "$2")
    if [[ -n "$want" && "$3" != "$want" ]]; then
      fail "the image is labelled $2 but its server reports version '$3'."
    fi
    ;;
  *) fail "usage: $0 expect <label> | check <label> <reported version>" ;;
esac
