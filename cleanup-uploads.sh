#!/usr/bin/env sh
# DEPRECATED wrapper — kept for backward compatibility.
# The old script connected to MySQL (pdo_mysql, ebook_library, root/123) and
# compared absolute disk paths to DB-relative paths, which would have deleted
# every upload. Use the SQLite-based, cross-platform cleanup.php instead:
#   php cleanup.php            # dry run (default, deletes nothing)
#   php cleanup.php --delete   # actually delete orphans
set -eu
DIR="$(cd "$(dirname "$0")" && pwd)"
exec php "$DIR/cleanup.php" --delete "$@"
