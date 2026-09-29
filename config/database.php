<?php

declare(strict_types=1);

namespace App\Config;

class Database
{
    private static ?Database $instance = null;
    private \PDO $pdo;

    private function __construct()
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            throw new \RuntimeException(
                'PDO SQLite driver not found. Install/enable it:' . PHP_EOL .
                '  Windows: enable extension=pdo_sqlite and extension=sqlite3 in php.ini' . PHP_EOL .
                '  Linux:   install php-sqlite3 (e.g. sudo apt install php-sqlite3) and restart PHP'
            );
        }

        // Cross-platform path: __DIR__ + DIRECTORY_SEPARATOR works on
        // Windows (\) and Linux (/); PHP also accepts / on Windows.
        $dbDir = __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'database';
        $realDir = realpath($dbDir) ?: $dbDir;
        if (!is_dir($realDir) && !@mkdir($realDir, 0777, true) && !is_dir($realDir)) {
            throw new \RuntimeException('Cannot create database directory: ' . $realDir);
        }
        $dbPath = rtrim($realDir, '/\\') . DIRECTORY_SEPARATOR . 'ebook.db';

        $this->pdo = new \PDO(
            "sqlite:$dbPath",
            null,
            null,
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $this->pdo->exec('PRAGMA journal_mode=WAL');
        $this->pdo->exec('PRAGMA foreign_keys=ON');
    }

    public static function connect(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    public function fetchOne(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row ?: null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->query($sql, $params);
        return (int) $this->pdo->lastInsertId();
    }
}
