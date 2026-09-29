<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

use App\Config\Auth;
use App\Config\Database;

$db = Database::connect();
$auth = Auth::init($db);

// Already logged in? Redirect away
if ($auth->isLoggedIn()) {
    $redirect = $_GET['redirect'] ?? '/';
    $allowed = ['/', '/register', '/preferences', '/account', '/activity', '/admin', '/upload'];
    if (!str_starts_with($redirect, '/') || str_contains($redirect, '//')) {
        $redirect = '/';
    }
    header('Location: ' . $redirect);
    exit;
}

$redirectTo = $_GET['redirect'] ?? '';
if (!str_starts_with($redirectTo, '/') || str_contains($redirectTo, '//')) {
    $redirectTo = '';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — E-Book Library</title>

</head>
<body>
<div class="layout">

<?php require 'partials/head.php'; ?>

<div class="main-content">

    <main class="content auth-page">
        <div class="auth-brand">
            
            <div class="auth-brand-logo">📚</div>
            <h1 class="auth-brand-title">E-Book Library</h1>
            <p class="auth-brand-tagline">Discover, read, and enjoy your next favorite book.</p>
        </div>
        <div class="auth-card">
            <h2>Sign In</h2>
            <div id="login-alert"></div>

            <form class="auth-form" id="login-form">
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" name="email" id="email" required placeholder="you@gmail.com">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrap">
                        <input type="password" name="password" id="password" required placeholder="Your password">
                        <button type="button" class="password-toggle" onclick="togglePass(this)" tabindex="-1" aria-label="Show password">👁</button>
                    </div>
                </div>
                <div class="form-group" style="display:flex;align-items:center;gap:8px;">
                    <input type="checkbox" name="remember" id="remember" value="1" style="width:auto;">
                    <label for="remember" style="margin:0;font-weight:400;">Remember me for 30 days</label>
                </div>
                <button type="submit" class="btn btn--primary">Sign In</button>
            </form>

            <p class="auth-alt">
                Don't have an account? <a href="/register">Register</a>
            </p>
        </div>
    </main>

    <footer class="footer">
        <p>E-Book Library &mdash; Built with PHP &amp; SQLite</p>
    </footer>

</div>
</div>

<script>
const API = '/api';
const redirectTo = '<?= htmlspecialchars($redirectTo) ?>';
document.getElementById('login-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const alert = document.getElementById('login-alert');
    alert.innerHTML = '';
    const fd = new FormData(this);
    const r = await fetch(`${API}/auth/login`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({
            email: fd.get('email'),
            password: fd.get('password'),
            remember: !!fd.get('remember')
        })
    });
    const data = await r.json();
    if (r.ok) {
        showToast('Welcome back, ' + data.user.name + '!', 'success');
        setTimeout(() => { location.href = redirectTo || '/'; }, 400);
    } else {
        alert.innerHTML = `<div class="alert alert--error">${esc(data.error || 'Login failed')}</div>`;
    }
});
</script>
</body>
</html>
