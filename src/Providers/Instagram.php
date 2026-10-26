<?php
declare(strict_types=1);

namespace App\Providers;

use App\Http;
use App\MediaTools;

/** Instagram professional accounts via the Instagram API with Instagram Login. */
final class Instagram extends OAuth2Provider
{
    private const GRAPH = 'https://graph.instagram.com/' . Facebook::VERSION;

    public static function key(): string { return 'instagram'; }
    public static function name(): string { return 'Instagram'; }
    public static function color(): string { return '#e4405f'; }
    public static function textLimit(): int { return 2200; }
    public static function implemented(): bool { return true; }

    public static function mediaRules(): array
    {
        return ['max_media' => 10, 'video' => true, 'requires_media' => true, 'requires_video' => false];
    }

    public static function credentialFields(): array
    {
        return ['app_id' => 'Instagram App ID', 'app_secret' => 'Instagram App Secret'];
    }

    public static function setupHint(): string
    {
        return 'In your Meta app add the Instagram product (“API setup with Instagram login”), copy the Instagram app ID and secret, and add the callback URL below under Business login settings. Only professional (business or creator) accounts can publish. Your server must be reachable from the internet so Instagram can fetch media.';
    }

    protected function authorizeEndpoint(): string { return 'https://www.instagram.com/oauth/authorize'; }
    protected function tokenEndpoint(): string { return 'https://api.instagram.com/oauth/access_token'; }
    protected function scopes(): array { return ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights']; }
    protected function scopeSeparator(): string { return ','; }

    public function connect(array $input, string $redirectUri): array
    {
        $short = $this->exchangeCode($input, $redirectUri);
        $long = Http::get('https://graph.instagram.com/access_token', ['query' => [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $this->clientSecret(),
            'access_token' => $short['access_token'],
        ]]);
        if (!Http::ok($long) || empty($long['json']['access_token'])) {
            throw new ProviderException('Instagram: ' . $this->errorMessage($long));
        }
        $token = ['access_token' => $long['json']['access_token'], 'expires_at' => time() + (int) ($long['json']['expires_in'] ?? 5184000)];
        $this->account = ['token' => $token];
        $me = $this->graph('GET', '/me', ['fields' => 'user_id,username,name,profile_picture_url,account_type']);
        return [[
            'provider_id' => (string) ($me['user_id'] ?? $me['id']),
            'name' => ($me['name'] ?? '') !== '' ? $me['name'] : $me['username'],
            'username' => $me['username'] ?? '',
            'avatar' => $me['profile_picture_url'] ?? null,
            'token' => $token,
            'data' => ['account_type' => $me['account_type'] ?? null],
        ]];
    }

    /** Long-lived Instagram tokens last 60 days and are refreshed with the token itself. */
    public function refreshToken(): ?array
    {
        $token = $this->token();
        if (empty($token['expires_at']) || $token['expires_at'] > time() + 7 * 86400) {
            return null;
        }
        if ($token['expires_at'] < time()) {
            throw ProviderException::unauthorized('Instagram access expired. Reconnect the account.');
        }
        $r = Http::get('https://graph.instagram.com/refresh_access_token', ['query' => ['grant_type' => 'ig_refresh_token', 'access_token' => $token['access_token']]]);
        if (!Http::ok($r) || empty($r['json']['access_token'])) {
            return null;
        }
        $new = ['access_token' => $r['json']['access_token'], 'expires_at' => time() + (int) ($r['json']['expires_in'] ?? 5184000)];
        $this->account['token'] = $new;
        return $new;
    }

    private function graph(string $method, string $path, array $params = []): array
    {
        $params['access_token'] = $this->accessToken();
        $res = Http::request($method, self::GRAPH . $path, $method === 'GET' ? ['query' => $params] : ['form' => $params, 'timeout' => 120]);
        if (!Http::ok($res)) {
            $code = $res['json']['error']['code'] ?? 0;
            $msg = 'Instagram: ' . $this->errorMessage($res);
            throw $code === 190 ? ProviderException::unauthorized($msg) : new ProviderException($msg);
        }
        return $res['json'];
    }

    public function publish(string $text, array $media): array
    {
        $igId = $this->account['provider_id'];
        $media = array_slice($media, 0, 10);
        if (!$media) {
            throw new ProviderException('Instagram posts need an image or video.');
        }
        foreach ($media as &$m) {
            $m = MediaTools::jpeg($m, 8 * 1024 * 1024, 1440); // Instagram only accepts JPEG images.
            MediaTools::assertPublicUrl($m['url'], 'Instagram');
        }
        unset($m);

        if (count($media) === 1) {
            $container = $this->container($igId, $media[0], ['caption' => $text]);
        } else {
            $children = array_map(fn($m) => $this->container($igId, $m, ['is_carousel_item' => 'true']), $media);
            foreach ($children as $c) {
                $this->waitReady($c);
            }
            $container = (string) $this->graph('POST', "/$igId/media", ['media_type' => 'CAROUSEL', 'children' => implode(',', $children), 'caption' => $text])['id'];
        }
        $this->waitReady($container);
        $id = (string) $this->graph('POST', "/$igId/media_publish", ['creation_id' => $container])['id'];
        $permalink = null;
        try {
            $permalink = $this->graph('GET', "/$id", ['fields' => 'permalink'])['permalink'] ?? null;
        } catch (ProviderException) {
        }
        return ['id' => $id, 'url' => $permalink];
    }

    private function container(string $igId, array $m, array $extra): string
    {
        if (MediaTools::isVideo($m)) {
            // Single videos post as Reels; videos inside a carousel use VIDEO.
            $params = ['media_type' => isset($extra['is_carousel_item']) ? 'VIDEO' : 'REELS', 'video_url' => $m['url']];
        } else {
            $params = ['image_url' => $m['url']];
        }
        return (string) $this->graph('POST', "/$igId/media", $params + $extra)['id'];
    }

    private function waitReady(string $containerId): void
    {
        $this->poll(function () use ($containerId) {
            $s = $this->graph('GET', "/$containerId", ['fields' => 'status_code,status']);
            $code = $s['status_code'] ?? 'FINISHED';
            if ($code === 'ERROR' || $code === 'EXPIRED') {
                throw new ProviderException('Instagram couldn’t process the media: ' . ($s['status'] ?? $code));
            }
            return $code === 'FINISHED' || $code === 'PUBLISHED' ? true : null;
        }, 300, 5);
    }

    public function accountMetrics(): array
    {
        $me = $this->graph('GET', '/me', ['fields' => 'followers_count,follows_count,media_count']);
        return isset($me['followers_count']) ? ['followers' => (int) $me['followers_count'], 'following' => (int) ($me['follows_count'] ?? 0)] : [];
    }

    public function postMetrics(string $providerPostId): array
    {
        $m = $this->graph('GET', '/' . $providerPostId, ['fields' => 'like_count,comments_count']);
        $out = ['likes' => (int) ($m['like_count'] ?? 0), 'replies' => (int) ($m['comments_count'] ?? 0)];
        try {
            $ins = $this->graph('GET', '/' . $providerPostId . '/insights', ['metric' => 'views,shares,saved']);
            foreach ($ins['data'] ?? [] as $row) {
                $v = (int) ($row['values'][0]['value'] ?? $row['total_value']['value'] ?? 0);
                match ($row['name']) {
                    'views' => $out['impressions'] = $v,
                    'shares' => $out['shares'] = $v,
                    'saved' => $out['saves'] = $v,
                    default => null,
                };
            }
        } catch (ProviderException) {
        }
        return $out;
    }
}
