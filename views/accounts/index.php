<?php
use App\Auth;
use App\Workspace;

$isAdmin = Workspace::can('admin');
?>
<div class="page-head">
    <h1>Accounts</h1>
</div>

<section class="card">
    <h2>Connected</h2>
    <?php if (!$accounts): ?>
        <p class="muted">No accounts yet<?= $isAdmin ? '. Pick a platform below.' : '. Ask a workspace admin to connect some.' ?></p>
    <?php else: ?>
    <div class="account-list">
        <?php foreach ($accounts as $a): ?>
            <div class="account-row">
                <?= account_avatar($a) ?>
                <div class="grow">
                    <strong><?= e($a['name']) ?></strong>
                    <div class="muted small"><?= e(provider_meta($a['provider'])['name']) ?><?= $a['username'] ? ' · @' . e($a['username']) : '' ?></div>
                </div>
                <?php if (!$a['authorized']): ?>
                    <span class="badge status-failed">Needs reconnect</span>
                    <?php if ($isAdmin && ($providers[$a['provider']]['ready'] ?? false)): ?>
                        <a class="btn btn-sm" href="<?= e(url('/accounts/connect/' . $a['provider'])) ?>">Reconnect</a>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <form method="post" action="<?= e(url('/accounts/' . $a['id'] . '/delete')) ?>" data-confirm="Remove <?= e($a['name']) ?>? Scheduled posts will skip it.">
                        <?= csrf_field() ?>
                        <button class="icon-btn" type="submit" aria-label="Remove"><?= icon('trash') ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<?php if ($isAdmin): ?>
<section class="card">
    <h2>Add an account</h2>
    <div class="provider-grid">
        <?php foreach ($providers as $key => $p): ?>
            <?php if ($p['ready']): ?>
                <a class="provider-tile" href="<?= e(url('/accounts/connect/' . $key)) ?>">
                    <?= provider_badge($key, 'pmark') ?>
                    <span><?= e($p['name']) ?></span>
                </a>
            <?php elseif ($p['implemented']): ?>
                <a class="provider-tile is-disabled" href="<?= e(url(Auth::isAdmin() ? '/admin/services#' . $key : '/accounts')) ?>">
                    <?= provider_badge($key, 'pmark') ?>
                    <span><?= e($p['name']) ?></span>
                    <small><?= Auth::isAdmin() ? 'Add API keys' : 'Needs API keys' ?></small>
                </a>
            <?php else: ?>
                <div class="provider-tile is-disabled">
                    <?= provider_badge($key, 'pmark') ?>
                    <span><?= e($p['name']) ?></span>
                    <small>Coming soon</small>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
