<?php
use App\Auth;
use App\Workspace;

$isAdmin = Workspace::can('admin');
unset($_SESSION['_invite_link']);
?>
<div class="page-head">
    <h1>Team</h1>
</div>

<?php if ($inviteLink): ?>
    <div class="notice notice-success">
        <?= icon('check') ?>
        <div class="grow">
            <strong>Invitation created for <?= e($inviteLink['email']) ?>.</strong> Send them this link (valid for 7 days):
            <code class="copy" data-copy><?= e($inviteLink['url']) ?></code>
        </div>
    </div>
<?php endif; ?>

<section class="card">
    <h2>Members</h2>
    <div class="account-list">
        <?php foreach ($members as $m): ?>
            <div class="account-row">
                <span class="avatar"><?= e(initials($m['name'])) ?></span>
                <div class="grow">
                    <strong><?= e($m['name']) ?><?= (int) $m['id'] === Auth::id() ? ' <span class="muted small">(you)</span>' : '' ?></strong>
                    <div class="muted small"><?= e($m['email']) ?></div>
                </div>
                <?php if ($isAdmin): ?>
                    <form method="post" action="<?= e(url('/members/' . $m['id'] . '/role')) ?>">
                        <?= csrf_field() ?>
                        <select name="role" data-autosubmit aria-label="Role for <?= e($m['name']) ?>">
                            <?php foreach (Workspace::ROLES as $k => $l): ?>
                                <option value="<?= e($k) ?>" <?= $m['role'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <form method="post" action="<?= e(url('/members/' . $m['id'] . '/remove')) ?>" data-confirm="Remove <?= e($m['name']) ?> from this workspace?">
                        <?= csrf_field() ?>
                        <button class="icon-btn" type="submit" aria-label="Remove"><?= icon('trash') ?></button>
                    </form>
                <?php else: ?>
                    <span class="badge"><?= e(Workspace::ROLES[$m['role']] ?? $m['role']) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($isAdmin): ?>
<section class="card">
    <h2>Invite someone</h2>
    <form class="field-row align-end" method="post" action="<?= e(url('/members/invite')) ?>">
        <?= csrf_field() ?>
        <label class="field grow">
            <span>Email</span>
            <input type="email" name="email" required placeholder="name@example.com">
        </label>
        <label class="field">
            <span>Role</span>
            <select name="role">
                <option value="member">Member</option>
                <option value="admin">Admin</option>
                <option value="viewer">Viewer</option>
            </select>
        </label>
        <button class="btn btn-primary" type="submit">Create invite link</button>
    </form>
    <dl class="role-help">
        <div><dt>Admin</dt><dd>Manages accounts, team, settings and approvals.</dd></div>
        <div><dt>Member</dt><dd>Creates and schedules posts (needs approval if that’s turned on).</dd></div>
        <div><dt>Viewer</dt><dd>Sees the calendar, posts and analytics, but can’t change anything.</dd></div>
    </dl>

    <?php if ($invitations): ?>
        <h3>Pending invitations</h3>
        <div class="account-list">
            <?php foreach ($invitations as $i): ?>
                <div class="account-row">
                    <span class="avatar"><?= icon('mail') ?></span>
                    <div class="grow"><strong><?= e($i['email']) ?></strong><div class="muted small"><?= e(Workspace::ROLES[$i['role']] ?? $i['role']) ?> · sent <?= e(local_time($i['created_at'], 'M j')) ?></div></div>
                    <form method="post" action="<?= e(url('/invitations/' . $i['id'] . '/revoke')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm" type="submit">Revoke</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="muted small">Links are shown only once. To resend, revoke and invite again.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
