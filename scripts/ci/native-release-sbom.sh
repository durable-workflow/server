#!/usr/bin/env bash
# Generates the SPDX 2.3 SBOM for one smoked native image platform (Server
# #1012). The publish job calls it with the exact digest each build job
# pushed and smoked, never a rebuild or a mutable tag, before it tags
# anything. It then attests the result to the release index in both
# registries, and
# native-release-github-release.sh verifies those attestations before it
# attaches the documents to the GitHub Release.
#
# The image is exported from the local daemon with docker save and scanned
# offline by the pinned Syft image for the runner's architecture, read from
# scripts/ci/native-sbom/tool-pins.v1.json. The scanner gets no network, a
# read-only root and input, no capabilities and bounded resources.
#
# usage: native-release-sbom.sh IMAGE@sha256:DIGEST PLATFORM VERSION OUT_DIR
# Writes OUT_DIR/server-linux-<arch>.spdx.json. The pull-request image
# workflow sets NATIVE_SBOM_LOCAL_IMAGE=true to run the same generator on its
# locally loaded dw-native-...:smoke image, which has no registry digest.
set -euo pipefail
fail() { printf 'native-release-sbom: %s\n' "$1" >&2; exit 2; }

[[ "$#" = 4 ]] || fail "usage: $0 IMAGE@sha256:DIGEST PLATFORM VERSION OUT_DIR"
image=$1 platform=$2 version=$3 out=$4
if [[ ${NATIVE_SBOM_LOCAL_IMAGE:-} == true ]]; then
  [[ $image =~ ^dw-native-[a-z0-9-]+:smoke$ ]] || fail "a local image must be a dw-native-...:smoke tag, not $image."
  docker image inspect "$image" >/dev/null 2>&1 || fail "local image $image is not loaded."
else
  [[ $image =~ ^[a-z0-9./-]+@sha256:[0-9a-f]{64}$ ]] || fail "name the image by digest, not $image."
fi
case $platform in
  linux/amd64 | linux/arm64) ;;
  *) fail "unsupported platform $platform." ;;
esac
[[ $version =~ ^[0-9]+\.[0-9]+\.[0-9]+(-rc\.[0-9]+)?$ ]] || fail "VERSION must be X.Y.Z or X.Y.Z-rc.N, not $version."
arch=${platform#linux/}

pins="$(dirname "${BASH_SOURCE[0]}")/native-sbom/tool-pins.v1.json"
# Syft only reads the image, so the scanner runs for the runner's own
# architecture; the amd64 publish job scans the arm64 image too.
host=linux/$(docker version --format '{{.Server.Arch}}')
case $host in
  linux/amd64 | linux/arm64) ;;
  *) fail "no pinned Syft image for a $host runner." ;;
esac
scanner_child=$(jq -er --arg p "$host" '.generator.platforms[$p].child_manifest_digest' "$pins")
scanner_version=$(jq -er '.generator.version' "$pins")
[[ $(jq -er '.generator.image' "$pins") == docker.io/anchore/syft && $scanner_child =~ ^sha256:[0-9a-f]{64}$ ]] \
  || fail "the Syft pin in $pins is not an exact digest."
scanner=docker.io/anchore/syft@$scanner_child

# The scanner container's name derives from RESOURCE_SCOPE, so the job's
# cleanup step can remove it.
if [[ -z "${RESOURCE_SCOPE:-}" ]]; then
  RESOURCE_SCOPE="dw-native-${GITHUB_RUN_ID:-local}-${GITHUB_RUN_ATTEMPT:-1}-${GITHUB_JOB:-sbom}-$$"
fi

# The smoke already pulled this digest; pull it again only if it is gone.
docker image inspect "$image" >/dev/null 2>&1 || docker pull --platform "$platform" "$image" >/dev/null
observed=$(docker image inspect --format '{{.Os}}/{{.Architecture}}' "$image")
[[ $observed == "$platform" ]] || fail "$image is $observed, not $platform."
docker pull --quiet --platform "$host" "$scanner" >/dev/null

mkdir -p "$out"
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
docker save --output "$work/image.tar" "$image"
chmod 0644 "$work/image.tar"
document="$out/server-linux-$arch.spdx.json"
name="durable-workflow-server-linux-$arch"

docker run --rm --init --name "$RESOURCE_SCOPE-syft" --platform "$host" --pull=never \
  --network=none --read-only --cap-drop=ALL --security-opt=no-new-privileges \
  --user "$(id -u):$(id -g)" \
  --tmpfs "/scratch:rw,noexec,nosuid,nodev,size=512m,mode=0700,uid=$(id -u),gid=$(id -g)" \
  --env HOME=/scratch --env TMPDIR=/scratch --env SYFT_CHECK_FOR_APP_UPDATE=false \
  --cpus=2 --memory=2g --memory-swap=2g --pids-limit=128 \
  --mount "type=bind,src=$work/image.tar,dst=/input/image.tar,readonly" \
  "$scanner" scan docker-archive:/input/image.tar --quiet \
  --source-name "$name" --source-version "$version" -o spdx-json@2.3 > "$work/sbom.json"

# Refuse an empty or mislabelled document before anything attests it. The
# root package's purl carries the architecture Syft read from the image.
size=$(stat -c %s "$work/sbom.json")
((size > 0 && size <= 16 * 1024 * 1024)) || fail "the SBOM is $size bytes; attest-sbom accepts 1 byte to 16 MiB."
jq -e --arg name "$name" --arg version "$version" --arg arch "$arch" --arg tool "Tool: syft-$scanner_version" '
    .spdxVersion == "SPDX-2.3" and .name == $name
    and (.creationInfo.creators | index($tool) != null)
    and ([.packages[] | select(.primaryPackagePurpose == "CONTAINER")] as $root
         | ($root | length) == 1 and $root[0].name == $name and $root[0].versionInfo == $version
           and any($root[0].externalRefs[]?; .referenceType == "purl"
                   and (.referenceLocator | test("[?&]arch=" + $arch + "($|&)"))))
    and ([.packages[] | select(.primaryPackagePurpose != "CONTAINER")] | length) > 0' \
  "$work/sbom.json" >/dev/null || fail "Syft did not produce the expected SPDX 2.3 document for $platform."
mv "$work/sbom.json" "$document"
printf '%s: %s packages from %s\n' "$document" "$(jq '.packages | length' "$document")" "$image"
