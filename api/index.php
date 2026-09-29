<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';

use App\Config\Auth;
use App\Config\Database;

header('Content-Type: application/json');
// Same-origin app: no wildcard CORS. Cookie/session auth must never be
// combined with `Access-Control-Allow-Origin: *`. Cross-origin API access
// should use an explicit allowlist if ever needed.
// Preflight support for same-origin fetch:
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$db = Database::connect();
$auth = Auth::init($db);
$method = $_SERVER['REQUEST_METHOD'];
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$path = rtrim($path, '/');

function json(array $data, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function body(): array
{
    $ct = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if ($_SERVER['REQUEST_METHOD'] !== 'GET' && !str_contains($ct, 'application/json')) {
        json(['error' => 'Content-Type must be application/json'], 415);
    }
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

function param(string $key, mixed $default = null): mixed
{
    return $_GET[$key] ?? $default;
}

function loggedUser(): ?array
{
    global $auth;
    return $auth->user();
}

function requireUser(): array
{
    $u = loggedUser();
    if (!$u) json(['error' => 'Unauthorized'], 401);
    return $u;
}

function requireCsrf(): void
{
    if (in_array($_SERVER['REQUEST_METHOD'], ['POST', 'PUT', 'DELETE'], true)) {
        $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (!Auth::get()->verifyCsrf($token)) {
            json(['error' => 'Invalid CSRF token'], 403);
        }
    }
}

function requireAdmin(): array
{
    $u = requireUser();
    if ($u['role'] !== 'admin') json(['error' => 'Forbidden'], 403);
    return $u;
}

function logAudit(string $action, string $targetType = '', string $targetId = '', array $details = []): void
{
    global $db, $auth;
    $admin = $auth->user();
    if (!$admin) return;
    $db->execute(
        'INSERT INTO audit_logs (admin_id, action, target_type, target_id, details) VALUES (?, ?, ?, ?, ?)',
        [$admin['user_id'], $action, $targetType, $targetId, json_encode($details)]
    );
}

function checkRateLimit(): void
{
    global $db;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    // Clean expired entries
    $db->execute("DELETE FROM login_attempts WHERE attempt_time < datetime('now', '-1 minute')");
    // Count recent attempts
    $count = (int) $db->fetchOne(
        "SELECT COUNT(*) AS c FROM login_attempts WHERE ip = ? AND attempt_time >= datetime('now', '-1 minute')",
        [$ip]
    )['c'];
    if ($count >= 10) {
        json(['error' => 'Too many attempts. Try again in one minute.'], 429);
    }
}

function recordAttempt(): void
{
    global $db;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $db->execute('INSERT INTO login_attempts (ip) VALUES (?)', [$ip]);
}

function clearAttempts(): void
{
    global $db;
    $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    $db->execute('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
}

// Release session lock early for GET requests to allow concurrent API calls
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    session_write_close();
}

try {
    requireCsrf();

    // ─── Auth ─────────────────────────────────────────────────

    if ($path === '/api/auth/login' && $method === 'POST') {
        checkRateLimit();
        $data = body();
        $emailErr = Auth::validateGmailDomain($data['email'] ?? '');
        if ($emailErr) json(['error' => $emailErr], 422);
        $result = $auth->login($data['email'] ?? '', $data['password'] ?? '', !empty($data['remember']));
        if (!$result['success']) {
            recordAttempt();
            json(['error' => $result['error']], 401);
        }
        clearAttempts();
        json(['user' => $result['user']]);
    }

    if ($path === '/api/auth/register' && $method === 'POST') {
        $data = body();
        $emailErr = Auth::validateGmailDomain($data['email'] ?? '');
        if ($emailErr) json(['error' => $emailErr], 422);
        $pwErr = Auth::validatePassword($data['password'] ?? '');
        if ($pwErr) json(['error' => $pwErr], 422);
        $result = $auth->register($data['name'] ?? '', $data['email'] ?? '', $data['password'] ?? '');
        if (!$result['success']) json(['error' => $result['error']], 422);
        json(['user' => $auth->user()], 201);
    }

    if ($path === '/api/auth/logout' && $method === 'POST') {
        $auth->logout();
        json(['ok' => true]);
    }

    // ─── Account Management ────────────────────────────────────

    if ($path === '/api/account/password' && $method === 'PUT') {
        $u = requireUser();
        $data = body();
        $current     = $data['current_password'] ?? '';
        $newPassword = $data['new_password'] ?? '';
        if ($current === '' || $newPassword === '') json(['error' => 'All fields required'], 422);
        $row = $db->fetchOne('SELECT password FROM users WHERE user_id = ?', [$u['user_id']]);
        if (!$row || !password_verify($current, $row['password'])) json(['error' => 'Current password is incorrect'], 403);
        $pwErr = Auth::validatePassword($newPassword);
        if ($pwErr) json(['error' => $pwErr], 422);
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        $db->execute('UPDATE users SET password = ? WHERE user_id = ?', [$hash, $u['user_id']]);
        json(['ok' => true]);
    }

    if ($path === '/api/account' && $method === 'DELETE') {
        $u = requireUser();
        $db->pdo()->beginTransaction();
        try {
            $db->execute('DELETE FROM bookmarks WHERE user_id = ?', [$u['user_id']]);
            $db->execute('DELETE FROM activity_logs WHERE user_id = ?', [$u['user_id']]);
            $db->execute('DELETE FROM user_preferences WHERE user_id = ?', [$u['user_id']]);
            $db->execute('DELETE FROM remember_tokens WHERE user_id = ?', [$u['user_id']]);
            $db->execute('DELETE FROM reviews WHERE user_id = ?', [$u['user_id']]);
            $db->execute('DELETE FROM books WHERE user_id = ?', [$u['user_id']]);
            $db->execute('DELETE FROM users WHERE user_id = ?', [$u['user_id']]);
            $db->pdo()->commit();
        } catch (\Throwable $e) {
            $db->pdo()->rollBack();
            throw $e;
        }
        $auth->logout();
        json(['ok' => true]);
    }

    // ─── Genres ───────────────────────────────────────────────

    if ($path === '/api/genres' && $method === 'GET') {
        header('Cache-Control: public, max-age=3600');
        $genres = $db->fetchAll('SELECT genre_id, name FROM genres ORDER BY name');
        json($genres);
    }

    // ─── Preferences (min 3 enforcement) ──────────────────────

    if ($path === '/api/preferences' && $method === 'POST') {
        $u = requireUser();
        $data = body();
        $genreIds = $data['genres'] ?? [];
        if (count($genreIds) < 3) json(['error' => 'Please select at least 3 genres.'], 422);
        $db->execute('DELETE FROM user_preferences WHERE user_id = ?', [$u['user_id']]);
        if (!empty($genreIds)) {
            $placeholders = implode(',', array_fill(0, count($genreIds), '(?, ?)'));
            $stmt = $db->pdo()->prepare("INSERT INTO user_preferences (user_id, genre_id) VALUES $placeholders");
            $params = [];
            foreach ($genreIds as $gid) {
                $params[] = $u['user_id'];
                $params[] = (int) $gid;
            }
            $stmt->execute($params);
        }
        json(['ok' => true]);
    }

    if ($path === '/api/preferences' && $method === 'GET') {
        $u = requireUser();
        $prefs = $db->fetchAll(
            'SELECT g.genre_id, g.name FROM user_preferences p JOIN genres g ON g.genre_id = p.genre_id WHERE p.user_id = ?',
            [$u['user_id']]
        );
        json($prefs);
    }

    // ─── Books ────────────────────────────────────────────────

    if ($path === '/api/books' && $method === 'GET') {
        $genre   = param('genre');
        $search  = param('search');
        $bookmarked = param('bookmarked');
        $page    = max(1, (int) param('page', 1));
        $limit   = 12;
        $offset  = ($page - 1) * $limit;

        $where = "b.status = 'approved'";
        $params = [];

        if ($genre) {
            $where .= ' AND g.name = ?';
            $params[] = $genre;
        }
        if ($search) {
            $where .= ' AND (b.title LIKE ? OR b.author LIKE ?)';
            $params[] = "%$search%";
            $params[] = "%$search%";
        }
        if ($bookmarked !== null) {
            $u = requireUser();
            $where .= ' AND b.book_id IN (SELECT book_id FROM bookmarks WHERE user_id = ?)';
            $params[] = $u['user_id'];
        }

        $total = (int) $db->fetchOne(
            "SELECT COUNT(*) AS c FROM books b LEFT JOIN genres g ON g.genre_id = b.genre_id WHERE $where",
            $params
        )['c'];

        $params[] = $limit;
        $params[] = $offset;

        $books = $db->fetchAll(
            "SELECT b.book_id AS id, b.title, b.author, b.cover_url, b.year, b.pages, b.description,
                    g.name AS genre
             FROM books b
             LEFT JOIN genres g ON g.genre_id = b.genre_id
             WHERE $where
             ORDER BY b.created_at DESC
             LIMIT ? OFFSET ?",
            $params
        );

        json(['books' => $books, 'total' => $total, 'page' => $page, 'totalPages' => (int)ceil($total / $limit)]);
    }

    if (preg_match('#^/api/books/statistics$#', $path) && $method === 'GET') {
        header('Cache-Control: public, max-age=300');
        $u = loggedUser();
        $activityJoin = $u
            ? "LEFT JOIN activity_logs a ON a.book_id = b.book_id AND a.is_deleted = 0 AND a.user_id = ?"
            : "LEFT JOIN activity_logs a ON a.book_id = b.book_id AND a.is_deleted = 0";
        $params = $u ? [$u['user_id']] : [];
        $stats = $db->fetchOne(
            "SELECT
                COUNT(DISTINCT b.book_id) AS total_books,
                COUNT(DISTINCT b.author)  AS total_authors,
                COUNT(DISTINCT g.name)    AS total_genres,
                COUNT(a.log_id)           AS total_activity
            FROM books b
            $activityJoin
            LEFT JOIN genres g ON g.genre_id = b.genre_id
            WHERE b.status = 'approved'",
            $params
        );
        json($stats);
    }

    if (preg_match('#^/api/books/(\d+)$#', $path, $m) && $method === 'GET') {
        $id = (int) $m[1];
        $book = $db->fetchOne(
            "SELECT b.book_id AS id, b.title, b.author, b.description, b.cover_url,
                    b.year, b.pages, b.file_path, b.file_size, b.genre_id,
                    g.name AS genre, u.name AS uploader_name
             FROM books b
             LEFT JOIN genres g ON g.genre_id = b.genre_id
             LEFT JOIN users u ON u.user_id = b.user_id
             WHERE b.book_id = ? AND b.status = 'approved'",
            [$id]
        );
        if (!$book) json(['error' => 'Not found'], 404);
        // Include bookmark + completion status for logged-in users
        $u = loggedUser();
        if ($u) {
            $bm = $db->fetchOne('SELECT 1 FROM bookmarks WHERE user_id = ? AND book_id = ?', [$u['user_id'], $id]);
            $book['bookmarked'] = (bool) $bm;
            $done = $db->fetchOne(
                "SELECT 1 FROM activity_logs WHERE user_id = ? AND book_id = ? AND event_type = 'book_completed' AND is_deleted = 0",
                [$u['user_id'], $id]
            );
            $book['completed'] = (bool) $done;
        }
        json($book);
    }

    if (preg_match('#^/api/books/(\d+)/download$#', $path, $m) && $method === 'GET') {
        $id = (int) $m[1];
        $book = $db->fetchOne(
            "SELECT file_path, title FROM books WHERE book_id = ? AND status = 'approved'", [$id]
        );
        if (!$book || !$book['file_path']) json(['error' => 'File not found'], 404);
        $fullPath = realpath(__DIR__ . '/../' . $book['file_path']);
        $uploadsRoot = realpath(__DIR__ . '/../uploads');
        if ($fullPath === false || $uploadsRoot === false || !str_starts_with($fullPath, $uploadsRoot) || !is_file($fullPath)) {
            json(['error' => 'File not found on disk'], 404);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        readfile($fullPath);
        exit;
    }

    if (preg_match('#^/api/books/(\d+)/read$#', $path, $m) && $method === 'GET') {
        $id = (int) $m[1];
        $book = $db->fetchOne(
            "SELECT file_path FROM books WHERE book_id = ? AND status = 'approved'", [$id]
        );
        if (!$book || !$book['file_path']) json(['error' => 'File not found'], 404);
        $fullPath = realpath(__DIR__ . '/../' . $book['file_path']);
        $uploadsRoot = realpath(__DIR__ . '/../uploads');
        if ($fullPath === false || $uploadsRoot === false || !str_starts_with($fullPath, $uploadsRoot) || !is_file($fullPath)) {
            json(['error' => 'File not found on disk'], 404);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . basename($fullPath) . '"');
        header('Content-Length: ' . filesize($fullPath));
        header('Cache-Control: public, max-age=3600');
        readfile($fullPath);
        exit;
    }

    // ─── Bookmarks ────────────────────────────────────────────

    if ($path === '/api/bookmarks' && $method === 'POST') {
        $u = requireUser();
        $data = body();
        $bookId = (int) ($data['book_id'] ?? 0);
        if (!$bookId) json(['error' => 'book_id required'], 422);
        $existing = $db->fetchOne('SELECT 1 FROM bookmarks WHERE user_id = ? AND book_id = ?', [$u['user_id'], $bookId]);
        if ($existing) {
            $db->execute('DELETE FROM bookmarks WHERE user_id = ? AND book_id = ?', [$u['user_id'], $bookId]);
            json(['bookmarked' => false]);
        } else {
            $db->execute('INSERT INTO bookmarks (user_id, book_id) VALUES (?, ?)', [$u['user_id'], $bookId]);
            json(['bookmarked' => true]);
        }
    }

    if ($path === '/api/bookmarks' && $method === 'GET') {
        $u = requireUser();
        $ids = $db->fetchAll(
            'SELECT book_id FROM bookmarks WHERE user_id = ? ORDER BY created_at DESC',
            [$u['user_id']]
        );
        json(array_column($ids, 'book_id'));
    }

    // ─── Activity ─────────────────────────────────────────────

    if ($path === '/api/activity' && $method === 'POST') {
        $u = requireUser();
        if ($u['role'] === 'admin') json(['ok' => true, 'weight' => 0]);
        $data = body();
        $bookId = (int) ($data['book_id'] ?? 0);
        $event  = $data['event'] ?? '';
        if (!in_array($event, ['book_opened', 'book_completed'], true) || !$bookId) {
            json(['error' => 'Invalid event or book_id'], 422);
        }
        $book = $db->fetchOne("SELECT book_id FROM books WHERE book_id = ? AND status = 'approved'", [$bookId]);
        if (!$book) json(['error' => 'Book not found'], 404);
        $weight = $event === 'book_completed' ? 2 : 1;
        $db->execute(
            'INSERT INTO activity_logs (user_id, book_id, event_type, weight) VALUES (?, ?, ?, ?)',
            [$u['user_id'], $bookId, $event, $weight]
        );
        json(['ok' => true, 'weight' => $weight]);
    }

    if ($path === '/api/activity' && $method === 'GET') {
        $u = requireUser();
        $type  = param('type'); // 'opened' or 'completed', null = all
        $page  = max(1, (int) param('page', 1));
        $limit = 30;
        $offset = ($page - 1) * $limit;

        $where = "a.user_id = ? AND a.is_deleted = 0 AND b.status = 'approved'";
        $params = [$u['user_id']];

        if ($type === 'opened' || $type === 'completed') {
            $where .= " AND a.event_type = ?";
            $params[] = 'book_' . $type;

            // Deduplicate: only the latest event per book for this event type
            $subClause = "a.created_at = (
                SELECT MAX(a2.created_at) FROM activity_logs a2
                WHERE a2.user_id = a.user_id AND a2.book_id = a.book_id
                  AND a2.event_type = a.event_type AND a2.is_deleted = 0
            )";
            $where .= " AND $subClause";
        }

        $total = (int) $db->fetchOne(
            "SELECT COUNT(*) AS c FROM activity_logs a
             JOIN books b ON b.book_id = a.book_id
             WHERE $where",
            $params
        )['c'];

        $pageParams = $params;
        $pageParams[] = $limit;
        $pageParams[] = $offset;

        $items = $db->fetchAll(
            "SELECT a.log_id, a.event_type, a.weight, a.created_at,
                    b.book_id, b.title, b.author, b.cover_url
             FROM activity_logs a
             JOIN books b ON b.book_id = a.book_id
             WHERE $where
             ORDER BY a.created_at DESC
             LIMIT ? OFFSET ?",
            $pageParams
        );

        json(['items' => $items, 'total' => $total, 'page' => $page, 'totalPages' => (int)ceil($total / $limit)]);
    }

    // DELETE /api/activity/:id  (soft-delete)
    if (preg_match('#^/api/activity/(\d+)$#', $path, $m) && $method === 'DELETE') {
        $u = requireUser();
        $logId = (int) $m[1];
        $log = $db->fetchOne('SELECT user_id FROM activity_logs WHERE log_id = ?', [$logId]);
        if (!$log || $log['user_id'] !== $u['user_id']) json(['error' => 'Not found'], 404);
        $db->execute("UPDATE activity_logs SET is_deleted = 1 WHERE log_id = ? AND user_id = ?", [$logId, $u['user_id']]);
        json(['ok' => true]);
    }

    // ─── Recommendations ──────────────────────────────────────

    if ($path === '/api/recommendations' && $method === 'GET') {
        $u = requireUser();
        $limit = min(20, max(1, (int) param('limit', 6)));

        $prefs = $db->fetchAll(
            'SELECT genre_id FROM user_preferences WHERE user_id = ?', [$u['user_id']]
        );
        $prefGenreIds = array_column($prefs, 'genre_id');

        $seen = $db->fetchAll(
            "SELECT DISTINCT book_id FROM activity_logs WHERE user_id = ? AND is_deleted = 0",
            [$u['user_id']]
        );
        $seenIds = array_column($seen, 'book_id');

        $completedGenres = [];
        if (!empty($seenIds)) {
            $completed = $db->fetchAll(
                "SELECT DISTINCT b.genre_id
                 FROM activity_logs a
                 JOIN books b ON b.book_id = a.book_id
                 WHERE a.user_id = ? AND a.event_type = 'book_completed' AND a.is_deleted = 0
                 AND b.genre_id IS NOT NULL",
                [$u['user_id']]
            );
            $completedGenres = array_column($completed, 'genre_id');
        }

        $excludeIds = $seenIds;
        $excludePlaceholder = empty($excludeIds) ? 'NULL' : implode(',', array_fill(0, count($excludeIds), '?'));
        $favGenres = array_unique(array_merge($prefGenreIds, $completedGenres));

        $recs = [];

        if (!empty($favGenres)) {
            $gPlaceholder = implode(',', array_fill(0, count($favGenres), '?'));
            $params = array_merge($favGenres, $excludeIds);
            $params[] = $limit;

            $recs = $db->fetchAll(
                "SELECT b.book_id AS id, b.title, b.author, b.cover_url, b.year,
                        g.name AS genre,
                        COALESCE(s.score, 0) AS interest_score
                 FROM books b
                 LEFT JOIN genres g ON g.genre_id = b.genre_id
                 LEFT JOIN user_book_scores s ON s.book_id = b.book_id AND s.user_id = ?
                 WHERE b.status = 'approved'
                   AND b.genre_id IN ($gPlaceholder)
                   AND b.book_id NOT IN ($excludePlaceholder)
                 ORDER BY interest_score DESC
                 LIMIT ?",
                array_merge([$u['user_id']], $params)
            );
        }

        if (count($recs) < $limit) {
            $existing = array_merge($excludeIds, array_column($recs, 'id'));
            $ePh = empty($existing) ? 'NULL' : implode(',', array_fill(0, count($existing), '?'));
            $fillParams = $existing;
            $fillParams[] = $limit - count($recs);

            $fill = $db->fetchAll(
                "SELECT b.book_id AS id, b.title, b.author, b.cover_url, b.year,
                        g.name AS genre,
                        COALESCE(s.score, 0) AS interest_score
                 FROM books b
                 LEFT JOIN genres g ON g.genre_id = b.genre_id
                 LEFT JOIN user_book_scores s ON s.book_id = b.book_id AND s.user_id = ?
                 WHERE b.status = 'approved'
                   AND b.book_id NOT IN ($ePh)
                 ORDER BY b.created_at DESC
                 LIMIT ?",
                array_merge([$u['user_id']], $fillParams)
            );

            $recs = array_merge($recs, $fill);
        }

        // Shuffle in PHP for variety (avoids expensive ORDER BY RANDOM())
        shuffle($recs);
        json($recs);
    }

    // ─── Admin: approve / reject ──────────────────────────────

    if (preg_match('#^/api/admin/books/(\d+)/approve$#', $path, $m) && $method === 'POST') {
        requireAdmin();
        $id = (int) $m[1];
        $book = $db->fetchOne('SELECT title FROM books WHERE book_id = ?', [$id]);
        $db->execute("UPDATE books SET status = 'approved' WHERE book_id = ? AND status = 'pending'", [$id]);
        logAudit('book_approve', 'book', (string) $id, ['title' => $book['title'] ?? '']);
        json(['ok' => true]);
    }

    if (preg_match('#^/api/admin/books/(\d+)/reject$#', $path, $m) && $method === 'POST') {
        requireAdmin();
        $id = (int) $m[1];
        $book = $db->fetchOne('SELECT title FROM books WHERE book_id = ?', [$id]);
        $db->execute("UPDATE books SET status = 'rejected' WHERE book_id = ? AND status = 'pending'", [$id]);
        logAudit('book_reject', 'book', (string) $id, ['title' => $book['title'] ?? '']);
        json(['ok' => true]);
    }

    // PUT /api/admin/books/:id  (edit metadata)
    if (preg_match('#^/api/admin/books/(\d+)$#', $path, $m) && $method === 'PUT') {
        requireAdmin();
        $id = (int) $m[1];
        $data = body();
        $fields = [];
        $params = [];
        foreach (['title', 'author', 'description', 'year', 'pages', 'genre_id'] as $f) {
            if (array_key_exists($f, $data)) {
                $fields[] = "$f = ?";
                $params[] = $data[$f];
            }
        }
        if (empty($fields)) json(['error' => 'No fields to update'], 422);
        $params[] = $id;
        $db->execute(
            "UPDATE books SET " . implode(', ', $fields) . " WHERE book_id = ?",
            $params
        );
        logAudit('book_edit', 'book', (string) $id, ['fields' => array_keys($data)]);
        json(['ok' => true]);
    }

    if ($path === '/api/admin/pending' && $method === 'GET') {
        requireAdmin();
        $pending = $db->fetchAll(
            "SELECT b.book_id AS id, b.title, b.author, b.created_at, u.name AS uploader_name
             FROM books b LEFT JOIN users u ON u.user_id = b.user_id
              WHERE b.status = 'pending' ORDER BY b.created_at DESC LIMIT 100"
        );
        json($pending);
    }

    if ($path === '/api/admin/approved' && $method === 'GET') {
        requireAdmin();
        $page  = max(1, (int) param('page', 1));
        $limit = min(50, max(1, (int) param('limit', 20)));
        $offset = ($page - 1) * $limit;

        $total = (int) $db->fetchOne(
            "SELECT COUNT(*) AS c FROM books WHERE status = 'approved'"
        )['c'];

        $approved = $db->fetchAll(
            "SELECT b.book_id AS id, b.title, b.author, b.year, b.pages, b.genre_id,
                    b.created_at, u.name AS uploader_name, b.file_path
             FROM books b LEFT JOIN users u ON u.user_id = b.user_id
             WHERE b.status = 'approved' ORDER BY b.created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
        json(['books' => $approved, 'total' => $total, 'page' => $page, 'totalPages' => (int)ceil($total / $limit)]);
    }

    // GET /api/admin/audit — system-wide activity overview
    if ($path === '/api/admin/audit' && $method === 'GET') {
        requireAdmin();
        $days = min(90, max(1, (int) param('days', 7)));
        $since = date('Y-m-d H:i:s', time() - $days * 86400);

        $overview = $db->fetchAll(
            "SELECT DATE(a.created_at) AS day,
                    COUNT(*) AS total,
                    SUM(CASE WHEN a.event_type = 'book_opened' THEN 1 ELSE 0 END) AS opened,
                    SUM(CASE WHEN a.event_type = 'book_completed' THEN 1 ELSE 0 END) AS completed
             FROM activity_logs a
             WHERE a.created_at >= ? AND a.is_deleted = 0
             GROUP BY DATE(a.created_at)
             ORDER BY day DESC
             LIMIT 90",
            [$since]
        );

        $topUsers = $db->fetchAll(
            "SELECT u.name, u.email, COUNT(a.log_id) AS events
             FROM activity_logs a
             JOIN users u ON u.user_id = a.user_id
             WHERE a.created_at >= ? AND a.is_deleted = 0
             GROUP BY a.user_id
             ORDER BY events DESC
             LIMIT 20",
            [$since]
        );

        json(['overview' => $overview, 'topUsers' => $topUsers, 'days' => $days]);
    }

    // GET /api/admin/audit-log — admin action trail
    if ($path === '/api/admin/audit-log' && $method === 'GET') {
        requireAdmin();
        $page  = max(1, (int) param('page', 1));
        $limit = min(100, max(1, (int) param('limit', 50)));
        $offset = ($page - 1) * $limit;
        $total = (int) $db->fetchOne('SELECT COUNT(*) AS c FROM audit_logs')['c'];
        $logs = $db->fetchAll(
            "SELECT a.log_id, a.action, a.target_type, a.target_id, a.details, a.created_at,
                    u.name AS admin_name
             FROM audit_logs a
             LEFT JOIN users u ON u.user_id = a.admin_id
             ORDER BY a.created_at DESC LIMIT ? OFFSET ?",
            [$limit, $offset]
        );
        json(['logs' => $logs, 'total' => $total, 'page' => $page, 'totalPages' => (int)ceil($total / $limit)]);
    }

    // GET /api/admin/stats — dashboard overview totals + genre distribution
    if ($path === '/api/admin/stats' && $method === 'GET') {
        requireAdmin();

        $totals = $db->fetchOne(
            "SELECT
                COUNT(DISTINCT b.book_id) AS total_books,
                COUNT(DISTINCT b.author)  AS total_authors,
                COUNT(DISTINCT g.name)    AS total_genres
             FROM books b
             LEFT JOIN genres g ON g.genre_id = b.genre_id
             WHERE b.status = 'approved'"
        );

        $activityCount = (int) $db->fetchOne(
            "SELECT COUNT(*) AS c FROM activity_logs WHERE is_deleted = 0"
        )['c'];

        $totalUsers = (int) $db->fetchOne(
            "SELECT COUNT(*) AS c FROM users"
        )['c'];

        $genreDist = $db->fetchAll(
            "SELECT g.genre_id, g.name, COUNT(b.book_id) AS count
             FROM genres g
             LEFT JOIN books b ON b.genre_id = g.genre_id AND b.status = 'approved'
             GROUP BY g.genre_id
             ORDER BY g.name"
        );

        json([
            'total_books'    => (int) $totals['total_books'],
            'total_authors'  => (int) $totals['total_authors'],
            'total_genres'   => (int) $totals['total_genres'],
            'total_activity' => $activityCount,
            'total_users'    => $totalUsers,
            'genre_dist'     => $genreDist,
        ]);
    }

    // GET /api/admin/users — list all non-admin users
    if ($path === '/api/admin/users' && $method === 'GET') {
        requireAdmin();
        $users = $db->fetchAll(
            "SELECT user_id, name, email, role, created_at FROM users WHERE role != 'admin' ORDER BY created_at DESC LIMIT 200"
        );
        json($users);
    }

    // PUT /api/admin/users/:id/role — change user role
    if (preg_match('#^/api/admin/users/([^/]+)/role$#', $path, $m) && $method === 'PUT') {
        requireAdmin();
        $targetId = $m[1];
        $data = body();
        $role = $data['role'] ?? '';
        if (!in_array($role, ['viewer', 'librarian'], true)) json(['error' => 'Invalid role'], 422);
        $target = $db->fetchOne('SELECT role, email FROM users WHERE user_id = ?', [$targetId]);
        if (!$target) json(['error' => 'User not found'], 404);
        if ($target['role'] === 'admin') json(['error' => 'Cannot change admin role'], 403);
        $db->execute('UPDATE users SET role = ? WHERE user_id = ?', [$role, $targetId]);
        logAudit('role_change', 'user', $targetId, ['email' => $target['email'], 'old' => $target['role'], 'new' => $role]);
        json(['ok' => true]);
    }

    // DELETE /api/admin/users/:id  (hard purge)
    if (preg_match('#^/api/admin/users/([^/]+)$#', $path, $m) && $method === 'DELETE') {
        requireAdmin();
        $userId = $m[1];
        $target = $db->fetchOne('SELECT email, name FROM users WHERE user_id = ?', [$userId]);
        $db->pdo()->beginTransaction();
        try {
            $db->execute('DELETE FROM bookmarks WHERE user_id = ?', [$userId]);
            $db->execute('DELETE FROM activity_logs WHERE user_id = ?', [$userId]);
            $db->execute('DELETE FROM user_preferences WHERE user_id = ?', [$userId]);
            $db->execute('DELETE FROM remember_tokens WHERE user_id = ?', [$userId]);
            $db->execute('DELETE FROM reviews WHERE user_id = ?', [$userId]);
            $db->execute('DELETE FROM users WHERE user_id = ?', [$userId]);
            $db->pdo()->commit();
        } catch (\Throwable $e) {
            $db->pdo()->rollBack();
            throw $e;
        }
        logAudit('user_delete', 'user', $userId, ['email' => $target['email'] ?? '', 'name' => $target['name'] ?? '']);
        json(['ok' => true]);
    }

    // ─── Upload ───────────────────────────────────────────────

    if ($path === '/api/upload' && $method === 'POST') {
        $u = requireUser();
        if (!in_array($u['role'], ['librarian', 'admin'], true)) json(['error' => 'Forbidden'], 403);

        $title       = trim($_POST['title'] ?? '');
        $authorName  = trim($_POST['author'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $genreId     = (int) ($_POST['genre_id'] ?? 0);
        $year        = (int) ($_POST['year'] ?? 0);
        $pages       = (int) ($_POST['pages'] ?? 0);
        $pdf         = $_FILES['pdf'] ?? null;

        if ($title === '' || $authorName === '') json(['error' => 'Title and author required'], 422);
        if (mb_strlen($title) > 255 || mb_strlen($authorName) > 255) json(['error' => 'Title/author too long (max 255)'], 422);
        if (mb_strlen($description) > 10000) json(['error' => 'Description too long (max 10000)'], 422);
        if ($genreId !== 0) {
            $genreRow = $db->fetchOne('SELECT genre_id FROM genres WHERE genre_id = ?', [$genreId]);
            if (!$genreRow) json(['error' => 'Invalid genre'], 422);
        }
        $currentYear = (int) date('Y');
        if ($year !== 0 && ($year < 0 || $year > $currentYear + 1)) json(['error' => 'Invalid year'], 422);
        if ($pages < 0 || $pages > 100000) json(['error' => 'Invalid page count'], 422);
        if (!$pdf || $pdf['error'] !== UPLOAD_ERR_OK) json(['error' => 'PDF file required'], 422);
        if (strtolower(pathinfo($pdf['name'], PATHINFO_EXTENSION)) !== 'pdf') json(['error' => 'Only PDF files allowed'], 422);
        if ($pdf['size'] > 50 * 1024 * 1024) json(['error' => 'File too large (max 50 MB)'], 422);
        // MIME check (client extension is trivially spoofable)
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($pdf['tmp_name']);
        if ($mime !== 'application/pdf') json(['error' => 'Invalid PDF file'], 422);

        // Cross-platform dirs: __DIR__ + DIRECTORY_SEPARATOR; mkdir recursive
        // so uploads work on Windows + Linux even from a fresh checkout.
        $appRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..') ?: (__DIR__ . DIRECTORY_SEPARATOR . '..');
        $uploadsDir = $appRoot . DIRECTORY_SEPARATOR . 'uploads';
        $coversDir = $uploadsDir . DIRECTORY_SEPARATOR . 'covers';
        foreach ([$uploadsDir, $coversDir] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
                json(['error' => 'Upload directory unavailable'], 500);
            }
        }

        // Single timestamp + random suffix: avoids collisions when two uploads
        // share a title/second, and fixes the old dual-time() cover bug where
        // the resized cover was renamed to a different timestamped name.
        $safe = preg_replace('/[^a-zA-Z0-9_-]/', '_', $title);
        $safe = trim($safe, '_');
        if ($safe === '') $safe = 'book';
        $safe = substr($safe, 0, 80);
        $stamp = time() . '_' . bin2hex(random_bytes(4));
        $pdfDest = 'uploads/' . $safe . '_' . $stamp . '.pdf';
        $pdfFull = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $pdfDest);

        if (!move_uploaded_file($pdf['tmp_name'], $pdfFull)) {
            json(['error' => 'Failed to save file'], 500);
        }
        // Verify PDF magic bytes (%PDF) after move
        $fh = fopen($pdfFull, 'rb');
        $magic = $fh ? fread($fh, 4) : false;
        if ($fh) fclose($fh);
        if ($magic !== '%PDF') {
            @unlink($pdfFull);
            json(['error' => 'Invalid PDF file'], 422);
        }

        $coverUrl = '';
        $cover = $_FILES['cover'] ?? null;
        if ($cover && $cover['error'] === UPLOAD_ERR_OK) {
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            $ext = strtolower(pathinfo($cover['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) json(['error' => 'Invalid cover image type'], 422);
            if ($cover['size'] > 10 * 1024 * 1024) {
                @unlink($pdfFull);
                json(['error' => 'Cover too large (max 10 MB)'], 422);
            }
            $coverMime = (new finfo(FILEINFO_MIME_TYPE))->file($cover['tmp_name']);
            if (!str_starts_with((string) $coverMime, 'image/')) {
                @unlink($pdfFull);
                json(['error' => 'Invalid cover image'], 422);
            }
            // Normalize to .jpg when GD resizes; keep original ext otherwise.
            $coverDest = 'uploads/covers/' . $safe . '_' . $stamp . '.' . $ext;
            $coverPath = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $coverDest);
            if (move_uploaded_file($cover['tmp_name'], $coverPath)) {
                // Resize to max 600px wide, JPEG 85% (skipped if GD missing)
                $size = @getimagesize($coverPath);
                $maxW = 600;
                if ($size && $size[0] > $maxW && function_exists('imagecreatetruecolor')) {
                    [$w, $h] = $size;
                    $nh = (int) round($h * $maxW / $w);
                    $src = match ($ext) {
                        'jpeg', 'jpg' => @imagecreatefromjpeg($coverPath),
                        'png'         => @imagecreatefrompng($coverPath),
                        'gif'         => @imagecreatefromgif($coverPath),
                        'webp'        => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($coverPath) : null,
                        default       => null,
                    };
                    if ($src) {
                        $thumb = imagecreatetruecolor($maxW, $nh);
                        if ($ext === 'png' || $ext === 'gif') {
                            imagealphablending($thumb, false);
                            imagesavealpha($thumb, true);
                        }
                        imagecopyresampled($thumb, $src, 0, 0, 0, 0, $maxW, $nh, $w, $h);
                        $jpgDest = 'uploads/covers/' . $safe . '_' . $stamp . '.jpg';
                        $jpgPath = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $jpgDest);
                        if (imagejpeg($thumb, $jpgPath, 85)) {
                            @unlink($coverPath);
                            $coverDest = $jpgDest;
                        }
                        imagedestroy($src);
                        imagedestroy($thumb);
                    }
                }
                $coverUrl = $coverDest;
            }
        }

        $status = $u['role'] === 'admin' ? 'approved' : 'pending';
        $db->execute(
            'INSERT INTO books (title, author, description, cover_url, genre_id, year, pages, user_id, status, file_path, file_size)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$title, $authorName, $description, $coverUrl, $genreId ?: null, $year, $pages, $u['user_id'], $status, $pdfDest, $pdf['size']]
        );

        json(['ok' => true, 'status' => $status]);
    }

    // 404
    json(['error' => 'Not found', 'path' => $path, 'method' => $method], 404);

} catch (\PDOException $e) {
    error_log('API PDO error: ' . $e->getMessage());
    json(['error' => 'Internal server error'], 500);
} catch (\Throwable $e) {
    error_log('API error: ' . $e->getMessage());
    json(['error' => 'Internal server error'], 500);
}
