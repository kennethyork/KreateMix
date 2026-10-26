<?php
use App\Analytics;

$fmt = fn($n) => $n === null ? '—' : ($n >= 100000 ? number_format($n / 1000) . 'k' : ($n >= 10000 ? number_format($n / 1000, 1) . 'k' : number_format((int) $n)));
$pct = fn($n) => $n === null ? '—' : rtrim(rtrim(number_format($n, 2), '0'), '.') . '%';
$tabUrl = fn($t) => url('/analytics', array_filter(['tab' => $t, 'account' => $accountId ?: null, 'days' => $days, 'from' => $days ? null : $a['from'], 'to' => $days ? null : $a['to']]));
$typeLabels = ['text' => 'Text only', 'image' => 'Single image', 'carousel' => 'Multiple images', 'video' => 'Video'];
?>
<div class="page-head">
    <h1>Analytics</h1>
    <div class="head-actions">
        <a class="btn" href="<?= e(url('/reports', array_filter(['account' => $accountId ?: null, 'from' => $a['from'], 'to' => $a['to']]))) ?>"><?= icon('file') ?> Report</a>
    </div>
</div>

<?php partial('partials/analytics_filters', ['accounts' => $accounts, 'accountId' => $accountId, 'days' => $days, 'a' => $a, 'base' => '/analytics', 'extra' => ['tab' => $tab]]); ?>

<nav class="tabs">
    <?php foreach (['overview' => 'Overview', 'networks' => 'Networks', 'content' => 'Content', 'timing' => 'Best time', 'audience' => 'Audience'] as $k => $l): ?>
        <a href="<?= e($tabUrl($k)) ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$a['lastImport']): ?>
    <div class="notice notice-info"><?= icon('clock') ?><div>Follower and engagement numbers appear after the scheduler’s first import (every 6 hours). Post counts are already live.</div></div>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
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
        <div class="stat"><span class="stat-label">Engagement rate</span><span class="stat-value"><?= $pct($a['rate']) ?></span></div>
    </div>
    <div class="stats compact-stats">
        <div class="stat"><span class="stat-label">Likes</span><span class="stat-value"><?= $fmt($a['totals']['likes']) ?></span></div>
        <div class="stat"><span class="stat-label">Comments</span><span class="stat-value"><?= $fmt($a['totals']['replies']) ?></span></div>
        <div class="stat"><span class="stat-label">Shares</span><span class="stat-value"><?= $fmt($a['totals']['shares']) ?></span></div>
        <div class="stat"><span class="stat-label">Saves</span><span class="stat-value"><?= $fmt($a['totals']['saves']) ?></span></div>
        <div class="stat"><span class="stat-label">Clicks</span><span class="stat-value"><?= $fmt($a['totals']['clicks']) ?></span></div>
    </div>
    <div class="grid-2">
        <section class="card"><h2>Followers</h2><div class="chart" data-chart="line" data-series="<?= e(json_encode($a['followers'])) ?>" data-label="Followers"></div></section>
        <section class="card"><h2>Engagement per day</h2><div class="chart" data-chart="bar" data-series="<?= e(json_encode($a['engagementSeries'])) ?>" data-label="Engagement"></div></section>
    </div>
    <section class="card">
        <h2>Posts per day</h2>
        <div class="chart short" data-chart="bar" data-series="<?= e(json_encode($a['postSeries'])) ?>" data-label="Posts"></div>
    </section>
    <?php partial('analytics/top_posts', ['top' => $a['top']]); ?>

