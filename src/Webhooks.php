<?php
declare(strict_types=1);

namespace App;

/**
 * Outgoing webhooks. Each delivery is a POST with a JSON body and an
 * X-MixCreate-Signature header: "sha256=" . hmac_sha256(body, secret).
 * Failed deliveries are retried by cron with backoff (1m, 5m, 30m, 2h, 6h).
 */
final class Webhooks
{
    public const EVENTS = [
        'post.created' => 'Post created',
        'post.updated' => 'Post updated',
        'post.scheduled' => 'Post scheduled',
        'post.approval_requested' => 'Approval requested',
        'post.approved' => 'Post approved',
        'post.rejected' => 'Post sent back',
        'post.published' => 'Post published',
        'post.failed' => 'Post failed',
        'post.deleted' => 'Post deleted',
        'account.connected' => 'Account connected',
        'account.disconnected' => 'Account disconnected',
    ];

    private const BACKOFF = [60, 300, 1800, 7200, 21600];

    public static function dispatch(int $workspaceId, string $event, array $data): void
    {
        $hooks = Db::all('SELECT * FROM webhooks WHERE workspace_id = ? AND active = 1', [$workspaceId]);
        foreach ($hooks as $hook) {
            $events = json_arr($hook['events']);
            if ($events && !in_array($event, $events, true)) {
                continue;
            }
            $payload = json_encode([
                'event' => $event,
                'workspace_id' => $workspaceId,
                'sent_at' => gmdate('c'),
                'data' => $data,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            Db::insert('webhook_deliveries', [
                'webhook_id' => $hook['id'],
                'event' => $event,
                'payload' => $payload,
                'next_attempt_at' => now(),
                'created_at' => now(),
            ]);
        }
        // Deliver after the response is sent so the user isn't kept waiting.
        if ($hooks && PHP_SAPI !== 'cli') {
            static $registered = false;
            if (!$registered) {
                $registered = true;
                register_shutdown_function(function () {
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    self::deliverPending(20);
                });
            }
        }
    }

    /** Sends due deliveries. Called after requests and from cron. */
    public static function deliverPending(int $limit = 50): int
    {
        $rows = Db::all(
            "SELECT d.*, w.url, w.secret FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id
             WHERE d.delivered_at IS NULL AND d.next_attempt_at IS NOT NULL AND d.next_attempt_at <= ?
             ORDER BY d.id LIMIT $limit",
            [now()]
        );
        $sent = 0;
        foreach ($rows as $d) {
            // Claim it so overlapping runs don't double-send.
            $claimed = Db::query(
                'UPDATE webhook_deliveries SET next_attempt_at = ? WHERE id = ? AND next_attempt_at = ?',
                [gmdate('Y-m-d H:i:s', time() + 120), $d['id'], $d['next_attempt_at']]
            )->rowCount();
            if (!$claimed) {
                continue;
            }
            if (self::send($d)) {
                $sent++;
            }
        }
        return $sent;
    }

    public static function send(array $d): bool
    {
        $attempts = (int) $d['attempts'] + 1;
        $status = null;
        $response = '';
        try {
            if (!self::safeUrl($d['url'])) {
                throw new \RuntimeException('Webhook URL must be a public http(s) address.');
            }
            $res = Http::post($d['url'], [
                'body' => $d['payload'],
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-MixCreate-Event' => $d['event'],
                    'X-MixCreate-Delivery' => (string) $d['id'],
                    'X-MixCreate-Signature' => 'sha256=' . hash_hmac('sha256', $d['payload'], $d['secret']),
                ],
            ]);
            $status = $res['status'];
            $response = mb_substr($res['body'], 0, 1000);
        } catch (\Throwable $e) {
            $response = $e->getMessage();
        }
        $ok = $status !== null && $status >= 200 && $status < 300;
        $next = (!$ok && $attempts <= count(self::BACKOFF)) ? gmdate('Y-m-d H:i:s', time() + self::BACKOFF[$attempts - 1]) : null;
        Db::update('webhook_deliveries', [
            'attempts' => $attempts,
            'status_code' => $status,
            'response' => $response,
            'delivered_at' => $ok ? now() : null,
            'next_attempt_at' => $ok ? null : $next,
        ], 'id = ?', [$d['id']]);
        return $ok;
    }

    /** Blocks webhooks to localhost and private networks unless debug is on. */
    public static function safeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }
        if (Config::get('debug')) {
            return true;
        }
        $ips = gethostbynamel($parts['host']) ?: [];
        if (!$ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }

    public static function prune(): void
    {
        Db::query('DELETE FROM webhook_deliveries WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
    }
}
