<?php
declare(strict_types=1);

namespace App\Providers;

/**
 * Base class for a social platform integration.
 *
 * $credentials are the app-wide API keys from Settings → Services.
 * $account is the connected account row with its decrypted token under 'token'.
 */
abstract class Provider
{
    /**
     * Values authUrl() needs back in connect(), e.g. a PKCE verifier or per-server client keys.
     * The controller keeps it in the session between the redirect and the callback.
     */
    public array $stash = [];

    public function __construct(
        protected array $credentials = [],
        protected ?array $account = null,
    ) {
    }

    abstract public static function key(): string;

    abstract public static function name(): string;

    /** Brand color used for badges in the UI. */
    public static function color(): string
    {
        return '#64748b';
    }

    /** Max characters per post. */
    public static function textLimit(): int
    {
        return 5000;
    }

    /** ['max_media' => int, 'video' => bool, 'requires_media' => bool, 'requires_video' => bool] */
    public static function mediaRules(): array
    {
        return ['max_media' => 4, 'video' => true, 'requires_media' => false, 'requires_video' => false];
    }

    /** API credentials an instance admin must enter, as [field => label]. Empty if none are needed. */
    public static function credentialFields(): array
    {
        return [];
    }

    /** Short setup hint shown on the Services page. */
    public static function setupHint(): string
    {
        return '';
    }

    /** False until the integration is built; the UI shows it as coming soon. */
    public static function implemented(): bool
    {
        return false;
    }

    /**
     * Fields for providers that connect with a form instead of OAuth (e.g. Bluesky app passwords,
     * Mastodon instance URL before OAuth). Return [] to go straight to authUrl().
     */
    public static function connectFields(): array
    {
        return [];
    }

    /** False for providers that connect with credentials only (connect() receives the form fields). */
    public static function usesOAuth(): bool
    {
        return true;
    }

    /** OAuth authorization URL. $state must be echoed back to the callback. */
    public function authUrl(string $redirectUri, string $state, array $form = []): string
    {
        throw new ProviderException(static::name() . ' does not use OAuth.');
    }

    /**
     * Completes a connection. $input is the OAuth callback query or the connect form fields.
     * Returns a list of accounts: [['provider_id','name','username','avatar','token' => array,'data' => array], ...]
     */
    abstract public function connect(array $input, string $redirectUri): array;

    /**
     * Publishes a post. $media items: ['path' => absolute file path, 'url' => public URL, 'mime', 'width', 'height'].
     * Returns ['id' => provider post id, 'url' => link to the post or null].
     */
    abstract public function publish(string $text, array $media): array;

    /** Returns a refreshed token array if it changed, else null. Called before publishing. */
    public function refreshToken(): ?array
    {
        return null;
    }

    /** Returns ['followers' => ?int, ...extra] for the daily analytics snapshot. */
    public function accountMetrics(): array
    {
        return [];
    }

    /** Returns metrics for one published post, e.g. ['likes' => 3, 'replies' => 1]. */
    public function postMetrics(string $providerPostId): array
    {
        return [];
    }

    protected function token(): array
    {
        return $this->account['token'] ?? [];
    }

    protected function accountData(): array
    {
        return json_arr($this->account['data'] ?? null);
    }
}
