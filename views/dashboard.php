<?php
use App\Auth;
use App\Workspace;

$hour = (int) local_time(now(), 'G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
?>
<div class="page-head">
    <div>
        <h1><?= e($greeting) ?>, <?= e(explode(' ', $user['name'])[0]) ?></h1>
        <p class="muted"><?= e(local_time(now(), 'l, F j')) ?></p>
    </div>
    <?php if (Workspace::can('member')): ?>
        <a class="btn btn-primary" href="<?= e(url('/posts/new')) ?>"><?= icon('pen') ?> New post</a>
    <?php endif; ?>
</div>

<?php if ($overdue && ($cronAge === null || $cronAge > 300)): ?>
    <div class="notice notice-warn">
        <?= icon('alert') ?>
        <div>
            <strong><?= $overdue ?> scheduled post<?= $overdue === 1 ? ' is' : 's are' ?> overdue.</strong>
            <?php if (Auth::isAdmin()): ?>
                The scheduler isn’t running<?= $cronAge !== null ? ' (last run ' . e(round($cronAge / 60)) . ' minutes ago)' : '' ?>. Add this cron job on your server:
                <code class="copy" data-copy>* * * * * php <?= e(BASE_PATH) ?>/cron.php &gt;/dev/null 2&gt;&amp;1</code>
            <?php else: ?>
                Ask your MixCreate admin to check the scheduler.
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!$accounts): ?>
    <div class="card empty-state">
        <?= icon('users', 'empty-icon') ?>
        <h2>Connect your first account</h2>
        <p class="muted">Link X, Mastodon, Bluesky, Instagram and more to start scheduling.</p>
        <a class="btn btn-primary" href="<?= e(url('/accounts')) ?>">Connect accounts</a>
    </div>
<?php endif; ?>

<?php if ($unauthorized): ?>
    <div class="notice notice-error">
        <?= icon('alert') ?>
        <div>
            <strong>Reconnect needed:</strong>
            <?= e(implode(', ', array_map(fn($a) => $a['name'] . ' (' . provider_meta($a['provider'])['name'] . ')', $unauthorized))) ?>.
            Posts to these accounts will fail until they’re reconnected. <a href="<?= e(url('/accounts')) ?>">Go to accounts</a>
        </div>
    </div>
<?php endif; ?>

<div class="stats">
    <a class="stat" href="<?= e(url('/posts', ['status' => 'scheduled'])) ?>">
        <span class="stat-label">Scheduled</span>
        <span class="stat-value"><?= (int) ($counts['scheduled'] ?? 0) ?></span>
    </a>
    <a class="stat" href="<?= e(url('/posts', ['status' => 'pending_approval'])) ?>">
        <span class="stat-label">Needs approval</span>
        <span class="stat-value"><?= (int) ($counts['pending_approval'] ?? 0) ?></span>
    </a>
    <a class="stat" href="<?= e(url('/posts', ['status' => 'draft'])) ?>">
        <span class="stat-label">Drafts</span>
        <span class="stat-value"><?= (int) ($counts['draft'] ?? 0) ?></span>
    </a>
    <a class="stat" href="<?= e(url('/posts', ['status' => 'published'])) ?>">
        <span class="stat-label">Published this week</span>
        <span class="stat-value"><?= $publishedWeek ?></span>
    </a>
</div>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <h2>Up next</h2>
            <a class="link" href="<?= e(url('/calendar')) ?>">Calendar</a>
        </div>
        <?php if (!$upcoming): ?>
            <p class="muted">Nothing scheduled.</p>
        <?php endif; ?>
        <div class="post-list compact">
            <?php foreach ($upcoming as $post) { partial('partials/post_row', ['post' => $post]); } ?>
        </div>
    </section>

    <div class="stack">
        <?php if ($failed): ?>
        <section class="card">
            <div class="card-head">
                <h2>Failed</h2>
                <a class="link" href="<?= e(url('/posts', ['status' => 'failed'])) ?>">All failed</a>
            </div>
            <div class="post-list compact">
                <?php foreach ($failed as $post) { partial('partials/post_row', ['post' => $post]); } ?>
            </div>
        </section>
        <?php endif; ?>
        <section class="card">
            <div class="card-head">
                <h2>Recently published</h2>
                <a class="link" href="<?= e(url('/analytics')) ?>">Analytics</a>
            </div>
            <?php if (!$recent): ?>
                <p class="muted">Nothing published yet.</p>
            <?php endif; ?>
            <div class="post-list compact">
                <?php foreach ($recent as $post) { partial('partials/post_row', ['post' => $post]); } ?>
            </div>
        </section>
    </div>
</div>
