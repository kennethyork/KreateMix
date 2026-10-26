<?php
declare(strict_types=1);

namespace App\Providers;

use App\Http;
use App\MediaTools;

/** LinkedIn personal profiles and (with Community Management API access) company pages. */
final class LinkedIn extends OAuth2Provider
{
    private const REST = 'https://api.linkedin.com/rest';
    /** Versions are supported for at least a year; bump this when LinkedIn sunsets it. */
    public const VERSION = '202610';

    public static function key(): string { return 'linkedin'; }
    public static function name(): string { return 'LinkedIn'; }
    public static function color(): string { return '#0a66c2'; }
    public static function textLimit(): int { return 3000; }
    public static function implemented(): bool { return true; }

    public static function mediaRules(): array
    {
        return ['max_media' => 20, 'video' => true, 'requires_media' => false, 'requires_video' => false];
    }

    public static function credentialFields(): array
    {
        return ['client_id' => 'Client ID', 'client_secret' => 'Client Secret'];
    }

    public static function connectFields(): array
    {
        return ['pages' => ['label' => 'Also connect company pages I manage (needs the Community Management API product)', 'type' => 'checkbox']];
    }

    public static function setupHint(): string
    {
        return 'At linkedin.com/developers create an app, add “Share on LinkedIn” and “Sign In with LinkedIn using OpenID Connect”, and add the callback URL below. For company pages, also get the Community Management API approved.';
    }

    protected function authorizeEndpoint(): string { return 'https://www.linkedin.com/oauth/v2/authorization'; }
    protected function tokenEndpoint(): string { return 'https://www.linkedin.com/oauth/v2/accessToken'; }

    protected function scopes(): array
    {
        $scopes = ['openid', 'profile', 'w_member_social'];
        if (!empty($this->stash['pages'])) {
            array_push($scopes, 'w_organization_social', 'r_organization_social', 'rw_organization_admin');
        }
        return $scopes;
    }

    public function authUrl(string $redirectUri, string $state, array $form = []): string
    {
        $this->stash['pages'] = !empty($form['pages']);
        return parent::authUrl($redirectUri, $state, $form);
    }

    private function headers(): array
    {
        return ['LinkedIn-Version' => self::VERSION, 'X-Restli-Protocol-Version' => '2.0.0'];
    }

    public function connect(array $input, string $redirectUri): array
    {
        $token = $this->exchangeCode($input, $redirectUri);
        $this->account = ['token' => $token];
        $me = $this->api('GET', 'https://api.linkedin.com/v2/userinfo')['json'];
        $accounts = [[
            'provider_id' => 'person:' . $me['sub'],
            'name' => $me['name'] ?? trim(($me['given_name'] ?? '') . ' ' . ($me['family_name'] ?? '')),
            'username' => 'Personal profile',
            'avatar' => $me['picture'] ?? null,
            'token' => $token,
            'data' => ['urn' => 'urn:li:person:' . $me['sub']],
        ]];

        if (!empty($this->stash['pages'])) {
            try {
                $acls = $this->api('GET', self::REST . '/organizationAcls', [
                    'query' => ['q' => 'roleAssignee', 'role' => 'ADMINISTRATOR', 'state' => 'APPROVED', 'count' => 100],
                    'headers' => $this->headers(),
                ])['json']['elements'] ?? [];
                foreach ($acls as $acl) {
                    $urn = $acl['organization'] ?? '';
                    $id = substr($urn, strrpos($urn, ':') + 1);
                    $org = $this->api('GET', self::REST . '/organizations/' . $id, ['headers' => $this->headers()])['json'];
                    $accounts[] = [
                        'provider_id' => 'organization:' . $id,
                        'name' => $org['localizedName'] ?? ('Organization ' . $id),
                        'username' => $org['vanityName'] ?? 'Company page',
                        'avatar' => null,
                        'token' => $token,
                        'data' => ['urn' => $urn],
                    ];
                }
            } catch (ProviderException $e) {
                // The app may not have Community Management API access; keep the personal profile.
                log_event('warning', 'LinkedIn company pages unavailable: ' . $e->getMessage());
            }
        }
        return $accounts;
    }

    private function urn(): string
    {
        return (string) ($this->accountData()['urn'] ?? '');
    }

    public function publish(string $text, array $media): array
    {
        $author = $this->urn();
        $post = [
            'author' => $author,
            'commentary' => self::littleText($text),
            'visibility' => 'PUBLIC',
            'distribution' => ['feedDistribution' => 'MAIN_FEED', 'targetEntities' => [], 'thirdPartyDistributionChannels' => []],
            'lifecycleState' => 'PUBLISHED',
            'isReshareDisabledByAuthor' => false,
        ];

        $videos = array_values(array_filter($media, [MediaTools::class, 'isVideo']));
        if ($videos) {
            $post['content'] = ['media' => ['id' => $this->uploadVideo($videos[0], $author), 'title' => '']];
        } elseif (count($media) === 1) {
            $post['content'] = ['media' => ['id' => $this->uploadImage($media[0], $author)]];
        } elseif ($media) {
            $images = array_map(fn($m) => ['id' => $this->uploadImage($m, $author)], array_slice($media, 0, 20));
            $post['content'] = ['multiImage' => ['images' => $images]];
        }

        $res = $this->api('POST', self::REST . '/posts', ['json' => $post, 'headers' => $this->headers()]);
        $id = $res['headers']['x-restli-id'] ?? $res['headers']['x-linkedin-id'] ?? '';
        return ['id' => $id, 'url' => $id ? 'https://www.linkedin.com/feed/update/' . $id . '/' : null];
    }

