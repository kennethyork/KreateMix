<form class="card auth-card" method="post" action="<?= e(url('/setup')) ?>">
    <?= csrf_field() ?>
    <h1>Welcome to MixCreate</h1>
    <p class="muted">Create the admin account for this install. You can invite your team afterwards.</p>
    <label class="field">
        <span>Your name</span>
        <input type="text" name="name" value="<?= e(old('name')) ?>" required autofocus autocomplete="name">
    </label>
    <label class="field">
        <span>Email</span>
        <input type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email">
    </label>
    <div class="field-row">
        <label class="field">
            <span>Password</span>
            <input type="password" name="password" required minlength="8" autocomplete="new-password">
        </label>
        <label class="field">
            <span>Confirm</span>
            <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
        </label>
    </div>
    <label class="field">
        <span>Timezone</span>
        <select name="timezone" data-detect-timezone>
            <?php foreach ($timezones as $tz): ?>
                <option value="<?= e($tz) ?>" <?= old('timezone', 'UTC') === $tz ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $tz)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>First workspace</span>
        <input type="text" name="workspace" value="<?= e(old('workspace')) ?>" placeholder="e.g. Acme Inc." required>
        <small class="hint">A workspace holds its own accounts, posts and team. Make one per brand or client.</small>
    </label>
    <button class="btn btn-primary btn-block" type="submit">Create account</button>
</form>
