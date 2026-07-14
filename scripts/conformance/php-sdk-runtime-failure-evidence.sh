#!/usr/bin/env bash

readonly PHP_SDK_SERVER_FAILURE_PATTERN='"classification":"server"|serverexception|status[_ ]?code[^0-9]*[45][0-9][0-9]|http/[0-9.]+ [45][0-9][0-9]|http (response )?[45][0-9][0-9]|server[_ -]error'
readonly PHP_SDK_RUNNER_FAILURE_PATTERN='"classification":"runner"|transportexception|connection refused|could not resolve|name or service not known|network is unreachable|connection timed out|curl error'

runtime_failure_pattern() {
  case "${1:-}" in
    server)
      printf '%s\n' "$PHP_SDK_SERVER_FAILURE_PATTERN"
      ;;
    runner)
      printf '%s\n' "$PHP_SDK_RUNNER_FAILURE_PATTERN"
      ;;
    *)
      return 0
      ;;
  esac
}

runtime_failure_match_excerpt() {
  local source_file="${1:?source file is required}"
  local classification="${2:?classification is required}"
  local pattern
  pattern="$(runtime_failure_pattern "$classification")"
  if [[ -z "$pattern" ]]; then
    return
  fi

  LC_ALL=C awk -v pattern="$pattern" '
    {
      line = tolower($0)
      marker = index(line, "dw_php_sdk_runtime_failure=")
      if (marker > 0) {
        print substr($0, marker, 6144)
        exit
      }
      if (match(line, pattern)) {
        start = RSTART > 256 ? RSTART - 256 : 1
        excerpt = substr($0, start, 1024)
        if (start > 1) {
          excerpt = "..." excerpt
        }
        if (start + 1024 <= length($0)) {
          excerpt = excerpt "..."
        }
        print excerpt
        exit
      }
    }
  ' "$source_file"
}

capture_runtime_diagnostic() {
  local stdout_file="${1:-}"
  local stderr_file="${2:-}"
  local diagnostic_file="${3:?diagnostic file is required}"
  local classification="${4:-sdk}"
  local unmatched_tail_bytes=3800
  local matched_tail_bytes=1500

  : > "$diagnostic_file"
  for stream in stdout stderr; do
    local source_file=""
    if [[ "$stream" == stdout ]]; then
      source_file="$stdout_file"
    else
      source_file="$stderr_file"
    fi
    if [[ -n "$source_file" && -s "$source_file" ]]; then
      printf '[%s: %s]\n' "$stream" "${source_file##*/}" >> "$diagnostic_file"
      local matched_excerpt
      matched_excerpt="$(runtime_failure_match_excerpt "$source_file" "$classification")"
      if [[ -n "$matched_excerpt" ]]; then
        printf '[matched %s failure]\n%s\n[tail]\n' \
          "$classification" "$matched_excerpt" >> "$diagnostic_file"
        tail -c "$matched_tail_bytes" "$source_file" >> "$diagnostic_file"
      else
        tail -c "$unmatched_tail_bytes" "$source_file" >> "$diagnostic_file"
      fi
      printf '\n' >> "$diagnostic_file"
    fi
  done

  if [[ ! -s "$diagnostic_file" ]]; then
    printf '%s\n' 'The process exited without writing stdout or stderr.' > "$diagnostic_file"
  fi
}

classify_runtime_failure() {
  local log_file
  for log_file in "$@"; do
    if [[ -f "$log_file" ]] && grep -Eqi "$PHP_SDK_SERVER_FAILURE_PATTERN" "$log_file"; then
      printf '%s\n' server
      return
    fi
  done
  for log_file in "$@"; do
    if [[ -f "$log_file" ]] && grep -Eqi "$PHP_SDK_RUNNER_FAILURE_PATTERN" "$log_file"; then
      printf '%s\n' runner
      return
    fi
  done
  printf '%s\n' sdk
}
