<?php
declare(strict_types=1);

namespace App;

/**
 * Instance-wide settings groups (SMTP, AI, Canva, ads platforms), stored encrypted
 * in the services table under "@group" so they never collide with platform keys.
 */
final class Settings
{
    private static array $cache = [];

    public static function get(string $group): array
    {
        if (!array_key_exists($group, self::$cache)) {
            $row = Db::one('SELECT credentials FROM services WHERE provider = ?', ['@' . $group]);
            self::$cache[$group] = Crypt::decryptArray($row['credentials'] ?? null);
        }
        return self::$cache[$group];
    }

    public static function value(string $group, string $key, mixed $default = null): mixed
    {
        $v = self::get($group)[$key] ?? null;
        return $v === null || $v === '' ? $default : $v;
    }

    public static function save(string $group, array $values): void
    {
        Db::query(
            'INSERT INTO services (provider, credentials, active, updated_at) VALUES (?, ?, 1, ?)
             ON CONFLICT(provider) DO UPDATE SET credentials = excluded.credentials, updated_at = excluded.updated_at',
            ['@' . $group, Crypt::encrypt($values), now()]
        );
        self::$cache[$group] = $values;
    }

    /** True when every listed key has a value. */
    public static function has(string $group, array $keys): bool
    {
        $s = self::get($group);
        foreach ($keys as $k) {
            if (empty($s[$k])) {
                return false;
            }
        }
        return true;
    }
}
