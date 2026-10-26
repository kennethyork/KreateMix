<?php use App\Workspace; ?>
<div class="page-head">
    <div>
        <h1>Reports</h1>
        <p class="muted"><?= e($scope) ?> · <?= e(date('M j', strtotime($a['from'])) . ' – ' . date('M j, Y', strtotime($a['to']))) ?></p>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e(url('/reports/pptx', $params)) ?>"><?= icon('download') ?> PowerPoint</a>
        <a class="btn btn-primary" href="<?= e(url('/reports/pdf', $params)) ?>"><?= icon('download') ?> Download PDF</a>
    </div>
</div>

<?php partial('partials/analytics_filters', ['accounts' => $accounts, 'accountId' => $accountId, 'days' => $days, 'a' => $a, 'base' => '/reports', 'extra' => []]); ?>

<?php if ($newShare): ?>
    <div class="notice notice-success"><?= icon('link') ?><div class="grow"><strong>Share link created.</strong> Anyone with it can view this report without signing in.<code class="copy" data-copy><?= e($newShare) ?></code></div></div>
<?php endif; ?>

<div class="grid-2 report-tools">
    <section class="card">
        <h2>Share with a client</h2>
        <p class="muted small">A read-only link to this report. <?= $days ? 'It always shows the last ' . (int) $days . ' days.' : 'It shows this exact date range.' ?></p>
        <form class="field-row align-end" method="post" action="<?= e(url('/reports/share', $params)) ?>">
            <?= csrf_field() ?>
            <label class="field"><span>Expires</span>
                <select name="expires"><option value="0">Never</option><option value="7">In 7 days</option><option value="30">In 30 days</option><option value="90">In 90 days</option></select>
            </label>
            <button class="btn" type="submit"><?= icon('link') ?> Create link</button>
        </form>
        <?php if ($shares): ?>
            <div class="account-list">
                <?php foreach ($shares as $s): $sp = json_arr($s['params']); ?>
                    <div class="account-row">
                        <div class="grow small"><strong><?= !empty($sp['days']) ? 'Last ' . (int) $sp['days'] . ' days' : e(($sp['from'] ?? '') . ' – ' . ($sp['to'] ?? '')) ?></strong>
                            <div class="muted">Created <?= e(local_time($s['created_at'], 'M j')) ?><?= $s['expires_at'] ? ' · expires ' . e(local_time($s['expires_at'], 'M j')) : '' ?></div></div>
                        <form method="post" action="<?= e(url('/reports/shares/' . $s['id'] . '/revoke')) ?>" data-confirm="Revoke this link? Anyone using it loses access.">
                            <?= csrf_field() ?><button class="btn btn-sm" type="submit">Revoke</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Email it on a schedule</h2>
        <?php if (!$mailReady): ?>
            <p class="muted small">Email isn’t set up yet. <?= App\Auth::isAdmin() ? '<a href="' . e(url('/admin/integrations#smtp')) . '">Add your mail server</a>.' : 'Ask your MixCreate admin to add a mail server.' ?></p>
        <?php endif; ?>
        <?php foreach ($schedules as $s): ?>
            <div class="account-row">
                <span class="avatar sm"><?= icon('mail') ?></span>
                <div class="grow small"><strong><?= e($s['name']) ?></strong>
                    <div class="muted"><?= $s['frequency'] === 'monthly' ? 'Monthly' : 'Weekly' ?> · <?= e(str_replace(',', ', ', $s['recipients'])) ?><?= $s['last_sent_at'] ? ' · last sent ' . e(local_time($s['last_sent_at'], 'M j')) : '' ?></div></div>
                <?php if (Workspace::can('member')): ?>
                <form method="post" action="<?= e(url('/reports/schedules/' . $s['id'] . '/send')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit">Send now</button></form>
                <form method="post" action="<?= e(url('/reports/schedules/' . $s['id'] . '/delete')) ?>" data-confirm="Delete this schedule?"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Delete"><?= icon('trash') ?></button></form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (Workspace::can('member')): ?>
        <details class="add-block" <?= $schedules ? '' : 'open' ?>>
            <summary class="btn btn-sm"><?= icon('plus') ?> New schedule</summary>
            <form method="post" action="<?= e(url('/reports/schedules')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="account" value="<?= (int) $accountId ?>">
                <label class="field"><span>Name</span><input type="text" name="name" placeholder="Weekly social report"></label>
                <label class="field"><span>Send to</span><input type="text" name="recipients" placeholder="client@example.com, boss@example.com" required></label>
                <label class="field"><span>How often</span>
                    <select name="frequency"><option value="weekly">Weekly, Monday morning (last 7 days)</option><option value="monthly">Monthly, on the 1st (last month)</option></select>
                </label>
                <p class="muted small">Covers <?= e($scope) ?> with a PDF attached.</p>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Schedule</button></div>
            </form>
        </details>
        <?php endif; ?>
    </section>
</div>

<h2 class="section-title">Preview</h2>
<?php partial('reports/report', ['a' => $a]); ?>
