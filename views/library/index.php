<?php
use App\Workspace;

$canEdit = Workspace::can('member');
$isAdmin = Workspace::can('admin');
?>
<div class="page-head">
    <h1>Library</h1>
</div>
<nav class="tabs" data-tabs>
    <a href="#templates">Templates</a>
    <a href="#hashtags">Hashtag groups</a>
    <a href="#variables">Variables</a>
    <a href="#tags">Tags</a>
</nav>

<section class="tab-panel" id="templates">
    <div class="card">
        <div class="card-head"><h2>Templates</h2></div>
        <p class="muted small">Reusable post text. Pick one from the composer toolbar, or start a post from it here.</p>
        <?php if (!$templates): ?><p class="muted">No templates yet.</p><?php endif; ?>
        <div class="lib-list">
            <?php foreach ($templates as $t): ?>
                <details class="lib-item">
                    <summary>
                        <strong><?= e($t['name']) ?></strong>
                        <span class="muted small grow"><?= e(str_limit($t['content'], 90)) ?></span>
                        <?php if ($canEdit): ?><a class="btn btn-sm" href="<?= e(url('/posts/new', ['template' => $t['id']])) ?>">Use</a><?php endif; ?>
                    </summary>
                    <?php if ($canEdit): ?>
                    <form method="post" action="<?= e(url('/library/templates')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                        <?php foreach (json_arr($t['media']) as $mid): ?><input type="hidden" name="media[]" value="<?= (int) $mid ?>"><?php endforeach; ?>
                        <label class="field"><span>Name</span><input type="text" name="name" value="<?= e($t['name']) ?>" required></label>
                        <label class="field"><span>Text</span><textarea name="content" rows="5"><?= e($t['content']) ?></textarea></label>
                        <div class="form-actions">
                            <button class="btn btn-ghost btn-danger-text" type="submit" form="del-tpl-<?= (int) $t['id'] ?>">Delete</button>
                            <span class="grow"></span>
                            <button class="btn btn-primary" type="submit">Save</button>
                        </div>
                    </form>
                    <form id="del-tpl-<?= (int) $t['id'] ?>" method="post" action="<?= e(url('/library/templates/' . $t['id'] . '/delete')) ?>" data-confirm="Delete this template?"><?= csrf_field() ?></form>
                    <?php else: ?>
                        <pre class="pre-wrap"><?= e($t['content']) ?></pre>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        </div>
        <?php if ($canEdit): ?>
        <details class="add-block">
            <summary class="btn"><?= icon('plus') ?> New template</summary>
            <form method="post" action="<?= e(url('/library/templates')) ?>">
                <?= csrf_field() ?>
                <label class="field"><span>Name</span><input type="text" name="name" required></label>
                <label class="field"><span>Text</span><textarea name="content" rows="5" placeholder="Use {{variables}} if you like"></textarea></label>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Add template</button></div>
            </form>
        </details>
        <?php endif; ?>
    </div>
</section>

<section class="tab-panel" id="hashtags">
    <div class="card">
        <div class="card-head"><h2>Hashtag groups</h2></div>
        <p class="muted small">Saved sets of hashtags you can drop into a post with one click.</p>
        <?php if (!$groups): ?><p class="muted">No hashtag groups yet.</p><?php endif; ?>
        <div class="lib-list">
            <?php foreach ($groups as $g): ?>
                <details class="lib-item">
                    <summary><strong><?= e($g['name']) ?></strong><span class="muted small grow"><?= e(str_limit($g['hashtags'], 90)) ?></span></summary>
                    <?php if ($canEdit): ?>
                    <form method="post" action="<?= e(url('/library/hashtags')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                        <label class="field"><span>Name</span><input type="text" name="name" value="<?= e($g['name']) ?>" required></label>
                        <label class="field"><span>Hashtags</span><textarea name="hashtags" rows="3"><?= e($g['hashtags']) ?></textarea></label>
                        <div class="form-actions">
                            <button class="btn btn-ghost btn-danger-text" type="submit" form="del-grp-<?= (int) $g['id'] ?>">Delete</button>
                            <span class="grow"></span>
                            <button class="btn btn-primary" type="submit">Save</button>
                        </div>
                    </form>
                    <form id="del-grp-<?= (int) $g['id'] ?>" method="post" action="<?= e(url('/library/hashtags/' . $g['id'] . '/delete')) ?>" data-confirm="Delete this group?"><?= csrf_field() ?></form>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        </div>
        <?php if ($canEdit): ?>
        <details class="add-block">
            <summary class="btn"><?= icon('plus') ?> New hashtag group</summary>
            <form method="post" action="<?= e(url('/library/hashtags')) ?>">
                <?= csrf_field() ?>
                <label class="field"><span>Name</span><input type="text" name="name" required placeholder="e.g. Product launch"></label>
                <label class="field"><span>Hashtags</span><textarea name="hashtags" rows="3" placeholder="#launch #startup design, product"></textarea><small class="hint">Separate with spaces or commas; # is added for you.</small></label>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Add group</button></div>
            </form>
        </details>
        <?php endif; ?>
    </div>
