<form class="card auth-card" method="post" action="<?= e(url('/login')) ?>">
    <?= csrf_field() ?>
    <h1>Sign in</h1>
    <label class="field">
        <span>Email</span>
        <input type="email" name="email" value="<?= e(old('email')) ?>" required autofocus autocomplete="email">
    </label>
    <label class="field">
        <span>Password</span>
        <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="btn btn-primary btn-block" type="submit">Sign in</button>
    <p class="muted small center">New here? Ask a workspace admin for an invite link.</p>
</form>
