<?php
declare(strict_types=1);

namespace App\Providers;

use App\Config;
use App\Crypt;
use App\Db;
use App\Hooks;

final class Registry
{
    private const CORE = [
        X::class,
        Mastodon::class,
        Bluesky::class,
        Facebook::class,
        Instagram::class,
        LinkedIn::class,
        YouTube::class,
        Threads::class,
        TikTok::class,
        Pinterest::class,
        GoogleBusiness::class,
    ];

    /** @return array<string, class-string<Provider>> keyed by provider key */
    public static function all(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $classes = self::CORE;
        if (Config::get('debug')) {
            $classes[] = Sandbox::class;
        }
        $classes = Hooks::filter('providers', $classes);
        $map = [];
        foreach ($classes as $class) {
            $map[$class::key()] = $class;
        }
        return $map;
    }

    /** @return class-string<Provider>|null */
    public static function class(string $key): ?string
    {
        return self::all()[$key] ?? null;
    }

    public static function credentials(string $key): array
    {
        $row = Db::one('SELECT credentials FROM services WHERE provider = ?', [$key]);
        return Crypt::decryptArray($row['credentials'] ?? null);
    }

    /** Whether the provider can be used: built, and its API keys are saved if it needs any. */
    public static function ready(string $key): bool
    {
        $class = self::class($key);
        if (!$class || !$class::implemented()) {
            return false;
        }
        $fields = $class::credentialFields();
        if (!$fields) {
            return true;
        }
        $creds = self::credentials($key);
        foreach (array_keys($fields) as $f) {
            if (empty($creds[$f])) {
                return false;
            }
        }
        return true;
    }

    /** Builds a provider for a stored account row. */
    public static function forAccount(array $account): Provider
    {
        $class = self::class($account['provider']);
        if (!$class) {
            throw new ProviderException('Unknown platform: ' . $account['provider']);
        }
        $account['token'] = Crypt::decryptArray($account['token'] ?? null);
        return new $class(self::credentials($account['provider']), $account);
    }

    public static function make(string $key): Provider
    {
        $class = self::class($key);
        if (!$class) {
            throw new ProviderException('Unknown platform: ' . $key);
        }
        return new $class(self::credentials($key));
    }
}
