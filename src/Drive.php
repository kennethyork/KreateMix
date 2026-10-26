<?php
declare(strict_types=1);

namespace App;

/** Google Drive: each user links their own Drive and imports images and videos into the media library. */
final class Drive
{
    private const API = 'https://www.googleapis.com/drive/v3';

    public static function enabled(): bool
    {
        return Settings::has('gdrive', ['client_id', 'client_secret']);
    }

    public static function redirectUri(): string
    {
        return abs_url('drive/callback');
    }

    public static function authUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => Settings::value('gdrive', 'client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    private static function token(array $form): array
    {
        $s = Settings::get('gdrive');
        $r = Http::post('https://oauth2.googleapis.com/token', ['form' => $form + ['client_id' => $s['client_id'], 'client_secret' => $s['client_secret']]]);
        if (empty($r['json']['access_token'])) {
            throw new \RuntimeException('Google: ' . ($r['json']['error_description'] ?? $r['json']['error'] ?? 'HTTP ' . $r['status']));
        }
        return ['access_token' => $r['json']['access_token'], 'refresh_token' => $r['json']['refresh_token'] ?? null, 'expires_at' => time() + (int) ($r['json']['expires_in'] ?? 3600)];
    }

    public static function connect(int $userId, string $code): void
    {
        $t = self::token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri()]);
        Db::query(
            "INSERT INTO user_connections (user_id, provider, token, created_at) VALUES (?, 'gdrive', ?, ?)
             ON CONFLICT(user_id, provider) DO UPDATE SET token = excluded.token, created_at = excluded.created_at",
            [$userId, Crypt::encrypt($t), now()]
        );
    }

    public static function connected(int $userId): bool
    {
        return (bool) Db::value("SELECT 1 FROM user_connections WHERE user_id = ? AND provider = 'gdrive'", [$userId]);
    }

    public static function disconnect(int $userId): void
    {
        Db::query("DELETE FROM user_connections WHERE user_id = ? AND provider = 'gdrive'", [$userId]);
    }

    private static function access(int $userId): string
    {
        $row = Db::one("SELECT token FROM user_connections WHERE user_id = ? AND provider = 'gdrive'", [$userId]);
        $t = Crypt::decryptArray($row['token'] ?? null);
        if (!$t) {
            throw new \RuntimeException('Connect Google Drive first.');
        }
        if (($t['expires_at'] ?? 0) < time() + 120) {
            if (empty($t['refresh_token'])) {
                self::disconnect($userId);
                throw new \RuntimeException('Your Google Drive connection expired. Connect again.');
            }
            $new = self::token(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']]);
            $new['refresh_token'] ??= $t['refresh_token'];
            $t = $new;
            Db::update('user_connections', ['token' => Crypt::encrypt($t)], "user_id = ? AND provider = 'gdrive'", [$userId]);
        }
        return $t['access_token'];
    }

    public static function files(int $userId, ?string $pageToken, string $query): array
    {
        $q = "(mimeType contains 'image/' or mimeType contains 'video/') and trashed = false";
        if ($query !== '') {
            $q .= " and name contains '" . str_replace(["\\", "'"], ["\\\\", "\\'"], $query) . "'";
        }
        $r = Http::get(self::API . '/files', [
            'query' => array_filter([
                'q' => $q, 'orderBy' => 'modifiedTime desc', 'pageSize' => 40, 'pageToken' => $pageToken,
                'fields' => 'nextPageToken,files(id,name,mimeType,thumbnailLink,size)', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
            ]),
            'headers' => ['Authorization' => 'Bearer ' . self::access($userId)],
        ]);
        if (!Http::ok($r)) {
            if ($r['status'] === 401) {
                self::disconnect($userId);
            }
            throw new \RuntimeException('Google Drive: ' . ($r['json']['error']['message'] ?? 'HTTP ' . $r['status']));
        }
        return [
            'items' => array_map(fn($f) => ['id' => $f['id'], 'title' => $f['name'], 'thumbnail' => $f['thumbnailLink'] ?? null, 'mime' => $f['mimeType']], $r['json']['files'] ?? []),
            'continuation' => $r['json']['nextPageToken'] ?? null,
        ];
    }

    public static function import(int $userId, int $workspaceId, string $fileId): array
    {
        $token = self::access($userId);
        $meta = Http::get(self::API . '/files/' . rawurlencode($fileId), ['query' => ['fields' => 'name,mimeType,size', 'supportsAllDrives' => 'true'], 'headers' => ['Authorization' => "Bearer $token"]]);
        if (!Http::ok($meta)) {
            throw new \RuntimeException('Google Drive: file not found.');
        }
        $res = Http::get(self::API . '/files/' . rawurlencode($fileId), ['query' => ['alt' => 'media', 'supportsAllDrives' => 'true'], 'headers' => ['Authorization' => "Bearer $token", 'Accept' => '*/*'], 'timeout' => 600]);
        if (!Http::ok($res)) {
            throw new \RuntimeException('Google Drive download failed (HTTP ' . $res['status'] . ').');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'gd');
        file_put_contents($tmp, $res['body']);
        $m = Controllers\MediaController::storeLocal($tmp, (string) $meta['json']['name'], $workspaceId, $userId);
        @unlink($tmp);
        if (isset($m['error'])) {
            throw new \RuntimeException($m['error']);
        }
        return [$m];
    }
}
