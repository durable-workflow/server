#!/usr/bin/env bash
# Both local exit traps and the owning Action's always-run cleanup call this.
set -euo pipefail
scope_identity="${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-codec}"
test -n "${RESOURCE_SCOPE:?Set the owning isolated resource scope}"
case "$RESOURCE_SCOPE" in *"$scope_identity"*) ;; *) exit 2 ;; esac
scope="$RESOURCE_SCOPE"
build_container="$RESOURCE_SCOPE-build"
native_server="$scope-native"
db="$scope-db"
probe="$scope-ready"
observer="$RESOURCE_SCOPE-native-codec-observer"
case "${1:?Expected build, observer or cell}" in
  build)
    docker rm -f "$build_container" >/dev/null 2>&1 || true
    ;;
  observer)
    docker rm -f "$observer" >/dev/null 2>&1 || true
    ;;
  cell)
    docker rm -f "$observer" "$probe" "$native_server" >/dev/null 2>&1 || true
    docker stop --time 10 "$db" >/dev/null 2>&1 || true
    docker rm "$db" >/dev/null 2>&1 || true
    docker network rm "$scope" >/dev/null 2>&1 || true
    ;;
  *) exit 2 ;;
esac
