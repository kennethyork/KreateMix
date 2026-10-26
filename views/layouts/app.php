<?php
use App\Auth;
use App\Db;
use App\Hooks;
use App\Workspace;

$user = Auth::user();
$ws = Workspace::current();
$workspaces = $user ? Workspace::forUser((int) $user['id']) : [];
$path = '/' . trim(substr((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), strlen(base_path())), '/');
$pending = $ws && Workspace::can('admin')
    ? (int) Db::value("SELECT COUNT(*) FROM posts WHERE workspace_id = ? AND status = 'pending_approval'", [$ws['id']])
    : 0;

$nav = Hooks::filter('nav', [
    ['label' => 'Dashboard', 'href' => '/', 'icon' => 'home', 'match' => '#^/$#'],
    ['label' => 'Calendar', 'href' => '/calendar', 'icon' => 'calendar', 'match' => '#^/calendar#'],
    ['label' => 'Posts', 'href' => '/posts', 'icon' => 'list', 'match' => '#^/posts(?!/new)#', 'badge' => $pending ?: null],
    ['label' => 'Analytics', 'href' => '/analytics', 'icon' => 'chart', 'match' => '#^/analytics#'],
    ['label' => 'Media', 'href' => '/media', 'icon' => 'image', 'match' => '#^/media#'],
    ['label' => 'Library', 'href' => '/library', 'icon' => 'book', 'match' => '#^/library#'],
    ['label' => 'Accounts', 'href' => '/accounts', 'icon' => 'users', 'match' => '#^/accounts#'],
]);
$wsNav = [
    ['label' => 'Team', 'href' => '/members', 'icon' => 'team', 'match' => '#^/members#'],
    ['label' => 'API & webhooks', 'href' => '/developers', 'icon' => 'code', 'match' => '#^/developers#'],
];
if (Workspace::can('admin')) {
    $wsNav[] = ['label' => 'Settings', 'href' => '/settings', 'icon' => 'settings', 'match' => '#^/settings#'];
}
$adminNav = Auth::isAdmin() ? [
    ['label' => 'Platform API keys', 'href' => '/admin/services', 'icon' => 'key', 'match' => '#^/admin/services#'],
    ['label' => 'Users & workspaces', 'href' => '/admin/users', 'icon' => 'shield', 'match' => '#^/admin/users#'],
] : [];

$navLink = function (array $item) use ($path): string {
    $active = preg_match($item['match'] ?? '#^' . preg_quote($item['href'], '#') . '#', $path);
    $badge = !empty($item['badge']) ? '<span class="nav-badge">' . e($item['badge']) . '</span>' : '';
    return '<a href="' . e(url($item['href'])) . '" class="nav-link' . ($active ? ' active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>'
        . icon($item['icon'] ?? 'dot') . '<span>' . e($item['label']) . '</span>' . $badge . '</a>';
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_path()) ?>">
<title><?= e(($title ? $title . ' · ' : '') . App\Config::get('app_name', 'MixCreate')) ?></title>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<script>try{var t=localStorage.getItem('mc-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
</head>
<body>
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <a class="brand" href="<?= e(url('/')) ?>"><?= icon('logo', 'brand-mark') ?><span><?= e(App\Config::get('app_name', 'MixCreate')) ?></span></a>
            <button class="icon-btn sidebar-close" type="button" data-toggle-sidebar aria-label="Close menu"><?= icon('x') ?></button>
        </div>

        <?php if ($ws): ?>
        <details class="ws-switch">
            <summary>
                <span class="ws-dot" style="background: <?= e($ws['color']) ?>"></span>
                <span class="ws-name"><?= e($ws['name']) ?></span>
                <?= icon('chevrons') ?>
            </summary>
            <div class="ws-menu">
                <?php foreach ($workspaces as $w): ?>
                    <form method="post" action="<?= e(url('/workspaces/' . $w['id'] . '/switch')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="ws-item<?= (int) $w['id'] === (int) $ws['id'] ? ' current' : '' ?>">
                            <span class="ws-dot" style="background: <?= e($w['color']) ?>"></span>
                            <span><?= e($w['name']) ?></span>
                            <small><?= e(Workspace::ROLES[$w['role']] ?? $w['role']) ?></small>
                        </button>
                    </form>
                <?php endforeach; ?>
                <?php if (Auth::isAdmin()): ?>
                    <a class="ws-item" href="<?= e(url('/workspaces/new')) ?>"><?= icon('plus') ?><span>New workspace</span></a>
                <?php endif; ?>
            </div>
        </details>

        <?php if (Workspace::can('member')): ?>
            <a class="btn btn-primary btn-block new-post" href="<?= e(url('/posts/new')) ?>"><?= icon('pen') ?> New post</a>
        <?php endif; ?>
        <?php endif; ?>

        <nav class="nav" aria-label="Main">
            <?php if ($ws): foreach ($nav as $item) { echo $navLink($item); } ?>
            <div class="nav-section">Workspace</div>
            <?php foreach ($wsNav as $item) { echo $navLink($item); } endif; ?>
            <?php if ($adminNav): ?>
                <div class="nav-section">Instance</div>
                <?php foreach ($adminNav as $item) { echo $navLink($item); } ?>
            <?php endif; ?>
        </nav>

        <div class="sidebar-foot">
            <a class="user-chip" href="<?= e(url('/profile')) ?>">
                <span class="avatar sm"><?= e(initials($user['name'] ?? '')) ?></span>
                <span class="user-meta"><strong><?= e($user['name'] ?? '') ?></strong><small><?= e($user['email'] ?? '') ?></small></span>
            </a>
            <div class="foot-actions">
                <button class="icon-btn" type="button" data-theme-toggle aria-label="Toggle dark mode"><?= icon('moon') ?></button>
                <form method="post" action="<?= e(url('/logout')) ?>">
                    <?= csrf_field() ?>
                    <button class="icon-btn" type="submit" aria-label="Sign out" title="Sign out"><?= icon('logout') ?></button>
                </form>
            </div>
        </div>
    </aside>
    <div class="scrim" data-toggle-sidebar></div>

    <div class="main">
        <header class="topbar">
            <button class="icon-btn" type="button" data-toggle-sidebar aria-label="Open menu"><?= icon('menu') ?></button>
            <span class="topbar-title"><?= e($title) ?></span>
            <?php if ($ws && Workspace::can('member')): ?>
                <a class="icon-btn" href="<?= e(url('/posts/new')) ?>" aria-label="New post"><?= icon('pen') ?></a>
            <?php endif; ?>
        </header>

        <div class="flashes" aria-live="polite">
            <?php foreach (flashes() as $f): ?>
                <div class="flash flash-<?= e($f['type']) ?>" role="status">
                    <?= icon($f['type'] === 'error' ? 'alert' : 'check') ?>
                    <span><?= e($f['message']) ?></span>
                    <button type="button" class="flash-close" aria-label="Dismiss"><?= icon('x') ?></button>
                </div>
            <?php endforeach; ?>
        </div>

        <main class="content">
            <?= $content ?>
        </main>
    </div>
</div>
<div class="toast-root" id="toasts" aria-live="polite"></div>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
