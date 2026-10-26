<?php
declare(strict_types=1);

namespace App;

use App\Providers\Registry;

/** Creating, validating and scheduling posts. Shared by the web UI and the REST API. */
final class PostService
{
    /**
     * @param array $in content, accounts (ids), media (ids), tags (ids), overrides ([account_id => text]), scheduled_at (UTC "Y-m-d H:i:s" or null)
     * @param string $action draft | schedule | now
     * @return array{post: ?array, errors: string[]}
     */
    public static function save(?int $postId, array $in, string $action, int $workspaceId, int $userId, bool $isAdmin): array
    {
        $existing = $postId ? self::find($postId, $workspaceId) : null;
        if ($postId && !$existing) {
            return ['post' => null, 'errors' => ['Post not found.']];
        }
        if ($existing && in_array($existing['status'], ['publishing', 'published'], true)) {
            return ['post' => null, 'errors' => ['Published posts can’t be edited. Duplicate it instead.']];
        }

        $content = str_replace("\r\n", "\n", (string) ($in['content'] ?? ''));
        $accountIds = self::ownedIds('accounts', $in['accounts'] ?? [], $workspaceId);
        $mediaIds = self::ownedIds('media', $in['media'] ?? [], $workspaceId, keepOrder: true);
        $tagIds = self::ownedIds('tags', $in['tags'] ?? [], $workspaceId);
        $overrides = [];
        foreach ((array) ($in['overrides'] ?? []) as $aid => $text) {
            $text = str_replace("\r\n", "\n", trim((string) $text));
            if ($text !== '' && in_array((int) $aid, $accountIds, true)) {
                $overrides[(int) $aid] = $text;
            }
        }

        $types = [];
        foreach ((array) ($in['types'] ?? []) as $aid => $type) {
            if (in_array((int) $aid, $accountIds, true) && is_string($type) && $type !== 'feed') {
                $types[(int) $aid] = $type;
            }
        }
        $firstComment = mb_substr(trim(str_replace("\r\n", "\n", (string) ($in['first_comment'] ?? ''))), 0, 2000);

        $scheduledAt = $in['scheduled_at'] ?? null;
        if ($action === 'now') {
            $scheduledAt = now();
        }

        $errors = self::validate($content, $accountIds, $mediaIds, $overrides, $scheduledAt, $action, $workspaceId, $types);
        if ($errors) {
            return ['post' => null, 'errors' => $errors];
        }

        $ws = Db::one('SELECT * FROM workspaces WHERE id = ?', [$workspaceId]);
        $status = 'draft';
        if ($action !== 'draft') {
            $status = ($ws['require_approval'] && !$isAdmin) ? 'pending_approval' : 'scheduled';
        }
        $status = Hooks::filter('post.status', $status, $existing ?? [], $action);

        $id = Db::transaction(function () use ($existing, $workspaceId, $userId, $content, $mediaIds, $status, $scheduledAt, $accountIds, $overrides, $tagIds, $types, $firstComment, $in) {
            $row = [
                'content' => $content,
                'first_comment' => $firstComment !== '' ? $firstComment : null,
                'media' => json_encode($mediaIds),
                'status' => $status,
                'scheduled_at' => $scheduledAt,
                'review_note' => null,
                'updated_at' => now(),
            ];
            if ($existing) {
                $id = (int) $existing['id'];
                Db::update('posts', $row, 'id = ?', [$id]);
            } else {
                $id = Db::insert('posts', $row + ['workspace_id' => $workspaceId, 'user_id' => $userId ?: null, 'autolist_id' => $in['autolist_id'] ?? null, 'created_at' => now()]);
            }
            // Keep publish results for accounts that already succeeded (retry after partial failure).
            $keep = Db::all("SELECT account_id FROM post_accounts WHERE post_id = ? AND status = 'published'", [$id]);
            $keep = array_map('intval', array_column($keep, 'account_id'));
            Db::query(
                'DELETE FROM post_accounts WHERE post_id = ?' . ($keep ? ' AND account_id NOT IN (' . implode(',', $keep) . ')' : ''),
                [$id]
            );
            foreach ($accountIds as $aid) {
                if (in_array($aid, $keep, true)) {
                    continue;
                }
                Db::insert('post_accounts', [
                    'post_id' => $id,
                    'account_id' => $aid,
                    'content' => $overrides[$aid] ?? null,
                    'options' => json_encode(isset($types[$aid]) ? ['type' => $types[$aid]] : new \stdClass()),
                    'status' => 'pending',
                ]);
            }
            Db::query('DELETE FROM post_tag WHERE post_id = ?', [$id]);
            foreach ($tagIds as $tid) {
                Db::insert('post_tag', ['post_id' => $id, 'tag_id' => $tid]);
            }
            return $id;
        });

        $post = self::find($id, $workspaceId);
        Hooks::fire('post.saved', $post, $existing === null);
        Webhooks::dispatch($workspaceId, $existing ? 'post.updated' : 'post.created', ['post' => self::toArray($post)]);
        if ($status === 'pending_approval') {
            Webhooks::dispatch($workspaceId, 'post.approval_requested', ['post' => self::toArray($post)]);
            Notify::users(Notify::workspaceAdmins($workspaceId), 'approvals', 'Post needs approval', str_limit($content !== '' ? $content : '(media)', 120), '/posts/' . $post['id']);
        } elseif ($status === 'scheduled') {
            Webhooks::dispatch($workspaceId, 'post.scheduled', ['post' => self::toArray($post)]);
        }
        return ['post' => $post, 'errors' => []];
    }

