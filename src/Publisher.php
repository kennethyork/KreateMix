<?php
declare(strict_types=1);

namespace App;

use App\Providers\ProviderException;
use App\Providers\Registry;

final class Publisher
{
    /** Publishes every scheduled post that is due. Returns a short report for cron output. */
    public static function runDue(int $limit = 25): array
    {
        // Recover posts stuck in "publishing" (e.g. the process died mid-run).
        Db::query(
            "UPDATE posts SET status = 'failed', updated_at = ? WHERE status = 'publishing' AND updated_at < ?",
            [now(), gmdate('Y-m-d H:i:s', time() - 900)]
        );

        $due = Db::all(
            "SELECT id FROM posts WHERE status = 'scheduled' AND scheduled_at <= ? ORDER BY scheduled_at LIMIT $limit",
            [now()]
        );
        $report = ['published' => 0, 'failed' => 0];
        foreach ($due as $row) {
            $status = self::publish((int) $row['id']);
            if ($status === 'published') {
                $report['published']++;
            } elseif ($status === 'failed') {
                $report['failed']++;
            }
        }
        return $report;
    }

    /**
     * Publishes one post to all its accounts. Only accounts not yet published are attempted,
     * so retrying a partly failed post doesn't double-post.
     * Returns the final post status, or null if another process claimed it first.
     */
    public static function publish(int $postId): ?string
    {
        $claimed = Db::query(
            "UPDATE posts SET status = 'publishing', updated_at = ? WHERE id = ? AND status = 'scheduled'",
            [now(), $postId]
        )->rowCount();
        if ($claimed !== 1) {
            return null;
        }

        $post = Db::one('SELECT * FROM posts WHERE id = ?', [$postId]);
        $media = self::resolveMedia(json_arr($post['media']));
        $targets = Db::all(
            "SELECT pa.*, a.provider, a.provider_id, a.name, a.username, a.token, a.data, a.authorized, a.workspace_id
             FROM post_accounts pa JOIN accounts a ON a.id = pa.account_id
             WHERE pa.post_id = ?",
            [$postId]
        );

        $results = [];
        foreach ($targets as $t) {
            if ($t['status'] === 'published') {
                $results[$t['account_id']] = ['ok' => true, 'skipped' => true];
                continue;
            }
            $results[$t['account_id']] = self::publishToAccount($post, $t, $media);
        }

        $failed = array_filter($results, fn($r) => !$r['ok']);
        $status = !$targets ? 'failed' : ($failed ? 'failed' : 'published');
        Db::update('posts', [
            'status' => $status,
            'published_at' => $status === 'published' ? now() : $post['published_at'],
            'updated_at' => now(),
        ], 'id = ?', [$postId]);

        $post = Db::one('SELECT * FROM posts WHERE id = ?', [$postId]);
        Hooks::fire($status === 'published' ? 'post.published' : 'post.failed', $post, $results);
        Webhooks::dispatch((int) $post['workspace_id'], 'post.' . $status, ['post' => PostService::toArray($post)]);
        $excerpt = str_limit($post['content'] !== '' ? $post['content'] : '(media)', 120);
        if ($status === 'failed') {
            $why = current(array_filter(array_column($results, 'error'))) ?: '';
            Notify::users([$post['user_id'], ...Notify::workspaceAdmins((int) $post['workspace_id'])], 'failed', 'Post failed to publish', $excerpt . ($why ? " — $why" : ''), '/posts/' . $post['id']);
        } else {
            Notify::users([$post['user_id']], 'published', 'Post published', $excerpt, '/posts/' . $post['id']);
        }
        return $status;
    }

    private static function publishToAccount(array $post, array $target, array $media): array
    {
        $accountId = (int) $target['account_id'];
        $text = $target['content'] !== null && $target['content'] !== '' ? $target['content'] : $post['content'];
        $text = Variables::apply((int) $post['workspace_id'], $text);
        try {
            if (!$target['authorized']) {
                throw ProviderException::unauthorized('Account needs to be reconnected.');
            }
            $account = $target;
            $account['id'] = $accountId;
            $provider = Registry::withFreshToken($account);
            $provider->options = json_arr($target['options'] ?? '{}');

            $result = $provider->publish($text, $media);
            Db::update('post_accounts', [
                'status' => 'published',
                'provider_post_id' => (string) ($result['id'] ?? ''),
                'url' => $result['url'] ?? null,
                'error' => null,
                'published_at' => now(),
            ], 'post_id = ? AND account_id = ?', [$post['id'], $accountId]);

            // First comment: best effort, never fails the post.
            $comment = trim((string) ($post['first_comment'] ?? ''));
            if ($comment !== '' && !empty($result['id']) && ($provider->options['type'] ?? 'feed') !== 'story') {
                $status = 'skipped';
                if ($provider::supportsFirstComment()) {
                    try {
                        $provider->firstComment((string) $result['id'], Variables::apply((int) $post['workspace_id'], $comment));
                        $status = 'sent';
                    } catch (\Throwable $e) {
                        $status = 'failed';
                        log_event('warning', "First comment failed for post {$post['id']} account $accountId: " . $e->getMessage());
                    }
                }
                Db::update('post_accounts', ['first_comment_status' => $status], 'post_id = ? AND account_id = ?', [$post['id'], $accountId]);
            }
            return ['ok' => true, 'url' => $result['url'] ?? null];
        } catch (\Throwable $e) {
            if ($e instanceof ProviderException && $e->unauthorized) {
                Db::update('accounts', ['authorized' => 0, 'updated_at' => now()], 'id = ?', [$accountId]);
            }
            $msg = $e instanceof ProviderException || $e instanceof \RuntimeException ? $e->getMessage() : 'Unexpected error while publishing.';
            Db::update('post_accounts', ['status' => 'failed', 'error' => mb_substr($msg, 0, 1000)], 'post_id = ? AND account_id = ?', [$post['id'], $accountId]);
            log_event('error', "Publish failed for post {$post['id']} to account $accountId: " . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return ['ok' => false, 'error' => $msg];
        }
    }

    /** Turns stored media ids into file paths and public URLs for providers. */
    public static function resolveMedia(array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $ids = array_map('intval', $ids);
        $rows = Db::all('SELECT * FROM media WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        $byId = array_column($rows, null, 'id');
        $out = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $m = $byId[$id];
            $out[] = [
                'id' => (int) $m['id'],
                'path' => BASE_PATH . '/public/' . $m['path'],
                'url' => abs_url($m['path']),
                'mime' => $m['mime'],
                'size' => (int) $m['size'],
                'width' => $m['width'] ? (int) $m['width'] : null,
                'height' => $m['height'] ? (int) $m['height'] : null,
                'name' => $m['name'],
            ];
        }
        return $out;
    }
}
