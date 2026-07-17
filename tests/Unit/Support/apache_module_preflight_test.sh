#!/usr/bin/env bash

set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
PREFLIGHT="$ROOT_DIR/scripts/regression/apache-module-preflight.sh"

if [ "${1:-}" = "mock-compose" ]; then
  scenario="$2"
  state_file="$3"
  shift 3

  expected=(exec -T server apache2ctl -M)
  actual=("$@")
  if [ "$#" -ne "${#expected[@]}" ]; then
    printf 'unexpected compose argument count: %d\n' "$#" >&2
    exit 99
  fi
  for index in "${!expected[@]}"; do
    if [ "${actual[$index]}" != "${expected[$index]}" ]; then
      printf 'unexpected compose argument %d: %s\n' "$index" "${actual[$index]}" >&2
      exit 99
    fi
  done

  invocation=0
  if [ -f "$state_file" ]; then
    invocation="$(<"$state_file")"
  fi
  invocation=$((invocation + 1))
  printf '%d\n' "$invocation" >"$state_file"

  case "$scenario" in
    retry-then-pass)
      if [ "$invocation" -eq 1 ]; then
        printf 'compose transport is not ready\n' >&2
        exit 125
      fi
      printf 'Loaded Modules:\n php_module (shared)\n'
      ;;
    missing-module)
      printf 'Loaded Modules:\n mpm_prefork_module (shared)\n'
      ;;
    repeated-execution-failure)
      printf 'stdout-start-%d-' "$invocation"
      printf 'S%.0s' {1..256}
      printf -- '-stdout-end-%d\n' "$invocation"
      printf 'stderr-start-%d-' "$invocation" >&2
      printf 'E%.0s' {1..256} >&2
      printf -- '-stderr-end-%d\n' "$invocation" >&2
      exit $((70 + invocation))
      ;;
    *)
      printf 'unknown mock scenario: %s\n' "$scenario" >&2
      exit 98
      ;;
  esac

  exit 0
fi

fail() {
  printf 'apache module preflight regression failed: %s\n' "$1" >&2
  exit 1
}

assert_contains() {
  local expected="$1"
  local file="$2"

  grep -Fq -- "$expected" "$file" || fail "expected output to contain: $expected"
}

assert_not_contains() {
  local unexpected="$1"
  local file="$2"

  if grep -Fq -- "$unexpected" "$file"; then
    fail "expected output to omit: $unexpected"
  fi
}

assert_invocations() {
  local expected="$1"
  local state_file="$2"

  [ "$(<"$state_file")" = "$expected" ] \
    || fail "expected $expected command invocations, got $(<"$state_file")"
}

run_preflight() {
  local scenario="$1"
  local state_file="$2"
  local stdout_file="$3"
  local stderr_file="$4"

  APACHE_MODULE_PREFLIGHT_ATTEMPTS=3 \
    APACHE_MODULE_PREFLIGHT_RETRY_DELAY_SECONDS=0 \
    APACHE_MODULE_DIAGNOSTIC_BYTES=128 \
    "$PREFLIGHT" "$0" mock-compose "$scenario" "$state_file" \
      exec -T server apache2ctl -M >"$stdout_file" 2>"$stderr_file"
}

tmp_dir="$(mktemp -d "${TMPDIR:-/tmp}/dw-apache-module-preflight-test.XXXXXX")"
trap 'rm -rf "$tmp_dir"' EXIT

retry_state="$tmp_dir/retry.state"
if ! run_preflight retry-then-pass "$retry_state" "$tmp_dir/retry.stdout" "$tmp_dir/retry.stderr"; then
  fail 'execution failure followed by php_module should pass'
fi
assert_invocations 2 "$retry_state"
assert_contains 'execution failed with status 125; retrying module preflight (1/3)' "$tmp_dir/retry.stderr"

missing_state="$tmp_dir/missing.state"
if run_preflight missing-module "$missing_state" "$tmp_dir/missing.stdout" "$tmp_dir/missing.stderr"; then
  fail 'successful module listing without php_module should fail'
fi
assert_invocations 1 "$missing_state"
assert_contains 'successful module listing omitted php_module' "$tmp_dir/missing.stderr"
assert_contains 'apache2ctl -M status: 0' "$tmp_dir/missing.stderr"
assert_contains 'mpm_prefork_module (shared)' "$tmp_dir/missing.stderr"

failure_state="$tmp_dir/failure.state"
if run_preflight repeated-execution-failure "$failure_state" "$tmp_dir/failure.stdout" "$tmp_dir/failure.stderr"; then
  fail 'repeated execution failures should exhaust the bounded attempts'
fi
assert_invocations 3 "$failure_state"
assert_contains 'after 3 attempts: execution or transport failed' "$tmp_dir/failure.stderr"
assert_contains 'apache2ctl -M status: 73' "$tmp_dir/failure.stderr"
assert_contains 'apache2ctl -M stdout (last 128 bytes)' "$tmp_dir/failure.stderr"
assert_contains 'apache2ctl -M stderr (last 128 bytes)' "$tmp_dir/failure.stderr"
assert_contains 'stdout-end-3' "$tmp_dir/failure.stderr"
assert_contains 'stderr-end-3' "$tmp_dir/failure.stderr"
assert_not_contains 'stdout-start-3' "$tmp_dir/failure.stderr"
assert_not_contains 'stderr-start-3' "$tmp_dir/failure.stderr"

printf 'Apache module preflight regression passed.\n'
