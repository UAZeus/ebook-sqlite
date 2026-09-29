<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

use App\Config\Auth;
use App\Config\Database;

$db = Database::connect();
$auth = Auth::init($db);
$user = $auth->require();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Account — E-Book Library</title>

</head>
<body>
<div class="layout">

<?php require 'partials/head.php'; ?>
<?php require 'partials/sidebar.php'; ?>

<div class="main-content">

    <header class="page-header">
        <a href="/" class="back-link">← Back to Library</a>
    </header>

    <main class="content">
        <div class="auth-card auth-card--wide" style="margin:0 auto;">
            <h2>My Account</h2>

            <div id="account-alert"></div>

            <!-- Change Password -->
            <form class="auth-form" id="password-form" style="margin-bottom:32px;">
                <h3 style="margin-bottom:16px;font-size:1.1rem;">Change Password</h3>
                <div class="form-group">
                    <label for="current_password">Current Password</label>
                    <div class="password-wrap">
                        <input type="password" name="current_password" id="current_password" required>
                        <button type="button" class="password-toggle" onclick="togglePass(this)" tabindex="-1" aria-label="Show password">👁</button>
                    </div>
                </div>
                <div class="form-group">
                    <label for="new_password">New Password</label>
                    <div class="password-wrap">
                        <input type="password" name="new_password" id="new_password" required minlength="8" placeholder="8+ chars, upper, lower, digit, special">
                        <button type="button" class="password-toggle" onclick="togglePass(this)" tabindex="-1" aria-label="Show password">👁</button>
                    </div>
                </div>
                <button type="submit" class="btn btn--primary">Update Password</button>
            </form>

            <hr style="border:none;border-top:1px solid #e0e0e0;margin:24px 0;">

            <!-- Delete Account -->
            <div style="margin-bottom:16px;">
                <h3 style="margin-bottom:8px;font-size:1.1rem;color:#c0392b;">Delete Account</h3>
                <p style="font-size:0.85rem;color:#888;margin-bottom:12px;">Permanently delete your account and all associated data. This cannot be undone.</p>
                <button class="btn" style="background:#c0392b;color:#fff;" onclick="confirmDelete()">🗑 Delete My Account</button>
            </div>
        </div>
    </main>

    <footer class="footer">
        <p>E-Book Library &mdash; Built with PHP &amp; SQLite</p>
    </footer>

</div>
</div>

<script>
const API = '/api';
const alertBox = document.getElementById('account-alert');

document.getElementById('password-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    alertBox.innerHTML = '';
    const fd = new FormData(this);
    try {
        const r = await fetch(`${API}/account/password`, {
            method: 'PUT',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ current_password: fd.get('current_password'), new_password: fd.get('new_password') })
        });
        const data = await r.json();
        if (r.ok) {
            showToast('Password updated!', 'success');
            this.reset();
        } else {
            alertBox.innerHTML = `<div class="alert alert--error">${esc(data.error || 'Update failed.')}</div>`;
        }
    } catch(e) {
        alertBox.innerHTML = `<div class="alert alert--error">Network error.</div>`;
    }
});

async function confirmDelete() {
    if (!confirm('Are you absolutely sure? This will permanently delete your account and all data. This cannot be undone.')) return;
    try {
        const r = await fetch(`${API}/account`, { method: 'DELETE' });
        if (r.ok) {
            showToast('Your account has been deleted.', 'info');
            setTimeout(() => { location.href = '/'; }, 500);
        }
    } catch(e) {
        alertBox.innerHTML = `<div class="alert alert--error">Failed to delete account.</div>`;
    }
}
</script>
</body>
</html>
