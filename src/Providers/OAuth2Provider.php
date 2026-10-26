<?php
declare(strict_types=1);

namespace App\Providers;

use App\Http;

/**
 * Shared OAuth 2.0 authorization-code flow (with optional PKCE) and token refresh.
 * Tokens are stored as ['access_token', 'refresh_token', 'expires_at' (unix), ...extra].
 */
abstract class OAuth2Provider extends Provider
{
    abstract protected function authorizeEndpoint(): string;

    abstract protected function tokenEndpoint(): string;

    /** @return string[] */
    abstract protected function scopes(): array;

    protected function scopeSeparator(): string
    {
        return ' ';
    }

    protected function clientId(): string
    {
        return (string) ($this->credentials['client_id'] ?? $this->credentials['app_id'] ?? $this->credentials['client_key'] ?? '');
    }

    protected function clientSecret(): string
    {
        return (string) ($this->credentials['client_secret'] ?? $this->credentials['app_secret'] ?? '');
    }

    protected function clientIdParam(): string
    {
        return 'client_id';
    }

    protected function usesPkce(): bool
    {
        return false;
    }

    /** Send client credentials as HTTP Basic auth instead of in the form body. */
    protected function basicAuth(): bool
    {
        return false;
    }

    protected function extraAuthParams(): array
    {
        return [];
    }

    public function authUrl(string $redirectUri, string $state, array $form = []): string
    {
        $params = [
            $this->clientIdParam() => $this->clientId(),
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => implode($this->scopeSeparator(), $this->scopes()),
            'state' => $state,
        ] + $this->extraAuthParams();
        if ($this->usesPkce()) {
            $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
            $this->stash['code_verifier'] = $verifier;
            $params['code_challenge'] = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
            $params['code_challenge_method'] = 'S256';
        }
        return $this->authorizeEndpoint() . (str_contains($this->authorizeEndpoint(), '?') ? '&' : '?') . http_build_query($params);
    }

    /** Exchanges an authorization code for a token. */
    protected function exchangeCode(array $input, string $redirectUri): array
    {
        $code = (string) ($input['code'] ?? '');
        if ($code === '') {
            throw new ProviderException('No authorization code was returned.');
        }
        $form = ['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => $redirectUri];
        if (!empty($this->stash['code_verifier'])) {
            $form['code_verifier'] = $this->stash['code_verifier'];
        }
        return $this->tokenRequest($form);
    }

    protected function tokenRequest(array $form): array
    {
        $headers = [];
        if ($this->basicAuth()) {
            $headers['Authorization'] = 'Basic ' . base64_encode($this->clientId() . ':' . $this->clientSecret());
        } else {
            $form[$this->clientIdParam()] = $this->clientId();
            $form['client_secret'] = $this->clientSecret();
        }
        $res = Http::post($this->tokenEndpoint(), ['form' => $form, 'headers' => $headers]);
        $json = $res['json'];
        if (isset($json['data']) && is_array($json['data']) && isset($json['data']['access_token'])) {
            $json = $json['data'];
        }
        if (!Http::ok($res) || empty($json['access_token'])) {
            throw new ProviderException(static::name() . ' rejected the login: ' . $this->errorMessage($res));
        }
        return $this->normalizeToken($json);
    }

    protected function normalizeToken(array $json, array $previous = []): array
    {
        $token = $previous;
        $token['access_token'] = $json['access_token'];
        if (!empty($json['refresh_token'])) {
            $token['refresh_token'] = $json['refresh_token'];
        }
        $token['expires_at'] = isset($json['expires_in']) ? time() + (int) $json['expires_in'] : ($previous['expires_at'] ?? null);
        if (isset($json['refresh_expires_in'])) {
            $token['refresh_expires_at'] = time() + (int) $json['refresh_expires_in'];
        }
        return $token;
    }

    /** Refreshes when the token expires within 5 minutes and a refresh token exists. */
    public function refreshToken(): ?array
    {
        $token = $this->token();
        if (empty($token['expires_at']) || $token['expires_at'] > time() + 300) {
            return null;
        }
        if (empty($token['refresh_token'])) {
            if ($token['expires_at'] < time()) {
                throw ProviderException::unauthorized(static::name() . ' access expired. Reconnect the account.');
            }
            return null;
        }
        try {
            $new = $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $token['refresh_token']]);
        } catch (ProviderException $e) {
            throw ProviderException::unauthorized(static::name() . ' access expired and couldn’t be renewed. Reconnect the account.');
        }
        $new = $this->normalizeToken($new, $token);
        $this->account['token'] = $new;
        return $new;
    }

    /** Current access token, for read-only lookups that borrow this account (e.g. competitor stats). */
    public function lookupToken(): string
    {
        return $this->accessToken();
    }

    protected function accessToken(): string
    {
        return (string) ($this->token()['access_token'] ?? '');
    }

    /** Authenticated JSON request; throws ProviderException on errors. */
    protected function api(string $method, string $url, array $options = []): array
    {
        $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer ' . $this->accessToken()];
        $res = Http::request($method, $url, $options);
        if (!Http::ok($res)) {
            $msg = static::name() . ': ' . $this->errorMessage($res);
            throw $res['status'] === 401 ? ProviderException::unauthorized($msg) : new ProviderException($msg);
        }
        return $res;
    }

    /** Best-effort extraction of a readable error from an API response. */
    protected function errorMessage(array $res): string
    {
        $j = $res['json'];
        $candidates = [
            $j['error']['message'] ?? null,
            $j['error_description'] ?? null,
            $j['detail'] ?? null,
            $j['errors'][0]['message'] ?? null,
            $j['errors'][0]['detail'] ?? null,
            $j['message'] ?? null,
            $j['title'] ?? null,
            is_string($j['error'] ?? null) ? $j['error'] : null,
        ];
        foreach ($candidates as $c) {
            if (is_string($c) && $c !== '') {
                return $c . ' (HTTP ' . $res['status'] . ')';
            }
        }
        $body = trim(strip_tags($res['body']));
        return 'HTTP ' . $res['status'] . ($body !== '' ? ': ' . mb_substr($body, 0, 200) : '');
    }

    /** Polls $check until it returns non-null or time runs out. */
    protected function poll(callable $check, int $timeoutSeconds = 300, int $interval = 3): mixed
    {
        $deadline = time() + $timeoutSeconds;
        do {
            $result = $check();
            if ($result !== null) {
                return $result;
            }
            sleep($interval);
        } while (time() < $deadline);
        throw new ProviderException(static::name() . ' is still processing the media. Try again in a few minutes.');
    }

    /** First line becomes a title (for YouTube, Pinterest, TikTok photos). */
    protected static function splitTitle(string $text, int $max): array
    {
        $text = trim($text);
        $lines = preg_split('/\R/u', $text, 2);
        $title = trim($lines[0] ?? '');
        $rest = trim($lines[1] ?? '');
        if (mb_strlen($title) > $max) {
            $title = rtrim(mb_substr($title, 0, $max - 1)) . '…';
            $rest = $text;
        }
        return [$title, $rest];
    }
}
