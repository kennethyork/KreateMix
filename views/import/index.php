<?php
$byId = array_column($accounts, null, 'id');
?>
<div class="page-head">
    <div>
        <h1>Bulk upload</h1>
        <p class="muted">Schedule many posts at once from a CSV file (Excel and Google Sheets can export one).</p>
    </div>
    <a class="btn" href="<?= e(url('/import/sample')) ?>"><?= icon('download') ?> Sample CSV</a>
</div>

<?php if (!$preview): ?>
<form class="card" method="post" action="<?= e(url('/import')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <label class="field"><span>CSV file</span><input type="file" name="csv" accept=".csv,text/csv" required></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Preview</button></div>
    <details class="api-docs">
        <summary>Columns</summary>
        <div class="table-wrap">
        <table class="table small">
            <tr><td><code>text</code></td><td>Post text (required)</td></tr>
            <tr><td><code>date</code>, <code>time</code></td><td>When to publish, in your timezone (<?= e(str_replace('_', ' ', user_tz())) ?>). e.g. 2026-11-03 and 09:00</td></tr>
            <tr><td><code>accounts</code></td><td><code>all</code>, or a list separated by semicolons: account names, @usernames, IDs, or platforms (<code>x</code>, <code>bluesky</code>, <code>instagram</code>…)</td></tr>
            <tr><td><code>media</code></td><td>Image/video URLs or media IDs, separated by spaces or semicolons. URLs are downloaded into your library.</td></tr>
            <tr><td><code>first_comment</code></td><td>Optional first comment</td></tr>
            <tr><td><code>tags</code></td><td>Tag names, separated by semicolons (created if new)</td></tr>
            <tr><td><code>status</code></td><td><code>schedule</code> (default) or <code>draft</code></td></tr>
        </table>
        </div>
    </details>
</form>
<?php else:
    $good = count(array_filter($preview['rows'], fn($r) => !$r['errors']));
?>
<div class="notice <?= $good === count($preview['rows']) ? 'notice-success' : 'notice-warn' ?>">
    <?= icon($good === count($preview['rows']) ? 'check' : 'alert') ?>
    <div class="grow"><strong><?= $good ?> of <?= count($preview['rows']) ?> rows are ready.</strong> Rows with problems will be skipped.</div>
</div>
<section class="card">
    <div class="table-wrap">
    <table class="table small">
        <thead><tr><th>Line</th><th>When</th><th>Text</th><th>Accounts</th><th>Media</th><th></th></tr></thead>
        <?php foreach ($preview['rows'] as $r): ?>
            <tr>
                <td class="muted"><?= (int) $r['line'] ?></td>
                <td class="nowrap"><?= $r['status'] === 'draft' ? '<span class="badge status-draft">Draft</span>' : e($r['scheduled_at'] ? local_time($r['scheduled_at'], 'M j, g:i A') : '—') ?></td>
                <td><?= e(str_limit($r['text'], 80)) ?><?php if ($r['first_comment'] !== ''): ?><div class="muted">+ first comment</div><?php endif; ?></td>
                <td><div class="avatar-stack" style="margin: 0"><?php foreach ($r['accounts'] as $aid) { if (isset($byId[$aid])) echo account_avatar($byId[$aid], 'xs'); } ?></div></td>
                <td><?= count($r['media']) ?: '—' ?></td>
                <td><?php if ($r['errors']): ?><span class="error-text"><?= e(implode(' ', $r['errors'])) ?></span><?php else: ?><span class="badge status-published">OK</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <div class="form-actions">
        <form method="post" action="<?= e(url('/import/cancel')) ?>"><?= csrf_field() ?><button class="btn" type="submit">Start over</button></form>
        <form method="post" action="<?= e(url('/import/confirm')) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit" <?= $good ? '' : 'disabled' ?>>Import <?= $good ?> post<?= $good === 1 ? '' : 's' ?></button></form>
    </div>
</section>
<?php endif; ?>
