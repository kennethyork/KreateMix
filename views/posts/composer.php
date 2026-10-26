<?php
use App\Workspace;

$selected = array_map(fn($t) => (int) $t['account_id'], $targets);
$overrides = [];
foreach ($targets as $t) {
    if (!empty($t['content'])) {
        $overrides[(int) $t['account_id']] = $t['content'];
    }
}
$targetById = array_column($targets, null, 'account_id');
$typesById = [];
foreach ($targets as $t) {
    $typesById[(int) $t['account_id']] = json_arr($t['options'] ?? '{}')['type'] ?? 'feed';
}
$date = $post['scheduled_at'] ? local_time($post['scheduled_at'], 'Y-m-d') : '';
$time = $post['scheduled_at'] ? local_time($post['scheduled_at'], 'H:i') : '';
$dis = $readonly ? 'disabled' : '';
$data = [
    'accounts' => array_map(fn($a) => [
        'id' => (int) $a['id'], 'provider' => $a['provider'], 'name' => $a['name'], 'username' => $a['username'],
        'avatar' => $a['avatar'], 'authorized' => (bool) $a['authorized'],
    ], $accounts),
    'providers' => $providers,
    'media' => array_map(fn($m) => ['id' => $m['id'], 'url' => url(substr($m['path'], strlen(BASE_PATH . '/public/'))), 'mime' => $m['mime'], 'name' => $m['name']], $media),
    'variables' => array_column($variables, 'value', 'name'),
    'readonly' => $readonly,
];
?>
<form class="composer" method="post" id="composer" action="<?= e(url($post['id'] ? '/posts/' . $post['id'] : '/posts')) ?>" data-composer>
    <?= csrf_field() ?>
    <script type="application/json" id="composer-data"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>

    <div class="page-head">
        <div>
            <h1><?= e($title) ?></h1>
            <?php if ($post['id']): ?><div class="muted small"><?= status_badge($post['status']) ?></div><?php endif; ?>
        </div>
        <?php if ($post['id']): ?>
            <div class="head-actions">
                <?php if (Workspace::can('member')): ?>
                    <?php if ($autolists): ?>
                        <details class="dropdown">
                            <summary class="btn"><?= icon('refresh') ?> Add to autolist</summary>
                            <div class="dropdown-menu">
                                <?php foreach ($autolists as $al): ?>
                                    <button type="submit" form="autolist-form" name="autolist" value="<?= (int) $al['id'] ?>"><strong><?= e($al['name']) ?></strong></button>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                    <button class="btn" type="submit" form="duplicate-form"><?= icon('copy') ?> Duplicate</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($post['review_note']): ?>
        <div class="notice notice-warn"><?= icon('alert') ?><div><strong>Sent back for changes:</strong> <?= e($post['review_note']) ?></div></div>
    <?php endif; ?>

    <?php if ($post['status'] === 'pending_approval' && $canApprove): ?>
        <div class="notice notice-info approval-bar">
            <?= icon('shield') ?>
            <div class="grow"><strong>Waiting for approval.</strong> Review it below, then approve or send it back.</div>
            <button class="btn btn-primary" type="submit" name="action" value="approve">Approve</button>
            <button class="btn" type="button" data-open-dialog="reject-dialog">Send back</button>
        </div>
    <?php elseif ($post['status'] === 'pending_approval'): ?>
        <div class="notice notice-info"><?= icon('clock') ?><div>Waiting for an admin to approve. You can still edit it.</div></div>
    <?php endif; ?>

    <?php if (in_array($post['status'], ['published', 'failed', 'publishing'], true) && $targets): ?>
        <section class="card results">
            <h2>Results</h2>
            <?php foreach ($targets as $t):
                $acct = current(array_filter($accounts, fn($a) => (int) $a['id'] === (int) $t['account_id']));
                if (!$acct) continue; ?>
                <div class="result-row">
                    <?= account_avatar($acct, 'sm') ?>
                    <div class="grow">
                        <strong><?= e($acct['name']) ?></strong>
                        <?php if ($t['status'] === 'failed'): ?>
                            <div class="error-text"><?= e($t['error'] ?: 'Failed') ?></div>
                        <?php elseif ($t['status'] === 'published'): ?>
                            <div class="muted small">Published <?= e(local_time($t['published_at'])) ?></div>
                        <?php else: ?>
                            <div class="muted small">Pending</div>
                        <?php endif; ?>
                    </div>
                    <?php if ($t['url']): ?><a class="btn btn-sm" href="<?= e($t['url']) ?>" target="_blank" rel="noopener">View <?= icon('external') ?></a><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($post['status'] === 'failed' && Workspace::can('member')): ?>
                <div class="form-actions"><button class="btn btn-primary" type="submit" form="retry-form"><?= icon('refresh') ?> Retry failed</button></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="composer-grid">
        <div class="composer-main">
            <section class="card">
                <div class="card-head">
                    <h2>Accounts</h2>
                    <?php if (!$readonly && count($accounts) > 1): ?>
                        <button type="button" class="link" data-select-all-accounts>Select all</button>
                    <?php endif; ?>
                </div>
                <?php if (!$accounts): ?>
                    <p class="muted">No accounts connected. <a href="<?= e(url('/accounts')) ?>">Connect one</a> first.</p>
                <?php endif; ?>
                <div class="account-picker">
                    <?php foreach ($accounts as $a): ?>
                        <label class="account-chip<?= $a['authorized'] ? '' : ' is-unauthorized' ?>" title="<?= e(provider_meta($a['provider'])['name'] . ' · @' . $a['username']) ?>">
                            <input type="checkbox" name="accounts[]" value="<?= (int) $a['id'] ?>" <?= in_array((int) $a['id'], $selected, true) ? 'checked' : '' ?> <?= $dis ?>>
                            <?= account_avatar($a, 'sm') ?>
                            <span class="account-chip-name"><?= e($a['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="card">
                <div class="editor">
                    <textarea name="content" id="content" rows="8" placeholder="What do you want to share?" <?= $dis ?>><?= e($post['content']) ?></textarea>
                    <?php if (!$readonly): ?>
                    <div class="editor-toolbar">
                        <button type="button" class="tool" data-open-media title="Add media"><?= icon('image') ?><span>Media</span></button>
                        <?php if ($aiEnabled): ?>
                            <button type="button" class="tool tool-ai" data-open-dialog="ai-dialog" title="Write with AI"><?= icon('sparkles') ?><span>AI</span></button>
                        <?php endif; ?>
                        <?php if ($hashtagGroups): ?>
                        <details class="dropdown">
                            <summary class="tool" title="Insert hashtags"><?= icon('hash') ?><span>Hashtags</span></summary>
                            <div class="dropdown-menu">
                                <?php foreach ($hashtagGroups as $g): ?>
                                    <button type="button" data-insert="<?= e(' ' . $g['hashtags']) ?>"><strong><?= e($g['name']) ?></strong><small><?= e(str_limit($g['hashtags'], 60)) ?></small></button>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php endif; ?>
                        <?php if ($variables): ?>
                        <details class="dropdown">
                            <summary class="tool" title="Insert variable"><?= icon('braces') ?><span>Variables</span></summary>
                            <div class="dropdown-menu">
                                <?php foreach ($variables as $v): ?>
                                    <button type="button" data-insert="<?= e('{{' . $v['name'] . '}}') ?>"><strong>{{<?= e($v['name']) ?>}}</strong><small><?= e(str_limit($v['value'], 60)) ?></small></button>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php endif; ?>
                        <?php if ($templates): ?>
                        <details class="dropdown">
                            <summary class="tool" title="Use a template"><?= icon('book') ?><span>Templates</span></summary>
                            <div class="dropdown-menu">
                                <?php foreach ($templates as $tpl): ?>
                                    <button type="button" data-template="<?= e($tpl['content']) ?>"><strong><?= e($tpl['name']) ?></strong><small><?= e(str_limit($tpl['content'], 60)) ?></small></button>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php endif; ?>
                        <span class="grow"></span>
                        <div class="counters" id="counters" aria-live="polite"></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="media-strip" id="media-strip" data-sortable></div>

                <details class="first-comment" <?= !empty($post['first_comment']) ? 'open' : '' ?>>
                    <summary>First comment</summary>
                    <p class="muted small">Posted as a comment right after publishing, on platforms that allow it. Handy for hashtags or links.</p>
                    <textarea name="first_comment" rows="2" placeholder="#hashtags or a link…" <?= $dis ?>><?= e($post['first_comment'] ?? '') ?></textarea>
                </details>

                <details class="overrides" id="overrides" <?= $overrides ? 'open' : '' ?>>
                    <summary>Customize per account</summary>
                    <p class="muted small">Leave text blank to use the main text. Stories use one image or video and ignore text.</p>
                    <div id="override-fields">
                        <?php foreach ($accounts as $a): ?>
                            <label class="field override" data-override-for="<?= (int) $a['id'] ?>" <?= in_array((int) $a['id'], $selected, true) ? '' : 'hidden' ?>>
                                <span><?= account_avatar($a, 'xs') ?> <?= e($a['name']) ?> <small class="muted counter-inline" data-override-count></small>
                                    <?php $ptypes = $providers[$a['provider']]['types'] ?? []; if (count($ptypes) > 1): ?>
                                        <select class="type-select" name="types[<?= (int) $a['id'] ?>]" aria-label="Post type for <?= e($a['name']) ?>" <?= $dis ?>>
                                            <?php foreach ($ptypes as $tk => $tl): ?><option value="<?= e($tk) ?>" <?= ($typesById[(int) $a['id']] ?? 'feed') === $tk ? 'selected' : '' ?>><?= e($tl) ?></option><?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </span>
                                <textarea name="overrides[<?= (int) $a['id'] ?>]" rows="3" <?= $dis ?>><?= e($overrides[(int) $a['id']] ?? '') ?></textarea>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </details>
            </section>

            <section class="card">
                <div class="field-row schedule-row">
                    <label class="field">
                        <span>Date</span>
                        <input type="date" name="date" value="<?= e($date) ?>" <?= $dis ?>>
                    </label>
                    <label class="field">
                        <span>Time <small class="muted">(<?= e(str_replace('_', ' ', user_tz())) ?>)</small></span>
                        <input type="time" name="time" value="<?= e($time) ?>" <?= $dis ?>>
                    </label>
                </div>
                <?php if ($bestTimes): ?>
                    <div class="best-chips">
                        <span class="muted small"><?= icon('clock') ?> Your best times:</span>
                        <?php foreach ($bestTimes as $bt): ?>
                            <button type="button" class="chip" data-best-day="<?= (int) $bt['day'] ?>" data-best-hour="<?= (int) $bt['hour'] ?>"><?= e(substr(App\Analytics::dayName($bt['day']), 0, 3) . ' ' . App\Analytics::hourLabel($bt['hour'])) ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($tags || !$readonly): ?>
                <fieldset class="field">
                    <legend>Tags</legend>
                    <div class="tag-picker" id="tag-picker">
                        <?php foreach ($tags as $t): ?>
                            <label class="tag-chip" style="--tc: <?= e($t['color']) ?>">
                                <input type="checkbox" name="tags[]" value="<?= (int) $t['id'] ?>" <?= in_array((int) $t['id'], $tagIds, true) ? 'checked' : '' ?> <?= $dis ?>>
                                <span><?= e($t['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                        <?php if (!$readonly): ?>
                            <button type="button" class="tag-add" data-new-tag><?= icon('plus') ?> Tag</button>
                        <?php endif; ?>
                    </div>
                </fieldset>
                <?php endif; ?>

                <?php foreach ($panels as $panel) { echo $panel; } ?>

                <?php if (!$readonly): ?>
                <div class="form-actions composer-actions">
                    <button class="btn" type="submit" name="action" value="draft">Save draft</button>
                    <span class="grow"></span>
                    <button class="btn" type="button" data-save-template title="Save the text as a reusable template"><?= icon('book') ?> Save as template</button>
                    <?php if ($needsApproval): ?>
                        <button class="btn btn-primary" type="submit" name="action" value="schedule" data-needs-date>Submit for approval</button>
                    <?php else: ?>
                        <button class="btn" type="submit" name="action" value="now" data-confirm="Publish to the selected accounts now?">Publish now</button>
                        <button class="btn btn-primary" type="submit" name="action" value="schedule" data-needs-date><?= icon('clock') ?> Schedule</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="composer-preview">
            <div class="preview-head">
                <h2>Preview</h2>
            </div>
            <div id="previews" class="previews">
                <p class="muted small">Select accounts to see how the post will look.</p>
            </div>
        </aside>
    </div>

    <?php if ($post['id'] && Workspace::can('member') && $post['status'] !== 'publishing'): ?>
        <div class="delete-row">
            <button class="btn btn-ghost btn-danger-text" type="submit" form="delete-form"><?= icon('trash') ?> Delete post</button>
        </div>
    <?php endif; ?>
</form>

<?php if ($post['id']): ?>
    <form id="delete-form" method="post" action="<?= e(url('/posts/' . $post['id'] . '/delete')) ?>" data-confirm="Delete this post? Anything already published stays on the platforms."><?= csrf_field() ?></form>
    <form id="duplicate-form" method="post" action="<?= e(url('/posts/' . $post['id'] . '/duplicate')) ?>"><?= csrf_field() ?></form>
    <form id="autolist-form" method="post" action="<?= e(url('/posts/' . $post['id'] . '/autolist')) ?>"><?= csrf_field() ?></form>
    <form id="retry-form" method="post" action="<?= e(url('/posts/' . $post['id'] . '/retry')) ?>"><?= csrf_field() ?></form>
<?php endif; ?>

<?php if ($post['status'] === 'pending_approval' && $canApprove): ?>
<dialog id="reject-dialog" class="dialog">
    <form method="post" action="<?= e(url('/posts/' . $post['id'])) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reject">
        <h2>Send back for changes</h2>
        <label class="field">
            <span>Note for the author</span>
            <textarea name="note" rows="3" placeholder="What should change?"></textarea>
        </label>
        <div class="form-actions">
            <button type="button" class="btn" data-close-dialog>Cancel</button>
            <button type="submit" class="btn btn-primary">Send back</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<dialog id="media-dialog" class="dialog dialog-wide">
    <div class="dialog-head">
        <h2>Media</h2>
        <button type="button" class="icon-btn" data-close-dialog aria-label="Close"><?= icon('x') ?></button>
    </div>
    <label class="dropzone" id="dropzone">
        <input type="file" id="media-file" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,video/webm" multiple hidden>
        <?= icon('upload') ?>
        <span><strong>Upload files</strong> or drop them here</span>
        <small class="muted">JPG, PNG, GIF, WebP, MP4, MOV, WebM</small>
    </label>
    <div class="upload-progress" id="upload-progress"></div>
    <?php if ($canvaEnabled || $driveEnabled): ?>
        <div class="canva-bar">
            <?php if ($canvaEnabled): ?><button type="button" class="btn btn-sm" data-cloud-open="canva"><?= icon('palette') ?> Import from Canva</button><?php endif; ?>
            <?php if ($driveEnabled): ?><button type="button" class="btn btn-sm" data-cloud-open="drive"><?= icon('download') ?> Import from Google Drive</button><?php endif; ?>
        </div>
        <div class="canva-panel" id="canva-panel" hidden>
            <div class="canva-head"><input type="search" id="canva-q" placeholder="Search"><button type="button" class="btn btn-sm" data-canva-close>Back to library</button></div>
            <div class="media-grid" id="canva-grid"></div>
            <div class="center"><button type="button" class="btn btn-sm" id="canva-more" hidden>Load more</button></div>
        </div>
    <?php endif; ?>
    <div class="media-grid" id="media-library"><p class="muted">Loading…</p></div>
    <div class="form-actions">
        <button type="button" class="btn" data-close-dialog>Cancel</button>
        <button type="button" class="btn btn-primary" id="media-insert">Add selected</button>
    </div>
</dialog>

<?php if ($aiEnabled && !$readonly): ?>
<dialog id="ai-dialog" class="dialog dialog-wide">
    <div class="dialog-head"><h2><?= icon('sparkles') ?> Write with AI</h2><button type="button" class="icon-btn" data-close-dialog aria-label="Close"><?= icon('x') ?></button></div>
    <label class="field"><span>What should the post be about? <small class="muted">(for new posts and variations)</small></span>
        <textarea id="ai-idea" rows="2" placeholder="e.g. Announce our spring sale, 20% off everything until Sunday"></textarea>
    </label>
    <div class="ai-actions">
        <?php foreach (App\Ai::ACTIONS as $k => $label): ?>
            <button type="button" class="btn btn-sm<?= $k === 'write' ? ' btn-primary' : '' ?>" data-ai-action="<?= e($k) ?>"><?= e($label) ?></button>
        <?php endforeach; ?>
    </div>
    <p class="muted small">Improve, shorten and the other edits work on the text currently in the post. The character limit follows the accounts you’ve selected.</p>
    <div id="ai-results" class="ai-results" aria-live="polite"></div>
</dialog>
<?php endif; ?>
