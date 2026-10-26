<?php
use App\Workspace;

$qs = fn(array $over) => url('/hashtags', array_filter(array_merge(['tag' => $current['id'] ?? null, 'days' => $days, 'from' => $days ? null : $from, 'to' => $days ? null : $to], $over), fn($v) => $v !== null && $v !== ''));
$netName = fn($k) => $networks[$k] ?? $k;
?>
<div class="page-head">
    <div>
        <h1>Hashtag tracker</h1>
        <p class="muted">Follow a campaign or topic hashtag: who’s using it, how often, and which posts take off. Checked every 30 minutes.</p>
    </div>
</div>

<?php if ($tags): ?>
<nav class="tabs">
    <?php foreach ($tags as $t): ?>
        <a href="<?= e(url('/hashtags', ['tag' => $t['id']])) ?>" class="<?= (int) $t['id'] === (int) ($current['id'] ?? 0) ? 'active' : '' ?>">#<?= e($t['tag']) ?> <span class="count"><?= (int) $t['total'] ?></span><?= $t['active'] ? '' : ' <span class="count">(paused)</span>' ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($current && $r): ?>
    <div class="analytics-filters">
        <div class="segmented" role="group" aria-label="Range">
            <?php foreach ([7, 30, 90] as $d): ?><a href="<?= e($qs(['days' => $d, 'from' => null, 'to' => null])) ?>" class="<?= $days === $d ? 'active' : '' ?>"><?= $d ?>d</a><?php endforeach; ?>
        </div>
        <span class="muted small">On <?= e(implode(', ', array_map($netName, json_arr($current['networks'])))) ?><?= $current['last_checked_at'] ? ' · checked ' . e(local_time($current['last_checked_at'], 'M j, g:i A')) : '' ?></span>
        <span class="grow"></span>
        <?php if (Workspace::can('member')): ?>
            <form method="post" action="<?= e(url('/hashtags/' . $current['id'] . '/refresh')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= icon('refresh') ?> Check now</button></form>
            <a class="btn btn-sm" href="<?= e(url('/hashtags/' . $current['id'] . '/export')) ?>"><?= icon('download') ?> CSV</a>
            <form method="post" action="<?= e(url('/hashtags/' . $current['id'] . '/toggle')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= $current['active'] ? 'Pause' : 'Resume' ?></button></form>
            <form method="post" action="<?= e(url('/hashtags/' . $current['id'] . '/delete')) ?>" data-confirm="Stop tracking #<?= e($current['tag']) ?> and delete its posts?"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Delete"><?= icon('trash') ?></button></form>
        <?php endif; ?>
    </div>
    <?php if ($current['error']): ?><div class="notice notice-warn"><?= icon('alert') ?><div><?= e($current['error']) ?></div></div><?php endif; ?>
    <div class="stats">
        <div class="stat"><span class="stat-label">Posts</span><span class="stat-value"><?= number_format($r['posts']) ?></span></div>
        <div class="stat"><span class="stat-label">People posting</span><span class="stat-value"><?= number_format($r['authors']) ?></span></div>
        <div class="stat"><span class="stat-label">Engagement</span><span class="stat-value"><?= number_format($r['engagement']) ?></span></div>
        <?php foreach ($r['networks'] as $n): ?>
            <div class="stat"><span class="stat-label"><?= e($netName($n['network'])) ?></span><span class="stat-value"><?= number_format((int) $n['posts']) ?></span></div>
        <?php endforeach; ?>
    </div>
    <section class="card"><h2>Posts per day</h2><div class="chart short" data-chart="bar" data-series="<?= e(json_encode($r['series'])) ?>" data-label="Posts"></div></section>
    <div class="grid-2">
        <section class="card">
            <h2>Top posts</h2>
            <?php if (!$r['top']): ?><p class="muted">None yet.</p><?php endif; ?>
            <?php foreach ($r['top'] as $p): ?>
                <div class="tag-post">
                    <div class="muted small"><?= provider_badge($p['network'], 'pdot') ?> <?= e($p['author_name'] ?: ($p['author_handle'] ? '@' . $p['author_handle'] : $netName($p['network']))) ?> · <?= e(local_time($p['posted_at'], 'M j')) ?> · <?= (int) ($p['likes'] + $p['reposts'] + $p['replies']) ?> engagements</div>
                    <p><?= e(str_limit($p['text'], 220)) ?></p>
                    <?php if ($p['url']): ?><a class="link small" href="<?= e($p['url']) ?>" target="_blank" rel="noopener">Open <?= icon('external') ?></a><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
        <section class="card">
            <h2>Top contributors</h2>
            <?php if (!$r['contributors']): ?><p class="muted">None yet.</p><?php endif; ?>
            <?php foreach ($r['contributors'] as $c): ?>
                <div class="account-row">
                    <span class="avatar xs"><?= e(initials($c['author_name'] ?: $c['author_handle'])) ?><?php if ($c['author_avatar']): ?><img src="<?= e($c['author_avatar']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?></span>
                    <div class="grow small"><strong><?= e($c['author_name'] ?: $c['author_handle']) ?></strong> <span class="muted">@<?= e($c['author_handle']) ?></span></div>
                    <span class="muted small"><?= (int) $c['posts'] ?> posts · <?= (int) $c['engagement'] ?></span>
                </div>
            <?php endforeach; ?>
        </section>
    </div>
<?php elseif (!$tags): ?>
    <div class="card empty-state"><?= icon('hash', 'empty-icon') ?><p class="muted">Track your first hashtag below.</p></div>
<?php endif; ?>

<?php if (Workspace::can('member')): ?>
<details class="card add-block" <?= $tags ? '' : 'open' ?>>
    <summary class="btn btn-sm"><?= icon('plus') ?> Track a hashtag</summary>
    <form method="post" action="<?= e(url('/hashtags')) ?>">
        <?= csrf_field() ?>
        <label class="field"><span>Hashtag</span><input type="text" name="tag" placeholder="#yourcampaign" required></label>
        <fieldset class="field"><legend>Networks</legend>
            <div class="check-grid">
                <label class="check"><input type="checkbox" name="networks[]" value="bluesky" checked> <span>Bluesky</span></label>
                <label class="check"><input type="checkbox" name="networks[]" value="mastodon" checked> <span>Mastodon</span></label>
                <label class="check"><input type="checkbox" name="networks[]" value="x"> <span>X <small class="muted">(needs a connected X account with search access)</small></span></label>
                <label class="check"><input type="checkbox" name="networks[]" value="instagram"> <span>Instagram <small class="muted">(needs a Facebook Page linked to Instagram; 30 hashtags a week)</small></span></label>
            </div>
        </fieldset>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Start tracking</button></div>
    </form>
</details>
<?php endif; ?>
