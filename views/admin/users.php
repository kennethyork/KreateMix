<?php use App\Auth; ?>
<div class="page-head">
    <h1>Users &amp; workspaces</h1>
    <a class="btn" href="<?= e(url('/workspaces/new')) ?>"><?= icon('plus') ?> New workspace</a>
</div>

<section class="card">
    <h2>Workspaces</h2>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Name</th><th>Members</th><th>Accounts</th><th>Created</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($workspaces as $w): ?>
            <tr>
                <td><span class="ws-dot" style="background: <?= e($w['color']) ?>"></span> <?= e($w['name']) ?></td>
                <td><?= (int) $w['members'] ?></td>
                <td><?= (int) $w['accounts'] ?></td>
                <td class="muted"><?= e(local_time($w['created_at'], 'M j, Y')) ?></td>
                <td class="right">
                    <form method="post" action="<?= e(url('/workspaces/' . $w['id'] . '/switch')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm" type="submit">Open</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted small">You can open a workspace only if you’re a member. Invite yourself from its Team page, or ask one of its admins.</p>
</section>

<section class="card">
    <h2>Users</h2>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Name</th><th>Email</th><th>Workspaces</th><th>Instance admin</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['name']) ?></td>
                <td class="muted"><?= e($u['email']) ?></td>
                <td class="muted small"><?= e($u['workspaces'] ?? '—') ?></td>
                <td>
                    <?php if ((int) $u['id'] === Auth::id()): ?>
                        <span class="badge status-published">You</span>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/admin')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm" type="submit"><?= $u['is_admin'] ? 'Remove admin' : 'Make admin' ?></button>
                        </form>
                    <?php endif; ?>
                </td>
                <td class="right">
                    <?php if ((int) $u['id'] !== Auth::id()): ?>
                        <form method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/delete')) ?>" data-confirm="Delete <?= e($u['name']) ?>’s account?">
                            <?= csrf_field() ?>
                            <button class="icon-btn" type="submit" aria-label="Delete user"><?= icon('trash') ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
