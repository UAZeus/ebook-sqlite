<?php

declare(strict_types=1);

/**
 * Database maintenance script.
 *
 * Usage (identical on Windows + Linux):
 *   php maintenance.php                  # full maintenance
 *   php maintenance.php --vacuum         # VACUUM + ANALYZE only
 *   php maintenance.php --archive        # 180-day archival (soft-delete old logs)
 *   php maintenance.php --purge          # hard-delete soft-deleted records
 *
 * Schedule weekly:
 *   Linux (cron):   0 3 * * 0  php /path/to/maintenance.php
 *   Windows:        Task Scheduler -> Create Basic Task -> weekly ->
 *                   Action "Start a program": php.exe, argument maintenance.php,
 *                   Start in: C:\path\to\ebook-sqlite
 */

require_once __DIR__ . '/config/database.php';

use App\Config\Database;

$mode = $argv[1] ?? 'all';

$db = Database::connect();
$pdo = $db->pdo();

echo "[" . date('Y-m-d H:i:s') . "] E-Book Library Maintenance\n";
echo str_repeat('-', 50) . "\n";

if ($mode === 'all' || $mode === '--archive') {
    // 180-day archival: soft-delete activity logs older than 180 days
    $cutoff = date('Y-m-d H:i:s', strtotime('-180 days'));
    $stmt = $pdo->prepare(
        "UPDATE activity_logs SET is_deleted = 1 WHERE created_at < ? AND is_deleted = 0"
    );
    $stmt->execute([$cutoff]);
    $archived = $stmt->rowCount();
    echo "Archived {$archived} activity records older than 180 days.\n";
}

if ($mode === 'all' || $mode === '--purge') {
    // Hard-delete soft-deleted activity logs (GDPR/privacy cleanup)
    // Keeps logs for 30 days after soft-delete for audit window
    $purgeCutoff = date('Y-m-d H:i:s', strtotime('-30 days'));
    $stmt = $pdo->prepare(
        "DELETE FROM activity_logs WHERE is_deleted = 1 AND created_at < ?"
    );
    $stmt->execute([$purgeCutoff]);
    $purged = $stmt->rowCount();
    echo "Purged {$purged} soft-deleted activity records.\n";
}

if ($mode === 'all' || $mode === '--vacuum') {
    // Rebuild database file, reclaim space, update query planner stats
    echo "Running ANALYZE...\n";
    $pdo->exec('ANALYZE');
    echo "Running VACUUM...\n";
    $pdo->exec('VACUUM');
    echo "VACUUM complete.\n";
}

echo str_repeat('-', 50) . "\n";
echo "Maintenance finished at " . date('Y-m-d H:i:s') . "\n";
