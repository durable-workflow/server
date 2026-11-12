#!/usr/bin/env bash
# Scans one native image platform digest with the pinned Trivy and writes a
# compact receipt (server#802). Exits 1 when a HIGH or CRITICAL row has a
# fixed version, which holds promotion; unfixed rows are inventory only.
#
# usage: native-image-scan.sh IMAGE@sha256:DIGEST PLATFORM OUT_DIR
set -euo pipefail

image=${1:?image by digest}
platform=${2:?platform}
out=${3:?output directory}

# Trivy 0.76.1's multi-architecture index. Its linux/amd64 manifest is
# sha256:2e6c00d711ab…, the one Security scanned with on server#802.
scanner=docker.io/aquasec/trivy@sha256:790fd66d1697395d8693d1acaa1ea702406a20f109b6fc7bac8db623eca6c06c

if [[ ! $image =~ ^[a-z0-9./-]+@sha256:[0-9a-f]{64}$ ]]; then
  echo "native-image-scan: name the image by digest, not $image" >&2
  exit 2
fi
case $platform in
  linux/amd64 | linux/arm64) ;;
  *) echo "native-image-scan: unsupported platform $platform" >&2; exit 2 ;;
esac

# The scanner container's name derives from RESOURCE_SCOPE, so the job's
# cleanup step can remove it.
scope_identity="${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-native-scan}"
if test -z "${RESOURCE_SCOPE:-}"; then
  RESOURCE_SCOPE="dw-native-$scope_identity-$(date -u +%Y%m%d%H%M%S)-$$"
fi

mkdir -p "$out"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/cache" "$work/report"
name=scan-${platform#linux/}

trivy() {
  docker run --rm --name "$RESOURCE_SCOPE-trivy" -u "$(id -u):$(id -g)" \
    -v "$work/cache:/cache" -v "$work/report:/report" "$scanner" "$@"
}

# Read the image from the registry for the one platform; nothing runs it.
trivy image --cache-dir /cache --image-src remote --platform "$platform" \
  --scanners vuln --severity HIGH,CRITICAL --format json --output /report/trivy.json --quiet "$image"
trivy version --cache-dir /cache --format json > "$work/report/version.json"

python3 -I "$(dirname "$0")/native_scan_receipt.py" "$(cat "$work/report/version.json")" \
  "$image" "$platform" "$scanner" < "$work/report/trivy.json" > "$out/$name.json"
