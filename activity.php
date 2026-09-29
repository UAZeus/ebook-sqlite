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
    <title>My Activity — E-Book Library</title>

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
        <div id="activity-content"></div>
    </main>

    <footer class="footer">
        <p>E-Book Library &mdash; Built with PHP &amp; SQLite</p>
    </footer>

</div>
</div>

<script>
const API = '/api';

const params = new URLSearchParams(location.search);
let currentTab = params.get('tab') || 'opened';
let currentPage = parseInt(params.get('page')) || 1;

function buildUrl() {
    return `${API}/activity?type=${currentTab}&page=${currentPage}`;
}

async function loadActivity() {
    const main = document.getElementById('activity-content');

    // Tab bar
    let html = `<div class="tab-bar">
        <button class="tab ${currentTab === 'opened' ? 'tab--active' : ''}" onclick="switchTab('opened')">📖 Opened</button>
        <button class="tab ${currentTab === 'completed' ? 'tab--active' : ''}" onclick="switchTab('completed')">✔ Completed</button>
    </div>`;

    try {
        const data = await getJSON(buildUrl());
        const items = data.items || [];

        if (items.length === 0) {
            html += `<p class="empty-state">${currentTab === 'opened' ? 'No books opened yet.' : 'No books completed yet.'} <a href="/">Browse the library</a></p>`;
        } else {
            html += '<div class="activity-list">';
            items.forEach(a => {
                const cover = a.cover_url
                    ? `<img src="${a.cover_url}" alt="">`
                    : `<div class="activity-placeholder">${esc(a.title[0])}</div>`;
                const badgeLabel = a.event_type === 'book_completed' ? 'Completed' : 'Opened';
                const eventClass = a.event_type === 'book_completed' ? 'completed' : 'opened';
                html += `<div class="activity-item-wrap">
                    <a href="/book/${a.book_id}" class="activity-item">
                        <div class="activity-cover">${cover}</div>
                        <div class="activity-info">
                            <strong>${esc(a.title)}</strong>
                            <span class="activity-author">${esc(a.author)}</span>
                            <span class="activity-event activity-event--${eventClass}">${badgeLabel}</span>
                        </div>
                        <span class="activity-date">${a.created_at}</span>
                    </a>
                    <button class="activity-delete" onclick="deleteEvent(${a.log_id})" title="Remove from history">✕</button>
                </div>`;
            });
            html += '</div>';
        }

        if (data.totalPages > 1) {
            html += `<div class="pagination">
                <button ${currentPage <= 1 ? 'disabled' : ''} onclick="goPage(${currentPage - 1})">← Previous</button>
                <span class="page-info">Page ${data.page} of ${data.totalPages}</span>
                <button ${currentPage >= data.totalPages ? 'disabled' : ''} onclick="goPage(${currentPage + 1})">Next →</button>
            </div>`;
        }
    } catch(e) {
        html += `<p class="empty-state">Failed to load activity.</p>`;
    }

    main.innerHTML = html;
}

function switchTab(tab) {
    currentTab = tab;
    currentPage = 1;
    const u = new URL(location.href);
    u.searchParams.set('tab', tab);
    u.searchParams.delete('page');
    history.replaceState(null, '', u.toString());
    loadActivity();
}

function goPage(p) {
    currentPage = p;
    const u = new URL(location.href);
    u.searchParams.set('tab', currentTab);
    u.searchParams.set('page', String(p));
    history.replaceState(null, '', u.toString());
    loadActivity();
}

async function deleteEvent(logId) {
    if (!confirm('Remove this entry from your history? It will no longer affect your recommendations.')) return;
    await fetch(`${API}/activity/${logId}`, { method: 'DELETE' });
    showToast('Activity removed.', 'info');
    loadActivity();
}

loadActivity();
</script>
</body>
</html>
