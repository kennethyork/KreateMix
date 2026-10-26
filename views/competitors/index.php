<?php
use App\Workspace;

$fmt = fn($n) => $n === null ? '—' : ($n >= 10000 ? number_format($n / 1000, 1) . 'k' : number_format((int) $n));
$ownBy = [];
foreach ($own as $o) {
    $ownBy[$o['provider']][] = $o;
}
?>
<div class="page-head">
    <div>
        <h1>Competitors</h1>
        <p class="muted">Public stats, refreshed daily. Growth builds up from the day you start tracking.</p>
    </div>
    <div class="segmented" role="group" aria-label="Range">
        <?php foreach ([7, 30, 90] as $d): ?>
            <a href="<?= e(url('/competitors', ['days' => $d])) ?>" class="<?= $days === $d ? 'active' : '' ?>"><?= $d ?>d</a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (Workspace::can('member')): ?>
<form class="card field-row align-end" method="post" action="<?= e(url('/competitors')) ?>">
    <?= csrf_field() ?>
    <label class="field"><span>Network</span>
        <select name="network" data-placeholder-target="#comp-handle">
            <?php foreach ($networks as $k => $n): ?><option value="<?= e($k) ?>" data-placeholder="<?= e($n['placeholder']) ?>"><?= e($n['label']) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field grow"><span>Account</span><input id="comp-handle" type="text" name="handle" required placeholder="handle.bsky.social"></label>
    <button class="btn btn-primary" type="submit"><?= icon('plus') ?> Track</button>
</form>
<p class="muted small">X, YouTube and Instagram use one of your connected accounts on that network to look others up (Instagram needs a Facebook Page linked to an Instagram business account). TikTok, LinkedIn, Facebook, Threads and Pinterest don’t offer this.</p>
<?php endif; ?>

<?php if (!$competitors): ?>
    <div class="card empty-state"><?= icon('target', 'empty-icon') ?><p class="muted">Add an account above to start comparing.</p></div>
<?php endif; ?>

<?php foreach ($networks as $key => $n):
    $list = array_values(array_filter($competitors, fn($c) => $c['provider'] === $key));
    if (!$list) continue;
    $max = max(1, max(array_map(fn($c) => (int) $c['followers'], $list)), max(array_map(fn($o) => (int) $o['followers'], $ownBy[$key] ?? [['followers' => 0]])));
?>
<section class="card">
    <div class="card-head"><h2><?= provider_badge($key, 'pdot') ?> <?= e($n['label']) ?></h2></div>
    <div class="table-wrap">
    <table class="table comp-table">
        <thead><tr><th>Account</th><th class="num">Followers</th><th class="num">Change (<?= $days ?>d)</th><th class="num">Posts / 30d</th><th class="num">Avg engagement</th><th>Trend</th><th></th></tr></thead>
        <?php foreach ($ownBy[$key] ?? [] as $o): ?>
            <tr class="is-own">
                <td><span class="acct-cell"><?= account_avatar($o, 'xs') ?> <strong><?= e($o['name']) ?></strong> <span class="badge">You</span></span></td>
                <td class="num"><?= $fmt($o['followers'] !== null ? (int) $o['followers'] : null) ?><span class="bar-mini"><i style="width: <?= round((int) $o['followers'] / $max * 100) ?>%"></i></span></td>
                <td class="num">—</td><td class="num">—</td><td class="num">—</td><td></td><td></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($list as $c): ?>
            <tr>
                <td>
                    <span class="acct-cell">
                        <span class="avatar xs"><?= e(initials($c['name'] ?: $c['handle'])) ?><?php if ($c['avatar']): ?><img src="<?= e($c['avatar']) ?>" alt="" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?></span>
                        <span><?= e($c['name'] ?: $c['handle']) ?><br><small class="muted"><?= e($c['handle']) ?></small></span>
                    </span>
                    <?php if ($c['error']): ?><div class="error-text small"><?= e($c['error']) ?></div><?php endif; ?>
                </td>
                <td class="num"><?= $fmt($c['followers']) ?><span class="bar-mini"><i style="width: <?= round((int) $c['followers'] / $max * 100) ?>%"></i></span></td>
                <td class="num <?= ($c['change'] ?? 0) >= 0 ? 'up' : 'down' ?>"><?= $c['change'] === null ? '—' : (($c['change'] >= 0 ? '+' : '') . number_format($c['change'])) ?></td>
                <td class="num"><?= $c['posts_30d'] === null ? ($c['sample'] ? $c['sample'] . '+' : '—') : $c['posts_30d'] ?></td>
                <td class="num"><?= $c['avg_engagement'] === null ? '—' : $c['avg_engagement'] ?></td>
                <td class="spark-cell"><?php if (count($c['series']) > 1): ?><div class="spark" data-chart="spark" data-series="<?= e(json_encode($c['series'])) ?>"></div><?php else: ?><span class="muted small">Building…</span><?php endif; ?></td>
                <td class="right nowrap">
                    <?php if (Workspace::can('member')): ?>
                    <form method="post" action="<?= e(url('/competitors/' . $c['id'] . '/refresh')) ?>" class="inline"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Refresh"><?= icon('refresh') ?></button></form>
                    <form method="post" action="<?= e(url('/competitors/' . $c['id'] . '/delete')) ?>" class="inline" data-confirm="Stop tracking <?= e($c['name'] ?: $c['handle']) ?>?"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Remove"><?= icon('trash') ?></button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
</section>
<?php endforeach; ?>
