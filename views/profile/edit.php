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

<?php $prefs = json_arr($user['notify'] ?? '{}'); ?>
<section class="card narrow" id="notifications">
    <h2><?= icon('bell') ?> Notifications</h2>
    <p class="muted small">Get push notifications on this phone or computer. On iPhone, first add MixCreate to your Home Screen (Share → Add to Home Screen), then open it from there. Needs HTTPS.</p>
    <div class="push-status" data-push>
        <span class="muted small" data-push-state>Checking…</span>
        <button class="btn btn-primary btn-sm" type="button" data-push-enable hidden>Turn on for this device</button>
        <button class="btn btn-sm" type="button" data-push-disable hidden>Turn off for this device</button>
    </div>
    <form method="post" action="<?= e(url('/push/preferences')) ?>">
        <?= csrf_field() ?>
        <?php foreach (App\Notify::EVENTS as $k => $label): ?>
            <label class="check"><input type="checkbox" name="<?= e($k) ?>" value="1" <?= !empty($prefs[$k]) ? 'checked' : '' ?>> <span><?= e($label) ?></span></label>
        <?php endforeach; ?>
        <div class="form-actions">
            <button class="btn" type="submit" form="push-test">Send a test</button>
            <button class="btn btn-primary" type="submit">Save</button>
        </div>
    </form>
    <form id="push-test" method="post" action="<?= e(url('/push/test')) ?>"><?= csrf_field() ?></form>
</section>

<section class="card narrow">
    <h2>Install the app</h2>
    <p class="muted small">MixCreate works as an app on your phone and computer. In Chrome or Edge use the install icon in the address bar; on iPhone use Share → Add to Home Screen; on Android use the browser menu → Install app.</p>
    <button class="btn btn-sm" type="button" data-install hidden><?= icon('download') ?> Install</button>
</section>
