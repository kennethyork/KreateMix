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

    /** Per-account options for the post being published, e.g. ['type' => 'story']. */
    public array $options = [];

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

    /**
     * Audience breakdowns where the platform offers them:
     * ['age' => [label => count], 'gender' => [...], 'country' => [...], 'city' => [...]]
     */
    public function audience(): array
    {
        return [];
    }

    /** Post formats this platform offers, as [key => label]. The first is the default. */
    public static function postTypes(): array
    {
        return ['feed' => 'Post'];
    }

    /** True when firstComment() is implemented. */
    public static function supportsFirstComment(): bool
    {
        return false;
    }

    /** Adds a comment under a post we just published (the "first comment"). */
    public function firstComment(string $providerPostId, string $text): void
    {
        throw new ProviderException(static::name() . ' doesn’t support a first comment.');
    }

    /** True when comments() and reply() are implemented. */
    public static function supportsComments(): bool
    {
        return false;
    }

    /**
     * Comments/replies on a published post, newest last:
     * [['id', 'text', 'author_name', 'author_handle', 'author_avatar', 'created_at' (UTC Y-m-d H:i:s), 'url', 'reply_ref', 'own' => bool]]
     */
    public function comments(string $providerPostId): array
    {
        return [];
    }

    /** Replies to a comment returned by comments(). $item has provider_item_id, root_ref and reply_ref. */
    public function reply(array $item, string $text): array
    {
        throw new ProviderException(static::name() . ' doesn’t support replies from MixCreate.');
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
