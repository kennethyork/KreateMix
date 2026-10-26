<?php use App\Autolists; use App\Workspace; ?>
<div class="page-head">
    <div>
        <h1>Autolists</h1>
        <p class="muted">Evergreen queues. At each time slot the next post in the list goes out, and the list starts over when it reaches the end.</p>
    </div>
</div>

<?php if ($lists): ?>
<div class="card post-list">
    <?php foreach ($lists as $l): ?>
        <a class="post-row" href="<?= e(url('/autolists/' . $l['id'])) ?>">
            <div class="post-row-main">
                <div class="post-row-meta">
                    <strong><?= e($l['name']) ?></strong>
                    <?= $l['active'] ? '<span class="badge status-published">Active</span>' : '<span class="badge">Paused</span>' ?>
                </div>
                <div class="muted small"><?= (int) $l['items'] ?> posts · <?= count(json_arr($l['slots'])) ?> slots a week · <?= (int) $l['runs'] ?> published<?= $l['next'] && $l['active'] ? ' · next ' . e($l['next']->format('D, M j g:i A')) : '' ?></div>
            </div>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (Workspace::can('member')): ?>
<details class="card add-block" <?= $lists ? '' : 'open' ?>>
    <summary class="btn"><?= icon('plus') ?> New autolist</summary>
    <?php partial('autolists/form', ['list' => null, 'accounts' => $accounts]); ?>
</details>
<?php endif; ?>
