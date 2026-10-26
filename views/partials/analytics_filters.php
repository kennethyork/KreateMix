<?php
/** @var array $accounts @var int $accountId @var ?int $days @var array $a @var string $base @var array $extra */
$extra = $extra ?? [];
$qs = fn(array $over) => url($base, array_filter(array_merge(['account' => $accountId ?: null, 'days' => $days, 'from' => $days ? null : $a['from'], 'to' => $days ? null : $a['to']], $extra, $over), fn($v) => $v !== null && $v !== '' && $v !== 0));
?>
<form class="analytics-filters" method="get" action="<?= e(url($base)) ?>">
    <?php foreach ($extra as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <select name="account" data-autosubmit aria-label="Account">
        <option value="">All accounts</option>
        <?php foreach ($accounts as $acc): ?>
            <option value="<?= (int) $acc['id'] ?>" <?= $accountId === (int) $acc['id'] ? 'selected' : '' ?>><?= e(provider_meta($acc['provider'])['name'] . ' · ' . $acc['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <div class="segmented" role="group" aria-label="Range">
        <?php foreach ([7, 30, 90, 365] as $d): ?>
            <a href="<?= e($qs(['days' => $d, 'from' => null, 'to' => null])) ?>" class="<?= $days === $d ? 'active' : '' ?>"><?= $d === 365 ? '1y' : $d . 'd' ?></a>
        <?php endforeach; ?>
    </div>
    <details class="dropdown range-custom">
        <summary class="btn btn-sm"><?= icon('calendar') ?> <?= $days ? 'Custom' : e(date('M j', strtotime($a['from'])) . ' – ' . date('M j, Y', strtotime($a['to']))) ?></summary>
        <div class="dropdown-menu range-menu">
            <label class="field"><span>From</span><input type="date" name="from" value="<?= e($a['from']) ?>"></label>
            <label class="field"><span>To</span><input type="date" name="to" value="<?= e($a['to']) ?>"></label>
            <button class="btn btn-primary btn-sm" type="submit">Apply</button>
        </div>
    </details>
</form>
