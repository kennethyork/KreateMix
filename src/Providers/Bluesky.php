<?php
declare(strict_types=1);

namespace App\Providers;

use App\Http;
use App\MediaTools;

/** Bluesky / AT Protocol with app passwords. */
final class Bluesky extends Provider
{
    private const PUBLIC_API = 'https://public.api.bsky.app';
    private const VIDEO_API = 'https://video.bsky.app';
    private const MAX_IMAGE_BYTES = 976560;

    private ?array $session = null;

    public static function key(): string { return 'bluesky'; }
    public static function name(): string { return 'Bluesky'; }
    public static function color(): string { return '#1185fe'; }
    public static function textLimit(): int { return 300; }
    public static function implemented(): bool { return true; }
    public static function usesOAuth(): bool { return false; }

    public static function mediaRules(): array
    {
        return ['max_media' => 4, 'video' => true, 'requires_media' => false, 'requires_video' => false];
    }

    public static function connectFields(): array
    {
        return [
            'handle' => ['label' => 'Handle', 'type' => 'text', 'placeholder' => 'you.bsky.social'],
            'app_password' => ['label' => 'App password', 'type' => 'password', 'placeholder' => 'xxxx-xxxx-xxxx-xxxx'],
            'service' => ['label' => 'Hosting provider (leave blank for bsky.social)', 'type' => 'text', 'placeholder' => 'https://bsky.social'],
        ];
    }

    public static function setupHint(): string
    {
        return 'No keys needed. Create an app password in Bluesky under Settings → Privacy and security → App passwords. Don’t use your main password.';
    }

    public function connect(array $input, string $redirectUri): array
    {
        $handle = ltrim(trim((string) ($input['handle'] ?? '')), '@');
        $password = trim((string) ($input['app_password'] ?? ''));
        $service = rtrim(trim((string) ($input['service'] ?? '')), '/') ?: 'https://bsky.social';
        if (!preg_match('#^https://#', $service)) {
            $service = 'https://' . preg_replace('#^https?://#', '', $service);
        }
        if ($handle === '' || $password === '') {
            throw new ProviderException('Enter your handle and an app password.');
        }
        $session = $this->createSession($service, $handle, $password);
        $profile = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.actor.getProfile', ['query' => ['actor' => $session['did']]])['json'];
        return [[
            'provider_id' => $session['did'],
            'name' => ($profile['displayName'] ?? '') !== '' ? $profile['displayName'] : $session['handle'],
            'username' => $session['handle'],
            'avatar' => $profile['avatar'] ?? null,
            'token' => ['identifier' => $handle, 'app_password' => $password, 'service' => $service],
            'data' => ['did' => $session['did'], 'pds' => $session['pds']],
        ]];
    }

    private function createSession(string $service, string $identifier, string $password): array
    {
        $res = Http::post($service . '/xrpc/com.atproto.server.createSession', ['json' => ['identifier' => $identifier, 'password' => $password]]);
        if (!Http::ok($res) || empty($res['json']['accessJwt'])) {
            $msg = $res['json']['message'] ?? ('HTTP ' . $res['status']);
            $e = new ProviderException('Bluesky sign-in failed: ' . $msg);
            $e->unauthorized = in_array($res['status'], [400, 401], true);
            throw $e;
        }
        $j = $res['json'];
        // The user's actual PDS, needed for video service auth.
        $pds = $service;
        foreach ($j['didDoc']['service'] ?? [] as $svc) {
            if (($svc['id'] ?? '') === '#atproto_pds' && !empty($svc['serviceEndpoint'])) {
                $pds = rtrim($svc['serviceEndpoint'], '/');
            }
        }
        return ['access' => $j['accessJwt'], 'did' => $j['did'], 'handle' => $j['handle'], 'pds' => $pds, 'service' => $service];
    }

    private function session(): array
    {
        if ($this->session === null) {
            $t = $this->token();
            $this->session = $this->createSession($t['service'] ?? 'https://bsky.social', (string) ($t['identifier'] ?? ''), (string) ($t['app_password'] ?? ''));
        }
        return $this->session;
    }

    private function xrpc(string $method, string $nsid, array $options = []): array
    {
        $s = $this->session();
        $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer ' . $s['access']];
        $res = Http::request($method, $s['pds'] . '/xrpc/' . $nsid, $options);
        if (!Http::ok($res)) {
            throw new ProviderException('Bluesky: ' . ($res['json']['message'] ?? $res['json']['error'] ?? 'HTTP ' . $res['status']));
        }
        return $res['json'];
    }

    public function publish(string $text, array $media): array
    {
        $s = $this->session();
        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'langs' => ['en'],
        ];
        $facets = $this->facets($text);
        if ($facets) {
            $record['facets'] = $facets;
        }

        $videos = array_values(array_filter($media, [MediaTools::class, 'isVideo']));
        if ($videos) {
            $v = $videos[0];
            $embed = ['$type' => 'app.bsky.embed.video', 'video' => $this->uploadVideo($v)];
            if ($v['width'] && $v['height']) {
                $embed['aspectRatio'] = ['width' => $v['width'], 'height' => $v['height']];
            }
            $record['embed'] = $embed;
        } elseif ($media) {
            $images = [];
            foreach (array_slice($media, 0, 4) as $m) {
                $img = MediaTools::fit($m, self::MAX_IMAGE_BYTES, 2000);
                $blob = $this->xrpc('POST', 'com.atproto.repo.uploadBlob', [
                    'body' => file_get_contents($img['path']),
                    'headers' => ['Content-Type' => $img['mime']],
                    'timeout' => 120,
                ])['blob'];
                $entry = ['alt' => '', 'image' => $blob];
                if ($img['width'] && $img['height']) {
                    $entry['aspectRatio'] = ['width' => $img['width'], 'height' => $img['height']];
                }
                $images[] = $entry;
            }
            $record['embed'] = ['$type' => 'app.bsky.embed.images', 'images' => $images];
        }

