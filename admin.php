<?php

declare(strict_types=1);

require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/auth.php';

use App\Config\Auth;
use App\Config\Database;

$db = Database::connect();
$auth = Auth::init($db);
$user = $auth->requireRole('admin');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard — E-Book Library</title>

    <style>
        .edit-dialog { position:fixed; inset:0; background:rgba(0,0,0,0.5); display:flex; align-items:center; justify-content:center; z-index:100; }
        .edit-dialog .auth-card { max-width:500px; }
    </style>
</head>
<body>
<div class="layout">

<?php require 'partials/head.php'; ?>
<?php require 'partials/sidebar.php'; ?>

<div class="main-content">

    <header class="page-header">
        <a href="/" class="back-link">← Back to Library</a>
    </header>

    <main class="content" id="admin-content"></main>

    <div id="edit-modal" style="display:none"></div>

    <footer class="footer">
        <p>E-Book Library &mdash; Built with PHP &amp; SQLite</p>
    </footer>

</div>
</div>

<script>
const API = '/api';

// ─── Load everything ──────────────────────────────────────────

async function loadDashboard() {
    const main = document.getElementById('admin-content');
    main.innerHTML = '<p class="empty-state">Loading dashboard…</p>';

    try {
        const [stats, pending, audit, users] = await Promise.all([
            getJSON(`${API}/admin/stats`),
            getJSON(`${API}/admin/pending`),
            getJSON(`${API}/admin/audit?days=7`),
            getJSON(`${API}/admin/users`),
        ]);

        const maxGenre = Math.max(...stats.genre_dist.map(g => g.count), 1);
        const maxDay = Math.max(...audit.overview.map(d => d.total), 1);

        let html = '';

        // ── Stats cards ──
        html += `<div class="admin-stats-grid">
            <div class="admin-stat-card"><span class="admin-stat-number">${stats.total_books}</span><span class="admin-stat-label">Total Books</span></div>
            <div class="admin-stat-card"><span class="admin-stat-number">${pending.length}</span><span class="admin-stat-label">Pending</span></div>
            <div class="admin-stat-card"><span class="admin-stat-number">${stats.total_users}</span><span class="admin-stat-label">Users</span></div>
            <div class="admin-stat-card"><span class="admin-stat-number">${stats.total_activity}</span><span class="admin-stat-label">Activity Events</span></div>
        </div>`;

        // ── Pending requests ──
        if (pending.length > 0) {
            html += `<section class="section">
                <h2 class="section-title">⏳ Pending Requests (${pending.length})</h2>
                <table class="admin-table">
                    <thead><tr><th>Title</th><th>Author</th><th>Uploaded by</th><th>Date</th><th>Actions</th></tr></thead>
                    <tbody>${pending.map(b => `<tr>
                        <td><a href="/book/${b.id}">${esc(b.title)}</a></td>
                        <td>${esc(b.author)}</td>
                        <td>${esc(b.uploader_name || 'Unknown')}</td>
                        <td>${b.created_at}</td>
                        <td class="actions-cell">
                            <button class="btn-action btn-approve" onclick="approve(${b.id})">✔ Approve</button>
                            <button class="btn-action btn-reject" onclick="reject(${b.id})">✕ Reject</button>
                        </td>
                    </tr>`).join('')}</tbody>
                </table>
            </section>`;
        } else {
            html += `<section class="section">
                <h2 class="section-title">⏳ Pending Requests</h2>
                <p class="empty-state">No pending requests. Everything is up to date.</p>
            </section>`;
        }

        // ── 📚 Books by Genre ──
        html += `<section class="section">
            <h2 class="section-title">📚 Books by Genre</h2>
            <div class="chart-bars">
                ${stats.genre_dist.map(g => `
                    <div class="chart-row">
                        <span class="chart-label">${esc(g.name)}</span>
                        <div class="chart-bar-track">
                            <div class="chart-bar" style="width:${(g.count / maxGenre * 100).toFixed(1)}%">${g.count}</div>
                        </div>
                        <span class="chart-value">${g.count}</span>
                    </div>
                `).join('')}
            </div>
        </section>`;

        // ── 📈 Daily Activity (last 7 days) ──
        if (audit.overview.length > 0) {
            html += `<section class="section">
                <h2 class="section-title">📈 Daily Activity (last ${audit.days} days)</h2>
                <div class="chart-bars">
                    ${audit.overview.map(d => `
                        <div class="chart-row">
                            <span class="chart-label">${d.day}</span>
                            <div class="chart-bar-track chart-bar-track--stacked">
                                <div class="chart-bar chart-bar--opened" style="width:${(d.opened / maxDay * 100).toFixed(1)}%" title="Opened: ${d.opened}"></div>
                                <div class="chart-bar chart-bar--completed" style="width:${(d.completed / maxDay * 100).toFixed(1)}%" title="Completed: ${d.completed}"></div>
                            </div>
                            <span class="chart-value">${d.total}</span>
                        </div>
                    `).join('')}
                </div>
            </section>`;
        }

        // ── Top Users ──
        if (audit.topUsers.length > 0) {
            html += `<section class="section">
                <h2 class="section-title">👤 Top Active Users</h2>
                <table class="admin-table">
                    <thead><tr><th>Name</th><th>Email</th><th>Events</th></tr></thead>
                    <tbody>${audit.topUsers.map(u => `<tr>
                        <td>${esc(u.name)}</td><td>${esc(u.email)}</td><td>${u.events}</td>
                    </tr>`).join('')}</tbody>
                </table>
            </section>`;
        }

        // ── Users ──
        html += `<section class="section">
            <h2 class="section-title">👥 All Users</h2>
            <table class="admin-table">
                <thead><tr><th>Name</th><th>Email</th><th>Role</th><th>Actions</th></tr></thead>
                <tbody>${users.map(u => {
                    const roleLabel = u.role === 'librarian' ? 'Librarian' : 'Viewer';
                    const promoteBtn = u.role === 'viewer' ? `<button class="btn-action btn-approve" onclick="promoteToLibrarian('${u.user_id}')">Upgrade to Librarian</button>` : '';
                    const demoteBtn = u.role === 'librarian' ? `<button class="btn-action btn-reject" onclick="demoteToViewer('${u.user_id}')">Demote to Viewer</button>` : '';
                    return `<tr>
                        <td>${esc(u.name)}</td>
                        <td>${esc(u.email)}</td>
                        <td>${roleLabel}</td>
                        <td class="actions-cell">${promoteBtn}${demoteBtn}<button class="btn-action" style="color:#c0392b;" onclick="deleteUser('${u.user_id}')">Delete</button></td>
                    </tr>`;
                }).join('')}</tbody>
            </table>
        </section>`;

        main.innerHTML = html;

    } catch(e) {
        console.error('dashboard:', e);
        main.innerHTML = `<p class="empty-state">Failed to load dashboard.</p>`;
    }
}

