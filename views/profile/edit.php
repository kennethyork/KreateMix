<div class="page-head">
    <h1>Your profile</h1>
</div>
<form class="card narrow" method="post" action="<?= e(url('/profile')) ?>">
    <?= csrf_field() ?>
    <h2>Details</h2>
    <label class="field"><span>Name</span><input type="text" name="name" value="<?= e($user['name']) ?>" required></label>
    <label class="field"><span>Email</span><input type="email" name="email" value="<?= e($user['email']) ?>" required></label>
    <label class="field">
        <span>Timezone</span>
        <select name="timezone">
            <?php foreach ($timezones as $tz): ?>
                <option value="<?= e($tz) ?>" <?= $user['timezone'] === $tz ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $tz)) ?></option>
            <?php endforeach; ?>
        </select>
        <small class="hint">Times you pick in the composer and calendar use this timezone.</small>
    </label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div>
</form>

<form class="card narrow" method="post" action="<?= e(url('/profile/password')) ?>">
    <?= csrf_field() ?>
    <h2>Password</h2>
    <label class="field"><span>Current password</span><input type="password" name="current_password" required autocomplete="current-password"></label>
    <div class="field-row">
        <label class="field"><span>New password</span><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
        <label class="field"><span>Confirm</span><input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Change password</button></div>
</form>
