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
    <title>Genre Preferences — E-Book Library</title>

</head>
<body>
<div class="layout">

<?php require 'partials/head.php'; ?>

<?php require 'partials/sidebar.php'; ?>

<div class="main-content">

    <header class="page-header">
        <a href="/" class="back-link">← Back to Library</a>
    </header>

    <main class="content auth-page">
        <div class="auth-card">
            <h2>Genre Preferences</h2>
            <p style="text-align:center;color:#666;margin-bottom:20px;font-size:0.9rem;">
                Select at least 3 genres you enjoy so we can recommend books you'll love.
            </p>

            <div id="prefs-alert"></div>

            <form id="prefs-form" class="auth-form">
                <div class="genre-grid" id="genre-grid"></div>
                <p id="genre-count" style="text-align:center;font-size:0.85rem;color:#888;margin-top:12px;">0 selected (minimum 3)</p>
                <button type="submit" class="btn btn--primary" style="margin-top:16px;width:100%">Save Preferences</button>
            </form>
        </div>
    </main>

    <footer class="footer">
        <p>E-Book Library &mdash; Built with PHP &amp; SQLite</p>
    </footer>

</div>
</div>

<script>
const API = '/api';

async function load() {
    const [genres, prefs] = await Promise.all([
        fetch(`${API}/genres`).then(r => r.json()),
        fetch(`${API}/preferences`).then(r => r.json()).catch(() => [])
    ]);
    const prefIds = new Set(prefs.map(p => p.genre_id));
    const grid = document.getElementById('genre-grid');
    grid.innerHTML = genres.map(g => `
        <label class="genre-card ${prefIds.has(g.genre_id) ? 'selected' : ''}">
            <input type="checkbox" name="genres" value="${g.genre_id}" ${prefIds.has(g.genre_id) ? 'checked' : ''}
                   onchange="updateCount()">
            ${g.name}
        </label>
    `).join('');
    updateCount();
}

function updateCount() {
    const checked = document.querySelectorAll('input[name="genres"]:checked').length;
    document.getElementById('genre-count').textContent = `${checked} selected (minimum 3)`;
    document.querySelectorAll('.genre-card').forEach((card, i) => {
        const cb = card.querySelector('input');
        card.classList.toggle('selected', cb.checked);
    });
}

document.getElementById('prefs-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const checked = [...this.querySelectorAll('input[name="genres"]:checked')].map(c => parseInt(c.value));
    if (checked.length < 3) {
        document.getElementById('prefs-alert').innerHTML = '<div class="alert alert--error">Please select at least 3 genres.</div>';
        return;
    }
    const r = await fetch(`${API}/preferences`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ genres: checked })
    });
    const data = await r.json();
    const alert = document.getElementById('prefs-alert');
    if (r.ok) {
        showToast('Preferences saved!', 'success');
            setTimeout(() => { location.href = '/'; }, 400);
    } else {
        alert.innerHTML = `<div class="alert alert--error">${data.error || 'Failed to save.'}</div>`;
    }
});

load();
</script>
</body>
</html>
