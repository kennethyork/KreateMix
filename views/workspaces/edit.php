<div class="page-head">
    <h1>Workspace settings</h1>
</div>
<?php if ($tabs): ?>
    <nav class="tabs"><?php foreach ($tabs as $t): ?><a href="<?= e(url($t['href'])) ?>"><?= e($t['label']) ?></a><?php endforeach; ?></nav>
<?php endif; ?>

<form class="card narrow" method="post" action="<?= e(url('/settings')) ?>">
    <?= csrf_field() ?>
    <h2>General</h2>
    <label class="field">
        <span>Name</span>
        <input type="text" name="name" value="<?= e($workspace['name']) ?>" required>
    </label>
    <fieldset class="field">
        <legend>Color</legend>
        <div class="swatches">
            <?php foreach ($colors as $c): ?>
                <label class="swatch" style="--c: <?= e($c) ?>">
                    <input type="radio" name="color" value="<?= e($c) ?>" <?= $workspace['color'] === $c ? 'checked' : '' ?>>
                    <span class="sr-only"><?= e($c) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <label class="check">
        <input type="checkbox" name="require_approval" value="1" <?= $workspace['require_approval'] ? 'checked' : '' ?>>
        <span><strong>Require approval</strong><br><small class="muted">Members’ posts wait for an admin to approve them before they’re scheduled. Admins’ posts skip the queue.</small></span>
    </label>
    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Save</button>
    </div>
</form>

<form class="card narrow danger-zone" method="post" action="<?= e(url('/settings/delete')) ?>" data-confirm="Delete this workspace and everything in it? This can’t be undone.">
    <?= csrf_field() ?>
    <h2>Delete workspace</h2>
    <p class="muted">Removes its accounts, posts, media and team access. Posts already on social platforms stay there.</p>
    <label class="field">
        <span>Type <strong><?= e($workspace['name']) ?></strong> to confirm</span>
        <input type="text" name="confirm" autocomplete="off">
    </label>
    <button class="btn btn-danger" type="submit">Delete workspace</button>
</form>
