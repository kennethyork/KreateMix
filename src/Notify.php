<?php
declare(strict_types=1);

namespace App;

/** Push notifications for things people care about: failures, approvals, comments, publishes. */
final class Notify
{
    public const EVENTS = [
        'failed' => 'A post failed to publish',
        'approvals' => 'A post needs my approval (admins)',
        'comments' => 'New comments in the inbox',
        'published' => 'My post was published',
    ];

    /** Sends to users who opted in to $event. Delivery happens after the response when possible. */
    public static function users(array $userIds, string $event, string $title, string $body, string $path): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) {
            return;
        }
        $users = Db::all('SELECT id, notify FROM users WHERE id IN (' . implode(',', $userIds) . ')');
        $ids = array_map(fn($u) => (int) $u['id'], array_filter($users, fn($u) => !empty(json_arr($u['notify'])[$event])));
        if (!$ids) {
            return;
        }
        $subs = Db::all('SELECT * FROM push_subscriptions WHERE user_id IN (' . implode(',', $ids) . ')');
        if (!$subs) {
            return;
        }
        $message = ['title' => $title, 'body' => str_limit($body, 180), 'url' => abs_url(ltrim($path, '/')), 'tag' => $event];
        $send = function () use ($subs, $message) {
            foreach ($subs as $s) {
                try {
                    WebPush::send($s, $message);
                } catch (\Throwable $e) {
                    error_log('push: ' . $e->getMessage());
                }
            }
        };
        PHP_SAPI === 'cli' ? $send() : register_shutdown_function($send);
    }

    public static function workspaceAdmins(int $workspaceId): array
    {
        return array_column(Db::all("SELECT user_id FROM workspace_user WHERE workspace_id = ? AND role = 'admin'", [$workspaceId]), 'user_id');
    }

    public static function workspaceMembers(int $workspaceId): array
    {
        return array_column(Db::all("SELECT user_id FROM workspace_user WHERE workspace_id = ? AND role IN ('admin', 'member')", [$workspaceId]), 'user_id');
    }
}
