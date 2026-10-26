<?php
declare(strict_types=1);

namespace App\Providers;

use App\Http;
use App\MediaTools;

/** Threads API (graph.threads.net). */
final class Threads extends OAuth2Provider
{
    private const GRAPH = 'https://graph.threads.net/v1.0';

    public static function key(): string { return 'threads'; }
    public static function name(): string { return 'Threads'; }
    public static function color(): string { return '#000000'; }
    public static function textLimit(): int { return 500; }
    public static function implemented(): bool { return true; }

    public static function mediaRules(): array
    {
        return ['max_media' => 20, 'video' => true, 'requires_media' => false, 'requires_video' => false];
    }

    public static function credentialFields(): array
    {
        return ['app_id' => 'Threads App ID', 'app_secret' => 'Threads App Secret'];
    }

    public static function setupHint(): string
    {
        return 'In your Meta app add the “Access the Threads API” use case with threads_basic, threads_content_publish and threads_manage_insights, and add the callback URL below as a redirect URI. Your server must be reachable from the internet so Threads can fetch media.';
    }

    protected function authorizeEndpoint(): string { return 'https://threads.net/oauth/authorize'; }
    protected function tokenEndpoint(): string { return 'https://graph.threads.net/oauth/access_token'; }
    protected function scopes(): array { return ['threads_basic', 'threads_content_publish', 'threads_manage_insights', 'threads_read_replies', 'threads_manage_replies']; }
    protected function scopeSeparator(): string { return ','; }

    public function connect(array $input, string $redirectUri): array
    {
        $short = $this->exchangeCode($input, $redirectUri);
        $long = Http::get('https://graph.threads.net/access_token', ['query' => [
            'grant_type' => 'th_exchange_token',
            'client_secret' => $this->clientSecret(),
            'access_token' => $short['access_token'],
        ]]);
        if (!Http::ok($long) || empty($long['json']['access_token'])) {
            throw new ProviderException('Threads: ' . $this->errorMessage($long));
        }
        $token = ['access_token' => $long['json']['access_token'], 'expires_at' => time() + (int) ($long['json']['expires_in'] ?? 5184000)];
        $this->account = ['token' => $token];
        $me = $this->graph('GET', '/me', ['fields' => 'id,username,name,threads_profile_picture_url']);
        return [[
            'provider_id' => (string) $me['id'],
            'name' => ($me['name'] ?? '') !== '' ? $me['name'] : $me['username'],
            'username' => $me['username'] ?? '',
            'avatar' => $me['threads_profile_picture_url'] ?? null,
            'token' => $token,
            'data' => [],
        ]];
    }