<?php elseif ($tab === 'networks'): ?>
    <section class="card">
        <h2>By network</h2>
        <?php if (!$a['perNetwork']): ?><p class="muted">No accounts.</p><?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Network</th><th class="num">Followers</th><th class="num">Change</th><th class="num">Posts</th><th class="num">Engagement</th><th class="num">Impressions</th><th class="num">Eng. rate</th></tr></thead>
            <?php foreach ($a['perNetwork'] as $n): ?>
                <tr>
                    <td><?= provider_badge($n['provider'], 'pdot') ?> <?= e(Analytics::providerName($n['provider'])) ?> <span class="muted small">(<?= $n['accounts'] ?>)</span></td>
                    <td class="num"><?= $fmt($n['followers']) ?></td>
                    <td class="num <?= ($n['followers_change'] ?? 0) >= 0 ? 'up' : 'down' ?>"><?= $n['followers_change'] === null ? '—' : (($n['followers_change'] >= 0 ? '+' : '') . number_format($n['followers_change'])) ?></td>
                    <td class="num"><?= $n['posts'] ?></td>
                    <td class="num"><?= $fmt($n['engagement']) ?></td>
                    <td class="num"><?= $fmt($n['impressions'] ?: null) ?></td>
                    <td class="num"><?= $pct($n['rate']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </section>
    <section class="card">
        <h2>By account</h2>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Account</th><th class="num">Followers</th><th class="num">Change</th><th class="num">Posts</th><th class="num">Likes</th><th class="num">Comments</th><th class="num">Shares</th><th class="num">Eng. rate</th></tr></thead>
            <?php foreach ($a['perAccount'] as $acc): ?>
                <tr>
                    <td><a href="<?= e(url('/analytics', ['account' => $acc['id'], 'days' => $days ?: 30])) ?>" class="acct-cell"><?= account_avatar($acc, 'xs') ?> <?= e($acc['name']) ?></a></td>
                    <td class="num"><?= $fmt($acc['followers']) ?></td>
                    <td class="num <?= ($acc['followers_change'] ?? 0) >= 0 ? 'up' : 'down' ?>"><?= $acc['followers_change'] === null ? '—' : (($acc['followers_change'] >= 0 ? '+' : '') . number_format($acc['followers_change'])) ?></td>
                    <td class="num"><?= $acc['posts'] ?></td>
                    <td class="num"><?= $fmt($acc['likes']) ?></td>
                    <td class="num"><?= $fmt($acc['replies']) ?></td>
                    <td class="num"><?= $fmt($acc['shares']) ?></td>
                    <td class="num"><?= $pct($acc['rate']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </section>

<?php elseif ($tab === 'content'): ?>
    <div class="grid-2">
        <section class="card">
            <h2>By format</h2>
            <p class="muted small">Average engagement per post.</p>
            <?php $maxAvg = max(1, max(array_column($a['types'], 'avg'))); ?>
            <div class="hbars">
                <?php foreach ($a['types'] as $k => $t): ?>
                    <div class="hbar">
                        <span class="hbar-label"><?= e($typeLabels[$k]) ?> <small class="muted"><?= $t['posts'] ?> posts</small></span>
                        <span class="hbar-track"><i style="width: <?= round($t['avg'] / $maxAvg * 100) ?>%"></i></span>
                        <span class="hbar-value"><?= $t['avg'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="card">
            <h2>Hashtags</h2>
            <p class="muted small">Average engagement on posts that used each hashtag.</p>
            <?php if (!$a['hashtags']): ?><p class="muted">No hashtags in this period.</p><?php else: ?>
            <?php $maxH = max(1, max(array_column($a['hashtags'], 'avg'))); ?>
            <div class="hbars">
                <?php foreach ($a['hashtags'] as $h): ?>
                    <div class="hbar">
                        <span class="hbar-label">#<?= e($h['tag']) ?> <small class="muted"><?= $h['posts'] ?></small></span>
                        <span class="hbar-track"><i style="width: <?= round($h['avg'] / $maxH * 100) ?>%"></i></span>
                        <span class="hbar-value"><?= $h['avg'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>
    <?php partial('analytics/top_posts', ['top' => $a['top']]); ?>

<?php elseif ($tab === 'timing'): ?>
    <?php partial('analytics/heatmap', ['best' => $a['best']]); ?>

<?php else: ?>
    <?php if (!$a['audience']): ?>
        <div class="card empty-state">
            <?= icon('users', 'empty-icon') ?>
            <p class="muted">No audience data yet. Instagram and Threads share age, gender and location for accounts with at least 100 followers; it’s imported once a day.</p>
        </div>
    <?php endif; ?>
    <?php foreach ($a['audience'] as $aud): ?>
        <section class="card">
            <h2><?= provider_badge($aud['provider'], 'pdot') ?> <?= e($aud['account']) ?></h2>
            <div class="audience-grid">
                <?php foreach (['age' => 'Age', 'gender' => 'Gender', 'country' => 'Top countries', 'city' => 'Top cities'] as $dim => $label):
                    $vals = $aud['data'][$dim] ?? [];
                    if (!$vals) continue;
                    arsort($vals);
                    if ($dim === 'age') ksort($vals);
                    $vals = array_slice($vals, 0, 8, true);
                    $total = max(1, array_sum($aud['data'][$dim]));
                    $max = max($vals);
                    $genderNames = ['F' => 'Women', 'M' => 'Men', 'U' => 'Unknown'];
                ?>
                    <div>
                        <h3><?= e($label) ?></h3>
                        <div class="hbars">
                            <?php foreach ($vals as $k => $v): ?>
                                <div class="hbar">
                                    <span class="hbar-label"><?= e($dim === 'gender' ? ($genderNames[$k] ?? $k) : $k) ?></span>
                                    <span class="hbar-track"><i style="width: <?= round($v / $max * 100) ?>%"></i></span>
                                    <span class="hbar-value"><?= round($v / $total * 100) ?>%</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
