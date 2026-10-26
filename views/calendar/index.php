<?php
use App\Workspace;

$canCreate = Workspace::can('member');
$month = $anchor->format('m');
?>
<div class="page-head">
    <h1><?= e($label) ?></h1>
    <div class="head-actions">
        <div class="segmented" role="group" aria-label="View">
            <a href="<?= e(url('/calendar', ['view' => 'month', 'date' => $anchor->format('Y-m-d')])) ?>" class="<?= $view === 'month' ? 'active' : '' ?>">Month</a>
            <a href="<?= e(url('/calendar', ['view' => 'week', 'date' => $anchor->format('Y-m-d')])) ?>" class="<?= $view === 'week' ? 'active' : '' ?>">Week</a>
        </div>
        <a class="icon-btn" href="<?= e(url('/calendar', ['view' => $view, 'date' => $prev])) ?>" aria-label="Previous"><?= icon('chevron-left') ?></a>
        <a class="btn" href="<?= e(url('/calendar', ['view' => $view])) ?>">Today</a>
        <a class="icon-btn" href="<?= e(url('/calendar', ['view' => $view, 'date' => $next])) ?>" aria-label="Next"><?= icon('chevron-right') ?></a>
    </div>
</div>

<?php if ($view === 'month'): ?>
<div class="calendar card">
    <div class="cal-weekdays">
        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d): ?><div><?= $d ?></div><?php endforeach; ?>
    </div>
    <div class="cal-grid">
        <?php foreach ($days as $d):
            $key = $d->format('Y-m-d');
            $items = $byDay[$key] ?? [];
            $classes = ['cal-day'];
            if ($d->format('m') !== $month) $classes[] = 'other-month';
            if ($key === $today) $classes[] = 'today';
            if ($key < $today) $classes[] = 'past';
        ?>
            <div class="<?= implode(' ', $classes) ?>">
                <div class="cal-day-head">
                    <span class="cal-num"><?= $d->format('j') ?></span>
                    <?php if ($canCreate && $key >= $today): ?>
                        <a class="cal-add" href="<?= e(url('/posts/new', ['date' => $key])) ?>" aria-label="New post on <?= e($d->format('F j')) ?>"><?= icon('plus') ?></a>
                    <?php endif; ?>
                </div>
                <div class="cal-items">
                    <?php foreach (array_slice($items, 0, 4) as $p): ?>
                        <a class="cal-item status-<?= e($p['status']) ?>" href="<?= e(url('/posts/' . $p['id'])) ?>" title="<?= e(str_limit($p['content'], 120)) ?>">
                            <span class="cal-time"><?= e($p['time']) ?></span>
                            <span class="cal-dots"><?php foreach (array_slice($p['targets'], 0, 4) as $t) { echo provider_badge($t['provider'], 'pdot'); } ?></span>
                            <span class="cal-text"><?= e(str_limit($p['content'] ?: '(media)', 40)) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (count($items) > 4): ?>
                        <a class="cal-more" href="<?= e(url('/calendar', ['view' => 'week', 'date' => $key])) ?>">+<?= count($items) - 4 ?> more</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="week card">
    <?php foreach ($days as $d):
        $key = $d->format('Y-m-d');
        $items = $byDay[$key] ?? [];
    ?>
        <section class="week-day<?= $key === $today ? ' today' : '' ?>">
            <header class="week-day-head">
                <span class="week-dow"><?= $d->format('D') ?></span>
                <span class="week-num"><?= $d->format('j') ?></span>
                <?php if ($canCreate && $key >= $today): ?>
                    <a class="cal-add" href="<?= e(url('/posts/new', ['date' => $key])) ?>" aria-label="New post on <?= e($d->format('F j')) ?>"><?= icon('plus') ?></a>
                <?php endif; ?>
            </header>
            <div class="week-items">
                <?php if (!$items): ?><p class="muted small week-empty">No posts</p><?php endif; ?>
                <?php foreach ($items as $p): ?>
                    <a class="week-item status-<?= e($p['status']) ?>" href="<?= e(url('/posts/' . $p['id'])) ?>">
                        <div class="week-item-top">
                            <span class="cal-time"><?= e($p['time']) ?></span>
                            <?= status_badge($p['status']) ?>
                        </div>
                        <p><?= e(str_limit($p['content'] ?: '(media only)', 140)) ?></p>
                        <div class="avatar-stack"><?php foreach (array_slice($p['targets'], 0, 6) as $t) { echo account_avatar($t, 'xs'); } ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="legend">
    <?php foreach (['scheduled' => 'Scheduled', 'pending_approval' => 'Needs approval', 'published' => 'Published', 'failed' => 'Failed', 'draft' => 'Draft'] as $k => $l): ?>
        <span class="legend-item status-<?= $k ?>"><i></i><?= $l ?></span>
    <?php endforeach; ?>
</div>
