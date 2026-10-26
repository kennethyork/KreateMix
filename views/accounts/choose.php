<div class="page-head">
    <h1>Choose <?= e($name) ?> accounts</h1>
</div>
<form class="card narrow" method="post" action="<?= e(url('/accounts/choose')) ?>">
    <?= csrf_field() ?>
    <p class="muted">Your login gave access to several. Pick the ones to add to this workspace.</p>
    <div class="account-list">
        <?php foreach ($accounts as $a): ?>
            <label class="account-row check-row">
                <input type="checkbox" name="pick[]" value="<?= e($a['provider_id']) ?>" checked>
                <?= account_avatar(['provider' => $provider, 'name' => $a['name'], 'avatar' => $a['avatar'] ?? null]) ?>
                <span class="grow"><strong><?= e($a['name']) ?></strong><?php if (!empty($a['username'])): ?><br><small class="muted"><?= e($a['username']) ?></small><?php endif; ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/accounts')) ?>">Cancel</a>
        <button class="btn btn-primary" type="submit">Add selected</button>
    </div>
</form>
