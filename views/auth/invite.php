<form class="card auth-card" method="post" action="<?= e(url('/invite/' . basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)))) ?>">
    <?= csrf_field() ?>
    <h1>Join <?= e($invite['workspace_name']) ?></h1>
    <p class="muted">You’ve been invited as <strong><?= e(App\Workspace::ROLES[$invite['role']] ?? $invite['role']) ?></strong> with <strong><?= e($invite['email']) ?></strong>.</p>

    <?php if ($current): ?>
        <p>You’re signed in as <?= e($current['email']) ?>.</p>
        <button class="btn btn-primary btn-block" type="submit">Accept invitation</button>
    <?php elseif ($existing): ?>
        <label class="field">
            <span>Password for <?= e($invite['email']) ?></span>
            <input type="password" name="password" required autofocus autocomplete="current-password">
        </label>
        <button class="btn btn-primary btn-block" type="submit">Sign in and join</button>
    <?php else: ?>
        <label class="field">
            <span>Your name</span>
            <input type="text" name="name" value="<?= e(old('name')) ?>" required autofocus autocomplete="name">
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
                    <option value="<?= e($tz) ?>"><?= e(str_replace('_', ' ', $tz)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-primary btn-block" type="submit">Create account and join</button>
    <?php endif; ?>
</form>
