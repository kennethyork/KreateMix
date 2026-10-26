<?php
declare(strict_types=1);

namespace App\Providers;

use App\Http;
use App\MediaTools;

/** Facebook Pages via the Graph API. Each page becomes its own account. */
final class Facebook extends OAuth2Provider
{
    public const VERSION = 'v26.0';
    private const GRAPH = 'https://graph.facebook.com/' . self::VERSION;

    public static function key(): string { return 'facebook'; }
    public static function name(): string { return 'Facebook Pages'; }
    public static function color(): string { return '#1877f2'; }
    public static function textLimit(): int { return 63206; }
    public static function implemented(): bool { return true; }

    public static function mediaRules(): array
    {
        return ['max_media' => 10, 'video' => true, 'requires_media' => false, 'requires_video' => false];
    }

    public static function credentialFields(): array
    {
        return ['app_id' => 'App ID', 'app_secret' => 'App Secret'];
    }

    public static function setupHint(): string
    {
        return 'At developers.facebook.com create a Business app, add Facebook Login for Business, and add the callback URL below as a valid OAuth redirect URI. Request pages_show_list, pages_read_engagement, pages_manage_posts and read_insights. Your server must be reachable from the internet so Facebook can fetch media.';
    }

    protected function authorizeEndpoint(): string { return 'https://www.facebook.com/' . self::VERSION . '/dialog/oauth'; }
    protected function tokenEndpoint(): string { return self::GRAPH . '/oauth/access_token'; }
    protected function scopes(): array { return ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'read_insights']; }
    protected function scopeSeparator(): string { return ','; }

    public function connect(array $input, string $redirectUri): array
    {
        $short = $this->exchangeCode($input, $redirectUri);
        // Page tokens fetched with a long-lived user token don't expire.
        $long = Http::get(self::GRAPH . '/oauth/access_token', ['query' => [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'fb_exchange_token' => $short['access_token'],
        ]]);
        $userToken = $long['json']['access_token'] ?? $short['access_token'];

        $pages = [];
        $url = self::GRAPH . '/me/accounts';
        $query = ['fields' => 'id,name,username,access_token,picture.type(large){url}', 'limit' => 100, 'access_token' => $userToken];
        while ($url) {
            $res = Http::get($url, ['query' => $query]);
            if (!Http::ok($res)) {
                throw new ProviderException('Facebook: ' . $this->errorMessage($res));
            }
            foreach ($res['json']['data'] ?? [] as $p) {
                $pages[] = [
                    'provider_id' => (string) $p['id'],
                    'name' => $p['name'],
                    'username' => $p['username'] ?? '',
                    'avatar' => $p['picture']['data']['url'] ?? null,
                    'token' => ['access_token' => $p['access_token']],
                    'data' => [],
                ];
            }
            $url = $res['json']['paging']['next'] ?? null;
            $query = [];
        }
        if (!$pages) {
            throw new ProviderException('No Facebook Pages were shared. Reconnect and select at least one Page.');
        }
        return $pages;
    }

    private function graph(string $method, string $path, array $params = [], string $host = self::GRAPH): array
    {
        $params['access_token'] = $this->accessToken();
        $res = Http::request($method, $host . $path, $method === 'GET' ? ['query' => $params] : ['form' => $params, 'timeout' => 300]);
        if (!Http::ok($res)) {
            $code = $res['json']['error']['code'] ?? 0;
            $msg = 'Facebook: ' . $this->errorMessage($res);
            throw in_array($code, [190, 102], true) ? ProviderException::unauthorized($msg) : new ProviderException($msg);
        }
        return $res['json'];
    }

    public function publish(string $text, array $media): array
    {
        $pageId = $this->account['provider_id'];
        $videos = array_values(array_filter($media, [MediaTools::class, 'isVideo']));
        $images = array_values(array_filter($media, fn($m) => !MediaTools::isVideo($m)));

        if ($videos) {
            MediaTools::assertPublicUrl($videos[0]['url'], 'Facebook');
            $r = $this->graph('POST', "/$pageId/videos", ['file_url' => $videos[0]['url'], 'description' => $text], 'https://graph-video.facebook.com/' . self::VERSION);
            $id = (string) $r['id'];
            return ['id' => $id, 'url' => "https://www.facebook.com/$pageId/videos/$id"];
        }
        if (count($images) === 1) {
            MediaTools::assertPublicUrl($images[0]['url'], 'Facebook');
            $r = $this->graph('POST', "/$pageId/photos", ['url' => $images[0]['url'], 'caption' => $text]);
            $id = (string) ($r['post_id'] ?? $r['id']);
            return ['id' => $id, 'url' => 'https://www.facebook.com/' . $id];
        }
        $params = ['message' => $text];
        foreach ($images as $i => $img) {
            MediaTools::assertPublicUrl($img['url'], 'Facebook');
            $photo = $this->graph('POST', "/$pageId/photos", ['url' => $img['url'], 'published' => 'false']);
            $params["attached_media[$i]"] = json_encode(['media_fbid' => $photo['id']]);
        }
        $r = $this->graph('POST', "/$pageId/feed", $params);
        $id = (string) $r['id'];
        return ['id' => $id, 'url' => 'https://www.facebook.com/' . $id];
    }

    public function accountMetrics(): array
    {
        $p = $this->graph('GET', '/' . $this->account['provider_id'], ['fields' => 'followers_count,fan_count']);
        return ['followers' => (int) ($p['followers_count'] ?? $p['fan_count'] ?? 0)];
    }

    public function postMetrics(string $providerPostId): array
    {
        $p = $this->graph('GET', '/' . $providerPostId, ['fields' => 'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares']);
        return [
            'likes' => (int) ($p['reactions']['summary']['total_count'] ?? 0),
            'replies' => (int) ($p['comments']['summary']['total_count'] ?? 0),
            'shares' => (int) ($p['shares']['count'] ?? 0),
        ];
    }
}
