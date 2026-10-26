<?php
declare(strict_types=1);

namespace App;

/** Canva Connect API: each user links their own Canva account and imports designs as media. */
final class Canva
{
    private const API = 'https://api.canva.com/rest/v1';
    private const SCOPES = 'design:meta:read design:content:read profile:read';

    public static function enabled(): bool
    {
        return Settings::has('canva', ['client_id', 'client_secret']);
    }

    public static function redirectUri(): string
    {
        return abs_url('canva/callback');
    }

    public static function authUrl(string $state, string $verifier): string
    {
        return 'https://www.canva.com/api/oauth/authorize?' . http_build_query([
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 's256',
            'scope' => self::SCOPES,
            'response_type' => 'code',
            'client_id' => Settings::value('canva', 'client_id'),
            'state' => $state,
            'redirect_uri' => self::redirectUri(),
        ]);
    }

    private static function tokenRequest(array $form): array
    {
        $s = Settings::get('canva');
        $res = Http::post(self::API . '/oauth/token', [
            'form' => $form,
            'headers' => ['Authorization' => 'Basic ' . base64_encode($s['client_id'] . ':' . $s['client_secret'])],
        ]);
        if (!Http::ok($res) || empty($res['json']['access_token'])) {
            throw new \RuntimeException('Canva: ' . ($res['json']['error_description'] ?? $res['json']['message'] ?? 'HTTP ' . $res['status']));
        }
        $j = $res['json'];
        return ['access_token' => $j['access_token'], 'refresh_token' => $j['refresh_token'] ?? null, 'expires_at' => time() + (int) ($j['expires_in'] ?? 14400)];
    }

    public static function connect(int $userId, string $code, string $verifier): void
    {
        $token = self::tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => self::redirectUri()]);
        Db::query(
            'INSERT INTO user_connections (user_id, provider, token, created_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(user_id, provider) DO UPDATE SET token = excluded.token, created_at = excluded.created_at',
            [$userId, 'canva', Crypt::encrypt($token), now()]
        );
    }

    public static function connected(int $userId): bool
    {
        return (bool) Db::value("SELECT 1 FROM user_connections WHERE user_id = ? AND provider = 'canva'", [$userId]);
    }

    public static function disconnect(int $userId): void
    {
        Db::query("DELETE FROM user_connections WHERE user_id = ? AND provider = 'canva'", [$userId]);
    }

    private static function token(int $userId): string
    {
        $row = Db::one("SELECT token FROM user_connections WHERE user_id = ? AND provider = 'canva'", [$userId]);
        $t = Crypt::decryptArray($row['token'] ?? null);
        if (!$t) {
            throw new \RuntimeException('Connect your Canva account first.');
        }
        if (($t['expires_at'] ?? 0) < time() + 120) {
            if (empty($t['refresh_token'])) {
                self::disconnect($userId);
                throw new \RuntimeException('Your Canva connection expired. Connect again.');
            }
            try {
                $t = self::tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']]);
            } catch (\RuntimeException) {
                self::disconnect($userId);
                throw new \RuntimeException('Your Canva connection expired. Connect again.');
            }
            Db::update('user_connections', ['token' => Crypt::encrypt($t)], "user_id = ? AND provider = 'canva'", [$userId]);
        }
        return $t['access_token'];
    }

    private static function api(int $userId, string $method, string $path, array $options = []): array
    {
        $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer ' . self::token($userId)];
        $res = Http::request($method, self::API . $path, $options);
        if (!Http::ok($res)) {
            if ($res['status'] === 401) {
                self::disconnect($userId);
            }
            throw new \RuntimeException('Canva: ' . ($res['json']['message'] ?? 'HTTP ' . $res['status']));
        }
        return $res['json'];
    }

    public static function designs(int $userId, ?string $continuation = null, string $query = ''): array
    {
        $j = self::api($userId, 'GET', '/designs', ['query' => array_filter([
            'ownership' => 'any',
            'sort_by' => 'modified_descending',
            'continuation' => $continuation,
            'query' => $query,
        ])]);
        return [
            'items' => array_map(fn($d) => [
                'id' => $d['id'],
                'title' => $d['title'] ?? 'Untitled design',
                'thumbnail' => $d['thumbnail']['url'] ?? null,
                'pages' => $d['page_count'] ?? null,
            ], $j['items'] ?? []),
            'continuation' => $j['continuation'] ?? null,
        ];
    }

    /** Exports a design (PNG, or MP4 for video designs) and saves each page as media. Returns media arrays. */
    public static function import(int $userId, int $workspaceId, string $designId, string $format = 'png'): array
    {
        $format = in_array($format, ['png', 'jpg', 'mp4'], true) ? $format : 'png';
        $body = ['design_id' => $designId, 'format' => ['type' => $format] + ($format === 'jpg' ? ['quality' => 90] : []) + ($format === 'mp4' ? ['quality' => 'horizontal_1080p'] : [])];
        $job = self::api($userId, 'POST', '/exports', ['json' => $body])['job'] ?? [];
        $deadline = time() + 120;
        while (($job['status'] ?? '') === 'in_progress' && time() < $deadline) {
            sleep(2);
            $job = self::api($userId, 'GET', '/exports/' . rawurlencode($job['id']))['job'] ?? [];
        }
        if (($job['status'] ?? '') !== 'success') {
            throw new \RuntimeException('Canva export ' . (($job['status'] ?? '') === 'failed' ? 'failed: ' . ($job['error']['message'] ?? '') : 'is taking too long. Try again.'));
        }
        $meta = self::api($userId, 'GET', '/designs/' . rawurlencode($designId))['design'] ?? [];
        $title = preg_replace('/[^\w\- ]+/u', '', $meta['title'] ?? 'canva') ?: 'canva';
        $out = [];
        foreach ($job['urls'] ?? [] as $i => $u) {
            $tmp = tempnam(sys_get_temp_dir(), 'canva');
            $res = Http::get($u, ['timeout' => 300, 'headers' => ['Accept' => '*/*']]);
            if (!Http::ok($res)) {
                continue;
            }
            file_put_contents($tmp, $res['body']);
            $name = $title . (count($job['urls']) > 1 ? ' ' . ($i + 1) : '') . '.' . $format;
            $m = Controllers\MediaController::storeLocal($tmp, $name, $workspaceId, $userId);
            @unlink($tmp);
            if (!isset($m['error'])) {
                $out[] = $m;
            }
        }
        if (!$out) {
            throw new \RuntimeException('Canva didn’t return any files.');
        }
        return $out;
    }
}
