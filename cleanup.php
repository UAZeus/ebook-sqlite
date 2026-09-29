<?php

declare(strict_types=1);

/**
 * Cross-platform orphan-upload cleanup (Windows + Linux). SQLite only.
 *
 * Deletes files under uploads/ (and uploads/covers/) that are no longer
 * referenced by books.file_path / books.cover_url. Compares NORMALIZED
 * relative paths (forward slashes, lowercase on Windows) so the old
 * bash-script bug — comparing absolute disk paths against DB-relative
 * paths and deleting everything — cannot recur.
 *
 * Usage:
 *   php cleanup.php                # dry run (lists orphans, deletes nothing)
 *   php cleanup.php --delete       # actually delete orphans
 *   php cleanup.php --delete --verbose
 *
 * Exit codes: 0 ok, 1 environment error, 2 aborted.
 */

$argv = $_SERVER['argv'] ?? [];
$doDelete = in_array('--delete', $argv, true);
$verbose  = in_array('--verbose', $argv, true) || in_array('-v', $argv, true);

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "Error: PDO SQLite driver not found.\n");
    exit(1);
}

$base      = __DIR__;
$uploads   = $base . DIRECTORY_SEPARATOR . 'uploads';
$covers    = $uploads . DIRECTORY_SEPARATOR . 'covers';
$dbFile    = $base . DIRECTORY_SEPARATOR . 'database' . DIRECTORY_SEPARATOR . 'ebook.db';

if (!is_file($dbFile)) {
    fwrite(STDERR, "Error: database not found: {$dbFile}\nRun: php seed.php\n");
    exit(1);
}

/** Normalize a DB- or disk-relative path for comparison. */
$normalize = static function (string $p): string {
    $p = str_replace('\\', '/', $p);          // Windows \ -> /
    $p = ltrim($p, '/');                      // strip leading /
    $p = preg_replace('#/+#', '/', $p);       // collapse //
    if (DIRECTORY_SEPARATOR === '\\') {
        $p = strtolower($p);                  // case-insensitive FS
    }
    return $p;
};

try {
    $pdo = new PDO('sqlite:' . $dbFile, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: cannot open database: ' . $e->getMessage() . "\n");
    exit(1);
}

$refs = [];
foreach (['file_path', 'cover_url'] as $col) {
    $rows = $pdo->query(
        "SELECT {$col} FROM books WHERE {$col} IS NOT NULL AND {$col} != ''"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($rows as $r) {
        if (is_string($r) && $r !== '') {
            $refs[$normalize($r)] = true;
        }
    }
}

/** Collect candidate files (relative, normalized) under a dir. */
$collect = static function (string $dir) use ($normalize, $base): array {
    $out = [];
    if (!is_dir($dir)) {
        return $out;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($it as $file) {
        /** @var SplFileInfo $file */
        if ($file->isFile()) {
            $abs = $file->getPathname();
            // Relative to project root, then normalized.
            $rel = substr($abs, strlen($base) + 1);
            $out[] = ['abs' => $abs, 'rel' => $normalize($rel === false ? $abs : $rel)];
        }
    }
    return $out;
};

// $collect($uploads) recurses into covers/ too; dedupe by abs path.
$candidates = $collect($uploads);
$seen = [];
$unique = [];
foreach ($candidates as $c) {
    if (!isset($seen[$c['abs']])) {
        $seen[$c['abs']] = true;
        $unique[] = $c;
    }
}

$orphans = array_values(array_filter($unique, static function (array $c) use ($refs): bool {
    return !isset($refs[$c['rel']]);
}));

if (empty($orphans)) {
    echo "No orphaned files. " . count($unique) . " file(s) checked, all referenced.\n";
    exit(0);
}

echo ($doDelete ? 'Deleting' : 'Found') . ' ' . count($orphans) . " orphaned file(s)"
    . ($doDelete ? '' : ' (dry run — re-run with --delete to remove)') . ":\n";
foreach ($orphans as $o) {
    echo '  ' . $o['rel'] . "\n";
}

if (!$doDelete) {
    exit(0);
}

$removed = 0;
$failed = [];
foreach ($orphans as $o) {
    // Safety: only delete inside uploads/ (realpath containment, both OSes).
    $realUploads = realpath($uploads);
    $realFile = realpath($o['abs']);
    if ($realUploads === false || $realFile === false || !str_starts_with($realFile, $realUploads)) {
        $failed[] = $o['rel'] . ' (outside uploads/)';
        continue;
    }
    if (@unlink($realFile)) {
        $removed++;
        if ($verbose) {
            echo "  removed {$o['rel']}\n";
        }
    } else {
        $failed[] = $o['rel'];
    }
}

echo "Removed {$removed} orphaned file(s).\n";
if (!empty($failed)) {
    fwrite(STDERR, 'Failed to remove ' . count($failed) . " file(s):\n");
    foreach ($failed as $f) {
        fwrite(STDERR, '  ' . $f . "\n");
    }
    exit(1);
}