        $res = $this->xrpc('POST', 'com.atproto.repo.createRecord', ['json' => [
            'repo' => $s['did'],
            'collection' => 'app.bsky.feed.post',
            'record' => $record,
        ]]);
        $uri = (string) $res['uri'];
        $rkey = substr($uri, strrpos($uri, '/') + 1);
        return ['id' => $uri, 'url' => 'https://bsky.app/profile/' . $s['handle'] . '/post/' . $rkey];
    }

    /** Uploads through Bluesky's video service, falling back to a plain blob upload. */
    private function uploadVideo(array $m): array
    {
        $s = $this->session();
        try {
            $pdsHost = parse_url($s['pds'], PHP_URL_HOST);
            $auth = $this->xrpc('GET', 'com.atproto.server.getServiceAuth', ['query' => [
                'aud' => 'did:web:' . $pdsHost,
                'lxm' => 'com.atproto.repo.uploadBlob',
                'exp' => time() + 1800,
            ]])['token'];
            $res = Http::post(self::VIDEO_API . '/xrpc/app.bsky.video.uploadVideo', [
                'query' => ['did' => $s['did'], 'name' => bin2hex(random_bytes(6)) . '.mp4'],
                'body' => file_get_contents($m['path']),
                'headers' => ['Authorization' => 'Bearer ' . $auth, 'Content-Type' => 'video/mp4'],
                'timeout' => 600,
            ]);
            $job = $res['json']['jobId'] ?? $res['json']['jobStatus']['jobId'] ?? null;
            if (!$job) {
                throw new ProviderException('Bluesky video upload: ' . ($res['json']['message'] ?? 'HTTP ' . $res['status']));
            }
            $deadline = time() + 600;
            while (time() < $deadline) {
                $st = Http::get(self::VIDEO_API . '/xrpc/app.bsky.video.getJobStatus', ['query' => ['jobId' => $job]])['json']['jobStatus'] ?? [];
                if (($st['state'] ?? '') === 'JOB_STATE_COMPLETED' && !empty($st['blob'])) {
                    return $st['blob'];
                }
                if (($st['state'] ?? '') === 'JOB_STATE_FAILED') {
                    throw new ProviderException('Bluesky couldn’t process the video: ' . ($st['error'] ?? $st['message'] ?? 'unknown error'));
                }
                sleep(3);
            }
            throw new ProviderException('Bluesky is still processing the video.');
        } catch (ProviderException $e) {
            if (str_contains($e->getMessage(), 'process')) {
                throw $e;
            }
            return $this->xrpc('POST', 'com.atproto.repo.uploadBlob', [
                'body' => file_get_contents($m['path']),
                'headers' => ['Content-Type' => 'video/mp4'],
                'timeout' => 600,
            ])['blob'];
        }
    }

    /** Rich-text facets for links, hashtags and mentions. Offsets are UTF-8 bytes. */
    private function facets(string $text): array
    {
        $facets = [];
        if (preg_match_all('#https?://[^\s<>"]+[^\s<>".,;:!?)\]]#u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as [$url, $start]) {
                $facets[] = ['index' => ['byteStart' => $start, 'byteEnd' => $start + strlen($url)], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => $url]]];
            }
        }
        if (preg_match_all('/(?<=^|\s)#([\p{L}\p{N}_]*\p{L}[\p{L}\p{N}_]*)/u', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => [$tag, $start]) {
                $facets[] = ['index' => ['byteStart' => $start, 'byteEnd' => $start + strlen($tag)], 'features' => [['$type' => 'app.bsky.richtext.facet#tag', 'tag' => $m[1][$i][0]]]];
            }
        }
        if (preg_match_all('/(?<=^|\s)@([a-z0-9][a-z0-9.-]*\.[a-z]{2,})/iu', $text, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[0] as $i => [$mention, $start]) {
                $r = Http::get(self::PUBLIC_API . '/xrpc/com.atproto.identity.resolveHandle', ['query' => ['handle' => $m[1][$i][0]]]);
                if (!empty($r['json']['did'])) {
                    $facets[] = ['index' => ['byteStart' => $start, 'byteEnd' => $start + strlen($mention)], 'features' => [['$type' => 'app.bsky.richtext.facet#mention', 'did' => $r['json']['did']]]];
                }
            }
        }
        return $facets;
    }

    public function accountMetrics(): array
    {
        $did = $this->accountData()['did'] ?? $this->account['provider_id'] ?? '';
        $p = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.actor.getProfile', ['query' => ['actor' => $did]])['json'];
        return isset($p['followersCount']) ? ['followers' => (int) $p['followersCount'], 'following' => (int) ($p['followsCount'] ?? 0)] : [];
    }

    public function postMetrics(string $providerPostId): array
    {
        $r = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.feed.getPosts', ['query' => ['uris' => $providerPostId]])['json'];
        $p = $r['posts'][0] ?? null;
        return $p ? [
            'likes' => (int) ($p['likeCount'] ?? 0),
            'replies' => (int) ($p['replyCount'] ?? 0),
            'shares' => (int) ($p['repostCount'] ?? 0) + (int) ($p['quoteCount'] ?? 0),
        ] : [];
    }
}
