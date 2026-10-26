<?php
use App\Analytics;

$max = 0;
foreach ($best['grid'] as $row) {
    foreach ($row as $v) {
        $max = max($max, (float) $v);
    }
}
?>
<section class="card">
    <h2>Best time to post</h2>
    <?php if ($best['top']): ?>
        <p class="muted small">Based on engagement of your last <?= (int) $best['posts'] ?> posts over 6 months, in your timezone (<?= e(str_replace('_', ' ', user_tz())) ?>).</p>
        <div class="best-times">
            <?php foreach ($best['top'] as $i => $c): ?>
                <div class="best-slot">
                    <span class="best-rank"><?= $i + 1 ?></span>
                    <div><strong><?= e(Analytics::dayName($c['day'])) ?>, <?= e(Analytics::hourLabel($c['hour'])) ?></strong>
                        <div class="muted small"><?= $c['avg'] ?> avg engagement · <?= $c['posts'] ?> post<?= $c['posts'] === 1 ? '' : 's' ?></div></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="muted">Not enough data yet: this needs at least 8 published posts with engagement numbers (you have <?= (int) $best['posts'] ?>). Keep posting at different times and it will fill in.</p>
    <?php endif; ?>

    <div class="heatmap-wrap">
        <table class="heatmap" aria-label="Average engagement by day and hour">
            <thead><tr><th></th><?php for ($h = 0; $h < 24; $h++): ?><th><?= $h % 3 === 0 ? e(str_replace([' AM', ' PM'], ['a', 'p'], Analytics::hourLabel($h))) : '' ?></th><?php endfor; ?></tr></thead>
            <tbody>
            <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $d): ?>
                <tr>
                    <th><?= e(substr(Analytics::dayName($d), 0, 3)) ?></th>
                    <?php for ($h = 0; $h < 24; $h++):
                        $v = $best['grid'][$d][$h];
                        $alpha = $v === null || $max == 0 ? 0 : 0.15 + 0.85 * ($v / $max);
                    ?>
                        <td style="--a: <?= round($alpha, 2) ?>" class="<?= $v === null ? 'empty' : '' ?>" title="<?= e(Analytics::dayName($d) . ' ' . Analytics::hourLabel($h) . ($v === null ? ': no posts' : ': ' . round($v, 1) . ' avg engagement')) ?>"></td>
                    <?php endfor; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
