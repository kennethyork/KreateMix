<?php
declare(strict_types=1);

namespace App\Providers;

use App\Crypt;
use App\Db;
use App\Http;

/** Mastodon (and compatible servers). Registers an app on each server automatically. */
final class Mastodon extends OAuth2Provider
{
    private string $server = '';

    public static function key(): string { return 'mastodon'; }
    public static function name(): string { return 'Mastodon'; }
    public static function color(): string { return '#6364ff'; }
    public static function textLimit(): int { return 500; }
    public static function implemented(): bool { return true; }

    public static function mediaRules(): array
    {
        return ['max_media' => 4, 'video' => true, 'requires_media' => false, 'requires_video' => false];
    }

    public static function connectFields(): array
    {
        return ['server' => ['label' => 'Server', 'type' => 'text', 'placeholder' => 'mastodon.social']];
    }

    public static function setupHint(): string
    {
        return 'No keys needed. MixCreate registers itself on each Mastodon server the first time someone connects an account there.';
    }

    protected function authorizeEndpoint(): string { return $this->base() . '/oauth/authorize'; }
    protected function tokenEndpoint(): string { return $this->base() . '/oauth/token'; }
    protected function scopes(): array { return ['read', 'write']; }

    private function base(): string
    {
        $server = $this->server ?: (string) ($this->accountData()['server'] ?? '');
        return 'https://' . $server;
    }

