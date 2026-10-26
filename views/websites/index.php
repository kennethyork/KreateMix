<?php
use App\Workspace;

$fmtDur = fn(?int $s) => $s === null ? '—' : ($s >= 60 ? intdiv($s, 60) . 'm ' . ($s % 60) . 's' : $s . 's');
$list = function (string $title, array $rows, string $label, ?callable $fmtKey = null) {
    echo '<section class="card"><h2>' . e($title) . '</h2>';
    if (!$rows) {
        echo '<p class="muted small">No data.</p></section>';
        return;
    }
    $max = max(1, max(array_column($rows, 'visitors')));
    echo '<div class="hbars">';
    foreach ($rows as $r) {
        $k = $fmtKey ? $fmtKey($r['k']) : $r['k'];
        echo '<div class="hbar"><span class="hbar-label" title="' . e($r['k']) . '">' . e($k) . '</span><span class="hbar-track"><i style="width:' . round($r['visitors'] / $max * 100) . '%"></i></span><span class="hbar-value">' . (int) $r['visitors'] . '</span></div>';
    }
    echo '</div></section>';
};
$qs = fn(array $over) => url('/websites', array_filter(array_merge(['site' => $site['id'] ?? null, 'days' => $days, 'from' => $days ? null : $from, 'to' => $days ? null : $to], $over), fn($v) => $v !== null && $v !== ''));
?>
<div class="page-head">
    <div>
        <h1>Website</h1>
        <p class="muted">Cookieless visitor stats for your site or blog, and how much traffic social brings in.</p>
    </div>
</div>

<?php if ($sites): ?>
<div class="analytics-filters">
    <select onchange="location.href=this.value" aria-label="Site">
        <?php foreach ($sites as $s): ?><option value="<?= e(url('/websites', ['site' => $s['id']])) ?>" <?= (int) $s['id'] === (int) ($site['id'] ?? 0) ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
    </select>
    <div class="segmented" role="group" aria-label="Range">
        <?php foreach ([7, 30, 90] as $d): ?><a href="<?= e($qs(['days' => $d, 'from' => null, 'to' => null])) ?>" class="<?= $days === $d ? 'active' : '' ?>"><?= $d ?>d</a><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($site && $r): ?>
    <?php if (!$r['views']): ?>
        <div class="notice notice-info"><?= icon('code') ?>
            <div class="grow"><strong>Waiting for the first visit.</strong> Add this to the <code>&lt;head&gt;</code> of every page on <?= e($site['domain']) ?>:
                <code class="copy" data-copy><?= e($snippet) ?></code></div></div>
    <?php endif; ?>
    <div class="stats">
        <div class="stat"><span class="stat-label">Visitors</span><span class="stat-value"><?= number_format($r['visitors']) ?></span></div>
        <div class="stat"><span class="stat-label">Page views</span><span class="stat-value"><?= number_format($r['views']) ?></span></div>
        <div class="stat"><span class="stat-label">Bounce rate</span><span class="stat-value"><?= $r['bounce'] === null ? '—' : $r['bounce'] . '%' ?></span></div>
        <div class="stat"><span class="stat-label">Time on page</span><span class="stat-value"><?= $fmtDur($r['duration']) ?></span></div>
        <div class="stat"><span class="stat-label">From social</span><span class="stat-value"><?= number_format(array_sum($r['social'])) ?></span></div>
    </div>
    <section class="card"><h2>Visitors per day</h2><div class="chart" data-chart="bar" data-series="<?= e(json_encode($r['series'])) ?>" data-label="Visitors"></div></section>
    <div class="grid-2">
        <?php $list('Top pages', $r['pages'], 'Page'); ?>
        <section class="card">
            <h2>Social traffic</h2>
            <?php if (!$r['social']): ?><p class="muted small">No visits from social networks yet. Links in your posts show up here.</p><?php else: $mx = max($r['social']); ?>
            <div class="hbars"><?php foreach ($r['social'] as $net => $n): ?>
                <div class="hbar"><span class="hbar-label"><?= e($net) ?></span><span class="hbar-track"><i style="width: <?= round($n / $mx * 100) ?>%"></i></span><span class="hbar-value"><?= (int) $n ?></span></div>
            <?php endforeach; ?></div>
            <?php endif; ?>
        </section>
        <?php $list('Referrers', $r['referrers'], 'Site'); ?>
        <?php $list('Campaigns (utm_campaign)', $r['campaigns'], 'Campaign'); ?>
        <?php $list('Devices', $r['devices'], 'Device', fn($k) => ucfirst($k)); ?>
        <?php $list('Browsers', $r['browsers'], 'Browser'); ?>
        <?php $list('Operating systems', $r['os'], 'OS'); ?>
        <?php if ($r['countries']) { $list('Countries', $r['countries'], 'Country'); } ?>
    </div>
    <?php if ($r['views']): ?>
        <details class="card"><summary class="link">Tracking snippet</summary><code class="copy" data-copy><?= e($snippet) ?></code></details>
    <?php endif; ?>
<?php elseif (!$sites): ?>
    <div class="card empty-state"><?= icon('chart', 'empty-icon') ?><p class="muted">Add your website to start counting visitors. No cookies, no consent banner needed for this script.</p></div>
<?php endif; ?>

<?php if (Workspace::can('admin')): ?>
<details class="card add-block" <?= $sites ? '' : 'open' ?>>
    <summary class="btn btn-sm"><?= icon('plus') ?> Add a website</summary>
    <form class="field-row align-end" method="post" action="<?= e(url('/websites')) ?>">
        <?= csrf_field() ?>
        <label class="field"><span>Name</span><input type="text" name="name" placeholder="Company blog"></label>
        <label class="field grow"><span>Domain</span><input type="text" name="domain" placeholder="example.com" required></label>
        <button class="btn btn-primary" type="submit">Add</button>
    </form>
    <?php if ($site): ?>
        <form method="post" action="<?= e(url('/websites/' . $site['id'] . '/delete')) ?>" data-confirm="Remove <?= e($site['name']) ?> and all its stats?"><?= csrf_field() ?><button class="btn btn-ghost btn-danger-text btn-sm" type="submit">Remove <?= e($site['name']) ?></button></form>
    <?php endif; ?>
</details>
<?php endif; ?>
