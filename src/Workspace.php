<?php
declare(strict_types=1);

namespace App;

/** The workspace the signed-in user is working in, and their role there. */
final class Workspace
{
    public const ROLES = ['admin' => 'Admin', 'member' => 'Member', 'viewer' => 'Viewer'];

    private static ?array $current = null;

    public static function current(): ?array
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $user = Auth::user();
        if (!$user) {
            return null;
        }
        $sql = 'SELECT w.*, wu.role FROM workspaces w JOIN workspace_user wu ON wu.workspace_id = w.id AND wu.user_id = ?';
        $ws = null;
        if ($user['current_workspace_id']) {
            $ws = Db::one("$sql WHERE w.id = ?", [$user['id'], $user['current_workspace_id']]);
        }
        $ws ??= Db::one("$sql ORDER BY w.id LIMIT 1", [$user['id']]);
        if ($ws && (int) $ws['id'] !== (int) $user['current_workspace_id']) {
            Db::update('users', ['current_workspace_id' => $ws['id']], 'id = ?', [$user['id']]);
        }
        return self::$current = $ws;
    }

    public static function id(): int
    {
        return (int) (self::current()['id'] ?? 0);
    }

    public static function role(): ?string
    {
        return self::current()['role'] ?? null;
    }

    /** admin > member > viewer. Instance admins count as workspace admins everywhere they belong. */
    public static function can(string $minRole): bool
    {
        $rank = ['viewer' => 1, 'member' => 2, 'admin' => 3];
        $role = self::role();
        if ($role === null) {
            return false;
        }
        if (Auth::isAdmin()) {
            return true;
        }
        return ($rank[$role] ?? 0) >= ($rank[$minRole] ?? 99);
    }

    public static function forUser(int $userId): array
    {
        return Db::all(
            'SELECT w.*, wu.role FROM workspaces w JOIN workspace_user wu ON wu.workspace_id = w.id WHERE wu.user_id = ? ORDER BY w.name',
            [$userId]
        );
    }

    public static function create(string $name, int $userId, string $color = '#4f46e5'): int
    {
        return Db::transaction(function () use ($name, $userId, $color) {
            $id = Db::insert('workspaces', ['name' => $name, 'color' => $color, 'created_by' => $userId, 'created_at' => now()]);
            Db::insert('workspace_user', ['workspace_id' => $id, 'user_id' => $userId, 'role' => 'admin', 'joined_at' => now()]);
            return $id;
        });
    }

    public static function switchTo(int $workspaceId): bool
    {
        $userId = Auth::id();
        $ok = Db::value('SELECT 1 FROM workspace_user WHERE workspace_id = ? AND user_id = ?', [$workspaceId, $userId]);
        if (!$ok) {
            return false;
        }
        Db::update('users', ['current_workspace_id' => $workspaceId], 'id = ?', [$userId]);
        self::$current = null;
        Auth::refresh();
        return true;
    }
}
