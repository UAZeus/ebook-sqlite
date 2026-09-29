<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

use App\Config\Auth;
use App\Config\Database;

$db = Database::connect();
$auth = Auth::init($db);

if ($auth->isLoggedIn()) {
    header('Location: /');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register — E-Book Library</title>

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
            <h2>Create an Account</h2>
            <div id="register-alert"></div>

            <form class="auth-form" id="register-form" autocomplete="off">
                <div class="form-group">
                    <label for="name">Name</label>
                    <input type="text" name="name" id="name" required placeholder="Your name">
                </div>
                <div class="form-group">
                    <label for="email">Email</label>
                    <input type="email" name="email" id="email" required placeholder="you@gmail.com">
                </div>
                <div class="form-group">
                    <label for="password">Password</label>
                    <div class="password-wrap">
                        <input type="password" name="password" id="password" required minlength="8" placeholder="8+ chars, upper, lower, digit, special">
                        <button type="button" class="password-toggle" onclick="togglePass(this)" tabindex="-1" aria-label="Show password">👁</button>
                    </div>
                </div>
                <div class="form-group">
                    <label for="password_confirm">Confirm Password</label>
                    <div class="password-wrap">
                        <input type="password" name="password_confirm" id="password_confirm" required placeholder="Repeat your password">
                        <button type="button" class="password-toggle" onclick="togglePass(this)" tabindex="-1" aria-label="Show password">👁</button>
                    </div>
                </div>
                <button type="submit" class="btn btn--primary">Create Account</button>
            </form>

            <p class="auth-alt">
                Already have an account? <a href="/login">Sign in</a>
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

document.getElementById('register-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const alert = document.getElementById('register-alert');
    alert.innerHTML = '';
    const fd = new FormData(this);
    const password = fd.get('password');
    const confirm = fd.get('password_confirm');
    if (password !== confirm) {
        alert.innerHTML = '<div class="alert alert--error">Passwords do not match.</div>';
        return;
    }
    const r = await fetch(`${API}/auth/register`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ name: fd.get('name'), email: fd.get('email'), password })
    });
    const data = await r.json();
    if (r.ok) {
        showToast('Account created! Set your genre preferences to get started.', 'success');
        setTimeout(() => { location.href = '/preferences'; }, 400);
    } else {
        alert.innerHTML = `<div class="alert alert--error">${data.error || 'Registration failed.'}</div>`;
    }
});
</script>
</body>
</html>
