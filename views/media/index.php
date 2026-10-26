<?php use App\Workspace; ?>
<div class="page-head">
    <h1>Media</h1>
    <?php if (App\Canva::enabled() && Workspace::can('member')): ?>
        <div class="head-actions">
            <?php if (App\Canva::connected((int) App\Auth::id())): ?>
                <button class="btn" type="button" data-open-dialog="canva-dialog" data-canva-page><?= icon('palette') ?> Import from Canva</button>
                <form method="post" action="<?= e(url('/canva/disconnect')) ?>"><?= csrf_field() ?><button class="btn btn-ghost btn-sm" type="submit">Disconnect Canva</button></form>
            <?php else: ?>
                <a class="btn" href="<?= e(url('/canva/connect', ['back' => '/media'])) ?>"><?= icon('palette') ?> Connect Canva</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
<?php if (App\Canva::enabled()): ?>
<dialog id="canva-dialog" class="dialog dialog-wide">
    <div class="dialog-head"><h2>Import from Canva</h2><button type="button" class="icon-btn" data-close-dialog aria-label="Close"><?= icon('x') ?></button></div>
    <div class="canva-head"><input type="search" id="canva-q" placeholder="Search your Canva designs"></div>
    <div class="media-grid" id="canva-grid"><p class="muted">Loading…</p></div>
    <div class="center"><button type="button" class="btn btn-sm" id="canva-more" hidden>Load more</button></div>
</dialog>
<?php endif; ?>

<?php if (Workspace::can('member')): ?>
<form class="dropzone-form" method="post" action="<?= e(url('/media')) ?>" enctype="multipart/form-data" data-media-page>
    <?= csrf_field() ?>
    <label class="dropzone">
        <input type="file" name="file" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,video/webm" multiple hidden>
        <?= icon('upload') ?>
        <span><strong>Upload files</strong> or drop them here</span>
        <small class="muted">Up to <?= (int) App\Config::get('max_upload_mb', 200) ?> MB each</small>
    </label>
    <div class="upload-progress"></div>
</form>
<?php endif; ?>

<?php if (!$media): ?>
    <div class="card empty-state"><?= icon('image', 'empty-icon') ?><p class="muted">No media uploaded yet.</p></div>
<?php else: ?>
<div class="media-grid page-media">
    <?php foreach ($media as $m): ?>
        <figure class="media-tile">
            <a href="<?= e($m['url']) ?>" target="_blank" rel="noopener" class="media-thumb">
                <?php if ($m['is_video']): ?>
                    <video src="<?= e($m['url']) ?>#t=0.1" muted preload="metadata"></video><span class="play"><?= icon('play') ?></span>
                <?php else: ?>
                    <img src="<?= e($m['url']) ?>" alt="<?= e($m['name']) ?>" loading="lazy">
                <?php endif; ?>
            </a>
            <figcaption>
                <span class="media-name" title="<?= e($m['name']) ?>"><?= e($m['name']) ?></span>
                <span class="muted small"><?= e(number_format($m['size'] / 1048576, 1)) ?> MB<?= $m['width'] ? ' · ' . $m['width'] . '×' . $m['height'] : '' ?></span>
            </figcaption>
            <?php if (Workspace::can('member')): ?>
            <form method="post" action="<?= e(url('/media/' . $m['id'] . '/delete')) ?>" data-confirm="Delete this file? Posts using it will lose it.">
                <?= csrf_field() ?>
                <button class="icon-btn media-del" type="submit" aria-label="Delete"><?= icon('trash') ?></button>
            </form>
            <?php endif; ?>
        </figure>
    <?php endforeach; ?>
</div>
<?php endif; ?>
