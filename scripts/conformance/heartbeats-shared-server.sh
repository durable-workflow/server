#!/usr/bin/env bash
set -euo pipefail

if [[ $# -ne 2 || ( "$1" != "start" && "$1" != "stop" ) ]]; then
  printf '%s\n' 'usage: heartbeats-shared-server.sh <start|stop> <state-file>' >&2
  exit 2
fi

script_dir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
repo_root="$(cd "$script_dir/../.." && pwd)"

REPO_ROOT="$repo_root" node "$script_dir/heartbeats-shared-server.mjs" "$1" "$2"
