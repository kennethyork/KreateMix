<div class="page-head">
    <div>
        <h1>Platform API keys</h1>
        <p class="muted">Keys are shared by every workspace on this install and stored encrypted. Secrets are never shown again after saving.</p>
    </div>
</div>

<div class="service-list">
<?php foreach ($services as $key => $s): ?>
    <section class="card service" id="<?= e($key) ?>">
        <div class="card-head">
            <h2><?= provider_badge($key, 'pmark sm') ?> <?= e($s['name']) ?></h2>
            <?php if (!$s['implemented']): ?>
                <span class="badge">Coming soon</span>
            <?php elseif ($s['ready']): ?>
                <span class="badge status-published">Ready</span>
            <?php else: ?>
                <span class="badge status-pending_approval">Needs keys</span>
            <?php endif; ?>
        </div>
        <?php if ($s['hint']): ?><p class="muted small"><?= e($s['hint']) ?></p><?php endif; ?>
        <?php if ($s['fields']): ?>
            <?php if ($s['oauth']): ?>
                <label class="field">
                    <span>Callback / redirect URL</span>
                    <code class="copy" data-copy><?= e($s['callback']) ?></code>
                </label>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/admin/services/' . $key)) ?>">
                <?= csrf_field() ?>
                <div class="field-row">
                    <?php foreach ($s['fields'] as $f => $field): ?>
                        <label class="field">
                            <span><?= e($field['label']) ?></span>
                            <input type="<?= $field['secret'] ? 'password' : 'text' ?>" name="<?= e($f) ?>" value="<?= e($field['value']) ?>"
                                   placeholder="<?= $field['secret'] && $field['set'] ? '•••••••• (saved — leave blank to keep)' : '' ?>" autocomplete="off">
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div>
            </form>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
</div>
