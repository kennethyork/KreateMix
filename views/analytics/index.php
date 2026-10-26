<?php
$fmt = fn(?int $n) => $n === null ? '—' : ($n >= 10000 ? number_format($n / 1000, 1) . 'k' : number_format($n));
?>
<div class="page-head">
    <h1>Analytics</h1>
    <form class="head-actions" method="get" action="<?= e(url('/analytics')) ?>">
        <select name="account" data-autosubmit aria-label="Account">
            <option value="">All accounts</option>
            <?php foreach ($accounts as $a): ?>
                <option value="<?= (int) $a['id'] ?>" <?= $accountId === (int) $a['id'] ? 'selected' : '' ?>><?= e($providers[$a['provider']]['name'] . ' · ' . $a['name']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="segmented" role="group" aria-label="Range">
            <?php foreach ([7, 30, 90] as $d): ?>
                <a href="<?= e(url('/analytics', array_filter(['days' => $d, 'account' => $accountId ?: null]))) ?>" class="<?= $days === $d ? 'active' : '' ?>"><?= $d ?>d</a>
            <?php endforeach; ?>
        </div>
    </form>
</div>

<?php if (!$lastImport): ?>
    <div class="notice notice-info"><?= icon('clock') ?><div>Numbers appear after the scheduler’s first import (it runs every 6 hours). Post counts below are already live.</div></div>
<?php endif; ?>

<div class="stats">
    <div class="stat">
        <span class="stat-label">Followers</span>
        <span class="stat-value"><?= $fmt($followersNow) ?></span>
        <?php if ($followersChange !== null): ?>
            <span class="stat-delta <?= $followersChange >= 0 ? 'up' : 'down' ?>"><?= $followersChange >= 0 ? '+' : '' ?><?= number_format($followersChange) ?> in <?= $days ?>d</span>
        <?php endif; ?>
    </div>
    <div class="stat"><span class="stat-label">Posts published</span><span class="stat-value"><?= $postCount ?></span></div>
    <div class="stat"><span class="stat-label">Likes</span><span class="stat-value"><?= $fmt($totals['likes']) ?></span></div>
    <div class="stat"><span class="stat-label">Replies</span><span class="stat-value"><?= $fmt($totals['replies']) ?></span></div>
    <div class="stat"><span class="stat-label">Shares</span><span class="stat-value"><?= $fmt($totals['shares']) ?></span></div>
    <?php if ($totals['impressions']): ?>
        <div class="stat"><span class="stat-label">Impressions</span><span class="stat-value"><?= $fmt($totals['impressions']) ?></span></div>
    <?php endif; ?>
</div>

<div class="grid-2">
    <section class="card">
        <h2>Followers</h2>
        <div class="chart" data-chart="line" data-series="<?= e(json_encode($followers)) ?>" data-label="Followers"></div>
    </section>
    <section class="card">
        <h2>Posts per day</h2>
        <div class="chart" data-chart="bar" data-series="<?= e(json_encode($postSeries)) ?>" data-label="Posts"></div>
    </section>
</div>

<section class="card">
    <h2>Top posts</h2>
    <?php if (!$top): ?>
        <p class="muted">No engagement data yet for this period.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Post</th><th>Account</th><th class="num">Likes</th><th class="num">Replies</th><th class="num">Shares</th><th class="num">Total</th></tr></thead>
        <?php foreach ($top as $p): $m = $p['m']; ?>
            <tr>
                <td><a href="<?= e(url('/posts/' . $p['id'])) ?>"><?= e(str_limit($p['content'] ?: '(media)', 70)) ?></a>
                    <div class="muted small"><?= e(local_time($p['published_at'], 'M j')) ?><?php if ($p['url']): ?> · <a href="<?= e($p['url']) ?>" target="_blank" rel="noopener">View</a><?php endif; ?></div></td>
                <td><?= provider_badge($p['provider'], 'pdot') ?> <?= e($p['account_name']) ?></td>
                <td class="num"><?= number_format((int) ($m['likes'] ?? $m['reactions'] ?? 0)) ?></td>
                <td class="num"><?= number_format((int) ($m['replies'] ?? $m['comments'] ?? 0)) ?></td>
                <td class="num"><?= number_format((int) ($m['shares'] ?? $m['reposts'] ?? 0)) ?></td>
                <td class="num"><strong><?= number_format($p['engagement']) ?></strong></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</section>
