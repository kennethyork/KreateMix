<div class="page-head">
    <h1>API &amp; webhooks</h1>
</div>

<?php if ($newToken): ?>
    <div class="notice notice-success">
        <?= icon('key') ?>
        <div class="grow"><strong>Copy your token now.</strong> It won’t be shown again.
            <code class="copy" data-copy><?= e($newToken) ?></code></div>
    </div>
<?php endif; ?>
<?php if ($newSecret): ?>
    <div class="notice notice-success">
        <?= icon('key') ?>
        <div class="grow"><strong>Webhook created. Copy its signing secret now.</strong> It won’t be shown again.
            <code class="copy" data-copy><?= e($newSecret) ?></code></div>
    </div>
<?php endif; ?>

<section class="card">
    <h2>API tokens</h2>
    <p class="muted small">Tokens act as you, in this workspace, with your role. Send them as <code>Authorization: Bearer &lt;token&gt;</code> to <code><?= e($apiBase) ?></code>.</p>
    <?php if ($tokens): ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Name</th><th>Created</th><th>Last used</th><th></th></tr></thead>
        <?php foreach ($tokens as $t): ?>
            <tr>
                <td><?= e($t['name']) ?></td>
                <td class="muted"><?= e(local_time($t['created_at'], 'M j, Y')) ?></td>
                <td class="muted"><?= $t['last_used_at'] ? e(local_time($t['last_used_at'])) : 'Never' ?></td>
                <td class="right">
                    <form method="post" action="<?= e(url('/developers/tokens/' . $t['id'] . '/delete')) ?>" data-confirm="Revoke this token? Apps using it will stop working.">
                        <?= csrf_field() ?><button class="btn btn-sm" type="submit">Revoke</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
    <form class="field-row align-end" method="post" action="<?= e(url('/developers/tokens')) ?>">
        <?= csrf_field() ?>
        <label class="field grow"><span>Token name</span><input type="text" name="name" placeholder="e.g. Zapier, my script" required></label>
        <button class="btn btn-primary" type="submit">Create token</button>
    </form>

    <details class="api-docs">
        <summary>API reference</summary>
        <div class="docs">
            <table class="table">
                <tr><td><code>GET /me</code></td><td>Your user, role and workspace</td></tr>
                <tr><td><code>GET /accounts</code></td><td>Connected accounts</td></tr>
                <tr><td><code>GET /tags</code></td><td>Tags</td></tr>
                <tr><td><code>GET /media</code></td><td>Latest 100 media files</td></tr>
                <tr><td><code>POST /media</code></td><td>Upload a file (multipart field <code>file</code>)</td></tr>
                <tr><td><code>GET /posts?status=&amp;page=&amp;limit=</code></td><td>List posts</td></tr>
                <tr><td><code>POST /posts</code></td><td>Create a post</td></tr>
                <tr><td><code>GET /posts/{id}</code></td><td>Get a post with per-account results</td></tr>
                <tr><td><code>PUT /posts/{id}</code></td><td>Update a post</td></tr>
                <tr><td><code>DELETE /posts/{id}</code></td><td>Delete a post</td></tr>
                <tr><td><code>POST /posts/{id}/approve</code></td><td>Approve (admins)</td></tr>
                <tr><td><code>POST /posts/{id}/reject</code></td><td>Send back with <code>{"note": "…"}</code> (admins)</td></tr>
            </table>
            <p class="small muted">Create or update body:</p>
<pre class="code">{
  "content": "Hello from the API",
  "accounts": [1, 2],
  "media": [5],
  "tags": [3],
  "overrides": { "2": "Different text for account 2" },
  "scheduled_at": "2026-11-03T09:00:00-05:00",
  "action": "schedule"      // "draft", "schedule" or "now"
}</pre>
<pre class="code">curl -X POST <?= e($apiBase) ?>/posts \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"content":"Hi","accounts":[1],"action":"now"}'</pre>
        </div>
    </details>
</section>

