#!/usr/bin/env sh
# DEPRECATED wrapper — kept for backward compatibility.
# The PHP built-in server cannot do TLS; for HTTPS use deploy/nginx-ssl.conf
# (or another reverse proxy) in front of PHP-FPM. This now delegates to
# serve.php, which behaves identically on Windows (serve.bat) and Linux.
set -eu
DIR="$(cd "$(dirname "$0")" && pwd)"
echo "NOTE: serve-tls.sh is deprecated. PHP's built-in server is HTTP-only;" >&2
echo "      for TLS terminate HTTPS at nginx (see deploy/nginx-ssl.conf)." >&2
exec php "$DIR/serve.php" "$@"
