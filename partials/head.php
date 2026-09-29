<meta name="csrf-token" content="<?= $auth->csrfToken() ?>">
<link rel="preconnect" href="https://fonts.bunny.net">
<link rel="preconnect" href="https://fonts.bunny.net" crossorigin>
<link href="https://fonts.bunny.net/css?family=Inter:400,500,600,700" rel="stylesheet">
<link rel="stylesheet" href="/public/style.css?v=<?= filemtime(__DIR__ . '/../public/style.css') ?>">
<div id="toast-container"></div>
<?php
$flashes = $auth->getFlash();
if (!empty($_GET['signed_out'])):
    $flashes[] = ['type' => 'info', 'message' => 'You have been signed out.'];
endif;
?>
<?php if (!empty($flashes)): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    <?php foreach ($flashes as $flash): ?>
    showToast(<?= json_encode($flash['message']) ?>, <?= json_encode($flash['type']) ?>);
    <?php endforeach; ?>
});
</script>
<?php endif; ?>

<script>
window.CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.content || '';

const _fetch = window.fetch;
window.fetch = function(url, opts = {}) {
    opts = opts || {};
    opts.method = (opts.method || 'GET').toUpperCase();
    if (opts.method !== 'GET') {
        opts.headers = opts.headers || {};
        if (opts.headers instanceof Headers) {
            opts.headers.set('X-CSRF-Token', window.CSRF_TOKEN);
        } else {
            opts.headers['X-CSRF-Token'] = window.CSRF_TOKEN;
        }
    }
    return _fetch.call(window, url, opts);
};

function showToast(msg, type) {
    type = type || 'info';
    const container = document.getElementById('toast-container');
    const el = document.createElement('div');
    el.className = 'toast toast--' + type;
    el.textContent = msg;
    container.appendChild(el);
    setTimeout(() => {
        el.classList.add('toast-leave');
        setTimeout(() => el.remove(), 200);
    }, 3000);
}

async function getJSON(url) {
    const r = await fetch(url);
    if (!r.ok) throw new Error(await r.text());
    return r.json();
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}

function togglePass(btn) {
    const input = btn.previousElementSibling;
    const isPassword = input.type === 'password';
    input.type = isPassword ? 'text' : 'password';
    btn.textContent = isPassword ? '🙈' : '👁';
}
</script>
