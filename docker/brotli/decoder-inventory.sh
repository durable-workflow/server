#!/bin/sh
# Refuses any libbrotlidec file or link under ROOT that does not resolve to
# LIBRARY, the reviewed libbrotli1 decoder: another copy, a dangling link, or
# a link (such as a stray libbrotlidec.so.1) to a differently named decoder.
# Usage: decoder-inventory.sh ROOT LIBRARY
set -eu
root=$1
library=$2
fail() { echo "$*" >&2; exit 1; }

{ [ -f "$library" ] && [ ! -L "$library" ]; } || fail "$library is not a regular file."
base=${root%/}
find "$root" -xdev \( -path "$base/proc" -o -path "$base/sys" \) -prune -o \
    \( -type f -o -type l \) -name 'libbrotlidec*' -print |
while IFS= read -r path; do
    target=$(readlink -f "$path") || fail "$path does not resolve."
    [ "$target" = "$library" ] || fail "$path resolves to $target, not $library."
done
