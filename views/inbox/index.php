<?php
use App\Workspace;

$open = (int) ($counts['new'] ?? 0) + (int) ($counts['read'] ?? 0);
$tabs = ['open' => ['Open', $open], 'replied' => ['Replied', (int) ($counts['replied'] ?? 0)], 'archived' => ['Archived', (int) ($counts['archived'] ?? 0)], 'all' => ['All', array_sum($counts)]];
$canReply = Workspace::can('member');
?>
<div class="page-head">
    <div>
        <h1>Inbox</h1>
        <p class="muted">Comments and replies on posts published in the last 14 days. Checked every 15 minutes.</p>
    </div>
    <div class="head-actions">
        <?php if (($counts['new'] ?? 0) > 0): ?>
            <form method="post" action="<?= e(url('/inbox/read-all')) ?>"><?= csrf_field() ?><button class="btn" type="submit">Mark all read</button></form>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/inbox/refresh')) ?>"><?= csrf_field() ?><button class="btn" type="submit"><?= icon('refresh') ?> Check now</button></form>
    </div>
</div>

<nav class="tabs">
    <?php foreach ($tabs as $k => [$label, $n]): ?>
        <a href="<?= e(url('/inbox', array_filter(['status' => $k, 'account' => $accountId ?: null]))) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= e($label) ?> <span class="count"><?= $n ?></span></a>
    <?php endforeach; ?>
</nav>

<form class="filters" method="get" action="<?= e(url('/inbox')) ?>">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <select name="account" data-autosubmit aria-label="Account">
        <option value="">All accounts</option>
        <?php foreach ($accounts as $a): ?>
            <option value="<?= (int) $a['id'] ?>" <?= $accountId === (int) $a['id'] ? 'selected' : '' ?>><?= e(provider_meta($a['provider'])['name'] . ' · ' . $a['name']) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if (!$items): ?>
    <div class="card empty-state">
        <?= icon('inbox', 'empty-icon') ?>
        <p class="muted">Nothing here. Comments show up for <?= e(implode(', ', $supported)) ?>.</p>
    </div>
<?php else: ?>
<div class="inbox-list">
    <?php foreach ($items as $i): ?>
        <article class="card inbox-item<?= $i['status'] === 'new' ? ' is-new' : '' ?>" data-inbox-item="<?= (int) $i['id'] ?>">
            <div class="inbox-head">
                <span class="avatar sm"><?= e(initials($i['author_name'] ?: $i['author_handle'] ?: '?')) ?><?php if ($i['author_avatar']): ?><img src="<?= e($i['author_avatar']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?></span>
                <div class="grow">
                    <strong><?= e($i['author_name'] ?: $i['author_handle']) ?></strong>
                    <?php if ($i['author_handle'] && $i['author_handle'] !== $i['author_name']): ?><span class="muted small">@<?= e($i['author_handle']) ?></span><?php endif; ?>
                    <div class="muted small"><?= provider_badge($i['provider'], 'pdot') ?> on <?= e($i['account_name']) ?> · <?= e(local_time($i['remote_created_at'], 'M j, g:i A')) ?></div>
                </div>
                <?php if ($i['status'] === 'new'): ?><span class="badge status-scheduled">New</span><?php endif; ?>
                <?php if ($i['status'] === 'replied'): ?><span class="badge status-published">Replied</span><?php endif; ?>
            </div>
            <p class="inbox-text"><?= nl2br(e($i['text'])) ?></p>
            <?php if ($i['post_content'] !== null): ?>
                <a class="inbox-context" href="<?= e(url('/posts/' . $i['post_id'])) ?>">On: <?= e(str_limit($i['post_content'] ?: '(media)', 100)) ?></a>
            <?php endif; ?>
            <?php if ($i['status'] === 'replied'): ?>
                <div class="inbox-reply-done"><?= icon('reply') ?> <span><?= e($i['reply_text']) ?></span> <span class="muted small">— <?= e($i['replied_by_name'] ?? '') ?>, <?= e(local_time($i['replied_at'], 'M j')) ?></span></div>
            <?php elseif ($canReply): ?>
                <form class="inbox-reply" method="post" action="<?= e(url('/inbox/' . $i['id'] . '/reply')) ?>">
                    <?= csrf_field() ?>
                    <textarea name="text" rows="1" placeholder="Reply as <?= e($i['account_name']) ?>…" required></textarea>
                    <button class="btn btn-primary btn-sm" type="submit">Reply</button>
                </form>
            <?php endif; ?>
            <div class="inbox-actions">
                <?php if ($i['url']): ?><a class="link" href="<?= e($i['url']) ?>" target="_blank" rel="noopener">Open <?= icon('external') ?></a><?php endif; ?>
                <?php if ($canReply): ?>
                    <?php if ($i['status'] !== 'archived'): ?>
                        <form method="post" action="<?= e(url('/inbox/' . $i['id'] . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="archived"><button class="link" type="submit">Archive</button></form>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('/inbox/' . $i['id'] . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="read"><button class="link" type="submit">Unarchive</button></form>
                    <?php endif; ?>
                    <?php if ($i['status'] === 'new'): ?>
                        <form method="post" action="<?= e(url('/inbox/' . $i['id'] . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="read"><button class="link" type="submit">Mark read</button></form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
