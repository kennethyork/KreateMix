<?php
declare(strict_types=1);

namespace App;

final class Middleware
{
    /** @var array<string, callable> middleware added by modules */
    private static array $custom = [];

    public static function register(string $name, callable $handler): void
    {
        self::$custom[$name] = $handler;
    }

    public static function run(string $name): void
    {
        if ($name === 'no_csrf') {
            return;
        }
        [$base] = explode(':', $name, 2);
        if (isset(self::$custom[$base])) {
            (self::$custom[$base])(explode(':', $name, 2)[1] ?? null);
            return;
        }
        [$name, $arg] = array_pad(explode(':', $name, 2), 2, null);
        match ($name) {
            'auth' => self::auth(),
            'guest' => self::guest(),
            'workspace' => self::workspace(),
            'role' => self::role((string) $arg),
            'admin' => self::admin(),
            default => throw new \LogicException("Unknown middleware $name"),
        };
    }

    private static function auth(): void
    {
        if (!Auth::user()) {
            if (wants_json()) {
                json_response(['error' => 'Not signed in.'], 401);
            }
            $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? null;
            redirect('/login');
        }
    }

    private static function guest(): void
    {
        if (Auth::user()) {
            redirect('/');
        }
    }

    private static function workspace(): void
    {
        if (!Workspace::current()) {
            redirect('/workspaces/new');
        }
    }

    private static function role(string $role): void
    {
        if (!Workspace::can($role)) {
            abort(403);
        }
    }

    private static function admin(): void
    {
        if (!Auth::isAdmin()) {
            abort(403);
        }
    }
}
