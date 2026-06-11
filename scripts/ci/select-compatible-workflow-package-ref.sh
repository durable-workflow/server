#!/usr/bin/env sh

set -eu

workflow_source="${WORKFLOW_PACKAGE_SOURCE:-https://github.com/durable-workflow/workflow.git}"
server_protocol_file="${SERVER_WORKER_PROTOCOL_FILE:-app/Support/WorkerProtocol.php}"
workflow_protocol_file="${WORKFLOW_WORKER_PROTOCOL_FILE:-src/V2/Support/WorkerProtocolVersion.php}"
tmp_dir=""

cleanup() {
    if [ -n "$tmp_dir" ]; then
        rm -rf "$tmp_dir"
    fi
}

trap cleanup EXIT HUP INT TERM

fail() {
    title="$1"
    message="$2"

    if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
        {
            printf '## %s\n\n' "$title"
            printf '%s\n' "$message"
        } >> "$GITHUB_STEP_SUMMARY"
    fi

    printf '::error title=%s::%s\n' "$title" "$message" >&2
    printf '%s\n' "$message" >&2
    exit 1
}

write_output() {
    if [ -n "${GITHUB_OUTPUT:-}" ]; then
        printf '%s=%s\n' "$1" "$2" >> "$GITHUB_OUTPUT"
    fi
}

php_const_version() {
    sed -n "s/.*public const VERSION = ['\"]\([^'\"]*\)['\"].*/\1/p" "$1" | head -n 1
}

normalize_tag_lines() {
    while IFS= read -r line; do
        case "$line" in
            *refs/tags/*)
                line="${line##*refs/tags/}"
                ;;
        esac

        line="${line%\^\{\}}"

        if [ -n "$line" ]; then
            printf '%s\n' "$line"
        fi
    done
}

collect_prerelease_tags() {
    if [ -n "${WORKFLOW_PACKAGE_KNOWN_TAGS:-}" ]; then
        printf '%s\n' "$WORKFLOW_PACKAGE_KNOWN_TAGS" | normalize_tag_lines
    else
        if ! raw_tags="$(git ls-remote --tags --refs "$workflow_source" 2>/tmp/workflow-package-tags.err)"; then
            error_detail="$(cat /tmp/workflow-package-tags.err 2>/dev/null || true)"
            fail "Workflow package tags unavailable" "Cannot list durable-workflow/workflow tags from ${workflow_source}. ${error_detail}"
        fi

        printf '%s\n' "$raw_tags" | normalize_tag_lines
    fi | while IFS= read -r tag; do
        if printf '%s' "$tag" | grep -Eq '^2\.0\.0-(alpha|beta)\.[0-9]+$'; then
            printf '%s\n' "$tag"
        fi
    done | sort -u -V
}

mapped_protocol_version() {
    tag="$1"

    if [ -z "${WORKFLOW_PACKAGE_PROTOCOL_VERSIONS:-}" ]; then
        return 0
    fi

    printf '%s\n' "$WORKFLOW_PACKAGE_PROTOCOL_VERSIONS" | awk -v tag="$tag" '
        BEGIN { FS = "[=[:space:]]+" }
        $1 == tag && $2 != "" { print $2; exit }
    '
}

mapped_tag_commit() {
    tag="$1"

    if [ -z "${WORKFLOW_PACKAGE_TAG_COMMITS:-}" ]; then
        return 0
    fi

    printf '%s\n' "$WORKFLOW_PACKAGE_TAG_COMMITS" | awk -v tag="$tag" '
        BEGIN { FS = "[=[:space:]]+" }
        $1 == tag && $2 != "" { print $2; exit }
    '
}

ensure_git_workdir() {
    if [ -n "$tmp_dir" ]; then
        return
    fi

    tmp_dir="$(mktemp -d)"
    git -C "$tmp_dir" init -q
    git -C "$tmp_dir" remote add origin "$workflow_source"
}

ensure_tag_fetched() {
    tag="$1"

    ensure_git_workdir

    if git -C "$tmp_dir" rev-parse -q --verify "refs/tags/${tag}" >/dev/null 2>&1; then
        return
    fi

    if ! git -C "$tmp_dir" fetch -q --depth=1 origin "refs/tags/${tag}:refs/tags/${tag}" 2>/tmp/workflow-package-fetch.err; then
        error_detail="$(cat /tmp/workflow-package-fetch.err 2>/dev/null || true)"
        fail "Workflow package tag unavailable" "Cannot fetch durable-workflow/workflow tag ${tag} from ${workflow_source}. ${error_detail}"
    fi
}

fetch_protocol_version() {
    tag="$1"
    mapped="$(mapped_protocol_version "$tag")"

    if [ -n "$mapped" ]; then
        printf '%s\n' "$mapped"
        return
    fi

    ensure_tag_fetched "$tag"

    if ! source_file="$(git -C "$tmp_dir" show "${tag}:${workflow_protocol_file}" 2>/tmp/workflow-package-protocol.err)"; then
        error_detail="$(cat /tmp/workflow-package-protocol.err 2>/dev/null || true)"
        fail "Workflow package protocol unavailable" "Cannot read ${workflow_protocol_file} from durable-workflow/workflow tag ${tag}. ${error_detail}"
    fi

    version="$(printf '%s\n' "$source_file" | sed -n "s/.*public const VERSION = ['\"]\([^'\"]*\)['\"].*/\1/p" | head -n 1)"

    if [ -z "$version" ]; then
        fail "Workflow package protocol unavailable" "Cannot determine worker protocol version from ${workflow_protocol_file} in durable-workflow/workflow tag ${tag}."
    fi

    printf '%s\n' "$version"
}

