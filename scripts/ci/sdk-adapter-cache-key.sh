#!/usr/bin/env bash
# Print the exact cache key for the published SDK adapters' public Cargo
# registry data and build tree (Shared Server fixtures, Server #325), and
# write it to $GITHUB_OUTPUT as `key` when that is set.
#
# The key names the runner OS and architecture, every pinned Rust image the
# adapter builds use and a digest of every tracked adapter input: the five
# adapter crates, their build scripts, the pip wrapper and the SDK profiles.
# It is an exact key with no restore prefix. Only pushes to main save it.
set -euo pipefail
: "${RUNNER_OS:?RUNNER_OS must be set}" "${RUNNER_ARCH:?RUNNER_ARCH must be set}"

parity=scripts/conformance/server-parity
profiles=tests/Fixtures/ServerParityProfiles
mapfile -t inputs < <(git ls-files -- \
  "$parity/published-sdk-patch" \
  "$parity/sdk-directions" \
  "$parity/legacy-marker-aliases" \
  "$parity/corrected-markers" \
  "$parity/query-authority" \
  ":(glob)$parity/*-adapters.sh" \
  scripts/ci/patched-pip.sh \
  "$profiles/published-sdk-patch-replay" \
  "$profiles/published-sdk-directions" \
  "$profiles/legacy-marker-aliases" \
  "$profiles/corrected-markers" \
  "$profiles/query-authority" \
  "$profiles/version-range-repair" | LC_ALL=C sort)

# Never a partial key: all five adapter crates and build scripts must be
# inputs. A sixth adapter must join the list above.
test "$(printf '%s\n' "${inputs[@]}" | grep -c '/rust/Cargo\.lock$')" -eq 5
test "$(printf '%s\n' "${inputs[@]}" | grep -c -- '-adapters\.sh$')" -eq 5

# The key names every pinned Rust image the inputs build with: the build
# scripts pin one and the query-authority profile another. The input digest
# below covers them too; naming them keeps a toolchain change readable.
mapfile -t toolchains < <(grep -ho 'library/rust@sha256:[0-9a-f]\{64\}' -- "${inputs[@]}" | sed 's/.*@sha256://' | LC_ALL=C sort -u)
test "${#toolchains[@]}" -ge 1
rust=$(IFS=-; printf '%s' "${toolchains[*]}")

digest=$(sha256sum -- "${inputs[@]}" | sha256sum | cut -c1-64)
key="sdk-adapter-cargo-v2-$RUNNER_OS-$RUNNER_ARCH-rust-$rust-inputs-$digest"
if test -n "${GITHUB_OUTPUT:-}"; then
  printf 'key=%s\n' "$key" >> "$GITHUB_OUTPUT"
fi
printf '%s\n' "$key"