// ─── Approve / Reject ─────────────────────────────────────────

async function approve(id) {
    await fetch(`${API}/admin/books/${id}/approve`, { method: 'POST' });
    showToast('Book approved!', 'success');
    loadDashboard();
}

async function reject(id) {
    if (!confirm('Reject this book?')) return;
    await fetch(`${API}/admin/books/${id}/reject`, { method: 'POST' });
    showToast('Book rejected.', 'info');
    loadDashboard();
}

// ─── User management ────────────────────────────────────────────

async function promoteToLibrarian(id) {
    if (!confirm('Upgrade this user to Librarian?')) return;
    await fetch(`${API}/admin/users/${id}/role`, {
        method: 'PUT',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({role: 'librarian'})
    });
    showToast('User upgraded to Librarian.', 'success');
    loadDashboard();
}

async function demoteToViewer(id) {
    if (!confirm('Demote this user back to Viewer?')) return;
    await fetch(`${API}/admin/users/${id}/role`, {
        method: 'PUT',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({role: 'viewer'})
    });
    showToast('User demoted to Viewer.', 'info');
    loadDashboard();
}

async function deleteUser(id) {
    if (!confirm('Delete this user and all their data? This cannot be undone.')) return;
    if (!confirm('Are you sure?')) return;
    await fetch(`${API}/admin/users/${id}`, { method: 'DELETE' });
    showToast('User deleted.', 'info');
    loadDashboard();
}

loadDashboard();
</script>
</body>
</html>
