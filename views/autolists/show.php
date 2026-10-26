<?php use App\Workspace; $canEdit = Workspace::can('member'); ?>
<div class="page-head">
    <div>
        <a class="link" href="<?= e(url('/autolists')) ?>">← Autolists</a>
        <h1><?= e($list['name']) ?></h1>
        <p class="muted"><?= count($items) ?> posts · <?= $list['active'] ? 'Active' : 'Paused' ?><?= $upcoming && $list['active'] ? ' · next ' . e($upcoming[0]->format('D, M j g:i A')) : '' ?></p>
    </div>
    <?php if ($canEdit): ?>
    <div class="head-actions">
        <form method="post" action="<?= e(url('/autolists/' . $list['id'] . '/publish')) ?>" data-confirm="Publish the next item now?"><?= csrf_field() ?><button class="btn" type="submit">Publish next now</button></form>
    </div>
    <?php endif; ?>
</div>

<div class="grid-2">
    <section class="card">
        <h2>Posts in this list</h2>
        <?php if (!$items): ?><p class="muted">Empty. Add posts below, or use “Add to autolist” on any existing post.</p><?php endif; ?>
        <div class="lib-list">
            <?php foreach ($items as $i => $it): $isNext = $list['order_mode'] === 'sequence' && $items && $i === ((int) $list['next_index'] % count($items)); ?>
                <details class="lib-item">
                    <summary>
                        <span class="muted small nowrap">#<?= $i + 1 ?></span>
                        <span class="grow ellipsis"><?= e(str_limit($it['content'] ?: '(media only)', 90)) ?></span>
                        <?php if ($isNext): ?><span class="badge status-scheduled">Next</span><?php endif; ?>
                        <span class="muted small nowrap"><?= (int) $it['times_posted'] ?>×</span>
                    </summary>
                    <?php if ($canEdit): ?>
                    <form method="post" action="<?= e(url('/autolists/' . $list['id'] . '/items')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                        <textarea name="content" rows="4"><?= e($it['content']) ?></textarea>
                        <div class="mini-media" data-media-field>
                            <?php foreach ($it['media_items'] as $m): ?>
                                <span class="mini-thumb"><input type="hidden" name="media[]" value="<?= (int) $m['id'] ?>"><?php if (str_starts_with($m['mime'], 'image/')): ?><img src="<?= e(url(substr($m['path'], strlen(BASE_PATH . '/public/')))) ?>" alt=""><?php else: ?><?= icon('play') ?><?php endif; ?><button type="button" data-remove-media aria-label="Remove"><?= icon('x') ?></button></span>
                            <?php endforeach; ?>
                            <button type="button" class="btn btn-sm" data-pick-media><?= icon('image') ?> Media</button>
                        </div>
                        <div class="form-actions">
                            <button class="btn btn-ghost btn-danger-text" type="submit" form="del-item-<?= (int) $it['id'] ?>">Remove</button>
                            <span class="grow"></span>
                            <button class="btn btn-primary btn-sm" type="submit">Save</button>
                        </div>
                    </form>
                    <form id="del-item-<?= (int) $it['id'] ?>" method="post" action="<?= e(url('/autolists/' . $list['id'] . '/items/' . $it['id'] . '/delete')) ?>"><?= csrf_field() ?></form>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        </div>
        <?php if ($canEdit): ?>
        <form method="post" action="<?= e(url('/autolists/' . $list['id'] . '/items')) ?>" class="add-item">
            <?= csrf_field() ?>
            <label class="field"><span>New post</span><textarea name="content" rows="3" placeholder="Write a post for this list. {{variables}} work here too."></textarea></label>
            <div class="mini-media" data-media-field><button type="button" class="btn btn-sm" data-pick-media><?= icon('image') ?> Media</button></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('plus') ?> Add</button></div>
        </form>
        <?php endif; ?>
    </section>

    <div class="stack">
        <section class="card">
            <h2>Coming up</h2>
            <?php if (!$upcoming || !$list['active']): ?><p class="muted"><?= $list['active'] ? 'No slots.' : 'Paused.' ?></p><?php endif; ?>
            <?php if ($list['active']): foreach ($upcoming as $u): ?><div class="muted small"><?= e($u->format('l, M j · g:i A')) ?></div><?php endforeach; endif; ?>
        </section>
        <?php if ($recent): ?>
        <section class="card">
            <h2>Recently created</h2>
            <div class="post-list compact"><?php foreach ($recent as $p) { partial('partials/post_row', ['post' => $p]); } ?></div>
        </section>
        <?php endif; ?>
        <?php if ($canEdit): ?>
        <details class="card">
            <summary class="btn btn-sm">Settings</summary>
            <?php partial('autolists/form', ['list' => $list, 'accounts' => $accounts]); ?>
            <form method="post" action="<?= e(url('/autolists/' . $list['id'] . '/delete')) ?>" data-confirm="Delete this autolist?"><?= csrf_field() ?><button class="btn btn-ghost btn-danger-text" type="submit">Delete autolist</button></form>
        </details>
        <?php endif; ?>
    </div>
</div>

<dialog id="pick-media-dialog" class="dialog dialog-wide">
    <div class="dialog-head"><h2>Choose media</h2><button type="button" class="icon-btn" data-close-dialog aria-label="Close"><?= icon('x') ?></button></div>
    <div class="media-grid" id="pick-media-grid"><p class="muted">Loading…</p></div>
    <div class="form-actions"><button type="button" class="btn btn-primary" id="pick-media-done">Add selected</button></div>
</dialog>
