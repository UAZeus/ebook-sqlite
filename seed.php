<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';

use App\Config\Database;

$db = Database::connect();
$db->pdo()->exec('PRAGMA foreign_keys=OFF');

// Run schema (safe to re-run — uses IF NOT EXISTS / OR IGNORE)
$schema = file_get_contents(__DIR__ . '/database/schema.sql');
$db->pdo()->exec($schema);

// Check if demo users already exist
$existing = $db->fetchAll(
    "SELECT email FROM users WHERE email IN ('403lzeus@gmail.com', 'ookamivillavert@gmail.com', 'keanriedagumanpan@gmail.com')"
);
if (count($existing) === 3) {
    echo "Database already seeded. Skipping.\n";
    $db->pdo()->exec('PRAGMA foreign_keys=ON');
    exit(0);
}

// Genres are inserted by schema.sql (INSERT OR IGNORE), but if somehow missing:
$genreCount = $db->fetchOne('SELECT COUNT(*) AS c FROM genres');
if (!$genreCount || $genreCount['c'] === 0) {
    $genres = ['Sci-Fi', 'Fantasy', 'Romance', 'Non-Fiction', 'Thriller'];
    $gStmt = $db->pdo()->prepare('INSERT OR IGNORE INTO genres (name) VALUES (?)');
    foreach ($genres as $g) {
        $gStmt->execute([$g]);
    }
    echo "Inserted " . count($genres) . " genres.\n";
}

// Insert demo users
$uStmt = $db->pdo()->prepare('INSERT OR IGNORE INTO users (user_id, name, email, password, role) VALUES (?, ?, ?, ?, ?)');

$users = [
    ['Admin User',     '403lzeus@gmail.com',        password_hash('Admin@2026', PASSWORD_BCRYPT),     'admin'],
    ['Librarian User', 'ookamivillavert@gmail.com', password_hash('Lib@2026!', PASSWORD_BCRYPT),      'librarian'],
    ['Viewer User',    'keanriedagumanpan@gmail.com', password_hash('View@er26', PASSWORD_BCRYPT),      'viewer'],
];
$inserted = 0;
foreach ($users as $u) {
    $uid = bin2hex(random_bytes(16));
    $uStmt->execute([$uid, $u[0], $u[1], $u[2], $u[3]]);
    if ($uStmt->rowCount() > 0) $inserted++;
}

$db->pdo()->exec('PRAGMA foreign_keys=ON');
echo "Seeded " . $inserted . " users. Library is ready.\n";
