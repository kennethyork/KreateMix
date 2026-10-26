<?php
/** Report body shared by the in-app preview and the public share page. @var array $a */
use App\Analytics;

$fmt = fn($n) => $n === null ? '—' : ($n >= 10000 ? number_format($n / 1000, 1) . 'k' : number_format((int) $n));
$tz = $tz ?? user_tz();
?>
<div class="report">
    <div class="stats">
        <div class="stat">
            <span class="stat-label">Followers</span>
            <span class="stat-value"><?= $fmt($a['followersNow']) ?></span>
            <?php if ($a['followersChange'] !== null): ?>
                <span class="stat-delta <?= $a['followersChange'] >= 0 ? 'up' : 'down' ?>"><?= $a['followersChange'] >= 0 ? '+' : '' ?><?= number_format($a['followersChange']) ?></span>
            <?php endif; ?>
        </div>
        <div class="stat"><span class="stat-label">Posts</span><span class="stat-value"><?= $a['totals']['posts'] ?></span></div>
        <div class="stat"><span class="stat-label">Engagement</span><span class="stat-value"><?= $fmt($a['totals']['engagement']) ?></span></div>
        <div class="stat"><span class="stat-label">Impressions</span><span class="stat-value"><?= $fmt($a['totals']['impressions'] ?: null) ?></span></div>
        <div class="stat"><span class="stat-label">Engagement rate</span><span class="stat-value"><?= $a['rate'] === null ? '—' : $a['rate'] . '%' ?></span></div>
    </div>
    <div class="grid-2">
        <section class="card"><h2>Followers</h2><div class="chart" data-chart="line" data-series="<?= e(json_encode($a['followers'])) ?>" data-label="Followers"></div></section>
        <section class="card"><h2>Engagement per day</h2><div class="chart" data-chart="bar" data-series="<?= e(json_encode($a['engagementSeries'])) ?>" data-label="Engagement"></div></section>
    </div>
    <?php if ($a['perNetwork']): ?>
    <section class="card">
        <h2>By network</h2>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Network</th><th class="num">Followers</th><th class="num">Change</th><th class="num">Posts</th><th class="num">Engagement</th><th class="num">Eng. rate</th></tr></thead>
            <?php foreach ($a['perNetwork'] as $n): ?>
                <tr>
                    <td><?= provider_badge($n['provider'], 'pdot') ?> <?= e(Analytics::providerName($n['provider'])) ?></td>
                    <td class="num"><?= $fmt($n['followers']) ?></td>
                    <td class="num"><?= $n['followers_change'] === null ? '—' : (($n['followers_change'] >= 0 ? '+' : '') . number_format($n['followers_change'])) ?></td>
                    <td class="num"><?= $n['posts'] ?></td>
                    <td class="num"><?= $fmt($n['engagement']) ?></td>
                    <td class="num"><?= $n['rate'] === null ? '—' : $n['rate'] . '%' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </section>
    <?php endif; ?>
    <section class="card">
        <h2>Top posts</h2>
        <?php if (!$a['top']): ?><p class="muted">No engagement data for this period.</p><?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Post</th><th>Network</th><th class="num">Likes</th><th class="num">Comments</th><th class="num">Total</th></tr></thead>
            <?php foreach ($a['top'] as $p): $m = $p['m']; ?>
                <tr>
                    <td><?= e(str_limit($p['content'] ?: '(media)', 90)) ?><div class="muted small"><?= e(local_time($p['published_at'], 'M j', $tz)) ?><?php if ($p['url']): ?> · <a href="<?= e($p['url']) ?>" target="_blank" rel="noopener">View</a><?php endif; ?></div></td>
                    <td><?= provider_badge($p['provider'], 'pdot') ?> <?= e($p['account_name']) ?></td>
                    <td class="num"><?= number_format((int) ($m['likes'] ?? $m['reactions'] ?? 0)) ?></td>
                    <td class="num"><?= number_format((int) ($m['replies'] ?? $m['comments'] ?? 0)) ?></td>
                    <td class="num"><strong><?= number_format($p['engagement']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </section>
    <?php if ($a['best']['top']): ?>
    <section class="card">
        <h2>Best times to post</h2>
        <div class="best-times">
            <?php foreach ($a['best']['top'] as $i => $c): ?>
                <div class="best-slot"><span class="best-rank"><?= $i + 1 ?></span><strong><?= e(Analytics::dayName($c['day']) . ', ' . Analytics::hourLabel($c['hour'])) ?></strong></div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
