<?php
declare(strict_types=1);

namespace App;

/** Replaces {{name}} placeholders with workspace variables when publishing. */
final class Variables
{
    private static array $cache = [];

    public static function forWorkspace(int $workspaceId): array
    {
        if (!isset(self::$cache[$workspaceId])) {
            $rows = Db::all('SELECT name, value FROM variables WHERE workspace_id = ?', [$workspaceId]);
            self::$cache[$workspaceId] = array_column($rows, 'value', 'name');
        }
        return self::$cache[$workspaceId];
    }

    public static function apply(int $workspaceId, string $text): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }
        $vars = self::forWorkspace($workspaceId);
        return preg_replace_callback('/\{\{\s*([\w.-]+)\s*\}\}/u', function ($m) use ($vars) {
            return array_key_exists($m[1], $vars) ? $vars[$m[1]] : $m[0];
        }, $text);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}
