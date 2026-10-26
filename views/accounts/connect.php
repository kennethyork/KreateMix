<div class="page-head">
    <h1>Connect <?= e($name) ?></h1>
</div>
<form class="card narrow" method="post" action="<?= e(url('/accounts/connect/' . $provider)) ?>">
    <?= csrf_field() ?>
    <?php if ($hint): ?><p class="muted"><?= e($hint) ?></p><?php endif; ?>
    <?php foreach ($fields as $key => $f): ?>
        <?php if ($f['type'] === 'checkbox'): ?>
            <label class="check"><input type="checkbox" name="<?= e($key) ?>" value="1"> <span><?= e($f['label']) ?></span></label>
        <?php else: ?>
            <label class="field">
                <span><?= e($f['label']) ?></span>
                <input type="<?= e($f['type']) ?>" name="<?= e($key) ?>" placeholder="<?= e($f['placeholder'] ?? '') ?>" autocomplete="off">
            </label>
        <?php endif; ?>
    <?php endforeach; ?>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/accounts')) ?>">Cancel</a>
        <button class="btn btn-primary" type="submit">Connect</button>
    </div>
</form>
