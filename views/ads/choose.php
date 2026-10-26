<div class="page-head"><h1>Choose <?= e(App\Ads::PROVIDERS[$provider]['name']) ?> accounts</h1></div>
<form class="card narrow" method="post" action="<?= e(url('/ads/choose')) ?>">
    <?= csrf_field() ?>
    <div class="account-list">
        <?php foreach ($accounts as $a): ?>
            <label class="account-row check-row">
                <input type="checkbox" name="pick[]" value="<?= e($a['external_id']) ?>" checked>
                <span class="grow"><strong><?= e($a['name']) ?></strong><br><small class="muted"><?= e($a['external_id']) ?> · <?= e($a['currency']) ?></small></span>
            </label>
        <?php endforeach; ?>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/ads')) ?>">Cancel</a>
        <button class="btn btn-primary" type="submit">Add selected</button>
    </div>
</form>
