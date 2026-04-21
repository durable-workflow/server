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

mkdir -p "$(dirname "$path")"

if [ ! -e "$path" ]; then
    touch "$path"
fi
