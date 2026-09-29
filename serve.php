<?php

declare(strict_types=1);

/**
 * Cross-platform dev server launcher (Windows + Linux).
 *
 * Usage:
 *   php serve.php                  # http://127.0.0.1:8080
 *   php serve.php --port=9000
 *   php serve.php --host=0.0.0.0 --port=8080
 *
 * Thin wrappers: serve.sh (Linux/macOS) and serve.bat (Windows) call this.
 *
 * NOTE: the PHP built-in server does not support TLS. For HTTPS in
 * production use deploy/nginx-ssl.conf (or another reverse proxy) in front
 * of PHP-FPM. config/server.crt/.key are kept for that purpose only.
 */

$opts = getopt('', ['host::', 'port::']);
$host = $opts['host'] ?? getenv('HOST') ?: '127.0.0.1';
$port = (int) ($opts['port'] ?? getenv('PORT') ?: 8080);

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "Error: PHP SQLite PDO driver not found.\n");
    fwrite(STDERR, "  Windows: enable extension=pdo_sqlite and extension=sqlite3 in php.ini\n");
    fwrite(STDERR, "  Linux:   sudo apt install php-sqlite3  (or yum/dnf equivalent)\n");
    exit(1);
}

// Cross-platform paths: __DIR__ + DIRECTORY_SEPARATOR works on both OSes.
$base = __DIR__;
$uploads = $base . DIRECTORY_SEPARATOR . 'uploads';
$covers  = $uploads . DIRECTORY_SEPARATOR . 'covers';
foreach ([$uploads, $covers] as $dir) {
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        fwrite(STDERR, "Error: cannot create directory: {$dir}\n");
        exit(1);
    }
}

if (!is_file($base . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'ebook.db')) {
    echo "Seeding database...\n";
    $cmd = PHP_BINARY . ' ' . escapeshellarg($base . DIRECTORY_SEPARATOR . 'seed.php');
    passthru($cmd, $seedExit);
    if ($seedExit !== 0) {
        fwrite(STDERR, "Seeding failed (exit {$seedExit}).\n");
        exit(1);
    }
    echo "\n";
}

echo "E-Book Library — PHP built-in server\n";
echo "-------------------------------------\n";
echo "  URL:  http://{$host}:{$port}\n";
echo "\n";
echo "Demo accounts:\n";
echo "  403lzeus@gmail.com              Admin@2026   (admin)\n";
echo "  ookamivillavert@gmail.com       Lib@2026!    (librarian)\n";
echo "  keanriedagumanpan@gmail.com     View@er26    (viewer)\n";
echo "\n";

// Escape each argument for the current OS (escapeshellarg is cross-platform).
$cmd = PHP_BINARY . ' -S ' . escapeshellarg("{$host}:{$port}")
    . ' -t ' . escapeshellarg($base)
    . ' ' . escapeshellarg($base . DIRECTORY_SEPARATOR . 'router.php');
passthru($cmd, $exit);
exit($exit);
