#!/usr/bin/env bash
set -euo pipefail

DIR="$(cd "$(dirname "$0")" && pwd)"

php -d extension=pdo_mysql -r '
$dir = $argv[1];
$db = new PDO("mysql:host=localhost;dbname=ebook_library;charset=utf8mb4", "root", "123");

$refs = $db->query("SELECT file_path FROM books WHERE file_path IS NOT NULL")
           ->fetchAll(PDO::FETCH_COLUMN);
$refs = array_merge($refs,
    $db->query("SELECT cover_url FROM books WHERE cover_url IS NOT NULL")
       ->fetchAll(PDO::FETCH_COLUMN));

$removed = 0;
foreach (glob($dir . "/uploads/*.pdf") as $f) {
    if (!in_array($f, $refs, true)) { unlink($f); $removed++; }
}
foreach (glob($dir . "/uploads/covers/*") as $f) {
    if (!in_array($f, $refs, true)) { unlink($f); $removed++; }
}

echo "Removed {$removed} orphaned file(s).\n";
' "$DIR"
