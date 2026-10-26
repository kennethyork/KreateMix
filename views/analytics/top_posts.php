<section class="card">
    <h2>Top posts</h2>
    <?php if (!$top): ?>
        <p class="muted">No engagement data yet for this period.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Post</th><th>Account</th><th class="num">Likes</th><th class="num">Comments</th><th class="num">Shares</th><th class="num">Total</th></tr></thead>
        <?php foreach ($top as $p): $m = $p['m']; ?>
            <tr>
                <td><a href="<?= e(url('/posts/' . $p['id'])) ?>"><?= e(str_limit($p['content'] ?: '(media)', 70)) ?></a>
                    <div class="muted small"><?= e(local_time($p['published_at'], 'M j')) ?><?php if ($p['url']): ?> · <a href="<?= e($p['url']) ?>" target="_blank" rel="noopener">View</a><?php endif; ?></div></td>
                <td><?= provider_badge($p['provider'], 'pdot') ?> <?= e($p['account_name']) ?></td>
                <td class="num"><?= number_format((int) ($m['likes'] ?? $m['reactions'] ?? 0)) ?></td>
                <td class="num"><?= number_format((int) ($m['replies'] ?? $m['comments'] ?? 0)) ?></td>
                <td class="num"><?= number_format((int) ($m['shares'] ?? $m['reposts'] ?? 0)) ?></td>
                <td class="num"><strong><?= number_format($p['engagement']) ?></strong></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</section>
