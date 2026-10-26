<?php
use App\Autolists;

$sel = $list ? array_map('intval', json_arr($list['accounts'])) : [];
$slots = $list ? json_arr($list['slots']) : [];
$days = array_unique(array_map(fn($s) => (int) $s['day'], $slots));
$times = array_values(array_unique(array_map(fn($s) => $s['time'], $slots)));
if (!$times) {
    $times = ['09:00'];
}
?>
<form method="post" action="<?= e(url($list ? '/autolists/' . $list['id'] : '/autolists')) ?>" class="autolist-form">
    <?= csrf_field() ?>
    <label class="field"><span>Name</span><input type="text" name="name" value="<?= e($list['name'] ?? '') ?>" placeholder="Evergreen tips" required></label>
    <fieldset class="field">
        <legend>Accounts</legend>
        <div class="account-picker">
            <?php foreach ($accounts as $a): ?>
                <label class="account-chip">
                    <input type="checkbox" name="accounts[]" value="<?= (int) $a['id'] ?>" <?= in_array((int) $a['id'], $sel, true) ? 'checked' : '' ?>>
                    <?= account_avatar($a, 'sm') ?><span class="account-chip-name"><?= e($a['name']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <fieldset class="field">
        <legend>Days</legend>
        <div class="day-picker">
            <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $d): ?>
                <label class="tag-chip" style="--tc: var(--accent)"><input type="checkbox" name="days[]" value="<?= $d ?>" <?= in_array($d, $days, true) || (!$list && $d >= 1 && $d <= 5) ? 'checked' : '' ?>><span><?= Autolists::DAYS[$d] ?></span></label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <fieldset class="field">
        <legend>Times <small class="muted">(<?= e(str_replace('_', ' ', $list['timezone'] ?? user_tz())) ?>)</small></legend>
        <div class="times" data-times>
            <?php foreach ($times as $t): ?>
                <span class="time-row"><input type="time" name="times[]" value="<?= e($t) ?>"><button type="button" class="icon-btn" data-remove-time aria-label="Remove time"><?= icon('x') ?></button></span>
            <?php endforeach; ?>
            <button type="button" class="btn btn-sm" data-add-time><?= icon('plus') ?> Time</button>
        </div>
    </fieldset>
    <label class="field"><span>Order</span>
        <select name="order_mode">
            <option value="sequence">In order, then start over</option>
            <option value="random" <?= ($list['order_mode'] ?? '') === 'random' ? 'selected' : '' ?>>Shuffle (every post gets a turn before repeats)</option>
        </select>
    </label>
    <?php if ($list): ?>
        <label class="check"><input type="checkbox" name="active" value="1" <?= $list['active'] ? 'checked' : '' ?>> <span>Active</span></label>
    <?php endif; ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $list ? 'Save' : 'Create autolist' ?></button></div>
</form>
