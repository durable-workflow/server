#!/usr/bin/env sh

set -eu

# Compatibility discovery is intentionally no longer a release selector. The
# Server Composer manifest and lock are the only permitted embedded package
# identity, and the resolver rejects every disagreeing caller override.
exec php "$(dirname "$0")/resolve-workflow-package-authority.php" "$@"
