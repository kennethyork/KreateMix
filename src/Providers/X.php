<?php
declare(strict_types=1);

namespace App\Providers;

use App\Http;

/** X (Twitter) API v2 with OAuth 2.0 PKCE and v2 chunked media upload. */
final class X extends OAuth2Provider
{
    private const API = 'https://api.x.com/2';
    private const CHUNK = 4 * 1024 * 1024;

    public static function key(): string { return 'x'; }
    public static function name(): string { return 'X'; }
    public static function color(): string { return '#000000'; }
    public static function textLimit(): int { return 280; }
    public static function implemented(): bool { return true; }

    public static function mediaRules(): array
    {
        return ['max_media' => 4, 'video' => true, 'requires_media' => false, 'requires_video' => false];
    }

    public static function credentialFields(): array
    {
        return ['client_id' => 'OAuth 2.0 Client ID', 'client_secret' => 'OAuth 2.0 Client Secret'];
    }

    public static function setupHint(): string
    {
        return 'At developer.x.com create an app, turn on OAuth 2.0 as a confidential “Web App” with Read and write permissions, and add the callback URL below. Posting needs an API plan with write access.';
    }

    protected function authorizeEndpoint(): string { return 'https://x.com/i/oauth2/authorize'; }
    protected function tokenEndpoint(): string { return self::API . '/oauth2/token'; }
    protected function scopes(): array { return ['tweet.read', 'tweet.write', 'users.read', 'media.write', 'offline.access']; }
    protected function usesPkce(): bool { return true; }
    protected function basicAuth(): bool { return true; }

    public function connect(array $input, string $redirectUri): array
    {
        $token = $this->exchangeCode($input, $redirectUri);
        $this->account = ['token' => $token];
        $me = $this->api('GET', self::API . '/users/me', ['query' => ['user.fields' => 'profile_image_url,public_metrics']])['json']['data'] ?? [];
        if (empty($me['id'])) {
            throw new ProviderException('X didn’t return the account details.');
        }
        return [[
            'provider_id' => (string) $me['id'],
            'name' => $me['name'] ?? $me['username'],
            'username' => $me['username'] ?? '',
            'avatar' => isset($me['profile_image_url']) ? str_replace('_normal.', '_400x400.', $me['profile_image_url']) : null,
            'token' => $token,
            'data' => [],
        ]];
    }

    public function publish(string $text, array $media): array
    {
        $body = ['text' => $text];
        if ($media) {
            $ids = [];
            foreach (array_slice($media, 0, 4) as $m) {
                $ids[] = $this->upload($m);
            }
            $body['media'] = ['media_ids' => $ids];
        }
        $res = $this->api('POST', self::API . '/tweets', ['json' => $body]);
        $id = (string) ($res['json']['data']['id'] ?? '');
        $username = $this->account['username'] ?? 'i';
        return ['id' => $id, 'url' => $id ? "https://x.com/{$username}/status/{$id}" : null];
    }

    private function upload(array $m): string
    {
        $isVideo = str_starts_with($m['mime'], 'video/');
        $category = $isVideo ? 'tweet_video' : ($m['mime'] === 'image/gif' ? 'tweet_gif' : 'tweet_image');
        $init = $this->api('POST', self::API . '/media/upload/initialize', ['json' => [
            'media_type' => $m['mime'] === 'video/quicktime' ? 'video/mp4' : $m['mime'],
            'total_bytes' => $m['size'],
            'media_category' => $category,
        ]]);
        $id = (string) ($init['json']['data']['id'] ?? '');
        if ($id === '') {
            throw new ProviderException('X didn’t accept the media upload.');
        }

        $fh = fopen($m['path'], 'rb');
        $segment = 0;
        while (!feof($fh)) {
            $chunk = fread($fh, self::CHUNK);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $this->api('POST', self::API . "/media/upload/$id/append", [
                'multipart' => ['segment_index' => (string) $segment, 'media' => new \CURLStringFile($chunk, 'chunk', 'application/octet-stream')],
                'timeout' => 300,
            ]);
            $segment++;
        }
        fclose($fh);

        $fin = $this->api('POST', self::API . "/media/upload/$id/finalize");
        $info = $fin['json']['data']['processing_info'] ?? null;
        if ($info && in_array($info['state'] ?? '', ['pending', 'in_progress'], true)) {
            $this->poll(function () use ($id) {
                $s = $this->api('GET', self::API . '/media/upload', ['query' => ['command' => 'STATUS', 'media_id' => $id]]);
                $state = $s['json']['data']['processing_info']['state'] ?? 'succeeded';
                if ($state === 'failed') {
                    $err = $s['json']['data']['processing_info']['error']['message'] ?? 'processing failed';
                    throw new ProviderException('X couldn’t process the media: ' . $err);
                }
                return $state === 'succeeded' ? true : null;
            }, 600, 5);
        }
        return $id;
    }

    public function accountMetrics(): array
    {
        $me = $this->api('GET', self::API . '/users/me', ['query' => ['user.fields' => 'public_metrics']])['json']['data'] ?? [];
        $pm = $me['public_metrics'] ?? [];
        return isset($pm['followers_count']) ? ['followers' => (int) $pm['followers_count'], 'following' => (int) ($pm['following_count'] ?? 0)] : [];
    }

    public function postMetrics(string $providerPostId): array
    {
        $t = $this->api('GET', self::API . '/tweets/' . rawurlencode($providerPostId), ['query' => ['tweet.fields' => 'public_metrics']])['json']['data'] ?? [];
        $pm = $t['public_metrics'] ?? [];
        return $pm ? [
            'likes' => (int) ($pm['like_count'] ?? 0),
            'replies' => (int) ($pm['reply_count'] ?? 0),
            'shares' => (int) ($pm['retweet_count'] ?? 0) + (int) ($pm['quote_count'] ?? 0),
            'impressions' => (int) ($pm['impression_count'] ?? 0),
        ] : [];
    }
}
