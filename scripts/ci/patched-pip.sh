#!/bin/sh
# Runs pip from a fresh venv that first takes the hash-pinned pip 26.2.2
# (GHSA-6q48-6c7w-9gw2), for containers whose own pip is unpatched or not
# writable by the build user. Arguments go to pip unchanged, pip's exit
# status is kept, and the temporary venv is removed afterwards.
#
# Usage: patched-pip.sh install ARGS...
set -eu

here=$(dirname "$0")
work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
trap 'exit 129' HUP
trap 'exit 130' INT
trap 'exit 143' TERM

python3 -m venv "$work/pip"
sh "$here/bootstrap-patched-pip.sh" "$work/pip/bin/python" >&2
status=0
"$work/pip/bin/python" -m pip "$@" || status=$?
exit "$status"
