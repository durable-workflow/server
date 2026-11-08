#!/usr/bin/env bash
# A job-scoped, read-only Composer dist cache for the locked published-SDK
# fixture installs of the parity profiles (Server #1093).
#
# Each profile used to download every pinned dist from GitHub again in its
# own single-attempt named container, so one transient zipball 504 failed the
# profile. Here the first profile in a job warms one cache with an ordinary
# locked install through run-composer.sh (its existing transient retry), and
# records the exact lock, every cached file's SHA-256 and the installed vendor
# tree. Every profile then installs from a verified, read-only copy of those
# bytes with no network, and must reproduce the recorded vendor tree.
#
# Nothing is restored from another job or a global cache: the root lives in
# this job's workspace. The lock, the profiles, the trust context and the
# post-install lock check are unchanged.
#
# Usage:
#   sdk-composer-cache.sh warm LOCK_DIR
#   sdk-composer-cache.sh install LOCK_DIR [docker run options...]
# Exit status of install: 0 installed from the cache; 3 the cache is not
# usable (the caller runs its unchanged authenticated install instead);
# 4 a cache-backed install did not reproduce the recorded vendor tree (refuse).
set -euo pipefail

here=$(dirname "$0")
command=${1:?warm or install}
lock_dir=${2:?lock directory}
lock_sha=$(sha256sum < "$lock_dir/composer.lock" | cut -d' ' -f1)
json_sha=$(sha256sum < "$lock_dir/composer.json" | cut -d' ' -f1)
# Keyed by the full lock and manifest, so a different lock never reuses it.
root="$PWD/parity-composer-cache-$lock_sha-$json_sha"
home_config='{"config":{"cache-read-only":true,"cache-dir":"/composer-cache"}}'

# The cached files, as sha256sum lists them, in byte order.
listing() { (cd "$1" && find . -type f -print0 | LC_ALL=C sort -z | xargs -0 -r sha256sum); }

verified() {
    test -d "$root" && ! test -L "$root" || return 1
    test "$(cat "$root/manifest/lock" 2>/dev/null)" = "$lock_sha $json_sha" || return 1
    test "$(cat "$root/home/config.json" 2>/dev/null)" = "$home_config" || return 1
    test -s "$root/manifest/files" && test -s "$root/manifest/vendor" || return 1
    listing "$root/cache" | cmp -s - "$root/manifest/files"
}

case "$command" in
    warm)
        exec 9> "$root.lock"
        flock 9
        if verified; then exit 0; fi
        # An unverifiable existing root is never repaired or reused.
        if test -e "$root"; then
            echo "sdk-composer-cache: $root exists but does not verify; not using it" >&2
            exit 3
        fi
        work=$(mktemp -d "$root.tmp.XXXXXX")
        trap 'rm -rf -- "$work"' EXIT
        mkdir "$work/project" "$work/cache" "$work/home" "$work/manifest"
        cp "$lock_dir/composer.json" "$lock_dir/composer.lock" "$work/project/"
        chmod 0777 "$work/project" "$work/cache"
        chmod 0666 "$work/project/composer.json" "$work/project/composer.lock"
        bash scripts/ci/run-composer.sh --rm -v "$work/project:/sdk" \
            -v "$work/cache:/composer-cache" -e COMPOSER_CACHE_DIR=/composer-cache -w /sdk -- \
            install --no-interaction --no-progress
        printf '%s\n' "$home_config" > "$work/home/config.json"
        printf '%s %s\n' "$lock_sha" "$json_sha" > "$work/manifest/lock"
        listing "$work/cache" > "$work/manifest/files"
        listing "$work/project/vendor" > "$work/manifest/vendor"
        rm -rf "$work/project"
        chmod -R a-w "$work/cache" "$work/home" "$work/manifest"
        mv "$work" "$root"
        trap - EXIT
        verified
        ;;
    install)
        shift 2
        if ! bash "$here/sdk-composer-cache.sh" warm "$lock_dir" || ! verified; then
            echo "sdk-composer-cache: no verified cache; use the authenticated install" >&2
            exit 3
        fi
        # No network: every dist must come from the verified bytes. The
        # container removes itself, so no cleanup step owns a new name.
        if ! bash scripts/ci/run-composer.sh --rm "$@" --network none -v "$lock_dir:/sdk" \
            -v "$root/cache:/composer-cache:ro" -v "$root/home:/composer-home:ro" \
            -e COMPOSER_HOME=/composer-home -w /sdk -- install --no-interaction --no-progress; then
            echo "sdk-composer-cache: cache-backed install failed; use the authenticated install" >&2
            exit 3
        fi
        if ! listing "$lock_dir/vendor" | cmp -s - "$root/manifest/vendor"; then
            echo "sdk-composer-cache: installed vendor tree differs from the recorded authenticated install" >&2
            exit 4
        fi
        ;;
    *)
        echo 'Usage: sdk-composer-cache.sh warm LOCK_DIR | install LOCK_DIR [docker run options...]' >&2
        exit 2
        ;;
esac
