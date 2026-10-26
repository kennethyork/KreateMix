<?php
declare(strict_types=1);

namespace App\Providers;

use App\MediaTools;

/** Pinterest API v5. Each board is connected as its own account. */
final class Pinterest extends OAuth2Provider
{
    private const API = 'https://api.pinterest.com/v5';

    public static function key(): string { return 'pinterest'; }
    public static function name(): string { return 'Pinterest'; }
    public static function color(): string { return '#e60023'; }
    public static function textLimit(): int { return 500; }
    public static function implemented(): bool { return true; }

    public static function mediaRules(): array
    {
        return ['max_media' => 1, 'video' => false, 'requires_media' => true, 'requires_video' => false];
    }

    public static function credentialFields(): array
    {
        return ['app_id' => 'App ID', 'app_secret' => 'App Secret'];
    }

    public static function setupHint(): string
    {
        return 'At developers.pinterest.com create an app, request boards:read, pins:read, pins:write and user_accounts:read, and add the callback URL below. Each board you pick becomes its own account. The first line of a post becomes the pin title; a link anywhere in the text becomes the pin’s destination.';
    }

    protected function authorizeEndpoint(): string { return 'https://www.pinterest.com/oauth/'; }
    protected function tokenEndpoint(): string { return self::API . '/oauth/token'; }
    protected function scopes(): array { return ['boards:read', 'pins:read', 'pins:write', 'user_accounts:read']; }
    protected function scopeSeparator(): string { return ','; }
    protected function basicAuth(): bool { return true; }

    public function connect(array $input, string $redirectUri): array
    {
        $token = $this->exchangeCode($input, $redirectUri);
        $this->account = ['token' => $token];
        $user = $this->api('GET', self::API . '/user_account')['json'];
        $boards = [];
        $bookmark = null;
        do {
            $r = $this->api('GET', self::API . '/boards', ['query' => array_filter(['page_size' => 100, 'bookmark' => $bookmark])])['json'];
            foreach ($r['items'] ?? [] as $b) {
                $boards[] = [
                    'provider_id' => (string) $b['id'],
                    'name' => $b['name'],
                    'username' => $user['username'] ?? '',
                    'avatar' => $user['profile_image'] ?? null,
                    'token' => $token,
                    'data' => ['board_id' => (string) $b['id']],
                ];
            }
            $bookmark = $r['bookmark'] ?? null;
        } while ($bookmark);
        if (!$boards) {
            throw new ProviderException('This Pinterest account has no boards. Create one first.');
        }
        return $boards;
    }

    public function publish(string $text, array $media): array
    {
        $images = array_values(array_filter($media, fn($m) => !MediaTools::isVideo($m)));
        if (!$images) {
            throw new ProviderException('Pinterest pins need an image.');
        }
        $img = MediaTools::fit($images[0], 20 * 1024 * 1024);
        [$title, $description] = self::splitTitle($text, 100);
        $link = preg_match('#https?://\S+#u', $text, $m) ? rtrim($m[0], '.,;:!?)') : null;

        $source = ['source_type' => 'image_base64', 'content_type' => $img['mime'] === 'image/png' ? 'image/png' : 'image/jpeg', 'data' => base64_encode((string) file_get_contents($img['path']))];
        if (!in_array($img['mime'], ['image/png', 'image/jpeg'], true)) {
            $img = MediaTools::jpeg($img, 20 * 1024 * 1024);
            $source = ['source_type' => 'image_base64', 'content_type' => 'image/jpeg', 'data' => base64_encode((string) file_get_contents($img['path']))];
        }
        $body = array_filter([
            'board_id' => $this->accountData()['board_id'] ?? $this->account['provider_id'],
            'title' => $title,
            'description' => mb_substr($description !== '' ? $description : $text, 0, 500),
            'link' => $link,
            'media_source' => $source,
        ], fn($v) => $v !== null && $v !== '');
        $pin = $this->api('POST', self::API . '/pins', ['json' => $body, 'timeout' => 120])['json'];
        return ['id' => (string) $pin['id'], 'url' => 'https://www.pinterest.com/pin/' . $pin['id'] . '/'];
    }

    public function accountMetrics(): array
    {
        $u = $this->api('GET', self::API . '/user_account')['json'];
        return isset($u['follower_count']) ? ['followers' => (int) $u['follower_count']] : [];
    }

    public function postMetrics(string $providerPostId): array
    {
        $r = $this->api('GET', self::API . '/pins/' . rawurlencode($providerPostId), ['query' => ['pin_metrics' => 'true']])['json'];
        $lt = $r['pin_metrics']['lifetime_metrics'] ?? $r['pin_metrics']['all_time'] ?? [];
        return $lt ? [
            'saves' => (int) ($lt['save'] ?? 0),
            'clicks' => (int) ($lt['pin_click'] ?? 0),
            'impressions' => (int) ($lt['impression'] ?? 0),
            'replies' => (int) ($lt['comment'] ?? 0),
            'likes' => (int) ($lt['reaction'] ?? 0),
        ] : [];
    }
}
