<?php use App\Workspace; $canEdit = Workspace::can('member'); ?>
<div class="page-head">
    <div>
        <h1>Link in bio</h1>
        <p class="muted">One page with all your links. Put its address in your profiles.</p>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e(url('@' . $page['slug'], ['preview' => 1])) ?>" target="_blank" rel="noopener"><?= icon('external') ?> Preview</a>
    </div>
</div>

<div class="notice <?= $page['published'] ? 'notice-success' : 'notice-info' ?>">
    <?= icon($page['published'] ? 'check' : 'clock') ?>
    <div class="grow">
        <?php if ($page['published']): ?><strong>Live at</strong><?php else: ?><strong>Not published yet.</strong> It will live at<?php endif; ?>
        <code class="copy" data-copy><?= e($publicUrl) ?></code>
    </div>
</div>

<div class="stats">
    <div class="stat"><span class="stat-label">Page views (30 days)</span><span class="stat-value"><?= number_format($views) ?></span></div>
    <div class="stat"><span class="stat-label">Link clicks (30 days)</span><span class="stat-value"><?= number_format(array_sum($clicks) - $views) ?></span></div>
    <div class="stat"><span class="stat-label">Click rate</span><span class="stat-value"><?= $views ? round((array_sum($clicks) - $views) / $views * 100) . '%' : '—' ?></span></div>
</div>

<div class="grid-2">
    <section class="card">
        <h2>Links</h2>
        <div class="bio-links" data-bio-sortable>
            <?php foreach ($links as $l): ?>
                <details class="bio-link" data-id="<?= (int) $l['id'] ?>">
                    <summary>
                        <?php if ($canEdit): ?><span class="grip" aria-hidden="true"><?= icon('grip') ?></span><?php endif; ?>
                        <span class="grow"><strong><?= e($l['label']) ?></strong><br><small class="muted ellipsis"><?= e($l['url']) ?></small></span>
                        <?php if (!$l['active']): ?><span class="badge">Hidden</span><?php endif; ?>
                        <span class="muted small nowrap"><?= (int) ($clicks[$l['id']] ?? 0) ?> clicks</span>
                    </summary>
                    <?php if ($canEdit): ?>
                    <form method="post" action="<?= e(url('/bio/links/' . $l['id'])) ?>">
                        <?= csrf_field() ?>
                        <div class="field-row">
                            <label class="field"><span>Label</span><input type="text" name="label" value="<?= e($l['label']) ?>" required></label>
                            <label class="field"><span>URL</span><input type="text" name="url" value="<?= e($l['url']) ?>" required></label>
                        </div>
                        <label class="check"><input type="checkbox" name="active" value="1" <?= $l['active'] ? 'checked' : '' ?>> <span>Show on page</span></label>
                        <div class="form-actions">
                            <button class="btn btn-ghost btn-danger-text" type="submit" form="del-link-<?= (int) $l['id'] ?>">Delete</button>
                            <span class="grow"></span>
                            <button class="btn btn-primary btn-sm" type="submit">Save</button>
                        </div>
                    </form>
                    <form id="del-link-<?= (int) $l['id'] ?>" method="post" action="<?= e(url('/bio/links/' . $l['id'] . '/delete')) ?>" data-confirm="Delete this link?"><?= csrf_field() ?></form>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        </div>
        <?php if (!$links): ?><p class="muted">No links yet.</p><?php endif; ?>
        <?php if ($canEdit): ?>
        <form class="field-row align-end" method="post" action="<?= e(url('/bio/links')) ?>">
            <?= csrf_field() ?>
            <label class="field"><span>Label</span><input type="text" name="label" placeholder="Shop the collection" required></label>
            <label class="field"><span>URL</span><input type="text" name="url" placeholder="https://…" required></label>
            <button class="btn btn-primary" type="submit"><?= icon('plus') ?> Add</button>
        </form>
        <?php endif; ?>
        <h3>Views per day</h3>
        <div class="chart short" data-chart="bar" data-series="<?= e(json_encode($series)) ?>" data-label="Views"></div>
    </section>

    <form class="card" method="post" action="<?= e(url('/bio')) ?>">
        <?= csrf_field() ?>
        <h2>Page</h2>
        <label class="field"><span>Address</span>
            <div class="input-prefix"><span><?= e(preg_replace('#^https?://#', '', abs_url('@'))) ?></span><input type="text" name="slug" value="<?= e($page['slug']) ?>" required <?= $canEdit ? '' : 'disabled' ?>></div>
        </label>
        <label class="field"><span>Title</span><input type="text" name="title" value="<?= e($page['title']) ?>" maxlength="80" <?= $canEdit ? '' : 'disabled' ?>></label>
        <label class="field"><span>Bio</span><textarea name="bio" rows="3" maxlength="300" <?= $canEdit ? '' : 'disabled' ?>><?= e($page['bio']) ?></textarea></label>
        <div class="field">
            <span>Profile picture</span>
            <div class="bio-avatar-row">
                <span class="avatar"><?= e(initials($page['title'] ?: '?')) ?><?php if ($avatar): ?><img src="<?= e(url(substr($avatar['path'], strlen(BASE_PATH . '/public/')))) ?>" alt="" data-bio-avatar-img><?php endif; ?></span>
                <input type="hidden" name="avatar_media_id" value="<?= (int) $page['avatar_media_id'] ?>" data-bio-avatar>
                <?php if ($canEdit): ?><button class="btn btn-sm" type="button" data-pick-avatar>Choose from media</button><?php endif; ?>
            </div>
        </div>
        <div class="field-row">
            <label class="field"><span>Style</span>
                <select name="style" <?= $canEdit ? '' : 'disabled' ?>><?php foreach ($themes as $k => $t): ?><option value="<?= e($k) ?>" <?= ($theme['style'] ?? 'light') === $k ? 'selected' : '' ?>><?= e($t['label']) ?></option><?php endforeach; ?></select>
            </label>
            <label class="field"><span>Buttons</span>
                <select name="button" <?= $canEdit ? '' : 'disabled' ?>><option value="rounded">Rounded</option><option value="square" <?= ($theme['button'] ?? '') === 'square' ? 'selected' : '' ?>>Square</option></select>
            </label>
            <label class="field"><span>Accent</span><input type="color" name="accent" value="<?= e($theme['accent'] ?? '#4f46e5') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
        </div>
        <label class="check"><input type="checkbox" name="show_socials" value="1" <?= $page['show_socials'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> <span>Show icons for this workspace’s social profiles</span></label>
        <label class="check"><input type="checkbox" name="show_posts" value="1" <?= $page['show_posts'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> <span>Show a grid of recent image posts, each linking to the post</span></label>
        <label class="check"><input type="checkbox" name="published" value="1" <?= $page['published'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> <span><strong>Published</strong> — anyone with the address can see it</span></label>
        <?php if ($canEdit): ?><div class="form-actions"><button class="btn btn-primary" type="submit">Save page</button></div><?php endif; ?>
    </form>
</div>

<dialog id="avatar-dialog" class="dialog dialog-wide">
    <div class="dialog-head"><h2>Choose a picture</h2><button type="button" class="icon-btn" data-close-dialog aria-label="Close"><?= icon('x') ?></button></div>
    <div class="media-grid" id="avatar-library"><p class="muted">Loading…</p></div>
</dialog>
