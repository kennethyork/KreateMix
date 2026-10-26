<?php
/** @var array $post decorated by PostsController::decorate */
$when = $post['published_at'] ?? $post['scheduled_at'];
?>
<a class="post-row" href="<?= e(url('/posts/' . $post['id'])) ?>">
    <div class="post-row-main">
        <div class="post-row-meta">
            <?= status_badge($post['status']) ?>
            <?php if ($when): ?>
                <span class="muted small"><?= e(local_time($when, 'D, M j · g:i A')) ?></span>
            <?php endif; ?>
            <?php foreach ($post['tags'] as $t): ?>
                <span class="tag" style="--tc: <?= e($t['color']) ?>"><?= e($t['name']) ?></span>
            <?php endforeach; ?>
        </div>
        <p class="post-row-text"><?= $post['content'] !== '' ? e(str_limit($post['content'], 220)) : '<span class="muted">No text</span>' ?></p>
        <div class="avatar-stack">
            <?php foreach (array_slice($post['targets'], 0, 8) as $t): ?>
                <span class="target<?= $t['status'] === 'failed' ? ' is-failed' : '' ?>"><?= account_avatar($t, 'xs') ?></span>
            <?php endforeach; ?>
            <?php if (count($post['targets']) > 8): ?><span class="muted small">+<?= count($post['targets']) - 8 ?></span><?php endif; ?>
        </div>
    </div>
    <?php if (!empty($post['thumb'])): ?>
        <div class="post-row-thumb">
            <?php if (str_starts_with($post['thumb']['mime'], 'video/')): ?>
                <video src="<?= e(url($post['thumb']['path'])) ?>#t=0.1" muted preload="metadata"></video>
                <span class="play"><?= icon('play') ?></span>
            <?php else: ?>
                <img src="<?= e(url($post['thumb']['path'])) ?>" alt="" loading="lazy">
            <?php endif; ?>
        </div>
    <?php endif; ?>
</a>
