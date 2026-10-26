<div class="page-head">
    <div>
        <h1>Integrations</h1>
        <p class="muted">Instance-wide settings. Secrets are stored encrypted and never shown again; leave a secret blank to keep it.</p>
    </div>
</div>

<div class="service-list">
<?php foreach ($groups as $key => $g): $v = $values[$key]; ?>
    <section class="card service" id="<?= e($key) ?>">
        <div class="card-head">
            <h2><?= e($g['title']) ?></h2>
        </div>
        <p class="muted small"><?= e($g['hint']) ?></p>
        <?php if (!empty($g['callback'])): ?>
            <label class="field"><span>Redirect URL</span><code class="copy" data-copy><?= e(abs_url($g['callback'])) ?></code></label>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/admin/integrations/' . $key)) ?>">
            <?= csrf_field() ?>
            <div class="field-row">
            <?php foreach ($g['fields'] as $f => [$label, $type, $secret, $placeholder]): ?>
                <?php if (str_starts_with($type, 'select:')): ?>
                    <label class="field"><span><?= e($label) ?></span>
                        <select name="<?= e($f) ?>">
                            <?php foreach (explode(',', substr($type, 7)) as $opt): [$ov, $ol] = explode('=', $opt, 2); ?>
                                <option value="<?= e($ov) ?>" <?= ($v[$f] ?? '') === $ov ? 'selected' : '' ?>><?= e($ol) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php elseif ($type === 'model'): ?>
                    <label class="field"><span><?= e($label) ?></span>
                        <input type="text" name="<?= e($f) ?>" value="<?= e($v[$f] ?? '') ?>" placeholder="<?= e($placeholder) ?>" list="openrouter-models" autocomplete="off">
                        <small class="hint">For OpenRouter, start typing to search <?= count($models) ?: 'its' ?> models, e.g. anthropic/…, openai/…, google/…; <code>openrouter/auto</code> picks one for you.</small>
                    </label>
                <?php elseif ($type === 'textarea'): ?>
                    <label class="field" style="flex-basis: 100%"><span><?= e($label) ?></span><textarea name="<?= e($f) ?>" rows="2" placeholder="<?= e($placeholder) ?>"><?= e($v[$f] ?? '') ?></textarea></label>
                <?php else: ?>
                    <label class="field"><span><?= e($label) ?></span>
                        <input type="<?= e($type) ?>" name="<?= e($f) ?>" value="<?= $secret ? '' : e($v[$f] ?? '') ?>" placeholder="<?= $secret && !empty($v[$f]) ? '•••••••• (saved)' : e($placeholder) ?>" autocomplete="off">
                    </label>
                <?php endif; ?>
            <?php endforeach; ?>
            </div>
            <div class="form-actions">
                <?php if ($key === 'smtp'): ?><button class="btn" type="submit" form="test-smtp">Send test email</button><?php endif; ?>
                <?php if ($key === 'ai'): ?><button class="btn" type="submit" form="test-ai">Test</button><?php endif; ?>
                <button class="btn btn-primary" type="submit">Save</button>
            </div>
        </form>
    </section>
<?php endforeach; ?>
</div>
<form id="test-smtp" method="post" action="<?= e(url('/admin/integrations/smtp/test')) ?>"><?= csrf_field() ?></form>
<form id="test-ai" method="post" action="<?= e(url('/admin/integrations/ai/test')) ?>"><?= csrf_field() ?></form>

<datalist id="openrouter-models">
    <option value="openrouter/auto">Auto (OpenRouter picks)</option>
    <?php foreach ($models as $m): ?>
        <option value="<?= e($m['id']) ?>"><?= e($m['name'] . ($m['prompt_price'] !== null ? sprintf(' · $%.2f / $%.2f per 1M tokens', $m['prompt_price'], $m['completion_price']) : '')) ?></option>
    <?php endforeach; ?>
</datalist>
