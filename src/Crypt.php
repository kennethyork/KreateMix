<?php
declare(strict_types=1);

namespace App;

/** AES-256-GCM encryption for API credentials and access tokens at rest. */
final class Crypt
{
    private static function key(): string
    {
        return hash('sha256', (string) Config::get('app_key'), true);
    }

    public static function encrypt(array|string $value): string
    {
        $plain = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : $value;
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    public static function decryptArray(?string $payload): array
    {
        $plain = self::decrypt($payload);
        return $plain === null ? [] : (json_decode($plain, true) ?: []);
    }
}
