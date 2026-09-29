#!/usr/bin/env bash
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"

# ─── Check SQLite driver ───────────────────────────────────
if ! php -m | grep -qi pdo_sqlite; then
    echo "Error: PHP SQLite PDO driver not found."
    echo "Enable it in /etc/php/php.ini:"
    echo "  extension=pdo_sqlite"
    echo "  extension=sqlite3"
    exit 1
fi

# ─── Ensure upload directories exist ───────────────────────
mkdir -p "$DIR/uploads/covers"

# ─── Seed database if needed ───────────────────────────────
if [ ! -f "$DIR/database/ebook.db" ]; then
    echo "Seeding database..."
    php "$DIR/seed.php"
    echo ""
fi

# ─── Start server with router ──────────────────────────────
HOST="${HOST:-0.0.0.0}"
PORT="${PORT:-8080}"

echo "E-Book Library — PHP built-in server"
echo "-------------------------------------"
echo "  URL:  http://$HOST:$PORT"
echo ""
echo "Demo accounts:"
echo "  403lzeus@gmail.com              Admin@2026   (admin)"
echo "  ookamivillavert@gmail.com       Lib@2026!    (librarian)"
echo "  keanriedagumanpan@gmail.com     View@er26    (viewer)"
echo ""

exec php -S "$HOST:$PORT" -t "$DIR" "$DIR/router.php"
