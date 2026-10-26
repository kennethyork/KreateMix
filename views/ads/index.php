<?php
use App\Ads;
use App\Auth;
use App\Workspace;

$money = function ($v) use ($currency) {
    $v = (float) $v;
    $s = number_format($v, $v >= 1000 ? 0 : 2);
    return $currency ? ($currency === 'USD' ? '$' . $s : $s . ' ' . $currency) : $s;
};
$spend = (float) $totals['spend'];
$impr = (int) $totals['impressions'];
$clicks = (int) $totals['clicks'];
$conv = (float) $totals['conversions'];
$qs = fn(array $over) => url('/ads', array_filter(array_merge(['ad_account' => $pick ?: null, 'days' => $days, 'from' => $days ? null : $from, 'to' => $days ? null : $to], $over), fn($v) => $v !== null && $v !== 0 && $v !== ''));
?>
<div class="page-head">
    <div><h1>Ads</h1><p class="muted">Campaign results from your ad accounts, updated every 6 hours.</p></div>
</div>

<?php if (!$accounts): ?>
    <div class="card empty-state">
        <?= icon('megaphone', 'empty-icon') ?>
        <h2>Connect an ad account</h2>
        <p class="muted">See spend, reach and results from Meta, Google and TikTok ads next to your organic posts.</p>
    </div>
<?php endif; ?>

<?php if (Workspace::can('admin')): ?>
<section class="card">
    <h2>Ad accounts</h2>
    <?php foreach ($accounts as $a): $p = Ads::PROVIDERS[$a['provider']]; ?>
        <div class="account-row">
            <span class="pmark sm" style="--pc: <?= e($p['color']) ?>"><?= e($p['abbr']) ?></span>
            <div class="grow"><strong><?= e($a['name']) ?></strong><div class="muted small"><?= e($p['name']) ?> · <?= e($a['external_id']) ?> · <?= e($a['currency']) ?><?= $a['last_import_at'] ? ' · synced ' . e(local_time($a['last_import_at'], 'M j, g:i A')) : ' · waiting for first sync' ?></div></div>
            <?php if (!$a['authorized']): ?><a class="btn btn-sm" href="<?= e(url('/ads/connect/' . $a['provider'])) ?>">Reconnect</a><?php endif; ?>
            <form method="post" action="<?= e(url('/ads/' . $a['id'] . '/sync')) ?>"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Sync now"><?= icon('refresh') ?></button></form>
            <form method="post" action="<?= e(url('/ads/' . $a['id'] . '/delete')) ?>" data-confirm="Remove this ad account and its stats?"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Remove"><?= icon('trash') ?></button></form>
        </div>
    <?php endforeach; ?>
    <div class="provider-grid" style="margin-top: 12px">
        <?php foreach (Ads::PROVIDERS as $key => $p): ?>
            <?php if ($ready[$key]): ?>
                <a class="provider-tile" href="<?= e(url('/ads/connect/' . $key)) ?>"><span class="pmark" style="--pc: <?= e($p['color']) ?>"><?= e($p['abbr']) ?></span><span><?= e($p['name']) ?></span></a>
            <?php else: ?>
                <a class="provider-tile is-disabled" href="<?= e(url(Auth::isAdmin() ? '/admin/integrations#' . $key : '/ads')) ?>"><span class="pmark" style="--pc: <?= e($p['color']) ?>"><?= e($p['abbr']) ?></span><span><?= e($p['name']) ?></span><small><?= Auth::isAdmin() ? 'Add API keys' : 'Not set up' ?></small></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($accounts): ?>
<form class="analytics-filters" method="get" action="<?= e(url('/ads')) ?>">
    <select name="ad_account" data-autosubmit aria-label="Ad account">
        <option value="">All ad accounts</option>
        <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $pick === (int) $a['id'] ? 'selected' : '' ?>><?= e(Ads::PROVIDERS[$a['provider']]['name'] . ' · ' . $a['name']) ?></option><?php endforeach; ?>
    </select>
    <div class="segmented" role="group" aria-label="Range">
        <?php foreach ([7, 30, 90] as $d): ?><a href="<?= e($qs(['days' => $d, 'from' => null, 'to' => null])) ?>" class="<?= $days === $d ? 'active' : '' ?>"><?= $d ?>d</a><?php endforeach; ?>
    </div>
</form>
<?php if ($mixedCurrency): ?><div class="notice notice-info"><?= icon('alert') ?><div>These accounts use different currencies, so totals are added as raw numbers. Pick one account for exact figures.</div></div><?php endif; ?>

<div class="stats">
    <div class="stat"><span class="stat-label">Spend</span><span class="stat-value"><?= $money($spend) ?></span></div>
    <div class="stat"><span class="stat-label">Impressions</span><span class="stat-value"><?= number_format($impr) ?></span></div>
    <div class="stat"><span class="stat-label">Clicks</span><span class="stat-value"><?= number_format($clicks) ?></span></div>
    <div class="stat"><span class="stat-label">CTR</span><span class="stat-value"><?= $impr ? round($clicks / $impr * 100, 2) . '%' : '—' ?></span></div>
    <div class="stat"><span class="stat-label">CPC</span><span class="stat-value"><?= $clicks ? $money($spend / $clicks) : '—' ?></span></div>
    <div class="stat"><span class="stat-label">Conversions</span><span class="stat-value"><?= number_format($conv, $conv == floor($conv) ? 0 : 1) ?></span></div>
    <div class="stat"><span class="stat-label">Cost / conversion</span><span class="stat-value"><?= $conv > 0 ? $money($spend / $conv) : '—' ?></span></div>
</div>

<section class="card"><h2>Spend per day</h2><div class="chart" data-chart="bar" data-series="<?= e(json_encode($series)) ?>" data-label="Spend"></div></section>

<section class="card">
    <h2>Campaigns</h2>
    <?php if (!$campaigns): ?><p class="muted">No campaign activity in this period.</p><?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Campaign</th><th class="num">Spend</th><th class="num">Impressions</th><th class="num">Clicks</th><th class="num">CTR</th><th class="num">CPC</th><th class="num">Conv.</th></tr></thead>
        <?php foreach ($campaigns as $c): ?>
            <tr>
                <td><span class="pdot" style="--pc: <?= e(Ads::PROVIDERS[$c['provider']]['color']) ?>"><?= e(Ads::PROVIDERS[$c['provider']]['abbr']) ?></span> <?= e($c['campaign_name'] ?: $c['campaign_id']) ?><div class="muted small"><?= e($c['account_name']) ?></div></td>
                <td class="num"><?= e(number_format((float) $c['spend'], 2) . ' ' . $c['currency']) ?></td>
                <td class="num"><?= number_format((int) $c['impressions']) ?></td>
                <td class="num"><?= number_format((int) $c['clicks']) ?></td>
                <td class="num"><?= $c['impressions'] ? round($c['clicks'] / $c['impressions'] * 100, 2) . '%' : '—' ?></td>
                <td class="num"><?= $c['clicks'] ? number_format($c['spend'] / $c['clicks'], 2) : '—' ?></td>
                <td class="num"><?= number_format((float) $c['conversions'], 1) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
