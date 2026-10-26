<?php
declare(strict_types=1);

namespace App;

final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = $_SESSION['user_id'] ?? null;
            self::$user = $id ? Db::one('SELECT * FROM users WHERE id = ?', [$id]) : null;
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int) self::user()['id'] : null;
    }

    public static function attempt(string $email, string $password): bool
    {
        $user = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$user || !password_verify($password, $user['password'])) {
            return false;
        }
        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }
        self::login((int) $user['id']);
        return true;
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        self::$loaded = false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
        self::$loaded = true;
    }

    public static function isAdmin(): bool
    {
        return (bool) (self::user()['is_admin'] ?? false);
    }

    public static function refresh(): void
    {
        self::$loaded = false;
    }
}
