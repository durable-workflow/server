#!/usr/bin/env bash
# The one recognized Buildx not-found diagnostic, for the exact requested tag.
# This is a fail-closed client-format contract, not a substring or HTTP parser.
# Unknown formats, multiple lines and all auth/helper/transport failures refuse.
native_registry_subject_missing() {
  local expected_ref=$1 diagnostic=$2
  [[ ${#expected_ref} -le 512 && "$expected_ref" =~ ^[a-z0-9][a-z0-9./_-]*:[A-Za-z0-9_.-]+$ ]] || return 1
  # Buildx prints Docker Hub's full name for this product's short reference.
  # Bind that one known spelling, without inferring other registry aliases.
  if [[ "$expected_ref" == durableworkflow/server:* ]]; then
    expected_ref="docker.io/$expected_ref"
  fi
  [[ "$diagnostic" == "ERROR: $expected_ref: not found" ]]
}
