#!/usr/bin/env bash
# The one recognized Buildx not-found diagnostic, for the exact requested tag.
# This is a fail-closed client-format contract, not a substring or HTTP parser.
# Unknown formats, multiple lines and all auth/helper/transport failures refuse.
native_registry_subject_missing() {
  local expected_ref=$1 diagnostic=$2
  [[ ${#expected_ref} -le 512 && "$expected_ref" =~ ^[a-z0-9][a-z0-9./_-]*:[A-Za-z0-9_.-]+$ ]] || return 1
  [[ "$diagnostic" == "ERROR: $expected_ref: not found" ]]
}
