<?php

declare(strict_types=1);

$uri = $_SERVER['REQUEST_URI'];
$path = parse_url($uri, PHP_URL_PATH);
$path = rtrim($path, '/');

// ─── API routes ────────────────────────────────────
if (str_starts_with($path, '/api')) {
    require __DIR__ . '/api/index.php';
    return true;
}

// ─── Book detail route: /book/123 → book.php?id=123 ─
if (preg_match('#^/book/(\d+)$#', $path, $m)) {
    $_GET['id'] = $m[1];
    require __DIR__ . '/book.php';
    return true;
}

// ─── Clean URL routes ──────────────────────────────
$routes = [
    '/login'       => 'login.php',
    '/register'    => 'register.php',
    '/account'     => 'account.php',
    '/activity'    => 'activity.php',
    '/admin'       => 'admin.php',
    '/preferences' => 'preferences.php',
    '/upload'      => 'upload.php',
    '/logout'      => 'logout.php',
];

if (isset($routes[$path])) {
    require __DIR__ . '/' . $routes[$path];
    return true;
}

// ─── Root → index.php ─────────────────────────────
if ($path === '' || $path === '/') {
    require __DIR__ . '/index.php';
    return true;
}

// ─── Block sensitive paths (built-in server would otherwise serve them) ─
// Without this, GET /database/ebook.db downloads the full DB (password
// hashes included) and /config/*.key leaks the TLS private key. The nginx
// configs already block these; the router must too for `php -S`.
$blockedPrefixes = ['/config', '/database', '/partials', '/deploy', '/.git'];
foreach ($blockedPrefixes as $prefix) {
    if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
        http_response_code(404);
        echo '<h1>404 Not Found</h1>';
        return true;
    }
}
$blockedExtensions = ['.db', '.db-wal', '.db-shm', '.sqlite', '.sqlite3', '.key', '.pem', '.env'];
foreach ($blockedExtensions as $ext) {
    if (str_ends_with(strtolower($path), $ext) || str_contains($path, '/.env')) {
        http_response_code(404);
        echo '<h1>404 Not Found</h1>';
        return true;
    }
}

// ─── Static files (let PHP built-in server handle) ─
// Resolve realpath and contain it inside the project dir (defense in depth;
// works with both / and \ separators on Windows + Linux).
$filePath = __DIR__ . '/' . ltrim($path, '/');
$realBase = realpath(__DIR__);
$realFile = realpath($filePath);
if ($realFile !== false && $realBase !== false && str_starts_with($realFile, $realBase) && is_file($realFile)) {
    return false;
}

// ─── 404 ──────────────────────────────────────────
http_response_code(404);
echo '<h1>404 Not Found</h1><p>' . htmlspecialchars($path) . '</p>';
return true;
