<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

use App\Config\Auth;
use App\Config\Database;

$db = Database::connect();
$auth = Auth::init($db);
$user = $auth->user();
$bookId = (int) ($_GET['id'] ?? 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book — E-Book Library</title>

</head>
<body>
<div class="layout">

<?php require 'partials/head.php'; ?>

<?php require 'partials/sidebar.php'; ?>

<div class="main-content">

    <header class="page-header">
        <a href="/" class="back-link">← Back to Library</a>
    </header>

    <main class="content book-detail" id="book-content"></main>

    <footer class="footer">
        <p>E-Book Library &mdash; Built with PHP &amp; SQLite</p>
    </footer>

</div>
</div>

<script>
const API = '/api';
const bookId = <?= $bookId ?>;
if (!bookId) { location.href = '/'; }
const isAdmin = <?= $user && $auth->isAdmin() ? 'true' : 'false' ?>;

let currentBook = null;

async function loadBook() {
    const main = document.getElementById('book-content');

    try {
        const book = await getJSON(`${API}/books/${bookId}`);
        currentBook = book;
        const loggedIn = <?= $user ? 'true' : 'false' ?>;

        const cover = book.cover_url
            ? `<img src="${book.cover_url}" alt="${esc(book.title)}">`
            : `<div class="book-cover--placeholder book-cover--placeholder-lg"><span>${esc(book.title)}</span></div>`;

        const tags = [];
        if (book.genre) tags.push(`<span class="book-genre">${esc(book.genre)}</span>`);
        if (book.year) tags.push(`<span class="book-detail-year">${book.year}</span>`);
        if (book.pages) tags.push(`<span class="book-detail-pages">${book.pages} pages</span>`);

        const fileBtn = book.file_path
            ? `<a href="${API}/books/${book.id}/download" class="btn btn--primary" download>📥 Download PDF</a>
               <button class="btn btn--secondary" onclick="openReader(${book.id})">📖 Read Online</button>`
            : `<p class="no-file">No PDF available for this book yet.</p>`;

        const bookmarkBtn = !isAdmin && loggedIn
            ? `<button class="btn btn--bookmark ${book.bookmarked ? 'bookmarked' : ''}" onclick="toggleBookmark(${book.id})">
                 ${book.bookmarked ? '★ Saved' : '☆ Save'}</button>`
            : '';

        const isComplete = book.completed;
        const completeBtn = !isAdmin && loggedIn
            ? `<button class="btn btn--complete" id="btn-complete" style="display:${isComplete ? 'none' : 'inline-block'}" onclick="markComplete()">✔ Mark Complete</button>
               <span class="completed-badge" id="badge-completed" style="display:${isComplete ? 'inline-flex' : 'none'}">✔ Completed</span>`
            : '';

        main.innerHTML = `
            <div class="book-detail-header">
                <div class="book-detail-cover">${cover}</div>
                <div class="book-detail-meta">
                    <h1>${esc(book.title)}</h1>
                    <p class="book-detail-author">by ${esc(book.author)}</p>
                    <div class="book-detail-tags">${tags.join('')}</div>
                    ${book.description ? `<p class="book-detail-desc">${esc(book.description)}</p>` : ''}
                    <div class="book-detail-actions">
                        ${fileBtn}
                        ${bookmarkBtn}
                        ${completeBtn}
                    </div>
                </div>
            </div>
            <div id="reader-container"></div>
        `;

    } catch(e) {
        console.error('book detail:', e);
        main.innerHTML = `<h1>404</h1><p>Book not found.</p><a href="/">Back to library</a>`;
    }
}

function openReader(id) {
    const container = document.getElementById('reader-container');
    if (container.querySelector('iframe')) return;
    container.innerHTML = `
        <section class="section">
            <h2 class="section-title">📖 ${esc(currentBook.title)}</h2>
            <div class="pdf-reader">
                <iframe src="${API}/books/${id}/read" width="100%" height="700px"></iframe>
            </div>
        </section>`;
    container.scrollIntoView({ behavior: 'smooth' });
    if (!isAdmin) {
        fetch(`${API}/activity`, {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({ event: 'book_opened', book_id: id })
        }).catch(e => console.error('log opened:', e));
    }
}

function markComplete() {
    fetch(`${API}/activity`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ event: 'book_completed', book_id: bookId })
    }).then(() => {
        document.getElementById('btn-complete').style.display = 'none';
        document.getElementById('badge-completed').style.display = 'inline-flex';
        showToast('Marked as completed!', 'success');
    }).catch(e => console.error('mark complete:', e));
}

async function toggleBookmark(id) {
    const r = await fetch(`${API}/bookmarks`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ book_id: id })
    });
    const data = await r.json();
    const btn = document.querySelector('.btn--bookmark');
    btn.innerHTML = data.bookmarked ? '★ Saved' : '☆ Save';
    btn.classList.toggle('bookmarked', data.bookmarked);
    showToast(data.bookmarked ? 'Bookmarked!' : 'Bookmark removed.', data.bookmarked ? 'success' : 'info');
}

loadBook();
</script>
</body>
</html>