    public static function validate(string $content, array $accountIds, array $mediaIds, array $overrides, ?string $scheduledAt, string $action, int $workspaceId, array $types = []): array
    {
        $errors = [];
        if ($action === 'draft') {
            if (trim($content) === '' && !$mediaIds) {
                $errors[] = 'Write something or add media before saving.';
            }
            return $errors;
        }
        if (!$accountIds) {
            $errors[] = 'Pick at least one account to post to.';
        }
        if ($action === 'schedule') {
            if (!$scheduledAt) {
                $errors[] = 'Pick a date and time to schedule the post.';
            } elseif (strtotime($scheduledAt . ' UTC') < time() - 60) {
                $errors[] = 'The scheduled time is in the past.';
            }
        }

        $media = $mediaIds ? Db::all('SELECT id, mime FROM media WHERE id IN (' . implode(',', $mediaIds) . ')') : [];
        $videos = count(array_filter($media, fn($m) => str_starts_with($m['mime'], 'video/')));
        $images = count($media) - $videos;

        $accounts = $accountIds ? Db::all('SELECT id, provider, name FROM accounts WHERE id IN (' . implode(',', $accountIds) . ')') : [];
        foreach ($accounts as $a) {
            $class = Registry::class($a['provider']);
            if (!$class) {
                continue;
            }
            $label = $class::name() . ' (' . $a['name'] . ')';
            $type = $types[(int) $a['id']] ?? 'feed';
            if ($type !== 'feed') {
                if (!isset($class::postTypes()[$type])) {
                    $errors[] = "$label doesn’t support that post type.";
                } elseif ($type === 'story' && count($media) !== 1) {
                    $errors[] = "$label: a story needs exactly one image or video.";
                }
                continue; // stories skip text and carousel rules
            }
            $text = Variables::apply($workspaceId, $overrides[(int) $a['id']] ?? $content);
            $limit = $class::textLimit();
            if (self::length($text, $a['provider']) > $limit) {
                $errors[] = "$label: the text is over the $limit character limit.";
            }
            if (trim($text) === '' && !$media) {
                $errors[] = "$label: the post is empty.";
            }
            $rules = $class::mediaRules();
            if ($rules['requires_video'] && !$videos) {
                $errors[] = "$label needs a video.";
            } elseif ($rules['requires_media'] && !$media) {
                $errors[] = "$label needs an image or video.";
            }
            if (count($media) > $rules['max_media']) {
                $errors[] = "$label allows at most {$rules['max_media']} media files.";
            }
            if ($videos && !$rules['video']) {
                $errors[] = "$label doesn’t support video.";
            }
            if ($videos > 1 || ($videos && $images && !in_array($a['provider'], ['facebook', 'instagram', 'threads'], true))) {
                $errors[] = "$label: use one video on its own, or images only.";
            }
        }
        return array_values(array_unique($errors));
    }

    /** Character count the way each platform counts (X counts every link as 23). */
    public static function length(string $text, string $provider): int
    {
        if ($provider === 'x') {
            $text = preg_replace('#https?://\S+#u', str_repeat('x', 23), $text);
        }
        return mb_strlen($text);
    }

