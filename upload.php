<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

use App\Config\Auth;
use App\Config\Database;

$db = Database::connect();
$auth = Auth::init($db);
$user = $auth->requireRole('librarian', 'admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Book — E-Book Library</title>

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
        <div class="auth-card auth-card--wide">
            <h2>Upload a Book</h2>

            <div id="upload-alert"></div>

            <form id="upload-form" class="auth-form" enctype="multipart/form-data">
                <div class="form-group">
                    <label for="title">Title *</label>
                    <input type="text" name="title" id="title" required>
                </div>
                <div class="form-group">
                    <label for="author">Author *</label>
                    <input type="text" name="author" id="author" required>
                </div>
                <div class="form-group">
                    <label for="description">Description</label>
                    <textarea name="description" id="description" rows="4"></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="genre_id">Genre</label>
                        <select name="genre_id" id="genre_id">
                            <option value="">Select genre...</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="year">Year</label>
                        <input type="number" name="year" id="year" min="0" max="2099" value="<?= date('Y') ?>">
                    </div>
                    <div class="form-group">
                        <label for="pages">Pages</label>
                        <input type="number" name="pages" id="pages" min="0">
                    </div>
                </div>
                <div class="form-group">
                    <label for="cover">Cover Image (optional)</label>
                    <input type="file" name="cover" id="cover" accept="image/jpeg,image/png,image/gif,image/webp">
                    <img id="cover-preview" class="cover-preview" style="display:none" alt="Cover preview">
                    <span class="form-hint">JPG, PNG, GIF, or WebP.</span>
                </div>
                <div class="form-group">
                    <label for="pdf">PDF File * (max 50 MB)</label>
                    <input type="file" name="pdf" id="pdf" accept=".pdf" required>
                </div>
                <button type="submit" class="btn btn--primary">Submit Book</button>
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

// Load genres into dropdown
(async function loadGenres() {
    const cached = sessionStorage.getItem('genres_v2');
    const genres = cached ? JSON.parse(cached) : await getJSON(`${API}/genres`);
    if (!cached) sessionStorage.setItem('genres_v2', JSON.stringify(genres));
    const sel = document.getElementById('genre_id');
    genres.forEach(g => {
        const opt = document.createElement('option');
        opt.value = g.genre_id;
        opt.textContent = g.name;
        sel.appendChild(opt);
    });
})().catch(e => console.error('genres:', e));

// Cover image preview
document.getElementById('cover').addEventListener('change', function() {
    const preview = document.getElementById('cover-preview');
    const file = this.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = e => { preview.src = e.target.result; preview.style.display = 'block'; };
        reader.readAsDataURL(file);
    } else {
        preview.style.display = 'none';
    }
});

document.getElementById('upload-form').addEventListener('submit', async function(e) {
    e.preventDefault();
    const alert = document.getElementById('upload-alert');
    const formData = new FormData(this);

    try {
        const r = await fetch(`${API}/upload`, { method: 'POST', body: formData });
        const data = await r.json();
        if (!r.ok) {
            alert.innerHTML = `<div class="alert alert--error">${data.error}</div>`;
        } else {
            const msg = data.status === 'approved'
                ? 'Book uploaded and automatically approved.'
                : 'Book submitted for admin approval.';
            alert.innerHTML = `<div class="alert alert--success">${msg}</div>`;
            showToast(msg, 'success');
            this.reset();
        }
    } catch(e) {
        alert.innerHTML = `<div class="alert alert--error">Upload failed.</div>`;
    }
});
</script>
</body>
</html>
