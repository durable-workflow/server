#!/usr/bin/env sh
set -eu

if [ "${DB_CONNECTION:-sqlite}" != "sqlite" ]; then
    exit 0
fi

database="${DB_DATABASE:-/app/database/database.sqlite}"

if [ "$database" = ":memory:" ]; then
    exit 0
fi

case "$database" in
    /*) path="$database" ;;
    *) path="/app/$database" ;;
esac

fail() {
    printf '%s\n' "SQLite bootstrap: $*" >&2
    exit 1
}

# Apache drops root before serving requests. Create and check the database as
# that same identity, without changing an existing operator-owned file.
if [ "$(id -u)" -eq 0 ]; then
    runtime_user="${APACHE_RUN_USER:-www-data}"
    runtime_group="${APACHE_RUN_GROUP:-www-data}"
    runtime_user="${runtime_user#\#}"
    runtime_group="${runtime_group#\#}"
    runtime_uid="$(getent passwd -- "$runtime_user" | cut -d: -f3)"
    supplementary_groups="--init-groups"
    if [ -z "$runtime_uid" ]; then
        case "$runtime_user" in
            ''|*[!0-9]*) fail "APACHE_RUN_USER must identify a local user or numeric UID." ;;
            *) runtime_uid="$runtime_user" ;;
        esac
        supplementary_groups="--clear-groups"
    fi
    runtime_gid="$(getent group -- "$runtime_group" | cut -d: -f3)"
    if [ -z "$runtime_gid" ]; then
        case "$runtime_group" in
            ''|*[!0-9]*) fail "APACHE_RUN_GROUP must identify a local group or numeric GID." ;;
            *) runtime_gid="$runtime_group" ;;
        esac
    fi
    [ "$runtime_uid" -ne 0 ] || fail "Configure a nonroot APACHE_RUN_USER for the HTTP runtime."
    exec setpriv --reuid "$runtime_uid" --regid "$runtime_gid" "$supplementary_groups" \
        -- /bin/sh "$0"
fi

identity="UID $(id -u), GID $(id -g)"
resolved_path="$(readlink -m "$path")" || fail "Cannot resolve '$path' as $identity."
path="$resolved_path"
directory="$(dirname "$path")"
mkdir -p "$directory" || fail "Cannot create parent for '$path' as $identity; align the database mount with the HTTP runtime identity."
[ -w "$directory" ] && [ -x "$directory" ] || \
    fail "Parent for '$path' must be writable and searchable by $identity; align the database mount with the HTTP runtime identity."

if [ ! -e "$path" ] && [ ! -L "$path" ]; then
    # Noclobber protects a database created concurrently by another role.
    if ! (set -C; : > "$path") 2>/dev/null; then
        [ -f "$path" ] || fail "Cannot create '$path' as $identity; check the database mount permissions."
    fi
fi

[ -f "$path" ] && [ -w "$path" ] || \
    fail "Database '$path' must be a writable regular file for $identity; preserve its data and correct the mount permissions before starting."