    public static function find(int $id, int $workspaceId): ?array
    {
        return Db::one('SELECT * FROM posts WHERE id = ? AND workspace_id = ?', [$id, $workspaceId]);
    }

    /** Publishes a post right away. Used for "Publish now" and for approvals of overdue posts. */
    public static function publishNowInBackground(int $postId): void
    {
        register_shutdown_function(function () use ($postId) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);
            set_time_limit(600);
            Publisher::publish($postId);
        });
    }

    public static function approve(array $post, int $reviewerId): void
    {
        $scheduledAt = $post['scheduled_at'] ?: now();
        Db::update('posts', [
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'review_note' => null,
            'updated_at' => now(),
        ], 'id = ?', [$post['id']]);
        $post = self::find((int) $post['id'], (int) $post['workspace_id']);
        Webhooks::dispatch((int) $post['workspace_id'], 'post.approved', ['post' => self::toArray($post)]);
        if (strtotime($scheduledAt . ' UTC') <= time()) {
            self::publishNowInBackground((int) $post['id']);
        }
    }

    public static function reject(array $post, int $reviewerId, string $note): void
    {
        Db::update('posts', [
            'status' => 'draft',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'review_note' => $note !== '' ? $note : 'Sent back for changes.',
            'updated_at' => now(),
        ], 'id = ?', [$post['id']]);
        $post = self::find((int) $post['id'], (int) $post['workspace_id']);
        Webhooks::dispatch((int) $post['workspace_id'], 'post.rejected', ['post' => self::toArray($post)]);
    }

    public static function delete(array $post): void
    {
        Db::query('DELETE FROM posts WHERE id = ?', [$post['id']]);
        Webhooks::dispatch((int) $post['workspace_id'], 'post.deleted', ['post' => ['id' => (int) $post['id']]]);
    }

    /** Public JSON shape of a post, used by the API and webhooks. */
    public static function toArray(array $post): array
    {
        $targets = Db::all(
            'SELECT pa.*, a.provider, a.name, a.username FROM post_accounts pa JOIN accounts a ON a.id = pa.account_id WHERE pa.post_id = ?',
            [$post['id']]
        );
        $tags = Db::all('SELECT t.id, t.name, t.color FROM tags t JOIN post_tag pt ON pt.tag_id = t.id WHERE pt.post_id = ?', [$post['id']]);
        $iso = fn(?string $t) => $t ? gmdate('c', strtotime($t . ' UTC')) : null;
        return [
            'id' => (int) $post['id'],
            'status' => $post['status'],
            'content' => $post['content'],
            'media' => array_map(fn($m) => ['id' => $m['id'], 'url' => $m['url'], 'mime' => $m['mime']], Publisher::resolveMedia(json_arr($post['media']))),
            'scheduled_at' => $iso($post['scheduled_at']),
            'published_at' => $iso($post['published_at']),
            'review_note' => $post['review_note'],
            'first_comment' => $post['first_comment'] ?? null,
            'tags' => array_map(fn($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'color' => $t['color']], $tags),
            'accounts' => array_map(fn($t) => [
                'id' => (int) $t['account_id'],
                'provider' => $t['provider'],
                'name' => $t['name'],
                'username' => $t['username'],
                'content' => $t['content'],
                'type' => json_arr($t['options'] ?? '{}')['type'] ?? 'feed',
                'status' => $t['status'],
                'url' => $t['url'],
                'error' => $t['error'],
                'published_at' => $iso($t['published_at']),
            ], $targets),
            'created_at' => $iso($post['created_at']),
            'updated_at' => $iso($post['updated_at']),
        ];
    }

    /** Filters ids to those belonging to the workspace. */
    private static function ownedIds(string $table, mixed $ids, int $workspaceId, bool $keepOrder = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        if (!$ids) {
            return [];
        }
        $found = Db::all(
            "SELECT id FROM $table WHERE workspace_id = ? AND id IN (" . implode(',', $ids) . ')',
            [$workspaceId]
        );
        $found = array_map('intval', array_column($found, 'id'));
        return $keepOrder ? array_values(array_filter($ids, fn($i) => in_array($i, $found, true))) : $found;
    }
}
