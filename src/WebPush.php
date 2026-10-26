<?php
declare(strict_types=1);

namespace App;

/**
 * Web Push with VAPID (RFC 8292) and aes128gcm payload encryption (RFC 8291), using only ext-openssl.
 */
final class WebPush
{
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64u(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /** VAPID keys, generated on first use. Returns ['private_pem', 'public' (base64url raw point)]. */
    public static function vapid(): array
    {
        $s = Settings::get('push');
        if (empty($s['private_pem'])) {
            $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            openssl_pkey_export($key, $pem);
            $s = ['private_pem' => $pem, 'public' => self::b64u(self::rawPublic($key))];
            Settings::save('push', $s);
        }
        return $s;
    }

    private static function rawPublic(\OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key)['ec'];
        return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function publicKeyFromRaw(string $raw): \OpenSSLAsymmetricKey
    {
        $der = hex2bin(self::P256_SPKI_PREFIX) . $raw;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if (!$key) {
            throw new \RuntimeException('Invalid subscription key.');
        }
        return $key;
    }

    /** Encrypts $payload for a subscription. Returns the request body. */
    public static function encrypt(string $payload, string $p256dh, string $auth, ?string $salt = null, ?\OpenSSLAsymmetricKey $ephemeral = null): string
    {
        $uaPublic = self::unb64u($p256dh);
        $authSecret = self::unb64u($auth);
        if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
            throw new \RuntimeException('Invalid subscription keys.');
        }
        $ephemeral ??= openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $asPublic = self::rawPublic($ephemeral);
        $shared = openssl_pkey_derive(self::publicKeyFromRaw($uaPublic), $ephemeral, 32);
        if ($shared === false) {
            throw new \RuntimeException('ECDH failed.');
        }
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $salt ??= random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    /** ES256 JWT for the VAPID Authorization header. */
    public static function jwt(string $audience, string $subject): string
    {
        $v = self::vapid();
        $h = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $c = self::b64u(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $subject]));
        openssl_sign("$h.$c", $der, $v['private_pem'], OPENSSL_ALGO_SHA256);
        return "$h.$c." . self::b64u(self::derToRaw($der));
    }

    /** Converts an ASN.1 DER ECDSA signature to the 64-byte R||S form JWS wants. */
    private static function derToRaw(string $der): string
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $pos++; // 0x02
            $len = ord($der[$pos++]);
            $int = substr($der, $pos, $len);
            $pos += $len;
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    /** Sends to one subscription row. Returns HTTP status; 404/410 means the subscription is gone. */
    public static function send(array $sub, array $message): int
    {
        $v = self::vapid();
        $p = parse_url($sub['endpoint']);
        $audience = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $admin = Db::value('SELECT email FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1') ?: 'admin@example.com';
        $body = self::encrypt(json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $sub['p256dh'], $sub['auth']);
        $res = Http::post($sub['endpoint'], [
            'body' => $body,
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'TTL' => '86400',
                'Urgency' => 'normal',
                'Authorization' => 'vapid t=' . self::jwt($audience, 'mailto:' . $admin) . ', k=' . $v['public'],
            ],
        ]);
        if (in_array($res['status'], [404, 410], true)) {
            Db::query('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]);
        }
        return $res['status'];
    }
}
