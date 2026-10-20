#!/usr/bin/env bash
# Print the failures in this job's Rust test output, so a failed test step is
# diagnosable from its own log. Reads parity-evidence; changes nothing.
set -uo pipefail
for output in parity-evidence/*tests*.txt; do
  [[ -f $output ]] && grep -q '^test result: FAILED' "$output" || continue
  echo "::group::Rust test failures in ${output#parity-evidence/}"
  grep -E -A3 'panicked at' "$output" | cut -c1-600 | head -n 120
  # The closing summary names every failed test.
  sed -n '/^failures:$/,$p' "$output" | grep -E '^failures:$|^    [A-Za-z0-9_:]+$|^test result:' | tail -n 60
  echo "::endgroup::"
done
exit 0
