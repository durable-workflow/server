#!/usr/bin/env bash

set -euo pipefail

readonly canonical_source='https://github.com/durable-workflow/workflow.git'

: "${WORKFLOW_PACKAGE_SOURCE:?WORKFLOW_PACKAGE_SOURCE is required}"
: "${WORKFLOW_PACKAGE_COMMIT:?WORKFLOW_PACKAGE_COMMIT is required}"

if [[ "$WORKFLOW_PACKAGE_SOURCE" != "$canonical_source" ]]; then
  printf 'Workflow package source %s does not match canonical source %s\n' \
    "$WORKFLOW_PACKAGE_SOURCE" \
    "$canonical_source" >&2
  exit 1
fi

if [[ ! "$WORKFLOW_PACKAGE_COMMIT" =~ ^[0-9a-f]{40}$ ]]; then
  printf 'Workflow package commit must be a full lowercase Git SHA: %s\n' \
    "$WORKFLOW_PACKAGE_COMMIT" >&2
  exit 1
fi

if [[ -e workflow-package ]]; then
  printf 'Refusing to replace existing workflow-package path\n' >&2
  exit 1
fi

git init --quiet workflow-package
git -C workflow-package remote add origin "$canonical_source"
GIT_CONFIG_GLOBAL=/dev/null \
GIT_CONFIG_NOSYSTEM=1 \
GIT_TERMINAL_PROMPT=0 \
GIT_ASKPASS=/bin/false \
SSH_ASKPASS=/bin/false \
  git -C workflow-package fetch --no-tags --depth=1 origin "$WORKFLOW_PACKAGE_COMMIT"
git -C workflow-package checkout --quiet --detach FETCH_HEAD
