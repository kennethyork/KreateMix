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

    /**
     * Builds a provider for an account and refreshes its token if it's about to expire,
     * saving the new token to this account and to sibling accounts that shared the old one
     * (pages, boards, channels and locations connected in a single login).
     */
    public static function withFreshToken(array $account): Provider
    {
        $provider = self::forAccount($account);
        $old = Crypt::decryptArray($account['token'] ?? null);
        $new = $provider->refreshToken();
        if ($new !== null) {
            $enc = Crypt::encrypt($new);
            Db::update('accounts', ['token' => $enc, 'authorized' => 1, 'updated_at' => now()], 'id = ?', [$account['id']]);
            if (!empty($old['refresh_token']) || !empty($old['access_token'])) {
                $siblings = Db::all(
                    'SELECT id, token FROM accounts WHERE workspace_id = (SELECT workspace_id FROM accounts WHERE id = ?) AND provider = ? AND id != ?',
                    [$account['id'], $account['provider'], $account['id']]
                );
                foreach ($siblings as $sib) {
                    $t = Crypt::decryptArray($sib['token']);
                    $same = (!empty($old['refresh_token']) && ($t['refresh_token'] ?? null) === $old['refresh_token'])
                        || (($t['access_token'] ?? null) === ($old['access_token'] ?? false));
                    if ($same) {
                        Db::update('accounts', ['token' => $enc, 'updated_at' => now()], 'id = ?', [$sib['id']]);
                    }
                }
            }
        }
        return $provider;
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