<section class="card">
    <h2>Webhooks</h2>
    <p class="muted small">MixCreate POSTs JSON to your URL when things happen. Verify the <code>X-MixCreate-Signature</code> header: <code>sha256=</code> + HMAC-SHA256 of the raw body with the webhook’s secret. Failed deliveries are retried 5 times over about 8 hours.</p>

    <?php foreach ($webhooks as $h): $ev = json_arr($h['events']); ?>
        <details class="lib-item">
            <summary>
                <span class="badge <?= $h['active'] ? 'status-published' : '' ?>"><?= $h['active'] ? 'Active' : 'Paused' ?></span>
                <code class="grow ellipsis"><?= e($h['url']) ?></code>
                <span class="muted small"><?= $ev ? count($ev) . ' events' : 'All events' ?></span>
            </summary>
            <?php if ($isAdmin): ?>
            <form method="post" action="<?= e(url('/developers/webhooks')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                <label class="field"><span>URL</span><input type="url" name="url" value="<?= e($h['url']) ?>" required></label>
                <fieldset class="field">
                    <legend>Events <small class="muted">(none checked = all)</small></legend>
                    <div class="check-grid">
                        <?php foreach ($events as $k => $l): ?>
                            <label class="check"><input type="checkbox" name="events[]" value="<?= e($k) ?>" <?= in_array($k, $ev, true) ? 'checked' : '' ?>> <span><?= e($l) ?> <code class="small"><?= e($k) ?></code></span></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <label class="check"><input type="checkbox" name="active" value="1" <?= $h['active'] ? 'checked' : '' ?>> <span>Active</span></label>
                <div class="form-actions">
                    <button class="btn btn-ghost btn-danger-text" type="submit" form="del-hook-<?= (int) $h['id'] ?>">Delete</button>
                    <span class="grow"></span>
                    <button class="btn" type="submit" form="test-hook-<?= (int) $h['id'] ?>">Send test ping</button>
                    <button class="btn btn-primary" type="submit">Save</button>
                </div>
            </form>
            <form id="del-hook-<?= (int) $h['id'] ?>" method="post" action="<?= e(url('/developers/webhooks/' . $h['id'] . '/delete')) ?>" data-confirm="Delete this webhook?"><?= csrf_field() ?></form>
            <form id="test-hook-<?= (int) $h['id'] ?>" method="post" action="<?= e(url('/developers/webhooks/' . $h['id'] . '/test')) ?>"><?= csrf_field() ?></form>
            <?php endif; ?>
            <?php if ($h['deliveries']): ?>
                <h3>Recent deliveries</h3>
                <div class="table-wrap">
                <table class="table small">
                    <thead><tr><th>Event</th><th>Status</th><th>Attempts</th><th>When</th></tr></thead>
                    <?php foreach ($h['deliveries'] as $d): ?>
                        <tr>
                            <td><code><?= e($d['event']) ?></code></td>
                            <td><?php if ($d['delivered_at']): ?><span class="badge status-published"><?= (int) $d['status_code'] ?></span>
                                <?php elseif ($d['next_attempt_at']): ?><span class="badge status-scheduled">Retrying<?= $d['status_code'] ? ' · ' . (int) $d['status_code'] : '' ?></span>
                                <?php else: ?><span class="badge status-failed">Failed<?= $d['status_code'] ? ' · ' . (int) $d['status_code'] : '' ?></span><?php endif; ?></td>
                            <td><?= (int) $d['attempts'] ?></td>
                            <td class="muted"><?= e(local_time($d['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                </div>
            <?php endif; ?>
        </details>
    <?php endforeach; ?>

    <?php if ($isAdmin): ?>
    <details class="add-block">
        <summary class="btn"><?= icon('plus') ?> Add webhook</summary>
        <form method="post" action="<?= e(url('/developers/webhooks')) ?>">
            <?= csrf_field() ?>
            <label class="field"><span>URL</span><input type="url" name="url" required placeholder="https://example.com/hooks/mixcreate"></label>
            <fieldset class="field">
                <legend>Events <small class="muted">(none checked = all)</small></legend>
                <div class="check-grid">
                    <?php foreach ($events as $k => $l): ?>
                        <label class="check"><input type="checkbox" name="events[]" value="<?= e($k) ?>"> <span><?= e($l) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Add webhook</button></div>
        </form>
    </details>
    <?php elseif (!$webhooks): ?>
        <p class="muted">No webhooks. Workspace admins can add them.</p>
    <?php endif; ?>
</section>
