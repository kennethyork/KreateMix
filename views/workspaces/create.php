<div class="page-head">
    <h1>New workspace</h1>
</div>
<form class="card narrow" method="post" action="<?= e(url('/workspaces')) ?>">
    <?= csrf_field() ?>
    <label class="field">
        <span>Name</span>
        <input type="text" name="name" required autofocus placeholder="Brand or client name">
    </label>
    <fieldset class="field">
        <legend>Color</legend>
        <div class="swatches">
            <?php foreach ($colors as $i => $c): ?>
                <label class="swatch" style="--c: <?= e($c) ?>">
                    <input type="radio" name="color" value="<?= e($c) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                    <span class="sr-only"><?= e($c) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Create workspace</button>
    </div>
</form>
