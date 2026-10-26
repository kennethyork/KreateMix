<?php
declare(strict_types=1);

namespace App\Providers;

use App\Http;
use App\MediaTools;

/** YouTube uploads via the Data API v3. First line of the post is the title. */
final class YouTube extends OAuth2Provider
{
    private const API = 'https://www.googleapis.com/youtube/v3';

    public static function key(): string { return 'youtube'; }
    public static function name(): string { return 'YouTube'; }
    public static function color(): string { return '#ff0000'; }
    public static function textLimit(): int { return 5000; }
    public static function implemented(): bool { return true; }

    public static function mediaRules(): array
    {
        return ['max_media' => 1, 'video' => true, 'requires_media' => true, 'requires_video' => true];
    }

    public static function credentialFields(): array
    {
        return ['client_id' => 'Google OAuth Client ID', 'client_secret' => 'Google OAuth Client Secret'];
    }

    public static function setupHint(): string
    {
        return 'In Google Cloud Console enable “YouTube Data API v3”, set up the OAuth consent screen, create an OAuth client of type Web application, and add the callback URL below. The first line of a post becomes the video title (max 100 characters); the rest is the description. Unverified apps upload as private until Google audits them.';
    }

    protected function authorizeEndpoint(): string { return 'https://accounts.google.com/o/oauth2/v2/auth'; }
    protected function tokenEndpoint(): string { return 'https://oauth2.googleapis.com/token'; }
    protected function scopes(): array { return ['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly', 'https://www.googleapis.com/auth/youtube.force-ssl']; }
    protected function extraAuthParams(): array { return ['access_type' => 'offline', 'prompt' => 'consent', 'include_granted_scopes' => 'true']; }

    public function connect(array $input, string $redirectUri): array
    {
        $token = $this->exchangeCode($input, $redirectUri);
        $this->account = ['token' => $token];
        $channels = $this->api('GET', self::API . '/channels', ['query' => ['part' => 'snippet', 'mine' => 'true']])['json']['items'] ?? [];
        if (!$channels) {
            throw new ProviderException('This Google account has no YouTube channel.');
        }
        return array_map(fn($c) => [
            'provider_id' => $c['id'],
            'name' => $c['snippet']['title'] ?? 'YouTube channel',
            'username' => ltrim($c['snippet']['customUrl'] ?? '', '@'),
            'avatar' => $c['snippet']['thumbnails']['high']['url'] ?? $c['snippet']['thumbnails']['default']['url'] ?? null,
            'token' => $token,
            'data' => [],
        ], $channels);
    }

    public function publish(string $text, array $media): array
    {
        $videos = array_values(array_filter($media, [MediaTools::class, 'isVideo']));
        if (!$videos) {
            throw new ProviderException('YouTube posts need a video.');
        }
        $v = $videos[0];
        [$title, $description] = self::splitTitle($text, 100);
        $title = str_replace(['<', '>'], '', $title) ?: pathinfo($v['name'], PATHINFO_FILENAME);

        $init = $this->api('POST', 'https://www.googleapis.com/upload/youtube/v3/videos', [
            'query' => ['uploadType' => 'resumable', 'part' => 'snippet,status'],
            'json' => [
                'snippet' => ['title' => $title, 'description' => str_replace(['<', '>'], '', $description), 'categoryId' => '22'],
                'status' => ['privacyStatus' => 'public', 'selfDeclaredMadeForKids' => false],
            ],
            'headers' => ['X-Upload-Content-Length' => (string) $v['size'], 'X-Upload-Content-Type' => $v['mime']],
        ]);
        $uploadUrl = $init['headers']['location'] ?? '';
        if ($uploadUrl === '') {
            throw new ProviderException('YouTube didn’t return an upload URL.');
        }
        $res = $this->api('PUT', $uploadUrl, [
            'body' => file_get_contents($v['path']),
            'headers' => ['Content-Type' => $v['mime']],
            'timeout' => 1800,
        ]);
        $id = (string) ($res['json']['id'] ?? '');
        return ['id' => $id, 'url' => $id ? 'https://www.youtube.com/watch?v=' . $id : null];
    }

    public function accountMetrics(): array
    {
        $c = $this->api('GET', self::API . '/channels', ['query' => ['part' => 'statistics', 'id' => $this->account['provider_id']]])['json']['items'][0] ?? [];
        $s = $c['statistics'] ?? [];
        return isset($s['subscriberCount']) ? ['followers' => (int) $s['subscriberCount'], 'views' => (int) ($s['viewCount'] ?? 0)] : [];
    }

    public function postMetrics(string $providerPostId): array
    {
        $v = $this->api('GET', self::API . '/videos', ['query' => ['part' => 'statistics', 'id' => $providerPostId]])['json']['items'][0] ?? [];
        $s = $v['statistics'] ?? [];
        return $s ? [
            'likes' => (int) ($s['likeCount'] ?? 0),
            'replies' => (int) ($s['commentCount'] ?? 0),
            'impressions' => (int) ($s['viewCount'] ?? 0),
        ] : [];
    }

    public static function supportsComments(): bool { return true; }

    public function comments(string $providerPostId): array
    {
        $r = $this->api('GET', self::API . '/commentThreads', ['query' => ['part' => 'snippet,replies', 'videoId' => $providerPostId, 'maxResults' => 100, 'order' => 'time', 'textFormat' => 'plainText']])['json'];
        $me = $this->account['provider_id'] ?? '';
        $out = [];
        $add = function (array $c, string $threadId) use (&$out, $me, $providerPostId) {
            $sn = $c['snippet'] ?? [];
            $out[] = [
                'id' => $c['id'],
                'text' => $sn['textDisplay'] ?? $sn['textOriginal'] ?? '',
                'author_name' => $sn['authorDisplayName'] ?? '',
                'author_handle' => ltrim((string) ($sn['authorDisplayName'] ?? ''), '@'),
                'author_avatar' => $sn['authorProfileImageUrl'] ?? null,
                'created_at' => gmdate('Y-m-d H:i:s', strtotime($sn['publishedAt'] ?? 'now')),
                'url' => 'https://www.youtube.com/watch?v=' . $providerPostId . '&lc=' . $c['id'],
                'reply_ref' => $threadId,
                'own' => ($sn['authorChannelId']['value'] ?? '') === $me,
            ];
        };
        foreach ($r['items'] ?? [] as $t) {
            $add($t['snippet']['topLevelComment'] ?? [], $t['id']);
            foreach ($t['replies']['comments'] ?? [] as $rep) {
                $add($rep, $t['id']);
            }
        }
        usort($out, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
        return $out;
    }

    public function reply(array $item, string $text): array
    {
        $r = $this->api('POST', self::API . '/comments', ['query' => ['part' => 'snippet'], 'json' => ['snippet' => ['parentId' => $item['reply_ref'] ?: $item['provider_item_id'], 'textOriginal' => $text]]])['json'];
        return ['id' => (string) ($r['id'] ?? ''), 'url' => null];
    }
}
