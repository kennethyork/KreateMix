<?php
declare(strict_types=1);

namespace App\Providers;

use App\MediaTools;

/** TikTok Content Posting API (direct post). */
final class TikTok extends OAuth2Provider
{
    private const API = 'https://open.tiktokapis.com/v2';
    private const MIN_CHUNK = 5 * 1024 * 1024;
    private const CHUNK = 10 * 1024 * 1024;

    public static function key(): string { return 'tiktok'; }
    public static function name(): string { return 'TikTok'; }
    public static function color(): string { return '#000000'; }
    public static function textLimit(): int { return 2200; }
    public static function implemented(): bool { return true; }

    public static function mediaRules(): array
    {
        return ['max_media' => 35, 'video' => true, 'requires_media' => true, 'requires_video' => false];
    }

    public static function credentialFields(): array
    {
        return ['client_key' => 'Client Key', 'client_secret' => 'Client Secret'];
    }

    public static function setupHint(): string
    {
        return 'At developers.tiktok.com create an app with Login Kit and the Content Posting API (Direct Post on), request user.info.basic, user.info.stats, video.publish and video.list, and add the callback URL below. Photo posts also need your domain verified under URL properties. Until TikTok audits the app, posts are only visible to the account owner.';
    }

    protected function authorizeEndpoint(): string { return 'https://www.tiktok.com/v2/auth/authorize/'; }
    protected function tokenEndpoint(): string { return self::API . '/oauth/token/'; }
    protected function scopes(): array { return ['user.info.basic', 'user.info.stats', 'video.publish', 'video.list']; }
    protected function scopeSeparator(): string { return ','; }
    protected function clientIdParam(): string { return 'client_key'; }

    public function connect(array $input, string $redirectUri): array
    {
        $token = $this->exchangeCode($input, $redirectUri);
        $this->account = ['token' => $token];
        $u = $this->api('GET', self::API . '/user/info/', ['query' => ['fields' => 'open_id,avatar_url,display_name,username']])['json']['data']['user'] ?? [];
        return [[
            'provider_id' => (string) ($u['open_id'] ?? $token['open_id'] ?? ''),
            'name' => $u['display_name'] ?? 'TikTok account',
            'username' => $u['username'] ?? '',
            'avatar' => $u['avatar_url'] ?? null,
            'token' => $token,
            'data' => [],
        ]];
    }

    protected function normalizeToken(array $json, array $previous = []): array
    {
        $t = parent::normalizeToken($json, $previous);
        if (!empty($json['open_id'])) {
            $t['open_id'] = $json['open_id'];
        }
        return $t;
    }

    /** TikTok wraps errors in error.code even on 200. */
    private function call(string $url, array $body): array
    {
        $res = $this->api('POST', $url, ['json' => $body, 'headers' => ['Content-Type' => 'application/json; charset=UTF-8']]);
        $code = $res['json']['error']['code'] ?? 'ok';
        if ($code !== 'ok') {
            $msg = 'TikTok: ' . ($res['json']['error']['message'] ?? $code) . " ($code)";
            throw in_array($code, ['access_token_invalid', 'scope_not_authorized'], true) ? ProviderException::unauthorized($msg) : new ProviderException($msg);
        }
        return $res['json']['data'] ?? [];
    }

    private function privacyLevel(): string
    {
        $info = $this->call(self::API . '/post/publish/creator_info/query/', []);
        $options = $info['privacy_level_options'] ?? [];
        foreach (['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY'] as $level) {
            if (in_array($level, $options, true)) {
                return $level;
            }
        }
        return 'SELF_ONLY';
    }