    private static function cleanServer(string $raw): string
    {
        $raw = strtolower(trim($raw));
        $raw = preg_replace('#^https?://#', '', $raw);
        $raw = preg_replace('#^@?[^@]+@#', '', $raw); // allow "@me@server"
        $host = explode('/', $raw)[0];
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)) {
            throw new ProviderException('Enter the server’s domain, like mastodon.social.');
        }
        return $host;
    }

    /** Gets or registers this install's app on the given server. */
    private function appFor(string $server, string $redirectUri): array
    {
        $key = 'mastodon@' . $server;
        $row = Db::one('SELECT credentials FROM services WHERE provider = ?', [$key]);
        $app = Crypt::decryptArray($row['credentials'] ?? null);
        if (!empty($app['client_id']) && ($app['redirect_uri'] ?? '') === $redirectUri) {
            return $app;
        }
        $res = Http::post("https://$server/api/v1/apps", ['form' => [
            'client_name' => \App\Config::get('app_name', 'MixCreate'),
            'redirect_uris' => $redirectUri,
            'scopes' => 'read write',
            'website' => abs_url(''),
        ]]);
        if (!Http::ok($res) || empty($res['json']['client_id'])) {
            throw new ProviderException("Couldn’t register with $server. Check the server name. (" . $this->errorMessage($res) . ')');
        }
        $app = ['client_id' => $res['json']['client_id'], 'client_secret' => $res['json']['client_secret'], 'redirect_uri' => $redirectUri];
        Db::query(
            'INSERT INTO services (provider, credentials, active, updated_at) VALUES (?, ?, 1, ?)
             ON CONFLICT(provider) DO UPDATE SET credentials = excluded.credentials, updated_at = excluded.updated_at',
            [$key, Crypt::encrypt($app), now()]
        );
        return $app;
    }

    public function authUrl(string $redirectUri, string $state, array $form = []): string
    {
        $this->server = self::cleanServer((string) ($form['server'] ?? ''));
        $app = $this->appFor($this->server, $redirectUri);
        $this->credentials = $app;
        $this->stash['server'] = $this->server;
        return parent::authUrl($redirectUri, $state, $form);
    }

    public function connect(array $input, string $redirectUri): array
    {
        $this->server = (string) ($this->stash['server'] ?? '');
        $this->credentials = $this->appFor($this->server, $redirectUri);
        $token = $this->exchangeCode($input, $redirectUri);
        $this->account = ['token' => $token];
        $me = $this->api('GET', $this->base() . '/api/v1/accounts/verify_credentials')['json'];
        return [[
            'provider_id' => $this->server . ':' . $me['id'],
            'name' => ($me['display_name'] ?? '') !== '' ? $me['display_name'] : $me['username'],
            'username' => $me['username'] . '@' . $this->server,
            'avatar' => $me['avatar'] ?? null,
            'token' => $token,
            'data' => ['server' => $this->server, 'account_id' => (string) $me['id']],
        ]];
    }

    public function publish(string $text, array $media): array
    {
        $ids = [];
        foreach (array_slice($media, 0, 4) as $m) {
            $ids[] = $this->upload($m);
        }
        $res = $this->api('POST', $this->base() . '/api/v1/statuses', [
            'json' => ['status' => $text, 'media_ids' => $ids, 'visibility' => 'public'],
            'headers' => ['Idempotency-Key' => hash('sha256', $text . implode(',', $ids) . ($this->account['id'] ?? ''))],
        ]);
        return ['id' => (string) $res['json']['id'], 'url' => $res['json']['url'] ?? null];
    }

    private function upload(array $m): string
    {
        $res = $this->api('POST', $this->base() . '/api/v2/media', [
            'multipart' => ['file' => new \CURLFile($m['path'], $m['mime'], $m['name'])],
            'timeout' => 300,
        ]);
        $id = (string) $res['json']['id'];
        if ($res['status'] === 202 || empty($res['json']['url'])) {
            $this->poll(function () use ($id) {
                $r = Http::get($this->base() . '/api/v1/media/' . $id, ['headers' => ['Authorization' => 'Bearer ' . $this->accessToken()]]);
                return ($r['status'] === 200 && !empty($r['json']['url'])) ? true : null;
            }, 300, 3);
        }
        return $id;
    }

    public function accountMetrics(): array
    {
        $me = $this->api('GET', $this->base() . '/api/v1/accounts/verify_credentials')['json'];
        return ['followers' => (int) ($me['followers_count'] ?? 0), 'following' => (int) ($me['following_count'] ?? 0)];
    }

    public function postMetrics(string $providerPostId): array
    {
        $s = $this->api('GET', $this->base() . '/api/v1/statuses/' . rawurlencode($providerPostId))['json'];
        return ['likes' => (int) ($s['favourites_count'] ?? 0), 'replies' => (int) ($s['replies_count'] ?? 0), 'shares' => (int) ($s['reblogs_count'] ?? 0)];
    }

    public static function supportsComments(): bool { return true; }

    public function comments(string $providerPostId): array
    {
        $ctx = $this->api('GET', $this->base() . '/api/v1/statuses/' . rawurlencode($providerPostId) . '/context')['json'];
        $me = (string) ($this->accountData()['account_id'] ?? '');
        $out = [];
        foreach ($ctx['descendants'] ?? [] as $s) {
            $a = $s['account'] ?? [];
            $out[] = [
                'id' => (string) $s['id'],
                'text' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br />', '</p><p>'], "\n", $s['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'author_name' => ($a['display_name'] ?? '') ?: ($a['username'] ?? ''),
                'author_handle' => $a['acct'] ?? '',
                'author_avatar' => $a['avatar'] ?? null,
                'created_at' => gmdate('Y-m-d H:i:s', strtotime($s['created_at'] ?? 'now')),
                'url' => $s['url'] ?? null,
                'reply_ref' => '@' . ($a['acct'] ?? ''),
                'own' => (string) ($a['id'] ?? '') === $me,
            ];
        }
        return $out;
    }

    public function reply(array $item, string $text): array
    {
        $mention = $item['reply_ref'] ?? '';
        if ($mention !== '' && $mention !== '@' && !str_contains($text, $mention)) {
            $text = $mention . ' ' . $text;
        }
        $res = $this->api('POST', $this->base() . '/api/v1/statuses', ['json' => ['status' => $text, 'in_reply_to_id' => $item['provider_item_id'], 'visibility' => 'public']]);
        return ['id' => (string) $res['json']['id'], 'url' => $res['json']['url'] ?? null];
    }
}
