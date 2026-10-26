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

<form class="card narrow" method="post" action="<?= e(url('/settings/branding')) ?>">
    <?= csrf_field() ?>
    <h2>Report branding</h2>
    <p class="muted small">Used on PDF and PowerPoint reports, shared report links and report emails.</p>
    <label class="field"><span>Brand name</span><input type="text" name="brand_name" value="<?= e($branding['name']) ?>" placeholder="<?= e($workspace['name']) ?>"></label>
    <div class="field-row">
        <label class="field"><span>Brand color</span><input type="color" name="brand_color" value="<?= e($branding['color']) ?>"></label>
        <div class="field">
            <span>Logo</span>
            <div class="bio-avatar-row">
                <span class="logo-preview"><?php if ($branding['logo_url']): ?><img src="<?= e($branding['logo_url']) ?>" alt="" data-bio-avatar-img><?php else: ?><span class="muted small">None</span><?php endif; ?></span>
                <input type="hidden" name="logo_media_id" value="<?= (int) ($branding['logo']['id'] ?? 0) ?>" data-bio-avatar>
                <button class="btn btn-sm" type="button" data-pick-avatar>Choose from media</button>
            </div>
        </div>
    </div>
    <label class="field"><span>Footer text</span><input type="text" name="brand_footer" value="<?= e($branding['footer']) ?>" maxlength="120" placeholder="e.g. Prepared by Acme Agency · acme.agency"></label>
    <label class="check"><input type="checkbox" name="hide_powered_by" value="1" <?= $branding['hide_powered_by'] ? 'checked' : '' ?>> <span>Hide “Made with <?= e(App\Config::get('app_name', 'MixCreate')) ?>”</span></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save branding</button></div>
</form>

<dialog id="avatar-dialog" class="dialog dialog-wide">
    <div class="dialog-head"><h2>Choose a logo</h2><button type="button" class="icon-btn" data-close-dialog aria-label="Close"><?= icon('x') ?></button></div>
    <div class="media-grid" id="avatar-library"><p class="muted">Loading…</p></div>
</dialog>

<form class="card narrow" method="post" action="<?= e(url('/settings/ai')) ?>" id="ai">
    <?= csrf_field() ?>
    <h2>AI writing</h2>
    <p class="muted small">Use this workspace’s own OpenRouter key (BYOK) instead of the instance key, so its AI usage is billed to that OpenRouter account. Leave blank to use the instance settings<?= App\Ai::enabled() ? '' : ' (none set yet)' ?>.</p>
    <label class="field"><span>OpenRouter API key</span><input type="password" name="api_key" placeholder="<?= $aiWs['api_key'] ? '•••••••• (saved — leave blank to keep)' : 'sk-or-v1-…' ?>" autocomplete="off"></label>
    <label class="field"><span>Model</span><input type="text" name="model" value="<?= e($aiWs['model'] ?? '') ?>" list="openrouter-models" placeholder="Same as instance (or openrouter/auto)" autocomplete="off"></label>
    <label class="field"><span>Brand voice</span><textarea name="brand_voice" rows="2" placeholder="e.g. Warm, playful, short sentences, no corporate jargon"><?= e($aiWs['brand_voice'] ?? '') ?></textarea></label>
    <?php if ($aiWs['api_key']): ?><label class="check"><input type="checkbox" name="remove_key" value="1"> <span>Remove this workspace’s key</span></label><?php endif; ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div>
</form>
<datalist id="openrouter-models">
    <option value="openrouter/auto">
    <?php foreach ($models as $m): ?><option value="<?= e($m['id']) ?>"><?= e($m['name']) ?></option><?php endforeach; ?>
</datalist>

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