fetch_tag_commit() {
    tag="$1"
    mapped="$(mapped_tag_commit "$tag")"

    if [ -n "$mapped" ]; then
        printf '%s\n' "$mapped"
        return
    fi

    ensure_tag_fetched "$tag"

    if ! commit="$(git -C "$tmp_dir" rev-parse "${tag}^{commit}" 2>/tmp/workflow-package-commit.err)"; then
        error_detail="$(cat /tmp/workflow-package-commit.err 2>/dev/null || true)"
        fail "Workflow package commit unavailable" "Cannot resolve commit for durable-workflow/workflow tag ${tag}. ${error_detail}"
    fi

    printf '%s\n' "$commit"
}

if [ ! -f "$server_protocol_file" ]; then
    fail "Server worker protocol unavailable" "Cannot read server worker protocol file ${server_protocol_file}."
fi

server_protocol="$(php_const_version "$server_protocol_file")"

if [ -z "$server_protocol" ]; then
    fail "Server worker protocol unavailable" "Cannot determine App\\Support\\WorkerProtocol::VERSION from ${server_protocol_file}."
fi

tags="$(collect_prerelease_tags)"

if [ -z "$tags" ]; then
    fail "Workflow package tags unavailable" "No numeric 2.0.0 alpha or beta durable-workflow/workflow prerelease tags were found in ${workflow_source}."
fi

selected_tag=""
selected_protocol=""
checked=""

for tag in $(printf '%s\n' "$tags" | sort -r -V); do
    workflow_protocol="$(fetch_protocol_version "$tag")"

    if [ "$workflow_protocol" = "$server_protocol" ]; then
        selected_tag="$tag"
        selected_protocol="$workflow_protocol"
        break
    fi

    if [ -z "$checked" ]; then
        checked="${tag} advertises worker protocol ${workflow_protocol}"
    else
        checked="${checked}; ${tag} advertises worker protocol ${workflow_protocol}"
    fi
done

if [ -z "$selected_tag" ]; then
    fail "Compatible workflow package unavailable" "No compatible durable-workflow/workflow prerelease tag found for server worker protocol ${server_protocol}. Checked: ${checked}."
fi

selected_commit="$(fetch_tag_commit "$selected_tag")"

write_output "tag" "$selected_tag"
write_output "protocol" "$selected_protocol"
write_output "server_protocol" "$server_protocol"
write_output "commit" "$selected_commit"

printf 'Using workflow package version: %s (worker protocol %s, server requires %s) at commit %s\n' "$selected_tag" "$selected_protocol" "$server_protocol" "$selected_commit"
