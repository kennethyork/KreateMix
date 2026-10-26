<?php
use App\Controllers\PostsController;
use App\Workspace;

$tabs = ['' => 'All'] + PostsController::STATUSES;
unset($tabs['publishing']);
$qs = fn(array $over) => url('/posts', array_filter(array_merge(['status' => $status] + $filters, $over), fn($v) => $v !== '' && $v !== 0 && $v !== null));
?>
<div class="page-head">
    <h1>Posts</h1>
    <?php if (Workspace::can('member')): ?>
        <a class="btn btn-primary" href="<?= e(url('/posts/new')) ?>"><?= icon('pen') ?> New post</a>
    <?php endif; ?>
</div>

<nav class="tabs" aria-label="Filter by status">
    <?php foreach ($tabs as $key => $label): ?>
        <?php $n = $key === '' ? array_sum($counts) : (int) ($counts[$key] ?? 0); ?>
        <?php if ($key === 'pending_approval' && !$n && $status !== $key) continue; ?>
        <a href="<?= e($qs(['status' => $key, 'page' => null])) ?>" class="<?= $status === $key ? 'active' : '' ?>">
            <?= e($label) ?> <span class="count"><?= $n ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<form class="filters" method="get" action="<?= e(url('/posts')) ?>">
    <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search text" aria-label="Search text">
    <select name="account" aria-label="Account" data-autosubmit>
        <option value="">All accounts</option>
        <?php foreach ($accounts as $a): ?>
            <option value="<?= (int) $a['id'] ?>" <?= $filters['account'] === (int) $a['id'] ? 'selected' : '' ?>><?= e(provider_meta($a['provider'])['name'] . ' · ' . $a['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($tags): ?>
    <select name="tag" aria-label="Tag" data-autosubmit>
        <option value="">All tags</option>
        <?php foreach ($tags as $t): ?>
            <option value="<?= (int) $t['id'] ?>" <?= $filters['tag'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button class="btn" type="submit">Filter</button>
</form>

<?php if (!$posts): ?>
    <div class="card empty-state">
        <?= icon('list', 'empty-icon') ?>
        <p class="muted">No posts here yet.</p>
    </div>
<?php else: ?>
    <div class="card post-list">
        <?php foreach ($posts as $post) { partial('partials/post_row', ['post' => $post]); } ?>
    </div>
<?php endif; ?>

<?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Pages">
        <?php if ($page > 1): ?><a class="btn" href="<?= e($qs(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
        <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="btn" href="<?= e($qs(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
    </nav>
<?php endif; ?>
