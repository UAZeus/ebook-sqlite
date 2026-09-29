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
    <title>E-Book Library</title>

</head>
<body>
<div class="layout">

<?php require 'partials/head.php'; ?>

<?php require 'partials/sidebar.php'; ?>

<div class="main-content">

    <section class="stats" id="stats">
        <div class="stats-grid">
            <div class="stat-card"><span class="stat-number" id="stat-books">—</span><span class="stat-label">Books</span></div>
            <div class="stat-card"><span class="stat-number" id="stat-authors">—</span><span class="stat-label">Authors</span></div>
            <div class="stat-card"><span class="stat-number" id="stat-genres">—</span><span class="stat-label">Genres</span></div>
            <div class="stat-card"><span class="stat-number" id="stat-activity">—</span><span class="stat-label">Activity</span></div>
        </div>
    </section>

    <section class="genres-bar" id="genres-bar">
        <div class="genres-bar-inner">
            <span class="genres-label">Browse by genre:</span>
            <div class="genre-tags" id="genre-tags"></div>
        </div>
    </section>

    <section class="search-bar">
        <form class="search-form" id="search-form" onsubmit="return doSearch(event)">
            <input type="search" name="search" id="search-input" placeholder="Search by title or author…" value="">
            <button type="submit" class="btn btn--primary">Search</button>
            <button type="button" class="btn btn--secondary" id="search-clear" style="display:none" onclick="clearSearch()">Clear</button>
        </form>
    </section>

    <main class="content" id="main-content"></main>

    <footer class="footer">
        <p>E-Book Library &mdash; Built with PHP &amp; SQLite</p>
    </footer>

</div>
</div>

<script>
const API = '/api';
const params = new URLSearchParams(location.search);
let currentGenre = params.get('genre') || '';
let currentSearch = params.get('search') || '';
let showBookmarked = params.has('bookmarked');
let currentPage = parseInt(params.get('page')) || 1;

function bookCardHTML(b) {
    const cover = b.cover_url
        ? `<img src="${b.cover_url}" alt="${esc(b.title)}" loading="lazy">`
        : `<div class="book-cover--placeholder"><span>${esc(b.title)}</span></div>`;
    const genre = b.genre ? `<span class="book-genre">${esc(b.genre)}</span>` : '';
    return `<a href="/book/${b.id}" class="book-card">
        <div class="book-cover">${cover}</div>
        <div class="book-info">
            <h3>${esc(b.title)}</h3>
            <p class="book-author">${esc(b.author)}</p>
            ${genre}
        </div>
    </a>`;
}

// Init search input from URL
document.getElementById('search-input').value = currentSearch;
if (currentSearch) document.getElementById('search-clear').style.display = 'inline-block';

// Load stats
getJSON(`${API}/books/statistics`).then(s => {
    document.getElementById('stat-books').textContent = s.total_books;
    document.getElementById('stat-authors').textContent = s.total_authors;
    document.getElementById('stat-genres').textContent = s.total_genres;
    document.getElementById('stat-activity').textContent = s.total_activity;
}).catch(e => console.error('stats:', e));

// Load genres
(async function loadGenres() {
    const cached = sessionStorage.getItem('genres_v2');
    const genres = cached ? JSON.parse(cached) : await getJSON(`${API}/genres`);
    if (!cached) sessionStorage.setItem('genres_v2', JSON.stringify(genres));
    const container = document.getElementById('genre-tags');
    let html = `<a href="?" class="genre-tag${currentGenre === '' ? ' genre-tag--active' : ''}">All</a>`;
    genres.forEach(g => {
        const active = currentGenre === g.name ? ' genre-tag--active' : '';
        html += `<a href="?genre=${encodeURIComponent(g.name)}" class="genre-tag${active}">${esc(g.name)}</a>`;
    });
    container.innerHTML = html;
})().catch(e => console.error('genres:', e));

// Load books and recommendations
async function loadContent() {
    const main = document.getElementById('main-content');
    let html = '';

    // Recommendations for logged-in users (hidden during search)
    <?php if ($user): ?>
    if (!currentSearch) {
        try {
            const recs = await getJSON(`${API}/recommendations?limit=6`);
            if (recs.length > 0) {
                html += `<section class="section"><h2 class="section-title">🎯 Recommended For You</h2><div class="book-grid">`;
                recs.forEach(b => { html += bookCardHTML(b); });
                html += `</div></section>`;
            }
        } catch(e) { console.error('recs:', e); }
    }
    <?php endif; ?>

    // Featured / genre-filtered / bookmarked books
    let bookUrl = `${API}/books?page=${currentPage}`;
    const qs = [];
    if (currentGenre) qs.push(`genre=${encodeURIComponent(currentGenre)}`);
    if (currentSearch) qs.push(`search=${encodeURIComponent(currentSearch)}`);
    if (showBookmarked) qs.push('bookmarked=1');
    if (qs.length) bookUrl += '&' + qs.join('&');

    const data = await getJSON(bookUrl);
    const sectionTitle = showBookmarked ? 'My Books' : (currentGenre ? esc(currentGenre) : 'Featured Books');
    html += `<section class="section"><h2 class="section-title">${sectionTitle}</h2>`;
    if (data.books.length === 0) {
        html += `<p class="empty-state">${currentSearch ? 'No books match your search.' : 'No books found.'}</p>`;
    } else {
        html += `<div class="book-grid">`;
        data.books.forEach(b => { html += bookCardHTML(b); });
        html += `</div>`;
    }
    html += `</section>`;

    if (data.totalPages > 1) {
        html += `<div class="pagination">
            <button ${currentPage <= 1 ? 'disabled' : ''} onclick="goPage(${currentPage - 1})">← Previous</button>
            <span class="page-info">Page ${data.page} of ${data.totalPages} (${data.total} books)</span>
            <button ${currentPage >= data.totalPages ? 'disabled' : ''} onclick="goPage(${currentPage + 1})">Next →</button>
        </div>`;
    }

    main.innerHTML = html;
}

function doSearch(e) {
    e.preventDefault();
    const q = document.getElementById('search-input').value.trim();
    const u = new URL(location.href);
    u.searchParams.set('search', q);
    u.searchParams.delete('genre');
    u.searchParams.delete('bookmarked');
    u.searchParams.delete('page');
    if (!q) u.searchParams.delete('search');
    location.href = u.toString();
    return false;
}

function clearSearch() {
    const u = new URL(location.href);
    u.searchParams.delete('search');
    u.searchParams.delete('page');
    location.href = u.toString();
}

function goPage(p) {
    const u = new URL(location.href);
    u.searchParams.set('page', p);
    if (currentGenre) u.searchParams.set('genre', currentGenre);
    else u.searchParams.delete('genre');
    if (currentSearch) u.searchParams.set('search', currentSearch);
    else u.searchParams.delete('search');
    if (showBookmarked) u.searchParams.set('bookmarked', '1');
    else u.searchParams.delete('bookmarked');
    location.href = u.toString();
}

loadContent();
</script>
</body>
</html>
