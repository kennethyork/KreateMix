<?php
declare(strict_types=1);

namespace App;

use App\Providers\ProviderException;
use App\Providers\Registry;

/** Pulls comments on recently published posts into the inbox, and sends replies. */
final class Inbox
{
    /** Checks posts from the last $days days, at most every $minutes minutes. */
    public static function fetchDue(int $minutes = 15, int $days = 14): int
    {
        $stamp = BASE_PATH . '/storage/inbox.stamp';
        if (is_file($stamp) && filemtime($stamp) > time() - $minutes * 60) {
            return 0;
        }
        touch($stamp);
        $new = 0;
        $targets = Db::all(
            "SELECT pa.post_id, pa.provider_post_id, a.*, p.workspace_id AS ws
             FROM post_accounts pa JOIN accounts a ON a.id = pa.account_id JOIN posts p ON p.id = pa.post_id
             WHERE pa.status = 'published' AND pa.provider_post_id IS NOT NULL AND pa.provider_post_id != '' AND a.authorized = 1 AND pa.published_at >= ?
             ORDER BY a.id",
            [gmdate('Y-m-d H:i:s', time() - $days * 86400)]
        );
        $providers = [];
        foreach ($targets as $t) {
            $class = Registry::class($t['provider']);
            if (!$class || !$class::supportsComments()) {
                continue;
            }
            try {
                $providers[$t['id']] ??= Registry::withFreshToken($t);
                $new += self::store($t, $providers[$t['id']]->comments($t['provider_post_id']));
            } catch (\Throwable $e) {
                if ($e instanceof ProviderException && $e->unauthorized) {
                    Db::update('accounts', ['authorized' => 0, 'updated_at' => now()], 'id = ?', [$t['id']]);
                }
                log_event('warning', "Inbox fetch failed for account {$t['id']}: " . $e->getMessage());
            }
        }
        return $new;
    }

    private static function store(array $t, array $comments): int
    {
        $n = 0;
        foreach ($comments as $c) {
            if (!empty($c['own'])) {
                continue;
            }
            $inserted = Db::query(
                'INSERT OR IGNORE INTO inbox_items (workspace_id, account_id, post_id, provider_item_id, root_ref, reply_ref, author_name, author_handle, author_avatar, text, url, remote_created_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$t['ws'], $t['id'], $t['post_id'], (string) $c['id'], $t['provider_post_id'], (string) ($c['reply_ref'] ?? ''), mb_substr((string) $c['author_name'], 0, 200),
                 mb_substr((string) $c['author_handle'], 0, 200), $c['author_avatar'] ?? null, mb_substr((string) $c['text'], 0, 5000), $c['url'] ?? null, $c['created_at'] ?? now(), now()]
            )->rowCount();
            $n += $inserted;
        }
        return $n;
    }

    public static function reply(array $item, string $text, int $userId): void
    {
        $account = Db::one('SELECT * FROM accounts WHERE id = ?', [$item['account_id']]);
        if (!$account) {
            throw new ProviderException('That account was removed.');
        }
        $provider = Registry::withFreshToken($account);
        $provider->reply($item, $text);
        Db::update('inbox_items', ['status' => 'replied', 'reply_text' => $text, 'replied_by' => $userId, 'replied_at' => now()], 'id = ?', [$item['id']]);
    }
}