    private function uploadImage(array $m, string $owner): string
    {
        $m = MediaTools::fit($m, 30 * 1024 * 1024);
        $init = $this->api('POST', self::REST . '/images', [
            'query' => ['action' => 'initializeUpload'],
            'json' => ['initializeUploadRequest' => ['owner' => $owner]],
            'headers' => $this->headers(),
        ])['json']['value'];
        $this->api('PUT', $init['uploadUrl'], ['body' => file_get_contents($m['path']), 'headers' => ['Content-Type' => $m['mime']], 'timeout' => 300]);
        return $init['image'];
    }

    private function uploadVideo(array $m, string $owner): string
    {
        $init = $this->api('POST', self::REST . '/videos', [
            'query' => ['action' => 'initializeUpload'],
            'json' => ['initializeUploadRequest' => ['owner' => $owner, 'fileSizeBytes' => $m['size'], 'uploadCaptions' => false, 'uploadThumbnail' => false]],
            'headers' => $this->headers(),
        ])['json']['value'];

        $etags = [];
        $fh = fopen($m['path'], 'rb');
        foreach ($init['uploadInstructions'] as $part) {
            fseek($fh, (int) $part['firstByte']);
            $chunk = fread($fh, (int) $part['lastByte'] - (int) $part['firstByte'] + 1);
            $res = $this->api('PUT', $part['uploadUrl'], ['body' => $chunk, 'headers' => ['Content-Type' => 'application/octet-stream'], 'timeout' => 600]);
            $etags[] = trim($res['headers']['etag'] ?? '', '"');
        }
        fclose($fh);

        $this->api('POST', self::REST . '/videos', [
            'query' => ['action' => 'finalizeUpload'],
            'json' => ['finalizeUploadRequest' => ['video' => $init['video'], 'uploadToken' => $init['uploadToken'] ?? '', 'uploadedPartIds' => $etags]],
            'headers' => $this->headers(),
        ]);

        $video = $init['video'];
        $this->poll(function () use ($video) {
            $s = $this->api('GET', self::REST . '/videos/' . rawurlencode($video), ['headers' => $this->headers()])['json'];
            $status = $s['status'] ?? 'AVAILABLE';
            if ($status === 'PROCESSING_FAILED') {
                throw new ProviderException('LinkedIn couldn’t process the video.');
            }
            return $status === 'AVAILABLE' ? true : null;
        }, 600, 5);
        return $video;
    }

    /** LinkedIn "little text": escape reserved characters, keep #hashtags as hashtags. */
    public static function littleText(string $text): string
    {
        $out = '';
        $parts = preg_split('/((?<=^|\s)#[\p{L}\p{N}_]+)/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($parts as $part) {
            if (preg_match('/^#([\p{L}\p{N}_]+)$/u', $part, $m)) {
                $out .= '{hashtag|\\#|' . $m[1] . '}';
            } else {
                $out .= preg_replace('/([\\\\|{}@\[\]()<>#*_~])/u', '\\\\$1', $part);
            }
        }
        return $out;
    }

    public function accountMetrics(): array
    {
        $urn = $this->urn();
        if (!str_contains($urn, 'organization')) {
            return [];
        }
        $r = $this->api('GET', self::REST . '/networkSizes/' . rawurlencode($urn), ['query' => ['edgeType' => 'COMPANY_FOLLOWED_BY_MEMBER'], 'headers' => $this->headers()])['json'];
        return isset($r['firstDegreeSize']) ? ['followers' => (int) $r['firstDegreeSize']] : [];
    }

    public function postMetrics(string $providerPostId): array
    {
        $r = $this->api('GET', self::REST . '/socialActions/' . rawurlencode($providerPostId), ['headers' => $this->headers()])['json'];
        return [
            'likes' => (int) ($r['likesSummary']['totalLikes'] ?? 0),
            'replies' => (int) ($r['commentsSummary']['aggregatedTotalComments'] ?? 0),
        ];
    }

    public static function supportsFirstComment(): bool { return true; }

    public function firstComment(string $providerPostId, string $text): void
    {
        $this->api('POST', self::REST . '/socialActions/' . rawurlencode($providerPostId) . '/comments', [
            'json' => ['actor' => $this->urn(), 'object' => $providerPostId, 'message' => ['text' => $text]],
            'headers' => $this->headers(),
        ]);
    }
}
