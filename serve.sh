#!/usr/bin/env sh
# Cross-platform launcher (Linux/macOS). Windows users: run serve.bat instead.
# All logic lives in serve.php so both OSes behave identically.
set -eu
DIR="$(cd "$(dirname "$0")" && pwd)"
exec php "$DIR/serve.php" "$@"
