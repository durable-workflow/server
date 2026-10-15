#!/usr/bin/env sh
set -eu

release_tag=${RELEASE_TAG:?RELEASE_TAG is required}
docker_bin=${DOCKER:-docker}
dockerhub_image=${DOCKERHUB_IMAGE:-durableworkflow/server}
ghcr_image=${GHCR_IMAGE:-ghcr.io/durable-workflow/server}
tmp_dir=$(mktemp -d)
trap 'test ! -d "$tmp_dir" || rm -r -- "$tmp_dir"' EXIT HUP INT TERM

for image in "$dockerhub_image" "$ghcr_image"; do
    ref="$image:$release_tag"
    if "$docker_bin" manifest inspect "$ref" > "$tmp_dir/manifest" 2> "$tmp_dir/error"; then
        printf 'Refusing to rebuild published release image %s. Use published-release-recovery.yml to verify existing artifacts; reconcile an incomplete publication without replacing its images.\n' "$ref" >&2
        exit 1
    fi

    # Only an explicit registry absence permits publication. Authentication,
    # rate limits and unavailable registries must never authorize a rebuild.
    error=$(cat "$tmp_dir/error")
    case "$error" in
        'manifest unknown'|'no such manifest: '"$ref"|'no such manifest: docker.io/'"$ref") ;;
        *)
            printf 'Cannot establish that release image %s is absent. Publication remains stopped.\n' "$ref" >&2
            exit 1
            ;;
    esac
done

printf 'Both exact release images are absent; initial publication may proceed.\n'