    public function publish(string $text, array $media): array
    {
        $privacy = $this->privacyLevel();
        $videos = array_values(array_filter($media, [MediaTools::class, 'isVideo']));

        if ($videos) {
            $v = $videos[0];
            $size = (int) $v['size'];
            if ($size <= 64 * 1024 * 1024) {
                $chunk = $size;
                $count = 1;
            } else {
                $chunk = self::CHUNK;
                $count = intdiv($size, $chunk); // last chunk absorbs the remainder
            }
            $data = $this->call(self::API . '/post/publish/video/init/', [
                'post_info' => ['title' => mb_substr($text, 0, 2200), 'privacy_level' => $privacy, 'disable_duet' => false, 'disable_stitch' => false, 'disable_comment' => false],
                'source_info' => ['source' => 'FILE_UPLOAD', 'video_size' => $size, 'chunk_size' => $chunk, 'total_chunk_count' => $count],
            ]);
            $fh = fopen($v['path'], 'rb');
            for ($i = 0; $i < $count; $i++) {
                $start = $i * $chunk;
                $len = $i === $count - 1 ? $size - $start : $chunk;
                fseek($fh, $start);
                $bytes = fread($fh, $len);
                $up = \App\Http::request('PUT', $data['upload_url'], [
                    'body' => $bytes,
                    'headers' => ['Content-Type' => $v['mime'], 'Content-Range' => sprintf('bytes %d-%d/%d', $start, $start + $len - 1, $size)],
                    'timeout' => 600,
                ]);
                if (!\App\Http::ok($up)) {
                    throw new ProviderException('TikTok upload failed: ' . $this->errorMessage($up));
                }
            }
            fclose($fh);
        } else {
            $urls = [];
            foreach (array_slice($media, 0, 35) as $m) {
                $m = MediaTools::jpeg($m, 20 * 1024 * 1024, 1920);
                MediaTools::assertPublicUrl($m['url'], 'TikTok');
                $urls[] = $m['url'];
            }
            [$title, $description] = self::splitTitle($text, 90);
            $data = $this->call(self::API . '/post/publish/content/init/', [
                'post_info' => ['title' => $title, 'description' => mb_substr($description !== '' ? $description : $text, 0, 4000), 'privacy_level' => $privacy, 'disable_comment' => false, 'auto_add_music' => true],
                'source_info' => ['source' => 'PULL_FROM_URL', 'photo_cover_index' => 0, 'photo_images' => $urls],
                'post_mode' => 'DIRECT_POST',
                'media_type' => 'PHOTO',
            ]);
        }

        $publishId = (string) $data['publish_id'];
        $postId = $this->poll(function () use ($publishId) {
            $s = $this->call(self::API . '/post/publish/status/fetch/', ['publish_id' => $publishId]);
            $status = $s['status'] ?? '';
            if ($status === 'FAILED') {
                throw new ProviderException('TikTok rejected the post: ' . ($s['fail_reason'] ?? 'unknown reason'));
            }
            if ($status === 'PUBLISH_COMPLETE') {
                return (string) ($s['publicaly_available_post_id'][0] ?? $publishId);
            }
            // SEND_TO_USER_INBOX / private posts don't get a public id; treat as done.
            return $status === 'SEND_TO_USER_INBOX' ? $publishId : null;
        }, 600, 5);

        $username = $this->account['username'] ?? '';
        $url = ($postId !== $publishId && $username) ? "https://www.tiktok.com/@{$username}/video/{$postId}" : null;
        return ['id' => $postId, 'url' => $url];
    }

    public function accountMetrics(): array
    {
        $u = $this->api('GET', self::API . '/user/info/', ['query' => ['fields' => 'follower_count,following_count,likes_count']])['json']['data']['user'] ?? [];
        return isset($u['follower_count']) ? ['followers' => (int) $u['follower_count'], 'following' => (int) ($u['following_count'] ?? 0)] : [];
    }

    public function postMetrics(string $providerPostId): array
    {
        if (!ctype_digit($providerPostId)) {
            return [];
        }
        $res = $this->api('POST', self::API . '/video/query/', [
            'query' => ['fields' => 'id,like_count,comment_count,share_count,view_count'],
            'json' => ['filters' => ['video_ids' => [$providerPostId]]],
        ]);
        $v = $res['json']['data']['videos'][0] ?? [];
        return $v ? [
            'likes' => (int) ($v['like_count'] ?? 0),
            'replies' => (int) ($v['comment_count'] ?? 0),
            'shares' => (int) ($v['share_count'] ?? 0),
            'impressions' => (int) ($v['view_count'] ?? 0),
        ] : [];
    }
}
