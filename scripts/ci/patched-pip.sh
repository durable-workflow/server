#!/bin/sh
# Runs pip from a fresh venv that first takes the hash-pinned pip 26.2.2
# (GHSA-6q48-6c7w-9gw2), for containers whose own pip is unpatched or not
# writable by the build user. Arguments go to pip unchanged.
#
# Usage: patched-pip.sh install ARGS...
set -eu

here=$(dirname "$0")
venv="$(mktemp -d)/pip"
python3 -m venv "$venv"
sh "$here/bootstrap-patched-pip.sh" "$venv/bin/python" >&2
exec "$venv/bin/python" -m pip "$@"
