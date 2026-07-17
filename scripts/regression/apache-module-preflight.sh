#!/usr/bin/env bash

set -euo pipefail

readonly APACHE_MODULE_PREFLIGHT_ATTEMPTS="${APACHE_MODULE_PREFLIGHT_ATTEMPTS:-5}"
readonly APACHE_MODULE_PREFLIGHT_RETRY_DELAY_SECONDS="${APACHE_MODULE_PREFLIGHT_RETRY_DELAY_SECONDS:-1}"
readonly APACHE_MODULE_DIAGNOSTIC_BYTES="${APACHE_MODULE_DIAGNOSTIC_BYTES:-4096}"

print_bounded_apache_module_output() {
  local stream_name="$1"
  local stream_file="$2"

  printf 'apache2ctl -M %s (last %d bytes):\n' \
    "$stream_name" "$APACHE_MODULE_DIAGNOSTIC_BYTES" >&2
  if [ -s "$stream_file" ]; then
    tail -c "$APACHE_MODULE_DIAGNOSTIC_BYTES" "$stream_file" >&2
    printf '\n' >&2
  else
    printf '<empty>\n' >&2
  fi
}

report_apache_module_preflight_failure() {
  local status="$1"
  local stdout_file="$2"
  local stderr_file="$3"

  printf 'apache2ctl -M status: %d\n' "$status" >&2
  print_bounded_apache_module_output stdout "$stdout_file"
  print_bounded_apache_module_output stderr "$stderr_file"
}

verify_apache_mod_php() {
  local attempt
  local status=0
  local stdout_file=""
  local stderr_file=""

  if [ "$#" -eq 0 ]; then
    printf 'Apache module preflight requires a command to run.\n' >&2
    return 64
  fi

  for attempt in $(seq 1 "$APACHE_MODULE_PREFLIGHT_ATTEMPTS"); do
    stdout_file="$(mktemp "${TMPDIR:-/tmp}/dw-apache-modules-stdout.XXXXXX")"
    stderr_file="$(mktemp "${TMPDIR:-/tmp}/dw-apache-modules-stderr.XXXXXX")"

    if "$@" >"$stdout_file" 2>"$stderr_file"; then
      status=0
    else
      status=$?
    fi

    if [ "$status" -eq 0 ]; then
      if grep -Eq '(^|[[:space:]])php_module([[:space:]]|$)' "$stdout_file"; then
        rm -f "$stdout_file" "$stderr_file"
        return 0
      fi

      printf 'Standalone Apache runtime did not load mod_php: the successful module listing omitted php_module.\n' >&2
      report_apache_module_preflight_failure "$status" "$stdout_file" "$stderr_file"
      rm -f "$stdout_file" "$stderr_file"
      return 1
    fi

    if [ "$attempt" -lt "$APACHE_MODULE_PREFLIGHT_ATTEMPTS" ]; then
      printf 'apache2ctl -M execution failed with status %d; retrying module preflight (%d/%d).\n' \
        "$status" "$attempt" "$APACHE_MODULE_PREFLIGHT_ATTEMPTS" >&2
      rm -f "$stdout_file" "$stderr_file"
      sleep "$APACHE_MODULE_PREFLIGHT_RETRY_DELAY_SECONDS"
    fi
  done

  printf 'Unable to inspect standalone Apache modules after %d attempts: execution or transport failed.\n' \
    "$APACHE_MODULE_PREFLIGHT_ATTEMPTS" >&2
  report_apache_module_preflight_failure "$status" "$stdout_file" "$stderr_file"
  rm -f "$stdout_file" "$stderr_file"
  return 1
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  verify_apache_mod_php "$@"
fi
