<?php
declare(strict_types=1);

namespace App;

use App\Providers\Registry;

/** Imports follower counts and post engagement from the platforms. Runs from cron. */
final class Metrics
{
    /** Imports at most once every $everyHours per account. */
    public static function import(int $everyHours = 6, int $postDays = 30): array
    {
        $report = ['accounts' => 0, 'posts' => 0, 'errors' => 0];
        $stamp = BASE_PATH . '/storage/metrics.stamp';
        if (is_file($stamp) && filemtime($stamp) > time() - $everyHours * 3600) {
            return $report;
        }
        touch($stamp);

        $today = gmdate('Y-m-d');
        foreach (Db::all('SELECT * FROM accounts WHERE authorized = 1') as $account) {
            $class = Registry::class($account['provider']);
            if (!$class || !$class::implemented()) {
                continue;
            }
            try {
                $provider = Registry::forAccount($account);
                $m = $provider->accountMetrics();
                if ($m) {
                    $followers = isset($m['followers']) ? (int) $m['followers'] : null;
                    unset($m['followers']);
                    Db::query(
                        'INSERT INTO account_metrics (account_id, date, followers, data) VALUES (?, ?, ?, ?)
                         ON CONFLICT(account_id, date) DO UPDATE SET followers = excluded.followers, data = excluded.data',
                        [$account['id'], $today, $followers, json_encode($m)]
                    );
                    $report['accounts']++;
                }
                $posts = Db::all(
                    "SELECT post_id, provider_post_id FROM post_accounts
                     WHERE account_id = ? AND status = 'published' AND provider_post_id IS NOT NULL AND provider_post_id != '' AND published_at >= ?",
                    [$account['id'], gmdate('Y-m-d H:i:s', time() - $postDays * 86400)]
                );
                foreach ($posts as $p) {
                    $pm = $provider->postMetrics($p['provider_post_id']);
                    if ($pm) {
                        Db::query(
                            'INSERT INTO post_metrics (post_id, account_id, data, updated_at) VALUES (?, ?, ?, ?)
                             ON CONFLICT(post_id, account_id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at',
                            [$p['post_id'], $account['id'], json_encode($pm), now()]
                        );
                        $report['posts']++;
                    }
                }
            } catch (\Throwable $e) {
                $report['errors']++;
                log_event('warning', "Metrics import failed for account {$account['id']}: " . $e->getMessage());
            }
        }
        return $report;
    }

    /** Sums the numeric engagement fields of a post_metrics data blob. */
    public static function engagement(array $data): int
    {
        $sum = 0;
        foreach (['likes', 'replies', 'comments', 'shares', 'reposts', 'quotes', 'saves', 'clicks', 'reactions'] as $k) {
            $sum += (int) ($data[$k] ?? 0);
        }
        return $sum;
    }
}
