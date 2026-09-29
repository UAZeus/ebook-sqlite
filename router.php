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

// ─── Static files (let PHP built-in server handle) ─
$filePath = __DIR__ . '/' . ltrim($path, '/');
if (is_file($filePath)) {
    return false;
}

// ─── 404 ──────────────────────────────────────────
http_response_code(404);
echo '<h1>404 Not Found</h1><p>' . htmlspecialchars($path) . '</p>';
return true;
