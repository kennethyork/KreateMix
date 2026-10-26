<div class="card auth-card empty-state">
    <h1>No workspace yet</h1>
    <p class="muted">You’re not a member of any workspace. Ask an admin to invite you.</p>
    <form method="post" action="<?= e(url('/logout')) ?>">
        <?= csrf_field() ?>
        <button class="btn" type="submit">Sign out</button>
    </form>
</div>