</section>

<section class="tab-panel" id="variables">
    <div class="card">
        <div class="card-head"><h2>Variables</h2></div>
        <p class="muted small">Write <code>{{name}}</code> in any post and it’s replaced with the value when publishing. Change a value once and every scheduled post picks it up.</p>
        <?php if (!$variables): ?><p class="muted">No variables yet.</p><?php endif; ?>
        <div class="table-wrap">
        <table class="table">
            <?php foreach ($variables as $v): ?>
                <tr>
                    <td><code>{{<?= e($v['name']) ?>}}</code></td>
                    <td class="grow"><?php if ($isAdmin): ?>
                        <form class="inline-form" method="post" action="<?= e(url('/library/variables')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                            <input type="hidden" name="name" value="<?= e($v['name']) ?>">
                            <input type="text" name="value" value="<?= e($v['value']) ?>" aria-label="Value">
                            <button class="btn btn-sm" type="submit">Save</button>
                        </form>
                    <?php else: ?><?= e($v['value']) ?><?php endif; ?></td>
                    <td class="right"><?php if ($isAdmin): ?>
                        <form method="post" action="<?= e(url('/library/variables/' . $v['id'] . '/delete')) ?>" data-confirm="Delete {{<?= e($v['name']) ?>}}? Posts using it will show the raw placeholder.">
                            <?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Delete"><?= icon('trash') ?></button>
                        </form>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php if ($isAdmin): ?>
        <form class="field-row align-end" method="post" action="<?= e(url('/library/variables')) ?>">
            <?= csrf_field() ?>
            <label class="field"><span>Name</span><input type="text" name="name" required pattern="[\w.\-]{1,40}" placeholder="website"></label>
            <label class="field grow"><span>Value</span><input type="text" name="value" placeholder="https://example.com"></label>
            <button class="btn btn-primary" type="submit">Add</button>
        </form>
        <?php endif; ?>
    </div>
</section>

<section class="tab-panel" id="tags">
    <div class="card">
        <div class="card-head"><h2>Tags</h2></div>
        <p class="muted small">Label posts by campaign or topic, then filter the post list by tag.</p>
        <div class="tag-cloud">
            <?php foreach ($tags as $t): ?>
                <span class="tag big" style="--tc: <?= e($t['color']) ?>">
                    <?= e($t['name']) ?> <small><?= (int) $t['posts'] ?></small>
                    <?php if ($canEdit): ?>
                    <form method="post" action="<?= e(url('/tags/' . $t['id'] . '/delete')) ?>" data-confirm="Delete the tag “<?= e($t['name']) ?>”?">
                        <?= csrf_field() ?><button type="submit" class="tag-x" aria-label="Delete tag"><?= icon('x') ?></button>
                    </form>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
            <?php if (!$tags): ?><p class="muted">No tags yet.</p><?php endif; ?>
        </div>
        <?php if ($canEdit): ?>
        <form class="field-row align-end" method="post" action="<?= e(url('/tags')) ?>">
            <?= csrf_field() ?>
            <label class="field grow"><span>New tag</span><input type="text" name="name" maxlength="40" required></label>
            <label class="field"><span>Color</span><input type="color" name="color" value="#4f46e5"></label>
            <button class="btn btn-primary" type="submit">Add</button>
        </form>
        <?php endif; ?>
    </div>
</section>
