<?php use App\Workspace; ?>
<div class="page-head">
    <h1>Media</h1>
</div>

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
