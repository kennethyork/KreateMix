<?php
$fmt = fn($n) => $n === null ? '—' : number_format((int) $n);
$cell = 'padding:12px 14px;background:#f4f5f8;border-radius:8px;';
?>
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#15181e;max-width:560px;margin:0 auto;">
    <h1 style="font-size:20px;margin:0 0 4px;"><?= e($workspace) ?></h1>
    <p style="color:#6b7280;margin:0 0 20px;"><?= e($name) ?> · <?= e($scope) ?> · <?= e(date('M j', strtotime($a['from'])) . ' – ' . date('M j, Y', strtotime($a['to']))) ?></p>
    <table role="presentation" cellspacing="8" cellpadding="0" style="width:100%;margin:0 -8px;">
        <tr>
            <td style="<?= $cell ?>"><div style="color:#6b7280;font-size:12px;">Followers</div><div style="font-size:22px;font-weight:700;"><?= $fmt($a['followersNow']) ?></div>
                <?php if ($a['followersChange'] !== null): ?><div style="font-size:12px;color:<?= $a['followersChange'] >= 0 ? '#15803d' : '#dc2626' ?>;"><?= $a['followersChange'] >= 0 ? '+' : '' ?><?= number_format($a['followersChange']) ?></div><?php endif; ?></td>
            <td style="<?= $cell ?>"><div style="color:#6b7280;font-size:12px;">Posts</div><div style="font-size:22px;font-weight:700;"><?= $a['totals']['posts'] ?></div></td>
            <td style="<?= $cell ?>"><div style="color:#6b7280;font-size:12px;">Engagement</div><div style="font-size:22px;font-weight:700;"><?= $fmt($a['totals']['engagement']) ?></div></td>
        </tr>
    </table>
    <?php if ($a['top']): $t = $a['top'][0]; ?>
        <h2 style="font-size:15px;margin:24px 0 6px;">Top post</h2>
        <p style="margin:0;padding:12px 14px;border:1px solid #e5e7eb;border-radius:8px;"><?= e(str_limit($t['content'] ?: '(media)', 200)) ?><br><span style="color:#6b7280;font-size:12px;"><?= e(App\Analytics::providerName($t['provider'])) ?> · <?= number_format($t['engagement']) ?> engagements</span></p>
    <?php endif; ?>
    <p style="color:#6b7280;font-size:13px;margin-top:24px;">The full report is attached as a PDF.</p>
</div>
