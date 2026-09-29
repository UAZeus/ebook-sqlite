<?php
$currentRoute = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$isActive = fn(string $route): string => $currentRoute === $route || $currentRoute === rtrim($route, '/') ? 'sidebar-link--active' : '';
?>
<aside class="sidebar">
    <div class="sidebar-header">
        <a href="/" class="sidebar-logo">📚 Library</a>
    </div>
    <?php if ($user && !$auth->isAdmin()): ?>
    <div class="sidebar-user-section">
        <span class="sidebar-user">👤 <?= htmlspecialchars($user['name']) ?></span>
        <span class="sidebar-role"><?= ucfirst($auth->role()) ?></span>
    </div>
    <?php endif; ?>
    <nav class="sidebar-nav">
        <a href="/" class="sidebar-link <?= $currentRoute === '/' ? 'sidebar-link--active' : '' ?>">🏠 Home</a>
        <a href="/account" class="sidebar-link <?= $currentRoute === '/account' ? 'sidebar-link--active' : '' ?>">👤 My Account</a>
        <?php if ($auth->isAdmin()): ?>
            <a href="/admin" class="sidebar-link <?= $currentRoute === '/admin' ? 'sidebar-link--active' : '' ?>">⚙️ Dashboard</a>
        <?php elseif ($user): ?>
            <a href="/activity" class="sidebar-link <?= $currentRoute === '/activity' ? 'sidebar-link--active' : '' ?>">📖 My Activity</a>
            <a href="/?bookmarked=1" class="sidebar-link <?= strpos($_SERVER['QUERY_STRING']??'', 'bookmarked=1') !== false ? 'sidebar-link--active' : '' ?>">🔖 My Books</a>
            <a href="/preferences" class="sidebar-link <?= $currentRoute === '/preferences' ? 'sidebar-link--active' : '' ?>">🏷️ Preferences</a>
            <?php if ($auth->isLibrarian()): ?>
                <a href="/upload" class="sidebar-link <?= $currentRoute === '/upload' ? 'sidebar-link--active' : '' ?>">📤 Upload Book</a>
            <?php endif; ?>
        <?php endif; ?>
    </nav>
    <div class="sidebar-footer">
        <?php if ($user): ?>
            <a href="#" class="sidebar-logout" onclick="return confirmLogout()">Sign Out</a>
        <?php else: ?>
            <a href="/login" class="sidebar-login">Sign In</a>
            <a href="/register" class="sidebar-register">Register</a>
        <?php endif; ?>
    </div>
</aside>

<script>
function confirmLogout() {
    if (confirm('Are you sure you want to sign out?')) {
        location.href = '/logout';
    }
    return false;
}
</script>