    public function refreshToken(): ?array
    {
        $token = $this->token();
        if (empty($token['expires_at']) || $token['expires_at'] > time() + 7 * 86400) {
            return null;
        }
        if ($token['expires_at'] < time()) {
            throw ProviderException::unauthorized('Threads access expired. Reconnect the account.');
        }
        $r = Http::get('https://graph.threads.net/refresh_access_token', ['query' => ['grant_type' => 'th_refresh_token', 'access_token' => $token['access_token']]]);
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
            $msg = 'Threads: ' . $this->errorMessage($res);
            throw $code === 190 ? ProviderException::unauthorized($msg) : new ProviderException($msg);
        }
        return $res['json'];
    }

    public function publish(string $text, array $media): array
    {
        $uid = $this->account['provider_id'];
        $media = array_slice($media, 0, 20);
        foreach ($media as &$m) {
            $m = MediaTools::jpeg($m, 8 * 1024 * 1024);
            MediaTools::assertPublicUrl($m['url'], 'Threads');
        }
        unset($m);

        if (!$media) {
            $container = (string) $this->graph('POST', "/$uid/threads", ['media_type' => 'TEXT', 'text' => $text])['id'];
        } elseif (count($media) === 1) {
            $container = $this->container($uid, $media[0], ['text' => $text]);
        } else {
            $children = array_map(fn($m) => $this->container($uid, $m, ['is_carousel_item' => 'true']), $media);
            foreach ($children as $c) {
                $this->waitReady($c);
            }
            $container = (string) $this->graph('POST', "/$uid/threads", ['media_type' => 'CAROUSEL', 'children' => implode(',', $children), 'text' => $text])['id'];
        }
        $this->waitReady($container);
        $id = (string) $this->graph('POST', "/$uid/threads_publish", ['creation_id' => $container])['id'];
        $permalink = null;
        try {
            $permalink = $this->graph('GET', "/$id", ['fields' => 'permalink'])['permalink'] ?? null;
        } catch (ProviderException) {
        }
        return ['id' => $id, 'url' => $permalink];
    }

    private function container(string $uid, array $m, array $extra): string
    {
        $params = MediaTools::isVideo($m) ? ['media_type' => 'VIDEO', 'video_url' => $m['url']] : ['media_type' => 'IMAGE', 'image_url' => $m['url']];
        return (string) $this->graph('POST', "/$uid/threads", $params + $extra)['id'];
    }

    private function waitReady(string $containerId): void
    {
        $this->poll(function () use ($containerId) {
            $s = $this->graph('GET', "/$containerId", ['fields' => 'status,error_message']);
            $status = $s['status'] ?? 'FINISHED';
            if ($status === 'ERROR' || $status === 'EXPIRED') {
                throw new ProviderException('Threads couldn’t process the media: ' . ($s['error_message'] ?? $status));
            }
            return in_array($status, ['FINISHED', 'PUBLISHED'], true) ? true : null;
        }, 300, 3);
    }

    public function accountMetrics(): array
    {
        $r = $this->graph('GET', '/' . $this->account['provider_id'] . '/threads_insights', ['metric' => 'followers_count']);
        foreach ($r['data'] ?? [] as $row) {
            if ($row['name'] === 'followers_count') {
                return ['followers' => (int) ($row['total_value']['value'] ?? 0)];
            }
        }
        return [];
    }

    public function audience(): array
    {
        $out = [];
        foreach (['age', 'gender', 'country', 'city'] as $dim) {
            try {
                $r = $this->graph('GET', '/' . $this->account['provider_id'] . '/threads_insights', ['metric' => 'follower_demographics', 'breakdown' => $dim]);
            } catch (ProviderException) {
                continue;
            }
            foreach ($r['data'][0]['total_value']['breakdowns'][0]['results'] ?? [] as $row) {
                $out[$dim][(string) ($row['dimension_values'][0] ?? '?')] = (int) ($row['value'] ?? 0);
            }
        }
        return $out;
    }

    public function postMetrics(string $providerPostId): array
    {
        $r = $this->graph('GET', '/' . $providerPostId . '/insights', ['metric' => 'views,likes,replies,reposts,quotes']);
        $v = [];
        foreach ($r['data'] ?? [] as $row) {
            $v[$row['name']] = (int) ($row['values'][0]['value'] ?? $row['total_value']['value'] ?? 0);
        }
        return $v ? [
            'likes' => $v['likes'] ?? 0,
            'replies' => $v['replies'] ?? 0,
            'shares' => ($v['reposts'] ?? 0) + ($v['quotes'] ?? 0),
            'impressions' => $v['views'] ?? 0,
        ] : [];
    }

    public static function supportsComments(): bool { return true; }

    public function comments(string $providerPostId): array
    {
        $r = $this->graph('GET', '/' . $providerPostId . '/replies', ['fields' => 'id,text,username,timestamp,permalink,is_reply_owned_by_me', 'reverse' => 'false']);
        $out = [];
        foreach ($r['data'] ?? [] as $c) {
            $out[] = [
                'id' => $c['id'],
                'text' => $c['text'] ?? '',
                'author_name' => $c['username'] ?? '',
                'author_handle' => $c['username'] ?? '',
                'author_avatar' => null,
                'created_at' => gmdate('Y-m-d H:i:s', strtotime($c['timestamp'] ?? 'now')),
                'url' => $c['permalink'] ?? null,
                'reply_ref' => $c['id'],
                'own' => !empty($c['is_reply_owned_by_me']),
            ];
        }
        return $out;
    }

    public function reply(array $item, string $text): array
    {
        $uid = $this->account['provider_id'];
        $container = (string) $this->graph('POST', "/$uid/threads", ['media_type' => 'TEXT', 'text' => $text, 'reply_to_id' => $item['provider_item_id']])['id'];
        $this->waitReady($container);
        $id = (string) $this->graph('POST', "/$uid/threads_publish", ['creation_id' => $container])['id'];
        return ['id' => $id, 'url' => null];
    }

    public static function supportsFirstComment(): bool { return true; }

    public function firstComment(string $providerPostId, string $text): void
    {
        $this->reply(['provider_item_id' => $providerPostId], $text);
    }
}
