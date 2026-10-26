<?php
/**
 * MixCreate: self-hosted social media scheduling and analytics, in one file.
 * https://github.com/kennethyork/MixCreate · MIT License
 *
 * Upload this file to your web root and open it in a browser. On first run it creates its settings
 * file (.ht.mixcreate-config.php) next to itself and a SQLite database (one folder up when it can,
 * out of the web server's reach; otherwise a hidden file beside this one).
 * Scheduler, every minute:  php /path/to/index.php cron
 */
declare(strict_types=1);


namespace {

use App\Auth;
use App\Config;
use App\Db;

function now(): string
{
    return gmdate('Y-m-d H:i:s');
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/** Path prefix when the app lives in a subfolder, e.g. "/social". Empty at a domain root. */
function base_path(): string
{
    static $base = null;
    if ($base !== null) {
        return $base;
    }
    $appUrl = (string) Config::get('app_url', '');
    if ($appUrl !== '') {
        $base = rtrim((string) parse_url($appUrl, PHP_URL_PATH), '/');
    } elseif (PHP_SAPI === 'cli-server' || PHP_SAPI === 'cli') {
        // The built-in server sets SCRIPT_NAME to the request path for URLs with a file extension.
        $base = '';
    } else {
        $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/index.php');
        $uri = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($script !== '' && str_starts_with($uri, $script)) {
            // No URL rewriting: pages are addressed as /index.php/path.
            $base = $script;
        } else {
            $base = rtrim(preg_replace('#/public$#', '', str_replace('\\', '/', dirname($script))), '/');
        }
    }
    return $base;
}

function url(string $path = '', array $query = []): string
{
    $u = base_path() . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

/** Absolute URL, needed for OAuth redirect URIs and links sent off-site. */
function abs_url(string $path = '', array $query = []): string
{
    $appUrl = rtrim((string) Config::get('app_url', ''), '/');
    if ($appUrl === '') {
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $appUrl = ($https ? 'https' : 'http') . '://' . $host . base_path();
    } else {
        $appUrl = preg_replace('#' . preg_quote(base_path(), '#') . '$#', '', $appUrl) . base_path();
    }
    $u = $appUrl . '/' . ltrim($path, '/');
    if ($query) {
        $u .= '?' . http_build_query($query);
    }
    return $u;
}

/**
 * A URL that points straight at index.php with a query string. Used for CSS, JS, icons and media so
 * they always reach PHP: many servers (CloudPanel's Nginx among them) answer *.css, *.js and image
 * paths from disk and would never pass /assets/app.css to the app.
 */
function entry_url(array $query, bool $absolute = false): string
{
    $script = basename(APP_FILE);
    $base = base_path();
    if (str_ends_with($base, '/' . $script)) {
        $base = substr($base, 0, -strlen('/' . $script));
    }
    $path = $base . '/' . $script . '?' . http_build_query($query);
    if (!$absolute) {
        return $path;
    }
    $root = abs_url('');
    $root = substr($root, 0, strlen($root) - strlen(base_path() . '/'));
    return $root . $path;
}

function asset(string $path): string
{
    return entry_url(['asset' => ltrim($path, '/'), 'v' => App\Assets::version()]);
}

function redirect(string $path, array $query = []): never
{
    $target = preg_match('#^https?://#', $path) ? $path : url($path, $query);
    header('Location: ' . $target);
    exit;
}

function back(string $fallback = '/'): never
{
    $ref = $_SERVER['HTTP_REFERER'] ?? '';
    $host = $_SERVER['HTTP_HOST'] ?? '';
    if ($ref !== '' && parse_url($ref, PHP_URL_HOST) === $host) {
        header('Location: ' . $ref);
        exit;
    }
    redirect($fallback);
}

function view(string $template, array $data = [], ?string $layout = 'app'): never
{
    echo render($template, $data, $layout);
    exit;
}

function render(string $template, array $data = [], ?string $layout = null): string
{
    $content = App\View::capture($template, $data);
    if ($layout === null) {
        return $content;
    }
    return App\View::capture('layouts/' . $layout, array_merge($data, ['content' => $content, 'title' => $data['title'] ?? '']));
}

function partial(string $template, array $data = []): void
{
    echo render($template, $data, null);
}

function json_response(mixed $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function abort(int $code, string $message = ''): never
{
    http_response_code($code);
    $messages = [403 => 'You do not have access to this.', 404 => 'Page not found.', 419 => 'Your session expired. Go back and try again.'];
    $message = $message !== '' ? $message : ($messages[$code] ?? 'Something went wrong.');
    if (wants_json()) {
        json_response(['error' => $message], $code);
    }
    view('error', ['title' => (string) $code, 'code' => $code, 'message' => $message], Auth::user() ? 'app' : 'auth');
}

function wants_json(): bool
{
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
        || str_starts_with((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), base_path() . '/api/')
        || ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch';
}

function input(string $key, mixed $default = null): mixed
{
    static $json = null;
    if ($json === null) {
        $json = [];
        if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
            $json = json_decode((string) file_get_contents('php://input'), true) ?: [];
        }
    }
    $v = $_POST[$key] ?? $json[$key] ?? $_GET[$key] ?? $default;
    return is_string($v) ? trim($v) : $v;
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void
{
    $token = $_POST['_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? input('_token', '');
    if (!is_string($token) || !hash_equals(csrf_token(), $token)) {
        abort(419);
    }
}

function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

function flashes(): array
{
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

function with_old(array $data): void
{
    unset($data['password'], $data['password_confirmation'], $data['_token']);
    $_SESSION['_old'] = $data;
}

function old(string $key, mixed $default = ''): mixed
{
    return $_SESSION['_old_now'][$key] ?? $default;
}

function user_tz(): string
{
    return Auth::user()['timezone'] ?? 'UTC';
}

/** Formats a UTC timestamp from the database in the user's timezone. */
function local_time(?string $utc, string $format = 'M j, Y g:i A', ?string $tz = null): string
{
    if (!$utc) {
        return '';
    }
    $dt = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $dt->setTimezone(new DateTimeZone($tz ?? user_tz()))->format($format);
}

/** Converts a local "Y-m-d H:i" string in the user's timezone to UTC for storage. */
function to_utc(string $local, ?string $tz = null): ?string
{
    try {
        $dt = new DateTimeImmutable($local, new DateTimeZone($tz ?? user_tz()));
    } catch (Exception) {
        return null;
    }
    return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function log_event(string $level, string $message, array $context = []): void
{
    try {
        Db::insert('logs', [
            'level' => $level,
            'message' => mb_substr($message, 0, 2000),
            'context' => $context ? json_encode($context, JSON_UNESCAPED_SLASHES) : null,
            'created_at' => now(),
        ]);
    } catch (Throwable) {
        error_log("[$level] $message");
    }
}

function json_arr(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $v = json_decode($json, true);
    return is_array($v) ? $v : [];
}

function initials(string $name): string
{
    $parts = preg_split('/\s+/', trim($name)) ?: [];
    $s = '';
    foreach (array_slice($parts, 0, 2) as $p) {
        $s .= mb_strtoupper(mb_substr($p, 0, 1));
    }
    return $s ?: '?';
}

function str_limit(string $s, int $n): string
{
    return mb_strlen($s) > $n ? rtrim(mb_substr($s, 0, $n - 1)) . '…' : $s;
}

function icon(string $name, string $class = ''): string
{
    return '<svg class="icon ' . e($class) . '" aria-hidden="true"><use href="' . e(asset('icons.svg')) . '#' . e($name) . '"></use></svg>';
}

function status_badge(string $status): string
{
    $labels = App\Controllers\PostsController::STATUSES;
    return '<span class="badge status-' . e($status) . '">' . e($labels[$status] ?? ucfirst($status)) . '</span>';
}

/** ['name' => ..., 'color' => ...] for a provider key, safe for unknown keys. */
function provider_meta(string $key): array
{
    $class = App\Providers\Registry::class($key);
    return $class ? ['name' => $class::name(), 'color' => $class::color()] : ['name' => ucfirst($key), 'color' => '#64748b'];
}

/** Avatar with a small platform badge. */
function account_avatar(array $a, string $size = ''): string
{
    $meta = provider_meta($a['provider']);
    $inner = !empty($a['avatar'])
        ? '<img src="' . e($a['avatar']) . '" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()">'
        : '';
    return '<span class="acct-avatar ' . e($size) . '" title="' . e($meta['name'] . ' · ' . ($a['name'] ?? '')) . '">'
        . '<span class="avatar ' . e($size) . '">' . e(initials((string) ($a['name'] ?? '?'))) . $inner . '</span>'
        . provider_badge($a['provider'])
        . '</span>';
}

/** Small colored platform marker with a short abbreviation (no brand logos). */
function provider_badge(string $key, string $class = 'pbadge'): string
{
    $abbr = ['x' => 'X', 'mastodon' => 'M', 'bluesky' => 'B', 'facebook' => 'f', 'instagram' => 'IG', 'linkedin' => 'in',
        'youtube' => 'YT', 'threads' => 'Th', 'tiktok' => 'TT', 'pinterest' => 'P', 'google_business' => 'G', 'sandbox' => 'S'];
    $meta = provider_meta($key);
    return '<span class="' . e($class) . '" style="--pc: ' . e($meta['color']) . '" aria-hidden="true">' . e($abbr[$key] ?? strtoupper(substr($key, 0, 1))) . '</span>';
}

/* ---------- Small runtime state kept in the database (instead of files in a storage folder) ---------- */

function kv_get(string $key, mixed $default = null): mixed
{
    $v = Db::value('SELECT v FROM kv WHERE k = ?', [$key]);
    return $v === null ? $default : json_decode((string) $v, true);
}

function kv_set(string $key, mixed $value): void
{
    Db::query('INSERT INTO kv (k, v, updated_at) VALUES (?, ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, updated_at = excluded.updated_at', [$key, json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), time()]);
}

/** Seconds since $key was last set, or null if never. */
function kv_age(string $key): ?int
{
    $t = Db::value('SELECT updated_at FROM kv WHERE k = ?', [$key]);
    return $t === null ? null : time() - (int) $t;
}

function kv_delete(string $key): void
{
    Db::query('DELETE FROM kv WHERE k = ?', [$key]);
}

/** Runs at most once per $seconds: returns true (and marks it) when it's time. */
function kv_due(string $key, int $seconds): bool
{
    $age = kv_age($key);
    if ($age !== null && $age < $seconds) {
        return false;
    }
    kv_set($key, true);
    return true;
}

/** Public URL of a stored media file (or converted copy). */
function media_url(array|string $m, bool $absolute = false): string
{
    $key = is_array($m) ? ($m['key'] ?? $m['path']) : $m;
    return entry_url(['file' => $key], $absolute);
}

/** The command to put in crontab. */
function cron_command(): string
{
    return 'php ' . APP_FILE . ' cron';
}

}

namespace App\Providers {

// ---- Providers/Provider.php ----
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

}

namespace App\Providers {

// ---- Providers/ProviderException.php ----
class ProviderException extends \RuntimeException
{
    /** True when the account's authorization is no longer valid and it must be reconnected. */
    public bool $unauthorized = false;

    public static function unauthorized(string $message): self
    {
        $e = new self($message);
        $e->unauthorized = true;
        return $e;
    }
}

}

namespace App\Providers {

// ---- Providers/OAuth2Provider.php ----
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

}

namespace App {

// ---- Ads.php ----
/** Ads reporting for Meta (Facebook/Instagram), Google Ads and TikTok Ads. Campaign-level daily stats. */
final class Ads
{
    public const PROVIDERS = [
        'meta_ads' => ['name' => 'Meta Ads', 'color' => '#1877f2', 'abbr' => 'f'],
        'google_ads' => ['name' => 'Google Ads', 'color' => '#4285f4', 'abbr' => 'G'],
        'tiktok_ads' => ['name' => 'TikTok Ads', 'color' => '#000000', 'abbr' => 'TT'],
    ];
    private const GOOGLE_VERSION = 'v26';
    private const META_GRAPH = 'https://graph.facebook.com/' . Providers\Facebook::VERSION;
    private const TIKTOK = 'https://business-api.tiktok.com/open_api/v1.3';

    public static function ready(string $provider): bool
    {
        return match ($provider) {
            'meta_ads' => Settings::has('meta_ads', ['app_id', 'app_secret']),
            'google_ads' => Settings::has('google_ads', ['client_id', 'client_secret', 'developer_token']),
            'tiktok_ads' => Settings::has('tiktok_ads', ['app_id', 'app_secret']),
            default => false,
        };
    }

    public static function authUrl(string $provider, string $state): string
    {
        $redirect = abs_url('ads/callback/' . $provider);
        $s = Settings::get($provider);
        return match ($provider) {
            'meta_ads' => 'https://www.facebook.com/' . Providers\Facebook::VERSION . '/dialog/oauth?' . http_build_query(['client_id' => $s['app_id'], 'redirect_uri' => $redirect, 'state' => $state, 'scope' => 'ads_read,business_management', 'response_type' => 'code']),
            'google_ads' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(['client_id' => $s['client_id'], 'redirect_uri' => $redirect, 'state' => $state, 'scope' => 'https://www.googleapis.com/auth/adwords', 'response_type' => 'code', 'access_type' => 'offline', 'prompt' => 'consent']),
            'tiktok_ads' => 'https://business-api.tiktok.com/portal/auth?' . http_build_query(['app_id' => $s['app_id'], 'redirect_uri' => $redirect, 'state' => $state]),
        };
    }

    /** Completes OAuth and returns ad accounts: [['external_id','name','currency','token' => array,'data' => array]] */
    public static function connect(string $provider, array $query): array
    {
        $redirect = abs_url('ads/callback/' . $provider);
        $s = Settings::get($provider);
        if ($provider === 'meta_ads') {
            $r = Http::get(self::META_GRAPH . '/oauth/access_token', ['query' => ['client_id' => $s['app_id'], 'client_secret' => $s['app_secret'], 'redirect_uri' => $redirect, 'code' => $query['code'] ?? '']]);
            $short = $r['json']['access_token'] ?? throw new \RuntimeException('Meta: ' . ($r['json']['error']['message'] ?? 'login failed'));
            $l = Http::get(self::META_GRAPH . '/oauth/access_token', ['query' => ['grant_type' => 'fb_exchange_token', 'client_id' => $s['app_id'], 'client_secret' => $s['app_secret'], 'fb_exchange_token' => $short]])['json'];
            $token = ['access_token' => $l['access_token'] ?? $short, 'expires_at' => time() + (int) ($l['expires_in'] ?? 5184000)];
            $accs = Http::get(self::META_GRAPH . '/me/adaccounts', ['query' => ['fields' => 'id,name,currency,account_status', 'limit' => 200, 'access_token' => $token['access_token']]])['json']['data'] ?? [];
            return array_map(fn($a) => ['external_id' => $a['id'], 'name' => $a['name'] ?? $a['id'], 'currency' => $a['currency'] ?? 'USD', 'token' => $token, 'data' => []], $accs);
        }
        if ($provider === 'google_ads') {
            $r = Http::post('https://oauth2.googleapis.com/token', ['form' => ['grant_type' => 'authorization_code', 'code' => $query['code'] ?? '', 'redirect_uri' => $redirect, 'client_id' => $s['client_id'], 'client_secret' => $s['client_secret']]]);
            if (empty($r['json']['access_token'])) {
                throw new \RuntimeException('Google: ' . ($r['json']['error_description'] ?? 'login failed'));
            }
            $token = ['access_token' => $r['json']['access_token'], 'refresh_token' => $r['json']['refresh_token'] ?? null, 'expires_at' => time() + (int) $r['json']['expires_in']];
            $list = self::google($token['access_token'], 'GET', '/customers:listAccessibleCustomers');
            $out = [];
            foreach ($list['resourceNames'] ?? [] as $rn) {
                $cid = substr($rn, strlen('customers/'));
                try {
                    $rows = self::googleQuery($token['access_token'], $cid, 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.manager FROM customer');
                    $c = $rows[0]['customer'] ?? [];
                    if (!empty($c['manager'])) {
                        continue; // manager accounts have no campaigns of their own
                    }
                    $out[] = ['external_id' => $cid, 'name' => $c['descriptiveName'] ?? $cid, 'currency' => $c['currencyCode'] ?? 'USD', 'token' => $token, 'data' => []];
                } catch (\RuntimeException) {
                    $out[] = ['external_id' => $cid, 'name' => 'Customer ' . $cid, 'currency' => 'USD', 'token' => $token, 'data' => []];
                }
            }
            return $out;
        }
        // TikTok
        $r = Http::post(self::TIKTOK . '/oauth2/access_token/', ['json' => ['app_id' => $s['app_id'], 'secret' => $s['app_secret'], 'auth_code' => $query['auth_code'] ?? $query['code'] ?? '']]);
        $data = $r['json']['data'] ?? [];
        if (empty($data['access_token'])) {
            throw new \RuntimeException('TikTok: ' . ($r['json']['message'] ?? 'login failed'));
        }
        $token = ['access_token' => $data['access_token']];
        $ids = $data['advertiser_ids'] ?? [];
        $info = $ids ? (Http::get(self::TIKTOK . '/advertiser/info/', ['query' => ['advertiser_ids' => json_encode(array_map('strval', $ids))], 'headers' => ['Access-Token' => $token['access_token']]])['json']['data']['list'] ?? []) : [];
        $byId = array_column($info, null, 'advertiser_id');
        return array_map(fn($id) => ['external_id' => (string) $id, 'name' => $byId[$id]['name'] ?? ('Advertiser ' . $id), 'currency' => $byId[$id]['currency'] ?? 'USD', 'token' => $token, 'data' => []], $ids);
    }

    private static function google(string $token, string $method, string $path, ?array $json = null): array
    {
        $s = Settings::get('google_ads');
        $headers = ['Authorization' => "Bearer $token", 'developer-token' => $s['developer_token']];
        if (!empty($s['login_customer_id'])) {
            $headers['login-customer-id'] = preg_replace('/\D/', '', $s['login_customer_id']);
        }
        $r = Http::request($method, 'https://googleads.googleapis.com/' . self::GOOGLE_VERSION . $path, array_filter(['headers' => $headers, 'json' => $json]));
        if (!Http::ok($r)) {
            $err = $r['json'][0]['error'] ?? $r['json']['error'] ?? [];
            throw new \RuntimeException('Google Ads: ' . ($err['details'][0]['errors'][0]['message'] ?? $err['message'] ?? 'HTTP ' . $r['status']));
        }
        return $r['json'];
    }

    private static function googleQuery(string $token, string $customerId, string $gaql): array
    {
        $batches = self::google($token, 'POST', "/customers/$customerId/googleAds:searchStream", ['query' => $gaql]);
        $rows = [];
        foreach ($batches as $b) {
            foreach ($b['results'] ?? [] as $row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** Refreshes tokens where possible and returns the access token. */
    private static function accessToken(array $acc): string
    {
        $t = Crypt::decryptArray($acc['token']);
        if ($acc['provider'] === 'google_ads' && ($t['expires_at'] ?? 0) < time() + 120) {
            $s = Settings::get('google_ads');
            $r = Http::post('https://oauth2.googleapis.com/token', ['form' => ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'] ?? '', 'client_id' => $s['client_id'], 'client_secret' => $s['client_secret']]]);
            if (empty($r['json']['access_token'])) {
                Db::update('ad_accounts', ['authorized' => 0], 'id = ?', [$acc['id']]);
                throw new \RuntimeException('Google Ads access expired. Reconnect.');
            }
            $t['access_token'] = $r['json']['access_token'];
            $t['expires_at'] = time() + (int) $r['json']['expires_in'];
            Db::update('ad_accounts', ['token' => Crypt::encrypt($t)], 'id = ?', [$acc['id']]);
        }
        if ($acc['provider'] === 'meta_ads' && ($t['expires_at'] ?? PHP_INT_MAX) < time()) {
            Db::update('ad_accounts', ['authorized' => 0], 'id = ?', [$acc['id']]);
            throw new \RuntimeException('Meta Ads access expired. Reconnect.');
        }
        return (string) ($t['access_token'] ?? '');
    }

    /**
     * Fetches daily campaign stats between two dates.
     * @return array<array{date: string, campaign_id: string, campaign_name: string, spend: float, impressions: int, clicks: int, conversions: float}>
     */
    public static function fetch(array $acc, string $from, string $to): array
    {
        $token = self::accessToken($acc);
        $out = [];
        if ($acc['provider'] === 'meta_ads') {
            $url = self::META_GRAPH . '/' . $acc['external_id'] . '/insights';
            $query = ['level' => 'campaign', 'fields' => 'campaign_id,campaign_name,spend,impressions,clicks,actions', 'time_increment' => 1,
                'time_range' => json_encode(['since' => $from, 'until' => $to]), 'limit' => 500, 'access_token' => $token];
            while ($url) {
                $r = Http::get($url, ['query' => $query]);
                if (!Http::ok($r)) {
                    if (($r['json']['error']['code'] ?? 0) === 190) {
                        Db::update('ad_accounts', ['authorized' => 0], 'id = ?', [$acc['id']]);
                    }
                    throw new \RuntimeException('Meta Ads: ' . ($r['json']['error']['message'] ?? 'HTTP ' . $r['status']));
                }
                foreach ($r['json']['data'] ?? [] as $row) {
                    $conv = 0;
                    foreach ($row['actions'] ?? [] as $a) {
                        if (in_array($a['action_type'], ['purchase', 'lead', 'complete_registration'], true)) {
                            $conv += (float) $a['value'];
                        }
                    }
                    $out[] = ['date' => $row['date_start'], 'campaign_id' => (string) $row['campaign_id'], 'campaign_name' => $row['campaign_name'] ?? '', 'spend' => (float) ($row['spend'] ?? 0),
                        'impressions' => (int) ($row['impressions'] ?? 0), 'clicks' => (int) ($row['clicks'] ?? 0), 'conversions' => $conv];
                }
                $url = $r['json']['paging']['next'] ?? null;
                $query = [];
            }
        } elseif ($acc['provider'] === 'google_ads') {
            $rows = self::googleQuery($token, $acc['external_id'],
                "SELECT campaign.id, campaign.name, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions FROM campaign WHERE segments.date BETWEEN '$from' AND '$to'");
            foreach ($rows as $row) {
                $out[] = ['date' => $row['segments']['date'], 'campaign_id' => (string) $row['campaign']['id'], 'campaign_name' => $row['campaign']['name'] ?? '',
                    'spend' => ((int) ($row['metrics']['costMicros'] ?? 0)) / 1e6, 'impressions' => (int) ($row['metrics']['impressions'] ?? 0),
                    'clicks' => (int) ($row['metrics']['clicks'] ?? 0), 'conversions' => (float) ($row['metrics']['conversions'] ?? 0)];
            }
        } else {
            $page = 1;
            do {
                $r = Http::get(self::TIKTOK . '/report/integrated/get/', ['headers' => ['Access-Token' => $token], 'query' => [
                    'advertiser_id' => $acc['external_id'], 'report_type' => 'BASIC', 'data_level' => 'AUCTION_CAMPAIGN',
                    'dimensions' => json_encode(['campaign_id', 'stat_time_day']),
                    'metrics' => json_encode(['campaign_name', 'spend', 'impressions', 'clicks', 'conversion']),
                    'start_date' => $from, 'end_date' => $to, 'page' => $page, 'page_size' => 1000,
                ]]);
                if (($r['json']['code'] ?? -1) !== 0) {
                    throw new \RuntimeException('TikTok Ads: ' . ($r['json']['message'] ?? 'HTTP ' . $r['status']));
                }
                foreach ($r['json']['data']['list'] ?? [] as $row) {
                    $m = $row['metrics'] ?? [];
                    $out[] = ['date' => substr($row['dimensions']['stat_time_day'], 0, 10), 'campaign_id' => (string) $row['dimensions']['campaign_id'], 'campaign_name' => $m['campaign_name'] ?? '',
                        'spend' => (float) ($m['spend'] ?? 0), 'impressions' => (int) ($m['impressions'] ?? 0), 'clicks' => (int) ($m['clicks'] ?? 0), 'conversions' => (float) ($m['conversion'] ?? 0)];
                }
                $total = (int) ($r['json']['data']['page_info']['total_page'] ?? 1);
                $page++;
            } while ($page <= $total && $page <= 20);
        }
        return $out;
    }

    /** Imports the last 30 days the first time, then the last 3 days (late conversions settle) every 6 hours. */
    public static function importDue(): int
    {
        $n = 0;
        $rows = Db::all("SELECT * FROM ad_accounts WHERE authorized = 1 AND (last_import_at IS NULL OR last_import_at < ?)", [gmdate('Y-m-d H:i:s', time() - 6 * 3600)]);
        foreach ($rows as $acc) {
            $from = gmdate('Y-m-d', time() - ($acc['last_import_at'] ? 3 : 30) * 86400);
            try {
                self::import($acc, $from, gmdate('Y-m-d'));
                $n++;
            } catch (\Throwable $e) {
                log_event('warning', "Ads import failed for {$acc['provider']} {$acc['external_id']}: " . $e->getMessage());
                Db::update('ad_accounts', ['last_import_at' => now()], 'id = ?', [$acc['id']]);
            }
        }
        return $n;
    }

    public static function import(array $acc, string $from, string $to): void
    {
        $rows = self::fetch($acc, $from, $to);
        Db::transaction(function () use ($acc, $rows, $from, $to) {
            Db::query('DELETE FROM ad_metrics WHERE ad_account_id = ? AND date BETWEEN ? AND ?', [$acc['id'], $from, $to]);
            foreach ($rows as $r) {
                Db::query(
                    'INSERT OR REPLACE INTO ad_metrics (ad_account_id, date, campaign_id, campaign_name, spend, impressions, clicks, conversions) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$acc['id'], $r['date'], $r['campaign_id'], mb_substr($r['campaign_name'], 0, 200), $r['spend'], $r['impressions'], $r['clicks'], $r['conversions']]
                );
            }
            Db::update('ad_accounts', ['last_import_at' => now()], 'id = ?', [$acc['id']]);
        });
    }
}

}

namespace App {

// ---- Ai.php ----
/**
 * Caption writing. Default provider is OpenRouter (bring your own key; one key reaches every major model).
 * Also supports OpenAI, any OpenAI-compatible server (Ollama, LM Studio, Groq…) and Anthropic.
 * Instance settings live under Integrations → AI; a workspace can override the key and model.
 */
final class Ai
{
    public const ACTIONS = [
        'write' => 'Write a post from an idea',
        'improve' => 'Improve',
        'shorten' => 'Shorten',
        'expand' => 'Expand',
        'casual' => 'More casual',
        'professional' => 'More professional',
        'hashtags' => 'Suggest hashtags',
        'fix' => 'Fix spelling & grammar',
        'variants' => '3 variations',
    ];

    public const OPENROUTER = 'https://openrouter.ai/api/v1';

    /** Effective settings: instance AI settings, with a workspace's own OpenRouter key/model on top. */
    public static function config(?int $workspaceId = null): array
    {
        $s = Settings::get('ai');
        $s['provider'] = ($s['provider'] ?? '') ?: 'openrouter';
        if ($workspaceId) {
            $ws = Settings::get('ai.ws.' . $workspaceId);
            if (!empty($ws['api_key'])) {
                // A workspace key always means OpenRouter (each client brings their own key).
                $s = ['provider' => 'openrouter', 'api_key' => $ws['api_key'], 'model' => ($ws['model'] ?? '') ?: (($s['provider'] === 'openrouter' ? $s['model'] ?? '' : '') ?: 'openrouter/auto'), 'brand_voice' => $s['brand_voice'] ?? ''];
            } elseif (!empty($ws['model']) && $s['provider'] === 'openrouter') {
                $s['model'] = $ws['model'];
            }
            if (!empty($ws['brand_voice'])) {
                $s['brand_voice'] = $ws['brand_voice'];
            }
        }
        if ($s['provider'] === 'openrouter' && empty($s['model'])) {
            $s['model'] = 'openrouter/auto';
        }
        return $s;
    }

    public static function enabled(?int $workspaceId = null): bool
    {
        $s = self::config($workspaceId);
        return !empty($s['model']) && (!empty($s['api_key']) || ($s['provider'] === 'openai_compatible' && !empty($s['base_url'])));
    }

    /** OpenRouter's model list, cached for a day. [['id', 'name', 'context', 'prompt_price', 'completion_price']] */
    public static function openRouterModels(): array
    {
        $age = kv_age('openrouter.models');
        $cached = $age === null ? null : (kv_get('openrouter.models') ?: []);
        // Keep a good list for a day; retry a failed fetch after an hour.
        if ($cached !== null && $age < ($cached ? 86400 : 3600)) {
            return $cached;
        }
        try {
            $res = Http::get(self::OPENROUTER . '/models', ['timeout' => 8]);
        } catch (\Throwable) {
            $res = ['json' => []];
        }
        $models = [];
        foreach ($res['json']['data'] ?? [] as $m) {
            if (!in_array('text', $m['architecture']['output_modalities'] ?? ['text'], true)) {
                continue;
            }
            $models[] = [
                'id' => $m['id'],
                'name' => $m['name'] ?? $m['id'],
                'context' => (int) ($m['context_length'] ?? 0),
                'prompt_price' => isset($m['pricing']['prompt']) ? (float) $m['pricing']['prompt'] * 1e6 : null,
                'completion_price' => isset($m['pricing']['completion']) ? (float) $m['pricing']['completion'] * 1e6 : null,
            ];
        }
        usort($models, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        if (!$models && $cached) {
            kv_set('openrouter.models', $cached); // keep the old list, try again later
            return $cached;
        }
        kv_set('openrouter.models', $models);
        return $models;
    }

    /** Runs an action and returns the generated text. */
    public static function assist(string $action, string $text, string $idea, int $limit, array $networks, string $brandVoice = '', ?int $workspaceId = null): string
    {
        if (!isset(self::ACTIONS[$action])) {
            throw new \InvalidArgumentException('Unknown action.');
        }
        $where = $networks ? implode(', ', $networks) : 'social media';
        $system = "You write social media posts for $where. Keep each post under $limit characters. "
            . 'Reply with only the post text: no quotes around it, no preamble, no explanation. Match the language of the input. '
            . 'Use hashtags and emoji only if the input already does or the task asks for them.'
            . ($brandVoice !== '' ? "\nBrand voice to follow: $brandVoice" : '');
        $user = match ($action) {
            'write' => "Write a post about: $idea" . ($text !== '' ? "\n\nCurrent draft for context:\n$text" : ''),
            'improve' => "Make this post clearer and more engaging without changing its meaning:\n\n$text",
            'shorten' => "Shorten this post, keeping the key point:\n\n$text",
            'expand' => "Expand this post with a bit more detail or a call to action:\n\n$text",
            'casual' => "Rewrite this post in a more casual, friendly tone:\n\n$text",
            'professional' => "Rewrite this post in a more professional tone:\n\n$text",
            'hashtags' => "Suggest 5 to 8 relevant hashtags for this post. Reply with only the hashtags separated by spaces:\n\n$text",
            'fix' => "Fix spelling, grammar and punctuation in this post. Change nothing else:\n\n$text",
            'variants' => "Write 3 different versions of this post. Separate them with a line containing only ---\n\n" . ($text !== '' ? $text : $idea),
        };
        if ($action !== 'write' && trim($text) === '' && $action !== 'variants') {
            throw new \RuntimeException('Write something in the post first.');
        }
        if (($action === 'write' || $action === 'variants') && trim($idea) === '' && trim($text) === '') {
            throw new \RuntimeException('Describe what the post should be about.');
        }
        return trim(self::complete($system, $user, $workspaceId), " \n\"");
    }

    public static function complete(string $system, string $user, ?int $workspaceId = null): string
    {
        $s = self::config($workspaceId);
        if (($s['provider'] ?? '') === 'anthropic') {
            $res = Http::post('https://api.anthropic.com/v1/messages', [
                'json' => ['model' => $s['model'], 'max_tokens' => 1024, 'system' => $system, 'messages' => [['role' => 'user', 'content' => $user]]],
                'headers' => ['x-api-key' => $s['api_key'], 'anthropic-version' => '2023-06-01'],
                'timeout' => 90,
            ]);
            if (!Http::ok($res)) {
                throw new \RuntimeException('AI request failed: ' . ($res['json']['error']['message'] ?? 'HTTP ' . $res['status']));
            }
            $out = '';
            foreach ($res['json']['content'] ?? [] as $block) {
                if (($block['type'] ?? '') === 'text') {
                    $out .= $block['text'];
                }
            }
            return $out;
        }
        $base = match ($s['provider']) {
            'openrouter' => self::OPENROUTER,
            'openai' => 'https://api.openai.com/v1',
            default => rtrim((string) ($s['base_url'] ?? ''), '/') ?: 'https://api.openai.com/v1',
        };
        $headers = !empty($s['api_key']) ? ['Authorization' => 'Bearer ' . $s['api_key']] : [];
        if ($s['provider'] === 'openrouter') {
            // Optional app attribution headers OpenRouter shows on its dashboard.
            $headers['HTTP-Referer'] = abs_url('');
            $headers['X-Title'] = (string) Config::get('app_name', 'MixCreate');
        }
        $res = Http::post($base . '/chat/completions', [
            'json' => ['model' => $s['model'], 'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]], 'temperature' => 0.8],
            'headers' => $headers,
            'timeout' => 120,
        ]);
        if (!Http::ok($res)) {
            $msg = $res['json']['error']['message'] ?? 'HTTP ' . $res['status'];
            if ($res['status'] === 402) {
                $msg = 'Your OpenRouter account is out of credits. Add credits or connect your own provider keys at openrouter.ai/settings/integrations.';
            }
            throw new \RuntimeException('AI request failed: ' . $msg);
        }
        $content = $res['json']['choices'][0]['message']['content'] ?? '';
        if (is_array($content)) { // some providers return content parts
            $content = implode('', array_map(fn($p) => $p['text'] ?? '', $content));
        }
        return (string) $content;
    }
}

}

namespace App {

// ---- Analytics.php ----
use App\Providers\Registry;

/** Builds analytics for a workspace and date range. Used by the Analytics page and reports. */
final class Analytics
{
    /**
     * @param int[] $accountIds accounts to include (already filtered to the workspace)
     * @param string $from Y-m-d (inclusive, in $tz)
     * @param string $to   Y-m-d (inclusive, in $tz)
     */
    public static function build(int $workspaceId, array $accountIds, string $from, string $to, string $tz): array
    {
        $in = $accountIds ? implode(',', array_map('intval', $accountIds)) : '0';
        $zone = new \DateTimeZone($tz);
        $utc = new \DateTimeZone('UTC');
        $fromUtc = (new \DateTimeImmutable($from . ' 00:00:00', $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $toUtc = (new \DateTimeImmutable($to . ' 23:59:59', $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $days = [];
        for ($d = new \DateTimeImmutable($from); $d <= new \DateTimeImmutable($to); $d = $d->modify('+1 day')) {
            $days[] = $d->format('Y-m-d');
        }

        $accounts = $accountIds ? Db::all("SELECT id, provider, name, username, avatar FROM accounts WHERE id IN ($in) ORDER BY provider, name") : [];

        // ---- Followers: per account, carry last value forward across days ----
        $rows = Db::all(
            "SELECT account_id, date, followers, data FROM account_metrics WHERE account_id IN ($in) AND date <= ? AND date >= ? ORDER BY date",
            [$to, (new \DateTimeImmutable($from))->modify('-60 days')->format('Y-m-d')]
        );
        $byAccount = [];
        foreach ($rows as $r) {
            if ($r['followers'] !== null) {
                $byAccount[$r['account_id']][$r['date']] = (int) $r['followers'];
            }
        }
        $last = [];
        $startVal = [];
        foreach ($byAccount as $aid => $vals) {
            foreach ($vals as $d => $v) {
                if ($d < $from) {
                    $last[$aid] = $v;
                }
            }
        }
        $followerSeries = [];
        foreach ($days as $d) {
            foreach ($byAccount as $aid => $vals) {
                if (isset($vals[$d])) {
                    $last[$aid] = $vals[$d];
                }
                if (!isset($startVal[$aid]) && isset($last[$aid])) {
                    $startVal[$aid] = $last[$aid];
                }
            }
            $followerSeries[] = ['date' => $d, 'value' => $last ? array_sum($last) : null];
        }
        $followersNow = $last ? array_sum($last) : null;
        $followersStart = $startVal ? array_sum($startVal) : null;

        // ---- Published posts with metrics ----
        $published = Db::all(
            "SELECT p.id, p.content, p.media, pa.account_id, pa.published_at, pa.url, a.provider, a.name AS account_name, pm.data AS metrics
             FROM post_accounts pa JOIN posts p ON p.id = pa.post_id JOIN accounts a ON a.id = pa.account_id
             LEFT JOIN post_metrics pm ON pm.post_id = pa.post_id AND pm.account_id = pa.account_id
             WHERE p.workspace_id = ? AND pa.account_id IN ($in) AND pa.status = 'published' AND pa.published_at BETWEEN ? AND ?
             ORDER BY pa.published_at DESC",
            [$workspaceId, $fromUtc, $toUtc]
        );
        $mediaTypes = self::mediaTypes($published);

        $totals = ['posts' => 0, 'likes' => 0, 'replies' => 0, 'shares' => 0, 'saves' => 0, 'clicks' => 0, 'impressions' => 0, 'engagement' => 0];
        $perAccount = [];
        foreach ($accounts as $a) {
            $perAccount[$a['id']] = $a + ['posts' => 0, 'engagement' => 0, 'impressions' => 0, 'likes' => 0, 'replies' => 0, 'shares' => 0,
                'followers' => $last[$a['id']] ?? null, 'followers_change' => isset($last[$a['id']], $startVal[$a['id']]) ? $last[$a['id']] - $startVal[$a['id']] : null];
        }
        $perDay = array_fill_keys($days, 0);
        $engDay = array_fill_keys($days, 0);
        $types = ['text' => ['posts' => 0, 'engagement' => 0], 'image' => ['posts' => 0, 'engagement' => 0], 'carousel' => ['posts' => 0, 'engagement' => 0], 'video' => ['posts' => 0, 'engagement' => 0]];
        $hashtags = [];
        foreach ($published as &$p) {
            $m = json_arr($p['metrics']);
            $p['m'] = $m;
            $p['engagement'] = Metrics::engagement($m);
            $p['impressions'] = (int) ($m['impressions'] ?? $m['views'] ?? 0);
            $likes = (int) ($m['likes'] ?? $m['reactions'] ?? 0);
            $replies = (int) ($m['replies'] ?? $m['comments'] ?? 0);
            $shares = (int) ($m['shares'] ?? $m['reposts'] ?? 0);
            $totals['posts']++;
            $totals['likes'] += $likes;
            $totals['replies'] += $replies;
            $totals['shares'] += $shares;
            $totals['saves'] += (int) ($m['saves'] ?? 0);
            $totals['clicks'] += (int) ($m['clicks'] ?? 0);
            $totals['impressions'] += $p['impressions'];
            $totals['engagement'] += $p['engagement'];

            if (isset($perAccount[$p['account_id']])) {
                $pa = &$perAccount[$p['account_id']];
                $pa['posts']++;
                $pa['engagement'] += $p['engagement'];
                $pa['impressions'] += $p['impressions'];
                $pa['likes'] += $likes;
                $pa['replies'] += $replies;
                $pa['shares'] += $shares;
                unset($pa);
            }
            $day = local_time($p['published_at'], 'Y-m-d', $tz);
            if (isset($perDay[$day])) {
                $perDay[$day]++;
                $engDay[$day] += $p['engagement'];
            }
            $type = $mediaTypes[$p['id']] ?? 'text';
            $p['type'] = $type;
            $types[$type]['posts']++;
            $types[$type]['engagement'] += $p['engagement'];

            if (preg_match_all('/(?<=^|\s)#([\p{L}\p{N}_]+)/u', $p['content'], $hm)) {
                foreach (array_unique(array_map('mb_strtolower', $hm[1])) as $tag) {
                    $hashtags[$tag] ??= ['tag' => $tag, 'posts' => 0, 'engagement' => 0];
                    $hashtags[$tag]['posts']++;
                    $hashtags[$tag]['engagement'] += $p['engagement'];
                }
            }
        }
        unset($p);

        // Per network
        $perNetwork = [];
        foreach ($perAccount as $a) {
            $k = $a['provider'];
            $perNetwork[$k] ??= ['provider' => $k, 'accounts' => 0, 'posts' => 0, 'engagement' => 0, 'impressions' => 0, 'followers' => null, 'followers_change' => null];
            $perNetwork[$k]['accounts']++;
            $perNetwork[$k]['posts'] += $a['posts'];
            $perNetwork[$k]['engagement'] += $a['engagement'];
            $perNetwork[$k]['impressions'] += $a['impressions'];
            if ($a['followers'] !== null) {
                $perNetwork[$k]['followers'] = ($perNetwork[$k]['followers'] ?? 0) + $a['followers'];
            }
            if ($a['followers_change'] !== null) {
                $perNetwork[$k]['followers_change'] = ($perNetwork[$k]['followers_change'] ?? 0) + $a['followers_change'];
            }
        }
        foreach ($perNetwork as &$n) {
            $n['rate'] = self::rate($n['engagement'], $n['impressions'], $n['followers'], $n['posts']);
        }
        unset($n);
        foreach ($perAccount as &$a) {
            $a['rate'] = self::rate($a['engagement'], $a['impressions'], $a['followers'], $a['posts']);
        }
        unset($a);

        foreach ($types as &$t) {
            $t['avg'] = $t['posts'] ? round($t['engagement'] / $t['posts'], 1) : 0;
        }
        unset($t);
        $hashtags = array_values(array_filter($hashtags, fn($h) => $h['posts'] >= 1));
        foreach ($hashtags as &$h) {
            $h['avg'] = round($h['engagement'] / $h['posts'], 1);
        }
        unset($h);
        usort($hashtags, fn($a, $b) => [$b['avg'], $b['posts']] <=> [$a['avg'], $a['posts']]);

        $top = $published;
        usort($top, fn($a, $b) => $b['engagement'] <=> $a['engagement']);

        return [
            'from' => $from,
            'to' => $to,
            'days' => count($days),
            'accounts' => $accounts,
            'followers' => $followerSeries,
            'followersNow' => $followersNow,
            'followersChange' => ($followersNow !== null && $followersStart !== null) ? $followersNow - $followersStart : null,
            'postSeries' => array_map(fn($d) => ['date' => $d, 'value' => $perDay[$d]], $days),
            'engagementSeries' => array_map(fn($d) => ['date' => $d, 'value' => $engDay[$d]], $days),
            'totals' => $totals,
            'rate' => self::rate($totals['engagement'], $totals['impressions'], $followersNow, $totals['posts']),
            'perNetwork' => array_values($perNetwork),
            'perAccount' => array_values($perAccount),
            'types' => $types,
            'hashtags' => array_slice($hashtags, 0, 15),
            'top' => array_slice(array_values(array_filter($top, fn($p) => $p['engagement'] > 0)), 0, 10),
            'audience' => self::audience($accountIds),
            'best' => self::bestTimes($workspaceId, $accountIds, $tz),
            'lastImport' => Db::value("SELECT MAX(date) FROM account_metrics WHERE account_id IN ($in)"),
        ];
    }

    /** Engagement rate: per impression when we have impressions, otherwise per follower per post. */
    private static function rate(int $engagement, int $impressions, ?int $followers, int $posts): ?float
    {
        if ($impressions > 0) {
            return round($engagement / $impressions * 100, 2);
        }
        if ($followers && $posts) {
            return round($engagement / $posts / $followers * 100, 2);
        }
        return null;
    }

    /** text | image | carousel | video for each post id. */
    private static function mediaTypes(array $posts): array
    {
        $ids = [];
        foreach ($posts as $p) {
            foreach (json_arr($p['media']) as $m) {
                $ids[(int) $m] = true;
            }
        }
        $mimes = $ids ? array_column(Db::all('SELECT id, mime FROM media WHERE id IN (' . implode(',', array_keys($ids)) . ')'), 'mime', 'id') : [];
        $out = [];
        foreach ($posts as $p) {
            $media = json_arr($p['media']);
            if (!$media) {
                $out[$p['id']] = 'text';
                continue;
            }
            $video = false;
            foreach ($media as $m) {
                if (str_starts_with($mimes[(int) $m] ?? '', 'video/')) {
                    $video = true;
                }
            }
            $out[$p['id']] = $video ? 'video' : (count($media) > 1 ? 'carousel' : 'image');
        }
        return $out;
    }

    /**
     * Average engagement by weekday and hour from the last 180 days of posts, in the viewer's timezone.
     * Returns ['grid' => [weekday 0-6][hour 0-23] => avg|null, 'top' => [['day','hour','avg','posts']], 'posts' => n]
     */
    public static function bestTimes(int $workspaceId, array $accountIds, string $tz): array
    {
        $in = $accountIds ? implode(',', array_map('intval', $accountIds)) : '0';
        $rows = Db::all(
            "SELECT pa.published_at, pm.data FROM post_accounts pa JOIN posts p ON p.id = pa.post_id
             JOIN post_metrics pm ON pm.post_id = pa.post_id AND pm.account_id = pa.account_id
             WHERE p.workspace_id = ? AND pa.account_id IN ($in) AND pa.status = 'published' AND pa.published_at >= ?",
            [$workspaceId, gmdate('Y-m-d H:i:s', time() - 180 * 86400)]
        );
        $sum = $count = [];
        foreach ($rows as $r) {
            $dow = (int) local_time($r['published_at'], 'w', $tz);
            $hour = (int) local_time($r['published_at'], 'G', $tz);
            $sum[$dow][$hour] = ($sum[$dow][$hour] ?? 0) + Metrics::engagement(json_arr($r['data']));
            $count[$dow][$hour] = ($count[$dow][$hour] ?? 0) + 1;
        }
        $grid = [];
        $cells = [];
        for ($d = 0; $d < 7; $d++) {
            for ($h = 0; $h < 24; $h++) {
                $n = $count[$d][$h] ?? 0;
                $avg = $n ? $sum[$d][$h] / $n : null;
                $grid[$d][$h] = $avg;
                if ($n) {
                    $cells[] = ['day' => $d, 'hour' => $h, 'avg' => round($avg, 1), 'posts' => $n];
                }
            }
        }
        // Prefer slots with more than one post so a single lucky post doesn't dominate.
        usort($cells, fn($a, $b) => [min($b['posts'], 3), $b['avg']] <=> [min($a['posts'], 3), $a['avg']]);
        return ['grid' => $grid, 'top' => count($rows) >= 8 ? array_slice($cells, 0, 3) : [], 'posts' => count($rows)];
    }

    /** Latest audience snapshot per account (age/gender/country/city where available). */
    private static function audience(array $accountIds): array
    {
        if (!$accountIds) {
            return [];
        }
        $in = implode(',', array_map('intval', $accountIds));
        $rows = Db::all(
            "SELECT aa.account_id, aa.data, a.name, a.provider FROM account_audience aa JOIN accounts a ON a.id = aa.account_id
             WHERE aa.account_id IN ($in) AND aa.date = (SELECT MAX(date) FROM account_audience WHERE account_id = aa.account_id)"
        );
        return array_map(fn($r) => ['account' => $r['name'], 'provider' => $r['provider'], 'data' => json_arr($r['data'])], $rows);
    }

    public static function dayName(int $d): string
    {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$d];
    }

    public static function hourLabel(int $h): string
    {
        return ($h % 12 ?: 12) . ($h < 12 ? ' AM' : ' PM');
    }

    public static function providerName(string $key): string
    {
        $c = Registry::class($key);
        return $c ? $c::name() : $key;
    }
}

}

namespace App {

// ---- Auth.php ----
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = $_SESSION['user_id'] ?? null;
            self::$user = $id ? Db::one('SELECT * FROM users WHERE id = ?', [$id]) : null;
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        return isset(self::user()['id']) ? (int) self::user()['id'] : null;
    }

    public static function attempt(string $email, string $password): bool
    {
        $user = Db::one('SELECT * FROM users WHERE email = ?', [$email]);
        if (!$user || !password_verify($password, $user['password'])) {
            return false;
        }
        if (password_needs_rehash($user['password'], PASSWORD_DEFAULT)) {
            Db::update('users', ['password' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        }
        self::login((int) $user['id']);
        return true;
    }

    public static function login(int $userId): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        self::$loaded = false;
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_regenerate_id(true);
        self::$user = null;
        self::$loaded = true;
    }

    public static function isAdmin(): bool
    {
        return (bool) (self::user()['is_admin'] ?? false);
    }

    public static function refresh(): void
    {
        self::$loaded = false;
    }
}

}

namespace App {

// ---- Autolists.php ----
/** Evergreen queues: at each time slot, publish the next item in the list, cycling forever. */
final class Autolists
{
    public const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /** Creates posts for slots that came due in the last 30 minutes. Runs from cron before publishing. */
    public static function runDue(?int $now = null): int
    {
        $now ??= time();
        $created = 0;
        foreach (Db::all('SELECT * FROM autolists WHERE active = 1') as $list) {
            $tz = new \DateTimeZone($list['timezone'] ?: 'UTC');
            $local = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
            foreach (json_arr($list['slots']) as $slot) {
                // Check today's and yesterday's occurrence so a slot near midnight isn't missed.
                foreach ([0, 1] as $back) {
                    $day = $local->modify("-$back day");
                    if ((int) $day->format('w') !== (int) $slot['day']) {
                        continue;
                    }
                    [$h, $m] = array_map('intval', explode(':', $slot['time']) + [1 => 0]);
                    $at = $day->setTime($h, $m);
                    $ts = $at->getTimestamp();
                    if ($ts > $now || $ts < $now - 1800) {
                        continue;
                    }
                    $key = gmdate('Y-m-d H:i', $ts);
                    $claimed = Db::query('INSERT OR IGNORE INTO autolist_runs (autolist_id, slot) VALUES (?, ?)', [$list['id'], $key])->rowCount();
                    if ($claimed && ($postId = self::publishNext($list))) {
                        Db::update('autolist_runs', ['post_id' => $postId], 'autolist_id = ? AND slot = ?', [$list['id'], $key]);
                        $created++;
                    }
                }
            }
        }
        return $created;
    }

    /** Picks the next item and creates a post for it. Returns the post id or null. */
    public static function publishNext(array $list): ?int
    {
        $items = Db::all('SELECT * FROM autolist_items WHERE autolist_id = ? ORDER BY position, id', [$list['id']]);
        $accounts = json_arr($list['accounts']);
        if (!$items || !$accounts) {
            return null;
        }
        if ($list['order_mode'] === 'random') {
            // Random among the least-posted items, so everything gets a turn.
            $min = min(array_column($items, 'times_posted'));
            $pool = array_values(array_filter($items, fn($i) => (int) $i['times_posted'] === (int) $min));
            $item = $pool[array_rand($pool)];
        } else {
            $item = $items[(int) $list['next_index'] % count($items)];
            Db::update('autolists', ['next_index' => ((int) $list['next_index'] + 1) % count($items)], 'id = ?', [$list['id']]);
        }
        $result = PostService::save(null, [
            'content' => $item['content'],
            'media' => json_arr($item['media']),
            'accounts' => $accounts,
            'scheduled_at' => now(),
            'autolist_id' => (int) $list['id'],
        ], 'schedule', (int) $list['workspace_id'], (int) ($list['created_by'] ?? 0), true);
        if ($result['errors']) {
            log_event('warning', "Autolist {$list['id']} item {$item['id']} skipped: " . implode(' ', $result['errors']));
            return null;
        }
        Db::query('UPDATE autolist_items SET times_posted = times_posted + 1, last_posted_at = ? WHERE id = ?', [now(), $item['id']]);
        return (int) $result['post']['id'];
    }

    /** Next N slot times (UTC) for display. */
    public static function upcoming(array $list, int $n = 5): array
    {
        $tz = new \DateTimeZone($list['timezone'] ?: 'UTC');
        $now = new \DateTimeImmutable('now', $tz);
        $out = [];
        for ($d = 0; $d < 14 && count($out) < 50; $d++) {
            $day = $now->modify("+$d day");
            foreach (json_arr($list['slots']) as $slot) {
                if ((int) $day->format('w') !== (int) $slot['day']) {
                    continue;
                }
                [$h, $m] = array_map('intval', explode(':', $slot['time']) + [1 => 0]);
                $at = $day->setTime($h, $m);
                if ($at > $now) {
                    $out[] = $at;
                }
            }
        }
        usort($out, fn($a, $b) => $a <=> $b);
        return array_slice($out, 0, $n);
    }
}

}

namespace App {

// ---- Blob.php ----
/**
 * File storage inside the SQLite database, so the app needs no uploads folder.
 * Files are split into chunks to keep memory use low for large videos, and served at /f/{key}.
 * Code that needs a real file (uploads to platforms, image conversion) gets a temporary copy.
 */
final class Blob
{
    private const CHUNK = 1048576; // 1 MB

    public const TYPES = [
        'jpg' => 'image/jpeg', 'png' => 'image/png', 'gif' => 'image/gif', 'webp' => 'image/webp',
        'mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm',
    ];

    /** @var string[] temporary copies made during this request */
    private static array $temp = [];

    public static function newKey(string $ext): string
    {
        return bin2hex(random_bytes(16)) . '.' . $ext;
    }

    /** Stores a file from disk under $key, replacing anything already there. */
    public static function putFile(string $key, string $file): bool
    {
        $fh = @fopen($file, 'rb');
        if (!$fh) {
            return false;
        }
        $pdo = Db::pdo();
        $pdo->beginTransaction();
        try {
            Db::query('DELETE FROM media_chunks WHERE key = ?', [$key]);
            $stmt = $pdo->prepare('INSERT INTO media_chunks (key, n, data) VALUES (?, ?, ?)');
            $n = 0;
            while (!feof($fh)) {
                $data = fread($fh, self::CHUNK);
                if ($data === false || $data === '') {
                    break;
                }
                $stmt->bindValue(1, $key);
                $stmt->bindValue(2, $n++, \PDO::PARAM_INT);
                $stmt->bindValue(3, $data, \PDO::PARAM_LOB);
                $stmt->execute();
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            fclose($fh);
            throw $e;
        }
        fclose($fh);
        return true;
    }

    public static function putString(string $key, string $data): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'mc');
        file_put_contents($tmp, $data);
        try {
            self::putFile($key, $tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public static function exists(string $key): bool
    {
        return (bool) Db::value('SELECT 1 FROM media_chunks WHERE key = ? LIMIT 1', [$key]);
    }

    public static function size(string $key): int
    {
        return (int) Db::value('SELECT COALESCE(SUM(length(data)), 0) FROM media_chunks WHERE key = ?', [$key]);
    }

    public static function get(string $key): string
    {
        $out = '';
        foreach (self::chunks($key) as $data) {
            $out .= $data;
        }
        return $out;
    }

    /** Yields the stored chunks in order without loading the whole file. */
    public static function chunks(string $key, int $from = 0): \Generator
    {
        $n = $from;
        while (true) {
            $data = Db::value('SELECT data FROM media_chunks WHERE key = ? AND n = ?', [$key, $n++]);
            if ($data === null) {
                return;
            }
            yield is_resource($data) ? stream_get_contents($data) : (string) $data;
        }
    }

    public static function delete(string $key): void
    {
        Db::query('DELETE FROM media_chunks WHERE key = ?', [$key]);
    }

    /** Deletes a media item's file and any converted copies of it. */
    public static function deleteMedia(array $m): void
    {
        self::delete($m['path']);
        Db::query("DELETE FROM media_chunks WHERE key LIKE ?", ['cache-' . (int) $m['id'] . '-%']);
    }

    /** A temporary file with the contents of $key, removed when the request ends. */
    public static function file(string $key): string
    {
        $ext = pathinfo($key, PATHINFO_EXTENSION);
        $path = sys_get_temp_dir() . '/mixcreate-' . substr(hash('sha256', Config::get('app_key') . $key), 0, 24) . ($ext !== '' ? '.' . $ext : '');
        if (isset(self::$temp[$path]) && is_file($path)) {
            return $path;
        }
        $fh = fopen($path, 'wb');
        foreach (self::chunks($key) as $data) {
            fwrite($fh, $data);
        }
        fclose($fh);
        @chmod($path, 0600);
        if (!self::$temp) {
            register_shutdown_function(static function () {
                foreach (array_keys(self::$temp) as $f) {
                    @unlink($f);
                }
            });
        }
        self::$temp[$path] = true;
        return $path;
    }

    public static function mime(string $key): string
    {
        return self::TYPES[strtolower(pathinfo($key, PATHINFO_EXTENSION))] ?? 'application/octet-stream';
    }

    /** Sends a stored file with caching and HTTP range support (needed for video). */
    public static function serve(string $key): never
    {
        if (!preg_match('/^[\w.-]+$/', $key) || !self::exists($key)) {
            http_response_code(404);
            exit('Not found');
        }
        $size = self::size($key);
        $etag = '"' . substr(sha1($key . $size), 0, 16) . '"';
        header('Content-Type: ' . self::mime($key));
        header('Cache-Control: public, max-age=31536000, immutable');
        header('ETag: ' . $etag);
        header('Accept-Ranges: bytes');
        header('X-Content-Type-Options: nosniff');
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            exit;
        }
        $start = 0;
        $end = $size - 1;
        if (preg_match('/^bytes=(\d*)-(\d*)$/', $_SERVER['HTTP_RANGE'] ?? '', $r) && ($r[1] !== '' || $r[2] !== '')) {
            if ($r[1] === '') {
                $start = max(0, $size - (int) $r[2]);
            } else {
                $start = (int) $r[1];
                $end = $r[2] !== '' ? min((int) $r[2], $size - 1) : $size - 1;
            }
            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */$size");
                exit;
            }
            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
        }
        header('Content-Length: ' . ($end - $start + 1));
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            exit;
        }
        while (ob_get_level()) {
            ob_end_clean();
        }
        $first = intdiv($start, self::CHUNK);
        $pos = $first * self::CHUNK;
        foreach (self::chunks($key, $first) as $data) {
            $len = strlen($data);
            $from = max(0, $start - $pos);
            $to = min($len, $end - $pos + 1);
            if ($to > $from) {
                echo substr($data, $from, $to - $from);
                flush();
            }
            $pos += $len;
            if ($pos > $end) {
                break;
            }
        }
        exit;
    }
}

}

namespace App {

// ---- Branding.php ----
/** White-label settings for a workspace's reports and share pages. */
final class Branding
{
    public static function for(int $workspaceId): array
    {
        $ws = Db::one('SELECT name, color, branding FROM workspaces WHERE id = ?', [$workspaceId]) ?? ['name' => '', 'color' => '#4f46e5', 'branding' => '{}'];
        $b = json_arr($ws['branding']);
        $logo = null;
        if (!empty($b['logo_media_id'])) {
            $m = Publisher::resolveMedia([(int) $b['logo_media_id']], true)[0] ?? null;
            if ($m && str_starts_with($m['mime'], 'image/')) {
                $logo = $m;
            }
        }
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string) ($b['color'] ?? '')) ? $b['color'] : ($ws['color'] ?: '#4f46e5');
        return [
            'name' => trim((string) ($b['name'] ?? '')) ?: $ws['name'],
            'color' => $color,
            'logo' => $logo,
            'logo_url' => $logo ? media_url($logo) : null,
            'hide_powered_by' => !empty($b['hide_powered_by']),
            'footer' => trim((string) ($b['footer'] ?? '')),
        ];
    }

    /** Readable text color (black or white) for a background. */
    public static function onColor(string $hex): string
    {
        $hex = ltrim($hex, '#');
        [$r, $g, $b] = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        return (0.299 * $r + 0.587 * $g + 0.114 * $b) > 160 ? '#15181e' : '#ffffff';
    }
}

}

namespace App {

// ---- Canva.php ----
/** Canva Connect API: each user links their own Canva account and imports designs as media. */
final class Canva
{
    private const API = 'https://api.canva.com/rest/v1';
    private const SCOPES = 'design:meta:read design:content:read profile:read';

    public static function enabled(): bool
    {
        return Settings::has('canva', ['client_id', 'client_secret']);
    }

    public static function redirectUri(): string
    {
        return abs_url('canva/callback');
    }

    public static function authUrl(string $state, string $verifier): string
    {
        return 'https://www.canva.com/api/oauth/authorize?' . http_build_query([
            'code_challenge' => rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='),
            'code_challenge_method' => 's256',
            'scope' => self::SCOPES,
            'response_type' => 'code',
            'client_id' => Settings::value('canva', 'client_id'),
            'state' => $state,
            'redirect_uri' => self::redirectUri(),
        ]);
    }

    private static function tokenRequest(array $form): array
    {
        $s = Settings::get('canva');
        $res = Http::post(self::API . '/oauth/token', [
            'form' => $form,
            'headers' => ['Authorization' => 'Basic ' . base64_encode($s['client_id'] . ':' . $s['client_secret'])],
        ]);
        if (!Http::ok($res) || empty($res['json']['access_token'])) {
            throw new \RuntimeException('Canva: ' . ($res['json']['error_description'] ?? $res['json']['message'] ?? 'HTTP ' . $res['status']));
        }
        $j = $res['json'];
        return ['access_token' => $j['access_token'], 'refresh_token' => $j['refresh_token'] ?? null, 'expires_at' => time() + (int) ($j['expires_in'] ?? 14400)];
    }

    public static function connect(int $userId, string $code, string $verifier): void
    {
        $token = self::tokenRequest(['grant_type' => 'authorization_code', 'code' => $code, 'code_verifier' => $verifier, 'redirect_uri' => self::redirectUri()]);
        Db::query(
            'INSERT INTO user_connections (user_id, provider, token, created_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(user_id, provider) DO UPDATE SET token = excluded.token, created_at = excluded.created_at',
            [$userId, 'canva', Crypt::encrypt($token), now()]
        );
    }

    public static function connected(int $userId): bool
    {
        return (bool) Db::value("SELECT 1 FROM user_connections WHERE user_id = ? AND provider = 'canva'", [$userId]);
    }

    public static function disconnect(int $userId): void
    {
        Db::query("DELETE FROM user_connections WHERE user_id = ? AND provider = 'canva'", [$userId]);
    }

    private static function token(int $userId): string
    {
        $row = Db::one("SELECT token FROM user_connections WHERE user_id = ? AND provider = 'canva'", [$userId]);
        $t = Crypt::decryptArray($row['token'] ?? null);
        if (!$t) {
            throw new \RuntimeException('Connect your Canva account first.');
        }
        if (($t['expires_at'] ?? 0) < time() + 120) {
            if (empty($t['refresh_token'])) {
                self::disconnect($userId);
                throw new \RuntimeException('Your Canva connection expired. Connect again.');
            }
            try {
                $t = self::tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']]);
            } catch (\RuntimeException) {
                self::disconnect($userId);
                throw new \RuntimeException('Your Canva connection expired. Connect again.');
            }
            Db::update('user_connections', ['token' => Crypt::encrypt($t)], "user_id = ? AND provider = 'canva'", [$userId]);
        }
        return $t['access_token'];
    }

    private static function api(int $userId, string $method, string $path, array $options = []): array
    {
        $options['headers'] = ($options['headers'] ?? []) + ['Authorization' => 'Bearer ' . self::token($userId)];
        $res = Http::request($method, self::API . $path, $options);
        if (!Http::ok($res)) {
            if ($res['status'] === 401) {
                self::disconnect($userId);
            }
            throw new \RuntimeException('Canva: ' . ($res['json']['message'] ?? 'HTTP ' . $res['status']));
        }
        return $res['json'];
    }

    public static function designs(int $userId, ?string $continuation = null, string $query = ''): array
    {
        $j = self::api($userId, 'GET', '/designs', ['query' => array_filter([
            'ownership' => 'any',
            'sort_by' => 'modified_descending',
            'continuation' => $continuation,
            'query' => $query,
        ])]);
        return [
            'items' => array_map(fn($d) => [
                'id' => $d['id'],
                'title' => $d['title'] ?? 'Untitled design',
                'thumbnail' => $d['thumbnail']['url'] ?? null,
                'pages' => $d['page_count'] ?? null,
            ], $j['items'] ?? []),
            'continuation' => $j['continuation'] ?? null,
        ];
    }

    /** Exports a design (PNG, or MP4 for video designs) and saves each page as media. Returns media arrays. */
    public static function import(int $userId, int $workspaceId, string $designId, string $format = 'png'): array
    {
        $format = in_array($format, ['png', 'jpg', 'mp4'], true) ? $format : 'png';
        $body = ['design_id' => $designId, 'format' => ['type' => $format] + ($format === 'jpg' ? ['quality' => 90] : []) + ($format === 'mp4' ? ['quality' => 'horizontal_1080p'] : [])];
        $job = self::api($userId, 'POST', '/exports', ['json' => $body])['job'] ?? [];
        $deadline = time() + 120;
        while (($job['status'] ?? '') === 'in_progress' && time() < $deadline) {
            sleep(2);
            $job = self::api($userId, 'GET', '/exports/' . rawurlencode($job['id']))['job'] ?? [];
        }
        if (($job['status'] ?? '') !== 'success') {
            throw new \RuntimeException('Canva export ' . (($job['status'] ?? '') === 'failed' ? 'failed: ' . ($job['error']['message'] ?? '') : 'is taking too long. Try again.'));
        }
        $meta = self::api($userId, 'GET', '/designs/' . rawurlencode($designId))['design'] ?? [];
        $title = preg_replace('/[^\w\- ]+/u', '', $meta['title'] ?? 'canva') ?: 'canva';
        $out = [];
        foreach ($job['urls'] ?? [] as $i => $u) {
            $tmp = tempnam(sys_get_temp_dir(), 'canva');
            $res = Http::get($u, ['timeout' => 300, 'headers' => ['Accept' => '*/*']]);
            if (!Http::ok($res)) {
                continue;
            }
            file_put_contents($tmp, $res['body']);
            $name = $title . (count($job['urls']) > 1 ? ' ' . ($i + 1) : '') . '.' . $format;
            $m = Controllers\MediaController::storeLocal($tmp, $name, $workspaceId, $userId);
            @unlink($tmp);
            if (!isset($m['error'])) {
                $out[] = $m;
            }
        }
        if (!$out) {
            throw new \RuntimeException('Canva didn’t return any files.');
        }
        return $out;
    }
}

}

namespace App {

// ---- ChartImage.php ----
/** Renders simple line/bar charts to PNG with GD (for PowerPoint exports). */
final class ChartImage
{
    /** @param array<array{date: string, value: ?float}> $series */
    public static function png(array $series, string $type, int $w, int $h, string $color, int $scale = 2): string
    {
        $W = $w * $scale;
        $H = $h * $scale;
        $im = imagecreatetruecolor($W, $H);
        imageantialias($im, true);
        $white = imagecolorallocate($im, 255, 255, 255);
        imagefill($im, 0, 0, $white);
        [$r, $g, $b] = sscanf(ltrim($color, '#'), '%02x%02x%02x');
        $c = imagecolorallocate($im, $r, $g, $b);
        $cSoft = imagecolorallocatealpha($im, $r, $g, $b, 105);
        $grid = imagecolorallocate($im, 232, 234, 238);
        $muted = imagecolorallocate($im, 120, 128, 140);

        $vals = array_values(array_filter(array_column($series, 'value'), fn($v) => $v !== null));
        $font = 3;
        $padL = 46 * $scale;
        $padB = 22 * $scale;
        $padT = 10 * $scale;
        $padR = 10 * $scale;
        if (!$vals || ($type === 'line' && count($vals) < 2) || ($type === 'bar' && max($vals) == 0)) {
            imagestring($im, 5, (int) ($W / 2 - 40), (int) ($H / 2 - 8), 'No data yet', $muted);
            return self::out($im);
        }
        $min = $type === 'bar' ? 0 : min($vals);
        $max = max($vals);
        if ($max == $min) {
            $max = $min + 1;
        }
        $n = count($series);
        $px = fn($i) => $padL + ($n === 1 ? ($W - $padL - $padR) / 2 : $i * ($W - $padL - $padR) / ($n - 1));
        $py = fn($v) => $padT + ($H - $padT - $padB) * (1 - ($v - $min) / ($max - $min));

        imagesetthickness($im, max(1, $scale));
        for ($t = 0; $t <= 4; $t++) {
            $v = $min + ($max - $min) * $t / 4;
            $y = (int) $py($v);
            imageline($im, $padL, $y, $W - $padR, $y, $grid);
            $label = $v >= 10000 ? round($v / 1000, 1) . 'k' : (string) round($v, $max - $min < 5 ? 1 : 0);
            imagestring($im, $font, (int) ($padL - 8 - strlen($label) * 7), $y - 6, $label, $muted);
        }
        $every = max(1, (int) ceil($n / 6));
        foreach ($series as $i => $s) {
            if ($i % $every === 0 || $i === $n - 1) {
                $label = date('M j', strtotime($s['date']));
                imagestring($im, $font, (int) min($W - strlen($label) * 7 - 2, max(0, $px($i) - strlen($label) * 3.5)), $H - $padB + 6, $label, $muted);
            }
        }
        if ($type === 'bar') {
            $bw = max(2, (int) (($W - $padL - $padR) / $n * 0.6));
            foreach ($series as $i => $s) {
                $v = (float) ($s['value'] ?? 0);
                if ($v > 0) {
                    $x = (int) ($n === 1 ? $px($i) : $padL + $i * (($W - $padL - $padR - $bw) / max(1, $n - 1)));
                    imagefilledrectangle($im, $x, (int) $py($v), $x + $bw, (int) $py($min), $c);
                }
            }
        } else {
            $pts = [];
            foreach ($series as $i => $s) {
                if ($s['value'] !== null) {
                    $pts[] = [(int) $px($i), (int) $py((float) $s['value'])];
                }
            }
            $poly = [];
            foreach ($pts as [$x, $y]) {
                $poly[] = $x;
                $poly[] = $y;
            }
            array_push($poly, end($pts)[0], (int) $py($min), $pts[0][0], (int) $py($min));
            imagefilledpolygon($im, $poly, $cSoft);
            imagesetthickness($im, 3 * $scale);
            for ($i = 1; $i < count($pts); $i++) {
                imageline($im, $pts[$i - 1][0], $pts[$i - 1][1], $pts[$i][0], $pts[$i][1], $c);
            }
        }
        return self::out($im);
    }

    private static function out(\GdImage $im): string
    {
        ob_start();
        imagepng($im);
        imagedestroy($im);
        return (string) ob_get_clean();
    }
}

}

namespace App {

// ---- Competitors.php ----
use App\Providers\ProviderException;
use App\Providers\Registry;

/**
 * Public stats for accounts you don't own. Only networks that expose them are supported.
 * X, Instagram and YouTube borrow a connected account's token from the same workspace.
 */
final class Competitors
{
    public const NETWORKS = [
        'bluesky' => ['label' => 'Bluesky', 'placeholder' => 'handle.bsky.social'],
        'mastodon' => ['label' => 'Mastodon', 'placeholder' => 'user@mastodon.social'],
        'x' => ['label' => 'X', 'placeholder' => 'username'],
        'instagram' => ['label' => 'Instagram', 'placeholder' => 'username (business or creator)'],
        'youtube' => ['label' => 'YouTube', 'placeholder' => '@handle'],
    ];

    /**
     * Fetches profile + recent post stats. Returns ['external_id','name','avatar','followers','posts','recent' => [['date','engagement']...]].
     */
    public static function fetch(int $workspaceId, string $network, string $handle): array
    {
        $handle = trim($handle);
        return match ($network) {
            'bluesky' => self::bluesky(ltrim($handle, '@')),
            'mastodon' => self::mastodon(ltrim($handle, '@')),
            'x' => self::x($workspaceId, ltrim($handle, '@')),
            'instagram' => self::instagram($workspaceId, ltrim($handle, '@')),
            'youtube' => self::youtube($workspaceId, $handle),
            default => throw new ProviderException('Unsupported network.'),
        };
    }

    private static function bluesky(string $handle): array
    {
        $p = Http::get('https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile', ['query' => ['actor' => $handle]]);
        if (!Http::ok($p)) {
            throw new ProviderException('Bluesky account not found.');
        }
        $feed = Http::get('https://public.api.bsky.app/xrpc/app.bsky.feed.getAuthorFeed', ['query' => ['actor' => $handle, 'limit' => 30, 'filter' => 'posts_no_replies']])['json']['feed'] ?? [];
        $recent = [];
        foreach ($feed as $item) {
            if (isset($item['reason'])) {
                continue; // reposts
            }
            $post = $item['post'];
            $recent[] = ['date' => substr($post['indexedAt'] ?? '', 0, 10), 'engagement' => (int) ($post['likeCount'] ?? 0) + (int) ($post['repostCount'] ?? 0) + (int) ($post['replyCount'] ?? 0) + (int) ($post['quoteCount'] ?? 0)];
        }
        $j = $p['json'];
        return ['external_id' => $j['did'], 'name' => ($j['displayName'] ?? '') ?: $j['handle'], 'avatar' => $j['avatar'] ?? null, 'followers' => (int) ($j['followersCount'] ?? 0), 'posts' => (int) ($j['postsCount'] ?? 0), 'recent' => $recent];
    }

    private static function mastodon(string $handle): array
    {
        if (!preg_match('/^([^@\s]+)@([a-z0-9.-]+\.[a-z]{2,})$/i', $handle, $m)) {
            throw new ProviderException('Use the full handle, like user@mastodon.social.');
        }
        $server = strtolower($m[2]);
        $acct = Http::get("https://$server/api/v1/accounts/lookup", ['query' => ['acct' => $m[1]]]);
        if (!Http::ok($acct)) {
            throw new ProviderException('Mastodon account not found.');
        }
        $a = $acct['json'];
        $statuses = Http::get("https://$server/api/v1/accounts/{$a['id']}/statuses", ['query' => ['limit' => 30, 'exclude_replies' => 'true', 'exclude_reblogs' => 'true']])['json'];
        $recent = [];
        foreach (is_array($statuses) ? $statuses : [] as $s) {
            $recent[] = ['date' => substr($s['created_at'] ?? '', 0, 10), 'engagement' => (int) ($s['favourites_count'] ?? 0) + (int) ($s['reblogs_count'] ?? 0) + (int) ($s['replies_count'] ?? 0)];
        }
        return ['external_id' => $server . ':' . $a['id'], 'name' => ($a['display_name'] ?? '') ?: $a['username'], 'avatar' => $a['avatar'] ?? null, 'followers' => (int) ($a['followers_count'] ?? 0), 'posts' => (int) ($a['statuses_count'] ?? 0), 'recent' => $recent];
    }

    /** Returns a provider for any authorized account of $provider in the workspace. */
    public static function borrow(int $workspaceId, string $provider, string $why): array
    {
        $acc = Db::one('SELECT * FROM accounts WHERE workspace_id = ? AND provider = ? AND authorized = 1 ORDER BY id LIMIT 1', [$workspaceId, $provider]);
        if (!$acc) {
            throw new ProviderException($why);
        }
        $p = Registry::withFreshToken($acc);
        return [$acc, $p instanceof Providers\OAuth2Provider ? $p->lookupToken() : ''];
    }

    private static function x(int $workspaceId, string $username): array
    {
        [, $token] = self::borrow($workspaceId, 'x', 'Connect an X account in this workspace to track X competitors.');
        $h = ['Authorization' => "Bearer $token"];
        $u = Http::get('https://api.x.com/2/users/by/username/' . rawurlencode($username), ['query' => ['user.fields' => 'public_metrics,profile_image_url'], 'headers' => $h]);
        $d = $u['json']['data'] ?? null;
        if (!$d) {
            throw new ProviderException('X account not found' . (isset($u['json']['title']) ? ': ' . $u['json']['title'] : '.'));
        }
        $tweets = Http::get("https://api.x.com/2/users/{$d['id']}/tweets", ['query' => ['max_results' => 20, 'exclude' => 'replies,retweets', 'tweet.fields' => 'public_metrics,created_at'], 'headers' => $h])['json']['data'] ?? [];
        $recent = array_map(fn($t) => ['date' => substr($t['created_at'] ?? '', 0, 10), 'engagement' => array_sum(array_intersect_key($t['public_metrics'] ?? [], array_flip(['like_count', 'retweet_count', 'reply_count', 'quote_count'])))], $tweets);
        $pm = $d['public_metrics'] ?? [];
        return ['external_id' => $d['id'], 'name' => $d['name'], 'avatar' => $d['profile_image_url'] ?? null, 'followers' => (int) ($pm['followers_count'] ?? 0), 'posts' => (int) ($pm['tweet_count'] ?? 0), 'recent' => $recent];
    }

    /** Instagram business discovery needs a Facebook Page linked to an Instagram professional account. */
    private static function instagram(int $workspaceId, string $username): array
    {
        [$page, $token] = self::borrow($workspaceId, 'facebook', 'Connect a Facebook Page that’s linked to an Instagram business account to track Instagram competitors.');
        $graph = 'https://graph.facebook.com/' . Providers\Facebook::VERSION;
        $ig = Http::get("$graph/{$page['provider_id']}", ['query' => ['fields' => 'instagram_business_account', 'access_token' => $token]])['json']['instagram_business_account']['id'] ?? null;
        if (!$ig) {
            throw new ProviderException('Your connected Facebook Page isn’t linked to an Instagram business account.');
        }
        $fields = 'business_discovery.username(' . $username . '){username,name,profile_picture_url,followers_count,media_count,media.limit(25){like_count,comments_count,timestamp}}';
        $r = Http::get("$graph/$ig", ['query' => ['fields' => $fields, 'access_token' => $token]]);
        $bd = $r['json']['business_discovery'] ?? null;
        if (!$bd) {
            throw new ProviderException('Instagram account not found, or it isn’t a business/creator account. ' . ($r['json']['error']['message'] ?? ''));
        }
        $recent = array_map(fn($m) => ['date' => substr($m['timestamp'] ?? '', 0, 10), 'engagement' => (int) ($m['like_count'] ?? 0) + (int) ($m['comments_count'] ?? 0)], $bd['media']['data'] ?? []);
        return ['external_id' => $bd['id'] ?? $username, 'name' => ($bd['name'] ?? '') ?: $bd['username'], 'avatar' => $bd['profile_picture_url'] ?? null, 'followers' => (int) ($bd['followers_count'] ?? 0), 'posts' => (int) ($bd['media_count'] ?? 0), 'recent' => $recent];
    }

    private static function youtube(int $workspaceId, string $handle): array
    {
        [, $token] = self::borrow($workspaceId, 'youtube', 'Connect a YouTube channel in this workspace to track YouTube competitors.');
        $h = ['Authorization' => "Bearer $token"];
        $handle = '@' . ltrim(preg_replace('#^https?://(www\.)?youtube\.com/#', '', $handle), '@');
        $c = Http::get('https://www.googleapis.com/youtube/v3/channels', ['query' => ['part' => 'snippet,statistics,contentDetails', 'forHandle' => $handle], 'headers' => $h])['json']['items'][0] ?? null;
        if (!$c) {
            throw new ProviderException('YouTube channel not found.');
        }
        $uploads = $c['contentDetails']['relatedPlaylists']['uploads'] ?? '';
        $items = $uploads ? (Http::get('https://www.googleapis.com/youtube/v3/playlistItems', ['query' => ['part' => 'contentDetails', 'playlistId' => $uploads, 'maxResults' => 20], 'headers' => $h])['json']['items'] ?? []) : [];
        $ids = array_column(array_column($items, 'contentDetails'), 'videoId');
        $recent = [];
        if ($ids) {
            $vids = Http::get('https://www.googleapis.com/youtube/v3/videos', ['query' => ['part' => 'statistics,snippet', 'id' => implode(',', $ids)], 'headers' => $h])['json']['items'] ?? [];
            foreach ($vids as $v) {
                $s = $v['statistics'] ?? [];
                $recent[] = ['date' => substr($v['snippet']['publishedAt'] ?? '', 0, 10), 'engagement' => (int) ($s['likeCount'] ?? 0) + (int) ($s['commentCount'] ?? 0)];
            }
        }
        $s = $c['statistics'] ?? [];
        return ['external_id' => $c['id'], 'name' => $c['snippet']['title'] ?? $handle, 'avatar' => $c['snippet']['thumbnails']['default']['url'] ?? null, 'followers' => (int) ($s['subscriberCount'] ?? 0), 'posts' => (int) ($s['videoCount'] ?? 0), 'recent' => $recent];
    }

    /** Saves today's snapshot for one competitor. */
    public static function refresh(array $c): void
    {
        try {
            $d = self::fetch((int) $c['workspace_id'], $c['provider'], $c['handle']);
        } catch (\Throwable $e) {
            Db::update('competitors', ['error' => mb_substr($e->getMessage(), 0, 300)], 'id = ?', [$c['id']]);
            return;
        }
        $recent = $d['recent'];
        $cut = gmdate('Y-m-d', time() - 30 * 86400);
        $last30 = array_filter($recent, fn($r) => $r['date'] >= $cut);
        $data = [
            'avg_engagement' => $recent ? round(array_sum(array_column($recent, 'engagement')) / count($recent), 1) : null,
            'posts_30d' => count($last30) >= count($recent) && count($recent) >= 20 ? null : count($last30), // null = at least that many (sample full)
            'sample' => count($recent),
        ];
        Db::update('competitors', ['external_id' => $d['external_id'], 'name' => mb_substr($d['name'], 0, 200), 'avatar' => $d['avatar'], 'error' => null], 'id = ?', [$c['id']]);
        Db::query(
            'INSERT INTO competitor_metrics (competitor_id, date, followers, posts, data) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(competitor_id, date) DO UPDATE SET followers = excluded.followers, posts = excluded.posts, data = excluded.data',
            [$c['id'], gmdate('Y-m-d'), $d['followers'], $d['posts'], json_encode($data)]
        );
    }

    /** Once a day per competitor, from cron. */
    public static function refreshDue(): int
    {
        $rows = Db::all(
            "SELECT c.* FROM competitors c WHERE NOT EXISTS (SELECT 1 FROM competitor_metrics m WHERE m.competitor_id = c.id AND m.date = ?) LIMIT 25",
            [gmdate('Y-m-d')]
        );
        foreach ($rows as $c) {
            self::refresh($c);
        }
        return count($rows);
    }
}

}

namespace App {

// ---- Config.php ----
/**
 * Settings live in a hidden file next to index.php (.ht.mixcreate-config.php), created on first run
 * with fresh secrets. It's a PHP file, so requesting it in a browser shows nothing. The SQLite database
 * goes one folder up when that's writable (out of the web server's reach), otherwise next to index.php
 * with a random hidden name. db_path in the settings file says where it is.
 */
final class Config
{
    public const FILE = '.ht.mixcreate-config.php';

    private static array $values = [];

    public static function path(): string
    {
        return BASE_PATH . '/' . self::FILE;
    }

    public static function load(): void
    {
        $file = self::path();
        if (!is_file($file)) {
            self::create($file);
        }
        self::$values = require $file;
        if (empty(self::$values['app_key'])) {
            http_response_code(500);
            exit(self::FILE . ' is missing app_key. Set it to a random 64-character hex string.');
        }
        if (empty(self::$values['db_path'])) {
            self::$values['db_path'] = BASE_PATH . '/.ht.mixcreate.sqlite';
        } elseif (!str_starts_with((string) self::$values['db_path'], '/') && !preg_match('#^[A-Za-z]:[\\\\/]#', (string) self::$values['db_path'])) {
            self::$values['db_path'] = BASE_PATH . '/' . self::$values['db_path'];
        }
    }

    private static function create(string $file): void
    {
        $values = [
            'app_name' => 'MixCreate',
            // Public URL without a trailing slash. Leave empty to detect it.
            'app_url' => '',
            // Encrypts access tokens and API keys. Changing it disconnects every account.
            'app_key' => bin2hex(random_bytes(32)),
            // Secret for the web cron URL: /cron?key=...
            'cron_key' => bin2hex(random_bytes(16)),
            // The database file: an absolute path, or relative to the folder index.php is in.
            'db_path' => self::defaultDbPath(),
            'max_upload_mb' => 200,
            // Shows errors and enables the Sandbox platform for testing.
            'debug' => false,
        ];
        $php = "<?php\n// MixCreate settings. Keep this file private and back it up with the database.\nreturn " . var_export($values, true) . ";\n";
        if (@file_put_contents($file, $php, LOCK_EX) === false) {
            http_response_code(500);
            header('Content-Type: text/plain; charset=utf-8');
            exit('MixCreate needs to create its settings file (' . self::FILE . ') next to index.php. Make that folder writable by the web server, then reload.');
        }
        @chmod($file, 0640);
    }

    /**
     * Prefers the folder above the web root, where the web server can't reach the file at all
     * (on CloudPanel: /home/<user>/htdocs). Falls back to a hidden, randomly named file next to index.php.
     */
    private static function defaultDbPath(): string
    {
        $name = '.ht.mixcreate-' . bin2hex(random_bytes(8)) . '.sqlite';
        $parent = dirname(BASE_PATH);
        if ($parent !== BASE_PATH && $parent !== '/' && @is_writable($parent)) {
            return $parent . '/' . $name;
        }
        return $name;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $default;
    }

    /** Tests and the setup screen can override values for this request only. */
    public static function set(string $key, mixed $value): void
    {
        self::$values[$key] = $value;
    }
}

}

namespace App\Controllers {

// ---- Controllers/AccountsController.php ----
use App\Auth;
use App\Crypt;
use App\Db;
use App\Providers\ProviderException;
use App\Providers\Registry;
use App\Webhooks;
use App\Workspace;

final class AccountsController
{
    public function index(): void
    {
        $accounts = Db::all('SELECT * FROM accounts WHERE workspace_id = ? ORDER BY provider, name', [Workspace::id()]);
        $providers = [];
        foreach (Registry::all() as $key => $class) {
            $providers[$key] = [
                'name' => $class::name(),
                'color' => $class::color(),
                'implemented' => $class::implemented(),
                'ready' => Registry::ready($key),
            ];
        }
        view('accounts/index', [
            'title' => 'Accounts',
            'accounts' => $accounts,
            'providers' => $providers,
        ]);
    }

    public function connect(string $provider): void
    {
        $class = $this->readyProvider($provider);
        if ($class::connectFields()) {
            view('accounts/connect', [
                'title' => 'Connect ' . $class::name(),
                'provider' => $provider,
                'name' => $class::name(),
                'fields' => $class::connectFields(),
                'hint' => $class::setupHint(),
            ]);
        }
        $this->startOAuth($provider, []);
    }

    public function connectSubmit(string $provider): void
    {
        $class = $this->readyProvider($provider);
        $form = [];
        foreach (array_keys($class::connectFields()) as $field) {
            $form[$field] = (string) input($field, '');
        }
        if ($class::usesOAuth()) {
            $this->startOAuth($provider, $form);
        }
        try {
            $accounts = Registry::make($provider)->connect($form, '');
        } catch (\Throwable $e) {
            flash('error', $this->message($e));
            redirect('/accounts/connect/' . $provider);
        }
        $this->saveAccounts($provider, $accounts);
    }

    public function callback(string $provider): void
    {
        $this->readyProvider($provider);
        $pending = $_SESSION['_oauth'] ?? null;
        unset($_SESSION['_oauth']);
        if (!$pending || $pending['provider'] !== $provider || !hash_equals($pending['state'], (string) input('state', ''))) {
            flash('error', 'The connection expired or was started in another browser. Try again.');
            redirect('/accounts');
        }
        if (input('error')) {
            flash('error', 'Connection cancelled: ' . (string) (input('error_description') ?: input('error')));
            redirect('/accounts');
        }
        try {
            $p = Registry::make($provider);
            $p->stash = $pending['stash'];
            $accounts = $p->connect($_GET + ['_form' => $pending['form']], abs_url('accounts/callback/' . $provider));
        } catch (\Throwable $e) {
            log_event('error', "Connect $provider failed: " . $e->getMessage());
            flash('error', $this->message($e));
            redirect('/accounts');
        }
        $this->saveAccounts($provider, $accounts);
    }

    /** Lets the admin pick which pages, boards or locations to add when a login returns several. */
    public function choose(): void
    {
        $pending = $_SESSION['_choose'] ?? null;
        if (!$pending) {
            redirect('/accounts');
        }
        $accounts = json_decode((string) Crypt::decrypt($pending['accounts']), true) ?: [];
        view('accounts/choose', [
            'title' => 'Choose accounts',
            'provider' => $pending['provider'],
            'name' => Registry::class($pending['provider'])::name(),
            'accounts' => $accounts,
        ]);
    }

    public function chooseSubmit(): void
    {
        $pending = $_SESSION['_choose'] ?? null;
        unset($_SESSION['_choose']);
        if (!$pending) {
            redirect('/accounts');
        }
        $accounts = json_decode((string) Crypt::decrypt($pending['accounts']), true) ?: [];
        $picked = array_map('strval', (array) input('pick', []));
        $accounts = array_values(array_filter($accounts, fn($a) => in_array((string) $a['provider_id'], $picked, true)));
        if (!$accounts) {
            flash('error', 'Nothing was selected.');
            redirect('/accounts');
        }
        $this->store($pending['provider'], $accounts);
    }

    public function delete(string $id): void
    {
        $a = Db::one('SELECT * FROM accounts WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$a) {
            abort(404);
        }
        Db::query('DELETE FROM accounts WHERE id = ?', [$a['id']]);
        Webhooks::dispatch(Workspace::id(), 'account.disconnected', ['account' => self::toArray($a)]);
        flash('success', 'Removed ' . $a['name'] . '. Its scheduled posts will skip it.');
        redirect('/accounts');
    }

    private function startOAuth(string $provider, array $form): never
    {
        $state = bin2hex(random_bytes(16));
        try {
            $p = Registry::make($provider);
            $url = $p->authUrl(abs_url('accounts/callback/' . $provider), $state, $form);
        } catch (\Throwable $e) {
            flash('error', $this->message($e));
            redirect('/accounts');
        }
        $_SESSION['_oauth'] = ['provider' => $provider, 'state' => $state, 'form' => $form, 'stash' => $p->stash];
        redirect($url);
    }

    private function saveAccounts(string $provider, array $accounts): never
    {
        if (!$accounts) {
            flash('error', 'No accounts were found to connect.');
            redirect('/accounts');
        }
        if (count($accounts) > 1) {
            $_SESSION['_choose'] = ['provider' => $provider, 'accounts' => Crypt::encrypt(json_encode($accounts))];
            redirect('/accounts/choose');
        }
        $this->store($provider, $accounts);
    }

    private function store(string $provider, array $accounts): never
    {
        $wsId = Workspace::id();
        foreach ($accounts as $a) {
            $row = [
                'name' => mb_substr((string) ($a['name'] ?? ''), 0, 200),
                'username' => mb_substr((string) ($a['username'] ?? ''), 0, 200),
                'avatar' => $a['avatar'] ?? null,
                'token' => Crypt::encrypt($a['token'] ?? []),
                'data' => json_encode($a['data'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES),
                'authorized' => 1,
                'updated_at' => now(),
            ];
            $existing = Db::one('SELECT id FROM accounts WHERE workspace_id = ? AND provider = ? AND provider_id = ?', [$wsId, $provider, (string) $a['provider_id']]);
            if ($existing) {
                Db::update('accounts', $row, 'id = ?', [$existing['id']]);
                $id = (int) $existing['id'];
            } else {
                $id = Db::insert('accounts', $row + [
                    'workspace_id' => $wsId,
                    'provider' => $provider,
                    'provider_id' => (string) $a['provider_id'],
                    'created_at' => now(),
                ]);
            }
            Webhooks::dispatch($wsId, 'account.connected', ['account' => self::toArray(Db::one('SELECT * FROM accounts WHERE id = ?', [$id]))]);
        }
        $n = count($accounts);
        flash('success', $n === 1 ? 'Connected ' . $accounts[0]['name'] . '.' : "Connected $n accounts.");
        redirect('/accounts');
    }

    /** @return class-string<\App\Providers\Provider> */
    private function readyProvider(string $provider): string
    {
        $class = Registry::class($provider);
        if (!$class || !$class::implemented()) {
            abort(404);
        }
        if (!Registry::ready($provider)) {
            flash('error', $class::name() . ' needs API keys first.' . (Auth::isAdmin() ? '' : ' Ask your MixCreate admin to add them.'));
            redirect(Auth::isAdmin() ? '/admin/services#' . $provider : '/accounts');
        }
        return $class;
    }

    private function message(\Throwable $e): string
    {
        return $e instanceof ProviderException || $e instanceof \RuntimeException
            ? $e->getMessage()
            : 'Something went wrong while connecting.';
    }

    public static function toArray(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'provider' => $a['provider'],
            'name' => $a['name'],
            'username' => $a['username'],
            'avatar' => $a['avatar'],
            'authorized' => (bool) $a['authorized'],
        ];
    }
}

}

namespace App\Controllers {

// ---- Controllers/AdsController.php ----
use App\Ads;
use App\Crypt;
use App\Db;
use App\Workspace;

final class AdsController
{
    public function index(): void
    {
        [$from, $to, $days] = AnalyticsController::range();
        $wsId = Workspace::id();
        $accounts = Db::all('SELECT * FROM ad_accounts WHERE workspace_id = ? ORDER BY provider, name', [$wsId]);
        $pick = (int) input('ad_account', 0);
        $ids = $pick ? [$pick] : array_map('intval', array_column($accounts, 'id'));
        $ids = array_values(array_intersect($ids, array_map('intval', array_column($accounts, 'id'))));
        $in = $ids ? implode(',', $ids) : '0';

        $totals = Db::one("SELECT COALESCE(SUM(spend),0) AS spend, COALESCE(SUM(impressions),0) AS impressions, COALESCE(SUM(clicks),0) AS clicks, COALESCE(SUM(conversions),0) AS conversions FROM ad_metrics WHERE ad_account_id IN ($in) AND date BETWEEN ? AND ?", [$from, $to]);
        $daily = array_column(Db::all("SELECT date, SUM(spend) AS spend FROM ad_metrics WHERE ad_account_id IN ($in) AND date BETWEEN ? AND ? GROUP BY date", [$from, $to]), 'spend', 'date');
        $series = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
            $d = date('Y-m-d', $t);
            $series[] = ['date' => $d, 'value' => round((float) ($daily[$d] ?? 0), 2)];
        }
        $campaigns = Db::all(
            "SELECT m.ad_account_id, a.provider, a.name AS account_name, a.currency, m.campaign_id, MAX(m.campaign_name) AS campaign_name,
                    SUM(m.spend) AS spend, SUM(m.impressions) AS impressions, SUM(m.clicks) AS clicks, SUM(m.conversions) AS conversions
             FROM ad_metrics m JOIN ad_accounts a ON a.id = m.ad_account_id
             WHERE m.ad_account_id IN ($in) AND m.date BETWEEN ? AND ? GROUP BY m.ad_account_id, m.campaign_id ORDER BY spend DESC LIMIT 100",
            [$from, $to]
        );
        $currencies = array_unique(array_column(array_filter($accounts, fn($a) => in_array((int) $a['id'], $ids, true)), 'currency'));
        $ready = [];
        foreach (array_keys(Ads::PROVIDERS) as $p) {
            $ready[$p] = Ads::ready($p);
        }
        view('ads/index', [
            'title' => 'Ads',
            'accounts' => $accounts,
            'pick' => $pick,
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'totals' => $totals,
            'series' => $series,
            'campaigns' => $campaigns,
            'currency' => count($currencies) === 1 ? reset($currencies) : null,
            'mixedCurrency' => count($currencies) > 1,
            'ready' => $ready,
        ]);
    }

    public function connect(string $provider): void
    {
        if (!isset(Ads::PROVIDERS[$provider]) || !Ads::ready($provider)) {
            flash('error', 'That ads platform isn’t set up. An instance admin can add it under Integrations.');
            redirect('/ads');
        }
        $state = bin2hex(random_bytes(16));
        $_SESSION['_ads'] = ['provider' => $provider, 'state' => $state];
        redirect(Ads::authUrl($provider, $state));
    }

    public function callback(string $provider): void
    {
        $pending = $_SESSION['_ads'] ?? null;
        unset($_SESSION['_ads']);
        if (!$pending || $pending['provider'] !== $provider || !hash_equals($pending['state'], (string) input('state', ''))) {
            flash('error', 'The connection expired. Try again.');
            redirect('/ads');
        }
        try {
            $found = Ads::connect($provider, $_GET);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/ads');
        }
        if (!$found) {
            flash('error', 'No ad accounts were found for that login.');
            redirect('/ads');
        }
        $_SESSION['_ads_choose'] = ['provider' => $provider, 'accounts' => Crypt::encrypt(json_encode($found))];
        redirect('/ads/choose');
    }

    public function choose(): void
    {
        $p = $_SESSION['_ads_choose'] ?? null;
        if (!$p) {
            redirect('/ads');
        }
        view('ads/choose', ['title' => 'Choose ad accounts', 'provider' => $p['provider'], 'accounts' => json_decode((string) Crypt::decrypt($p['accounts']), true) ?: []]);
    }

    public function chooseSubmit(): void
    {
        $p = $_SESSION['_ads_choose'] ?? null;
        unset($_SESSION['_ads_choose']);
        if (!$p) {
            redirect('/ads');
        }
        $picked = array_map('strval', (array) input('pick', []));
        $n = 0;
        foreach (json_decode((string) Crypt::decrypt($p['accounts']), true) ?: [] as $a) {
            if (!in_array((string) $a['external_id'], $picked, true)) {
                continue;
            }
            Db::query(
                'INSERT INTO ad_accounts (workspace_id, provider, external_id, name, currency, token, data, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT(workspace_id, provider, external_id) DO UPDATE SET name = excluded.name, currency = excluded.currency, token = excluded.token, authorized = 1',
                [Workspace::id(), $p['provider'], $a['external_id'], $a['name'], $a['currency'], Crypt::encrypt($a['token']), json_encode($a['data']), now()]
            );
            $n++;
        }
        flash('success', $n ? "Connected $n ad account" . ($n === 1 ? '' : 's') . '. The last 30 days import within a few minutes.' : 'Nothing selected.');
        redirect('/ads');
    }

    public function sync(string $id): void
    {
        $acc = Db::one('SELECT * FROM ad_accounts WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$acc) {
            abort(404);
        }
        try {
            Ads::import($acc, gmdate('Y-m-d', time() - 30 * 86400), gmdate('Y-m-d'));
            flash('success', 'Synced ' . $acc['name'] . '.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/ads');
    }

    public function delete(string $id): void
    {
        Db::query('DELETE FROM ad_accounts WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Ad account removed.');
        redirect('/ads');
    }
}

}

namespace App\Controllers {

// ---- Controllers/AiController.php ----
use App\Ai;
use App\Db;
use App\Providers\Registry;
use App\Settings;
use App\Workspace;

final class AiController
{
    public function assist(): void
    {
        if (!Ai::enabled(Workspace::id())) {
            json_response(['error' => 'AI isn’t set up. Add an OpenRouter key in workspace settings, or ask your admin to add one under Integrations.'], 422);
        }
        $action = (string) input('action', '');
        $text = (string) input('text', '');
        $idea = (string) input('idea', '');
        $ids = array_map('intval', (array) input('accounts', []));
        $networks = [];
        $limit = 2200;
        if ($ids) {
            $rows = Db::all('SELECT DISTINCT provider FROM accounts WHERE workspace_id = ? AND id IN (' . implode(',', $ids) . ')', [Workspace::id()]);
            foreach ($rows as $r) {
                if ($c = Registry::class($r['provider'])) {
                    $networks[] = $c::name();
                    $limit = min($limit, $c::textLimit());
                }
            }
        }
        try {
            $out = Ai::assist($action, $text, $idea, $limit, $networks, (string) (Ai::config(Workspace::id())['brand_voice'] ?? ''), Workspace::id());
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
        $variants = $action === 'variants' ? array_values(array_filter(array_map('trim', preg_split('/^\s*-{3,}\s*$/m', $out)))) : [$out];
        json_response(['text' => $out, 'variants' => $variants]);
    }
}

}

namespace App\Controllers {

// ---- Controllers/AnalyticsController.php ----
use App\Analytics;
use App\Workspace;

final class AnalyticsController
{
    /** Resolves ?days= or ?from=&to= into a Y-m-d range in the user's timezone. */
    public static function range(): array
    {
        $tz = new \DateTimeZone(user_tz());
        $today = new \DateTimeImmutable('today', $tz);
        $from = (string) input('from', '');
        $to = (string) input('to', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && $from <= $to) {
            $span = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days;
            if ($span <= 366) {
                return [$from, $to, null];
            }
        }
        $days = in_array((int) input('days', 30), [7, 30, 90, 365], true) ? (int) input('days', 30) : 30;
        return [$today->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), $today->format('Y-m-d'), $days];
    }

    public static function selectedAccounts(): array
    {
        $accounts = PostsController::accounts();
        $all = array_map('intval', array_column($accounts, 'id'));
        $pick = (int) input('account', 0);
        return [$accounts, $pick && in_array($pick, $all, true) ? [$pick] : $all, $pick];
    }

    public function index(): void
    {
        [$from, $to, $days] = self::range();
        [$accounts, $ids, $accountId] = self::selectedAccounts();
        $tab = in_array(input('tab'), ['overview', 'networks', 'content', 'timing', 'audience'], true) ? input('tab') : 'overview';
        $data = Analytics::build(Workspace::id(), $ids, $from, $to, user_tz());
        view('analytics/index', [
            'title' => 'Analytics',
            'tab' => $tab,
            'days' => $days,
            'accounts' => $accounts,
            'accountId' => $accountId,
            'a' => $data,
        ]);
    }
}

}

namespace App\Controllers {

// ---- Controllers/ApiController.php ----
use App\Db;
use App\PostService;

/**
 * REST API, v1. Authenticate with "Authorization: Bearer mc_...".
 * A token acts as its user inside the token's workspace with that user's current role.
 */
final class ApiController
{
    private array $user;
    private int $workspaceId;
    private string $role;

    public function __construct()
    {
        header('Content-Type: application/json; charset=utf-8');
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        // ?api_token= is accepted for tools that can't send headers (Google Sheets IMPORTDATA, Looker Studio).
        if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) && !(is_string($_GET['api_token'] ?? null) && preg_match('/^(mc_[0-9a-f]+)$/', $_GET['api_token'], $m))) {
            json_response(['error' => 'Missing bearer token.'], 401);
        }
        $row = Db::one(
            'SELECT t.id AS token_id, t.workspace_id, u.*, wu.role FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             JOIN workspace_user wu ON wu.user_id = t.user_id AND wu.workspace_id = t.workspace_id
             WHERE t.token = ?',
            [hash('sha256', $m[1])]
        );
        if (!$row) {
            json_response(['error' => 'Invalid token.'], 401);
        }
        Db::update('api_tokens', ['last_used_at' => now()], 'id = ?', [$row['token_id']]);
        $this->user = $row;
        $this->workspaceId = (int) $row['workspace_id'];
        $this->role = $row['is_admin'] ? 'admin' : $row['role'];
    }

    private function requireRole(string $min): void
    {
        $rank = ['viewer' => 1, 'member' => 2, 'admin' => 3];
        if (($rank[$this->role] ?? 0) < $rank[$min]) {
            json_response(['error' => 'Your role in this workspace doesn’t allow this.'], 403);
        }
    }

    /**
     * GET /api/v1/analytics?from=YYYY-MM-DD&to=YYYY-MM-DD&format=json|csv
     * One row per account per day: followers, posts, likes, comments, shares, impressions, engagement.
     */
    public function analytics(): void
    {
        $tz = $this->user['timezone'] ?: 'UTC';
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) input('to', '')) ? input('to') : (new \DateTimeImmutable('today', new \DateTimeZone($tz)))->format('Y-m-d');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) input('from', '')) ? input('from') : date('Y-m-d', strtotime($to . ' -29 days'));
        if ($from > $to || (strtotime($to) - strtotime($from)) > 400 * 86400) {
            json_response(['error' => 'Use a range of up to 400 days.'], 422);
        }
        $accounts = Db::all('SELECT id, provider, name FROM accounts WHERE workspace_id = ?', [$this->workspaceId]);
        $rows = [];
        foreach ($accounts as $acc) {
            $followers = array_column(Db::all('SELECT date, followers FROM account_metrics WHERE account_id = ? AND date <= ? AND followers IS NOT NULL ORDER BY date', [$acc['id'], $to]), 'followers', 'date');
            $posts = Db::all(
                "SELECT pa.published_at, pm.data FROM post_accounts pa LEFT JOIN post_metrics pm ON pm.post_id = pa.post_id AND pm.account_id = pa.account_id
                 WHERE pa.account_id = ? AND pa.status = 'published' AND pa.published_at BETWEEN ? AND ?",
                [$acc['id'], to_utc("$from 00:00", $tz), to_utc("$to 23:59:59", $tz)]
            );
            $byDay = [];
            foreach ($posts as $p) {
                $d = local_time($p['published_at'], 'Y-m-d', $tz);
                $m = json_arr($p['data']);
                $byDay[$d] ??= ['posts' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'impressions' => 0, 'engagement' => 0];
                $byDay[$d]['posts']++;
                $byDay[$d]['likes'] += (int) ($m['likes'] ?? $m['reactions'] ?? 0);
                $byDay[$d]['comments'] += (int) ($m['replies'] ?? $m['comments'] ?? 0);
                $byDay[$d]['shares'] += (int) ($m['shares'] ?? $m['reposts'] ?? 0);
                $byDay[$d]['impressions'] += (int) ($m['impressions'] ?? $m['views'] ?? 0);
                $byDay[$d]['engagement'] += \App\Metrics::engagement($m);
            }
            $last = null;
            foreach ($followers as $d => $f) {
                if ($d < $from) {
                    $last = (int) $f;
                }
            }
            for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
                $d = date('Y-m-d', $t);
                if (isset($followers[$d])) {
                    $last = (int) $followers[$d];
                }
                $rows[] = ['date' => $d, 'account_id' => (int) $acc['id'], 'account' => $acc['name'], 'network' => $acc['provider'], 'followers' => $last]
                    + ($byDay[$d] ?? ['posts' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'impressions' => 0, 'engagement' => 0]);
            }
        }
        if (input('format') === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'account_id', 'account', 'network', 'followers', 'posts', 'likes', 'comments', 'shares', 'impressions', 'engagement']);
            foreach ($rows as $r) {
                fputcsv($out, array_values($r));
            }
            fclose($out);
            exit;
        }
        json_response(['data' => $rows, 'meta' => ['from' => $from, 'to' => $to, 'timezone' => $tz]]);
    }

    public function me(): void
    {
        $ws = Db::one('SELECT id, name, require_approval FROM workspaces WHERE id = ?', [$this->workspaceId]);
        json_response([
            'user' => ['id' => (int) $this->user['id'], 'name' => $this->user['name'], 'email' => $this->user['email'], 'role' => $this->role],
            'workspace' => ['id' => (int) $ws['id'], 'name' => $ws['name'], 'require_approval' => (bool) $ws['require_approval']],
        ]);
    }

    public function accounts(): void
    {
        $rows = Db::all('SELECT * FROM accounts WHERE workspace_id = ? ORDER BY provider, name', [$this->workspaceId]);
        json_response(['data' => array_map([AccountsController::class, 'toArray'], $rows)]);
    }

    public function tags(): void
    {
        $rows = Db::all('SELECT id, name, color FROM tags WHERE workspace_id = ? ORDER BY name', [$this->workspaceId]);
        json_response(['data' => array_map(fn($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'color' => $t['color']], $rows)]);
    }

    public function posts(): void
    {
        $where = 'workspace_id = ?';
        $params = [$this->workspaceId];
        if ($s = (string) input('status', '')) {
            $where .= ' AND status = ?';
            $params[] = $s;
        }
        $limit = min(100, max(1, (int) input('limit', 25)));
        $page = max(1, (int) input('page', 1));
        $total = (int) Db::value("SELECT COUNT(*) FROM posts WHERE $where", $params);
        $rows = Db::all("SELECT * FROM posts WHERE $where ORDER BY id DESC LIMIT $limit OFFSET " . (($page - 1) * $limit), $params);
        json_response([
            'data' => array_map([PostService::class, 'toArray'], $rows),
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total],
        ]);
    }

    public function show(string $id): void
    {
        $post = PostService::find((int) $id, $this->workspaceId);
        if (!$post) {
            json_response(['error' => 'Post not found.'], 404);
        }
        json_response(['data' => PostService::toArray($post)]);
    }

    /**
     * POST /api/v1/posts  and  PUT /api/v1/posts/{id}
     * Body: {content, accounts: [ids], media: [ids], tags: [ids], overrides: {account_id: text},
     *        scheduled_at: ISO 8601, action: "draft"|"schedule"|"now"}
     */
    public function store(): void
    {
        $this->save(null);
    }

    public function update(string $id): void
    {
        $this->save((int) $id);
    }

    private function save(?int $id): void
    {
        $this->requireRole('member');
        $action = (string) input('action', 'draft');
        if (!in_array($action, ['draft', 'schedule', 'now'], true)) {
            json_response(['error' => 'action must be draft, schedule or now.'], 422);
        }
        $scheduledAt = null;
        if ($raw = (string) input('scheduled_at', '')) {
            $ts = strtotime($raw);
            if ($ts === false) {
                json_response(['error' => 'scheduled_at must be an ISO 8601 date-time, e.g. 2026-11-03T09:00:00-05:00.'], 422);
            }
            $scheduledAt = gmdate('Y-m-d H:i:s', $ts);
        }
        $in = [
            'content' => (string) input('content', ''),
            'accounts' => (array) input('accounts', []),
            'media' => (array) input('media', []),
            'tags' => (array) input('tags', []),
            'overrides' => (array) input('overrides', []),
            'types' => (array) input('types', []),
            'first_comment' => (string) input('first_comment', ''),
            'scheduled_at' => $scheduledAt,
        ];
        $result = PostService::save($id, $in, $action, $this->workspaceId, (int) $this->user['id'], $this->role === 'admin');
        if ($result['errors']) {
            json_response(['error' => 'Validation failed.', 'errors' => $result['errors']], 422);
        }
        $post = $result['post'];
        if ($post['status'] === 'scheduled' && $action === 'now') {
            PostService::publishNowInBackground((int) $post['id']);
        }
        json_response(['data' => PostService::toArray($post)], $id ? 200 : 201);
    }

    public function destroy(string $id): void
    {
        $this->requireRole('member');
        $post = PostService::find((int) $id, $this->workspaceId);
        if (!$post) {
            json_response(['error' => 'Post not found.'], 404);
        }
        PostService::delete($post);
        json_response(['ok' => true]);
    }

    /** POST /api/v1/posts/{id}/approve and /reject (body: {note}) */
    public function review(string $id, string $decision): void
    {
        $this->requireRole('admin');
        $post = PostService::find((int) $id, $this->workspaceId);
        if (!$post || $post['status'] !== 'pending_approval') {
            json_response(['error' => 'Post is not waiting for approval.'], 422);
        }
        $decision === 'approve'
            ? PostService::approve($post, (int) $this->user['id'])
            : PostService::reject($post, (int) $this->user['id'], (string) input('note', ''));
        json_response(['data' => PostService::toArray(PostService::find((int) $id, $this->workspaceId))]);
    }

    /** POST /api/v1/media (multipart, field "file") */
    public function upload(): void
    {
        $this->requireRole('member');
        $result = MediaController::store($_FILES['file'] ?? null, $this->workspaceId, (int) $this->user['id']);
        if (isset($result['error'])) {
            json_response(['error' => $result['error']], 422);
        }
        $result['url'] = abs_url(ltrim(substr($result['url'], strlen(base_path())), '/'));
        json_response(['data' => $result], 201);
    }

    public function media(): void
    {
        $rows = Db::all('SELECT * FROM media WHERE workspace_id = ? ORDER BY id DESC LIMIT 100', [$this->workspaceId]);
        json_response(['data' => array_map(fn($m) => ['id' => (int) $m['id'], 'url' => media_url($m, true), 'mime' => $m['mime'], 'name' => $m['name']], $rows)]);
    }
}

}

namespace App\Controllers {

// ---- Controllers/AuthController.php ----
use App\Auth;
use App\Db;
use App\Workspace;

final class AuthController
{
    public function showLogin(): void
    {
        if (!Db::value('SELECT COUNT(*) FROM users')) {
            redirect('/setup');
        }
        view('auth/login', ['title' => 'Sign in'], 'auth');
    }

    public function login(): void
    {
        $email = (string) input('email', '');
        $password = (string) input('password', '');

        $attempts = $_SESSION['_login_attempts'] ?? ['n' => 0, 't' => time()];
        if (time() - $attempts['t'] > 600) {
            $attempts = ['n' => 0, 't' => time()];
        }
        if ($attempts['n'] >= 8) {
            flash('error', 'Too many attempts. Wait a few minutes and try again.');
            redirect('/login');
        }

        if (!Auth::attempt($email, $password)) {
            $attempts['n']++;
            $_SESSION['_login_attempts'] = $attempts;
            with_old(['email' => $email]);
            flash('error', 'Those details don’t match an account.');
            redirect('/login');
        }
        unset($_SESSION['_login_attempts']);
        $intended = $_SESSION['_intended'] ?? null;
        unset($_SESSION['_intended']);
        if ($intended && str_starts_with($intended, base_path() . '/') && !str_contains($intended, '//')) {
            header('Location: ' . $intended);
            exit;
        }
        redirect('/');
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('/login');
    }

    /** First-run: create the instance admin and their first workspace. */
    public function showSetup(): void
    {
        if (Db::value('SELECT COUNT(*) FROM users')) {
            redirect('/login');
        }
        view('auth/setup', ['title' => 'Set up MixCreate', 'timezones' => \DateTimeZone::listIdentifiers()], 'auth');
    }

    public function setup(): void
    {
        if (Db::value('SELECT COUNT(*) FROM users')) {
            redirect('/login');
        }
        $data = [
            'name' => (string) input('name', ''),
            'email' => (string) input('email', ''),
            'workspace' => (string) input('workspace', ''),
            'timezone' => (string) input('timezone', 'UTC'),
        ];
        $errors = self::validateUser($data['name'], $data['email'], (string) input('password', ''), (string) input('password_confirmation', ''));
        if ($data['workspace'] === '') {
            $errors[] = 'Give your first workspace a name.';
        }
        if ($errors) {
            with_old($data);
            foreach ($errors as $err) {
                flash('error', $err);
            }
            redirect('/setup');
        }
        $tz = in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true) ? $data['timezone'] : 'UTC';
        $userId = Db::insert('users', [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash((string) input('password'), PASSWORD_DEFAULT),
            'is_admin' => 1,
            'timezone' => $tz,
            'created_at' => now(),
        ]);
        $wsId = Workspace::create($data['workspace'], $userId);
        Db::update('users', ['current_workspace_id' => $wsId], 'id = ?', [$userId]);
        Auth::login($userId);
        flash('success', 'You’re all set. Next, add your platform API keys and connect accounts.');
        redirect('/accounts');
    }

    public function showInvite(string $token): void
    {
        $invite = self::findInvite($token);
        $existing = Db::one('SELECT id FROM users WHERE email = ?', [$invite['email']]);
        view('auth/invite', [
            'title' => 'Join ' . $invite['workspace_name'],
            'invite' => $invite,
            'existing' => (bool) $existing,
            'current' => Auth::user(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ], 'auth');
    }

    public function acceptInvite(string $token): void
    {
        $invite = self::findInvite($token);
        $current = Auth::user();
        $existing = Db::one('SELECT * FROM users WHERE email = ?', [$invite['email']]);

        if ($current) {
            if (strcasecmp($current['email'], $invite['email']) !== 0) {
                flash('error', 'This invitation is for ' . $invite['email'] . '. Sign out and open the link again.');
                redirect('/invite/' . $token);
            }
            $userId = (int) $current['id'];
        } elseif ($existing) {
            if (!password_verify((string) input('password', ''), $existing['password'])) {
                flash('error', 'Wrong password.');
                redirect('/invite/' . $token);
            }
            $userId = (int) $existing['id'];
        } else {
            $name = (string) input('name', '');
            $errors = self::validateUser($name, $invite['email'], (string) input('password', ''), (string) input('password_confirmation', ''));
            if ($errors) {
                with_old(['name' => $name]);
                foreach ($errors as $err) {
                    flash('error', $err);
                }
                redirect('/invite/' . $token);
            }
            $tz = (string) input('timezone', 'UTC');
            $userId = Db::insert('users', [
                'name' => $name,
                'email' => $invite['email'],
                'password' => password_hash((string) input('password'), PASSWORD_DEFAULT),
                'timezone' => in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC',
                'created_at' => now(),
            ]);
        }

        Db::transaction(function () use ($invite, $userId) {
            Db::query(
                'INSERT OR IGNORE INTO workspace_user (workspace_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)',
                [$invite['workspace_id'], $userId, $invite['role'], now()]
            );
            Db::update('invitations', ['accepted_at' => now()], 'id = ?', [$invite['id']]);
            Db::update('users', ['current_workspace_id' => $invite['workspace_id']], 'id = ?', [$userId]);
        });
        if (!$current) {
            Auth::login($userId);
        }
        flash('success', 'Welcome to ' . $invite['workspace_name'] . '.');
        redirect('/');
    }

    private static function findInvite(string $token): array
    {
        $invite = Db::one(
            'SELECT i.*, w.name AS workspace_name FROM invitations i JOIN workspaces w ON w.id = i.workspace_id
             WHERE i.token = ? AND i.accepted_at IS NULL',
            [hash('sha256', $token)]
        );
        if (!$invite || strtotime($invite['created_at'] . ' UTC') < time() - 7 * 86400) {
            abort(404, 'This invitation link is invalid or has expired. Ask for a new one.');
        }
        return $invite;
    }

    public static function validateUser(string $name, string $email, string $password, string $confirm): array
    {
        $errors = [];
        if ($name === '') {
            $errors[] = 'Enter your name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        } elseif (Db::value('SELECT 1 FROM users WHERE email = ?', [$email])) {
            $errors[] = 'An account with that email already exists.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Use a password of at least 8 characters.';
        } elseif ($password !== $confirm) {
            $errors[] = 'The passwords don’t match.';
        }
        return $errors;
    }
}

}

namespace App\Controllers {

// ---- Controllers/AutolistsController.php ----
use App\Auth;
use App\Autolists;
use App\Db;
use App\Publisher;
use App\Workspace;

final class AutolistsController
{
    public function index(): void
    {
        $lists = Db::all(
            'SELECT l.*, (SELECT COUNT(*) FROM autolist_items WHERE autolist_id = l.id) AS items,
                    (SELECT COUNT(*) FROM autolist_runs WHERE autolist_id = l.id AND post_id IS NOT NULL) AS runs
             FROM autolists l WHERE workspace_id = ? ORDER BY name',
            [Workspace::id()]
        );
        foreach ($lists as &$l) {
            $l['next'] = Autolists::upcoming($l, 1)[0] ?? null;
        }
        unset($l);
        view('autolists/index', ['title' => 'Autolists', 'lists' => $lists, 'accounts' => PostsController::accounts()]);
    }

    public function show(string $id): void
    {
        $list = $this->find($id);
        $items = Db::all('SELECT * FROM autolist_items WHERE autolist_id = ? ORDER BY position, id', [$list['id']]);
        foreach ($items as &$it) {
            $it['media_items'] = Publisher::resolveMedia(json_arr($it['media']));
        }
        unset($it);
        view('autolists/show', [
            'title' => $list['name'],
            'list' => $list,
            'items' => $items,
            'accounts' => PostsController::accounts(),
            'upcoming' => Autolists::upcoming($list, 6),
            'recent' => PostsController::decorate(Db::all('SELECT * FROM posts WHERE autolist_id = ? ORDER BY id DESC LIMIT 5', [$list['id']])),
        ]);
    }

    private function find(string $id): array
    {
        $list = Db::one('SELECT * FROM autolists WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$list) {
            abort(404);
        }
        return $list;
    }

    private function fields(): array
    {
        $accounts = array_values(array_intersect(array_map('intval', (array) input('accounts', [])), array_map('intval', array_column(PostsController::accounts(), 'id'))));
        $slots = [];
        $times = array_filter((array) input('times', []), fn($t) => preg_match('/^\d{2}:\d{2}$/', (string) $t));
        foreach (array_map('intval', (array) input('days', [])) as $d) {
            if ($d >= 0 && $d <= 6) {
                foreach ($times as $t) {
                    $slots[] = ['day' => $d, 'time' => $t];
                }
            }
        }
        $name = mb_substr((string) input('name', ''), 0, 80);
        if ($name === '' || !$accounts || !$slots) {
            flash('error', 'Give the autolist a name, at least one account, and at least one day and time.');
            back('/autolists');
        }
        return [
            'name' => $name,
            'accounts' => json_encode($accounts),
            'slots' => json_encode($slots),
            'order_mode' => input('order_mode') === 'random' ? 'random' : 'sequence',
            'timezone' => user_tz(),
        ];
    }

    public function store(): void
    {
        $id = Db::insert('autolists', $this->fields() + ['workspace_id' => Workspace::id(), 'created_by' => Auth::id(), 'created_at' => now()]);
        flash('success', 'Autolist created. Add posts to it.');
        redirect('/autolists/' . $id);
    }

    public function update(string $id): void
    {
        $list = $this->find($id);
        Db::update('autolists', $this->fields() + ['active' => input('active') ? 1 : 0], 'id = ?', [$list['id']]);
        flash('success', 'Saved.');
        redirect('/autolists/' . $list['id']);
    }

    public function delete(string $id): void
    {
        $list = $this->find($id);
        Db::query('DELETE FROM autolists WHERE id = ?', [$list['id']]);
        flash('success', 'Autolist deleted. Posts it already created stay.');
        redirect('/autolists');
    }

    public function addItem(string $id): void
    {
        $list = $this->find($id);
        $content = str_replace("\r\n", "\n", trim((string) input('content', '')));
        $media = array_values(array_filter(array_map('intval', (array) input('media', []))));
        if ($media) {
            $owned = Db::all('SELECT id FROM media WHERE workspace_id = ? AND id IN (' . implode(',', $media) . ')', [Workspace::id()]);
            $media = array_values(array_intersect($media, array_map('intval', array_column($owned, 'id'))));
        }
        if ($content === '' && !$media) {
            flash('error', 'Add some text or media.');
            redirect('/autolists/' . $list['id']);
        }
        $itemId = (int) input('item_id', 0);
        if ($itemId) {
            Db::update('autolist_items', ['content' => $content, 'media' => json_encode($media)], 'id = ? AND autolist_id = ?', [$itemId, $list['id']]);
        } else {
            $pos = (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM autolist_items WHERE autolist_id = ?', [$list['id']]);
            Db::insert('autolist_items', ['autolist_id' => $list['id'], 'content' => $content, 'media' => json_encode($media), 'position' => $pos, 'created_at' => now()]);
        }
        redirect('/autolists/' . $list['id']);
    }

    public function deleteItem(string $id, string $item): void
    {
        $list = $this->find($id);
        Db::query('DELETE FROM autolist_items WHERE id = ? AND autolist_id = ?', [(int) $item, $list['id']]);
        redirect('/autolists/' . $list['id']);
    }

    /** Copies an existing post into an autolist. */
    public function addPost(string $id): void
    {
        $post = Db::one('SELECT * FROM posts WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        $list = $this->find((string) input('autolist', '0'));
        if (!$post) {
            abort(404);
        }
        $pos = (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM autolist_items WHERE autolist_id = ?', [$list['id']]);
        Db::insert('autolist_items', ['autolist_id' => $list['id'], 'content' => $post['content'], 'media' => $post['media'], 'position' => $pos, 'created_at' => now()]);
        flash('success', 'Added to “' . $list['name'] . '”.');
        redirect('/posts/' . $post['id']);
    }

    public function publishNow(string $id): void
    {
        $list = $this->find($id);
        $postId = Autolists::publishNext($list);
        if ($postId) {
            \App\PostService::publishNowInBackground($postId);
            flash('success', 'Publishing the next item now.');
            redirect('/posts/' . $postId);
        }
        flash('error', 'Nothing to publish. Check the list has items and accounts.');
        redirect('/autolists/' . $list['id']);
    }
}

}

namespace App\Controllers {

// ---- Controllers/BioController.php ----
use App\Db;
use App\Publisher;
use App\Workspace;

/** Link-in-bio page: one public page per workspace at /@slug. */
final class BioController
{
    public const THEMES = [
        'light' => ['label' => 'Light', 'bg' => '#f6f7f9', 'card' => '#ffffff', 'text' => '#15181e'],
        'dark' => ['label' => 'Dark', 'bg' => '#0f1115', 'card' => '#1b1f27', 'text' => '#eceef2'],
        'tint' => ['label' => 'Tinted', 'bg' => 'accent', 'card' => '#ffffff', 'text' => '#15181e'],
    ];

    public static function page(int $workspaceId): ?array
    {
        return Db::one('SELECT * FROM bio_pages WHERE workspace_id = ?', [$workspaceId]);
    }

    public function edit(): void
    {
        $ws = Workspace::current();
        $page = self::page((int) $ws['id']);
        if (!$page) {
            $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ws['name'])), '-') ?: 'page';
            $slug = $base;
            for ($i = 2; Db::value('SELECT 1 FROM bio_pages WHERE slug = ?', [$slug]); $i++) {
                $slug = "$base-$i";
            }
            Db::insert('bio_pages', [
                'workspace_id' => $ws['id'], 'slug' => $slug, 'title' => $ws['name'], 'bio' => '',
                'theme' => json_encode(['accent' => $ws['color'], 'style' => 'light', 'button' => 'rounded']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $page = self::page((int) $ws['id']);
        }
        $links = Db::all('SELECT * FROM bio_links WHERE page_id = ? ORDER BY position, id', [$page['id']]);
        $since = gmdate('Y-m-d', time() - 29 * 86400);
        $stats = Db::all('SELECT link_id, SUM(count) AS n FROM bio_stats WHERE page_id = ? AND date >= ? GROUP BY link_id', [$page['id'], $since]);
        $byLink = array_column($stats, 'n', 'link_id');
        $views = Db::all('SELECT date, count FROM bio_stats WHERE page_id = ? AND link_id = 0 AND date >= ? ORDER BY date', [$page['id'], $since]);
        $viewMap = array_column($views, 'count', 'date');
        $series = [];
        for ($t = strtotime($since); $t <= time(); $t += 86400) {
            $d = gmdate('Y-m-d', $t);
            $series[] = ['date' => $d, 'value' => (int) ($viewMap[$d] ?? 0)];
        }
        view('bio/edit', [
            'title' => 'Link in bio',
            'page' => $page,
            'theme' => json_arr($page['theme']),
            'links' => $links,
            'clicks' => $byLink,
            'views' => (int) ($byLink[0] ?? 0),
            'series' => $series,
            'publicUrl' => abs_url('@' . $page['slug']),
            'avatar' => $page['avatar_media_id'] ? (Publisher::resolveMedia([(int) $page['avatar_media_id']])[0] ?? null) : null,
            'themes' => self::THEMES,
        ]);
    }

    public function update(): void
    {
        $page = self::page(Workspace::id()) ?? abort(404);
        $slug = strtolower(trim((string) input('slug', $page['slug'])));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $slug)) {
            flash('error', 'The address can use 2–41 lowercase letters, numbers and dashes.');
            redirect('/bio');
        }
        if ($slug !== $page['slug'] && Db::value('SELECT 1 FROM bio_pages WHERE slug = ?', [$slug])) {
            flash('error', 'That address is taken.');
            redirect('/bio');
        }
        $accent = preg_match('/^#[0-9a-f]{6}$/i', (string) input('accent', '')) ? input('accent') : '#4f46e5';
        $style = isset(self::THEMES[input('style')]) ? input('style') : 'light';
        $avatar = (int) input('avatar_media_id', 0);
        if ($avatar && !Db::value('SELECT 1 FROM media WHERE id = ? AND workspace_id = ?', [$avatar, Workspace::id()])) {
            $avatar = 0;
        }
        Db::update('bio_pages', [
            'slug' => $slug,
            'title' => mb_substr((string) input('title', ''), 0, 80),
            'bio' => mb_substr((string) input('bio', ''), 0, 300),
            'avatar_media_id' => $avatar ?: null,
            'theme' => json_encode(['accent' => $accent, 'style' => $style, 'button' => input('button') === 'square' ? 'square' : 'rounded']),
            'show_posts' => input('show_posts') ? 1 : 0,
            'show_socials' => input('show_socials') ? 1 : 0,
            'published' => input('published') ? 1 : 0,
            'updated_at' => now(),
        ], 'id = ?', [$page['id']]);
        flash('success', 'Saved.');
        redirect('/bio');
    }

    public function addLink(): void
    {
        $page = self::page(Workspace::id()) ?? abort(404);
        [$label, $url] = $this->validLink();
        $pos = (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM bio_links WHERE page_id = ?', [$page['id']]);
        Db::insert('bio_links', ['page_id' => $page['id'], 'label' => $label, 'url' => $url, 'position' => $pos, 'created_at' => now()]);
        redirect('/bio');
    }

    public function updateLink(string $id): void
    {
        $page = self::page(Workspace::id()) ?? abort(404);
        [$label, $url] = $this->validLink();
        Db::update('bio_links', ['label' => $label, 'url' => $url, 'active' => input('active') ? 1 : 0], 'id = ? AND page_id = ?', [(int) $id, $page['id']]);
        redirect('/bio');
    }

    public function deleteLink(string $id): void
    {
        $page = self::page(Workspace::id()) ?? abort(404);
        Db::query('DELETE FROM bio_links WHERE id = ? AND page_id = ?', [(int) $id, $page['id']]);
        redirect('/bio');
    }

    public function reorder(): void
    {
        $page = self::page(Workspace::id()) ?? abort(404);
        foreach (array_values((array) input('order', [])) as $i => $id) {
            Db::update('bio_links', ['position' => $i], 'id = ? AND page_id = ?', [(int) $id, $page['id']]);
        }
        json_response(['ok' => true]);
    }

    private function validLink(): array
    {
        $label = mb_substr(trim((string) input('label', '')), 0, 80);
        $url = trim((string) input('url', ''));
        if ($url !== '' && !preg_match('#^(https?://|mailto:|tel:)#i', $url)) {
            $url = 'https://' . $url;
        }
        if ($label === '' || !filter_var(preg_replace('#^(mailto|tel):#i', 'http://x.y/', $url), FILTER_VALIDATE_URL)) {
            flash('error', 'Each link needs a label and a valid URL.');
            redirect('/bio');
        }
        return [$label, $url];
    }

    // ---------- Public ----------

    public function show(string $slug): void
    {
        $page = Db::one('SELECT * FROM bio_pages WHERE slug = ?', [$slug]);
        $preview = isset($_GET['preview']) && \App\Auth::user() && $page && Db::value('SELECT 1 FROM workspace_user WHERE workspace_id = ? AND user_id = ?', [$page['workspace_id'], \App\Auth::id()]);
        if (!$page || (!$page['published'] && !$preview)) {
            abort(404, 'This page doesn’t exist.');
        }
        if (!$preview) {
            self::count((int) $page['id'], 0);
        }
        $links = Db::all('SELECT * FROM bio_links WHERE page_id = ? AND active = 1 ORDER BY position, id', [$page['id']]);
        $socials = [];
        if ($page['show_socials']) {
            foreach (Db::all('SELECT provider, provider_id, name, username, data FROM accounts WHERE workspace_id = ? ORDER BY provider', [$page['workspace_id']]) as $a) {
                $u = self::profileUrl($a);
                if ($u && !isset($socials[$u])) {
                    $socials[$u] = $a;
                }
            }
        }
        $posts = [];
        if ($page['show_posts']) {
            $rows = Db::all(
                "SELECT p.id, p.content, p.media, (SELECT pa.url FROM post_accounts pa WHERE pa.post_id = p.id AND pa.url IS NOT NULL ORDER BY pa.published_at LIMIT 1) AS url
                 FROM posts p WHERE p.workspace_id = ? AND p.status = 'published' AND p.media != '[]' ORDER BY p.published_at DESC LIMIT 30",
                [$page['workspace_id']]
            );
            foreach ($rows as $r) {
                $m = Publisher::resolveMedia(array_slice(json_arr($r['media']), 0, 1))[0] ?? null;
                if ($m && str_starts_with($m['mime'], 'image/') && $r['url']) {
                    $posts[] = ['img' => media_url($m), 'url' => $r['url'], 'alt' => str_limit($r['content'], 100)];
                }
                if (count($posts) >= 9) {
                    break;
                }
            }
        }
        $avatar = $page['avatar_media_id'] ? (Publisher::resolveMedia([(int) $page['avatar_media_id']])[0] ?? null) : null;
        header('Cache-Control: no-store');
        view('bio/public', [
            'page' => $page,
            'theme' => json_arr($page['theme']),
            'links' => $links,
            'socials' => $socials,
            'posts' => $posts,
            'avatar' => $avatar ? media_url($avatar) : null,
            'preview' => $preview,
            'themes' => self::THEMES,
        ], null);
    }

    public function go(string $slug, string $id): void
    {
        $link = Db::one('SELECT l.* FROM bio_links l JOIN bio_pages p ON p.id = l.page_id WHERE p.slug = ? AND l.id = ? AND l.active = 1 AND p.published = 1', [$slug, (int) $id]);
        if (!$link) {
            abort(404);
        }
        self::count((int) $link['page_id'], (int) $link['id']);
        header('Location: ' . $link['url'], true, 302);
        exit;
    }

    private static function count(int $pageId, int $linkId): void
    {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '' || preg_match('/bot|crawl|spider|preview|facebookexternalhit|slurp|curl|wget/', $ua)) {
            return;
        }
        Db::query(
            'INSERT INTO bio_stats (page_id, link_id, date, count) VALUES (?, ?, ?, 1) ON CONFLICT(page_id, link_id, date) DO UPDATE SET count = count + 1',
            [$pageId, $linkId, gmdate('Y-m-d')]
        );
    }

    public static function profileUrl(array $a): ?string
    {
        $u = $a['username'] ?? '';
        $data = json_arr($a['data'] ?? null);
        return match ($a['provider']) {
            'x' => $u ? "https://x.com/$u" : null,
            'bluesky' => $u ? "https://bsky.app/profile/$u" : null,
            'mastodon' => str_contains($u, '@') ? 'https://' . explode('@', $u)[1] . '/@' . explode('@', $u)[0] : null,
            'instagram' => $u ? "https://www.instagram.com/$u/" : null,
            'threads' => $u ? "https://www.threads.net/@$u" : null,
            'tiktok' => $u ? "https://www.tiktok.com/@$u" : null,
            'youtube' => $u ? "https://www.youtube.com/@$u" : 'https://www.youtube.com/channel/' . $a['provider_id'],
            'pinterest' => $u ? "https://www.pinterest.com/$u/" : null,
            'facebook' => 'https://www.facebook.com/' . ($u ?: $a['provider_id']),
            'linkedin' => str_starts_with((string) $a['provider_id'], 'organization:') && $u && $u !== 'Company page' ? "https://www.linkedin.com/company/$u/" : null,
            default => null,
        };
    }
}

}

namespace App\Controllers {

// ---- Controllers/CalendarController.php ----
use App\Db;
use App\Workspace;

final class CalendarController
{
    public function index(): void
    {
        $tz = new \DateTimeZone(user_tz());
        $view = input('view') === 'week' ? 'week' : 'month';
        $today = new \DateTimeImmutable('today', $tz);
        $date = (string) input('date', '');
        $anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? new \DateTimeImmutable($date, $tz) : $today;

        if ($view === 'month') {
            $first = $anchor->modify('first day of this month');
            $start = $first->modify('-' . (int) $first->format('w') . ' days');
            $last = $anchor->modify('last day of this month');
            $end = $last->modify('+' . (6 - (int) $last->format('w')) . ' days')->modify('+1 day');
            $prev = $first->modify('-1 month')->format('Y-m-d');
            $next = $first->modify('+1 month')->format('Y-m-d');
            $label = $first->format('F Y');
        } else {
            $start = $anchor->modify('-' . (int) $anchor->format('w') . ' days');
            $end = $start->modify('+7 days');
            $prev = $start->modify('-7 days')->format('Y-m-d');
            $next = $start->modify('+7 days')->format('Y-m-d');
            $label = $start->format('M j') . ' – ' . $end->modify('-1 day')->format('M j, Y');
        }

        $utc = new \DateTimeZone('UTC');
        $posts = Db::all(
            "SELECT * FROM posts WHERE workspace_id = ?
             AND COALESCE(published_at, scheduled_at) >= ? AND COALESCE(published_at, scheduled_at) < ?
             ORDER BY COALESCE(published_at, scheduled_at)",
            [Workspace::id(), $start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')]
        );
        $posts = PostsController::decorate($posts);

        $byDay = [];
        foreach ($posts as $p) {
            $when = $p['published_at'] ?? $p['scheduled_at'];
            $day = local_time($when, 'Y-m-d');
            $p['time'] = local_time($when, 'g:i A');
            $p['hour'] = (int) local_time($when, 'G');
            $byDay[$day][] = $p;
        }

        $days = [];
        for ($d = $start; $d < $end; $d = $d->modify('+1 day')) {
            $days[] = $d;
        }

        view('calendar/index', [
            'title' => 'Calendar',
            'view' => $view,
            'days' => $days,
            'byDay' => $byDay,
            'anchor' => $anchor,
            'today' => $today->format('Y-m-d'),
            'label' => $label,
            'prev' => $prev,
            'next' => $next,
        ]);
    }
}

}

namespace App\Controllers {

// ---- Controllers/CanvaController.php ----
use App\Auth;
use App\Canva;
use App\Workspace;

final class CanvaController
{
    public function connect(): void
    {
        if (!Canva::enabled()) {
            flash('error', 'Canva isn’t set up. An instance admin can add it under Integrations.');
            back('/media');
        }
        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $_SESSION['_canva'] = ['state' => $state, 'verifier' => $verifier, 'back' => (string) input('back', '/media')];
        redirect(Canva::authUrl($state, $verifier));
    }

    public function callback(): void
    {
        $pending = $_SESSION['_canva'] ?? null;
        unset($_SESSION['_canva']);
        $back = $pending['back'] ?? '/media';
        if (!str_starts_with($back, '/')) {
            $back = '/media';
        }
        if (!$pending || !hash_equals($pending['state'], (string) input('state', ''))) {
            flash('error', 'The Canva connection expired. Try again.');
            redirect($back);
        }
        if (input('error')) {
            flash('error', 'Canva connection cancelled.');
            redirect($back);
        }
        try {
            Canva::connect((int) Auth::id(), (string) input('code', ''), $pending['verifier']);
            flash('success', 'Canva connected. Pick a design to import.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($back, ['canva' => 1]);
    }

    public function disconnect(): void
    {
        Canva::disconnect((int) Auth::id());
        flash('success', 'Canva disconnected.');
        redirect('/media');
    }

    public function designs(): void
    {
        if (!Canva::connected((int) Auth::id())) {
            json_response(['connected' => false]);
        }
        try {
            $r = Canva::designs((int) Auth::id(), input('continuation') ?: null, (string) input('q', ''));
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage(), 'connected' => Canva::connected((int) Auth::id())], 422);
        }
        json_response(['connected' => true] + $r);
    }

    public function import(): void
    {
        set_time_limit(300);
        try {
            $media = Canva::import((int) Auth::id(), Workspace::id(), (string) input('design', ''), (string) input('format', 'png'));
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
        json_response(['media' => $media]);
    }
}

}

namespace App\Controllers {

// ---- Controllers/CompetitorsController.php ----
use App\Competitors;
use App\Db;
use App\Workspace;

final class CompetitorsController
{
    public function index(): void
    {
        $wsId = Workspace::id();
        $days = in_array((int) input('days', 30), [7, 30, 90], true) ? (int) input('days', 30) : 30;
        $since = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $rows = Db::all('SELECT * FROM competitors WHERE workspace_id = ? ORDER BY provider, name', [$wsId]);
        foreach ($rows as &$c) {
            $metrics = Db::all('SELECT date, followers, posts, data FROM competitor_metrics WHERE competitor_id = ? AND date >= ? ORDER BY date', [$c['id'], gmdate('Y-m-d', time() - ($days + 30) * 86400)]);
            $inRange = array_values(array_filter($metrics, fn($m) => $m['date'] >= $since));
            $latest = end($metrics) ?: null;
            $base = null;
            foreach ($metrics as $m) {
                if ($m['date'] <= $since) {
                    $base = $m;
                }
            }
            $base ??= $inRange[0] ?? null;
            $data = $latest ? json_arr($latest['data']) : [];
            $c['followers'] = $latest ? (int) $latest['followers'] : null;
            $c['change'] = ($latest && $base && $base !== $latest) ? (int) $latest['followers'] - (int) $base['followers'] : null;
            $c['avg_engagement'] = $data['avg_engagement'] ?? null;
            $c['posts_30d'] = $data['posts_30d'] ?? null;
            $c['sample'] = $data['sample'] ?? 0;
            $c['series'] = array_map(fn($m) => ['date' => $m['date'], 'value' => (int) $m['followers']], $inRange);
            $c['updated'] = $latest['date'] ?? null;
        }
        unset($c);

        // Your own accounts on the same networks, for comparison.
        $own = Db::all(
            "SELECT a.id, a.provider, a.name, a.username, a.avatar,
                    (SELECT followers FROM account_metrics m WHERE m.account_id = a.id AND followers IS NOT NULL ORDER BY date DESC LIMIT 1) AS followers
             FROM accounts a WHERE a.workspace_id = ? AND a.provider IN ('" . implode("','", array_keys(Competitors::NETWORKS)) . "')",
            [$wsId]
        );

        view('competitors/index', [
            'title' => 'Competitors',
            'competitors' => $rows,
            'own' => $own,
            'days' => $days,
            'networks' => Competitors::NETWORKS,
        ]);
    }

    public function store(): void
    {
        $network = (string) input('network', '');
        $handle = trim((string) input('handle', ''));
        if (!isset(Competitors::NETWORKS[$network]) || $handle === '') {
            flash('error', 'Pick a network and enter a handle.');
            redirect('/competitors');
        }
        $handle = mb_substr($handle, 0, 120);
        try {
            $d = Competitors::fetch(Workspace::id(), $network, $handle);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/competitors');
        }
        Db::query('INSERT OR IGNORE INTO competitors (workspace_id, provider, handle, name, created_at) VALUES (?, ?, ?, ?, ?)', [Workspace::id(), $network, $handle, $d['name'], now()]);
        $c = Db::one('SELECT * FROM competitors WHERE workspace_id = ? AND provider = ? AND handle = ?', [Workspace::id(), $network, $handle]);
        Competitors::refresh($c);
        flash('success', 'Now tracking ' . $d['name'] . '. Growth shows up as daily snapshots build.');
        redirect('/competitors');
    }

    public function delete(string $id): void
    {
        Db::query('DELETE FROM competitors WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Stopped tracking.');
        redirect('/competitors');
    }

    public function refresh(string $id): void
    {
        $c = Db::one('SELECT * FROM competitors WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if ($c) {
            Competitors::refresh($c);
        }
        redirect('/competitors');
    }
}

}

namespace App\Controllers {

// ---- Controllers/CronController.php ----
use App\Config;
use App\Cron;

/** Web cron for hosts without a real crontab: GET /cron?key=CRON_KEY every minute. */
final class CronController
{
    public function run(): void
    {
        $key = (string) Config::get('cron_key', '');
        if ($key === '' || !hash_equals($key, (string) input('key', ''))) {
            abort(403, 'Invalid cron key.');
        }
        ignore_user_abort(true);
        json_response(Cron::tick());
    }
}

}

namespace App\Controllers {

// ---- Controllers/DashboardController.php ----
use App\Auth;
use App\Db;
use App\Workspace;

final class DashboardController
{
    public function index(): void
    {
        $wsId = Workspace::id();
        $counts = array_column(Db::all('SELECT status, COUNT(*) AS n FROM posts WHERE workspace_id = ? GROUP BY status', [$wsId]), 'n', 'status');
        $upcoming = Db::all(
            "SELECT * FROM posts WHERE workspace_id = ? AND status IN ('scheduled', 'pending_approval') ORDER BY scheduled_at LIMIT 8",
            [$wsId]
        );
        $failed = Db::all("SELECT * FROM posts WHERE workspace_id = ? AND status = 'failed' ORDER BY updated_at DESC LIMIT 5", [$wsId]);
        $recent = Db::all("SELECT * FROM posts WHERE workspace_id = ? AND status = 'published' ORDER BY published_at DESC LIMIT 5", [$wsId]);
        $accounts = PostsController::accounts();
        $publishedWeek = (int) Db::value(
            "SELECT COUNT(*) FROM posts WHERE workspace_id = ? AND status = 'published' AND published_at >= ?",
            [$wsId, gmdate('Y-m-d H:i:s', time() - 7 * 86400)]
        );

        // Is cron running? Warn admins if scheduled posts are overdue.
        $overdue = (int) Db::value("SELECT COUNT(*) FROM posts WHERE workspace_id = ? AND status = 'scheduled' AND scheduled_at < ?", [$wsId, gmdate('Y-m-d H:i:s', time() - 300)]);
        $cronAge = kv_age('cron.ran');

        view('dashboard', [
            'title' => 'Dashboard',
            'counts' => $counts,
            'upcoming' => PostsController::decorate($upcoming),
            'failed' => PostsController::decorate($failed),
            'recent' => PostsController::decorate($recent),
            'accounts' => $accounts,
            'unauthorized' => array_values(array_filter($accounts, fn($a) => !$a['authorized'])),
            'publishedWeek' => $publishedWeek,
            'overdue' => $overdue,
            'cronAge' => $cronAge,
            'user' => Auth::user(),
        ]);
    }
}

}

namespace App\Controllers {

// ---- Controllers/DevelopersController.php ----
use App\Auth;
use App\Db;
use App\Webhooks;
use App\Workspace;

/** API tokens (per user, per workspace) and webhooks (per workspace, admins). */
final class DevelopersController
{
    public function index(): void
    {
        $wsId = Workspace::id();
        $hooks = Db::all('SELECT * FROM webhooks WHERE workspace_id = ? ORDER BY id', [$wsId]);
        foreach ($hooks as &$h) {
            $h['deliveries'] = Db::all('SELECT id, event, status_code, attempts, delivered_at, next_attempt_at, created_at FROM webhook_deliveries WHERE webhook_id = ? ORDER BY id DESC LIMIT 10', [$h['id']]);
        }
        unset($h);
        $newToken = $_SESSION['_new_token'] ?? null;
        unset($_SESSION['_new_token']);
        $newSecret = $_SESSION['_new_secret'] ?? null;
        unset($_SESSION['_new_secret']);
        view('developers/index', [
            'title' => 'API & webhooks',
            'tokens' => Db::all('SELECT * FROM api_tokens WHERE user_id = ? AND workspace_id = ? ORDER BY id DESC', [Auth::id(), $wsId]),
            'webhooks' => $hooks,
            'events' => Webhooks::EVENTS,
            'newToken' => $newToken,
            'newSecret' => $newSecret,
            'apiBase' => abs_url('api/v1'),
            'isAdmin' => Workspace::can('admin'),
        ]);
    }

    public function createToken(): void
    {
        $name = mb_substr((string) input('name', ''), 0, 60) ?: 'API token';
        $token = 'mc_' . bin2hex(random_bytes(24));
        Db::insert('api_tokens', [
            'user_id' => Auth::id(),
            'workspace_id' => Workspace::id(),
            'name' => $name,
            'token' => hash('sha256', $token),
            'created_at' => now(),
        ]);
        $_SESSION['_new_token'] = $token;
        redirect('/developers');
    }

    public function deleteToken(string $id): void
    {
        Db::query('DELETE FROM api_tokens WHERE id = ? AND user_id = ?', [(int) $id, Auth::id()]);
        flash('success', 'Token revoked.');
        redirect('/developers');
    }

    public function saveWebhook(): void
    {
        $id = (int) input('id', 0);
        $url = (string) input('url', '');
        $events = array_values(array_intersect((array) input('events', []), array_keys(Webhooks::EVENTS)));
        if (!Webhooks::safeUrl($url)) {
            flash('error', 'Enter a public http(s) URL.');
            redirect('/developers');
        }
        if ($id) {
            Db::update('webhooks', ['url' => $url, 'events' => json_encode($events), 'active' => input('active') ? 1 : 0], 'id = ? AND workspace_id = ?', [$id, Workspace::id()]);
            flash('success', 'Webhook saved.');
        } else {
            $secret = 'whsec_' . bin2hex(random_bytes(20));
            Db::insert('webhooks', ['workspace_id' => Workspace::id(), 'url' => $url, 'events' => json_encode($events), 'secret' => $secret, 'active' => 1, 'created_at' => now()]);
            $_SESSION['_new_secret'] = $secret;
        }
        redirect('/developers');
    }

    public function deleteWebhook(string $id): void
    {
        Db::query('DELETE FROM webhooks WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Webhook deleted.');
        redirect('/developers');
    }

    public function testWebhook(string $id): void
    {
        $hook = Db::one('SELECT * FROM webhooks WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$hook) {
            abort(404);
        }
        $payload = json_encode(['event' => 'ping', 'workspace_id' => Workspace::id(), 'sent_at' => gmdate('c'), 'data' => ['message' => 'Hello from MixCreate']]);
        $did = Db::insert('webhook_deliveries', ['webhook_id' => $hook['id'], 'event' => 'ping', 'payload' => $payload, 'created_at' => now()]);
        $ok = Webhooks::send(['id' => $did, 'attempts' => 5, 'event' => 'ping', 'payload' => $payload, 'url' => $hook['url'], 'secret' => $hook['secret']]);
        $d = Db::one('SELECT status_code, response FROM webhook_deliveries WHERE id = ?', [$did]);
        flash($ok ? 'success' : 'error', $ok ? "Ping delivered (HTTP {$d['status_code']})." : 'Ping failed: ' . ($d['status_code'] ? "HTTP {$d['status_code']}" : str_limit((string) $d['response'], 160)));
        redirect('/developers');
    }
}

}

namespace App\Controllers {

// ---- Controllers/DriveController.php ----
use App\Auth;
use App\Drive;
use App\Workspace;

final class DriveController
{
    public function connect(): void
    {
        if (!Drive::enabled()) {
            flash('error', 'Google Drive isn’t set up. An instance admin can add it under Integrations.');
            back('/media');
        }
        $state = bin2hex(random_bytes(16));
        $_SESSION['_gdrive'] = ['state' => $state, 'back' => (string) input('back', '/media')];
        redirect(Drive::authUrl($state));
    }

    public function callback(): void
    {
        $pending = $_SESSION['_gdrive'] ?? null;
        unset($_SESSION['_gdrive']);
        $back = str_starts_with((string) ($pending['back'] ?? ''), '/') ? $pending['back'] : '/media';
        if (!$pending || !hash_equals($pending['state'], (string) input('state', '')) || input('error')) {
            flash('error', 'Google Drive connection cancelled or expired.');
            redirect($back);
        }
        try {
            Drive::connect((int) Auth::id(), (string) input('code', ''));
            flash('success', 'Google Drive connected.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($back, ['drive' => 1]);
    }

    public function disconnect(): void
    {
        Drive::disconnect((int) Auth::id());
        flash('success', 'Google Drive disconnected.');
        redirect('/media');
    }

    public function files(): void
    {
        if (!Drive::connected((int) Auth::id())) {
            json_response(['connected' => false]);
        }
        try {
            json_response(['connected' => true] + Drive::files((int) Auth::id(), input('continuation') ?: null, (string) input('q', '')));
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage(), 'connected' => Drive::connected((int) Auth::id())], 422);
        }
    }

    public function import(): void
    {
        set_time_limit(600);
        try {
            json_response(['media' => Drive::import((int) Auth::id(), Workspace::id(), (string) input('design', ''))]);
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
    }
}

}

namespace App\Controllers {

// ---- Controllers/HashtagsController.php ----
use App\Db;
use App\Hashtags;
use App\Workspace;

final class HashtagsController
{
    public function index(): void
    {
        $tags = Db::all('SELECT h.*, (SELECT COUNT(*) FROM hashtag_posts WHERE hashtag_id = h.id) AS total FROM hashtags h WHERE workspace_id = ? ORDER BY tag', [Workspace::id()]);
        $id = (int) input('tag', 0) ?: (int) ($tags[0]['id'] ?? 0);
        $current = null;
        foreach ($tags as $t) {
            if ((int) $t['id'] === $id) {
                $current = $t;
            }
        }
        [$from, $to, $days] = AnalyticsController::range();
        $tz = user_tz();
        $dayList = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
            $dayList[] = date('Y-m-d', $t);
        }
        view('hashtags/index', [
            'title' => 'Hashtag tracker',
            'tags' => $tags,
            'current' => $current,
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'r' => $current ? Hashtags::report((int) $current['id'], to_utc("$from 00:00"), to_utc("$to 23:59:59"), $dayList, $tz) : null,
            'networks' => Hashtags::NETWORKS,
        ]);
    }

    public function store(): void
    {
        $tag = Hashtags::clean((string) input('tag', ''));
        $nets = array_values(array_intersect((array) input('networks', []), array_keys(Hashtags::NETWORKS)));
        if ($tag === '' || !$nets) {
            flash('error', 'Enter a hashtag and pick at least one network.');
            redirect('/hashtags');
        }
        Db::query('INSERT OR IGNORE INTO hashtags (workspace_id, tag, networks, created_at) VALUES (?, ?, ?, ?)', [Workspace::id(), $tag, json_encode($nets), now()]);
        $h = Db::one('SELECT * FROM hashtags WHERE workspace_id = ? AND tag = ?', [Workspace::id(), $tag]);
        Db::update('hashtags', ['networks' => json_encode($nets), 'active' => 1], 'id = ?', [$h['id']]);
        $h['networks'] = json_encode($nets);
        $n = Hashtags::check($h);
        $err = Db::value('SELECT error FROM hashtags WHERE id = ?', [$h['id']]);
        flash($err ? 'error' : 'success', "Tracking #$tag. Found $n posts so far." . ($err ? " Some networks failed: $err" : ''));
        redirect('/hashtags', ['tag' => $h['id']]);
    }

    public function toggle(string $id): void
    {
        Db::query('UPDATE hashtags SET active = 1 - active WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        redirect('/hashtags', ['tag' => (int) $id]);
    }

    public function refresh(string $id): void
    {
        $h = Db::one('SELECT * FROM hashtags WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if ($h) {
            $n = Hashtags::check($h);
            flash('success', "$n new posts.");
        }
        redirect('/hashtags', ['tag' => (int) $id]);
    }

    public function delete(string $id): void
    {
        Db::query('DELETE FROM hashtags WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Stopped tracking and deleted collected posts.');
        redirect('/hashtags');
    }

    public function export(string $id): void
    {
        $h = Db::one('SELECT * FROM hashtags WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$h) {
            abort(404);
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="hashtag-' . $h['tag'] . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['network', 'posted_at_utc', 'author', 'text', 'likes', 'reposts', 'replies', 'url']);
        foreach (Db::all('SELECT * FROM hashtag_posts WHERE hashtag_id = ? ORDER BY posted_at DESC', [$h['id']]) as $p) {
            fputcsv($out, [$p['network'], $p['posted_at'], $p['author_handle'], $p['text'], $p['likes'], $p['reposts'], $p['replies'], $p['url']]);
        }
        fclose($out);
        exit;
    }
}

}

namespace App\Controllers {

// ---- Controllers/ImportController.php ----
use App\Auth;
use App\Db;
use App\Http;
use App\PostService;
use App\Providers\Registry;
use App\Workspace;

/**
 * Bulk CSV upload. Columns (header names, any order, case-insensitive):
 * date, time, text, accounts, media, first_comment, tags, status
 */
final class ImportController
{
    public function form(): void
    {
        $preview = $_SESSION['_import'] ?? null;
        view('import/index', [
            'title' => 'Bulk upload',
            'preview' => $preview && (int) $preview['workspace'] === Workspace::id() ? $preview : null,
            'accounts' => PostsController::accounts(),
        ]);
    }

    public function sample(): void
    {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="mixcreate-sample.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['date', 'time', 'text', 'accounts', 'media', 'first_comment', 'tags', 'status']);
        $d = (new \DateTimeImmutable('tomorrow', new \DateTimeZone(user_tz())))->format('Y-m-d');
        fputcsv($out, [$d, '09:00', 'Our spring sale starts today! 🌷', 'all', '', 'Shop now: https://example.com', 'Spring', 'schedule']);
        fputcsv($out, [$d, '13:30', 'Behind the scenes of our new studio', 'x; bluesky', 'https://example.com/photo.jpg', '', '', 'schedule']);
        fputcsv($out, ['', '', 'An idea to finish later', '', '', '', '', 'draft']);
        fclose($out);
        exit;
    }

    public function upload(): void
    {
        unset($_SESSION['_import']);
        $file = $_FILES['csv'] ?? null;
        if (!$file || !is_uploaded_file($file['tmp_name'] ?? '')) {
            flash('error', 'Choose a CSV file.');
            redirect('/import');
        }
        $fh = fopen($file['tmp_name'], 'r');
        $first = fgets($fh);
        // Strip a UTF-8 BOM and detect ; or , as the separator (Excel in some locales uses ;).
        $first = preg_replace('/^\xEF\xBB\xBF/', '', (string) $first);
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = array_map(fn($h) => strtolower(trim($h)), str_getcsv($first, $sep, '"', '\\'));
        if (!in_array('text', $header, true)) {
            flash('error', 'The first row must be a header with at least a “text” column. Download the sample to see the format.');
            redirect('/import');
        }
        $rows = [];
        $line = 1;
        while (($cols = fgetcsv($fh, 0, $sep, '"', '\\')) !== false) {
            $line++;
            if ($cols === [null] || trim(implode('', $cols)) === '') {
                continue;
            }
            $r = [];
            foreach ($header as $i => $h) {
                $r[$h] = trim((string) ($cols[$i] ?? ''));
            }
            $rows[] = $this->parse($r, $line);
            if (count($rows) >= 500) {
                break;
            }
        }
        fclose($fh);
        if (!$rows) {
            flash('error', 'No rows found.');
            redirect('/import');
        }
        $_SESSION['_import'] = ['workspace' => Workspace::id(), 'rows' => $rows];
        redirect('/import');
    }

    private function parse(array $r, int $line): array
    {
        $errors = [];
        $accounts = PostsController::accounts();
        $status = in_array(strtolower($r['status'] ?? ''), ['draft'], true) ? 'draft' : 'schedule';

        // Accounts: "all", ids, names, @usernames, or platform keys/names ("x", "bluesky", "Instagram").
        $ids = [];
        $spec = $r['accounts'] ?? '';
        if ($spec === '' && $status === 'schedule') {
            $errors[] = 'No accounts.';
        }
        foreach (preg_split('/[;,|]+/', $spec, -1, PREG_SPLIT_NO_EMPTY) as $token) {
            $t = strtolower(trim($token));
            $matched = false;
            foreach ($accounts as $a) {
                $pname = strtolower(Registry::class($a['provider'])::name());
                if ($t === 'all' || $t === (string) $a['id'] || $t === strtolower($a['name']) || ltrim($t, '@') === strtolower($a['username'])
                    || $t === $a['provider'] || $t === $pname || str_starts_with($pname, $t . ' ')) {
                    $ids[] = (int) $a['id'];
                    $matched = true;
                }
            }
            if (!$matched) {
                $errors[] = "Unknown account “{$token}”.";
            }
        }

        $scheduled = null;
        if ($status === 'schedule') {
            $date = $r['date'] ?? '';
            $time = $r['time'] ?? '' ?: '09:00';
            $ts = $date !== '' ? strtotime("$date $time") : false;
            if ($ts === false) {
                $errors[] = 'Missing or invalid date/time.';
            } else {
                $scheduled = to_utc(date('Y-m-d H:i', $ts));
                if (strtotime($scheduled . ' UTC') < time()) {
                    $errors[] = 'Date is in the past.';
                }
            }
        }

        $media = [];
        foreach (preg_split('/[\s;|]+/', $r['media'] ?? '', -1, PREG_SPLIT_NO_EMPTY) as $m) {
            if (ctype_digit($m) || preg_match('#^https?://#i', $m)) {
                $media[] = $m;
            } else {
                $errors[] = "Media must be a URL or media ID: “{$m}”.";
            }
        }
        return [
            'line' => $line,
            'text' => $r['text'] ?? '',
            'accounts' => array_values(array_unique($ids)),
            'scheduled_at' => $scheduled,
            'media' => $media,
            'first_comment' => $r['first_comment'] ?? '',
            'tags' => array_values(array_filter(array_map('trim', preg_split('/[;,]+/', $r['tags'] ?? '')))),
            'status' => $status,
            'errors' => $errors,
        ];
    }

    public function confirm(): void
    {
        $data = $_SESSION['_import'] ?? null;
        unset($_SESSION['_import']);
        if (!$data || (int) $data['workspace'] !== Workspace::id()) {
            redirect('/import');
        }
        set_time_limit(600);
        $wsId = Workspace::id();
        $ok = 0;
        $failed = [];
        foreach ($data['rows'] as $row) {
            if ($row['errors']) {
                continue;
            }
            $mediaIds = [];
            foreach ($row['media'] as $m) {
                if (ctype_digit($m)) {
                    $mediaIds[] = (int) $m;
                    continue;
                }
                $res = Http::get($m, ['timeout' => 120, 'headers' => ['Accept' => '*/*']]);
                if (!Http::ok($res)) {
                    $failed[] = "Line {$row['line']}: couldn’t download $m";
                    continue 2;
                }
                $tmp = tempnam(sys_get_temp_dir(), 'imp');
                file_put_contents($tmp, $res['body']);
                $saved = MediaController::storeLocal($tmp, basename((string) parse_url($m, PHP_URL_PATH)) ?: 'import', $wsId, (int) Auth::id());
                @unlink($tmp);
                if (isset($saved['error'])) {
                    $failed[] = "Line {$row['line']}: {$saved['error']}";
                    continue 2;
                }
                $mediaIds[] = $saved['id'];
            }
            $tagIds = [];
            foreach ($row['tags'] as $tag) {
                Db::query('INSERT OR IGNORE INTO tags (workspace_id, name) VALUES (?, ?)', [$wsId, mb_substr($tag, 0, 40)]);
                $tagIds[] = (int) Db::value('SELECT id FROM tags WHERE workspace_id = ? AND name = ?', [$wsId, mb_substr($tag, 0, 40)]);
            }
            $result = PostService::save(null, [
                'content' => $row['text'],
                'accounts' => $row['accounts'],
                'media' => $mediaIds,
                'tags' => $tagIds,
                'first_comment' => $row['first_comment'],
                'scheduled_at' => $row['scheduled_at'],
            ], $row['status'], $wsId, (int) Auth::id(), Workspace::can('admin'));
            if ($result['errors']) {
                $failed[] = "Line {$row['line']}: " . implode(' ', $result['errors']);
            } else {
                $ok++;
            }
        }
        flash($ok ? 'success' : 'error', "Imported $ok post" . ($ok === 1 ? '' : 's') . '.' . ($failed ? ' ' . count($failed) . ' skipped.' : ''));
        foreach (array_slice($failed, 0, 10) as $f) {
            flash('error', $f);
        }
        redirect('/posts');
    }

    public function cancel(): void
    {
        unset($_SESSION['_import']);
        redirect('/import');
    }
}

}

namespace App\Controllers {

// ---- Controllers/InboxController.php ----
use App\Auth;
use App\Db;
use App\Inbox;
use App\Providers\Registry;
use App\Workspace;

final class InboxController
{
    public function index(): void
    {
        $wsId = Workspace::id();
        $status = in_array(input('status'), ['open', 'replied', 'archived', 'all'], true) ? input('status') : 'open';
        $accountId = (int) input('account', 0);
        $where = ['i.workspace_id = ?'];
        $params = [$wsId];
        $where[] = match ($status) {
            'open' => "i.status IN ('new', 'read')",
            'replied' => "i.status = 'replied'",
            'archived' => "i.status = 'archived'",
            default => '1 = 1',
        };
        if ($accountId) {
            $where[] = 'i.account_id = ?';
            $params[] = $accountId;
        }
        $items = Db::all(
            'SELECT i.*, a.provider, a.name AS account_name, a.username AS account_username, a.avatar AS account_avatar, p.content AS post_content, u.name AS replied_by_name
             FROM inbox_items i JOIN accounts a ON a.id = i.account_id LEFT JOIN posts p ON p.id = i.post_id LEFT JOIN users u ON u.id = i.replied_by
             WHERE ' . implode(' AND ', $where) . ' ORDER BY i.remote_created_at DESC LIMIT 200',
            $params
        );
        $counts = array_column(Db::all('SELECT status, COUNT(*) AS n FROM inbox_items WHERE workspace_id = ? GROUP BY status', [$wsId]), 'n', 'status');
        $supported = [];
        foreach (Registry::all() as $k => $c) {
            if ($c::supportsComments()) {
                $supported[] = $c::name();
            }
        }
        view('inbox/index', [
            'title' => 'Inbox',
            'items' => $items,
            'status' => $status,
            'counts' => $counts,
            'accounts' => PostsController::accounts(),
            'accountId' => $accountId,
            'supported' => $supported,
        ]);
    }

    private function item(string $id): array
    {
        $item = Db::one('SELECT * FROM inbox_items WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$item) {
            abort(404);
        }
        return $item;
    }

    public function reply(string $id): void
    {
        $item = $this->item($id);
        $text = trim((string) input('text', ''));
        if ($text === '') {
            flash('error', 'Write a reply first.');
            back('/inbox');
        }
        try {
            Inbox::reply($item, $text, (int) Auth::id());
            if (wants_json()) {
                json_response(['ok' => true]);
            }
            flash('success', 'Reply sent.');
        } catch (\Throwable $e) {
            if (wants_json()) {
                json_response(['error' => $e->getMessage()], 422);
            }
            flash('error', 'Couldn’t reply: ' . $e->getMessage());
        }
        back('/inbox');
    }

    public function status(string $id): void
    {
        $item = $this->item($id);
        $status = in_array(input('status'), ['read', 'archived', 'new'], true) ? input('status') : 'read';
        Db::update('inbox_items', ['status' => $status], 'id = ?', [$item['id']]);
        if (wants_json()) {
            json_response(['ok' => true]);
        }
        back('/inbox');
    }

    public function markAllRead(): void
    {
        Db::query("UPDATE inbox_items SET status = 'read' WHERE workspace_id = ? AND status = 'new'", [Workspace::id()]);
        back('/inbox');
    }

    public function refresh(): void
    {
        kv_delete('inbox.checked');
        $n = Inbox::fetchDue(0);
        flash('success', $n ? "$n new comment" . ($n === 1 ? '' : 's') . '.' : 'No new comments.');
        redirect('/inbox');
    }
}

}

namespace App\Controllers {

// ---- Controllers/IntegrationsController.php ----
use App\Auth;
use App\Mailer;
use App\Settings;

/** Instance admin: email, AI, Canva and ads platform credentials. */
final class IntegrationsController
{
    /** group => [field => [label, type, secret?, placeholder?]] */
    public const GROUPS = [
        'smtp' => [
            'title' => 'Email (SMTP)',
            'hint' => 'Used to send scheduled reports. Leave the host empty to use PHP’s built-in mail().',
            'fields' => [
                'host' => ['Host', 'text', false, 'smtp.example.com'],
                'port' => ['Port', 'number', false, '587'],
                'encryption' => ['Encryption', 'select:tls=STARTTLS (587),ssl=SSL/TLS (465),none=None', false, ''],
                'username' => ['Username', 'text', false, ''],
                'password' => ['Password', 'password', true, ''],
                'from_email' => ['From address', 'email', false, 'reports@example.com'],
                'from_name' => ['From name', 'text', false, 'MixCreate'],
            ],
        ],
        'ai' => [
            'title' => 'AI writing',
            'hint' => 'Adds an AI button to the composer. OpenRouter is recommended: paste your own OpenRouter key and pick any model. If you’ve added your own OpenAI, Anthropic or Google keys in OpenRouter (BYOK), OpenRouter uses them automatically. Workspaces can also use their own OpenRouter key in their settings.',
            'fields' => [
                'provider' => ['Provider', 'select:openrouter=OpenRouter (recommended),openai=OpenAI,openai_compatible=OpenAI-compatible (custom URL),anthropic=Anthropic', false, ''],
                'api_key' => ['API key', 'password', true, 'sk-or-v1-…'],
                'model' => ['Model', 'model', false, 'openrouter/auto'],
                'base_url' => ['Base URL (custom provider only)', 'text', false, 'http://localhost:11434/v1'],
                'brand_voice' => ['Brand voice (optional)', 'textarea', false, 'e.g. Friendly and direct, no jargon, light humor'],
            ],
        ],
        'canva' => [
            'title' => 'Canva',
            'hint' => 'Lets people import designs from their own Canva accounts. Create an integration at canva.com/developers with the design:meta:read, design:content:read and profile:read scopes, and add the redirect URL below.',
            'fields' => [
                'client_id' => ['Client ID', 'text', false, ''],
                'client_secret' => ['Client secret', 'password', true, ''],
            ],
            'callback' => 'canva/callback',
        ],
        'gdrive' => [
            'title' => 'Google Drive',
            'hint' => 'Lets people import images and videos from their own Google Drive. In Google Cloud Console enable the Google Drive API, create an OAuth web client with the redirect URL below, and add the drive.readonly scope to your consent screen.',
            'fields' => [
                'client_id' => ['OAuth client ID', 'text', false, ''],
                'client_secret' => ['OAuth client secret', 'password', true, ''],
            ],
            'callback' => 'drive/callback',
        ],
        'meta_ads' => [
            'title' => 'Meta Ads (Facebook & Instagram)',
            'hint' => 'Uses your Facebook app. Add the Marketing API product and the redirect URL below; requires the ads_read permission.',
            'fields' => [
                'app_id' => ['App ID', 'text', false, ''],
                'app_secret' => ['App secret', 'password', true, ''],
            ],
            'callback' => 'ads/callback/meta_ads',
        ],
        'google_ads' => [
            'title' => 'Google Ads',
            'hint' => 'Needs a Google Ads developer token (from your manager account’s API Center) and a Google OAuth web client with the redirect URL below.',
            'fields' => [
                'client_id' => ['OAuth client ID', 'text', false, ''],
                'client_secret' => ['OAuth client secret', 'password', true, ''],
                'developer_token' => ['Developer token', 'password', true, ''],
                'login_customer_id' => ['Manager account ID (optional)', 'text', false, '123-456-7890'],
            ],
            'callback' => 'ads/callback/google_ads',
        ],
        'tiktok_ads' => [
            'title' => 'TikTok Ads',
            'hint' => 'Create a developer app at business-api.tiktok.com with reporting access and set its redirect URL to the one below.',
            'fields' => [
                'app_id' => ['App ID', 'text', false, ''],
                'app_secret' => ['Secret', 'password', true, ''],
            ],
            'callback' => 'ads/callback/tiktok_ads',
        ],
    ];

    public function index(): void
    {
        $values = [];
        foreach (array_keys(self::GROUPS) as $g) {
            $values[$g] = Settings::get($g);
        }
        view('admin/integrations', ['title' => 'Integrations', 'groups' => self::GROUPS, 'values' => $values, 'models' => \App\Ai::openRouterModels()]);
    }

    public function save(string $group): void
    {
        if (!isset(self::GROUPS[$group])) {
            abort(404);
        }
        $current = Settings::get($group);
        foreach (self::GROUPS[$group]['fields'] as $key => [$label, $type, $secret]) {
            $v = (string) input($key, '');
            if ($secret && $v === '') {
                continue; // keep stored secret
            }
            $current[$key] = $v;
        }
        Settings::save($group, $current);
        flash('success', self::GROUPS[$group]['title'] . ' saved.');
        redirect('/admin/integrations#' . $group);
    }

    public function testEmail(): void
    {
        try {
            Mailer::send([(string) Auth::user()['email']], 'MixCreate test email', '<p>Your mail settings work.</p>');
            flash('success', 'Test email sent to ' . Auth::user()['email'] . '.');
        } catch (\Throwable $e) {
            flash('error', 'Email failed: ' . $e->getMessage());
        }
        redirect('/admin/integrations#smtp');
    }

    public function testAi(): void
    {
        try {
            $out = \App\Ai::complete('Reply with exactly: OK', 'Say OK');
            flash('success', 'AI responded: ' . str_limit(trim($out), 80));
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/admin/integrations#ai');
    }
}

}

namespace App\Controllers {

// ---- Controllers/LibraryController.php ----
use App\Auth;
use App\Db;
use App\Workspace;

/** Templates, hashtag groups, variables and tags: reusable pieces for the composer. */
final class LibraryController
{
    public function index(): void
    {
        $wsId = Workspace::id();
        view('library/index', [
            'title' => 'Library',
            'templates' => Db::all('SELECT * FROM templates WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'groups' => Db::all('SELECT * FROM hashtag_groups WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'variables' => Db::all('SELECT * FROM variables WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'tags' => Db::all('SELECT t.*, (SELECT COUNT(*) FROM post_tag WHERE tag_id = t.id) AS posts FROM tags t WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'edit' => (int) input('edit', 0),
        ]);
    }

    public function saveTemplate(): void
    {
        $id = (int) input('id', 0);
        $name = mb_substr((string) input('name', ''), 0, 80);
        $content = str_replace("\r\n", "\n", (string) input('content', ''));
        $media = array_values(array_filter(array_map('intval', (array) input('media', []))));
        if ($name === '' || ($content === '' && !$media)) {
            if (wants_json()) {
                json_response(['error' => 'A template needs a name and some text.'], 422);
            }
            flash('error', 'A template needs a name and some text.');
            redirect('/library#templates');
        }
        $row = ['name' => $name, 'content' => $content, 'media' => json_encode($media), 'updated_at' => now()];
        if ($id) {
            Db::update('templates', $row, 'id = ? AND workspace_id = ?', [$id, Workspace::id()]);
        } else {
            $id = Db::insert('templates', $row + ['workspace_id' => Workspace::id(), 'created_by' => Auth::id(), 'created_at' => now()]);
        }
        if (wants_json()) {
            json_response(['template' => Db::one('SELECT id, name, content FROM templates WHERE id = ?', [$id])]);
        }
        flash('success', 'Template saved.');
        redirect('/library#templates');
    }

    public function deleteTemplate(string $id): void
    {
        Db::query('DELETE FROM templates WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Template deleted.');
        redirect('/library#templates');
    }

    public function saveGroup(): void
    {
        $id = (int) input('id', 0);
        $name = mb_substr((string) input('name', ''), 0, 80);
        $tags = self::normalizeHashtags((string) input('hashtags', ''));
        if ($name === '' || $tags === '') {
            flash('error', 'A hashtag group needs a name and at least one hashtag.');
            redirect('/library#hashtags');
        }
        if ($id) {
            Db::update('hashtag_groups', ['name' => $name, 'hashtags' => $tags], 'id = ? AND workspace_id = ?', [$id, Workspace::id()]);
        } else {
            Db::insert('hashtag_groups', ['workspace_id' => Workspace::id(), 'name' => $name, 'hashtags' => $tags, 'created_at' => now()]);
        }
        flash('success', 'Hashtag group saved.');
        redirect('/library#hashtags');
    }

    public function deleteGroup(string $id): void
    {
        Db::query('DELETE FROM hashtag_groups WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Hashtag group deleted.');
        redirect('/library#hashtags');
    }

    public function saveVariable(): void
    {
        $id = (int) input('id', 0);
        $name = (string) input('name', '');
        $value = (string) input('value', '');
        if (!preg_match('/^[\w.-]{1,40}$/', $name)) {
            flash('error', 'Variable names can use letters, numbers, dots, dashes and underscores.');
            redirect('/library#variables');
        }
        try {
            if ($id) {
                Db::update('variables', ['name' => $name, 'value' => $value], 'id = ? AND workspace_id = ?', [$id, Workspace::id()]);
            } else {
                Db::insert('variables', ['workspace_id' => Workspace::id(), 'name' => $name, 'value' => $value]);
            }
        } catch (\PDOException) {
            flash('error', "A variable called “{$name}” already exists.");
            redirect('/library#variables');
        }
        flash('success', 'Variable saved.');
        redirect('/library#variables');
    }

    public function deleteVariable(string $id): void
    {
        Db::query('DELETE FROM variables WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Variable deleted.');
        redirect('/library#variables');
    }

    /** "foo, #bar baz" → "#foo #bar #baz" */
    public static function normalizeHashtags(string $raw): string
    {
        $words = preg_split('/[\s,]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($words as $w) {
            $w = '#' . ltrim($w, '#');
            if (mb_strlen($w) > 1 && !in_array($w, $out, true)) {
                $out[] = $w;
            }
        }
        return implode(' ', $out);
    }
}

}

namespace App\Controllers {

// ---- Controllers/McpController.php ----
use App\Analytics;
use App\Db;
use App\Inbox;
use App\PostService;

/**
 * MCP server over Streamable HTTP at /mcp, so AI assistants can read analytics and draft or schedule posts.
 * Authenticate with an API token: "Authorization: Bearer mc_...".
 * Speaks both the stateless 2026-07-28 protocol (server/discover, per-request _meta) and the
 * 2025-11-25 handshake (initialize), answering with plain JSON responses.
 */
final class McpController
{
    private const VERSIONS = ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'];
    private array $user;
    private int $workspaceId;
    private string $role;
    private string $version = self::VERSIONS[0];

    public function handle(): void
    {
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            http_response_code(405);
            exit;
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $row = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? Db::one(
            'SELECT t.id AS token_id, t.workspace_id, u.*, wu.role FROM api_tokens t JOIN users u ON u.id = t.user_id
             JOIN workspace_user wu ON wu.user_id = t.user_id AND wu.workspace_id = t.workspace_id WHERE t.token = ?',
            [hash('sha256', $m[1])]
        ) : null;
        if (!$row) {
            header('WWW-Authenticate: Bearer realm="MixCreate"');
            json_response(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Unauthorized: send an API token from MixCreate → API & webhooks.']], 401);
        }
        Db::update('api_tokens', ['last_used_at' => now()], 'id = ?', [$row['token_id']]);
        $this->user = $row;
        $this->workspaceId = (int) $row['workspace_id'];
        $this->role = $row['is_admin'] ? 'admin' : $row['role'];

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            json_response(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
        }
        $batch = array_is_list($body) ? $body : [$body];
        $out = [];
        foreach ($batch as $msg) {
            $res = $this->dispatch(is_array($msg) ? $msg : []);
            if ($res !== null) {
                $out[] = $res;
            }
        }
        if (!$out) {
            http_response_code(202); // notifications only
            exit;
        }
        json_response(array_is_list($body) ? $out : $out[0]);
    }

    private function dispatch(array $msg): ?array
    {
        $id = $msg['id'] ?? null;
        $method = (string) ($msg['method'] ?? '');
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
        if ($id === null) {
            return null; // notification (e.g. notifications/initialized)
        }
        $requested = $params['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null;
        if ($requested !== null) {
            if (!in_array($requested, self::VERSIONS, true)) {
                return $this->error($id, -32022, 'Unsupported protocol version', ['supported' => self::VERSIONS, 'requested' => $requested]);
            }
            $this->version = $requested;
        }
        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'server/discover' => $this->discover(),
                'ping' => [],
                'tools/list' => ['tools' => $this->tools(), 'ttlMs' => 3600000, 'cacheScope' => 'private'],
                'tools/call' => $this->call((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? [])),
                'resources/list' => ['resources' => [], 'ttlMs' => 3600000, 'cacheScope' => 'private'],
                'prompts/list' => ['prompts' => [], 'ttlMs' => 3600000, 'cacheScope' => 'private'],
                default => throw new \BadMethodCallException("Method not found: $method"),
            };
        } catch (\BadMethodCallException $e) {
            return $this->error($id, -32601, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($id, -32602, $e->getMessage());
        }
        $result['resultType'] = 'complete';
        $result['_meta']['io.modelcontextprotocol/serverInfo'] = $this->serverInfo();
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error(mixed $id, int $code, string $message, ?array $data = null): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => array_filter(['code' => $code, 'message' => $message, 'data' => $data], fn($v) => $v !== null)];
    }

    private function serverInfo(): array
    {
        return ['name' => 'mixcreate', 'title' => \App\Config::get('app_name', 'MixCreate'), 'version' => '1.0.0'];
    }

    private function initialize(array $params): array
    {
        $v = in_array($params['protocolVersion'] ?? '', self::VERSIONS, true) ? $params['protocolVersion'] : '2025-11-25';
        return ['protocolVersion' => $v, 'capabilities' => ['tools' => ['listChanged' => false]], 'serverInfo' => $this->serverInfo(),
            'instructions' => 'Tools for the MixCreate workspace this token belongs to. Times are ISO 8601; drafts are safest unless the user asked to schedule or publish.'];
    }

    private function discover(): array
    {
        return ['supportedVersions' => self::VERSIONS, 'capabilities' => ['tools' => new \stdClass()], 'serverInfo' => $this->serverInfo()];
    }

    private function tools(): array
    {
        $obj = fn(array $props, array $required = []) => ['type' => 'object', 'properties' => $props ?: new \stdClass(), 'required' => $required];
        return [
            ['name' => 'create_post', 'title' => 'Create post', 'description' => 'Create a draft, schedule a post, or publish now to connected accounts. Use list_accounts for account ids.',
                'inputSchema' => $obj([
                    'content' => ['type' => 'string', 'description' => 'Post text'],
                    'account_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'action' => ['type' => 'string', 'enum' => ['draft', 'schedule', 'now'], 'default' => 'draft'],
                    'scheduled_at' => ['type' => 'string', 'description' => 'ISO 8601 date-time, required for schedule'],
                    'first_comment' => ['type' => 'string'],
                    'media_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ], ['content'])],
            ['name' => 'get_analytics', 'title' => 'Get analytics', 'description' => 'Followers, engagement, top posts, per-network results and best times to post for a date range.',
                'inputSchema' => $obj(['from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'to' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'account_id' => ['type' => 'integer']]),
                'annotations' => ['readOnlyHint' => true]],
            ['name' => 'get_post', 'title' => 'Get post', 'description' => 'One post with per-account status, links and errors.',
                'inputSchema' => $obj(['id' => ['type' => 'integer']], ['id']), 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'list_accounts', 'title' => 'List accounts', 'description' => 'Connected social accounts in this workspace.',
                'inputSchema' => $obj([]), 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'list_inbox', 'title' => 'List inbox', 'description' => 'Recent comments on published posts.',
                'inputSchema' => $obj(['status' => ['type' => 'string', 'enum' => ['open', 'replied', 'all'], 'default' => 'open'], 'limit' => ['type' => 'integer', 'default' => 20]]),
                'annotations' => ['readOnlyHint' => true]],
            ['name' => 'list_posts', 'title' => 'List posts', 'description' => 'Posts by status, newest first.',
                'inputSchema' => $obj(['status' => ['type' => 'string', 'enum' => ['draft', 'pending_approval', 'scheduled', 'published', 'failed']], 'limit' => ['type' => 'integer', 'default' => 20]]),
                'annotations' => ['readOnlyHint' => true]],
            ['name' => 'reply_to_comment', 'title' => 'Reply to comment', 'description' => 'Reply to an inbox comment as the account it was left on.',
                'inputSchema' => $obj(['id' => ['type' => 'integer', 'description' => 'Inbox item id'], 'text' => ['type' => 'string']], ['id', 'text'])],
        ];
    }

    private function requireMember(): void
    {
        if (!in_array($this->role, ['member', 'admin'], true)) {
            throw new \InvalidArgumentException('Your role in this workspace is read-only.');
        }
    }

    private function call(string $name, array $args): array
    {
        try {
            $data = match ($name) {
                'list_accounts' => array_map([AccountsController::class, 'toArray'], Db::all('SELECT * FROM accounts WHERE workspace_id = ? ORDER BY provider, name', [$this->workspaceId])),
                'list_posts' => $this->listPosts($args),
                'get_post' => $this->getPost($args),
                'create_post' => $this->createPost($args),
                'get_analytics' => $this->analytics($args),
                'list_inbox' => $this->inbox($args),
                'reply_to_comment' => $this->reply($args),
                default => throw new \InvalidArgumentException("Unknown tool: $name"),
            };
        } catch (\InvalidArgumentException $e) {
            if (str_starts_with($e->getMessage(), 'Unknown tool')) {
                throw $e;
            }
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        } catch (\Throwable $e) {
            return ['content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]], 'isError' => true];
        }
        $structured = array_is_list($data) ? ['items' => $data] : $data;
        return ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]], 'structuredContent' => $structured];
    }

    private function listPosts(array $a): array
    {
        $limit = max(1, min(50, (int) ($a['limit'] ?? 20)));
        $where = 'workspace_id = ?';
        $p = [$this->workspaceId];
        if (!empty($a['status'])) {
            $where .= ' AND status = ?';
            $p[] = (string) $a['status'];
        }
        return array_map([PostService::class, 'toArray'], Db::all("SELECT * FROM posts WHERE $where ORDER BY id DESC LIMIT $limit", $p));
    }

    private function getPost(array $a): array
    {
        $post = PostService::find((int) ($a['id'] ?? 0), $this->workspaceId);
        if (!$post) {
            throw new \InvalidArgumentException('Post not found.');
        }
        return PostService::toArray($post);
    }

    private function createPost(array $a): array
    {
        $this->requireMember();
        $action = in_array($a['action'] ?? 'draft', ['draft', 'schedule', 'now'], true) ? ($a['action'] ?? 'draft') : 'draft';
        $scheduled = null;
        if (!empty($a['scheduled_at'])) {
            $ts = strtotime((string) $a['scheduled_at']);
            if ($ts === false) {
                throw new \InvalidArgumentException('scheduled_at must be ISO 8601.');
            }
            $scheduled = gmdate('Y-m-d H:i:s', $ts);
        }
        $result = PostService::save(null, [
            'content' => (string) ($a['content'] ?? ''),
            'accounts' => (array) ($a['account_ids'] ?? []),
            'media' => (array) ($a['media_ids'] ?? []),
            'first_comment' => (string) ($a['first_comment'] ?? ''),
            'scheduled_at' => $scheduled,
        ], $action, $this->workspaceId, (int) $this->user['id'], $this->role === 'admin');
        if ($result['errors']) {
            throw new \InvalidArgumentException('Not saved: ' . implode(' ', $result['errors']));
        }
        if ($action === 'now' && $result['post']['status'] === 'scheduled') {
            PostService::publishNowInBackground((int) $result['post']['id']);
        }
        return PostService::toArray($result['post']);
    }

    private function analytics(array $a): array
    {
        $tz = $this->user['timezone'] ?: 'UTC';
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($a['to'] ?? '')) ? $a['to'] : (new \DateTimeImmutable('today', new \DateTimeZone($tz)))->format('Y-m-d');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($a['from'] ?? '')) ? $a['from'] : date('Y-m-d', strtotime("$to -29 days"));
        $ids = array_map('intval', array_column(Db::all('SELECT id FROM accounts WHERE workspace_id = ?', [$this->workspaceId]), 'id'));
        if (!empty($a['account_id'])) {
            $ids = array_values(array_intersect($ids, [(int) $a['account_id']]));
        }
        $d = Analytics::build($this->workspaceId, $ids, $from, $to, $tz);
        return [
            'from' => $from, 'to' => $to, 'timezone' => $tz,
            'followers' => $d['followersNow'], 'followers_change' => $d['followersChange'],
            'totals' => $d['totals'], 'engagement_rate_percent' => $d['rate'],
            'by_network' => array_map(fn($n) => ['network' => $n['provider'], 'followers' => $n['followers'], 'posts' => $n['posts'], 'engagement' => $n['engagement'], 'engagement_rate_percent' => $n['rate']], $d['perNetwork']),
            'top_posts' => array_map(fn($p) => ['post_id' => (int) $p['id'], 'network' => $p['provider'], 'text' => str_limit($p['content'], 200), 'engagement' => $p['engagement'], 'url' => $p['url']], array_slice($d['top'], 0, 5)),
            'formats' => $d['types'],
            'best_times' => array_map(fn($c) => ['day' => Analytics::dayName($c['day']), 'hour' => Analytics::hourLabel($c['hour']), 'avg_engagement' => $c['avg']], $d['best']['top']),
        ];
    }

    private function inbox(array $a): array
    {
        $limit = max(1, min(100, (int) ($a['limit'] ?? 20)));
        $status = $a['status'] ?? 'open';
        $where = match ($status) { 'replied' => "AND i.status = 'replied'", 'all' => '', default => "AND i.status IN ('new', 'read')" };
        return array_map(fn($i) => ['id' => (int) $i['id'], 'network' => $i['provider'], 'account' => $i['account_name'], 'author' => $i['author_name'] ?: $i['author_handle'],
            'text' => $i['text'], 'on_post' => str_limit((string) $i['post_content'], 120), 'status' => $i['status'], 'at' => gmdate('c', strtotime($i['remote_created_at'] . ' UTC'))],
            Db::all("SELECT i.*, a.provider, a.name AS account_name, p.content AS post_content FROM inbox_items i JOIN accounts a ON a.id = i.account_id LEFT JOIN posts p ON p.id = i.post_id
                     WHERE i.workspace_id = ? $where ORDER BY i.remote_created_at DESC LIMIT $limit", [$this->workspaceId]));
    }

    private function reply(array $a): array
    {
        $this->requireMember();
        $item = Db::one('SELECT * FROM inbox_items WHERE id = ? AND workspace_id = ?', [(int) ($a['id'] ?? 0), $this->workspaceId]);
        if (!$item) {
            throw new \InvalidArgumentException('Inbox item not found.');
        }
        $text = trim((string) ($a['text'] ?? ''));
        if ($text === '') {
            throw new \InvalidArgumentException('Reply text is empty.');
        }
        Inbox::reply($item, $text, (int) $this->user['id']);
        return ['ok' => true, 'id' => (int) $item['id'], 'status' => 'replied'];
    }
}

}

namespace App\Controllers {

// ---- Controllers/MediaController.php ----
use App\Auth;
use App\Config;
use App\Db;
use App\Workspace;

final class MediaController
{
    public const TYPES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'video/mp4' => 'mp4',
        'video/quicktime' => 'mov',
        'video/webm' => 'webm',
    ];

    public function index(): void
    {
        $rows = Db::all('SELECT * FROM media WHERE workspace_id = ? ORDER BY id DESC LIMIT 200', [Workspace::id()]);
        $items = array_map(fn($m) => self::toArray($m), $rows);
        if (wants_json()) {
            json_response(['media' => $items]);
        }
        view('media/index', ['title' => 'Media', 'media' => $items]);
    }

    public function upload(): void
    {
        $file = $_FILES['file'] ?? null;
        $result = self::store($file, Workspace::id(), (int) Auth::id());
        if (isset($result['error'])) {
            if (wants_json()) {
                json_response(['error' => $result['error']], 422);
            }
            flash('error', $result['error']);
            redirect('/media');
        }
        if (wants_json()) {
            json_response(['media' => $result]);
        }
        flash('success', 'Uploaded.');
        redirect('/media');
    }

    /** Validates and saves an uploaded file. Returns the media array or ['error' => message]. */
    public static function store(?array $file, int $workspaceId, int $userId): array
    {
        if (!$file || !is_uploaded_file($file['tmp_name'] ?? '')) {
            $codes = [UPLOAD_ERR_INI_SIZE => 'The file is larger than the server allows (upload_max_filesize).', UPLOAD_ERR_FORM_SIZE => 'The file is too large.'];
            return ['error' => $codes[$file['error'] ?? 0] ?? 'No file was uploaded.'];
        }
        return self::save($file['tmp_name'], (string) $file['name'], $workspaceId, $userId, true);
    }

    /** Saves a file already on disk (e.g. downloaded from Canva). The source file is copied. */
    public static function storeLocal(string $path, string $name, int $workspaceId, int $userId): array
    {
        return self::save($path, $name, $workspaceId, $userId, false);
    }

    private static function save(string $tmp, string $origName, int $workspaceId, int $userId, bool $uploaded): array
    {
        $size = (int) filesize($tmp);
        $maxBytes = (int) Config::get('max_upload_mb', 200) * 1024 * 1024;
        if ($size > $maxBytes) {
            return ['error' => 'The file is larger than ' . Config::get('max_upload_mb', 200) . ' MB.'];
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        if (!isset(self::TYPES[$mime])) {
            return ['error' => 'Only JPG, PNG, GIF, WebP, MP4, MOV and WebM files are supported.'];
        }
        $ext = self::TYPES[$mime];
        $key = \App\Blob::newKey($ext);
        try {
            \App\Blob::putFile($key, $tmp);
        } catch (\Throwable $e) {
            return ['error' => 'Couldn’t save the file: ' . $e->getMessage()];
        }
        $w = $h = null;
        if (str_starts_with($mime, 'image/')) {
            $dims = @getimagesize($tmp);
            if ($dims) {
                [$w, $h] = $dims;
            }
        }
        if ($uploaded) {
            @unlink($tmp);
        }
        $name = mb_substr(preg_replace('/[^\w.\- ]+/u', '', $origName) ?: 'file.' . $ext, 0, 120);
        $id = Db::insert('media', [
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'path' => $key,
            'name' => $name,
            'mime' => $mime,
            'size' => $size,
            'width' => $w,
            'height' => $h,
            'created_at' => now(),
        ]);
        return self::toArray(Db::one('SELECT * FROM media WHERE id = ?', [$id]));
    }

    public function delete(string $id): void
    {
        $m = Db::one('SELECT * FROM media WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$m) {
            abort(404);
        }
        Db::query('DELETE FROM media WHERE id = ?', [$m['id']]);
        \App\Blob::deleteMedia($m);
        if (wants_json()) {
            json_response(['ok' => true]);
        }
        flash('success', 'Deleted.');
        redirect('/media');
    }

    public static function toArray(array $m): array
    {
        return [
            'id' => (int) $m['id'],
            'url' => media_url($m),
            'name' => $m['name'],
            'mime' => $m['mime'],
            'size' => (int) $m['size'],
            'width' => $m['width'] ? (int) $m['width'] : null,
            'height' => $m['height'] ? (int) $m['height'] : null,
            'is_video' => str_starts_with($m['mime'], 'video/'),
        ];
    }
}

}

namespace App\Controllers {

// ---- Controllers/MembersController.php ----
use App\Auth;
use App\Db;
use App\Workspace;

final class MembersController
{
    public function index(): void
    {
        $wsId = Workspace::id();
        view('members/index', [
            'title' => 'Team',
            'members' => Db::all(
                'SELECT u.id, u.name, u.email, u.is_admin, wu.role, wu.joined_at FROM workspace_user wu
                 JOIN users u ON u.id = wu.user_id WHERE wu.workspace_id = ? ORDER BY u.name',
                [$wsId]
            ),
            'invitations' => Db::all(
                'SELECT * FROM invitations WHERE workspace_id = ? AND accepted_at IS NULL ORDER BY created_at DESC',
                [$wsId]
            ),
            'inviteLink' => $_SESSION['_invite_link'] ?? null,
        ]);
    }

    public function invite(): void
    {
        unset($_SESSION['_invite_link']);
        $email = (string) input('email', '');
        $role = (string) input('role', 'member');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !isset(Workspace::ROLES[$role])) {
            flash('error', 'Enter a valid email and role.');
            redirect('/members');
        }
        $wsId = Workspace::id();
        $existing = Db::one('SELECT id FROM users WHERE email = ?', [$email]);
        if ($existing && Db::value('SELECT 1 FROM workspace_user WHERE workspace_id = ? AND user_id = ?', [$wsId, $existing['id']])) {
            flash('error', "$email is already in this workspace.");
            redirect('/members');
        }
        Db::query('DELETE FROM invitations WHERE workspace_id = ? AND email = ? AND accepted_at IS NULL', [$wsId, $email]);
        $token = bin2hex(random_bytes(24));
        Db::insert('invitations', [
            'workspace_id' => $wsId,
            'email' => $email,
            'role' => $role,
            'token' => hash('sha256', $token),
            'invited_by' => Auth::id(),
            'created_at' => now(),
        ]);
        // No mail server is assumed; the admin copies the link and sends it however they like.
        $_SESSION['_invite_link'] = ['email' => $email, 'url' => abs_url('invite/' . $token)];
        redirect('/members');
    }

    public function role(string $id): void
    {
        $role = (string) input('role', '');
        if (!isset(Workspace::ROLES[$role])) {
            abort(422, 'Unknown role.');
        }
        $this->guardLastAdmin((int) $id, $role !== 'admin');
        Db::update('workspace_user', ['role' => $role], 'workspace_id = ? AND user_id = ?', [Workspace::id(), (int) $id]);
        flash('success', 'Role updated.');
        redirect('/members');
    }

    public function remove(string $id): void
    {
        $this->guardLastAdmin((int) $id, true);
        Db::query('DELETE FROM workspace_user WHERE workspace_id = ? AND user_id = ?', [Workspace::id(), (int) $id]);
        Db::query('UPDATE users SET current_workspace_id = NULL WHERE id = ? AND current_workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Removed from workspace.');
        redirect((int) $id === Auth::id() ? '/' : '/members');
    }

    public function revoke(string $id): void
    {
        Db::query('DELETE FROM invitations WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        unset($_SESSION['_invite_link']);
        flash('success', 'Invitation revoked.');
        redirect('/members');
    }

    private function guardLastAdmin(int $userId, bool $losingAdmin): void
    {
        if (!$losingAdmin) {
            return;
        }
        $role = Db::value('SELECT role FROM workspace_user WHERE workspace_id = ? AND user_id = ?', [Workspace::id(), $userId]);
        $admins = (int) Db::value("SELECT COUNT(*) FROM workspace_user WHERE workspace_id = ? AND role = 'admin'", [Workspace::id()]);
        if ($role === 'admin' && $admins <= 1) {
            flash('error', 'A workspace needs at least one admin.');
            redirect('/members');
        }
    }
}

}

namespace App\Controllers {

// ---- Controllers/PostsController.php ----
use App\Auth;
use App\Db;
use App\Hooks;
use App\PostService;
use App\Providers\Registry;
use App\Publisher;
use App\Workspace;

final class PostsController
{
    public const STATUSES = [
        'draft' => 'Draft',
        'pending_approval' => 'Needs approval',
        'scheduled' => 'Scheduled',
        'publishing' => 'Publishing',
        'published' => 'Published',
        'failed' => 'Failed',
    ];

    public function index(): void
    {
        $wsId = Workspace::id();
        $status = (string) input('status', '');
        $accountId = (int) input('account', 0);
        $tagId = (int) input('tag', 0);
        $q = (string) input('q', '');
        $page = max(1, (int) input('page', 1));
        $per = 25;

        $where = ['p.workspace_id = ?'];
        $params = [$wsId];
        if (isset(self::STATUSES[$status])) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if ($accountId) {
            $where[] = 'EXISTS (SELECT 1 FROM post_accounts pa WHERE pa.post_id = p.id AND pa.account_id = ?)';
            $params[] = $accountId;
        }
        if ($tagId) {
            $where[] = 'EXISTS (SELECT 1 FROM post_tag pt WHERE pt.post_id = p.id AND pt.tag_id = ?)';
            $params[] = $tagId;
        }
        if ($q !== '') {
            $where[] = 'p.content LIKE ?';
            $params[] = '%' . $q . '%';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) Db::value("SELECT COUNT(*) FROM posts p WHERE $whereSql", $params);
        $order = $status === 'published' ? 'p.published_at DESC' : "CASE WHEN p.scheduled_at IS NULL THEN 1 ELSE 0 END, p.scheduled_at DESC, p.updated_at DESC";
        $posts = Db::all(
            "SELECT p.*, u.name AS author FROM posts p LEFT JOIN users u ON u.id = p.user_id
             WHERE $whereSql ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per),
            $params
        );

        $counts = array_column(
            Db::all('SELECT status, COUNT(*) AS n FROM posts WHERE workspace_id = ? GROUP BY status', [$wsId]),
            'n',
            'status'
        );

        view('posts/index', [
            'title' => 'Posts',
            'posts' => self::decorate($posts),
            'status' => $status,
            'counts' => $counts,
            'accounts' => self::accounts(),
            'tags' => Db::all('SELECT * FROM tags WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'filters' => ['account' => $accountId, 'tag' => $tagId, 'q' => $q],
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $per)),
        ]);
    }

    public function create(): void
    {
        $post = ['id' => null, 'content' => '', 'media' => '[]', 'status' => 'draft', 'scheduled_at' => null, 'review_note' => null, 'first_comment' => null];
        // Prefill from the calendar ("?date=2026-11-03&time=09:00") or a template.
        $date = (string) input('date', '');
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $time = preg_match('/^\d{2}:\d{2}$/', (string) input('time', '')) ? input('time') : '09:00';
            $post['scheduled_at'] = to_utc("$date $time");
        }
        if ($tid = (int) input('template', 0)) {
            $tpl = Db::one('SELECT * FROM templates WHERE id = ? AND workspace_id = ?', [$tid, Workspace::id()]);
            if ($tpl) {
                $post['content'] = $tpl['content'];
                $post['media'] = $tpl['media'];
            }
        }
        $this->composer($post, [], [], []);
    }

    public function edit(string $id): void
    {
        $post = PostService::find((int) $id, Workspace::id());
        if (!$post) {
            abort(404);
        }
        $targets = Db::all('SELECT * FROM post_accounts WHERE post_id = ?', [$post['id']]);
        $tagIds = array_map('intval', array_column(Db::all('SELECT tag_id FROM post_tag WHERE post_id = ?', [$post['id']]), 'tag_id'));
        $readonly = in_array($post['status'], ['publishing', 'published'], true) || !Workspace::can('member');
        $this->composer($post, $targets, $tagIds, [], $readonly);
    }

    private function composer(array $post, array $targets, array $tagIds, array $errors, bool $readonly = false): never
    {
        $wsId = Workspace::id();
        $old = $_SESSION['_old_now'] ?? [];
        if ($old) {
            $post['content'] = $old['content'] ?? $post['content'];
            $post['media'] = json_encode(array_map('intval', $old['media'] ?? []));
            $targets = array_map(fn($a) => ['account_id' => (int) $a, 'content' => $old['overrides'][$a] ?? null, 'options' => json_encode(['type' => $old['types'][$a] ?? 'feed']), 'status' => 'pending'], $old['accounts'] ?? []);
            $post['first_comment'] = $old['first_comment'] ?? ($post['first_comment'] ?? null);
            $tagIds = array_map('intval', $old['tags'] ?? []);
            if (!empty($old['date'])) {
                $post['scheduled_at'] = to_utc(($old['date'] ?? '') . ' ' . ($old['time'] ?? '09:00'));
            }
        }

        $providers = [];
        foreach (Registry::all() as $key => $class) {
            $providers[$key] = ['name' => $class::name(), 'color' => $class::color(), 'limit' => $class::textLimit(), 'rules' => $class::mediaRules(),
                'types' => $class::postTypes(), 'firstComment' => $class::supportsFirstComment()];
        }
        $ws = Workspace::current();

        view('posts/composer', [
            'title' => $post['id'] ? ($readonly ? 'View post' : 'Edit post') : 'New post',
            'post' => $post,
            'readonly' => $readonly,
            'targets' => $targets,
            'tagIds' => $tagIds,
            'accounts' => self::accounts(),
            'providers' => $providers,
            'media' => Publisher::resolveMedia(json_arr($post['media'])),
            'tags' => Db::all('SELECT * FROM tags WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'templates' => Db::all('SELECT id, name, content FROM templates WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'hashtagGroups' => Db::all('SELECT * FROM hashtag_groups WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'variables' => Db::all('SELECT name, value FROM variables WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'needsApproval' => $ws['require_approval'] && !Workspace::can('admin'),
            'canApprove' => Workspace::can('admin'),
            'panels' => Hooks::filter('composer.panels', [], $post),
            'bestTimes' => $readonly ? [] : \App\Analytics::bestTimes($wsId, array_map('intval', array_column(self::accounts(), 'id')), user_tz())['top'],
            'aiEnabled' => \App\Ai::enabled($wsId),
            'canvaEnabled' => \App\Canva::enabled(),
            'driveEnabled' => \App\Drive::enabled(),
            'autolists' => Db::all('SELECT id, name FROM autolists WHERE workspace_id = ? ORDER BY name', [$wsId]),
        ]);
    }

    public function store(): void
    {
        $this->save(null);
    }

    public function update(string $id): void
    {
        $action = (string) input('action', '');
        if (in_array($action, ['approve', 'reject'], true)) {
            $this->review((int) $id, $action);
        }
        $this->save((int) $id);
    }

    private function save(?int $id): void
    {
        $action = (string) input('action', 'draft');
        if (!in_array($action, ['draft', 'schedule', 'now'], true)) {
            $action = 'draft';
        }
        $date = (string) input('date', '');
        $time = (string) input('time', '');
        $scheduledAt = $date !== '' ? to_utc($date . ' ' . ($time !== '' ? $time : '09:00')) : null;

        $in = [
            'content' => (string) input('content', ''),
            'accounts' => (array) input('accounts', []),
            'media' => (array) input('media', []),
            'tags' => (array) input('tags', []),
            'overrides' => (array) input('overrides', []),
            'types' => (array) input('types', []),
            'first_comment' => (string) input('first_comment', ''),
            'scheduled_at' => $scheduledAt,
        ];
        $result = PostService::save($id, $in, $action, Workspace::id(), (int) Auth::id(), Workspace::can('admin'));

        if ($result['errors']) {
            with_old($in + ['date' => $date, 'time' => $time]);
            foreach ($result['errors'] as $err) {
                flash('error', $err);
            }
            redirect($id ? "/posts/$id" : '/posts/new');
        }

        $post = $result['post'];
        if ($post['status'] === 'scheduled' && $action === 'now') {
            PostService::publishNowInBackground((int) $post['id']);
            flash('success', 'Publishing now. Refresh in a moment to see the result.');
        } elseif ($post['status'] === 'pending_approval') {
            flash('success', 'Sent for approval.');
        } elseif ($post['status'] === 'scheduled') {
            flash('success', 'Scheduled for ' . local_time($post['scheduled_at']) . '.');
        } else {
            flash('success', 'Draft saved.');
        }
        redirect('/posts/' . $post['id']);
    }

    private function review(int $id, string $action): never
    {
        if (!Workspace::can('admin')) {
            abort(403);
        }
        $post = PostService::find($id, Workspace::id());
        if (!$post || $post['status'] !== 'pending_approval') {
            flash('error', 'This post isn’t waiting for approval.');
            redirect("/posts/$id");
        }
        if ($action === 'approve') {
            PostService::approve($post, (int) Auth::id());
            flash('success', 'Approved.');
        } else {
            PostService::reject($post, (int) Auth::id(), (string) input('note', ''));
            flash('success', 'Sent back to draft with your note.');
        }
        redirect("/posts/$id");
    }

    public function delete(string $id): void
    {
        $post = PostService::find((int) $id, Workspace::id());
        if (!$post) {
            abort(404);
        }
        if ($post['status'] === 'publishing') {
            flash('error', 'This post is publishing right now. Try again in a minute.');
            redirect("/posts/$id");
        }
        PostService::delete($post);
        flash('success', 'Post deleted. Anything already published stays on the platforms.');
        redirect('/posts');
    }

    public function duplicate(string $id): void
    {
        $post = PostService::find((int) $id, Workspace::id());
        if (!$post) {
            abort(404);
        }
        $newId = Db::transaction(function () use ($post) {
            $newId = Db::insert('posts', [
                'workspace_id' => $post['workspace_id'],
                'user_id' => Auth::id(),
                'content' => $post['content'],
                'media' => $post['media'],
                'first_comment' => $post['first_comment'],
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Db::query("INSERT INTO post_accounts (post_id, account_id, content, options, status) SELECT ?, account_id, content, options, 'pending' FROM post_accounts WHERE post_id = ?", [$newId, $post['id']]);
            Db::query('INSERT INTO post_tag (post_id, tag_id) SELECT ?, tag_id FROM post_tag WHERE post_id = ?', [$newId, $post['id']]);
            return $newId;
        });
        flash('success', 'Copied to a new draft.');
        redirect("/posts/$newId");
    }

    /** Retries the accounts that failed. Already-published accounts are skipped. */
    public function retry(string $id): void
    {
        $post = PostService::find((int) $id, Workspace::id());
        if (!$post || $post['status'] !== 'failed') {
            abort(404);
        }
        Db::query("UPDATE post_accounts SET status = 'pending', error = NULL WHERE post_id = ? AND status = 'failed'", [$post['id']]);
        Db::update('posts', ['status' => 'scheduled', 'scheduled_at' => now(), 'updated_at' => now()], 'id = ?', [$post['id']]);
        PostService::publishNowInBackground((int) $post['id']);
        flash('success', 'Retrying now. Refresh in a moment.');
        redirect("/posts/$id");
    }

    public static function accounts(): array
    {
        $rows = Db::all('SELECT id, provider, name, username, avatar, authorized FROM accounts WHERE workspace_id = ? ORDER BY provider, name', [Workspace::id()]);
        return array_values(array_filter($rows, fn($a) => Registry::class($a['provider']) !== null));
    }

    /** Adds target accounts and tags to post rows for list views. */
    public static function decorate(array $posts): array
    {
        if (!$posts) {
            return [];
        }
        $ids = implode(',', array_map('intval', array_column($posts, 'id')));
        $targets = Db::all("SELECT pa.post_id, pa.status, pa.url, pa.error, a.id, a.provider, a.name, a.username, a.avatar FROM post_accounts pa JOIN accounts a ON a.id = pa.account_id WHERE pa.post_id IN ($ids)");
        $tags = Db::all("SELECT pt.post_id, t.name, t.color FROM post_tag pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id IN ($ids)");
        $media = [];
        foreach ($posts as $p) {
            $first = json_arr($p['media'])[0] ?? null;
            if ($first) {
                $media[$p['id']] = (int) $first;
            }
        }
        $thumbs = $media ? array_column(Db::all('SELECT id, path, mime FROM media WHERE id IN (' . implode(',', $media) . ')'), null, 'id') : [];
        foreach ($posts as &$p) {
            $p['targets'] = array_values(array_filter($targets, fn($t) => (int) $t['post_id'] === (int) $p['id']));
            $p['tags'] = array_values(array_filter($tags, fn($t) => (int) $t['post_id'] === (int) $p['id']));
            $p['thumb'] = isset($media[$p['id']]) ? ($thumbs[$media[$p['id']]] ?? null) : null;
        }
        return $posts;
    }
}

}

namespace App\Controllers {

// ---- Controllers/ProfileController.php ----
use App\Auth;
use App\Db;

final class ProfileController
{
    public function edit(): void
    {
        view('profile/edit', [
            'title' => 'Your profile',
            'user' => Auth::user(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(): void
    {
        $name = (string) input('name', '');
        $email = (string) input('email', '');
        $tz = (string) input('timezone', 'UTC');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter your name and a valid email.');
            redirect('/profile');
        }
        if (Db::value('SELECT 1 FROM users WHERE email = ? AND id != ?', [$email, Auth::id()])) {
            flash('error', 'Another account uses that email.');
            redirect('/profile');
        }
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $tz = 'UTC';
        }
        Db::update('users', ['name' => $name, 'email' => $email, 'timezone' => $tz], 'id = ?', [Auth::id()]);
        flash('success', 'Profile saved.');
        redirect('/profile');
    }

    public function password(): void
    {
        $user = Auth::user();
        if (!password_verify((string) input('current_password', ''), $user['password'])) {
            flash('error', 'Your current password is wrong.');
            redirect('/profile');
        }
        $new = (string) input('password', '');
        if (strlen($new) < 8 || $new !== input('password_confirmation')) {
            flash('error', 'Use at least 8 characters and make sure both entries match.');
            redirect('/profile');
        }
        Db::update('users', ['password' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        session_regenerate_id(true);
        flash('success', 'Password changed.');
        redirect('/profile');
    }
}

}

namespace App\Controllers {

// ---- Controllers/PushController.php ----
use App\Auth;
use App\Db;
use App\Notify;
use App\WebPush;

final class PushController
{
    public function key(): void
    {
        json_response(['key' => WebPush::vapid()['public']]);
    }

    public function subscribe(): void
    {
        $endpoint = (string) input('endpoint', '');
        $keys = (array) input('keys', []);
        if (!preg_match('#^https://#', $endpoint) || empty($keys['p256dh']) || empty($keys['auth'])) {
            json_response(['error' => 'Invalid subscription.'], 422);
        }
        Db::query(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(endpoint) DO UPDATE SET user_id = excluded.user_id, p256dh = excluded.p256dh, auth = excluded.auth',
            [Auth::id(), $endpoint, (string) $keys['p256dh'], (string) $keys['auth'], mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), now()]
        );
        json_response(['ok' => true]);
    }

    public function unsubscribe(): void
    {
        Db::query('DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?', [(string) input('endpoint', ''), Auth::id()]);
        json_response(['ok' => true]);
    }

    public function test(): void
    {
        $subs = Db::all('SELECT * FROM push_subscriptions WHERE user_id = ?', [Auth::id()]);
        $ok = 0;
        $codes = [];
        foreach ($subs as $s) {
            try {
                $code = WebPush::send($s, ['title' => 'MixCreate', 'body' => 'Notifications work on this device.', 'url' => abs_url(''), 'tag' => 'test']);
                $codes[] = $code;
                $ok += $code >= 200 && $code < 300 ? 1 : 0;
            } catch (\Throwable $e) {
                $codes[] = $e->getMessage();
            }
        }
        flash($ok ? 'success' : 'error', $subs ? "Sent to $ok of " . count($subs) . ' device(s).' . ($ok < count($subs) ? ' Responses: ' . implode(', ', $codes) : '') : 'No devices are subscribed yet. Turn on notifications first.');
        redirect('/profile#notifications');
    }

    public function preferences(): void
    {
        $prefs = [];
        foreach (array_keys(Notify::EVENTS) as $k) {
            $prefs[$k] = input($k) ? 1 : 0;
        }
        Db::update('users', ['notify' => json_encode($prefs)], 'id = ?', [Auth::id()]);
        flash('success', 'Notification settings saved.');
        redirect('/profile#notifications');
    }
}

}

namespace App\Controllers {

// ---- Controllers/ReportsController.php ----
use App\Auth;
use App\Db;
use App\Report;
use App\Reports;
use App\Settings;
use App\Workspace;

final class ReportsController
{
    private function params(): array
    {
        [$from, $to, $days] = AnalyticsController::range();
        return array_filter(['account' => (int) input('account', 0), 'days' => $days, 'from' => $days ? null : $from, 'to' => $days ? null : $to]);
    }

    public function index(): void
    {
        $params = $this->params();
        $data = Reports::data(Workspace::id(), $params, user_tz());
        [$accounts, , $accountId] = AnalyticsController::selectedAccounts();
        $newShare = $_SESSION['_new_share'] ?? null;
        unset($_SESSION['_new_share']);
        view('reports/index', [
            'title' => 'Reports',
            'a' => $data['a'],
            'scope' => $data['scope'],
            'accounts' => $accounts,
            'accountId' => $accountId,
            'days' => $params['days'] ?? null,
            'params' => $params,
            'schedules' => Db::all('SELECT * FROM report_schedules WHERE workspace_id = ? ORDER BY id', [Workspace::id()]),
            'shares' => Db::all('SELECT * FROM report_shares WHERE workspace_id = ? ORDER BY id DESC', [Workspace::id()]),
            'newShare' => $newShare,
            'mailReady' => Settings::value('smtp', 'from_email') !== null,
        ]);
    }

    public function pdf(): void
    {
        $data = Reports::data(Workspace::id(), $this->params(), user_tz());
        self::sendPdf($data, Workspace::current()['name'], user_tz(), Workspace::id());
    }

    private static function sendPdf(array $data, string $workspace, string $tz, int $workspaceId): never
    {
        $pdf = Report::pdf($data['a'], $workspace, $data['scope'], $tz, \App\Branding::for($workspaceId));
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . Reports::filename($workspace, $data['a']['from'], $data['a']['to']) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    public function pptx(): void
    {
        $data = Reports::data(Workspace::id(), $this->params(), user_tz());
        self::sendPptx($data, user_tz(), Workspace::id());
    }

    private static function sendPptx(array $data, string $tz, int $workspaceId): never
    {
        $brand = \App\Branding::for($workspaceId);
        $bytes = \App\ReportPptx::build($data['a'], $brand, $data['scope'], $tz);
        header('Content-Type: application/vnd.openxmlformats-officedocument.presentationml.presentation');
        header('Content-Disposition: attachment; filename="' . preg_replace('/\.pdf$/', '.pptx', Reports::filename($brand['name'], $data['a']['from'], $data['a']['to'])) . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    public function share(): void
    {
        $token = bin2hex(random_bytes(20));
        $expires = (int) input('expires', 0);
        Db::insert('report_shares', [
            'workspace_id' => Workspace::id(),
            'token' => hash('sha256', $token),
            'params' => json_encode($this->params()),
            'created_by' => Auth::id(),
            'created_at' => now(),
            'expires_at' => $expires > 0 ? gmdate('Y-m-d H:i:s', time() + $expires * 86400) : null,
        ]);
        $_SESSION['_new_share'] = abs_url('r/' . $token);
        redirect('/reports', $this->params());
    }

    public function revokeShare(string $id): void
    {
        Db::query('DELETE FROM report_shares WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Share link revoked.');
        redirect('/reports');
    }

    /** Public, read-only report: /r/{token} and /r/{token}.pdf */
    public function public(string $token): void
    {
        $pdf = str_ends_with($token, '.pdf');
        $pptx = str_ends_with($token, '.pptx');
        $token = preg_replace('/\.(pdf|pptx)$/', '', $token);
        $share = Db::one('SELECT s.*, w.name AS workspace_name, u.timezone FROM report_shares s JOIN workspaces w ON w.id = s.workspace_id LEFT JOIN users u ON u.id = s.created_by WHERE s.token = ?', [hash('sha256', $token)]);
        if (!$share || ($share['expires_at'] && strtotime($share['expires_at'] . ' UTC') < time())) {
            abort(404, 'This report link is invalid or has expired.');
        }
        $tz = $share['timezone'] ?: 'UTC';
        $data = Reports::data((int) $share['workspace_id'], json_arr($share['params']), $tz);
        if ($pptx) {
            self::sendPptx($data, $tz, (int) $share['workspace_id']);
        }
        if ($pdf) {
            self::sendPdf($data, $share['workspace_name'], $tz, (int) $share['workspace_id']);
        }
        header('X-Robots-Tag: noindex');
        view('reports/public', [
            'title' => $share['workspace_name'] . ' report',
            'a' => $data['a'],
            'scope' => $data['scope'],
            'workspace' => $share['workspace_name'],
            'pdfUrl' => url('r/' . $token . '.pdf'),
            'pptxUrl' => url('r/' . $token . '.pptx'),
            'brand' => \App\Branding::for((int) $share['workspace_id']),
            'tz' => $tz,
        ], null);
    }

    public function saveSchedule(): void
    {
        $recipients = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) input('recipients', ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        $name = mb_substr((string) input('name', ''), 0, 80) ?: 'Social media report';
        if (!$recipients) {
            flash('error', 'Add at least one valid email address.');
            redirect('/reports');
        }
        $account = (int) input('account', 0);
        Db::insert('report_schedules', [
            'workspace_id' => Workspace::id(),
            'name' => $name,
            'recipients' => implode(',', $recipients),
            'frequency' => input('frequency') === 'monthly' ? 'monthly' : 'weekly',
            'accounts' => json_encode($account ? [$account] : []),
            'created_by' => Auth::id(),
            'created_at' => now(),
        ]);
        flash('success', 'Scheduled. It goes out ' . (input('frequency') === 'monthly' ? 'on the 1st of each month' : 'every Monday') . ' at 8 AM your time.');
        redirect('/reports');
    }

    public function deleteSchedule(string $id): void
    {
        Db::query('DELETE FROM report_schedules WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Schedule deleted.');
        redirect('/reports');
    }

    public function sendNow(string $id): void
    {
        $s = Db::one('SELECT * FROM report_schedules WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$s) {
            abort(404);
        }
        $tz = user_tz();
        $today = new \DateTimeImmutable('today', new \DateTimeZone($tz));
        [$from, $to] = $s['frequency'] === 'monthly'
            ? [$today->modify('first day of last month')->format('Y-m-d'), $today->modify('last day of last month')->format('Y-m-d')]
            : [$today->modify('-7 days')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d')];
        try {
            Reports::send($s, $from, $to, $tz);
            flash('success', 'Sent to ' . str_replace(',', ', ', $s['recipients']) . '.');
        } catch (\Throwable $e) {
            flash('error', 'Couldn’t send: ' . $e->getMessage());
        }
        redirect('/reports');
    }
}

}

namespace App\Controllers {

// ---- Controllers/ServicesController.php ----
use App\Crypt;
use App\Db;
use App\Providers\Registry;

/** Instance-wide API keys for each platform. Instance admins only. */
final class ServicesController
{
    public function index(): void
    {
        $services = [];
        foreach (Registry::all() as $key => $class) {
            $creds = Registry::credentials($key);
            $fields = [];
            foreach ($class::credentialFields() as $f => $label) {
                // Secrets are never sent back to the browser; show only whether they're set.
                $isSecret = str_contains($f, 'secret');
                $fields[$f] = [
                    'label' => $label,
                    'secret' => $isSecret,
                    'value' => $isSecret ? '' : ($creds[$f] ?? ''),
                    'set' => !empty($creds[$f]),
                ];
            }
            $services[$key] = [
                'name' => $class::name(),
                'color' => $class::color(),
                'implemented' => $class::implemented(),
                'ready' => Registry::ready($key),
                'hint' => $class::setupHint(),
                'fields' => $fields,
                'callback' => abs_url('accounts/callback/' . $key),
                'oauth' => $class::usesOAuth(),
            ];
        }
        view('admin/services', ['title' => 'Platform API keys', 'services' => $services]);
    }

    public function update(string $provider): void
    {
        $class = Registry::class($provider);
        if (!$class) {
            abort(404);
        }
        $creds = Registry::credentials($provider);
        foreach (array_keys($class::credentialFields()) as $f) {
            $v = (string) input($f, '');
            // Leaving a secret blank keeps the stored one.
            if ($v !== '' || !str_contains($f, 'secret')) {
                $creds[$f] = $v;
            }
        }
        Db::query(
            'INSERT INTO services (provider, credentials, active, updated_at) VALUES (?, ?, 1, ?)
             ON CONFLICT(provider) DO UPDATE SET credentials = excluded.credentials, updated_at = excluded.updated_at',
            [$provider, Crypt::encrypt($creds), now()]
        );
        flash('success', $class::name() . ' keys saved.');
        redirect('/admin/services#' . $provider);
    }
}

}

namespace App\Controllers {

// ---- Controllers/TagsController.php ----
use App\Db;
use App\Workspace;

final class TagsController
{
    public function index(): void
    {
        $tags = Db::all(
            'SELECT t.*, (SELECT COUNT(*) FROM post_tag WHERE tag_id = t.id) AS posts FROM tags t WHERE workspace_id = ? ORDER BY name',
            [Workspace::id()]
        );
        if (wants_json()) {
            json_response(['tags' => $tags]);
        }
        redirect('/library#tags');
    }

    public function store(): void
    {
        $name = mb_substr((string) input('name', ''), 0, 40);
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string) input('color', '')) ? input('color') : '#64748b';
        if ($name === '') {
            if (wants_json()) {
                json_response(['error' => 'Name required.'], 422);
            }
            flash('error', 'Give the tag a name.');
            redirect('/library#tags');
        }
        Db::query('INSERT OR IGNORE INTO tags (workspace_id, name, color) VALUES (?, ?, ?)', [Workspace::id(), $name, $color]);
        $tag = Db::one('SELECT * FROM tags WHERE workspace_id = ? AND name = ?', [Workspace::id(), $name]);
        if (wants_json()) {
            json_response(['tag' => $tag]);
        }
        flash('success', 'Tag added.');
        redirect('/library#tags');
    }

    public function delete(string $id): void
    {
        Db::query('DELETE FROM tags WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Tag deleted.');
        redirect('/library#tags');
    }
}

}

namespace App\Controllers {

// ---- Controllers/UsersController.php ----
use App\Auth;
use App\Db;

/** Instance admins: everyone with an account on this MixCreate install. */
final class UsersController
{
    public function index(): void
    {
        $users = Db::all(
            "SELECT u.*, (SELECT GROUP_CONCAT(w.name, ', ') FROM workspace_user wu JOIN workspaces w ON w.id = wu.workspace_id WHERE wu.user_id = u.id) AS workspaces
             FROM users u ORDER BY u.name"
        );
        $workspaces = Db::all(
            'SELECT w.*, (SELECT COUNT(*) FROM workspace_user WHERE workspace_id = w.id) AS members,
                    (SELECT COUNT(*) FROM accounts WHERE workspace_id = w.id) AS accounts
             FROM workspaces w ORDER BY w.name'
        );
        view('admin/users', ['title' => 'Users', 'users' => $users, 'workspaces' => $workspaces]);
    }

    public function toggleAdmin(string $id): void
    {
        $id = (int) $id;
        if ($id === Auth::id()) {
            flash('error', 'You can’t change your own admin status.');
            redirect('/admin/users');
        }
        Db::query('UPDATE users SET is_admin = 1 - is_admin WHERE id = ?', [$id]);
        flash('success', 'Updated.');
        redirect('/admin/users');
    }

    public function delete(string $id): void
    {
        $id = (int) $id;
        if ($id === Auth::id()) {
            flash('error', 'You can’t delete yourself.');
            redirect('/admin/users');
        }
        Db::query('DELETE FROM users WHERE id = ?', [$id]);
        flash('success', 'User deleted. Their posts stay in the workspace.');
        redirect('/admin/users');
    }
}

}

namespace App\Controllers {

// ---- Controllers/WebsitesController.php ----
use App\Db;
use App\WebAnalytics;
use App\Workspace;

final class WebsitesController
{
    public function index(): void
    {
        $sites = Db::all('SELECT * FROM sites WHERE workspace_id = ? ORDER BY name', [Workspace::id()]);
        $siteId = (int) input('site', 0) ?: (int) ($sites[0]['id'] ?? 0);
        $site = null;
        foreach ($sites as $s) {
            if ((int) $s['id'] === $siteId) {
                $site = $s;
            }
        }
        [$from, $to, $days] = AnalyticsController::range();
        view('websites/index', [
            'title' => 'Website',
            'sites' => $sites,
            'site' => $site,
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'r' => $site ? WebAnalytics::report((int) $site['id'], $from, $to, user_tz()) : null,
            'snippet' => $site ? '<script defer src="' . entry_url(['asset' => 't.js'], true) . '" data-site="' . $site['site_key'] . '" data-endpoint="' . abs_url('collect') . '"></script>' : '',
        ]);
    }

    public function store(): void
    {
        $domain = strtolower(trim((string) input('domain', '')));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#^www\.#', '', explode('/', $domain)[0]);
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$|^localhost(:\d+)?$/', $domain)) {
            flash('error', 'Enter a domain like example.com.');
            redirect('/websites');
        }
        $id = Db::insert('sites', [
            'workspace_id' => Workspace::id(),
            'name' => mb_substr((string) input('name', '') ?: $domain, 0, 80),
            'domain' => $domain,
            'site_key' => bin2hex(random_bytes(10)),
            'created_at' => now(),
        ]);
        flash('success', 'Site added. Paste the snippet into your site’s <head>.');
        redirect('/websites', ['site' => $id]);
    }

    public function delete(string $id): void
    {
        Db::query('DELETE FROM sites WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Site removed with its stats.');
        redirect('/websites');
    }

    /** Public beacon endpoint for t.js. Always answers 204 so the browser never waits. */
    public function collect(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $raw = (string) file_get_contents('php://input', false, null, 0, 8192);
            $data = json_decode($raw, true);
            if (is_array($data)) {
                try {
                    WebAnalytics::collect($data, $_SERVER);
                } catch (\Throwable $e) {
                    error_log('collect: ' . $e->getMessage());
                }
            }
        }
        http_response_code(204);
        exit;
    }
}

}

namespace App\Controllers {

// ---- Controllers/WorkspacesController.php ----
use App\Auth;
use App\Db;
use App\Hooks;
use App\Workspace;

final class WorkspacesController
{
    public const COLORS = ['#4f46e5', '#0891b2', '#059669', '#ca8a04', '#ea580c', '#dc2626', '#db2777', '#7c3aed', '#475569'];

    public function create(): void
    {
        // Only instance admins create workspaces; others land here only if they belong to none.
        if (!Auth::isAdmin()) {
            if (Workspace::current()) {
                redirect('/');
            }
            view('workspaces/none', ['title' => 'No workspace'], 'auth');
        }
        view('workspaces/create', ['title' => 'New workspace', 'colors' => self::COLORS]);
    }

    public function store(): void
    {
        if (!Auth::isAdmin()) {
            abort(403);
        }
        $name = (string) input('name', '');
        if ($name === '') {
            flash('error', 'Give the workspace a name.');
            redirect('/workspaces/new');
        }
        $color = in_array(input('color'), self::COLORS, true) ? input('color') : self::COLORS[0];
        $id = Workspace::create($name, (int) Auth::id(), $color);
        Workspace::switchTo($id);
        flash('success', "Workspace “{$name}” created.");
        redirect('/accounts');
    }

    public function switch(string $id): void
    {
        if (!Workspace::switchTo((int) $id)) {
            abort(403);
        }
        redirect('/');
    }

    public function edit(): void
    {
        view('workspaces/edit', [
            'title' => 'Workspace settings',
            'workspace' => Workspace::current(),
            'colors' => self::COLORS,
            'tabs' => Hooks::filter('settings.tabs', []),
            'branding' => \App\Branding::for(Workspace::id()),
            'aiWs' => \App\Settings::get('ai.ws.' . Workspace::id()) + ['api_key' => null],
            'models' => \App\Ai::openRouterModels(),
        ]);
    }

    public function update(): void
    {
        $name = (string) input('name', '');
        if ($name === '') {
            flash('error', 'The name can’t be empty.');
            redirect('/settings');
        }
        $color = in_array(input('color'), self::COLORS, true) ? input('color') : Workspace::current()['color'];
        Db::update('workspaces', [
            'name' => $name,
            'color' => $color,
            'require_approval' => input('require_approval') ? 1 : 0,
        ], 'id = ?', [Workspace::id()]);
        flash('success', 'Saved.');
        redirect('/settings');
    }

    public function branding(): void
    {
        $logo = (int) input('logo_media_id', 0);
        if ($logo && !Db::value("SELECT 1 FROM media WHERE id = ? AND workspace_id = ? AND mime LIKE 'image/%'", [$logo, Workspace::id()])) {
            $logo = 0;
        }
        Db::update('workspaces', ['branding' => json_encode([
            'name' => mb_substr((string) input('brand_name', ''), 0, 80),
            'color' => preg_match('/^#[0-9a-f]{6}$/i', (string) input('brand_color', '')) ? input('brand_color') : null,
            'logo_media_id' => $logo ?: null,
            'footer' => mb_substr((string) input('brand_footer', ''), 0, 120),
            'hide_powered_by' => (bool) input('hide_powered_by'),
        ])], 'id = ?', [Workspace::id()]);
        flash('success', 'Branding saved.');
        redirect('/settings');
    }

    public function ai(): void
    {
        $group = 'ai.ws.' . Workspace::id();
        $cur = \App\Settings::get($group);
        $key = trim((string) input('api_key', ''));
        if (input('remove_key')) {
            $cur['api_key'] = null;
        } elseif ($key !== '') {
            $cur['api_key'] = $key;
        }
        $cur['model'] = mb_substr(trim((string) input('model', '')), 0, 120);
        $cur['brand_voice'] = mb_substr(trim((string) input('brand_voice', '')), 0, 500);
        \App\Settings::save($group, $cur);
        flash('success', 'AI settings saved.');
        redirect('/settings#ai');
    }

    public function delete(): void
    {
        $ws = Workspace::current();
        if (input('confirm') !== $ws['name']) {
            flash('error', 'Type the workspace name exactly to delete it.');
            redirect('/settings');
        }
        $files = Db::all('SELECT id, path FROM media WHERE workspace_id = ?', [$ws['id']]);
        Db::query('DELETE FROM workspaces WHERE id = ?', [$ws['id']]);
        foreach ($files as $f) {
            \App\Blob::deleteMedia($f);
        }
        Db::query('UPDATE users SET current_workspace_id = NULL WHERE current_workspace_id = ?', [$ws['id']]);
        flash('success', "Deleted “{$ws['name']}”.");
        redirect('/');
    }
}

}

namespace App {

// ---- Cron.php ----
/** One scheduler tick: publish due posts, deliver webhooks, import analytics. */
final class Cron
{
    public static function tick(): array
    {
        // A lock row in the database stops overlapping runs; a crashed run's lock expires after 30 minutes.
        $now = time();
        $got = Db::query(
            "INSERT INTO kv (k, v, updated_at) VALUES ('cron.lock', ?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v, updated_at = excluded.updated_at WHERE kv.updated_at < ?",
            [json_encode(getmypid()), $now, $now - 1800]
        )->rowCount();
        if (!$got) {
            return ['skipped' => 'Another run is in progress.'];
        }
        kv_set('cron.ran', true);
        set_time_limit(0);

        // Each job is isolated so one failing integration can't stop publishing or the others.
        $report = [];
        $jobs = [
            'autolists' => fn() => Autolists::runDue(),
            'posts' => fn() => Publisher::runDue(),
            'webhooks' => fn() => Webhooks::deliverPending(),
            'metrics' => fn() => Metrics::import(),
            'inbox' => fn() => Inbox::fetchDue(),
            'competitors' => fn() => Competitors::refreshDue(),
            'ads' => fn() => Ads::importDue(),
            'reports' => fn() => Reports::sendDue(),
            'hashtags' => fn() => Hashtags::checkDue(),
        ];
        foreach ($jobs as $name => $job) {
            try {
                $report[$name] = $job();
            } catch (\Throwable $e) {
                $report[$name] = ['error' => $e->getMessage()];
                log_event('error', "Cron job $name failed: " . $e->getMessage());
            }
        }
        Hooks::fire('cron', $report);

        // Housekeeping once a day.
        if (kv_due('cron.daily', 86400)) {
            Webhooks::prune();
            WebAnalytics::prune();
            Db::query('DELETE FROM logs WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
            Db::query('DELETE FROM invitations WHERE accepted_at IS NULL AND created_at < ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
        }

        kv_delete('cron.lock');
        return $report;
    }
}

}

namespace App {

// ---- Crypt.php ----
/** AES-256-GCM encryption for API credentials and access tokens at rest. */
final class Crypt
{
    private static function key(): string
    {
        return hash('sha256', (string) Config::get('app_key'), true);
    }

    public static function encrypt(array|string $value): string
    {
        $plain = is_array($value) ? json_encode($value, JSON_UNESCAPED_SLASHES) : $value;
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $payload): ?string
    {
        if ($payload === null || $payload === '') {
            return null;
        }
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? null : $plain;
    }

    public static function decryptArray(?string $payload): array
    {
        $plain = self::decrypt($payload);
        return $plain === null ? [] : (json_decode($plain, true) ?: []);
    }
}

}

namespace App {

// ---- Db.php ----
use PDO;

final class Db
{
    private static ?PDO $pdo = null;

    public static function pdo(): PDO
    {
        if (self::$pdo === null) {
            $path = Config::get('db_path');
            $new = !is_file($path);
            self::$pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            self::$pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdo->exec('PRAGMA journal_mode = WAL');
            self::$pdo->exec('PRAGMA busy_timeout = 5000');
            self::migrate();
            if ($new) {
                // Owner-only, so the web server can't hand the file out even if someone guessed its name.
                @chmod($path, 0600);
            }
        }
        return self::$pdo;
    }

    /** Applies migrations in order, once each. */
    private static function migrate(): void
    {
        $pdo = self::$pdo;
        $pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name TEXT PRIMARY KEY, ran_at TEXT NOT NULL)');
        $done = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        foreach (Migrations::all() as $name => $sql) {
            if (in_array($name, $done, true)) {
                continue;
            }
            $pdo->beginTransaction();
            try {
                $pdo->exec($sql);
                $stmt = $pdo->prepare('INSERT INTO migrations (name, ran_at) VALUES (?, ?)');
                $stmt->execute([$name, now()]);
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        }
    }

    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function all(string $sql, array $params = []): array
    {
        return self::query($sql, $params)->fetchAll();
    }

    public static function one(string $sql, array $params = []): ?array
    {
        $row = self::query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public static function value(string $sql, array $params = []): mixed
    {
        $v = self::query($sql, $params)->fetchColumn();
        return $v === false ? null : $v;
    }

    public static function insert(string $table, array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode(', ', $cols),
            implode(', ', array_fill(0, count($cols), '?'))
        );
        self::query($sql, array_values($data));
        return (int) self::pdo()->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $params = []): int
    {
        $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($data)));
        return self::query("UPDATE $table SET $set WHERE $where", [...array_values($data), ...$params])->rowCount();
    }

    public static function transaction(callable $fn): mixed
    {
        $pdo = self::pdo();
        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}

}

namespace App {

// ---- Drive.php ----
/** Google Drive: each user links their own Drive and imports images and videos into the media library. */
final class Drive
{
    private const API = 'https://www.googleapis.com/drive/v3';

    public static function enabled(): bool
    {
        return Settings::has('gdrive', ['client_id', 'client_secret']);
    }

    public static function redirectUri(): string
    {
        return abs_url('drive/callback');
    }

    public static function authUrl(string $state): string
    {
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
            'client_id' => Settings::value('gdrive', 'client_id'),
            'redirect_uri' => self::redirectUri(),
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);
    }

    private static function token(array $form): array
    {
        $s = Settings::get('gdrive');
        $r = Http::post('https://oauth2.googleapis.com/token', ['form' => $form + ['client_id' => $s['client_id'], 'client_secret' => $s['client_secret']]]);
        if (empty($r['json']['access_token'])) {
            throw new \RuntimeException('Google: ' . ($r['json']['error_description'] ?? $r['json']['error'] ?? 'HTTP ' . $r['status']));
        }
        return ['access_token' => $r['json']['access_token'], 'refresh_token' => $r['json']['refresh_token'] ?? null, 'expires_at' => time() + (int) ($r['json']['expires_in'] ?? 3600)];
    }

    public static function connect(int $userId, string $code): void
    {
        $t = self::token(['grant_type' => 'authorization_code', 'code' => $code, 'redirect_uri' => self::redirectUri()]);
        Db::query(
            "INSERT INTO user_connections (user_id, provider, token, created_at) VALUES (?, 'gdrive', ?, ?)
             ON CONFLICT(user_id, provider) DO UPDATE SET token = excluded.token, created_at = excluded.created_at",
            [$userId, Crypt::encrypt($t), now()]
        );
    }

    public static function connected(int $userId): bool
    {
        return (bool) Db::value("SELECT 1 FROM user_connections WHERE user_id = ? AND provider = 'gdrive'", [$userId]);
    }

    public static function disconnect(int $userId): void
    {
        Db::query("DELETE FROM user_connections WHERE user_id = ? AND provider = 'gdrive'", [$userId]);
    }

    private static function access(int $userId): string
    {
        $row = Db::one("SELECT token FROM user_connections WHERE user_id = ? AND provider = 'gdrive'", [$userId]);
        $t = Crypt::decryptArray($row['token'] ?? null);
        if (!$t) {
            throw new \RuntimeException('Connect Google Drive first.');
        }
        if (($t['expires_at'] ?? 0) < time() + 120) {
            if (empty($t['refresh_token'])) {
                self::disconnect($userId);
                throw new \RuntimeException('Your Google Drive connection expired. Connect again.');
            }
            $new = self::token(['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token']]);
            $new['refresh_token'] ??= $t['refresh_token'];
            $t = $new;
            Db::update('user_connections', ['token' => Crypt::encrypt($t)], "user_id = ? AND provider = 'gdrive'", [$userId]);
        }
        return $t['access_token'];
    }

    public static function files(int $userId, ?string $pageToken, string $query): array
    {
        $q = "(mimeType contains 'image/' or mimeType contains 'video/') and trashed = false";
        if ($query !== '') {
            $q .= " and name contains '" . str_replace(["\\", "'"], ["\\\\", "\\'"], $query) . "'";
        }
        $r = Http::get(self::API . '/files', [
            'query' => array_filter([
                'q' => $q, 'orderBy' => 'modifiedTime desc', 'pageSize' => 40, 'pageToken' => $pageToken,
                'fields' => 'nextPageToken,files(id,name,mimeType,thumbnailLink,size)', 'supportsAllDrives' => 'true', 'includeItemsFromAllDrives' => 'true',
            ]),
            'headers' => ['Authorization' => 'Bearer ' . self::access($userId)],
        ]);
        if (!Http::ok($r)) {
            if ($r['status'] === 401) {
                self::disconnect($userId);
            }
            throw new \RuntimeException('Google Drive: ' . ($r['json']['error']['message'] ?? 'HTTP ' . $r['status']));
        }
        return [
            'items' => array_map(fn($f) => ['id' => $f['id'], 'title' => $f['name'], 'thumbnail' => $f['thumbnailLink'] ?? null, 'mime' => $f['mimeType']], $r['json']['files'] ?? []),
            'continuation' => $r['json']['nextPageToken'] ?? null,
        ];
    }

    public static function import(int $userId, int $workspaceId, string $fileId): array
    {
        $token = self::access($userId);
        $meta = Http::get(self::API . '/files/' . rawurlencode($fileId), ['query' => ['fields' => 'name,mimeType,size', 'supportsAllDrives' => 'true'], 'headers' => ['Authorization' => "Bearer $token"]]);
        if (!Http::ok($meta)) {
            throw new \RuntimeException('Google Drive: file not found.');
        }
        $res = Http::get(self::API . '/files/' . rawurlencode($fileId), ['query' => ['alt' => 'media', 'supportsAllDrives' => 'true'], 'headers' => ['Authorization' => "Bearer $token", 'Accept' => '*/*'], 'timeout' => 600]);
        if (!Http::ok($res)) {
            throw new \RuntimeException('Google Drive download failed (HTTP ' . $res['status'] . ').');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'gd');
        file_put_contents($tmp, $res['body']);
        $m = Controllers\MediaController::storeLocal($tmp, (string) $meta['json']['name'], $workspaceId, $userId);
        @unlink($tmp);
        if (isset($m['error'])) {
            throw new \RuntimeException($m['error']);
        }
        return [$m];
    }
}

}

namespace App {

// ---- Hashtags.php ----
use App\Providers\Registry;

/** Hashtag tracker: collects public posts using a hashtag on Bluesky, Mastodon, X and Instagram. */
final class Hashtags
{
    public const NETWORKS = [
        'bluesky' => 'Bluesky',
        'mastodon' => 'Mastodon',
        'x' => 'X',
        'instagram' => 'Instagram',
    ];

    public static function clean(string $tag): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}_]/u', '', ltrim(trim($tag), '#')));
    }

    /** Runs each active hashtag at most every 30 minutes. */
    public static function checkDue(): int
    {
        $rows = Db::all('SELECT * FROM hashtags WHERE active = 1 AND (last_checked_at IS NULL OR last_checked_at < ?) LIMIT 20', [gmdate('Y-m-d H:i:s', time() - 1800)]);
        $n = 0;
        foreach ($rows as $h) {
            $n += self::check($h);
        }
        return $n;
    }

    /** Fetches new posts for one hashtag. Returns how many were new. */
    public static function check(array $h): int
    {
        $new = 0;
        $errors = [];
        foreach (json_arr($h['networks']) as $net) {
            try {
                $posts = match ($net) {
                    'bluesky' => self::bluesky((int) $h['workspace_id'], $h['tag']),
                    'mastodon' => self::mastodon((int) $h['workspace_id'], $h['tag']),
                    'x' => self::x((int) $h['workspace_id'], $h['tag']),
                    'instagram' => self::instagram((int) $h['workspace_id'], $h['tag']),
                    default => [],
                };
                foreach ($posts as $p) {
                    $inserted = Db::query(
                        'INSERT INTO hashtag_posts (hashtag_id, network, external_id, author_handle, author_name, author_avatar, text, url, likes, reposts, replies, posted_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON CONFLICT(hashtag_id, network, external_id) DO UPDATE SET likes = excluded.likes, reposts = excluded.reposts, replies = excluded.replies',
                        [$h['id'], $net, $p['id'], mb_substr($p['handle'], 0, 200), mb_substr($p['name'], 0, 200), $p['avatar'], mb_substr($p['text'], 0, 3000), $p['url'],
                         $p['likes'], $p['reposts'], $p['replies'], $p['posted_at']]
                    );
                    $new += $inserted->rowCount() > 0 ? 1 : 0;
                }
            } catch (\Throwable $e) {
                $errors[] = self::NETWORKS[$net] . ': ' . $e->getMessage();
            }
        }
        Db::update('hashtags', ['last_checked_at' => now(), 'error' => $errors ? mb_substr(implode(' · ', $errors), 0, 500) : null], 'id = ?', [$h['id']]);
        return $new;
    }

    private static function ts(?string $iso): string
    {
        $t = $iso ? strtotime($iso) : false;
        return gmdate('Y-m-d H:i:s', $t ?: time());
    }

    private static function bluesky(int $wsId, string $tag): array
    {
        $query = ['q' => '#' . $tag, 'tag' => $tag, 'sort' => 'latest', 'limit' => 100];
        $acc = Db::one("SELECT * FROM accounts WHERE workspace_id = ? AND provider = 'bluesky' AND authorized = 1 LIMIT 1", [$wsId]);
        if ($acc) {
            // Search through the user's own PDS (it proxies to the AppView); public search may be rate limited.
            $p = Registry::forAccount($acc);
            $r = (new \ReflectionMethod($p, 'xrpc'))->invoke($p, 'GET', 'app.bsky.feed.searchPosts', ['query' => $query]);
        } else {
            $res = Http::get('https://public.api.bsky.app/xrpc/app.bsky.feed.searchPosts', ['query' => $query]);
            if (!Http::ok($res)) {
                throw new \RuntimeException('search needs a connected Bluesky account (HTTP ' . $res['status'] . ')');
            }
            $r = $res['json'];
        }
        $out = [];
        foreach ($r['posts'] ?? [] as $post) {
            $a = $post['author'] ?? [];
            $out[] = [
                'id' => $post['uri'], 'handle' => $a['handle'] ?? '', 'name' => $a['displayName'] ?? '', 'avatar' => $a['avatar'] ?? null,
                'text' => $post['record']['text'] ?? '', 'url' => 'https://bsky.app/profile/' . ($a['handle'] ?? '') . '/post/' . substr($post['uri'], strrpos($post['uri'], '/') + 1),
                'likes' => (int) ($post['likeCount'] ?? 0), 'reposts' => (int) ($post['repostCount'] ?? 0) + (int) ($post['quoteCount'] ?? 0), 'replies' => (int) ($post['replyCount'] ?? 0),
                'posted_at' => self::ts($post['record']['createdAt'] ?? $post['indexedAt'] ?? null),
            ];
        }
        return $out;
    }

    private static function mastodon(int $wsId, string $tag): array
    {
        $acc = Db::one("SELECT data FROM accounts WHERE workspace_id = ? AND provider = 'mastodon' LIMIT 1", [$wsId]);
        $server = json_arr($acc['data'] ?? null)['server'] ?? 'mastodon.social';
        $res = Http::get("https://$server/api/v1/timelines/tag/" . rawurlencode($tag), ['query' => ['limit' => 40]]);
        if (!Http::ok($res)) {
            throw new \RuntimeException("$server returned HTTP {$res['status']}");
        }
        $out = [];
        foreach ($res['json'] as $s) {
            $a = $s['account'] ?? [];
            $out[] = [
                'id' => $s['uri'] ?? $s['id'], 'handle' => $a['acct'] ?? '', 'name' => $a['display_name'] ?? '', 'avatar' => $a['avatar'] ?? null,
                'text' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br />', '</p><p>'], "\n", $s['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'url' => $s['url'] ?? null, 'likes' => (int) ($s['favourites_count'] ?? 0), 'reposts' => (int) ($s['reblogs_count'] ?? 0), 'replies' => (int) ($s['replies_count'] ?? 0),
                'posted_at' => self::ts($s['created_at'] ?? null),
            ];
        }
        return $out;
    }

    private static function x(int $wsId, string $tag): array
    {
        [, $token] = Competitors::borrow($wsId, 'x', 'connect an X account (and an API plan with search).');
        $res = Http::get('https://api.x.com/2/tweets/search/recent', [
            'query' => ['query' => '#' . $tag . ' -is:retweet', 'max_results' => 100, 'tweet.fields' => 'public_metrics,created_at,author_id', 'expansions' => 'author_id', 'user.fields' => 'username,name,profile_image_url'],
            'headers' => ['Authorization' => "Bearer $token"],
        ]);
        if (!Http::ok($res)) {
            throw new \RuntimeException($res['json']['detail'] ?? $res['json']['title'] ?? 'HTTP ' . $res['status']);
        }
        $users = array_column($res['json']['includes']['users'] ?? [], null, 'id');
        $out = [];
        foreach ($res['json']['data'] ?? [] as $t) {
            $u = $users[$t['author_id']] ?? [];
            $m = $t['public_metrics'] ?? [];
            $out[] = [
                'id' => $t['id'], 'handle' => $u['username'] ?? '', 'name' => $u['name'] ?? '', 'avatar' => $u['profile_image_url'] ?? null, 'text' => $t['text'],
                'url' => 'https://x.com/' . ($u['username'] ?? 'i') . '/status/' . $t['id'],
                'likes' => (int) ($m['like_count'] ?? 0), 'reposts' => (int) ($m['retweet_count'] ?? 0) + (int) ($m['quote_count'] ?? 0), 'replies' => (int) ($m['reply_count'] ?? 0),
                'posted_at' => self::ts($t['created_at'] ?? null),
            ];
        }
        return $out;
    }

    private static function instagram(int $wsId, string $tag): array
    {
        [$page, $token] = Competitors::borrow($wsId, 'facebook', 'connect a Facebook Page linked to an Instagram business account.');
        $graph = 'https://graph.facebook.com/' . Providers\Facebook::VERSION;
        $ig = Http::get("$graph/{$page['provider_id']}", ['query' => ['fields' => 'instagram_business_account', 'access_token' => $token]])['json']['instagram_business_account']['id'] ?? null;
        if (!$ig) {
            throw new \RuntimeException('the connected Facebook Page isn’t linked to an Instagram business account.');
        }
        $hid = Http::get("$graph/ig_hashtag_search", ['query' => ['user_id' => $ig, 'q' => $tag, 'access_token' => $token]])['json']['data'][0]['id'] ?? null;
        if (!$hid) {
            throw new \RuntimeException('hashtag not found (Instagram allows 30 different hashtags per week).');
        }
        $res = Http::get("$graph/$hid/recent_media", ['query' => ['user_id' => $ig, 'fields' => 'id,caption,like_count,comments_count,timestamp,permalink', 'limit' => 50, 'access_token' => $token]]);
        if (!Http::ok($res)) {
            throw new \RuntimeException($res['json']['error']['message'] ?? 'HTTP ' . $res['status']);
        }
        return array_map(fn($m) => [
            'id' => $m['id'], 'handle' => '', 'name' => '', 'avatar' => null, 'text' => $m['caption'] ?? '', 'url' => $m['permalink'] ?? null,
            'likes' => (int) ($m['like_count'] ?? 0), 'reposts' => 0, 'replies' => (int) ($m['comments_count'] ?? 0), 'posted_at' => self::ts($m['timestamp'] ?? null),
        ], $res['json']['data'] ?? []);
    }

    public static function report(int $hashtagId, string $fromUtc, string $toUtc, array $days, string $tz): array
    {
        $w = 'hashtag_id = ? AND posted_at BETWEEN ? AND ?';
        $p = [$hashtagId, $fromUtc, $toUtc];
        $tot = Db::one("SELECT COUNT(*) AS posts, COUNT(DISTINCT network || author_handle) AS authors, COALESCE(SUM(likes + reposts + replies), 0) AS engagement FROM hashtag_posts WHERE $w", $p);
        $byNet = Db::all("SELECT network, COUNT(*) AS posts, SUM(likes + reposts + replies) AS engagement FROM hashtag_posts WHERE $w GROUP BY network ORDER BY posts DESC", $p);
        $rows = Db::all("SELECT posted_at FROM hashtag_posts WHERE $w", $p);
        $per = array_fill_keys($days, 0);
        foreach ($rows as $r) {
            $d = local_time($r['posted_at'], 'Y-m-d', $tz);
            if (isset($per[$d])) {
                $per[$d]++;
            }
        }
        return [
            'posts' => (int) $tot['posts'],
            'authors' => (int) $tot['authors'],
            'engagement' => (int) $tot['engagement'],
            'networks' => $byNet,
            'series' => array_map(fn($d) => ['date' => $d, 'value' => $per[$d]], $days),
            'top' => Db::all("SELECT * FROM hashtag_posts WHERE $w ORDER BY (likes + reposts + replies) DESC LIMIT 10", $p),
            'contributors' => Db::all("SELECT network, author_handle, MAX(author_name) AS author_name, MAX(author_avatar) AS author_avatar, COUNT(*) AS posts, SUM(likes + reposts + replies) AS engagement
                FROM hashtag_posts WHERE $w AND author_handle != '' GROUP BY network, author_handle ORDER BY posts DESC, engagement DESC LIMIT 10", $p),
            'latest' => Db::all("SELECT * FROM hashtag_posts WHERE $w ORDER BY posted_at DESC LIMIT 20", $p),
        ];
    }
}

}

namespace App {

// ---- Hooks.php ----
/**
 * Extension points for modules in /modules/<name>/module.php.
 *
 * Actions (Hooks::on / Hooks::fire):
 *   routes(Router $router)                 register extra routes
 *   post.saved(array $post)                after a post is created or updated
 *   post.published(array $post, array $results)
 *   post.failed(array $post, array $results)
 *   cron(array &$report)                   runs every cron tick
 *
 * Filters (Hooks::addFilter / Hooks::filter):
 *   providers(array $classes)              provider class names
 *   nav(array $items)                      sidebar items [['label','href','icon','role','match']]
 *   post.status(string $status, array $post, string $action)   e.g. turn 'scheduled' into 'pending_approval'
 *   composer.panels(array $html)           extra HTML blocks below the composer
 *   settings.tabs(array $tabs)             extra workspace settings tabs [['label','href']]
 */
final class Hooks
{
    private static array $actions = [];
    private static array $filters = [];

    public static function on(string $event, callable $callback, int $priority = 10): void
    {
        self::$actions[$event][$priority][] = $callback;
    }

    public static function fire(string $event, mixed ...$args): void
    {
        foreach (self::sorted(self::$actions[$event] ?? []) as $cb) {
            $cb(...$args);
        }
    }

    public static function addFilter(string $name, callable $callback, int $priority = 10): void
    {
        self::$filters[$name][$priority][] = $callback;
    }

    public static function filter(string $name, mixed $value, mixed ...$args): mixed
    {
        foreach (self::sorted(self::$filters[$name] ?? []) as $cb) {
            $value = $cb($value, ...$args);
        }
        return $value;
    }

    private static function sorted(array $byPriority): array
    {
        ksort($byPriority);
        return $byPriority ? array_merge(...array_values($byPriority)) : [];
    }

    /** Loads every modules/<name>/module.php, alphabetically. */
    public static function loadModules(): void
    {
        $files = glob(BASE_PATH . '/modules/*/module.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            require_once $file;
        }
    }
}

}

namespace App {

// ---- Http.php ----
/** Minimal cURL wrapper for platform APIs. */
final class Http
{
    /**
     * @param array $options headers => [k => v], json => array, form => array, multipart => array, body => string, timeout => int
     * @return array{status:int, body:string, json:array, headers:array}
     */
    /** Test hook: when set, receives (method, url, options) and returns a response array instead of calling cURL. */
    public static ?\Closure $fake = null;

    public static function request(string $method, string $url, array $options = []): array
    {
        if (self::$fake !== null) {
            if (isset($options['query']) && $options['query']) {
                $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($options['query']);
            }
            $r = (self::$fake)(strtoupper($method), $url, $options);
            return $r + ['status' => 200, 'body' => json_encode($r['json'] ?? []), 'json' => [], 'headers' => []];
        }
        $ch = curl_init();
        $headers = [];
        foreach ($options['headers'] ?? [] as $k => $v) {
            $headers[] = "$k: $v";
        }

        if (isset($options['json'])) {
            $body = json_encode($options['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
        } elseif (isset($options['form'])) {
            $body = http_build_query($options['form']);
            $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        } elseif (isset($options['multipart'])) {
            $body = $options['multipart'];
        } else {
            $body = $options['body'] ?? null;
        }

        if (isset($options['query']) && $options['query']) {
            $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($options['query']);
        }

        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => $options['timeout'] ?? 60,
            CURLOPT_HTTPHEADER => array_merge(['Accept: application/json', 'User-Agent: MixCreate/1.0'], $headers),
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        } elseif (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            // Send an explicit empty body so servers get Content-Length: 0.
            curl_setopt($ch, CURLOPT_POSTFIELDS, '');
        }

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \RuntimeException("Request to " . parse_url($url, PHP_URL_HOST) . " failed: $err");
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        $json = json_decode($raw, true);
        return ['status' => $status, 'body' => $raw, 'json' => is_array($json) ? $json : [], 'headers' => $respHeaders];
    }

    public static function get(string $url, array $options = []): array
    {
        return self::request('GET', $url, $options);
    }

    public static function post(string $url, array $options = []): array
    {
        return self::request('POST', $url, $options);
    }

    public static function ok(array $response): bool
    {
        return $response['status'] >= 200 && $response['status'] < 300;
    }
}

}

namespace App {

// ---- Inbox.php ----
use App\Providers\ProviderException;
use App\Providers\Registry;

/** Pulls comments on recently published posts into the inbox, and sends replies. */
final class Inbox
{
    /** Checks posts from the last $days days, at most every $minutes minutes. */
    public static function fetchDue(int $minutes = 15, int $days = 14): int
    {
        if (!kv_due('inbox.checked', $minutes * 60)) {
            return 0;
        }
        $new = 0;
        $targets = Db::all(
            "SELECT pa.post_id, pa.provider_post_id, a.*, p.workspace_id AS ws
             FROM post_accounts pa JOIN accounts a ON a.id = pa.account_id JOIN posts p ON p.id = pa.post_id
             WHERE pa.status = 'published' AND pa.provider_post_id IS NOT NULL AND pa.provider_post_id != '' AND a.authorized = 1 AND pa.published_at >= ?
             ORDER BY a.id",
            [gmdate('Y-m-d H:i:s', time() - $days * 86400)]
        );
        $providers = [];
        $perWorkspace = [];
        foreach ($targets as $t) {
            $class = Registry::class($t['provider']);
            if (!$class || !$class::supportsComments()) {
                continue;
            }
            try {
                $providers[$t['id']] ??= Registry::withFreshToken($t);
                $added = self::store($t, $providers[$t['id']]->comments($t['provider_post_id']));
                $new += $added;
                $perWorkspace[$t['ws']] = ($perWorkspace[$t['ws']] ?? 0) + $added;
            } catch (\Throwable $e) {
                if ($e instanceof ProviderException && $e->unauthorized) {
                    Db::update('accounts', ['authorized' => 0, 'updated_at' => now()], 'id = ?', [$t['id']]);
                }
                log_event('warning', "Inbox fetch failed for account {$t['id']}: " . $e->getMessage());
            }
        }
        foreach ($perWorkspace as $ws => $n) {
            if ($n > 0) {
                Notify::users(Notify::workspaceMembers((int) $ws), 'comments', $n === 1 ? 'New comment' : "$n new comments", 'Open the inbox to reply.', '/inbox');
            }
        }
        return $new;
    }

    private static function store(array $t, array $comments): int
    {
        $n = 0;
        foreach ($comments as $c) {
            if (!empty($c['own'])) {
                continue;
            }
            $inserted = Db::query(
                'INSERT OR IGNORE INTO inbox_items (workspace_id, account_id, post_id, provider_item_id, root_ref, reply_ref, author_name, author_handle, author_avatar, text, url, remote_created_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$t['ws'], $t['id'], $t['post_id'], (string) $c['id'], $t['provider_post_id'], (string) ($c['reply_ref'] ?? ''), mb_substr((string) $c['author_name'], 0, 200),
                 mb_substr((string) $c['author_handle'], 0, 200), $c['author_avatar'] ?? null, mb_substr((string) $c['text'], 0, 5000), $c['url'] ?? null, $c['created_at'] ?? now(), now()]
            )->rowCount();
            $n += $inserted;
        }
        return $n;
    }

    public static function reply(array $item, string $text, int $userId): void
    {
        $account = Db::one('SELECT * FROM accounts WHERE id = ?', [$item['account_id']]);
        if (!$account) {
            throw new ProviderException('That account was removed.');
        }
        $provider = Registry::withFreshToken($account);
        $provider->reply($item, $text);
        Db::update('inbox_items', ['status' => 'replied', 'reply_text' => $text, 'replied_by' => $userId, 'replied_at' => now()], 'id = ?', [$item['id']]);
    }
}

}

namespace App {

// ---- Mailer.php ----
/**
 * Sends email through the SMTP server in Settings → Integrations, or PHP's mail() when none is set.
 * Supports SSL (port 465), STARTTLS (587) and plain connections, with AUTH LOGIN.
 */
final class Mailer
{
    /** @param array<array{name: string, mime: string, data: string}> $attachments */
    public static function send(array $to, string $subject, string $html, array $attachments = []): void
    {
        $s = Settings::get('smtp');
        $fromEmail = $s['from_email'] ?? '';
        if ($fromEmail === '') {
            throw new \RuntimeException('Set a sender address under Integrations → Email first.');
        }
        $fromName = $s['from_name'] ?? Config::get('app_name', 'MixCreate');
        $to = array_values(array_filter(array_map('trim', $to), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        if (!$to) {
            throw new \RuntimeException('No valid recipients.');
        }

        $boundary = 'mc' . bin2hex(random_bytes(8));
        $alt = 'alt' . bin2hex(random_bytes(8));
        $headers = [
            'From: ' . self::encodeHeader($fromName) . " <$fromEmail>",
            'To: ' . implode(', ', $to),
            'Subject: ' . self::encodeHeader($subject),
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . (explode('@', $fromEmail)[1] ?? 'mixcreate') . '>',
            'MIME-Version: 1.0',
            "Content-Type: multipart/mixed; boundary=\"$boundary\"",
        ];
        $text = trim(html_entity_decode(strip_tags(preg_replace('#<(br|/p|/h\d|/tr|/li)[^>]*>#i', "\n", $html)), ENT_QUOTES, 'UTF-8'));
        $body = "--$boundary\r\nContent-Type: multipart/alternative; boundary=\"$alt\"\r\n\r\n"
            . "--$alt\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($text)) . "\r\n"
            . "--$alt\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n" . chunk_split(base64_encode($html)) . "\r\n"
            . "--$alt--\r\n";
        foreach ($attachments as $att) {
            $body .= "--$boundary\r\nContent-Type: {$att['mime']}; name=\"{$att['name']}\"\r\nContent-Disposition: attachment; filename=\"{$att['name']}\"\r\nContent-Transfer-Encoding: base64\r\n\r\n"
                . chunk_split(base64_encode($att['data'])) . "\r\n";
        }
        $body .= "--$boundary--\r\n";

        if (empty($s['host'])) {
            $plainHeaders = array_filter($headers, fn($h) => !str_starts_with($h, 'To:') && !str_starts_with($h, 'Subject:'));
            if (!mail(implode(', ', $to), self::encodeHeader($subject), $body, implode("\r\n", $plainHeaders))) {
                throw new \RuntimeException('PHP mail() failed. Configure an SMTP server under Integrations → Email.');
            }
            return;
        }
        self::smtp($s, $fromEmail, $to, implode("\r\n", $headers) . "\r\n\r\n" . $body);
    }

    private static function smtp(array $s, string $from, array $to, string $message): void
    {
        $port = (int) ($s['port'] ?? 587);
        $enc = $s['encryption'] ?? ($port === 465 ? 'ssl' : 'tls');
        $host = ($enc === 'ssl' ? 'ssl://' : '') . $s['host'];
        $fp = @stream_socket_client("$host:$port", $errno, $errstr, 20);
        if (!$fp) {
            throw new \RuntimeException("Couldn’t connect to the mail server: $errstr");
        }
        stream_set_timeout($fp, 30);
        $read = function () use ($fp): string {
            $data = '';
            while (($line = fgets($fp, 515)) !== false) {
                $data .= $line;
                if (isset($line[3]) && $line[3] === ' ') {
                    break;
                }
            }
            return $data;
        };
        $cmd = function (string $c, array $expect) use ($fp, $read): string {
            if ($c !== '') {
                fwrite($fp, $c . "\r\n");
            }
            $resp = $read();
            if (!in_array((int) substr($resp, 0, 3), $expect, true)) {
                $shown = str_starts_with($c, 'AUTH') || strlen($c) > 60 ? strtok($c, ' ') : $c;
                throw new \RuntimeException('Mail server rejected ' . ($shown ?: 'the connection') . ': ' . trim($resp));
            }
            return $resp;
        };
        $ehloHost = parse_url(abs_url(''), PHP_URL_HOST) ?: 'localhost';
        $cmd('', [220]);
        $cmd("EHLO $ehloHost", [250]);
        if ($enc === 'tls') {
            $cmd('STARTTLS', [220]);
            if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                throw new \RuntimeException('STARTTLS failed.');
            }
            $cmd("EHLO $ehloHost", [250]);
        }
        if (!empty($s['username'])) {
            $cmd('AUTH LOGIN', [334]);
            $cmd(base64_encode($s['username']), [334]);
            $cmd(base64_encode((string) ($s['password'] ?? '')), [235]);
        }
        $cmd("MAIL FROM:<$from>", [250]);
        foreach ($to as $rcpt) {
            $cmd("RCPT TO:<$rcpt>", [250, 251]);
        }
        $cmd('DATA', [354]);
        // Dot-stuffing per RFC 5321.
        $message = preg_replace('/^\./m', '..', str_replace(["\r\n", "\n"], ["\n", "\r\n"], $message));
        $cmd($message . "\r\n.", [250]);
        fwrite($fp, "QUIT\r\n");
        fclose($fp);
    }

    private static function encodeHeader(string $s): string
    {
        return preg_match('/[^\x20-\x7E]/', $s) ? '=?UTF-8?B?' . base64_encode($s) . '?=' : $s;
    }
}

}

namespace App {

// ---- MediaTools.php ----
/** Image conversions some platforms need (JPEG only, size caps). Results are cached in the database. */
final class MediaTools
{
    public static function isVideo(array $m): bool
    {
        return str_starts_with($m['mime'], 'video/');
    }

    /**
     * Returns a JPEG version of an image media item (same array shape), converting and/or shrinking if needed.
     * $maxBytes and $maxSide are optional caps. Videos are returned unchanged.
     */
    public static function jpeg(array $m, ?int $maxBytes = null, ?int $maxSide = null): array
    {
        if (self::isVideo($m)) {
            return $m;
        }
        $fits = $m['mime'] === 'image/jpeg'
            && ($maxBytes === null || $m['size'] <= $maxBytes)
            && ($maxSide === null || max((int) $m['width'], (int) $m['height']) <= $maxSide);
        if ($fits) {
            return $m;
        }
        return self::convert($m, 'jpeg', $maxBytes, $maxSide);
    }

    /** Shrinks an image to fit a byte limit, keeping PNG/WebP if already small enough. */
    public static function fit(array $m, int $maxBytes, ?int $maxSide = null): array
    {
        if (self::isVideo($m) || ($m['size'] <= $maxBytes && ($maxSide === null || max((int) $m['width'], (int) $m['height']) <= $maxSide))) {
            return $m;
        }
        return self::convert($m, 'jpeg', $maxBytes, $maxSide);
    }

    private static function convert(array $m, string $format, ?int $maxBytes, ?int $maxSide): array
    {
        if (!function_exists('imagecreatefromstring')) {
            return $m;
        }
        $key = 'cache-' . $m['id'] . '-' . ($maxBytes ?? 0) . '-' . ($maxSide ?? 0) . '.jpg';
        if (!Blob::exists($key)) {
            $src = @imagecreatefromstring((string) file_get_contents($m['path']));
            if (!$src) {
                return $m;
            }
            $w = imagesx($src);
            $h = imagesy($src);
            $scale = $maxSide && max($w, $h) > $maxSide ? $maxSide / max($w, $h) : 1.0;
            $quality = 88;
            for ($attempt = 0; $attempt < 8; $attempt++) {
                $nw = max(1, (int) round($w * $scale));
                $nh = max(1, (int) round($h * $scale));
                $dst = imagecreatetruecolor($nw, $nh);
                // Flatten transparency onto white; JPEG has no alpha.
                imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                ob_start();
                imagejpeg($dst, null, $quality);
                $data = ob_get_clean();
                imagedestroy($dst);
                if ($maxBytes === null || strlen($data) <= $maxBytes) {
                    break;
                }
                $quality = max(60, $quality - 8);
                $scale *= 0.85;
            }
            imagedestroy($src);
            Blob::putString($key, $data);
        }
        $abs = Blob::file($key);
        $size = getimagesize($abs) ?: [null, null];
        return [
            'id' => $m['id'],
            'key' => $key,
            'path' => $abs,
            'url' => media_url($key, true),
            'mime' => 'image/jpeg',
            'size' => filesize($abs),
            'width' => $size[0],
            'height' => $size[1],
            'name' => pathinfo($m['name'], PATHINFO_FILENAME) . '.jpg',
        ];
    }

    /** Platforms that fetch media by URL can't reach localhost or private addresses. */
    public static function assertPublicUrl(string $url, string $platform): void
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $private = $host === 'localhost'
            || str_ends_with($host, '.local')
            || (filter_var($host, FILTER_VALIDATE_IP) && !filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE));
        if ($private) {
            throw new Providers\ProviderException("$platform downloads media from your server, but $host isn’t reachable from the internet. Set app_url in .ht.mixcreate-config.php to your public address.");
        }
    }
}

}

namespace App {

// ---- Metrics.php ----
use App\Providers\Registry;

/** Imports follower counts and post engagement from the platforms. Runs from cron. */
final class Metrics
{
    /** Imports at most once every $everyHours per account. */
    public static function import(int $everyHours = 6, int $postDays = 30): array
    {
        $report = ['accounts' => 0, 'posts' => 0, 'errors' => 0];
        if (!kv_due('metrics.imported', $everyHours * 3600)) {
            return $report;
        }

        $today = gmdate('Y-m-d');
        foreach (Db::all('SELECT * FROM accounts WHERE authorized = 1') as $account) {
            $class = Registry::class($account['provider']);
            if (!$class || !$class::implemented()) {
                continue;
            }
            try {
                $provider = Registry::withFreshToken($account);
                $m = $provider->accountMetrics();
                if ($m) {
                    $followers = isset($m['followers']) ? (int) $m['followers'] : null;
                    unset($m['followers']);
                    Db::query(
                        'INSERT INTO account_metrics (account_id, date, followers, data) VALUES (?, ?, ?, ?)
                         ON CONFLICT(account_id, date) DO UPDATE SET followers = excluded.followers, data = excluded.data',
                        [$account['id'], $today, $followers, json_encode($m)]
                    );
                    $report['accounts']++;
                }
                // Demographics change slowly; take one snapshot a day.
                if (!Db::value('SELECT 1 FROM account_audience WHERE account_id = ? AND date = ?', [$account['id'], $today])) {
                    $aud = $provider->audience();
                    if ($aud) {
                        Db::insert('account_audience', ['account_id' => $account['id'], 'date' => $today, 'data' => json_encode($aud)]);
                    }
                }
                $posts = Db::all(
                    "SELECT post_id, provider_post_id FROM post_accounts
                     WHERE account_id = ? AND status = 'published' AND provider_post_id IS NOT NULL AND provider_post_id != '' AND published_at >= ?",
                    [$account['id'], gmdate('Y-m-d H:i:s', time() - $postDays * 86400)]
                );
                foreach ($posts as $p) {
                    $pm = $provider->postMetrics($p['provider_post_id']);
                    if ($pm) {
                        Db::query(
                            'INSERT INTO post_metrics (post_id, account_id, data, updated_at) VALUES (?, ?, ?, ?)
                             ON CONFLICT(post_id, account_id) DO UPDATE SET data = excluded.data, updated_at = excluded.updated_at',
                            [$p['post_id'], $account['id'], json_encode($pm), now()]
                        );
                        $report['posts']++;
                    }
                }
            } catch (\Throwable $e) {
                $report['errors']++;
                if ($e instanceof \App\Providers\ProviderException && $e->unauthorized) {
                    Db::update('accounts', ['authorized' => 0, 'updated_at' => now()], 'id = ?', [$account['id']]);
                }
                log_event('warning', "Metrics import failed for account {$account['id']}: " . $e->getMessage());
            }
        }
        return $report;
    }

    /** Sums the numeric engagement fields of a post_metrics data blob. */
    public static function engagement(array $data): int
    {
        $sum = 0;
        foreach (['likes', 'replies', 'comments', 'shares', 'reposts', 'quotes', 'saves', 'clicks', 'reactions'] as $k) {
            $sum += (int) ($data[$k] ?? 0);
        }
        return $sum;
    }
}

}

namespace App {

// ---- Middleware.php ----
final class Middleware
{
    /** @var array<string, callable> middleware added by modules */
    private static array $custom = [];

    public static function register(string $name, callable $handler): void
    {
        self::$custom[$name] = $handler;
    }

    public static function run(string $name): void
    {
        if ($name === 'no_csrf') {
            return;
        }
        [$base] = explode(':', $name, 2);
        if (isset(self::$custom[$base])) {
            (self::$custom[$base])(explode(':', $name, 2)[1] ?? null);
            return;
        }
        [$name, $arg] = array_pad(explode(':', $name, 2), 2, null);
        match ($name) {
            'auth' => self::auth(),
            'guest' => self::guest(),
            'workspace' => self::workspace(),
            'role' => self::role((string) $arg),
            'admin' => self::admin(),
            default => throw new \LogicException("Unknown middleware $name"),
        };
    }

    private static function auth(): void
    {
        if (!Auth::user()) {
            if (wants_json()) {
                json_response(['error' => 'Not signed in.'], 401);
            }
            $_SESSION['_intended'] = $_SERVER['REQUEST_URI'] ?? null;
            redirect('/login');
        }
    }

    private static function guest(): void
    {
        if (Auth::user()) {
            redirect('/');
        }
    }

    private static function workspace(): void
    {
        if (!Workspace::current()) {
            redirect('/workspaces/new');
        }
    }

    private static function role(string $role): void
    {
        if (!Workspace::can($role)) {
            abort(403);
        }
    }

    private static function admin(): void
    {
        if (!Auth::isAdmin()) {
            abort(403);
        }
    }
}

}

namespace App {

// ---- Notify.php ----
/** Push notifications for things people care about: failures, approvals, comments, publishes. */
final class Notify
{
    public const EVENTS = [
        'failed' => 'A post failed to publish',
        'approvals' => 'A post needs my approval (admins)',
        'comments' => 'New comments in the inbox',
        'published' => 'My post was published',
    ];

    /** Sends to users who opted in to $event. Delivery happens after the response when possible. */
    public static function users(array $userIds, string $event, string $title, string $body, string $path): void
    {
        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if (!$userIds) {
            return;
        }
        $users = Db::all('SELECT id, notify FROM users WHERE id IN (' . implode(',', $userIds) . ')');
        $ids = array_map(fn($u) => (int) $u['id'], array_filter($users, fn($u) => !empty(json_arr($u['notify'])[$event])));
        if (!$ids) {
            return;
        }
        $subs = Db::all('SELECT * FROM push_subscriptions WHERE user_id IN (' . implode(',', $ids) . ')');
        if (!$subs) {
            return;
        }
        $message = ['title' => $title, 'body' => str_limit($body, 180), 'url' => abs_url(ltrim($path, '/')), 'tag' => $event];
        $send = function () use ($subs, $message) {
            foreach ($subs as $s) {
                try {
                    WebPush::send($s, $message);
                } catch (\Throwable $e) {
                    error_log('push: ' . $e->getMessage());
                }
            }
        };
        PHP_SAPI === 'cli' ? $send() : register_shutdown_function($send);
    }

    public static function workspaceAdmins(int $workspaceId): array
    {
        return array_column(Db::all("SELECT user_id FROM workspace_user WHERE workspace_id = ? AND role = 'admin'", [$workspaceId]), 'user_id');
    }

    public static function workspaceMembers(int $workspaceId): array
    {
        return array_column(Db::all("SELECT user_id FROM workspace_user WHERE workspace_id = ? AND role IN ('admin', 'member')", [$workspaceId]), 'user_id');
    }
}

}

namespace App {

// ---- Pdf.php ----
/**
 * Small PDF writer: A4 pages, Helvetica / Helvetica-Bold, filled and stroked shapes, text.
 * Coordinates are in points from the top-left corner. Text is converted to Windows-1252.
 */
final class Pdf
{
    public const W = 595.28;
    public const H = 841.89;

    private array $pages = [];
    /** @var array<string, array{data: string, w: int, h: int}> JPEG images by name */
    private array $images = [];
    private string $buf = '';
    private float $size = 10;
    private bool $bold = false;

    private const HELV = [
        ' ' => 278, '!' => 278, '"' => 355, '#' => 556, '$' => 556, '%' => 889, '&' => 667, "'" => 191, '(' => 333, ')' => 333, '*' => 389, '+' => 584,
        ',' => 278, '-' => 333, '.' => 278, '/' => 278, ':' => 278, ';' => 278, '<' => 584, '=' => 584, '>' => 584, '?' => 556, '@' => 1015,
        'A' => 667, 'B' => 667, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278, 'J' => 500, 'K' => 667, 'L' => 556,
        'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667,
        'Y' => 667, 'Z' => 611, '[' => 278, '\\' => 278, ']' => 278, '^' => 469, '_' => 556, '`' => 333, 'a' => 556, 'b' => 556, 'c' => 500, 'd' => 556,
        'e' => 556, 'f' => 278, 'g' => 556, 'h' => 556, 'i' => 222, 'j' => 222, 'k' => 500, 'l' => 222, 'm' => 833, 'n' => 556, 'o' => 556, 'p' => 556,
        'q' => 556, 'r' => 333, 's' => 500, 't' => 278, 'u' => 556, 'v' => 500, 'w' => 722, 'x' => 500, 'y' => 500, 'z' => 500, '{' => 334, '|' => 260,
        '}' => 334, '~' => 584,
    ];
    private const HELV_B = [
        ' ' => 278, '!' => 333, '"' => 474, '#' => 556, '$' => 556, '%' => 889, '&' => 722, "'" => 238, '(' => 333, ')' => 333, '*' => 389, '+' => 584,
        ',' => 278, '-' => 333, '.' => 278, '/' => 278, ':' => 333, ';' => 333, '<' => 584, '=' => 584, '>' => 584, '?' => 611, '@' => 975,
        'A' => 722, 'B' => 722, 'C' => 722, 'D' => 722, 'E' => 667, 'F' => 611, 'G' => 778, 'H' => 722, 'I' => 278, 'J' => 556, 'K' => 722, 'L' => 611,
        'M' => 833, 'N' => 722, 'O' => 778, 'P' => 667, 'Q' => 778, 'R' => 722, 'S' => 667, 'T' => 611, 'U' => 722, 'V' => 667, 'W' => 944, 'X' => 667,
        'Y' => 667, 'Z' => 611, '[' => 333, '\\' => 278, ']' => 333, '^' => 584, '_' => 556, '`' => 333, 'a' => 556, 'b' => 611, 'c' => 556, 'd' => 611,
        'e' => 556, 'f' => 333, 'g' => 611, 'h' => 611, 'i' => 278, 'j' => 278, 'k' => 556, 'l' => 278, 'm' => 889, 'n' => 611, 'o' => 611, 'p' => 611,
        'q' => 611, 'r' => 389, 's' => 556, 't' => 333, 'u' => 611, 'v' => 556, 'w' => 778, 'x' => 556, 'y' => 556, 'z' => 500, '{' => 389, '|' => 280,
        '}' => 389, '~' => 584,
    ];

    public function addPage(): void
    {
        if ($this->buf !== '') {
            $this->pages[] = $this->buf;
        }
        $this->buf = ' ';
    }

    public function font(float $size, bool $bold = false): void
    {
        $this->size = $size;
        $this->bold = $bold;
    }

    public function fill(string $hex): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $this->buf .= sprintf("%.3F %.3F %.3F rg\n", $r, $g, $b);
    }

    public function stroke(string $hex, float $width = 1): void
    {
        [$r, $g, $b] = self::rgb($hex);
        $this->buf .= sprintf("%.3F %.3F %.3F RG %.2F w\n", $r, $g, $b, $width);
    }

    public function rect(float $x, float $y, float $w, float $h, bool $fill = true, bool $stroke = false, float $radius = 0): void
    {
        $op = $fill && $stroke ? 'B' : ($fill ? 'f' : 'S');
        if ($radius <= 0) {
            $this->buf .= sprintf("%.2F %.2F %.2F %.2F re %s\n", $x, self::H - $y - $h, $w, $h, $op);
            return;
        }
        $r = min($radius, $w / 2, $h / 2);
        $k = 0.5523 * $r;
        $x1 = $x; $y1 = self::H - $y; $x2 = $x + $w; $y2 = self::H - $y - $h;
        $this->buf .= sprintf(
            "%.2F %.2F m %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c %.2F %.2F l %.2F %.2F %.2F %.2F %.2F %.2F c h %s\n",
            $x1 + $r, $y1, $x2 - $r, $y1, $x2 - $r + $k, $y1, $x2, $y1 - $r + $k, $x2, $y1 - $r,
            $x2, $y2 + $r, $x2, $y2 + $r - $k, $x2 - $r + $k, $y2, $x2 - $r, $y2,
            $x1 + $r, $y2, $x1 + $r - $k, $y2, $x1, $y2 + $r - $k, $x1, $y2 + $r,
            $x1, $y1 - $r, $x1, $y1 - $r + $k, $x1 + $r - $k, $y1, $x1 + $r, $y1, $op
        );
    }

    /** Draws a JPEG file. Height is derived from the aspect ratio when $h is 0. Returns the drawn height. */
    public function image(string $jpegPath, float $x, float $y, float $w, float $h = 0): float
    {
        $info = @getimagesize($jpegPath);
        if (!$info || $info[2] !== IMAGETYPE_JPEG) {
            return 0;
        }
        $name = 'Im' . substr(md5($jpegPath), 0, 8);
        $this->images[$name] ??= ['data' => (string) file_get_contents($jpegPath), 'w' => $info[0], 'h' => $info[1]];
        if ($h <= 0) {
            $h = $w * $info[1] / $info[0];
        }
        $this->buf .= sprintf("q %.2F 0 0 %.2F %.2F %.2F cm /%s Do Q\n", $w, $h, $x, self::H - $y - $h, $name);
        return $h;
    }

    public function line(float $x1, float $y1, float $x2, float $y2): void
    {
        $this->buf .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $x1, self::H - $y1, $x2, self::H - $y2);
    }

    /** @param array<array{0: float, 1: float}> $points */
    public function polyline(array $points, bool $closeFill = false): void
    {
        if (count($points) < 2) {
            return;
        }
        $s = '';
        foreach ($points as $i => [$x, $y]) {
            $s .= sprintf('%.2F %.2F %s ', $x, self::H - $y, $i === 0 ? 'm' : 'l');
        }
        $this->buf .= $s . ($closeFill ? "h f\n" : "S\n");
    }

    /** Text at (x, baseline y). align: L, R, C. */
    public function text(float $x, float $y, string $text, string $align = 'L'): void
    {
        $enc = self::encode($text);
        $w = $this->width($text);
        if ($align === 'R') {
            $x -= $w;
        } elseif ($align === 'C') {
            $x -= $w / 2;
        }
        $this->buf .= sprintf("BT /%s %.2F Tf %.2F %.2F Td (%s) Tj ET\n", $this->bold ? 'F2' : 'F1', $this->size, $x, self::H - $y, self::escape($enc));
    }

    /** Text cut with an ellipsis to fit $maxWidth. */
    public function textFit(float $x, float $y, string $text, float $maxWidth, string $align = 'L'): void
    {
        $text = preg_replace('/\s+/u', ' ', trim($text));
        if ($this->width($text) > $maxWidth) {
            while ($text !== '' && $this->width($text . '...') > $maxWidth) {
                $text = mb_substr($text, 0, -1);
            }
            $text = rtrim($text) . '...';
        }
        $this->text($x, $y, $text, $align);
    }

    /** Wraps text into lines that fit $maxWidth. */
    public function wrap(string $text, float $maxWidth): array
    {
        $lines = [];
        foreach (preg_split('/\R/u', $text) as $para) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($para)) as $word) {
                $try = $line === '' ? $word : "$line $word";
                if ($line !== '' && $this->width($try) > $maxWidth) {
                    $lines[] = $line;
                    $line = $word;
                } else {
                    $line = $try;
                }
            }
            $lines[] = $line;
        }
        return $lines;
    }

    public function width(string $text): float
    {
        $table = $this->bold ? self::HELV_B : self::HELV;
        $w = 0;
        foreach (mb_str_split(self::plain($text)) as $ch) {
            $w += $table[$ch] ?? (ctype_digit($ch) ? 556 : 556);
        }
        return $w * $this->size / 1000;
    }

    public function output(): string
    {
        if ($this->buf !== '') {
            $this->pages[] = $this->buf;
            $this->buf = '';
        }
        $objs = [];
        $objs[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objs[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objs[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $kids = [];
        $n = 5;
        $xobjects = '';
        foreach ($this->images as $name => $img) {
            $objs[$n] = sprintf("<< /Type /XObject /Subtype /Image /Width %d /Height %d /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length %d >>\nstream\n", $img['w'], $img['h'], strlen($img['data'])) . $img['data'] . "\nendstream";
            $xobjects .= "/$name $n 0 R ";
            $n++;
        }
        $res = '/Font << /F1 3 0 R /F2 4 0 R >>' . ($xobjects !== '' ? " /XObject << $xobjects>>" : '');
        foreach ($this->pages as $content) {
            $pageId = $n++;
            $contentId = $n++;
            $kids[] = "$pageId 0 R";
            $objs[$pageId] = sprintf('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %.2F %.2F] /Resources << %s >> /Contents %d 0 R >>', self::W, self::H, $res, $contentId);
            $z = gzcompress($content);
            $objs[$contentId] = "<< /Length " . strlen($z) . " /Filter /FlateDecode >>\nstream\n" . $z . "\nendstream";
        }
        $objs[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objs);

        $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [];
        foreach ($objs as $id => $body) {
            $offsets[$id] = strlen($out);
            $out .= "$id 0 obj\n$body\nendobj\n";
        }
        $xref = strlen($out);
        $max = max(array_keys($objs));
        $out .= "xref\n0 " . ($max + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $out .= sprintf("%010d 00000 n \n", $offsets[$i] ?? 0);
        }
        $out .= "trailer\n<< /Size " . ($max + 1) . " /Root 1 0 R /Info << /Producer (MixCreate) >> >>\nstartxref\n$xref\n%%EOF";
        return $out;
    }

    private static function plain(string $s): string
    {
        // Common typographic characters to ASCII-ish so widths are close.
        return strtr($s, ['’' => "'", '‘' => "'", '“' => '"', '”' => '"', '–' => '-', '—' => '-', '…' => '...', '·' => '.', '•' => '.']);
    }

    private static function encode(string $s): string
    {
        $s = strtr($s, ['…' => '...']);
        $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $s);
        return $out === false ? preg_replace('/[^\x20-\x7E]/', '?', $s) : $out;
    }

    private static function escape(string $s): string
    {
        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', '', ' '], $s);
    }

    private static function rgb(string $hex): array
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        return [hexdec(substr($hex, 0, 2)) / 255, hexdec(substr($hex, 2, 2)) / 255, hexdec(substr($hex, 4, 2)) / 255];
    }
}

}

namespace App {

// ---- PostService.php ----
use App\Providers\Registry;

/** Creating, validating and scheduling posts. Shared by the web UI and the REST API. */
final class PostService
{
    /**
     * @param array $in content, accounts (ids), media (ids), tags (ids), overrides ([account_id => text]), scheduled_at (UTC "Y-m-d H:i:s" or null)
     * @param string $action draft | schedule | now
     * @return array{post: ?array, errors: string[]}
     */
    public static function save(?int $postId, array $in, string $action, int $workspaceId, int $userId, bool $isAdmin): array
    {
        $existing = $postId ? self::find($postId, $workspaceId) : null;
        if ($postId && !$existing) {
            return ['post' => null, 'errors' => ['Post not found.']];
        }
        if ($existing && in_array($existing['status'], ['publishing', 'published'], true)) {
            return ['post' => null, 'errors' => ['Published posts can’t be edited. Duplicate it instead.']];
        }

        $content = str_replace("\r\n", "\n", (string) ($in['content'] ?? ''));
        $accountIds = self::ownedIds('accounts', $in['accounts'] ?? [], $workspaceId);
        $mediaIds = self::ownedIds('media', $in['media'] ?? [], $workspaceId, keepOrder: true);
        $tagIds = self::ownedIds('tags', $in['tags'] ?? [], $workspaceId);
        $overrides = [];
        foreach ((array) ($in['overrides'] ?? []) as $aid => $text) {
            $text = str_replace("\r\n", "\n", trim((string) $text));
            if ($text !== '' && in_array((int) $aid, $accountIds, true)) {
                $overrides[(int) $aid] = $text;
            }
        }

        $types = [];
        foreach ((array) ($in['types'] ?? []) as $aid => $type) {
            if (in_array((int) $aid, $accountIds, true) && is_string($type) && $type !== 'feed') {
                $types[(int) $aid] = $type;
            }
        }
        $firstComment = mb_substr(trim(str_replace("\r\n", "\n", (string) ($in['first_comment'] ?? ''))), 0, 2000);

        $scheduledAt = $in['scheduled_at'] ?? null;
        if ($action === 'now') {
            $scheduledAt = now();
        }

        $errors = self::validate($content, $accountIds, $mediaIds, $overrides, $scheduledAt, $action, $workspaceId, $types);
        if ($errors) {
            return ['post' => null, 'errors' => $errors];
        }

        $ws = Db::one('SELECT * FROM workspaces WHERE id = ?', [$workspaceId]);
        $status = 'draft';
        if ($action !== 'draft') {
            $status = ($ws['require_approval'] && !$isAdmin) ? 'pending_approval' : 'scheduled';
        }
        $status = Hooks::filter('post.status', $status, $existing ?? [], $action);

        $id = Db::transaction(function () use ($existing, $workspaceId, $userId, $content, $mediaIds, $status, $scheduledAt, $accountIds, $overrides, $tagIds, $types, $firstComment, $in) {
            $row = [
                'content' => $content,
                'first_comment' => $firstComment !== '' ? $firstComment : null,
                'media' => json_encode($mediaIds),
                'status' => $status,
                'scheduled_at' => $scheduledAt,
                'review_note' => null,
                'updated_at' => now(),
            ];
            if ($existing) {
                $id = (int) $existing['id'];
                Db::update('posts', $row, 'id = ?', [$id]);
            } else {
                $id = Db::insert('posts', $row + ['workspace_id' => $workspaceId, 'user_id' => $userId ?: null, 'autolist_id' => $in['autolist_id'] ?? null, 'created_at' => now()]);
            }
            // Keep publish results for accounts that already succeeded (retry after partial failure).
            $keep = Db::all("SELECT account_id FROM post_accounts WHERE post_id = ? AND status = 'published'", [$id]);
            $keep = array_map('intval', array_column($keep, 'account_id'));
            Db::query(
                'DELETE FROM post_accounts WHERE post_id = ?' . ($keep ? ' AND account_id NOT IN (' . implode(',', $keep) . ')' : ''),
                [$id]
            );
            foreach ($accountIds as $aid) {
                if (in_array($aid, $keep, true)) {
                    continue;
                }
                Db::insert('post_accounts', [
                    'post_id' => $id,
                    'account_id' => $aid,
                    'content' => $overrides[$aid] ?? null,
                    'options' => json_encode(isset($types[$aid]) ? ['type' => $types[$aid]] : new \stdClass()),
                    'status' => 'pending',
                ]);
            }
            Db::query('DELETE FROM post_tag WHERE post_id = ?', [$id]);
            foreach ($tagIds as $tid) {
                Db::insert('post_tag', ['post_id' => $id, 'tag_id' => $tid]);
            }
            return $id;
        });

        $post = self::find($id, $workspaceId);
        Hooks::fire('post.saved', $post, $existing === null);
        Webhooks::dispatch($workspaceId, $existing ? 'post.updated' : 'post.created', ['post' => self::toArray($post)]);
        if ($status === 'pending_approval') {
            Webhooks::dispatch($workspaceId, 'post.approval_requested', ['post' => self::toArray($post)]);
            Notify::users(Notify::workspaceAdmins($workspaceId), 'approvals', 'Post needs approval', str_limit($content !== '' ? $content : '(media)', 120), '/posts/' . $post['id']);
        } elseif ($status === 'scheduled') {
            Webhooks::dispatch($workspaceId, 'post.scheduled', ['post' => self::toArray($post)]);
        }
        return ['post' => $post, 'errors' => []];
    }

    public static function validate(string $content, array $accountIds, array $mediaIds, array $overrides, ?string $scheduledAt, string $action, int $workspaceId, array $types = []): array
    {
        $errors = [];
        if ($action === 'draft') {
            if (trim($content) === '' && !$mediaIds) {
                $errors[] = 'Write something or add media before saving.';
            }
            return $errors;
        }
        if (!$accountIds) {
            $errors[] = 'Pick at least one account to post to.';
        }
        if ($action === 'schedule') {
            if (!$scheduledAt) {
                $errors[] = 'Pick a date and time to schedule the post.';
            } elseif (strtotime($scheduledAt . ' UTC') < time() - 60) {
                $errors[] = 'The scheduled time is in the past.';
            }
        }

        $media = $mediaIds ? Db::all('SELECT id, mime FROM media WHERE id IN (' . implode(',', $mediaIds) . ')') : [];
        $videos = count(array_filter($media, fn($m) => str_starts_with($m['mime'], 'video/')));
        $images = count($media) - $videos;

        $accounts = $accountIds ? Db::all('SELECT id, provider, name FROM accounts WHERE id IN (' . implode(',', $accountIds) . ')') : [];
        foreach ($accounts as $a) {
            $class = Registry::class($a['provider']);
            if (!$class) {
                continue;
            }
            $label = $class::name() . ' (' . $a['name'] . ')';
            $type = $types[(int) $a['id']] ?? 'feed';
            if ($type !== 'feed') {
                if (!isset($class::postTypes()[$type])) {
                    $errors[] = "$label doesn’t support that post type.";
                } elseif ($type === 'story' && count($media) !== 1) {
                    $errors[] = "$label: a story needs exactly one image or video.";
                }
                continue; // stories skip text and carousel rules
            }
            $text = Variables::apply($workspaceId, $overrides[(int) $a['id']] ?? $content);
            $limit = $class::textLimit();
            if (self::length($text, $a['provider']) > $limit) {
                $errors[] = "$label: the text is over the $limit character limit.";
            }
            if (trim($text) === '' && !$media) {
                $errors[] = "$label: the post is empty.";
            }
            $rules = $class::mediaRules();
            if ($rules['requires_video'] && !$videos) {
                $errors[] = "$label needs a video.";
            } elseif ($rules['requires_media'] && !$media) {
                $errors[] = "$label needs an image or video.";
            }
            if (count($media) > $rules['max_media']) {
                $errors[] = "$label allows at most {$rules['max_media']} media files.";
            }
            if ($videos && !$rules['video']) {
                $errors[] = "$label doesn’t support video.";
            }
            if ($videos > 1 || ($videos && $images && !in_array($a['provider'], ['facebook', 'instagram', 'threads'], true))) {
                $errors[] = "$label: use one video on its own, or images only.";
            }
        }
        return array_values(array_unique($errors));
    }

    /** Character count the way each platform counts (X counts every link as 23). */
    public static function length(string $text, string $provider): int
    {
        if ($provider === 'x') {
            $text = preg_replace('#https?://\S+#u', str_repeat('x', 23), $text);
        }
        return mb_strlen($text);
    }

    public static function find(int $id, int $workspaceId): ?array
    {
        return Db::one('SELECT * FROM posts WHERE id = ? AND workspace_id = ?', [$id, $workspaceId]);
    }

    /** Publishes a post right away. Used for "Publish now" and for approvals of overdue posts. */
    public static function publishNowInBackground(int $postId): void
    {
        register_shutdown_function(function () use ($postId) {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            }
            ignore_user_abort(true);
            set_time_limit(600);
            Publisher::publish($postId);
        });
    }

    public static function approve(array $post, int $reviewerId): void
    {
        $scheduledAt = $post['scheduled_at'] ?: now();
        Db::update('posts', [
            'status' => 'scheduled',
            'scheduled_at' => $scheduledAt,
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'review_note' => null,
            'updated_at' => now(),
        ], 'id = ?', [$post['id']]);
        $post = self::find((int) $post['id'], (int) $post['workspace_id']);
        Webhooks::dispatch((int) $post['workspace_id'], 'post.approved', ['post' => self::toArray($post)]);
        if (strtotime($scheduledAt . ' UTC') <= time()) {
            self::publishNowInBackground((int) $post['id']);
        }
    }

    public static function reject(array $post, int $reviewerId, string $note): void
    {
        Db::update('posts', [
            'status' => 'draft',
            'reviewed_by' => $reviewerId,
            'reviewed_at' => now(),
            'review_note' => $note !== '' ? $note : 'Sent back for changes.',
            'updated_at' => now(),
        ], 'id = ?', [$post['id']]);
        $post = self::find((int) $post['id'], (int) $post['workspace_id']);
        Webhooks::dispatch((int) $post['workspace_id'], 'post.rejected', ['post' => self::toArray($post)]);
    }

    public static function delete(array $post): void
    {
        Db::query('DELETE FROM posts WHERE id = ?', [$post['id']]);
        Webhooks::dispatch((int) $post['workspace_id'], 'post.deleted', ['post' => ['id' => (int) $post['id']]]);
    }

    /** Public JSON shape of a post, used by the API and webhooks. */
    public static function toArray(array $post): array
    {
        $targets = Db::all(
            'SELECT pa.*, a.provider, a.name, a.username FROM post_accounts pa JOIN accounts a ON a.id = pa.account_id WHERE pa.post_id = ?',
            [$post['id']]
        );
        $tags = Db::all('SELECT t.id, t.name, t.color FROM tags t JOIN post_tag pt ON pt.tag_id = t.id WHERE pt.post_id = ?', [$post['id']]);
        $iso = fn(?string $t) => $t ? gmdate('c', strtotime($t . ' UTC')) : null;
        return [
            'id' => (int) $post['id'],
            'status' => $post['status'],
            'content' => $post['content'],
            'media' => array_map(fn($m) => ['id' => $m['id'], 'url' => $m['url'], 'mime' => $m['mime']], Publisher::resolveMedia(json_arr($post['media']))),
            'scheduled_at' => $iso($post['scheduled_at']),
            'published_at' => $iso($post['published_at']),
            'review_note' => $post['review_note'],
            'first_comment' => $post['first_comment'] ?? null,
            'tags' => array_map(fn($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'color' => $t['color']], $tags),
            'accounts' => array_map(fn($t) => [
                'id' => (int) $t['account_id'],
                'provider' => $t['provider'],
                'name' => $t['name'],
                'username' => $t['username'],
                'content' => $t['content'],
                'type' => json_arr($t['options'] ?? '{}')['type'] ?? 'feed',
                'status' => $t['status'],
                'url' => $t['url'],
                'error' => $t['error'],
                'published_at' => $iso($t['published_at']),
            ], $targets),
            'created_at' => $iso($post['created_at']),
            'updated_at' => $iso($post['updated_at']),
        ];
    }

    /** Filters ids to those belonging to the workspace. */
    private static function ownedIds(string $table, mixed $ids, int $workspaceId, bool $keepOrder = false): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        if (!$ids) {
            return [];
        }
        $found = Db::all(
            "SELECT id FROM $table WHERE workspace_id = ? AND id IN (" . implode(',', $ids) . ')',
            [$workspaceId]
        );
        $found = array_map('intval', array_column($found, 'id'));
        return $keepOrder ? array_values(array_filter($ids, fn($i) => in_array($i, $found, true))) : $found;
    }
}

}

namespace App {

// ---- Pptx.php ----
/**
 * Minimal PowerPoint (.pptx) writer: 16:9 slides with text boxes, filled shapes and images.
 * Coordinates are in points on a 960 × 540 slide.
 */
final class Pptx
{
    private const EMU = 12700;
    private const NS = 'xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:p="http://schemas.openxmlformats.org/presentationml/2006/main"';

    /** @var array<int, array{shapes: string[], rels: array<string, string>, bg: ?string}> */
    private array $slides = [];
    /** @var array<string, string> media file name => bytes */
    private array $media = [];
    private int $nextId = 2;

    public function __construct(private string $title = 'Report', private string $font = 'Calibri')
    {
    }

    public function addSlide(?string $background = null): int
    {
        $this->slides[] = ['shapes' => [], 'rels' => [], 'bg' => $background];
        return count($this->slides) - 1;
    }

    private static function emu(float $pt): int
    {
        return (int) round($pt * self::EMU);
    }

    private static function hex(string $c): string
    {
        return strtoupper(ltrim($c, '#'));
    }

    private static function x(string $s): string
    {
        // Strip characters XML 1.0 forbids, then escape.
        $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s) ?? '';
        return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }

    private function xfrm(float $x, float $y, float $w, float $h): string
    {
        return sprintf('<a:xfrm><a:off x="%d" y="%d"/><a:ext cx="%d" cy="%d"/></a:xfrm>', self::emu($x), self::emu($y), self::emu($w), self::emu($h));
    }

    /** Filled (rounded) rectangle. */
    public function rect(int $slide, float $x, float $y, float $w, float $h, string $fill, bool $rounded = false, ?string $line = null): void
    {
        $id = $this->nextId++;
        $geom = $rounded ? '<a:prstGeom prst="roundRect"><a:avLst><a:gd name="adj" fmla="val 8000"/></a:avLst></a:prstGeom>' : '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>';
        $ln = $line ? '<a:ln w="9525"><a:solidFill><a:srgbClr val="' . self::hex($line) . '"/></a:solidFill></a:ln>' : '<a:ln><a:noFill/></a:ln>';
        $this->slides[$slide]['shapes'][] = '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Shape ' . $id . '"/><p:cNvSpPr/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr>' . $this->xfrm($x, $y, $w, $h) . $geom . '<a:solidFill><a:srgbClr val="' . self::hex($fill) . '"/></a:solidFill>' . $ln . '</p:spPr>'
            . '<p:txBody><a:bodyPr/><a:lstStyle/><a:p><a:endParaRPr lang="en-US"/></a:p></p:txBody></p:sp>';
    }

    /**
     * Text box. Options: size (pt), bold, color, align (l|ctr|r), valign (t|ctr|b), fill, lines (array of [text, size, bold, color] for multi-style paragraphs).
     */
    public function text(int $slide, float $x, float $y, float $w, float $h, string $text, array $o = []): void
    {
        $id = $this->nextId++;
        $paras = $o['lines'] ?? array_map(fn($line) => [$line, $o['size'] ?? 14, $o['bold'] ?? false, $o['color'] ?? '#15181e'], preg_split('/\R/u', $text));
        $body = '';
        foreach ($paras as [$t, $size, $bold, $color]) {
            $body .= '<a:p><a:pPr algn="' . ($o['align'] ?? 'l') . '"/>'
                . '<a:r><a:rPr lang="en-US" sz="' . (int) round($size * 100) . '"' . ($bold ? ' b="1"' : '') . ' dirty="0">'
                . '<a:solidFill><a:srgbClr val="' . self::hex($color) . '"/></a:solidFill><a:latin typeface="' . self::x($this->font) . '"/></a:rPr>'
                . '<a:t>' . self::x($t) . '</a:t></a:r></a:p>';
        }
        $fill = isset($o['fill']) ? '<a:solidFill><a:srgbClr val="' . self::hex($o['fill']) . '"/></a:solidFill>' : '<a:noFill/>';
        $this->slides[$slide]['shapes'][] = '<p:sp><p:nvSpPr><p:cNvPr id="' . $id . '" name="Text ' . $id . '"/><p:cNvSpPr txBox="1"/><p:nvPr/></p:nvSpPr>'
            . '<p:spPr>' . $this->xfrm($x, $y, $w, $h) . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom>' . $fill . '</p:spPr>'
            . '<p:txBody><a:bodyPr wrap="square" lIns="45720" tIns="22860" rIns="45720" bIns="22860" anchor="' . ($o['valign'] ?? 't') . '"><a:normAutofit/></a:bodyPr><a:lstStyle/>'
            . $body . '</p:txBody></p:sp>';
    }

    /** Image from bytes ('png' or 'jpeg'). */
    public function image(int $slide, string $bytes, string $ext, float $x, float $y, float $w, float $h): void
    {
        $name = 'image' . (count($this->media) + 1) . '.' . $ext;
        $this->media[$name] = $bytes;
        $rid = 'rId' . (count($this->slides[$slide]['rels']) + 2);
        $this->slides[$slide]['rels'][$rid] = '../media/' . $name;
        $id = $this->nextId++;
        $this->slides[$slide]['shapes'][] = '<p:pic><p:nvPicPr><p:cNvPr id="' . $id . '" name="Picture ' . $id . '"/><p:cNvPicPr><a:picLocks noChangeAspect="1"/></p:cNvPicPr><p:nvPr/></p:nvPicPr>'
            . '<p:blipFill><a:blip r:embed="' . $rid . '"/><a:stretch><a:fillRect/></a:stretch></p:blipFill>'
            . '<p:spPr>' . $this->xfrm($x, $y, $w, $h) . '<a:prstGeom prst="rect"><a:avLst/></a:prstGeom></p:spPr></p:pic>';
    }

    public function output(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pptx');
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        $n = count($this->slides);

        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Default Extension="png" ContentType="image/png"/><Default Extension="jpeg" ContentType="image/jpeg"/>'
            . '<Override PartName="/ppt/presentation.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.presentation.main+xml"/>'
            . '<Override PartName="/ppt/slideMasters/slideMaster1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideMaster+xml"/>'
            . '<Override PartName="/ppt/slideLayouts/slideLayout1.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slideLayout+xml"/>'
            . '<Override PartName="/ppt/theme/theme1.xml" ContentType="application/vnd.openxmlformats-officedocument.theme+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>';
        for ($i = 1; $i <= $n; $i++) {
            $ct .= '<Override PartName="/ppt/slides/slide' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.presentationml.slide+xml"/>';
        }
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');

        $rel = fn(array $rels) => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . implode('', array_map(fn($r) => '<Relationship Id="' . $r[0] . '" Type="http://schemas.openxmlformats.org/' . $r[1] . '" Target="' . $r[2] . '"/>', $rels)) . '</Relationships>';

        $zip->addFromString('_rels/.rels', $rel([
            ['rId1', 'officeDocument/2006/relationships/officeDocument', 'ppt/presentation.xml'],
            ['rId2', 'package/2006/relationships/metadata/core-properties', 'docProps/core.xml'],
            ['rId3', 'officeDocument/2006/relationships/extended-properties', 'docProps/app.xml'],
        ]));
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>' . self::x($this->title) . '</dc:title><dc:creator>' . self::x(Config::get('app_name', 'MixCreate')) . '</dc:creator>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:created><dcterms:modified xsi:type="dcterms:W3CDTF">' . $now . '</dcterms:modified></cp:coreProperties>');
        $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties"><Application>' . self::x(Config::get('app_name', 'MixCreate')) . '</Application><Slides>' . $n . '</Slides></Properties>');

        $sldIds = '';
        $presRels = [['rId1', 'officeDocument/2006/relationships/slideMaster', 'slideMasters/slideMaster1.xml']];
        for ($i = 1; $i <= $n; $i++) {
            $sldIds .= '<p:sldId id="' . (255 + $i) . '" r:id="rId' . ($i + 1) . '"/>';
            $presRels[] = ['rId' . ($i + 1), 'officeDocument/2006/relationships/slide', 'slides/slide' . $i . '.xml'];
        }
        $presRels[] = ['rId' . ($n + 2), 'officeDocument/2006/relationships/theme', 'theme/theme1.xml'];
        $zip->addFromString('ppt/presentation.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:presentation ' . self::NS . ' saveSubsetFonts="1">'
            . '<p:sldMasterIdLst><p:sldMasterId id="2147483648" r:id="rId1"/></p:sldMasterIdLst><p:sldIdLst>' . $sldIds . '</p:sldIdLst>'
            . '<p:sldSz cx="12192000" cy="6858000"/><p:notesSz cx="6858000" cy="9144000"/><p:defaultTextStyle><a:defPPr><a:defRPr lang="en-US"/></a:defPPr></p:defaultTextStyle></p:presentation>');
        $zip->addFromString('ppt/_rels/presentation.xml.rels', $rel($presRels));

        $emptyTree = '<p:spTree><p:nvGrpSpPr><p:cNvPr id="1" name=""/><p:cNvGrpSpPr/><p:nvPr/></p:nvGrpSpPr><p:grpSpPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="0" cy="0"/><a:chOff x="0" y="0"/><a:chExt cx="0" cy="0"/></a:xfrm></p:grpSpPr>';
        $zip->addFromString('ppt/slideMasters/slideMaster1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldMaster ' . self::NS . '>'
            . '<p:cSld><p:bg><p:bgRef idx="1001"><a:schemeClr val="bg1"/></p:bgRef></p:bg>' . $emptyTree . '</p:spTree></p:cSld>'
            . '<p:clrMap bg1="lt1" tx1="dk1" bg2="lt2" tx2="dk2" accent1="accent1" accent2="accent2" accent3="accent3" accent4="accent4" accent5="accent5" accent6="accent6" hlink="hlink" folHlink="folHlink"/>'
            . '<p:sldLayoutIdLst><p:sldLayoutId id="2147483649" r:id="rId1"/></p:sldLayoutIdLst>'
            . '<p:txStyles><p:titleStyle><a:lvl1pPr><a:defRPr sz="3200"/></a:lvl1pPr></p:titleStyle><p:bodyStyle><a:lvl1pPr><a:defRPr sz="1800"/></a:lvl1pPr></p:bodyStyle><p:otherStyle><a:lvl1pPr><a:defRPr sz="1800"/></a:lvl1pPr></p:otherStyle></p:txStyles></p:sldMaster>');
        $zip->addFromString('ppt/slideMasters/_rels/slideMaster1.xml.rels', $rel([
            ['rId1', 'officeDocument/2006/relationships/slideLayout', '../slideLayouts/slideLayout1.xml'],
            ['rId2', 'officeDocument/2006/relationships/theme', '../theme/theme1.xml'],
        ]));
        $zip->addFromString('ppt/slideLayouts/slideLayout1.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sldLayout ' . self::NS . ' type="blank" preserve="1">'
            . '<p:cSld name="Blank">' . $emptyTree . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sldLayout>');
        $zip->addFromString('ppt/slideLayouts/_rels/slideLayout1.xml.rels', $rel([['rId1', 'officeDocument/2006/relationships/slideMaster', '../slideMasters/slideMaster1.xml']]));
        $zip->addFromString('ppt/theme/theme1.xml', $this->theme());

        foreach ($this->slides as $i => $s) {
            $bg = $s['bg'] ? '<p:bg><p:bgPr><a:solidFill><a:srgbClr val="' . self::hex($s['bg']) . '"/></a:solidFill><a:effectLst/></p:bgPr></p:bg>' : '';
            $zip->addFromString('ppt/slides/slide' . ($i + 1) . '.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><p:sld ' . self::NS . '>'
                . '<p:cSld>' . $bg . $emptyTree . implode('', $s['shapes']) . '</p:spTree></p:cSld><p:clrMapOvr><a:masterClrMapping/></p:clrMapOvr></p:sld>');
            $rels = [['rId1', 'officeDocument/2006/relationships/slideLayout', '../slideLayouts/slideLayout1.xml']];
            foreach ($s['rels'] as $rid => $target) {
                $rels[] = [$rid, 'officeDocument/2006/relationships/image', $target];
            }
            $zip->addFromString('ppt/slides/_rels/slide' . ($i + 1) . '.xml.rels', $rel($rels));
        }
        foreach ($this->media as $name => $bytes) {
            $zip->addFromString('ppt/media/' . $name, $bytes);
        }
        $zip->close();
        $data = (string) file_get_contents($tmp);
        @unlink($tmp);
        return $data;
    }

    private function theme(): string
    {
        $f = self::x($this->font);
        $fill3 = '<a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill><a:solidFill><a:schemeClr val="phClr"/></a:solidFill>';
        $ln = '<a:ln w="9525"><a:solidFill><a:schemeClr val="phClr"/></a:solidFill></a:ln>';
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><a:theme xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" name="MixCreate"><a:themeElements>'
            . '<a:clrScheme name="MixCreate"><a:dk1><a:srgbClr val="15181E"/></a:dk1><a:lt1><a:srgbClr val="FFFFFF"/></a:lt1><a:dk2><a:srgbClr val="374151"/></a:dk2><a:lt2><a:srgbClr val="F4F5F8"/></a:lt2>'
            . '<a:accent1><a:srgbClr val="4F46E5"/></a:accent1><a:accent2><a:srgbClr val="0891B2"/></a:accent2><a:accent3><a:srgbClr val="059669"/></a:accent3><a:accent4><a:srgbClr val="CA8A04"/></a:accent4>'
            . '<a:accent5><a:srgbClr val="EA580C"/></a:accent5><a:accent6><a:srgbClr val="DB2777"/></a:accent6><a:hlink><a:srgbClr val="4F46E5"/></a:hlink><a:folHlink><a:srgbClr val="7C3AED"/></a:folHlink></a:clrScheme>'
            . '<a:fontScheme name="MixCreate"><a:majorFont><a:latin typeface="' . $f . '"/><a:ea typeface=""/><a:cs typeface=""/></a:majorFont><a:minorFont><a:latin typeface="' . $f . '"/><a:ea typeface=""/><a:cs typeface=""/></a:minorFont></a:fontScheme>'
            . '<a:fmtScheme name="MixCreate"><a:fillStyleLst>' . $fill3 . '</a:fillStyleLst><a:lnStyleLst>' . $ln . $ln . $ln . '</a:lnStyleLst>'
            . '<a:effectStyleLst><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle><a:effectStyle><a:effectLst/></a:effectStyle></a:effectStyleLst>'
            . '<a:bgFillStyleLst>' . $fill3 . '</a:bgFillStyleLst></a:fmtScheme></a:themeElements><a:objectDefaults/><a:extraClrSchemeLst/></a:theme>';
    }
}

}

namespace App\Providers {

// ---- Providers/Bluesky.php ----
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

    public static function supportsComments(): bool { return true; }

    public function comments(string $providerPostId): array
    {
        $r = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.feed.getPostThread', ['query' => ['uri' => $providerPostId, 'depth' => 1, 'parentHeight' => 0]]);
        $thread = $r['json']['thread'] ?? [];
        $root = $thread['post'] ?? null;
        $me = $this->accountData()['did'] ?? $this->account['provider_id'] ?? '';
        $out = [];
        foreach ($thread['replies'] ?? [] as $rep) {
            $p = $rep['post'] ?? null;
            if (!$p) {
                continue;
            }
            $a = $p['author'] ?? [];
            $rkey = substr($p['uri'], strrpos($p['uri'], '/') + 1);
            $out[] = [
                'id' => $p['uri'],
                'text' => $p['record']['text'] ?? '',
                'author_name' => ($a['displayName'] ?? '') ?: ($a['handle'] ?? ''),
                'author_handle' => $a['handle'] ?? '',
                'author_avatar' => $a['avatar'] ?? null,
                'created_at' => gmdate('Y-m-d H:i:s', strtotime($p['record']['createdAt'] ?? $p['indexedAt'] ?? 'now')),
                'url' => 'https://bsky.app/profile/' . ($a['handle'] ?? '') . '/post/' . $rkey,
                'reply_ref' => json_encode(['uri' => $p['uri'], 'cid' => $p['cid'], 'root_uri' => $root['uri'] ?? $p['uri'], 'root_cid' => $root['cid'] ?? $p['cid']]),
                'own' => ($a['did'] ?? '') === $me,
            ];
        }
        usort($out, fn($a, $b) => strcmp($a['created_at'], $b['created_at']));
        return $out;
    }

    public function reply(array $item, string $text): array
    {
        $ref = json_decode((string) $item['reply_ref'], true) ?: [];
        if (empty($ref['uri'])) {
            throw new ProviderException('Missing reply reference.');
        }
        $s = $this->session();
        $record = [
            '$type' => 'app.bsky.feed.post',
            'text' => $text,
            'createdAt' => gmdate('Y-m-d\TH:i:s.v\Z'),
            'reply' => ['root' => ['uri' => $ref['root_uri'], 'cid' => $ref['root_cid']], 'parent' => ['uri' => $ref['uri'], 'cid' => $ref['cid']]],
        ];
        if ($facets = $this->facets($text)) {
            $record['facets'] = $facets;
        }
        $res = $this->xrpc('POST', 'com.atproto.repo.createRecord', ['json' => ['repo' => $s['did'], 'collection' => 'app.bsky.feed.post', 'record' => $record]]);
        $rkey = substr($res['uri'], strrpos($res['uri'], '/') + 1);
        return ['id' => $res['uri'], 'url' => 'https://bsky.app/profile/' . $s['handle'] . '/post/' . $rkey];
    }

    public static function supportsFirstComment(): bool { return true; }

    public function firstComment(string $providerPostId, string $text): void
    {
        $p = Http::get(self::PUBLIC_API . '/xrpc/app.bsky.feed.getPosts', ['query' => ['uris' => $providerPostId]])['json']['posts'][0] ?? null;
        $cid = $p['cid'] ?? null;
        if (!$cid) {
            // Freshly created posts can take a moment to appear in the AppView; read the record from the PDS.
            $parts = explode('/', substr($providerPostId, 5));
            $rec = $this->xrpc('GET', 'com.atproto.repo.getRecord', ['query' => ['repo' => $parts[0], 'collection' => $parts[1], 'rkey' => $parts[2]]]);
            $cid = $rec['cid'] ?? null;
        }
        if (!$cid) {
            throw new ProviderException('Bluesky: couldn’t find the post to comment on.');
        }
        $ref = json_encode(['uri' => $providerPostId, 'cid' => $cid, 'root_uri' => $providerPostId, 'root_cid' => $cid]);
        $this->reply(['provider_item_id' => $providerPostId, 'reply_ref' => $ref], $text);
    }
}

}

namespace App\Providers {

// ---- Providers/Facebook.php ----
use App\Http;
use App\MediaTools;

/** Facebook Pages via the Graph API. Each page becomes its own account. */
final class Facebook extends OAuth2Provider
{
    public const VERSION = 'v26.0';
    private const GRAPH = 'https://graph.facebook.com/' . self::VERSION;

    public static function key(): string { return 'facebook'; }
    public static function name(): string { return 'Facebook Pages'; }
    public static function color(): string { return '#1877f2'; }
    public static function textLimit(): int { return 63206; }
    public static function implemented(): bool { return true; }

    public static function mediaRules(): array
    {
        return ['max_media' => 10, 'video' => true, 'requires_media' => false, 'requires_video' => false];
    }

    public static function credentialFields(): array
    {
        return ['app_id' => 'App ID', 'app_secret' => 'App Secret'];
    }

    public static function setupHint(): string
    {
        return 'At developers.facebook.com create a Business app, add Facebook Login for Business, and add the callback URL below as a valid OAuth redirect URI. Request pages_show_list, pages_read_engagement, pages_manage_posts and read_insights. Your server must be reachable from the internet so Facebook can fetch media.';
    }

    protected function authorizeEndpoint(): string { return 'https://www.facebook.com/' . self::VERSION . '/dialog/oauth'; }
    protected function tokenEndpoint(): string { return self::GRAPH . '/oauth/access_token'; }
    protected function scopes(): array { return ['pages_show_list', 'pages_read_engagement', 'pages_manage_posts', 'pages_manage_engagement', 'pages_read_user_content', 'read_insights', 'instagram_basic']; }
    protected function scopeSeparator(): string { return ','; }

    public function connect(array $input, string $redirectUri): array
    {
        $short = $this->exchangeCode($input, $redirectUri);
        // Page tokens fetched with a long-lived user token don't expire.
        $long = Http::get(self::GRAPH . '/oauth/access_token', ['query' => [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
            'fb_exchange_token' => $short['access_token'],
        ]]);
        $userToken = $long['json']['access_token'] ?? $short['access_token'];

        $pages = [];
        $url = self::GRAPH . '/me/accounts';
        $query = ['fields' => 'id,name,username,access_token,picture.type(large){url}', 'limit' => 100, 'access_token' => $userToken];
        while ($url) {
            $res = Http::get($url, ['query' => $query]);
            if (!Http::ok($res)) {
                throw new ProviderException('Facebook: ' . $this->errorMessage($res));
            }
            foreach ($res['json']['data'] ?? [] as $p) {
                $pages[] = [
                    'provider_id' => (string) $p['id'],
                    'name' => $p['name'],
                    'username' => $p['username'] ?? '',
                    'avatar' => $p['picture']['data']['url'] ?? null,
                    'token' => ['access_token' => $p['access_token']],
                    'data' => [],
                ];
            }
            $url = $res['json']['paging']['next'] ?? null;
            $query = [];
        }
        if (!$pages) {
            throw new ProviderException('No Facebook Pages were shared. Reconnect and select at least one Page.');
        }
        return $pages;
    }

    private function graph(string $method, string $path, array $params = [], string $host = self::GRAPH): array
    {
        $params['access_token'] = $this->accessToken();
        $res = Http::request($method, $host . $path, $method === 'GET' ? ['query' => $params] : ['form' => $params, 'timeout' => 300]);
        if (!Http::ok($res)) {
            $code = $res['json']['error']['code'] ?? 0;
            $msg = 'Facebook: ' . $this->errorMessage($res);
            throw in_array($code, [190, 102], true) ? ProviderException::unauthorized($msg) : new ProviderException($msg);
        }
        return $res['json'];
    }

    public function publish(string $text, array $media): array
    {
        $pageId = $this->account['provider_id'];
        if (($this->options['type'] ?? 'feed') === 'story') {
            return $this->publishStory($pageId, $media);
        }
        $videos = array_values(array_filter($media, [MediaTools::class, 'isVideo']));
        $images = array_values(array_filter($media, fn($m) => !MediaTools::isVideo($m)));

        if ($videos) {
            MediaTools::assertPublicUrl($videos[0]['url'], 'Facebook');
            $r = $this->graph('POST', "/$pageId/videos", ['file_url' => $videos[0]['url'], 'description' => $text], 'https://graph-video.facebook.com/' . self::VERSION);
            $id = (string) $r['id'];
            return ['id' => $id, 'url' => "https://www.facebook.com/$pageId/videos/$id"];
        }
        if (count($images) === 1) {
            MediaTools::assertPublicUrl($images[0]['url'], 'Facebook');
            $r = $this->graph('POST', "/$pageId/photos", ['url' => $images[0]['url'], 'caption' => $text]);
            $id = (string) ($r['post_id'] ?? $r['id']);
            return ['id' => $id, 'url' => 'https://www.facebook.com/' . $id];
        }
        $params = ['message' => $text];
        foreach ($images as $i => $img) {
            MediaTools::assertPublicUrl($img['url'], 'Facebook');
            $photo = $this->graph('POST', "/$pageId/photos", ['url' => $img['url'], 'published' => 'false']);
            $params["attached_media[$i]"] = json_encode(['media_fbid' => $photo['id']]);
        }
        $r = $this->graph('POST', "/$pageId/feed", $params);
        $id = (string) $r['id'];
        return ['id' => $id, 'url' => 'https://www.facebook.com/' . $id];
    }

    private function publishStory(string $pageId, array $media): array
    {
        if (!$media) {
            throw new ProviderException('Facebook stories need an image or video.');
        }
        $m = $media[0];
        MediaTools::assertPublicUrl($m['url'], 'Facebook');
        if (MediaTools::isVideo($m)) {
            $start = $this->graph('POST', "/$pageId/video_stories", ['upload_phase' => 'start']);
            $up = Http::post($start['upload_url'], ['headers' => ['Authorization' => 'OAuth ' . $this->accessToken(), 'file_url' => $m['url']], 'timeout' => 300]);
            if (!Http::ok($up)) {
                throw new ProviderException('Facebook story upload failed: ' . $this->errorMessage($up));
            }
            $r = $this->graph('POST', "/$pageId/video_stories", ['upload_phase' => 'finish', 'video_id' => $start['video_id']]);
            return ['id' => (string) ($r['post_id'] ?? $start['video_id']), 'url' => null];
        }
        $photo = $this->graph('POST', "/$pageId/photos", ['url' => $m['url'], 'published' => 'false']);
        $r = $this->graph('POST', "/$pageId/photo_stories", ['photo_id' => $photo['id']]);
        return ['id' => (string) ($r['post_id'] ?? $photo['id']), 'url' => null];
    }

    public function accountMetrics(): array
    {
        $p = $this->graph('GET', '/' . $this->account['provider_id'], ['fields' => 'followers_count,fan_count']);
        return ['followers' => (int) ($p['followers_count'] ?? $p['fan_count'] ?? 0)];
    }

    public function postMetrics(string $providerPostId): array
    {
        $p = $this->graph('GET', '/' . $providerPostId, ['fields' => 'reactions.summary(total_count).limit(0),comments.summary(total_count).limit(0),shares']);
        return [
            'likes' => (int) ($p['reactions']['summary']['total_count'] ?? 0),
            'replies' => (int) ($p['comments']['summary']['total_count'] ?? 0),
            'shares' => (int) ($p['shares']['count'] ?? 0),
        ];
    }

    public static function supportsComments(): bool { return true; }

    public function comments(string $providerPostId): array
    {
        $r = $this->graph('GET', '/' . $providerPostId . '/comments', ['fields' => 'id,message,from{id,name,picture},created_time,permalink_url', 'filter' => 'stream', 'limit' => 100]);
        $me = $this->account['provider_id'] ?? '';
        $out = [];
        foreach ($r['data'] ?? [] as $c) {
            $out[] = [
                'id' => $c['id'],
                'text' => $c['message'] ?? '',
                'author_name' => $c['from']['name'] ?? 'Facebook user',
                'author_handle' => '',
                'author_avatar' => $c['from']['picture']['data']['url'] ?? null,
                'created_at' => gmdate('Y-m-d H:i:s', strtotime($c['created_time'] ?? 'now')),
                'url' => $c['permalink_url'] ?? null,
                'reply_ref' => $c['id'],
                'own' => ($c['from']['id'] ?? '') === $me,
            ];
        }
        return $out;
    }

    public function reply(array $item, string $text): array
    {
        $r = $this->graph('POST', '/' . $item['provider_item_id'] . '/comments', ['message' => $text]);
        return ['id' => (string) $r['id'], 'url' => null];
    }

    public static function supportsFirstComment(): bool { return true; }

    public function firstComment(string $providerPostId, string $text): void
    {
        $this->graph('POST', '/' . $providerPostId . '/comments', ['message' => $text]);
    }

    public static function postTypes(): array
    {
        return ['feed' => 'Post', 'story' => 'Story'];
    }
}

}

namespace App\Providers {

// ---- Providers/GoogleBusiness.php ----
use App\MediaTools;

/** Google Business Profile local posts. Each location is connected as its own account. */
final class GoogleBusiness extends OAuth2Provider
{
    public static function key(): string { return 'google_business'; }
    public static function name(): string { return 'Google Business Profile'; }
    public static function color(): string { return '#4285f4'; }
    public static function textLimit(): int { return 1500; }
    public static function implemented(): bool { return true; }

    public static function mediaRules(): array
    {
        return ['max_media' => 1, 'video' => false, 'requires_media' => false, 'requires_video' => false];
    }

    public static function credentialFields(): array
    {
        return ['client_id' => 'Google OAuth Client ID', 'client_secret' => 'Google OAuth Client Secret'];
    }

    public static function setupHint(): string
    {
        return 'Request Business Profile API access from Google, then in Google Cloud Console enable the My Business Account Management, Business Information and Google My Business APIs, create an OAuth web client and add the callback URL below. Each location becomes its own account. Your server must be reachable from the internet so Google can fetch images.';
    }

    protected function authorizeEndpoint(): string { return 'https://accounts.google.com/o/oauth2/v2/auth'; }
    protected function tokenEndpoint(): string { return 'https://oauth2.googleapis.com/token'; }
    protected function scopes(): array { return ['https://www.googleapis.com/auth/business.manage']; }
    protected function extraAuthParams(): array { return ['access_type' => 'offline', 'prompt' => 'consent']; }

    public function connect(array $input, string $redirectUri): array
    {
        $token = $this->exchangeCode($input, $redirectUri);
        $this->account = ['token' => $token];
        $accounts = $this->api('GET', 'https://mybusinessaccountmanagement.googleapis.com/v1/accounts')['json']['accounts'] ?? [];
        $out = [];
        foreach ($accounts as $acct) {
            $pageToken = null;
            do {
                $r = $this->api('GET', 'https://mybusinessbusinessinformation.googleapis.com/v1/' . $acct['name'] . '/locations', ['query' => array_filter([
                    'readMask' => 'name,title,storefrontAddress',
                    'pageSize' => 100,
                    'pageToken' => $pageToken,
                ])])['json'];
                foreach ($r['locations'] ?? [] as $loc) {
                    $address = $loc['storefrontAddress']['locality'] ?? ($loc['storefrontAddress']['addressLines'][0] ?? '');
                    $out[] = [
                        'provider_id' => $loc['name'],
                        'name' => $loc['title'] ?? 'Location',
                        'username' => $address,
                        'avatar' => null,
                        'token' => $token,
                        // v4 local posts need "accounts/{a}/locations/{l}".
                        'data' => ['parent' => $acct['name'] . '/' . $loc['name']],
                    ];
                }
                $pageToken = $r['nextPageToken'] ?? null;
            } while ($pageToken);
        }
        if (!$out) {
            throw new ProviderException('No Business Profile locations were found for this Google account.');
        }
        return $out;
    }

    public function publish(string $text, array $media): array
    {
        $parent = $this->accountData()['parent'] ?? '';
        $body = [
            'languageCode' => 'en',
            'summary' => $text,
            'topicType' => 'STANDARD',
        ];
        if (preg_match('#https?://\S+#u', $text, $m)) {
            $body['callToAction'] = ['actionType' => 'LEARN_MORE', 'url' => rtrim($m[0], '.,;:!?)')];
        }
        $images = array_values(array_filter($media, fn($m) => !MediaTools::isVideo($m)));
        if ($images) {
            $img = MediaTools::jpeg($images[0], 5 * 1024 * 1024);
            MediaTools::assertPublicUrl($img['url'], 'Google');
            $body['media'] = [['mediaFormat' => 'PHOTO', 'sourceUrl' => $img['url']]];
        }
        $post = $this->api('POST', 'https://mybusiness.googleapis.com/v4/' . $parent . '/localPosts', ['json' => $body])['json'];
        return ['id' => (string) ($post['name'] ?? ''), 'url' => $post['searchUrl'] ?? null];
    }
}

}

namespace App\Providers {

// ---- Providers/Instagram.php ----
use App\Http;
use App\MediaTools;

/** Instagram professional accounts via the Instagram API with Instagram Login. */
final class Instagram extends OAuth2Provider
{
    private const GRAPH = 'https://graph.instagram.com/' . Facebook::VERSION;

    public static function key(): string { return 'instagram'; }
    public static function name(): string { return 'Instagram'; }
    public static function color(): string { return '#e4405f'; }
    public static function textLimit(): int { return 2200; }
    public static function implemented(): bool { return true; }

    public static function mediaRules(): array
    {
        return ['max_media' => 10, 'video' => true, 'requires_media' => true, 'requires_video' => false];
    }

    public static function credentialFields(): array
    {
        return ['app_id' => 'Instagram App ID', 'app_secret' => 'Instagram App Secret'];
    }

    public static function setupHint(): string
    {
        return 'In your Meta app add the Instagram product (“API setup with Instagram login”), copy the Instagram app ID and secret, and add the callback URL below under Business login settings. Only professional (business or creator) accounts can publish. Your server must be reachable from the internet so Instagram can fetch media.';
    }

    protected function authorizeEndpoint(): string { return 'https://www.instagram.com/oauth/authorize'; }
    protected function tokenEndpoint(): string { return 'https://api.instagram.com/oauth/access_token'; }
    protected function scopes(): array { return ['instagram_business_basic', 'instagram_business_content_publish', 'instagram_business_manage_insights', 'instagram_business_manage_comments']; }
    protected function scopeSeparator(): string { return ','; }

    public function connect(array $input, string $redirectUri): array
    {
        $short = $this->exchangeCode($input, $redirectUri);
        $long = Http::get('https://graph.instagram.com/access_token', ['query' => [
            'grant_type' => 'ig_exchange_token',
            'client_secret' => $this->clientSecret(),
            'access_token' => $short['access_token'],
        ]]);
        if (!Http::ok($long) || empty($long['json']['access_token'])) {
            throw new ProviderException('Instagram: ' . $this->errorMessage($long));
        }
        $token = ['access_token' => $long['json']['access_token'], 'expires_at' => time() + (int) ($long['json']['expires_in'] ?? 5184000)];
        $this->account = ['token' => $token];
        $me = $this->graph('GET', '/me', ['fields' => 'user_id,username,name,profile_picture_url,account_type']);
        return [[
            'provider_id' => (string) ($me['user_id'] ?? $me['id']),
            'name' => ($me['name'] ?? '') !== '' ? $me['name'] : $me['username'],
            'username' => $me['username'] ?? '',
            'avatar' => $me['profile_picture_url'] ?? null,
            'token' => $token,
            'data' => ['account_type' => $me['account_type'] ?? null],
        ]];
    }

    /** Long-lived Instagram tokens last 60 days and are refreshed with the token itself. */
    public function refreshToken(): ?array
    {
        $token = $this->token();
        if (empty($token['expires_at']) || $token['expires_at'] > time() + 7 * 86400) {
            return null;
        }
        if ($token['expires_at'] < time()) {
            throw ProviderException::unauthorized('Instagram access expired. Reconnect the account.');
        }
        $r = Http::get('https://graph.instagram.com/refresh_access_token', ['query' => ['grant_type' => 'ig_refresh_token', 'access_token' => $token['access_token']]]);
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
            $msg = 'Instagram: ' . $this->errorMessage($res);
            throw $code === 190 ? ProviderException::unauthorized($msg) : new ProviderException($msg);
        }
        return $res['json'];
    }

    public function publish(string $text, array $media): array
    {
        $igId = $this->account['provider_id'];
        if (($this->options['type'] ?? 'feed') === 'story') {
            return $this->publishStory($igId, $media);
        }
        $media = array_slice($media, 0, 10);
        if (!$media) {
            throw new ProviderException('Instagram posts need an image or video.');
        }
        foreach ($media as &$m) {
            $m = MediaTools::jpeg($m, 8 * 1024 * 1024, 1440); // Instagram only accepts JPEG images.
            MediaTools::assertPublicUrl($m['url'], 'Instagram');
        }
        unset($m);

        if (count($media) === 1) {
            $container = $this->container($igId, $media[0], ['caption' => $text]);
        } else {
            $children = array_map(fn($m) => $this->container($igId, $m, ['is_carousel_item' => 'true']), $media);
            foreach ($children as $c) {
                $this->waitReady($c);
            }
            $container = (string) $this->graph('POST', "/$igId/media", ['media_type' => 'CAROUSEL', 'children' => implode(',', $children), 'caption' => $text])['id'];
        }
        $this->waitReady($container);
        $id = (string) $this->graph('POST', "/$igId/media_publish", ['creation_id' => $container])['id'];
        $permalink = null;
        try {
            $permalink = $this->graph('GET', "/$id", ['fields' => 'permalink'])['permalink'] ?? null;
        } catch (ProviderException) {
        }
        return ['id' => $id, 'url' => $permalink];
    }

    /** Stories take one image or video and no caption. */
    private function publishStory(string $igId, array $media): array
    {
        if (!$media) {
            throw new ProviderException('Instagram stories need an image or video.');
        }
        $m = MediaTools::jpeg($media[0], 8 * 1024 * 1024, 1920);
        MediaTools::assertPublicUrl($m['url'], 'Instagram');
        $params = ['media_type' => 'STORIES'] + (MediaTools::isVideo($m) ? ['video_url' => $m['url']] : ['image_url' => $m['url']]);
        $container = (string) $this->graph('POST', "/$igId/media", $params)['id'];
        $this->waitReady($container);
        $id = (string) $this->graph('POST', "/$igId/media_publish", ['creation_id' => $container])['id'];
        return ['id' => $id, 'url' => null];
    }

    private function container(string $igId, array $m, array $extra): string
    {
        if (MediaTools::isVideo($m)) {
            // Single videos post as Reels; videos inside a carousel use VIDEO.
            $params = ['media_type' => isset($extra['is_carousel_item']) ? 'VIDEO' : 'REELS', 'video_url' => $m['url']];
        } else {
            $params = ['image_url' => $m['url']];
        }
        return (string) $this->graph('POST', "/$igId/media", $params + $extra)['id'];
    }

    private function waitReady(string $containerId): void
    {
        $this->poll(function () use ($containerId) {
            $s = $this->graph('GET', "/$containerId", ['fields' => 'status_code,status']);
            $code = $s['status_code'] ?? 'FINISHED';
            if ($code === 'ERROR' || $code === 'EXPIRED') {
                throw new ProviderException('Instagram couldn’t process the media: ' . ($s['status'] ?? $code));
            }
            return $code === 'FINISHED' || $code === 'PUBLISHED' ? true : null;
        }, 300, 5);
    }

    public function accountMetrics(): array
    {
        $me = $this->graph('GET', '/me', ['fields' => 'followers_count,follows_count,media_count']);
        return isset($me['followers_count']) ? ['followers' => (int) $me['followers_count'], 'following' => (int) ($me['follows_count'] ?? 0)] : [];
    }

    public function audience(): array
    {
        $out = [];
        foreach (['age', 'gender', 'country', 'city'] as $dim) {
            try {
                $r = $this->graph('GET', '/me/insights', ['metric' => 'follower_demographics', 'period' => 'lifetime', 'metric_type' => 'total_value', 'breakdown' => $dim]);
            } catch (ProviderException) {
                continue; // needs 100+ followers and the insights permission
            }
            foreach ($r['data'][0]['total_value']['breakdowns'][0]['results'] ?? [] as $row) {
                $out[$dim][(string) ($row['dimension_values'][0] ?? '?')] = (int) ($row['value'] ?? 0);
            }
        }
        return $out;
    }

    public function postMetrics(string $providerPostId): array
    {
        $m = $this->graph('GET', '/' . $providerPostId, ['fields' => 'like_count,comments_count']);
        $out = ['likes' => (int) ($m['like_count'] ?? 0), 'replies' => (int) ($m['comments_count'] ?? 0)];
        try {
            $ins = $this->graph('GET', '/' . $providerPostId . '/insights', ['metric' => 'views,shares,saved']);
            foreach ($ins['data'] ?? [] as $row) {
                $v = (int) ($row['values'][0]['value'] ?? $row['total_value']['value'] ?? 0);
                match ($row['name']) {
                    'views' => $out['impressions'] = $v,
                    'shares' => $out['shares'] = $v,
                    'saved' => $out['saves'] = $v,
                    default => null,
                };
            }
        } catch (ProviderException) {
        }
        return $out;
    }

    public static function supportsComments(): bool { return true; }

    public function comments(string $providerPostId): array
    {
        $r = $this->graph('GET', '/' . $providerPostId . '/comments', ['fields' => 'id,text,username,timestamp,from{id,username},replies{id,text,username,timestamp,from{id}}', 'limit' => 50]);
        $me = $this->account['provider_id'] ?? '';
        $mine = $this->account['username'] ?? '';
        $out = [];
        $add = function (array $c) use (&$out, $me, $mine) {
            $out[] = [
                'id' => $c['id'],
                'text' => $c['text'] ?? '',
                'author_name' => $c['username'] ?? '',
                'author_handle' => $c['username'] ?? '',
                'author_avatar' => null,
                'created_at' => gmdate('Y-m-d H:i:s', strtotime($c['timestamp'] ?? 'now')),
                'url' => null,
                'reply_ref' => '@' . ($c['username'] ?? ''),
                'own' => ($c['from']['id'] ?? '') === $me || ($mine !== '' && ($c['username'] ?? '') === $mine),
            ];
        };
        foreach ($r['data'] ?? [] as $c) {
            $add($c);
            foreach ($c['replies']['data'] ?? [] as $rep) {
                $add($rep);
            }
        }
        return $out;
    }

    public function reply(array $item, string $text): array
    {
        $r = $this->graph('POST', '/' . $item['provider_item_id'] . '/replies', ['message' => $text]);
        return ['id' => (string) $r['id'], 'url' => null];
    }

    public static function supportsFirstComment(): bool { return true; }

    public function firstComment(string $providerPostId, string $text): void
    {
        $this->graph('POST', '/' . $providerPostId . '/comments', ['message' => $text]);
    }

    public static function postTypes(): array
    {
        return ['feed' => 'Post / Reel', 'story' => 'Story'];
    }
}

}

namespace App\Providers {

// ---- Providers/LinkedIn.php ----
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

}

namespace App\Providers {

// ---- Providers/Mastodon.php ----
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

    public static function supportsFirstComment(): bool { return true; }

    public function firstComment(string $providerPostId, string $text): void
    {
        $this->api('POST', $this->base() . '/api/v1/statuses', ['json' => ['status' => $text, 'in_reply_to_id' => $providerPostId, 'visibility' => 'public']]);
    }
}

}

namespace App\Providers {

// ---- Providers/Pinterest.php ----
use App\MediaTools;

/** Pinterest API v5. Each board is connected as its own account. */
final class Pinterest extends OAuth2Provider
{
    private const API = 'https://api.pinterest.com/v5';

    public static function key(): string { return 'pinterest'; }
    public static function name(): string { return 'Pinterest'; }
    public static function color(): string { return '#e60023'; }
    public static function textLimit(): int { return 500; }
    public static function implemented(): bool { return true; }

    public static function mediaRules(): array
    {
        return ['max_media' => 1, 'video' => false, 'requires_media' => true, 'requires_video' => false];
    }

    public static function credentialFields(): array
    {
        return ['app_id' => 'App ID', 'app_secret' => 'App Secret'];
    }

    public static function setupHint(): string
    {
        return 'At developers.pinterest.com create an app, request boards:read, pins:read, pins:write and user_accounts:read, and add the callback URL below. Each board you pick becomes its own account. The first line of a post becomes the pin title; a link anywhere in the text becomes the pin’s destination.';
    }

    protected function authorizeEndpoint(): string { return 'https://www.pinterest.com/oauth/'; }
    protected function tokenEndpoint(): string { return self::API . '/oauth/token'; }
    protected function scopes(): array { return ['boards:read', 'pins:read', 'pins:write', 'user_accounts:read']; }
    protected function scopeSeparator(): string { return ','; }
    protected function basicAuth(): bool { return true; }

    public function connect(array $input, string $redirectUri): array
    {
        $token = $this->exchangeCode($input, $redirectUri);
        $this->account = ['token' => $token];
        $user = $this->api('GET', self::API . '/user_account')['json'];
        $boards = [];
        $bookmark = null;
        do {
            $r = $this->api('GET', self::API . '/boards', ['query' => array_filter(['page_size' => 100, 'bookmark' => $bookmark])])['json'];
            foreach ($r['items'] ?? [] as $b) {
                $boards[] = [
                    'provider_id' => (string) $b['id'],
                    'name' => $b['name'],
                    'username' => $user['username'] ?? '',
                    'avatar' => $user['profile_image'] ?? null,
                    'token' => $token,
                    'data' => ['board_id' => (string) $b['id']],
                ];
            }
            $bookmark = $r['bookmark'] ?? null;
        } while ($bookmark);
        if (!$boards) {
            throw new ProviderException('This Pinterest account has no boards. Create one first.');
        }
        return $boards;
    }

    public function publish(string $text, array $media): array
    {
        $images = array_values(array_filter($media, fn($m) => !MediaTools::isVideo($m)));
        if (!$images) {
            throw new ProviderException('Pinterest pins need an image.');
        }
        $img = MediaTools::fit($images[0], 20 * 1024 * 1024);
        [$title, $description] = self::splitTitle($text, 100);
        $link = preg_match('#https?://\S+#u', $text, $m) ? rtrim($m[0], '.,;:!?)') : null;

        $source = ['source_type' => 'image_base64', 'content_type' => $img['mime'] === 'image/png' ? 'image/png' : 'image/jpeg', 'data' => base64_encode((string) file_get_contents($img['path']))];
        if (!in_array($img['mime'], ['image/png', 'image/jpeg'], true)) {
            $img = MediaTools::jpeg($img, 20 * 1024 * 1024);
            $source = ['source_type' => 'image_base64', 'content_type' => 'image/jpeg', 'data' => base64_encode((string) file_get_contents($img['path']))];
        }
        $body = array_filter([
            'board_id' => $this->accountData()['board_id'] ?? $this->account['provider_id'],
            'title' => $title,
            'description' => mb_substr($description !== '' ? $description : $text, 0, 500),
            'link' => $link,
            'media_source' => $source,
        ], fn($v) => $v !== null && $v !== '');
        $pin = $this->api('POST', self::API . '/pins', ['json' => $body, 'timeout' => 120])['json'];
        return ['id' => (string) $pin['id'], 'url' => 'https://www.pinterest.com/pin/' . $pin['id'] . '/'];
    }

    public function accountMetrics(): array
    {
        $u = $this->api('GET', self::API . '/user_account')['json'];
        return isset($u['follower_count']) ? ['followers' => (int) $u['follower_count']] : [];
    }

    public function postMetrics(string $providerPostId): array
    {
        $r = $this->api('GET', self::API . '/pins/' . rawurlencode($providerPostId), ['query' => ['pin_metrics' => 'true']])['json'];
        $lt = $r['pin_metrics']['lifetime_metrics'] ?? $r['pin_metrics']['all_time'] ?? [];
        return $lt ? [
            'saves' => (int) ($lt['save'] ?? 0),
            'clicks' => (int) ($lt['pin_click'] ?? 0),
            'impressions' => (int) ($lt['impression'] ?? 0),
            'replies' => (int) ($lt['comment'] ?? 0),
            'likes' => (int) ($lt['reaction'] ?? 0),
        ] : [];
    }
}

}

namespace App\Providers {

// ---- Providers/Registry.php ----
use App\Config;
use App\Crypt;
use App\Db;
use App\Hooks;

final class Registry
{
    private const CORE = [
        X::class,
        Mastodon::class,
        Bluesky::class,
        Facebook::class,
        Instagram::class,
        LinkedIn::class,
        YouTube::class,
        Threads::class,
        TikTok::class,
        Pinterest::class,
        GoogleBusiness::class,
    ];

    /** @return array<string, class-string<Provider>> keyed by provider key */
    public static function all(): array
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }
        $classes = self::CORE;
        if (Config::get('debug')) {
            $classes[] = Sandbox::class;
        }
        $classes = Hooks::filter('providers', $classes);
        $map = [];
        foreach ($classes as $class) {
            $map[$class::key()] = $class;
        }
        return $map;
    }

    /** @return class-string<Provider>|null */
    public static function class(string $key): ?string
    {
        return self::all()[$key] ?? null;
    }

    public static function credentials(string $key): array
    {
        $row = Db::one('SELECT credentials FROM services WHERE provider = ?', [$key]);
        return Crypt::decryptArray($row['credentials'] ?? null);
    }

    /** Whether the provider can be used: built, and its API keys are saved if it needs any. */
    public static function ready(string $key): bool
    {
        $class = self::class($key);
        if (!$class || !$class::implemented()) {
            return false;
        }
        $fields = $class::credentialFields();
        if (!$fields) {
            return true;
        }
        $creds = self::credentials($key);
        foreach (array_keys($fields) as $f) {
            if (empty($creds[$f])) {
                return false;
            }
        }
        return true;
    }

    /** Builds a provider for a stored account row. */
    public static function forAccount(array $account): Provider
    {
        $class = self::class($account['provider']);
        if (!$class) {
            throw new ProviderException('Unknown platform: ' . $account['provider']);
        }
        $account['token'] = Crypt::decryptArray($account['token'] ?? null);
        return new $class(self::credentials($account['provider']), $account);
    }

    /**
     * Builds a provider for an account and refreshes its token if it's about to expire,
     * saving the new token to this account and to sibling accounts that shared the old one
     * (pages, boards, channels and locations connected in a single login).
     */
    public static function withFreshToken(array $account): Provider
    {
        $provider = self::forAccount($account);
        $old = Crypt::decryptArray($account['token'] ?? null);
        $new = $provider->refreshToken();
        if ($new !== null) {
            $enc = Crypt::encrypt($new);
            Db::update('accounts', ['token' => $enc, 'authorized' => 1, 'updated_at' => now()], 'id = ?', [$account['id']]);
            if (!empty($old['refresh_token']) || !empty($old['access_token'])) {
                $siblings = Db::all(
                    'SELECT id, token FROM accounts WHERE workspace_id = (SELECT workspace_id FROM accounts WHERE id = ?) AND provider = ? AND id != ?',
                    [$account['id'], $account['provider'], $account['id']]
                );
                foreach ($siblings as $sib) {
                    $t = Crypt::decryptArray($sib['token']);
                    $same = (!empty($old['refresh_token']) && ($t['refresh_token'] ?? null) === $old['refresh_token'])
                        || (($t['access_token'] ?? null) === ($old['access_token'] ?? false));
                    if ($same) {
                        Db::update('accounts', ['token' => $enc, 'updated_at' => now()], 'id = ?', [$sib['id']]);
                    }
                }
            }
        }
        return $provider;
    }

    public static function make(string $key): Provider
    {
        $class = self::class($key);
        if (!$class) {
            throw new ProviderException('Unknown platform: ' . $key);
        }
        return new $class(self::credentials($key));
    }
}

}

namespace App\Providers {

// ---- Providers/Sandbox.php ----
/** Fake platform for testing the scheduler without real APIs. Only available when debug is on. */
final class Sandbox extends Provider
{
    public static function key(): string { return 'sandbox'; }
    public static function name(): string { return 'Sandbox'; }
    public static function color(): string { return '#0d9488'; }
    public static function textLimit(): int { return 500; }
    public static function implemented(): bool { return true; }

    public static function usesOAuth(): bool { return false; }

    public static function connectFields(): array
    {
        return [
            'name' => ['label' => 'Account name', 'type' => 'text', 'placeholder' => 'Test account'],
            'fail' => ['label' => 'Fail every publish (to test errors)', 'type' => 'checkbox'],
        ];
    }

    public function connect(array $input, string $redirectUri): array
    {
        $name = trim((string) ($input['name'] ?? '')) ?: 'Test account';
        return [[
            'provider_id' => 'sandbox-' . bin2hex(random_bytes(4)),
            'name' => $name,
            'username' => strtolower(preg_replace('/\W+/', '', $name)) ?: 'test',
            'avatar' => null,
            'token' => ['fake' => true],
            'data' => ['fail' => !empty($input['fail'])],
        ]];
    }

    public function publish(string $text, array $media): array
    {
        if (!empty($this->accountData()['fail'])) {
            throw new ProviderException('Sandbox account is set to fail.');
        }
        $id = bin2hex(random_bytes(6));
        log_event('sandbox', sprintf('@%s %s | media: %d', $this->account['username'] ?? '', str_replace("\n", ' ', $text), count($media)));
        return ['id' => $id, 'url' => null];
    }

    public function accountMetrics(): array
    {
        return ['followers' => 100 + crc32((string) ($this->account['id'] ?? 0) . date('Y-m-d')) % 50];
    }

    public function audience(): array
    {
        return [
            'age' => ['18-24' => 20, '25-34' => 45, '35-44' => 25, '45-54' => 10],
            'gender' => ['F' => 55, 'M' => 42, 'U' => 3],
            'country' => ['US' => 50, 'GB' => 15, 'CA' => 10, 'DE' => 8],
        ];
    }

    public function postMetrics(string $providerPostId): array
    {
        $n = crc32($providerPostId);
        return ['likes' => $n % 40, 'replies' => $n % 7, 'shares' => $n % 11];
    }

    public static function supportsComments(): bool { return true; }

    public function comments(string $providerPostId): array
    {
        $n = crc32($providerPostId) % 3 + 1;
        $out = [];
        for ($i = 1; $i <= $n; $i++) {
            $out[] = [
                'id' => $providerPostId . '-c' . $i,
                'text' => ['Love this!', 'Where can I learn more?', 'Great post 👏'][$i - 1],
                'author_name' => ['Sam Lee', 'Priya N', 'Jo Kim'][$i - 1],
                'author_handle' => ['samlee', 'priyan', 'jokim'][$i - 1],
                'author_avatar' => null,
                'created_at' => gmdate('Y-m-d H:i:s', time() - (10 - $i) * 60),
                'url' => null,
                'reply_ref' => '',
                'own' => false,
            ];
        }
        return $out;
    }

    public function reply(array $item, string $text): array
    {
        log_event('sandbox', sprintf('reply to %s: %s', $item['provider_item_id'], $text));
        return ['id' => 'r-' . bin2hex(random_bytes(4)), 'url' => null];
    }

    public static function supportsFirstComment(): bool { return true; }

    public function firstComment(string $providerPostId, string $text): void
    {
        log_event('sandbox', sprintf('first comment on %s: %s', $providerPostId, $text));
    }

    public static function postTypes(): array
    {
        return ['feed' => 'Post', 'story' => 'Story'];
    }
}

}

namespace App\Providers {

// ---- Providers/Threads.php ----
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

}

namespace App\Providers {

// ---- Providers/TikTok.php ----
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

}

namespace App\Providers {

// ---- Providers/X.php ----
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

    public static function supportsComments(): bool { return true; }

    public function comments(string $providerPostId): array
    {
        $r = $this->api('GET', self::API . '/tweets/search/recent', ['query' => [
            'query' => 'conversation_id:' . $providerPostId . ' is:reply',
            'max_results' => 50,
            'tweet.fields' => 'author_id,created_at,in_reply_to_user_id',
            'expansions' => 'author_id',
            'user.fields' => 'username,name,profile_image_url',
        ]])['json'];
        $users = array_column($r['includes']['users'] ?? [], null, 'id');
        $me = $this->account['provider_id'] ?? '';
        $out = [];
        foreach ($r['data'] ?? [] as $t) {
            $u = $users[$t['author_id']] ?? [];
            $out[] = [
                'id' => $t['id'],
                'text' => $t['text'],
                'author_name' => $u['name'] ?? '',
                'author_handle' => $u['username'] ?? '',
                'author_avatar' => $u['profile_image_url'] ?? null,
                'created_at' => gmdate('Y-m-d H:i:s', strtotime($t['created_at'] ?? 'now')),
                'url' => isset($u['username']) ? "https://x.com/{$u['username']}/status/{$t['id']}" : null,
                'reply_ref' => $t['id'],
                'own' => $t['author_id'] === $me,
            ];
        }
        return array_reverse($out);
    }

    public function reply(array $item, string $text): array
    {
        $res = $this->api('POST', self::API . '/tweets', ['json' => ['text' => $text, 'reply' => ['in_reply_to_tweet_id' => $item['provider_item_id']]]]);
        $id = (string) ($res['json']['data']['id'] ?? '');
        return ['id' => $id, 'url' => $id ? 'https://x.com/' . ($this->account['username'] ?? 'i') . '/status/' . $id : null];
    }

    public static function supportsFirstComment(): bool { return true; }

    public function firstComment(string $providerPostId, string $text): void
    {
        $this->api('POST', self::API . '/tweets', ['json' => ['text' => $text, 'reply' => ['in_reply_to_tweet_id' => $providerPostId]]]);
    }
}

}

namespace App\Providers {

// ---- Providers/YouTube.php ----
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

    public static function supportsFirstComment(): bool { return true; }

    public function firstComment(string $providerPostId, string $text): void
    {
        $this->api('POST', self::API . '/commentThreads', ['query' => ['part' => 'snippet'], 'json' => ['snippet' => [
            'videoId' => $providerPostId,
            'topLevelComment' => ['snippet' => ['textOriginal' => $text]],
        ]]]);
    }
}

}

namespace App {

// ---- Publisher.php ----
use App\Providers\ProviderException;
use App\Providers\Registry;

final class Publisher
{
    /** Publishes every scheduled post that is due. Returns a short report for cron output. */
    public static function runDue(int $limit = 25): array
    {
        // Recover posts stuck in "publishing" (e.g. the process died mid-run).
        Db::query(
            "UPDATE posts SET status = 'failed', updated_at = ? WHERE status = 'publishing' AND updated_at < ?",
            [now(), gmdate('Y-m-d H:i:s', time() - 900)]
        );

        $due = Db::all(
            "SELECT id FROM posts WHERE status = 'scheduled' AND scheduled_at <= ? ORDER BY scheduled_at LIMIT $limit",
            [now()]
        );
        $report = ['published' => 0, 'failed' => 0];
        foreach ($due as $row) {
            $status = self::publish((int) $row['id']);
            if ($status === 'published') {
                $report['published']++;
            } elseif ($status === 'failed') {
                $report['failed']++;
            }
        }
        return $report;
    }

    /**
     * Publishes one post to all its accounts. Only accounts not yet published are attempted,
     * so retrying a partly failed post doesn't double-post.
     * Returns the final post status, or null if another process claimed it first.
     */
    public static function publish(int $postId): ?string
    {
        $claimed = Db::query(
            "UPDATE posts SET status = 'publishing', updated_at = ? WHERE id = ? AND status = 'scheduled'",
            [now(), $postId]
        )->rowCount();
        if ($claimed !== 1) {
            return null;
        }

        $post = Db::one('SELECT * FROM posts WHERE id = ?', [$postId]);
        $media = self::resolveMedia(json_arr($post['media']), true);
        $targets = Db::all(
            "SELECT pa.*, a.provider, a.provider_id, a.name, a.username, a.token, a.data, a.authorized, a.workspace_id
             FROM post_accounts pa JOIN accounts a ON a.id = pa.account_id
             WHERE pa.post_id = ?",
            [$postId]
        );

        $results = [];
        foreach ($targets as $t) {
            if ($t['status'] === 'published') {
                $results[$t['account_id']] = ['ok' => true, 'skipped' => true];
                continue;
            }
            $results[$t['account_id']] = self::publishToAccount($post, $t, $media);
        }

        $failed = array_filter($results, fn($r) => !$r['ok']);
        $status = !$targets ? 'failed' : ($failed ? 'failed' : 'published');
        Db::update('posts', [
            'status' => $status,
            'published_at' => $status === 'published' ? now() : $post['published_at'],
            'updated_at' => now(),
        ], 'id = ?', [$postId]);

        $post = Db::one('SELECT * FROM posts WHERE id = ?', [$postId]);
        Hooks::fire($status === 'published' ? 'post.published' : 'post.failed', $post, $results);
        Webhooks::dispatch((int) $post['workspace_id'], 'post.' . $status, ['post' => PostService::toArray($post)]);
        $excerpt = str_limit($post['content'] !== '' ? $post['content'] : '(media)', 120);
        if ($status === 'failed') {
            $why = current(array_filter(array_column($results, 'error'))) ?: '';
            Notify::users([$post['user_id'], ...Notify::workspaceAdmins((int) $post['workspace_id'])], 'failed', 'Post failed to publish', $excerpt . ($why ? " — $why" : ''), '/posts/' . $post['id']);
        } else {
            Notify::users([$post['user_id']], 'published', 'Post published', $excerpt, '/posts/' . $post['id']);
        }
        return $status;
    }

    private static function publishToAccount(array $post, array $target, array $media): array
    {
        $accountId = (int) $target['account_id'];
        $text = $target['content'] !== null && $target['content'] !== '' ? $target['content'] : $post['content'];
        $text = Variables::apply((int) $post['workspace_id'], $text);
        try {
            if (!$target['authorized']) {
                throw ProviderException::unauthorized('Account needs to be reconnected.');
            }
            $account = $target;
            $account['id'] = $accountId;
            $provider = Registry::withFreshToken($account);
            $provider->options = json_arr($target['options'] ?? '{}');

            $result = $provider->publish($text, $media);
            Db::update('post_accounts', [
                'status' => 'published',
                'provider_post_id' => (string) ($result['id'] ?? ''),
                'url' => $result['url'] ?? null,
                'error' => null,
                'published_at' => now(),
            ], 'post_id = ? AND account_id = ?', [$post['id'], $accountId]);

            // First comment: best effort, never fails the post.
            $comment = trim((string) ($post['first_comment'] ?? ''));
            if ($comment !== '' && !empty($result['id']) && ($provider->options['type'] ?? 'feed') !== 'story') {
                $status = 'skipped';
                if ($provider::supportsFirstComment()) {
                    try {
                        $provider->firstComment((string) $result['id'], Variables::apply((int) $post['workspace_id'], $comment));
                        $status = 'sent';
                    } catch (\Throwable $e) {
                        $status = 'failed';
                        log_event('warning', "First comment failed for post {$post['id']} account $accountId: " . $e->getMessage());
                    }
                }
                Db::update('post_accounts', ['first_comment_status' => $status], 'post_id = ? AND account_id = ?', [$post['id'], $accountId]);
            }
            return ['ok' => true, 'url' => $result['url'] ?? null];
        } catch (\Throwable $e) {
            if ($e instanceof ProviderException && $e->unauthorized) {
                Db::update('accounts', ['authorized' => 0, 'updated_at' => now()], 'id = ?', [$accountId]);
            }
            $msg = $e instanceof ProviderException || $e instanceof \RuntimeException ? $e->getMessage() : 'Unexpected error while publishing.';
            Db::update('post_accounts', ['status' => 'failed', 'error' => mb_substr($msg, 0, 1000)], 'post_id = ? AND account_id = ?', [$post['id'], $accountId]);
            log_event('error', "Publish failed for post {$post['id']} to account $accountId: " . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ]);
            return ['ok' => false, 'error' => $msg];
        }
    }

    /** Turns stored media ids into file paths and public URLs for providers. */
    public static function resolveMedia(array $ids, bool $files = false): array
    {
        if (!$ids) {
            return [];
        }
        $ids = array_map('intval', $ids);
        $rows = Db::all('SELECT * FROM media WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
        $byId = array_column($rows, null, 'id');
        $out = [];
        foreach ($ids as $id) {
            if (!isset($byId[$id])) {
                continue;
            }
            $m = $byId[$id];
            $out[] = [
                'id' => (int) $m['id'],
                'key' => $m['path'],
                'path' => $files ? Blob::file($m['path']) : null,
                'url' => media_url($m['path'], true),
                'mime' => $m['mime'],
                'size' => (int) $m['size'],
                'width' => $m['width'] ? (int) $m['width'] : null,
                'height' => $m['height'] ? (int) $m['height'] : null,
                'name' => $m['name'],
            ];
        }
        return $out;
    }
}

}

namespace App {

// ---- Report.php ----
/** Renders an analytics report (from Analytics::build) as a PDF. */
final class Report
{
    private const M = 40;            // page margin
    private const ACCENT = '#4f46e5';
    private const TEXT = '#15181e';
    private const MUTED = '#6b7280';
    private const LINE = '#e5e7eb';

    private Pdf $pdf;
    private float $y = 0;
    private string $accent = self::ACCENT;
    private array $brand = [];

    /** $brand from Branding::for(); defaults to plain MixCreate styling. */
    public static function pdf(array $a, string $workspace, string $scope, string $tz, array $brand = []): string
    {
        $r = new self();
        return $r->render($a, $workspace, $scope, $tz, $brand);
    }

    private function render(array $a, string $workspace, string $scope, string $tz, array $brand): string
    {
        $this->brand = $brand;
        $this->accent = $brand['color'] ?? self::ACCENT;
        $on = Branding::onColor($this->accent);
        $p = $this->pdf = new Pdf();
        $p->addPage();
        $w = Pdf::W - 2 * self::M;

        // Header band
        $p->fill($this->accent);
        $p->rect(0, 0, Pdf::W, 92);
        $x = self::M;
        if (!empty($brand['logo'])) {
            $logo = MediaTools::jpeg($brand['logo'], null, 400);
            $p->fill('#ffffff');
            $p->rect(self::M, 18, 56, 56, true, false, 8);
            $info = @getimagesize($logo['path']);
            if ($info) {
                $scale = min(46 / $info[0], 46 / $info[1]);
                $lw = $info[0] * $scale;
                $lh = $info[1] * $scale;
                $p->image($logo['path'], self::M + 28 - $lw / 2, 46 - $lh / 2, $lw, $lh);
            }
            $x += 70;
        }
        $p->fill($on);
        $p->font(20, true);
        $p->textFit($x, 44, $brand['name'] ?? $workspace, 300);
        $p->font(11);
        $p->text($x, 66, 'Social media report · ' . $scope);
        $p->text(Pdf::W - self::M, 44, self::range($a['from'], $a['to']), 'R');
        $p->font(9);
        $p->text(Pdf::W - self::M, 66, 'Generated ' . (new \DateTimeImmutable('now', new \DateTimeZone($tz)))->format('M j, Y'), 'R');
        $this->y = 116;

        // Stats
        $fmt = fn($n) => $n === null ? '-' : ($n >= 10000 ? number_format($n / 1000, 1) . 'k' : number_format((int) $n));
        $change = $a['followersChange'] === null ? '' : (($a['followersChange'] >= 0 ? '+' : '') . number_format($a['followersChange']));
        $stats = [
            ['Followers', $fmt($a['followersNow']), $change],
            ['Posts', (string) $a['totals']['posts'], ''],
            ['Engagement', $fmt($a['totals']['engagement']), ''],
            ['Impressions', $a['totals']['impressions'] ? $fmt($a['totals']['impressions']) : '-', ''],
            ['Engagement rate', $a['rate'] === null ? '-' : $a['rate'] . '%', ''],
        ];
        $bw = ($w - 4 * 10) / 5;
        foreach ($stats as $i => [$label, $value, $sub]) {
            $x = self::M + $i * ($bw + 10);
            $p->fill('#f4f5f8');
            $p->rect($x, $this->y, $bw, 62, true, false, 6);
            $p->fill(self::MUTED);
            $p->font(8.5);
            $p->text($x + 10, $this->y + 18, $label);
            $p->fill(self::TEXT);
            $p->font(17, true);
            $p->textFit($x + 10, $this->y + 42, $value, $bw - 20);
            if ($sub !== '') {
                $p->fill($a['followersChange'] >= 0 ? '#15803d' : '#dc2626');
                $p->font(8.5, true);
                $p->text($x + 10, $this->y + 55, $sub);
            }
        }
        $this->y += 84;

        // Charts
        $cw = ($w - 16) / 2;
        $this->chart(self::M, $this->y, $cw, 150, 'Followers', $a['followers'], 'line');
        $this->chart(self::M + $cw + 16, $this->y, $cw, 150, 'Engagement per day', $a['engagementSeries'], 'bar');
        $this->y += 172;

        // Networks
        if ($a['perNetwork']) {
            $this->heading('By network');
            $rows = array_map(fn($n) => [
                Analytics::providerName($n['provider']),
                $fmt($n['followers']),
                $n['followers_change'] === null ? '-' : (($n['followers_change'] >= 0 ? '+' : '') . number_format($n['followers_change'])),
                (string) $n['posts'],
                $fmt($n['engagement']),
                $n['rate'] === null ? '-' : $n['rate'] . '%',
            ], $a['perNetwork']);
            $this->table(['Network', 'Followers', 'Change', 'Posts', 'Engagement', 'Eng. rate'], $rows, [0.34, 0.14, 0.12, 0.1, 0.15, 0.15]);
        }

        // Top posts
        if ($a['top']) {
            $this->heading('Top posts');
            $rows = array_map(fn($t) => [
                $t['content'] !== '' ? $t['content'] : '(media)',
                Analytics::providerName($t['provider']),
                local_time($t['published_at'], 'M j', $tz),
                number_format((int) ($t['m']['likes'] ?? $t['m']['reactions'] ?? 0)),
                number_format((int) ($t['m']['replies'] ?? $t['m']['comments'] ?? 0)),
                number_format($t['engagement']),
            ], $a['top']);
            $this->table(['Post', 'Network', 'Date', 'Likes', 'Comments', 'Total'], $rows, [0.46, 0.16, 0.09, 0.09, 0.11, 0.09]);
        }

        // Formats + best times
        $this->heading('What worked');
        $labels = ['text' => 'Text only', 'image' => 'Single image', 'carousel' => 'Multiple images', 'video' => 'Video'];
        $rows = [];
        foreach ($a['types'] as $k => $t) {
            $rows[] = [$labels[$k], (string) $t['posts'], (string) $t['avg']];
        }
        $this->table(['Format', 'Posts', 'Avg engagement'], $rows, [0.5, 0.25, 0.25]);
        if ($a['best']['top']) {
            $this->need(60);
            $p->fill(self::TEXT);
            $p->font(10, true);
            $p->text(self::M, $this->y + 4, 'Best times to post');
            $p->font(10);
            $slots = array_map(fn($c) => Analytics::dayName($c['day']) . ' ' . Analytics::hourLabel($c['hour']), $a['best']['top']);
            $p->fill(self::MUTED);
            $p->text(self::M, $this->y + 20, implode('   ·   ', $slots));
            $this->y += 34;
        }
        if ($a['hashtags']) {
            $this->need(40);
            $p->fill(self::TEXT);
            $p->font(10, true);
            $p->text(self::M, $this->y + 4, 'Top hashtags');
            $p->font(10);
            $p->fill(self::MUTED);
            $tags = array_map(fn($h) => '#' . $h['tag'] . ' (' . $h['avg'] . ')', array_slice($a['hashtags'], 0, 8));
            foreach ($p->wrap(implode('   ', $tags), $w) as $i => $line) {
                $p->text(self::M, $this->y + 20 + $i * 14, $line);
            }
            $this->y += 40;
        }

        $this->footer();
        return $p->output();
    }

    private function heading(string $text): void
    {
        $this->need(60);
        $this->pdf->fill(self::TEXT);
        $this->pdf->font(12, true);
        $this->pdf->text(self::M, $this->y + 6, $text);
        $this->y += 18;
    }

    private function table(array $head, array $rows, array $cols): void
    {
        $p = $this->pdf;
        $w = Pdf::W - 2 * self::M;
        $rowH = 20;
        $drawHead = function () use ($p, $head, $cols, $w) {
            $p->fill('#f4f5f8');
            $p->rect(self::M, $this->y, $w, $rowH = 20);
            $p->fill(self::MUTED);
            $p->font(8.5, true);
            $x = self::M;
            foreach ($head as $i => $h) {
                $cw = $cols[$i] * $w;
                $i === 0 ? $p->text($x + 8, $this->y + 13.5, $h) : $p->text($x + $cw - 8, $this->y + 13.5, $h, 'R');
                $x += $cw;
            }
            $this->y += 20;
        };
        $drawHead();
        foreach ($rows as $row) {
            if ($this->y + $rowH > Pdf::H - 50) {
                $this->footer();
                $p->addPage();
                $this->y = self::M;
                $drawHead();
            }
            $x = self::M;
            $p->font(9);
            foreach ($row as $i => $cell) {
                $cw = $cols[$i] * $w;
                $p->fill($i === 0 ? self::TEXT : '#374151');
                $i === 0 ? $p->textFit($x + 8, $this->y + 13.5, (string) $cell, $cw - 16) : $p->textFit($x + $cw - 8, $this->y + 13.5, (string) $cell, $cw - 12, 'R');
                $x += $cw;
            }
            $p->stroke(self::LINE, 0.6);
            $p->line(self::M, $this->y + $rowH, self::M + $w, $this->y + $rowH);
            $this->y += $rowH;
        }
        $this->y += 18;
    }

    private function chart(float $x, float $y, float $w, float $h, string $title, array $series, string $type): void
    {
        $p = $this->pdf;
        $p->stroke(self::LINE, 0.8);
        $p->fill('#ffffff');
        $p->rect($x, $y, $w, $h, true, true, 6);
        $p->fill(self::TEXT);
        $p->font(10, true);
        $p->text($x + 12, $y + 18, $title);

        $vals = array_values(array_filter(array_column($series, 'value'), fn($v) => $v !== null));
        $px = $x + 36;
        $py = $y + 30;
        $pw = $w - 48;
        $ph = $h - 52;
        if (!$vals || ($type === 'line' && count($vals) < 2) || ($type === 'bar' && max($vals) == 0)) {
            $p->fill(self::MUTED);
            $p->font(9);
            $p->text($x + $w / 2, $y + $h / 2 + 8, 'No data yet', 'C');
            return;
        }
        $min = $type === 'bar' ? 0 : min($vals);
        $max = max($vals);
        if ($max == $min) {
            $max = $min + 1;
        }
        $n = count($series);
        $sx = fn($i) => $px + ($n === 1 ? $pw / 2 : $i * $pw / ($n - 1));
        $sy = fn($v) => $py + $ph - ($v - $min) / ($max - $min) * $ph;

        $p->font(7);
        for ($t = 0; $t <= 2; $t++) {
            $v = $min + ($max - $min) * $t / 2;
            $p->stroke('#eef0f3', 0.6);
            $p->line($px, $sy($v), $px + $pw, $sy($v));
            $p->fill(self::MUTED);
            $p->text($px - 5, $sy($v) + 2.5, $v >= 10000 ? round($v / 1000, 1) . 'k' : (string) round($v), 'R');
        }
        $p->text($px, $y + $h - 8, date('M j', strtotime($series[0]['date'])));
        $p->text($px + $pw, $y + $h - 8, date('M j', strtotime($series[$n - 1]['date'])), 'R');

        if ($type === 'bar') {
            $bw = max(1.2, $pw / $n * 0.65);
            $p->fill($this->accent);
            foreach ($series as $i => $s) {
                $v = (float) ($s['value'] ?? 0);
                if ($v > 0) {
                    $top = $sy($v);
                    $bx = $n === 1 ? $px + $pw / 2 - $bw / 2 : $px + $i * ($pw - $bw) / max(1, $n - 1);
                    $p->rect($bx, $top, $bw, $py + $ph - $top);
                }
            }
        } else {
            $pts = [];
            foreach ($series as $i => $s) {
                if ($s['value'] !== null) {
                    $pts[] = [$sx($i), $sy($s['value'])];
                }
            }
            $area = $pts;
            $area[] = [end($pts)[0], $py + $ph];
            $area[] = [$pts[0][0], $py + $ph];
            $p->fill(self::tint($this->accent, 0.15));
            $p->polyline($area, true);
            $p->stroke($this->accent, 1.6);
            $p->polyline($pts);
        }
    }

    private function need(float $space): void
    {
        if ($this->y + $space > Pdf::H - 50) {
            $this->footer();
            $this->pdf->addPage();
            $this->y = self::M;
        }
    }

    private int $page = 0;

    private function footer(): void
    {
        $this->page++;
        $this->pdf->fill(self::MUTED);
        $this->pdf->font(8);
        $left = $this->brand['footer'] ?? '';
        if ($left === '') {
            $left = $this->brand['name'] ?? Config::get('app_name', 'MixCreate');
            if (empty($this->brand['hide_powered_by']) && isset($this->brand['name'])) {
                $left .= ' · made with ' . Config::get('app_name', 'MixCreate');
            }
        }
        $this->pdf->text(self::M, Pdf::H - 24, $left);
        $this->pdf->text(Pdf::W - self::M, Pdf::H - 24, 'Page ' . $this->page, 'R');
    }

    /** Mixes a color with white. */
    public static function tint(string $hex, float $amount): string
    {
        $hex = ltrim($hex, '#');
        $c = [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
        return sprintf('#%02x%02x%02x', ...array_map(fn($v) => (int) round(255 - (255 - $v) * $amount), $c));
    }

    private static function range(string $from, string $to): string
    {
        $f = strtotime($from);
        $t = strtotime($to);
        return date('Y', $f) === date('Y', $t) ? date('M j', $f) . ' – ' . date('M j, Y', $t) : date('M j, Y', $f) . ' – ' . date('M j, Y', $t);
    }
}

}

namespace App {

// ---- ReportPptx.php ----
/** Builds a branded PowerPoint deck from Analytics::build() data. */
final class ReportPptx
{
    private const TEXT = '#15181e';
    private const MUTED = '#6b7280';
    private const SOFT = '#f4f5f8';

    public static function build(array $a, array $brand, string $scope, string $tz): string
    {
        $accent = $brand['color'] ?? '#4f46e5';
        $on = Branding::onColor($accent);
        $range = date('M j', strtotime($a['from'])) . ' – ' . date('M j, Y', strtotime($a['to']));
        $p = new Pptx(($brand['name'] ?? 'Report') . ' social media report');
        $fmt = fn($n) => $n === null ? '—' : ($n >= 10000 ? number_format($n / 1000, 1) . 'k' : number_format((int) $n));
        $footer = function (int $s) use ($p, $brand, $range) {
            $left = $brand['footer'] ?? '';
            if ($left === '') {
                $left = ($brand['name'] ?? '') . (empty($brand['hide_powered_by']) ? ' · made with ' . Config::get('app_name', 'MixCreate') : '');
            }
            $p->text($s, 40, 506, 600, 22, $left, ['size' => 9, 'color' => self::MUTED]);
            $p->text($s, 640, 506, 280, 22, $range, ['size' => 9, 'color' => self::MUTED, 'align' => 'r']);
        };
        $heading = function (int $s, string $title) use ($p, $accent) {
            $p->rect($s, 40, 40, 6, 30, $accent);
            $p->text($s, 56, 34, 860, 44, $title, ['size' => 26, 'bold' => true, 'valign' => 'ctr']);
        };

        // 1. Cover
        $s = $p->addSlide($accent);
        $y = 170;
        if (!empty($brand['logo'])) {
            $logo = MediaTools::jpeg($brand['logo'], null, 600);
            $info = @getimagesize($logo['path']);
            if ($info) {
                $p->rect($s, 60, 90, 96, 96, '#ffffff', true);
                $scale = min(76 / $info[0], 76 / $info[1]);
                $p->image($s, (string) file_get_contents($logo['path']), 'jpeg', 108 - $info[0] * $scale / 2, 138 - $info[1] * $scale / 2, $info[0] * $scale, $info[1] * $scale);
                $y = 220;
            }
        }
        $p->text($s, 56, $y, 840, 90, $brand['name'] ?? 'Report', ['size' => 48, 'bold' => true, 'color' => $on]);
        $p->text($s, 56, $y + 90, 840, 40, 'Social media report', ['size' => 24, 'color' => $on]);
        $p->text($s, 56, $y + 140, 840, 30, $scope . '  ·  ' . $range, ['size' => 16, 'color' => $on]);

        // 2. Overview
        $s = $p->addSlide();
        $heading($s, 'Overview');
        $stats = [
            ['Followers', $fmt($a['followersNow']), $a['followersChange'] === null ? '' : (($a['followersChange'] >= 0 ? '+' : '') . number_format($a['followersChange']))],
            ['Posts', (string) $a['totals']['posts'], ''],
            ['Engagement', $fmt($a['totals']['engagement']), ''],
            ['Impressions', $a['totals']['impressions'] ? $fmt($a['totals']['impressions']) : '—', ''],
            ['Engagement rate', $a['rate'] === null ? '—' : $a['rate'] . '%', ''],
        ];
        $bw = (880 - 4 * 14) / 5;
        foreach ($stats as $i => [$label, $value, $sub]) {
            $x = 40 + $i * ($bw + 14);
            $p->rect($s, $x, 100, $bw, 96, self::SOFT, true);
            $lines = [[$label, 11, false, self::MUTED], [$value, 26, true, self::TEXT]];
            if ($sub !== '') {
                $lines[] = [$sub, 11, true, $a['followersChange'] >= 0 ? '#15803d' : '#dc2626'];
            }
            $p->text($s, $x + 8, 106, $bw - 16, 86, '', ['lines' => $lines]);
        }
        $p->text($s, 40, 214, 430, 24, 'Followers', ['size' => 14, 'bold' => true]);
        $p->image($s, ChartImage::png($a['followers'], 'line', 430, 250, $accent, 1), 'png', 40, 240, 430, 250);
        $p->text($s, 490, 214, 430, 24, 'Engagement per day', ['size' => 14, 'bold' => true]);
        $p->image($s, ChartImage::png($a['engagementSeries'], 'bar', 430, 250, $accent, 1), 'png', 490, 240, 430, 250);
        $footer($s);

        // 3. Networks
        if ($a['perNetwork']) {
            $s = $p->addSlide();
            $heading($s, 'By network');
            $rows = array_map(fn($n) => [Analytics::providerName($n['provider']), $fmt($n['followers']),
                $n['followers_change'] === null ? '—' : (($n['followers_change'] >= 0 ? '+' : '') . number_format($n['followers_change'])),
                (string) $n['posts'], $fmt($n['engagement']), $n['rate'] === null ? '—' : $n['rate'] . '%'], $a['perNetwork']);
            self::table($p, $s, ['Network', 'Followers', 'Change', 'Posts', 'Engagement', 'Eng. rate'], $rows, [0.34, 0.13, 0.13, 0.1, 0.15, 0.15], 100);
            $footer($s);
        }

        // 4. Top posts
        if ($a['top']) {
            $s = $p->addSlide();
            $heading($s, 'Top posts');
            $rows = array_map(fn($t) => [str_limit(preg_replace('/\s+/u', ' ', $t['content'] !== '' ? $t['content'] : '(media)'), 90), Analytics::providerName($t['provider']), local_time($t['published_at'], 'M j', $tz),
                number_format((int) ($t['m']['likes'] ?? $t['m']['reactions'] ?? 0)), number_format((int) ($t['m']['replies'] ?? $t['m']['comments'] ?? 0)), number_format($t['engagement'])], array_slice($a['top'], 0, 8));
            self::table($p, $s, ['Post', 'Network', 'Date', 'Likes', 'Comments', 'Total'], $rows, [0.5, 0.15, 0.08, 0.08, 0.1, 0.09], 100);
            $footer($s);
        }

        // 5. What worked
        $s = $p->addSlide();
        $heading($s, 'What worked');
        $labels = ['text' => 'Text only', 'image' => 'Single image', 'carousel' => 'Multiple images', 'video' => 'Video'];
        $rows = [];
        foreach ($a['types'] as $k => $t) {
            $rows[] = [$labels[$k], (string) $t['posts'], (string) $t['avg']];
        }
        self::table($p, $s, ['Format', 'Posts', 'Avg engagement'], $rows, [0.5, 0.2, 0.3], 100, 420);
        $right = [['Best times to post', 14, true, self::TEXT]];
        foreach ($a['best']['top'] ?: [] as $i => $c) {
            $right[] = [($i + 1) . '.  ' . Analytics::dayName($c['day']) . ', ' . Analytics::hourLabel($c['hour']), 14, false, self::TEXT];
        }
        if (!$a['best']['top']) {
            $right[] = ['Not enough posts yet', 12, false, self::MUTED];
        }
        $right[] = [' ', 8, false, self::TEXT];
        $right[] = ['Top hashtags', 14, true, self::TEXT];
        foreach (array_slice($a['hashtags'], 0, 6) as $h) {
            $right[] = ['#' . $h['tag'] . '  (' . $h['avg'] . ' avg)', 12, false, self::TEXT];
        }
        if (!$a['hashtags']) {
            $right[] = ['No hashtags used', 12, false, self::MUTED];
        }
        $p->text($s, 500, 100, 420, 380, '', ['lines' => $right]);
        $footer($s);

        return $p->output();
    }

    private static function table(Pptx $p, int $s, array $head, array $rows, array $cols, float $y, float $width = 880): void
    {
        $x0 = 40;
        $rowH = 34;
        $p->rect($s, $x0, $y, $width, $rowH, self::SOFT);
        $x = $x0;
        foreach ($head as $i => $h) {
            $w = $cols[$i] * $width;
            $p->text($s, $x + 4, $y, $w - 8, $rowH, $h, ['size' => 11, 'bold' => true, 'color' => self::MUTED, 'valign' => 'ctr', 'align' => $i === 0 ? 'l' : 'r']);
            $x += $w;
        }
        foreach (array_slice($rows, 0, 10) as $r => $row) {
            $ry = $y + $rowH * ($r + 1);
            $x = $x0;
            foreach ($row as $i => $cell) {
                $w = $cols[$i] * $width;
                $p->text($s, $x + 4, $ry, $w - 8, $rowH, (string) $cell, ['size' => 12, 'valign' => 'ctr', 'align' => $i === 0 ? 'l' : 'r']);
                $x += $w;
            }
            $p->rect($s, $x0, $ry + $rowH - 1, $width, 1, '#e5e7eb');
        }
    }
}

}

namespace App {

// ---- Reports.php ----
/** Report parameters, data and scheduled delivery. */
final class Reports
{
    /**
     * Resolves stored params into data. Params: account (int|0), days (int|null) or from/to (Y-m-d).
     * Rolling "days" ranges end today in $tz.
     */
    public static function data(int $workspaceId, array $params, string $tz): array
    {
        $accounts = Db::all('SELECT id, name FROM accounts WHERE workspace_id = ?', [$workspaceId]);
        $all = array_map('intval', array_column($accounts, 'id'));
        $account = (int) ($params['account'] ?? 0);
        $ids = $account && in_array($account, $all, true) ? [$account] : $all;
        if (!empty($params['days'])) {
            $today = new \DateTimeImmutable('today', new \DateTimeZone($tz));
            $from = $today->modify('-' . ((int) $params['days'] - 1) . ' days')->format('Y-m-d');
            $to = $today->format('Y-m-d');
        } else {
            $from = $params['from'];
            $to = $params['to'];
        }
        $scope = 'All accounts';
        if ($account && count($ids) === 1) {
            foreach ($accounts as $a) {
                if ((int) $a['id'] === $account) {
                    $scope = $a['name'];
                }
            }
        }
        return ['a' => Analytics::build($workspaceId, $ids, $from, $to, $tz), 'scope' => $scope];
    }

    /** Sends scheduled reports that are due. Weekly: Mondays, covering the previous 7 days. Monthly: the 1st, covering last month. */
    public static function sendDue(): int
    {
        $sent = 0;
        foreach (Db::all('SELECT s.*, w.name AS workspace_name, u.timezone FROM report_schedules s JOIN workspaces w ON w.id = s.workspace_id LEFT JOIN users u ON u.id = s.created_by') as $s) {
            $tz = $s['timezone'] ?: 'UTC';
            $now = new \DateTimeImmutable('now', new \DateTimeZone($tz));
            if ((int) $now->format('G') < 8) {
                continue; // send in the morning, local time
            }
            $last = $s['last_sent_at'] ? new \DateTimeImmutable($s['last_sent_at'] . ' UTC') : null;
            if ($s['frequency'] === 'monthly') {
                $due = $now->format('j') === '1' && (!$last || $last->setTimezone($now->getTimezone())->format('Y-m-d') !== $now->format('Y-m-d'));
                $from = $now->modify('first day of last month')->format('Y-m-d');
                $to = $now->modify('last day of last month')->format('Y-m-d');
            } else {
                $due = $now->format('N') === '1' && (!$last || $last < $now->modify('-6 days'));
                $from = $now->modify('-7 days')->format('Y-m-d');
                $to = $now->modify('-1 day')->format('Y-m-d');
            }
            if (!$due) {
                continue;
            }
            try {
                self::send($s, $from, $to, $tz);
                $sent++;
            } catch (\Throwable $e) {
                log_event('error', "Scheduled report {$s['id']} failed: " . $e->getMessage());
            }
            // Mark as sent either way so a broken mail setup doesn't retry every minute.
            Db::update('report_schedules', ['last_sent_at' => now()], 'id = ?', [$s['id']]);
        }
        return $sent;
    }

    public static function send(array $schedule, string $from, string $to, string $tz): void
    {
        $accounts = json_arr($schedule['accounts']);
        $data = self::data((int) $schedule['workspace_id'], ['account' => $accounts[0] ?? 0, 'from' => $from, 'to' => $to], $tz);
        $workspace = $schedule['workspace_name'] ?? (string) Db::value('SELECT name FROM workspaces WHERE id = ?', [$schedule['workspace_id']]);
        $brand = Branding::for((int) $schedule['workspace_id']);
        $pdf = Report::pdf($data['a'], $workspace, $data['scope'], $tz, $brand);
        $a = $data['a'];
        $html = render('reports/email', ['a' => $a, 'workspace' => $brand['name'], 'scope' => $data['scope'], 'name' => $schedule['name'], 'brand' => $brand], null);
        Mailer::send(
            explode(',', $schedule['recipients']),
            $schedule['name'] . ' · ' . date('M j', strtotime($from)) . '–' . date('M j', strtotime($to)),
            $html,
            [['name' => self::filename($workspace, $from, $to), 'mime' => 'application/pdf', 'data' => $pdf]]
        );
    }

    public static function filename(string $workspace, string $from, string $to): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($workspace)), '-') ?: 'report';
        return "$slug-report-$from-to-$to.pdf";
    }
}

}

namespace App {

// ---- Router.php ----
final class Router
{
    /** @var array<int, array{method:string, pattern:string, handler:array|callable, middleware:array}> */
    private array $routes = [];
    private array $groupMiddleware = [];

    public function get(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    public function options(string $path, array|callable $handler, array $middleware = []): void
    {
        $this->add('OPTIONS', $path, $handler, $middleware);
    }

    public function group(array $middleware, callable $define): void
    {
        $prev = $this->groupMiddleware;
        $this->groupMiddleware = [...$prev, ...$middleware];
        $define($this);
        $this->groupMiddleware = $prev;
    }

    private function add(string $method, string $path, array|callable $handler, array $middleware): void
    {
        $pattern = preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', rtrim($path, '/') ?: '/');
        $this->routes[] = [
            'method' => $method,
            'pattern' => '#^' . $pattern . '$#',
            'handler' => $handler,
            'middleware' => [...$this->groupMiddleware, ...$middleware],
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        $path = (string) parse_url($uri, PHP_URL_PATH);
        $base = base_path();
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        $path = '/' . trim(rawurldecode($path), '/');

        $allowed = false;
        foreach ($this->routes as $route) {
            if (!preg_match($route['pattern'], $path, $m)) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed = true;
                continue;
            }
            $params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            foreach ($route['middleware'] as $mw) {
                Middleware::run($mw);
            }
            // Token-authenticated routes (APIs, webhooks) opt out with the 'no_csrf' middleware.
            if ($method !== 'GET' && !in_array('no_csrf', $route['middleware'], true)) {
                verify_csrf();
            }
            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $action] = $handler;
                (new $class())->$action(...$params);
            } else {
                $handler(...$params);
            }
            return;
        }
        abort($allowed ? 405 : 404, $allowed ? 'Method not allowed.' : '');
    }
}

}

namespace App {

// ---- Settings.php ----
/**
 * Instance-wide settings groups (SMTP, AI, Canva, ads platforms), stored encrypted
 * in the services table under "@group" so they never collide with platform keys.
 */
final class Settings
{
    private static array $cache = [];

    public static function get(string $group): array
    {
        if (!array_key_exists($group, self::$cache)) {
            $row = Db::one('SELECT credentials FROM services WHERE provider = ?', ['@' . $group]);
            self::$cache[$group] = Crypt::decryptArray($row['credentials'] ?? null);
        }
        return self::$cache[$group];
    }

    public static function value(string $group, string $key, mixed $default = null): mixed
    {
        $v = self::get($group)[$key] ?? null;
        return $v === null || $v === '' ? $default : $v;
    }

    public static function save(string $group, array $values): void
    {
        Db::query(
            'INSERT INTO services (provider, credentials, active, updated_at) VALUES (?, ?, 1, ?)
             ON CONFLICT(provider) DO UPDATE SET credentials = excluded.credentials, updated_at = excluded.updated_at',
            ['@' . $group, Crypt::encrypt($values), now()]
        );
        self::$cache[$group] = $values;
    }

    /** True when every listed key has a value. */
    public static function has(string $group, array $keys): bool
    {
        $s = self::get($group);
        foreach ($keys as $k) {
            if (empty($s[$k])) {
                return false;
            }
        }
        return true;
    }
}

}

namespace App {

// ---- Variables.php ----
/** Replaces {{name}} placeholders with workspace variables when publishing. */
final class Variables
{
    private static array $cache = [];

    public static function forWorkspace(int $workspaceId): array
    {
        if (!isset(self::$cache[$workspaceId])) {
            $rows = Db::all('SELECT name, value FROM variables WHERE workspace_id = ?', [$workspaceId]);
            self::$cache[$workspaceId] = array_column($rows, 'value', 'name');
        }
        return self::$cache[$workspaceId];
    }

    public static function apply(int $workspaceId, string $text): string
    {
        if (!str_contains($text, '{{')) {
            return $text;
        }
        $vars = self::forWorkspace($workspaceId);
        return preg_replace_callback('/\{\{\s*([\w.-]+)\s*\}\}/u', function ($m) use ($vars) {
            return array_key_exists($m[1], $vars) ? $vars[$m[1]] : $m[0];
        }, $text);
    }

    public static function clearCache(): void
    {
        self::$cache = [];
    }
}

}

namespace App {

// ---- WebAnalytics.php ----
/** Cookieless website analytics: collection and reporting. */
final class WebAnalytics
{
    public const SOCIAL = [
        'x.com' => 'X', 't.co' => 'X', 'twitter.com' => 'X', 'facebook.com' => 'Facebook', 'm.facebook.com' => 'Facebook', 'l.facebook.com' => 'Facebook', 'lm.facebook.com' => 'Facebook',
        'instagram.com' => 'Instagram', 'l.instagram.com' => 'Instagram', 'linkedin.com' => 'LinkedIn', 'lnkd.in' => 'LinkedIn', 'youtube.com' => 'YouTube', 'm.youtube.com' => 'YouTube',
        'threads.net' => 'Threads', 'threads.com' => 'Threads', 'l.threads.com' => 'Threads', 'tiktok.com' => 'TikTok', 'pinterest.com' => 'Pinterest', 'pin.it' => 'Pinterest',
        'bsky.app' => 'Bluesky', 'reddit.com' => 'Reddit', 'out.reddit.com' => 'Reddit', 'news.ycombinator.com' => 'Hacker News',
    ];

    /** Handles one beacon. Returns nothing; bad input is ignored. */
    public static function collect(array $d, array $server): void
    {
        $site = Db::one('SELECT * FROM sites WHERE site_key = ?', [(string) ($d['s'] ?? '')]);
        if (!$site) {
            return;
        }
        $pid = substr(preg_replace('/[^a-z0-9]/i', '', (string) ($d['p'] ?? '')), 0, 40);
        if (($d['e'] ?? '') === 'leave') {
            $dur = max(0, min(3600 * 4, (int) ($d['d'] ?? 0)));
            Db::query('UPDATE pageviews SET duration = ? WHERE site_id = ? AND pid = ? AND ts >= ?', [$dur, $site['id'], $pid, gmdate('Y-m-d H:i:s', time() - 6 * 3600)]);
            return;
        }
        $ua = (string) ($server['HTTP_USER_AGENT'] ?? '');
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|headless|preview|monitor|lighthouse/i', $ua)) {
            return;
        }
        $url = parse_url((string) ($d['u'] ?? ''));
        if (!$url || empty($url['host'])) {
            return;
        }
        // Only count pages from the registered domain (and its subdomains).
        $host = strtolower(preg_replace('/^www\./', '', $url['host']));
        $domain = strtolower(preg_replace('/^www\./', '', $site['domain']));
        if ($host !== $domain && !str_ends_with($host, '.' . $domain) && !Config::get('debug')) {
            return;
        }
        parse_str($url['query'] ?? '', $q);
        $refHost = strtolower((string) parse_url((string) ($d['r'] ?? ''), PHP_URL_HOST));
        $refHost = preg_replace('/^www\./', '', $refHost);
        if ($refHost === $host || $refHost === $domain || str_ends_with($refHost, '.' . $domain)) {
            $refHost = ''; // internal navigation
        }
        $ip = $server['HTTP_CF_CONNECTING_IP'] ?? $server['REMOTE_ADDR'] ?? '';
        $visitor = substr(hash('sha256', Config::get('app_key') . gmdate('Y-m-d') . $site['id'] . $ip . $ua), 0, 20);
        $width = (int) ($d['w'] ?? 0);
        Db::insert('pageviews', [
            'site_id' => $site['id'],
            'ts' => now(),
            'path' => mb_substr(($url['path'] ?? '/') ?: '/', 0, 300),
            'title' => mb_substr((string) ($d['t'] ?? ''), 0, 200) ?: null,
            'referrer' => $refHost ?: null,
            'utm_source' => isset($q['utm_source']) ? mb_substr((string) $q['utm_source'], 0, 100) : null,
            'utm_medium' => isset($q['utm_medium']) ? mb_substr((string) $q['utm_medium'], 0, 100) : null,
            'utm_campaign' => isset($q['utm_campaign']) ? mb_substr((string) $q['utm_campaign'], 0, 100) : null,
            'device' => preg_match('/iPad|Tablet/i', $ua) || ($width >= 600 && $width < 1024 && preg_match('/Android/i', $ua)) ? 'tablet' : (preg_match('/Mobi|iPhone|Android/i', $ua) ? 'mobile' : 'desktop'),
            'browser' => self::browser($ua),
            'os' => self::os($ua),
            'country' => isset($server['HTTP_CF_IPCOUNTRY']) && preg_match('/^[A-Z]{2}$/', $server['HTTP_CF_IPCOUNTRY']) ? $server['HTTP_CF_IPCOUNTRY'] : null,
            'visitor' => $visitor,
            'pid' => $pid ?: null,
        ]);
    }

    private static function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Other',
        };
    }

    private static function os(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Other',
        };
    }

    public static function report(int $siteId, string $from, string $to, string $tz): array
    {
        $zone = new \DateTimeZone($tz);
        $utc = new \DateTimeZone('UTC');
        $a = (new \DateTimeImmutable("$from 00:00:00", $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $b = (new \DateTimeImmutable("$to 23:59:59", $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $w = 'site_id = ? AND ts BETWEEN ? AND ?';
        $p = [$siteId, $a, $b];
        $tot = Db::one("SELECT COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors, AVG(NULLIF(duration, 0)) AS dur FROM pageviews WHERE $w", $p);
        // Bounce: visitors (per day) who viewed one page.
        $bounce = Db::one("SELECT SUM(n = 1) AS b, COUNT(*) AS t FROM (SELECT COUNT(*) AS n FROM pageviews WHERE $w GROUP BY visitor)", $p);
        $offset = $zone->getOffset(new \DateTimeImmutable($from)) / 60;
        $mod = sprintf('%+d minutes', $offset);
        $daily = Db::all("SELECT date(ts, '$mod') AS d, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM pageviews WHERE $w GROUP BY d", $p);
        $byDay = array_column($daily, null, 'd');
        $series = $viewSeries = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
            $d = date('Y-m-d', $t);
            $series[] = ['date' => $d, 'value' => (int) ($byDay[$d]['visitors'] ?? 0)];
            $viewSeries[] = ['date' => $d, 'value' => (int) ($byDay[$d]['views'] ?? 0)];
        }
        $top = fn(string $col, int $n = 10) => Db::all("SELECT $col AS k, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM pageviews WHERE $w AND $col IS NOT NULL AND $col != '' GROUP BY $col ORDER BY visitors DESC, views DESC LIMIT $n", $p);
        $refs = $top('referrer', 50);
        $social = [];
        foreach ($refs as $r) {
            $net = self::SOCIAL[$r['k']] ?? null;
            if ($net) {
                $social[$net] = ($social[$net] ?? 0) + (int) $r['visitors'];
            }
        }
        // UTM sources count as social when they name a network (e.g. utm_source=instagram).
        foreach ($top('utm_source', 50) as $u) {
            foreach (array_unique(self::SOCIAL) as $net) {
                if (strcasecmp($u['k'], $net) === 0 || strcasecmp(str_replace(' ', '', $u['k']), $net) === 0) {
                    $social[$net] = ($social[$net] ?? 0) + (int) $u['visitors'];
                }
            }
        }
        arsort($social);
        return [
            'views' => (int) $tot['views'],
            'visitors' => (int) $tot['visitors'],
            'duration' => $tot['dur'] !== null ? (int) round((float) $tot['dur']) : null,
            'bounce' => $bounce['t'] ? round($bounce['b'] / $bounce['t'] * 100) : null,
            'series' => $series,
            'viewSeries' => $viewSeries,
            'pages' => $top('path'),
            'referrers' => array_slice($refs, 0, 10),
            'social' => $social,
            'campaigns' => $top('utm_campaign'),
            'sources' => $top('utm_source'),
            'devices' => $top('device', 5),
            'browsers' => $top('browser', 6),
            'os' => $top('os', 6),
            'countries' => $top('country', 10),
        ];
    }

    public static function prune(): void
    {
        Db::query('DELETE FROM pageviews WHERE ts < ?', [gmdate('Y-m-d H:i:s', time() - 400 * 86400)]);
    }
}

}

namespace App {

// ---- WebPush.php ----
/**
 * Web Push with VAPID (RFC 8292) and aes128gcm payload encryption (RFC 8291), using only ext-openssl.
 */
final class WebPush
{
    private const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function unb64u(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
    }

    /** VAPID keys, generated on first use. Returns ['private_pem', 'public' (base64url raw point)]. */
    public static function vapid(): array
    {
        $s = Settings::get('push');
        if (empty($s['private_pem'])) {
            $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            openssl_pkey_export($key, $pem);
            $s = ['private_pem' => $pem, 'public' => self::b64u(self::rawPublic($key))];
            Settings::save('push', $s);
        }
        return $s;
    }

    private static function rawPublic(\OpenSSLAsymmetricKey $key): string
    {
        $d = openssl_pkey_get_details($key)['ec'];
        return "\x04" . str_pad($d['x'], 32, "\0", STR_PAD_LEFT) . str_pad($d['y'], 32, "\0", STR_PAD_LEFT);
    }

    private static function publicKeyFromRaw(string $raw): \OpenSSLAsymmetricKey
    {
        $der = hex2bin(self::P256_SPKI_PREFIX) . $raw;
        $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
        $key = openssl_pkey_get_public($pem);
        if (!$key) {
            throw new \RuntimeException('Invalid subscription key.');
        }
        return $key;
    }

    /** Encrypts $payload for a subscription. Returns the request body. */
    public static function encrypt(string $payload, string $p256dh, string $auth, ?string $salt = null, ?\OpenSSLAsymmetricKey $ephemeral = null): string
    {
        $uaPublic = self::unb64u($p256dh);
        $authSecret = self::unb64u($auth);
        if (strlen($uaPublic) !== 65 || strlen($authSecret) !== 16) {
            throw new \RuntimeException('Invalid subscription keys.');
        }
        $ephemeral ??= openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        $asPublic = self::rawPublic($ephemeral);
        $shared = openssl_pkey_derive(self::publicKeyFromRaw($uaPublic), $ephemeral, 32);
        if ($shared === false) {
            throw new \RuntimeException('ECDH failed.');
        }
        $ikm = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $salt ??= random_bytes(16);
        $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
        return $salt . pack('N', 4096) . chr(65) . $asPublic . $cipher . $tag;
    }

    /** ES256 JWT for the VAPID Authorization header. */
    public static function jwt(string $audience, string $subject): string
    {
        $v = self::vapid();
        $h = self::b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256']));
        $c = self::b64u(json_encode(['aud' => $audience, 'exp' => time() + 12 * 3600, 'sub' => $subject]));
        openssl_sign("$h.$c", $der, $v['private_pem'], OPENSSL_ALGO_SHA256);
        return "$h.$c." . self::b64u(self::derToRaw($der));
    }

    /** Converts an ASN.1 DER ECDSA signature to the 64-byte R||S form JWS wants. */
    private static function derToRaw(string $der): string
    {
        $pos = 2;
        if (ord($der[1]) & 0x80) {
            $pos += ord($der[1]) & 0x7f;
        }
        $out = '';
        for ($i = 0; $i < 2; $i++) {
            $pos++; // 0x02
            $len = ord($der[$pos++]);
            $int = substr($der, $pos, $len);
            $pos += $len;
            $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        }
        return $out;
    }

    /** Sends to one subscription row. Returns HTTP status; 404/410 means the subscription is gone. */
    public static function send(array $sub, array $message): int
    {
        $v = self::vapid();
        $p = parse_url($sub['endpoint']);
        $audience = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
        $admin = Db::value('SELECT email FROM users WHERE is_admin = 1 ORDER BY id LIMIT 1') ?: 'admin@example.com';
        $body = self::encrypt(json_encode($message, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $sub['p256dh'], $sub['auth']);
        $res = Http::post($sub['endpoint'], [
            'body' => $body,
            'timeout' => 15,
            'headers' => [
                'Content-Type' => 'application/octet-stream',
                'Content-Encoding' => 'aes128gcm',
                'TTL' => '86400',
                'Urgency' => 'normal',
                'Authorization' => 'vapid t=' . self::jwt($audience, 'mailto:' . $admin) . ', k=' . $v['public'],
            ],
        ]);
        if (in_array($res['status'], [404, 410], true)) {
            Db::query('DELETE FROM push_subscriptions WHERE id = ?', [$sub['id']]);
        }
        return $res['status'];
    }
}

}

namespace App {

// ---- Webhooks.php ----
/**
 * Outgoing webhooks. Each delivery is a POST with a JSON body and an
 * X-MixCreate-Signature header: "sha256=" . hmac_sha256(body, secret).
 * Failed deliveries are retried by cron with backoff (1m, 5m, 30m, 2h, 6h).
 */
final class Webhooks
{
    public const EVENTS = [
        'post.created' => 'Post created',
        'post.updated' => 'Post updated',
        'post.scheduled' => 'Post scheduled',
        'post.approval_requested' => 'Approval requested',
        'post.approved' => 'Post approved',
        'post.rejected' => 'Post sent back',
        'post.published' => 'Post published',
        'post.failed' => 'Post failed',
        'post.deleted' => 'Post deleted',
        'account.connected' => 'Account connected',
        'account.disconnected' => 'Account disconnected',
    ];

    private const BACKOFF = [60, 300, 1800, 7200, 21600];

    public static function dispatch(int $workspaceId, string $event, array $data): void
    {
        $hooks = Db::all('SELECT * FROM webhooks WHERE workspace_id = ? AND active = 1', [$workspaceId]);
        foreach ($hooks as $hook) {
            $events = json_arr($hook['events']);
            if ($events && !in_array($event, $events, true)) {
                continue;
            }
            $payload = json_encode([
                'event' => $event,
                'workspace_id' => $workspaceId,
                'sent_at' => gmdate('c'),
                'data' => $data,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            Db::insert('webhook_deliveries', [
                'webhook_id' => $hook['id'],
                'event' => $event,
                'payload' => $payload,
                'next_attempt_at' => now(),
                'created_at' => now(),
            ]);
        }
        // Deliver after the response is sent so the user isn't kept waiting.
        if ($hooks && PHP_SAPI !== 'cli') {
            static $registered = false;
            if (!$registered) {
                $registered = true;
                register_shutdown_function(function () {
                    if (function_exists('fastcgi_finish_request')) {
                        fastcgi_finish_request();
                    }
                    self::deliverPending(20);
                });
            }
        }
    }

    /** Sends due deliveries. Called after requests and from cron. */
    public static function deliverPending(int $limit = 50): int
    {
        $rows = Db::all(
            "SELECT d.*, w.url, w.secret FROM webhook_deliveries d JOIN webhooks w ON w.id = d.webhook_id
             WHERE d.delivered_at IS NULL AND d.next_attempt_at IS NOT NULL AND d.next_attempt_at <= ?
             ORDER BY d.id LIMIT $limit",
            [now()]
        );
        $sent = 0;
        foreach ($rows as $d) {
            // Claim it so overlapping runs don't double-send.
            $claimed = Db::query(
                'UPDATE webhook_deliveries SET next_attempt_at = ? WHERE id = ? AND next_attempt_at = ?',
                [gmdate('Y-m-d H:i:s', time() + 120), $d['id'], $d['next_attempt_at']]
            )->rowCount();
            if (!$claimed) {
                continue;
            }
            if (self::send($d)) {
                $sent++;
            }
        }
        return $sent;
    }

    public static function send(array $d): bool
    {
        $attempts = (int) $d['attempts'] + 1;
        $status = null;
        $response = '';
        try {
            if (!self::safeUrl($d['url'])) {
                throw new \RuntimeException('Webhook URL must be a public http(s) address.');
            }
            $res = Http::post($d['url'], [
                'body' => $d['payload'],
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'X-MixCreate-Event' => $d['event'],
                    'X-MixCreate-Delivery' => (string) $d['id'],
                    'X-MixCreate-Signature' => 'sha256=' . hash_hmac('sha256', $d['payload'], $d['secret']),
                ],
            ]);
            $status = $res['status'];
            $response = mb_substr($res['body'], 0, 1000);
        } catch (\Throwable $e) {
            $response = $e->getMessage();
        }
        $ok = $status !== null && $status >= 200 && $status < 300;
        $next = (!$ok && $attempts <= count(self::BACKOFF)) ? gmdate('Y-m-d H:i:s', time() + self::BACKOFF[$attempts - 1]) : null;
        Db::update('webhook_deliveries', [
            'attempts' => $attempts,
            'status_code' => $status,
            'response' => $response,
            'delivered_at' => $ok ? now() : null,
            'next_attempt_at' => $ok ? null : $next,
        ], 'id = ?', [$d['id']]);
        return $ok;
    }

    /** Blocks webhooks to localhost and private networks unless debug is on. */
    public static function safeUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!$parts || !in_array($parts['scheme'] ?? '', ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }
        if (Config::get('debug')) {
            return true;
        }
        $ips = gethostbynamel($parts['host']) ?: [];
        if (!$ips) {
            return false;
        }
        foreach ($ips as $ip) {
            if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return false;
            }
        }
        return true;
    }

    public static function prune(): void
    {
        Db::query('DELETE FROM webhook_deliveries WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
    }
}

}

namespace App {

// ---- Workspace.php ----
/** The workspace the signed-in user is working in, and their role there. */
final class Workspace
{
    public const ROLES = ['admin' => 'Admin', 'member' => 'Member', 'viewer' => 'Viewer'];

    private static ?array $current = null;

    public static function current(): ?array
    {
        if (self::$current !== null) {
            return self::$current;
        }
        $user = Auth::user();
        if (!$user) {
            return null;
        }
        $sql = 'SELECT w.*, wu.role FROM workspaces w JOIN workspace_user wu ON wu.workspace_id = w.id AND wu.user_id = ?';
        $ws = null;
        if ($user['current_workspace_id']) {
            $ws = Db::one("$sql WHERE w.id = ?", [$user['id'], $user['current_workspace_id']]);
        }
        $ws ??= Db::one("$sql ORDER BY w.id LIMIT 1", [$user['id']]);
        if ($ws && (int) $ws['id'] !== (int) $user['current_workspace_id']) {
            Db::update('users', ['current_workspace_id' => $ws['id']], 'id = ?', [$user['id']]);
        }
        return self::$current = $ws;
    }

    public static function id(): int
    {
        return (int) (self::current()['id'] ?? 0);
    }

    public static function role(): ?string
    {
        return self::current()['role'] ?? null;
    }

    /** admin > member > viewer. Instance admins count as workspace admins everywhere they belong. */
    public static function can(string $minRole): bool
    {
        $rank = ['viewer' => 1, 'member' => 2, 'admin' => 3];
        $role = self::role();
        if ($role === null) {
            return false;
        }
        if (Auth::isAdmin()) {
            return true;
        }
        return ($rank[$role] ?? 0) >= ($rank[$minRole] ?? 99);
    }

    public static function forUser(int $userId): array
    {
        return Db::all(
            'SELECT w.*, wu.role FROM workspaces w JOIN workspace_user wu ON wu.workspace_id = w.id WHERE wu.user_id = ? ORDER BY w.name',
            [$userId]
        );
    }

    public static function create(string $name, int $userId, string $color = '#4f46e5'): int
    {
        return Db::transaction(function () use ($name, $userId, $color) {
            $id = Db::insert('workspaces', ['name' => $name, 'color' => $color, 'created_by' => $userId, 'created_at' => now()]);
            Db::insert('workspace_user', ['workspace_id' => $id, 'user_id' => $userId, 'role' => 'admin', 'joined_at' => now()]);
            return $id;
        });
    }

    public static function switchTo(int $workspaceId): bool
    {
        $userId = Auth::id();
        $ok = Db::value('SELECT 1 FROM workspace_user WHERE workspace_id = ? AND user_id = ?', [$workspaceId, $userId]);
        if (!$ok) {
            return false;
        }
        Db::update('users', ['current_workspace_id' => $workspaceId], 'id = ?', [$userId]);
        self::$current = null;
        Auth::refresh();
        return true;
    }
}

}

namespace App {

/** Page templates, compiled into functions. */
final class View
{
    private const MAP = array (
  'accounts/choose' => 'mc_view_accounts_choose',
  'accounts/connect' => 'mc_view_accounts_connect',
  'accounts/index' => 'mc_view_accounts_index',
  'admin/integrations' => 'mc_view_admin_integrations',
  'admin/services' => 'mc_view_admin_services',
  'admin/users' => 'mc_view_admin_users',
  'ads/choose' => 'mc_view_ads_choose',
  'ads/index' => 'mc_view_ads_index',
  'analytics/heatmap' => 'mc_view_analytics_heatmap',
  'analytics/index' => 'mc_view_analytics_index',
  'analytics/top_posts' => 'mc_view_analytics_top_posts',
  'auth/invite' => 'mc_view_auth_invite',
  'auth/login' => 'mc_view_auth_login',
  'auth/setup' => 'mc_view_auth_setup',
  'autolists/form' => 'mc_view_autolists_form',
  'autolists/index' => 'mc_view_autolists_index',
  'autolists/show' => 'mc_view_autolists_show',
  'bio/edit' => 'mc_view_bio_edit',
  'bio/public' => 'mc_view_bio_public',
  'calendar/index' => 'mc_view_calendar_index',
  'competitors/index' => 'mc_view_competitors_index',
  'dashboard' => 'mc_view_dashboard',
  'developers/index' => 'mc_view_developers_index',
  'error' => 'mc_view_error',
  'hashtags/index' => 'mc_view_hashtags_index',
  'import/index' => 'mc_view_import_index',
  'inbox/index' => 'mc_view_inbox_index',
  'layouts/app' => 'mc_view_layouts_app',
  'layouts/auth' => 'mc_view_layouts_auth',
  'library/index' => 'mc_view_library_index',
  'media/index' => 'mc_view_media_index',
  'members/index' => 'mc_view_members_index',
  'partials/analytics_filters' => 'mc_view_partials_analytics_filters',
  'partials/post_row' => 'mc_view_partials_post_row',
  'posts/composer' => 'mc_view_posts_composer',
  'posts/index' => 'mc_view_posts_index',
  'profile/edit' => 'mc_view_profile_edit',
  'reports/email' => 'mc_view_reports_email',
  'reports/index' => 'mc_view_reports_index',
  'reports/public' => 'mc_view_reports_public',
  'reports/report' => 'mc_view_reports_report',
  'websites/index' => 'mc_view_websites_index',
  'workspaces/create' => 'mc_view_workspaces_create',
  'workspaces/edit' => 'mc_view_workspaces_edit',
  'workspaces/none' => 'mc_view_workspaces_none',
);

    public static function exists(string $name): bool
    {
        return isset(self::MAP[$name]);
    }

    public static function capture(string $name, array $vars): string
    {
        $fn = self::MAP[$name] ?? throw new \RuntimeException("View not found: $name");
        $level = ob_get_level();
        ob_start();
        try {
            ('\\' . $fn)($vars);
        } catch (\Throwable $e) {
            while (ob_get_level() > $level) {
                ob_end_clean();
            }
            throw $e;
        }
        return (string) ob_get_clean();
    }
}

}

namespace {

// ---- view: accounts/choose ----
function mc_view_accounts_choose(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><div class="page-head">
    <h1>Choose <?= e($name) ?> accounts</h1>
</div>
<form class="card narrow" method="post" action="<?= e(url('/accounts/choose')) ?>">
    <?= csrf_field() ?>
    <p class="muted">Your login gave access to several. Pick the ones to add to this workspace.</p>
    <div class="account-list">
        <?php foreach ($accounts as $a): ?>
            <label class="account-row check-row">
                <input type="checkbox" name="pick[]" value="<?= e($a['provider_id']) ?>" checked>
                <?= account_avatar(['provider' => $provider, 'name' => $a['name'], 'avatar' => $a['avatar'] ?? null]) ?>
                <span class="grow"><strong><?= e($a['name']) ?></strong><?php if (!empty($a['username'])): ?><br><small class="muted"><?= e($a['username']) ?></small><?php endif; ?></span>
            </label>
        <?php endforeach; ?>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/accounts')) ?>">Cancel</a>
        <button class="btn btn-primary" type="submit">Add selected</button>
    </div>
</form>
<?php
}

}

namespace {

// ---- view: accounts/connect ----
function mc_view_accounts_connect(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><div class="page-head">
    <h1>Connect <?= e($name) ?></h1>
</div>
<form class="card narrow" method="post" action="<?= e(url('/accounts/connect/' . $provider)) ?>">
    <?= csrf_field() ?>
    <?php if ($hint): ?><p class="muted"><?= e($hint) ?></p><?php endif; ?>
    <?php foreach ($fields as $key => $f): ?>
        <?php if ($f['type'] === 'checkbox'): ?>
            <label class="check"><input type="checkbox" name="<?= e($key) ?>" value="1"> <span><?= e($f['label']) ?></span></label>
        <?php else: ?>
            <label class="field">
                <span><?= e($f['label']) ?></span>
                <input type="<?= e($f['type']) ?>" name="<?= e($key) ?>" placeholder="<?= e($f['placeholder'] ?? '') ?>" autocomplete="off">
            </label>
        <?php endif; ?>
    <?php endforeach; ?>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/accounts')) ?>">Cancel</a>
        <button class="btn btn-primary" type="submit">Connect</button>
    </div>
</form>
<?php
}

}

namespace {
use App\Auth;
use App\Workspace;

// ---- view: accounts/index ----
function mc_view_accounts_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php



$isAdmin = Workspace::can('admin');
?>
<div class="page-head">
    <h1>Accounts</h1>
</div>

<section class="card">
    <h2>Connected</h2>
    <?php if (!$accounts): ?>
        <p class="muted">No accounts yet<?= $isAdmin ? '. Pick a platform below.' : '. Ask a workspace admin to connect some.' ?></p>
    <?php else: ?>
    <div class="account-list">
        <?php foreach ($accounts as $a): ?>
            <div class="account-row">
                <?= account_avatar($a) ?>
                <div class="grow">
                    <strong><?= e($a['name']) ?></strong>
                    <div class="muted small"><?= e(provider_meta($a['provider'])['name']) ?><?= $a['username'] ? ' · @' . e($a['username']) : '' ?></div>
                </div>
                <?php if (!$a['authorized']): ?>
                    <span class="badge status-failed">Needs reconnect</span>
                    <?php if ($isAdmin && ($providers[$a['provider']]['ready'] ?? false)): ?>
                        <a class="btn btn-sm" href="<?= e(url('/accounts/connect/' . $a['provider'])) ?>">Reconnect</a>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if ($isAdmin): ?>
                    <form method="post" action="<?= e(url('/accounts/' . $a['id'] . '/delete')) ?>" data-confirm="Remove <?= e($a['name']) ?>? Scheduled posts will skip it.">
                        <?= csrf_field() ?>
                        <button class="icon-btn" type="submit" aria-label="Remove"><?= icon('trash') ?></button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<?php if ($isAdmin): ?>
<section class="card">
    <h2>Add an account</h2>
    <div class="provider-grid">
        <?php foreach ($providers as $key => $p): ?>
            <?php if ($p['ready']): ?>
                <a class="provider-tile" href="<?= e(url('/accounts/connect/' . $key)) ?>">
                    <?= provider_badge($key, 'pmark') ?>
                    <span><?= e($p['name']) ?></span>
                </a>
            <?php elseif ($p['implemented']): ?>
                <a class="provider-tile is-disabled" href="<?= e(url(Auth::isAdmin() ? '/admin/services#' . $key : '/accounts')) ?>">
                    <?= provider_badge($key, 'pmark') ?>
                    <span><?= e($p['name']) ?></span>
                    <small><?= Auth::isAdmin() ? 'Add API keys' : 'Needs API keys' ?></small>
                </a>
            <?php else: ?>
                <div class="provider-tile is-disabled">
                    <?= provider_badge($key, 'pmark') ?>
                    <span><?= e($p['name']) ?></span>
                    <small>Coming soon</small>
                </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>
<?php
}

}

namespace {

// ---- view: admin/integrations ----
function mc_view_admin_integrations(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><div class="page-head">
    <div>
        <h1>Integrations</h1>
        <p class="muted">Instance-wide settings. Secrets are stored encrypted and never shown again; leave a secret blank to keep it.</p>
    </div>
</div>

<div class="service-list">
<?php foreach ($groups as $key => $g): $v = $values[$key]; ?>
    <section class="card service" id="<?= e($key) ?>">
        <div class="card-head">
            <h2><?= e($g['title']) ?></h2>
        </div>
        <p class="muted small"><?= e($g['hint']) ?></p>
        <?php if (!empty($g['callback'])): ?>
            <label class="field"><span>Redirect URL</span><code class="copy" data-copy><?= e(abs_url($g['callback'])) ?></code></label>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/admin/integrations/' . $key)) ?>">
            <?= csrf_field() ?>
            <div class="field-row">
            <?php foreach ($g['fields'] as $f => [$label, $type, $secret, $placeholder]): ?>
                <?php if (str_starts_with($type, 'select:')): ?>
                    <label class="field"><span><?= e($label) ?></span>
                        <select name="<?= e($f) ?>">
                            <?php foreach (explode(',', substr($type, 7)) as $opt): [$ov, $ol] = explode('=', $opt, 2); ?>
                                <option value="<?= e($ov) ?>" <?= ($v[$f] ?? '') === $ov ? 'selected' : '' ?>><?= e($ol) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php elseif ($type === 'model'): ?>
                    <label class="field"><span><?= e($label) ?></span>
                        <input type="text" name="<?= e($f) ?>" value="<?= e($v[$f] ?? '') ?>" placeholder="<?= e($placeholder) ?>" list="openrouter-models" autocomplete="off">
                        <small class="hint">For OpenRouter, start typing to search <?= count($models) ?: 'its' ?> models, e.g. anthropic/…, openai/…, google/…; <code>openrouter/auto</code> picks one for you.</small>
                    </label>
                <?php elseif ($type === 'textarea'): ?>
                    <label class="field" style="flex-basis: 100%"><span><?= e($label) ?></span><textarea name="<?= e($f) ?>" rows="2" placeholder="<?= e($placeholder) ?>"><?= e($v[$f] ?? '') ?></textarea></label>
                <?php else: ?>
                    <label class="field"><span><?= e($label) ?></span>
                        <input type="<?= e($type) ?>" name="<?= e($f) ?>" value="<?= $secret ? '' : e($v[$f] ?? '') ?>" placeholder="<?= $secret && !empty($v[$f]) ? '•••••••• (saved)' : e($placeholder) ?>" autocomplete="off">
                    </label>
                <?php endif; ?>
            <?php endforeach; ?>
            </div>
            <div class="form-actions">
                <?php if ($key === 'smtp'): ?><button class="btn" type="submit" form="test-smtp">Send test email</button><?php endif; ?>
                <?php if ($key === 'ai'): ?><button class="btn" type="submit" form="test-ai">Test</button><?php endif; ?>
                <button class="btn btn-primary" type="submit">Save</button>
            </div>
        </form>
    </section>
<?php endforeach; ?>
</div>
<form id="test-smtp" method="post" action="<?= e(url('/admin/integrations/smtp/test')) ?>"><?= csrf_field() ?></form>
<form id="test-ai" method="post" action="<?= e(url('/admin/integrations/ai/test')) ?>"><?= csrf_field() ?></form>

<datalist id="openrouter-models">
    <option value="openrouter/auto">Auto (OpenRouter picks)</option>
    <?php foreach ($models as $m): ?>
        <option value="<?= e($m['id']) ?>"><?= e($m['name'] . ($m['prompt_price'] !== null ? sprintf(' · $%.2f / $%.2f per 1M tokens', $m['prompt_price'], $m['completion_price']) : '')) ?></option>
    <?php endforeach; ?>
</datalist>
<?php
}

}

namespace {

// ---- view: admin/services ----
function mc_view_admin_services(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><div class="page-head">
    <div>
        <h1>Platform API keys</h1>
        <p class="muted">Keys are shared by every workspace on this install and stored encrypted. Secrets are never shown again after saving.</p>
    </div>
</div>

<div class="service-list">
<?php foreach ($services as $key => $s): ?>
    <section class="card service" id="<?= e($key) ?>">
        <div class="card-head">
            <h2><?= provider_badge($key, 'pmark sm') ?> <?= e($s['name']) ?></h2>
            <?php if (!$s['implemented']): ?>
                <span class="badge">Coming soon</span>
            <?php elseif ($s['ready']): ?>
                <span class="badge status-published">Ready</span>
            <?php else: ?>
                <span class="badge status-pending_approval">Needs keys</span>
            <?php endif; ?>
        </div>
        <?php if ($s['hint']): ?><p class="muted small"><?= e($s['hint']) ?></p><?php endif; ?>
        <?php if ($s['fields']): ?>
            <?php if ($s['oauth']): ?>
                <label class="field">
                    <span>Callback / redirect URL</span>
                    <code class="copy" data-copy><?= e($s['callback']) ?></code>
                </label>
            <?php endif; ?>
            <form method="post" action="<?= e(url('/admin/services/' . $key)) ?>">
                <?= csrf_field() ?>
                <div class="field-row">
                    <?php foreach ($s['fields'] as $f => $field): ?>
                        <label class="field">
                            <span><?= e($field['label']) ?></span>
                            <input type="<?= $field['secret'] ? 'password' : 'text' ?>" name="<?= e($f) ?>" value="<?= e($field['value']) ?>"
                                   placeholder="<?= $field['secret'] && $field['set'] ? '•••••••• (saved — leave blank to keep)' : '' ?>" autocomplete="off">
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div>
            </form>
        <?php endif; ?>
    </section>
<?php endforeach; ?>
</div>
<?php
}

}

namespace {
use App\Auth;

// ---- view: admin/users ----
function mc_view_admin_users(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php  ?>
<div class="page-head">
    <h1>Users &amp; workspaces</h1>
    <a class="btn" href="<?= e(url('/workspaces/new')) ?>"><?= icon('plus') ?> New workspace</a>
</div>

<section class="card">
    <h2>Workspaces</h2>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Name</th><th>Members</th><th>Accounts</th><th>Created</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($workspaces as $w): ?>
            <tr>
                <td><span class="ws-dot" style="background: <?= e($w['color']) ?>"></span> <?= e($w['name']) ?></td>
                <td><?= (int) $w['members'] ?></td>
                <td><?= (int) $w['accounts'] ?></td>
                <td class="muted"><?= e(local_time($w['created_at'], 'M j, Y')) ?></td>
                <td class="right">
                    <form method="post" action="<?= e(url('/workspaces/' . $w['id'] . '/switch')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm" type="submit">Open</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <p class="muted small">You can open a workspace only if you’re a member. Invite yourself from its Team page, or ask one of its admins.</p>
</section>

<section class="card">
    <h2>Users</h2>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Name</th><th>Email</th><th>Workspaces</th><th>Instance admin</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($users as $u): ?>
            <tr>
                <td><?= e($u['name']) ?></td>
                <td class="muted"><?= e($u['email']) ?></td>
                <td class="muted small"><?= e($u['workspaces'] ?? '—') ?></td>
                <td>
                    <?php if ((int) $u['id'] === Auth::id()): ?>
                        <span class="badge status-published">You</span>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/admin')) ?>">
                            <?= csrf_field() ?>
                            <button class="btn btn-sm" type="submit"><?= $u['is_admin'] ? 'Remove admin' : 'Make admin' ?></button>
                        </form>
                    <?php endif; ?>
                </td>
                <td class="right">
                    <?php if ((int) $u['id'] !== Auth::id()): ?>
                        <form method="post" action="<?= e(url('/admin/users/' . $u['id'] . '/delete')) ?>" data-confirm="Delete <?= e($u['name']) ?>’s account?">
                            <?= csrf_field() ?>
                            <button class="icon-btn" type="submit" aria-label="Delete user"><?= icon('trash') ?></button>
                        </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</section>
<?php
}

}

namespace {

// ---- view: ads/choose ----
function mc_view_ads_choose(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><div class="page-head"><h1>Choose <?= e(App\Ads::PROVIDERS[$provider]['name']) ?> accounts</h1></div>
<form class="card narrow" method="post" action="<?= e(url('/ads/choose')) ?>">
    <?= csrf_field() ?>
    <div class="account-list">
        <?php foreach ($accounts as $a): ?>
            <label class="account-row check-row">
                <input type="checkbox" name="pick[]" value="<?= e($a['external_id']) ?>" checked>
                <span class="grow"><strong><?= e($a['name']) ?></strong><br><small class="muted"><?= e($a['external_id']) ?> · <?= e($a['currency']) ?></small></span>
            </label>
        <?php endforeach; ?>
    </div>
    <div class="form-actions">
        <a class="btn" href="<?= e(url('/ads')) ?>">Cancel</a>
        <button class="btn btn-primary" type="submit">Add selected</button>
    </div>
</form>
<?php
}

}

namespace {
use App\Ads;
use App\Auth;
use App\Workspace;

// ---- view: ads/index ----
function mc_view_ads_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php




$money = function ($v) use ($currency) {
    $v = (float) $v;
    $s = number_format($v, $v >= 1000 ? 0 : 2);
    return $currency ? ($currency === 'USD' ? '$' . $s : $s . ' ' . $currency) : $s;
};
$spend = (float) $totals['spend'];
$impr = (int) $totals['impressions'];
$clicks = (int) $totals['clicks'];
$conv = (float) $totals['conversions'];
$qs = fn(array $over) => url('/ads', array_filter(array_merge(['ad_account' => $pick ?: null, 'days' => $days, 'from' => $days ? null : $from, 'to' => $days ? null : $to], $over), fn($v) => $v !== null && $v !== 0 && $v !== ''));
?>
<div class="page-head">
    <div><h1>Ads</h1><p class="muted">Campaign results from your ad accounts, updated every 6 hours.</p></div>
</div>

<?php if (!$accounts): ?>
    <div class="card empty-state">
        <?= icon('megaphone', 'empty-icon') ?>
        <h2>Connect an ad account</h2>
        <p class="muted">See spend, reach and results from Meta, Google and TikTok ads next to your organic posts.</p>
    </div>
<?php endif; ?>

<?php if (Workspace::can('admin')): ?>
<section class="card">
    <h2>Ad accounts</h2>
    <?php foreach ($accounts as $a): $p = Ads::PROVIDERS[$a['provider']]; ?>
        <div class="account-row">
            <span class="pmark sm" style="--pc: <?= e($p['color']) ?>"><?= e($p['abbr']) ?></span>
            <div class="grow"><strong><?= e($a['name']) ?></strong><div class="muted small"><?= e($p['name']) ?> · <?= e($a['external_id']) ?> · <?= e($a['currency']) ?><?= $a['last_import_at'] ? ' · synced ' . e(local_time($a['last_import_at'], 'M j, g:i A')) : ' · waiting for first sync' ?></div></div>
            <?php if (!$a['authorized']): ?><a class="btn btn-sm" href="<?= e(url('/ads/connect/' . $a['provider'])) ?>">Reconnect</a><?php endif; ?>
            <form method="post" action="<?= e(url('/ads/' . $a['id'] . '/sync')) ?>"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Sync now"><?= icon('refresh') ?></button></form>
            <form method="post" action="<?= e(url('/ads/' . $a['id'] . '/delete')) ?>" data-confirm="Remove this ad account and its stats?"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Remove"><?= icon('trash') ?></button></form>
        </div>
    <?php endforeach; ?>
    <div class="provider-grid" style="margin-top: 12px">
        <?php foreach (Ads::PROVIDERS as $key => $p): ?>
            <?php if ($ready[$key]): ?>
                <a class="provider-tile" href="<?= e(url('/ads/connect/' . $key)) ?>"><span class="pmark" style="--pc: <?= e($p['color']) ?>"><?= e($p['abbr']) ?></span><span><?= e($p['name']) ?></span></a>
            <?php else: ?>
                <a class="provider-tile is-disabled" href="<?= e(url(Auth::isAdmin() ? '/admin/integrations#' . $key : '/ads')) ?>"><span class="pmark" style="--pc: <?= e($p['color']) ?>"><?= e($p['abbr']) ?></span><span><?= e($p['name']) ?></span><small><?= Auth::isAdmin() ? 'Add API keys' : 'Not set up' ?></small></a>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($accounts): ?>
<form class="analytics-filters" method="get" action="<?= e(url('/ads')) ?>">
    <select name="ad_account" data-autosubmit aria-label="Ad account">
        <option value="">All ad accounts</option>
        <?php foreach ($accounts as $a): ?><option value="<?= (int) $a['id'] ?>" <?= $pick === (int) $a['id'] ? 'selected' : '' ?>><?= e(Ads::PROVIDERS[$a['provider']]['name'] . ' · ' . $a['name']) ?></option><?php endforeach; ?>
    </select>
    <div class="segmented" role="group" aria-label="Range">
        <?php foreach ([7, 30, 90] as $d): ?><a href="<?= e($qs(['days' => $d, 'from' => null, 'to' => null])) ?>" class="<?= $days === $d ? 'active' : '' ?>"><?= $d ?>d</a><?php endforeach; ?>
    </div>
</form>
<?php if ($mixedCurrency): ?><div class="notice notice-info"><?= icon('alert') ?><div>These accounts use different currencies, so totals are added as raw numbers. Pick one account for exact figures.</div></div><?php endif; ?>

<div class="stats">
    <div class="stat"><span class="stat-label">Spend</span><span class="stat-value"><?= $money($spend) ?></span></div>
    <div class="stat"><span class="stat-label">Impressions</span><span class="stat-value"><?= number_format($impr) ?></span></div>
    <div class="stat"><span class="stat-label">Clicks</span><span class="stat-value"><?= number_format($clicks) ?></span></div>
    <div class="stat"><span class="stat-label">CTR</span><span class="stat-value"><?= $impr ? round($clicks / $impr * 100, 2) . '%' : '—' ?></span></div>
    <div class="stat"><span class="stat-label">CPC</span><span class="stat-value"><?= $clicks ? $money($spend / $clicks) : '—' ?></span></div>
    <div class="stat"><span class="stat-label">Conversions</span><span class="stat-value"><?= number_format($conv, $conv == floor($conv) ? 0 : 1) ?></span></div>
    <div class="stat"><span class="stat-label">Cost / conversion</span><span class="stat-value"><?= $conv > 0 ? $money($spend / $conv) : '—' ?></span></div>
</div>

<section class="card"><h2>Spend per day</h2><div class="chart" data-chart="bar" data-series="<?= e(json_encode($series)) ?>" data-label="Spend"></div></section>

<section class="card">
    <h2>Campaigns</h2>
    <?php if (!$campaigns): ?><p class="muted">No campaign activity in this period.</p><?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Campaign</th><th class="num">Spend</th><th class="num">Impressions</th><th class="num">Clicks</th><th class="num">CTR</th><th class="num">CPC</th><th class="num">Conv.</th></tr></thead>
        <?php foreach ($campaigns as $c): ?>
            <tr>
                <td><span class="pdot" style="--pc: <?= e(Ads::PROVIDERS[$c['provider']]['color']) ?>"><?= e(Ads::PROVIDERS[$c['provider']]['abbr']) ?></span> <?= e($c['campaign_name'] ?: $c['campaign_id']) ?><div class="muted small"><?= e($c['account_name']) ?></div></td>
                <td class="num"><?= e(number_format((float) $c['spend'], 2) . ' ' . $c['currency']) ?></td>
                <td class="num"><?= number_format((int) $c['impressions']) ?></td>
                <td class="num"><?= number_format((int) $c['clicks']) ?></td>
                <td class="num"><?= $c['impressions'] ? round($c['clicks'] / $c['impressions'] * 100, 2) . '%' : '—' ?></td>
                <td class="num"><?= $c['clicks'] ? number_format($c['spend'] / $c['clicks'], 2) : '—' ?></td>
                <td class="num"><?= number_format((float) $c['conversions'], 1) ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php
}

}

namespace {
use App\Analytics;

// ---- view: analytics/heatmap ----
function mc_view_analytics_heatmap(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php


$max = 0;
foreach ($best['grid'] as $row) {
    foreach ($row as $v) {
        $max = max($max, (float) $v);
    }
}
?>
<section class="card">
    <h2>Best time to post</h2>
    <?php if ($best['top']): ?>
        <p class="muted small">Based on engagement of your last <?= (int) $best['posts'] ?> posts over 6 months, in your timezone (<?= e(str_replace('_', ' ', user_tz())) ?>).</p>
        <div class="best-times">
            <?php foreach ($best['top'] as $i => $c): ?>
                <div class="best-slot">
                    <span class="best-rank"><?= $i + 1 ?></span>
                    <div><strong><?= e(Analytics::dayName($c['day'])) ?>, <?= e(Analytics::hourLabel($c['hour'])) ?></strong>
                        <div class="muted small"><?= $c['avg'] ?> avg engagement · <?= $c['posts'] ?> post<?= $c['posts'] === 1 ? '' : 's' ?></div></div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <p class="muted">Not enough data yet: this needs at least 8 published posts with engagement numbers (you have <?= (int) $best['posts'] ?>). Keep posting at different times and it will fill in.</p>
    <?php endif; ?>

    <div class="heatmap-wrap">
        <table class="heatmap" aria-label="Average engagement by day and hour">
            <thead><tr><th></th><?php for ($h = 0; $h < 24; $h++): ?><th><?= $h % 3 === 0 ? e(str_replace([' AM', ' PM'], ['a', 'p'], Analytics::hourLabel($h))) : '' ?></th><?php endfor; ?></tr></thead>
            <tbody>
            <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $d): ?>
                <tr>
                    <th><?= e(substr(Analytics::dayName($d), 0, 3)) ?></th>
                    <?php for ($h = 0; $h < 24; $h++):
                        $v = $best['grid'][$d][$h];
                        $alpha = $v === null || $max == 0 ? 0 : 0.15 + 0.85 * ($v / $max);
                    ?>
                        <td style="--a: <?= round($alpha, 2) ?>" class="<?= $v === null ? 'empty' : '' ?>" title="<?= e(Analytics::dayName($d) . ' ' . Analytics::hourLabel($h) . ($v === null ? ': no posts' : ': ' . round($v, 1) . ' avg engagement')) ?>"></td>
                    <?php endfor; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php
}

}

namespace {
use App\Analytics;

// ---- view: analytics/index ----
function mc_view_analytics_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php


$fmt = fn($n) => $n === null ? '—' : ($n >= 100000 ? number_format($n / 1000) . 'k' : ($n >= 10000 ? number_format($n / 1000, 1) . 'k' : number_format((int) $n)));
$pct = fn($n) => $n === null ? '—' : rtrim(rtrim(number_format($n, 2), '0'), '.') . '%';
$tabUrl = fn($t) => url('/analytics', array_filter(['tab' => $t, 'account' => $accountId ?: null, 'days' => $days, 'from' => $days ? null : $a['from'], 'to' => $days ? null : $a['to']]));
$typeLabels = ['text' => 'Text only', 'image' => 'Single image', 'carousel' => 'Multiple images', 'video' => 'Video'];
?>
<div class="page-head">
    <h1>Analytics</h1>
    <div class="head-actions">
        <a class="btn" href="<?= e(url('/reports', array_filter(['account' => $accountId ?: null, 'from' => $a['from'], 'to' => $a['to']]))) ?>"><?= icon('file') ?> Report</a>
    </div>
</div>

<?php partial('partials/analytics_filters', ['accounts' => $accounts, 'accountId' => $accountId, 'days' => $days, 'a' => $a, 'base' => '/analytics', 'extra' => ['tab' => $tab]]); ?>

<nav class="tabs">
    <?php foreach (['overview' => 'Overview', 'networks' => 'Networks', 'content' => 'Content', 'timing' => 'Best time', 'audience' => 'Audience'] as $k => $l): ?>
        <a href="<?= e($tabUrl($k)) ?>" class="<?= $tab === $k ? 'active' : '' ?>"><?= e($l) ?></a>
    <?php endforeach; ?>
</nav>

<?php if (!$a['lastImport']): ?>
    <div class="notice notice-info"><?= icon('clock') ?><div>Follower and engagement numbers appear after the scheduler’s first import (every 6 hours). Post counts are already live.</div></div>
<?php endif; ?>

<?php if ($tab === 'overview'): ?>
    <div class="stats">
        <div class="stat">
            <span class="stat-label">Followers</span>
            <span class="stat-value"><?= $fmt($a['followersNow']) ?></span>
            <?php if ($a['followersChange'] !== null): ?>
                <span class="stat-delta <?= $a['followersChange'] >= 0 ? 'up' : 'down' ?>"><?= $a['followersChange'] >= 0 ? '+' : '' ?><?= number_format($a['followersChange']) ?></span>
            <?php endif; ?>
        </div>
        <div class="stat"><span class="stat-label">Posts</span><span class="stat-value"><?= $a['totals']['posts'] ?></span></div>
        <div class="stat"><span class="stat-label">Engagement</span><span class="stat-value"><?= $fmt($a['totals']['engagement']) ?></span></div>
        <div class="stat"><span class="stat-label">Impressions</span><span class="stat-value"><?= $fmt($a['totals']['impressions'] ?: null) ?></span></div>
        <div class="stat"><span class="stat-label">Engagement rate</span><span class="stat-value"><?= $pct($a['rate']) ?></span></div>
    </div>
    <div class="stats compact-stats">
        <div class="stat"><span class="stat-label">Likes</span><span class="stat-value"><?= $fmt($a['totals']['likes']) ?></span></div>
        <div class="stat"><span class="stat-label">Comments</span><span class="stat-value"><?= $fmt($a['totals']['replies']) ?></span></div>
        <div class="stat"><span class="stat-label">Shares</span><span class="stat-value"><?= $fmt($a['totals']['shares']) ?></span></div>
        <div class="stat"><span class="stat-label">Saves</span><span class="stat-value"><?= $fmt($a['totals']['saves']) ?></span></div>
        <div class="stat"><span class="stat-label">Clicks</span><span class="stat-value"><?= $fmt($a['totals']['clicks']) ?></span></div>
    </div>
    <div class="grid-2">
        <section class="card"><h2>Followers</h2><div class="chart" data-chart="line" data-series="<?= e(json_encode($a['followers'])) ?>" data-label="Followers"></div></section>
        <section class="card"><h2>Engagement per day</h2><div class="chart" data-chart="bar" data-series="<?= e(json_encode($a['engagementSeries'])) ?>" data-label="Engagement"></div></section>
    </div>
    <section class="card">
        <h2>Posts per day</h2>
        <div class="chart short" data-chart="bar" data-series="<?= e(json_encode($a['postSeries'])) ?>" data-label="Posts"></div>
    </section>
    <?php partial('analytics/top_posts', ['top' => $a['top']]); ?>

<?php elseif ($tab === 'networks'): ?>
    <section class="card">
        <h2>By network</h2>
        <?php if (!$a['perNetwork']): ?><p class="muted">No accounts.</p><?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Network</th><th class="num">Followers</th><th class="num">Change</th><th class="num">Posts</th><th class="num">Engagement</th><th class="num">Impressions</th><th class="num">Eng. rate</th></tr></thead>
            <?php foreach ($a['perNetwork'] as $n): ?>
                <tr>
                    <td><?= provider_badge($n['provider'], 'pdot') ?> <?= e(Analytics::providerName($n['provider'])) ?> <span class="muted small">(<?= $n['accounts'] ?>)</span></td>
                    <td class="num"><?= $fmt($n['followers']) ?></td>
                    <td class="num <?= ($n['followers_change'] ?? 0) >= 0 ? 'up' : 'down' ?>"><?= $n['followers_change'] === null ? '—' : (($n['followers_change'] >= 0 ? '+' : '') . number_format($n['followers_change'])) ?></td>
                    <td class="num"><?= $n['posts'] ?></td>
                    <td class="num"><?= $fmt($n['engagement']) ?></td>
                    <td class="num"><?= $fmt($n['impressions'] ?: null) ?></td>
                    <td class="num"><?= $pct($n['rate']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </section>
    <section class="card">
        <h2>By account</h2>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Account</th><th class="num">Followers</th><th class="num">Change</th><th class="num">Posts</th><th class="num">Likes</th><th class="num">Comments</th><th class="num">Shares</th><th class="num">Eng. rate</th></tr></thead>
            <?php foreach ($a['perAccount'] as $acc): ?>
                <tr>
                    <td><a href="<?= e(url('/analytics', ['account' => $acc['id'], 'days' => $days ?: 30])) ?>" class="acct-cell"><?= account_avatar($acc, 'xs') ?> <?= e($acc['name']) ?></a></td>
                    <td class="num"><?= $fmt($acc['followers']) ?></td>
                    <td class="num <?= ($acc['followers_change'] ?? 0) >= 0 ? 'up' : 'down' ?>"><?= $acc['followers_change'] === null ? '—' : (($acc['followers_change'] >= 0 ? '+' : '') . number_format($acc['followers_change'])) ?></td>
                    <td class="num"><?= $acc['posts'] ?></td>
                    <td class="num"><?= $fmt($acc['likes']) ?></td>
                    <td class="num"><?= $fmt($acc['replies']) ?></td>
                    <td class="num"><?= $fmt($acc['shares']) ?></td>
                    <td class="num"><?= $pct($acc['rate']) ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </section>

<?php elseif ($tab === 'content'): ?>
    <div class="grid-2">
        <section class="card">
            <h2>By format</h2>
            <p class="muted small">Average engagement per post.</p>
            <?php $maxAvg = max(1, max(array_column($a['types'], 'avg'))); ?>
            <div class="hbars">
                <?php foreach ($a['types'] as $k => $t): ?>
                    <div class="hbar">
                        <span class="hbar-label"><?= e($typeLabels[$k]) ?> <small class="muted"><?= $t['posts'] ?> posts</small></span>
                        <span class="hbar-track"><i style="width: <?= round($t['avg'] / $maxAvg * 100) ?>%"></i></span>
                        <span class="hbar-value"><?= $t['avg'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
        <section class="card">
            <h2>Hashtags</h2>
            <p class="muted small">Average engagement on posts that used each hashtag.</p>
            <?php if (!$a['hashtags']): ?><p class="muted">No hashtags in this period.</p><?php else: ?>
            <?php $maxH = max(1, max(array_column($a['hashtags'], 'avg'))); ?>
            <div class="hbars">
                <?php foreach ($a['hashtags'] as $h): ?>
                    <div class="hbar">
                        <span class="hbar-label">#<?= e($h['tag']) ?> <small class="muted"><?= $h['posts'] ?></small></span>
                        <span class="hbar-track"><i style="width: <?= round($h['avg'] / $maxH * 100) ?>%"></i></span>
                        <span class="hbar-value"><?= $h['avg'] ?></span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </section>
    </div>
    <?php partial('analytics/top_posts', ['top' => $a['top']]); ?>

<?php elseif ($tab === 'timing'): ?>
    <?php partial('analytics/heatmap', ['best' => $a['best']]); ?>

<?php else: ?>
    <?php if (!$a['audience']): ?>
        <div class="card empty-state">
            <?= icon('users', 'empty-icon') ?>
            <p class="muted">No audience data yet. Instagram and Threads share age, gender and location for accounts with at least 100 followers; it’s imported once a day.</p>
        </div>
    <?php endif; ?>
    <?php foreach ($a['audience'] as $aud): ?>
        <section class="card">
            <h2><?= provider_badge($aud['provider'], 'pdot') ?> <?= e($aud['account']) ?></h2>
            <div class="audience-grid">
                <?php foreach (['age' => 'Age', 'gender' => 'Gender', 'country' => 'Top countries', 'city' => 'Top cities'] as $dim => $label):
                    $vals = $aud['data'][$dim] ?? [];
                    if (!$vals) continue;
                    arsort($vals);
                    if ($dim === 'age') ksort($vals);
                    $vals = array_slice($vals, 0, 8, true);
                    $total = max(1, array_sum($aud['data'][$dim]));
                    $max = max($vals);
                    $genderNames = ['F' => 'Women', 'M' => 'Men', 'U' => 'Unknown'];
                ?>
                    <div>
                        <h3><?= e($label) ?></h3>
                        <div class="hbars">
                            <?php foreach ($vals as $k => $v): ?>
                                <div class="hbar">
                                    <span class="hbar-label"><?= e($dim === 'gender' ? ($genderNames[$k] ?? $k) : $k) ?></span>
                                    <span class="hbar-track"><i style="width: <?= round($v / $max * 100) ?>%"></i></span>
                                    <span class="hbar-value"><?= round($v / $total * 100) ?>%</span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
<?php endif; ?>
<?php
}

}

namespace {

// ---- view: analytics/top_posts ----
function mc_view_analytics_top_posts(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><section class="card">
    <h2>Top posts</h2>
    <?php if (!$top): ?>
        <p class="muted">No engagement data yet for this period.</p>
    <?php else: ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Post</th><th>Account</th><th class="num">Likes</th><th class="num">Comments</th><th class="num">Shares</th><th class="num">Total</th></tr></thead>
        <?php foreach ($top as $p): $m = $p['m']; ?>
            <tr>
                <td><a href="<?= e(url('/posts/' . $p['id'])) ?>"><?= e(str_limit($p['content'] ?: '(media)', 70)) ?></a>
                    <div class="muted small"><?= e(local_time($p['published_at'], 'M j')) ?><?php if ($p['url']): ?> · <a href="<?= e($p['url']) ?>" target="_blank" rel="noopener">View</a><?php endif; ?></div></td>
                <td><?= provider_badge($p['provider'], 'pdot') ?> <?= e($p['account_name']) ?></td>
                <td class="num"><?= number_format((int) ($m['likes'] ?? $m['reactions'] ?? 0)) ?></td>
                <td class="num"><?= number_format((int) ($m['replies'] ?? $m['comments'] ?? 0)) ?></td>
                <td class="num"><?= number_format((int) ($m['shares'] ?? $m['reposts'] ?? 0)) ?></td>
                <td class="num"><strong><?= number_format($p['engagement']) ?></strong></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
</section>
<?php
}

}

namespace {

// ---- view: auth/invite ----
function mc_view_auth_invite(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><form class="card auth-card" method="post" action="<?= e(url('/invite/' . basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)))) ?>">
    <?= csrf_field() ?>
    <h1>Join <?= e($invite['workspace_name']) ?></h1>
    <p class="muted">You’ve been invited as <strong><?= e(App\Workspace::ROLES[$invite['role']] ?? $invite['role']) ?></strong> with <strong><?= e($invite['email']) ?></strong>.</p>

    <?php if ($current): ?>
        <p>You’re signed in as <?= e($current['email']) ?>.</p>
        <button class="btn btn-primary btn-block" type="submit">Accept invitation</button>
    <?php elseif ($existing): ?>
        <label class="field">
            <span>Password for <?= e($invite['email']) ?></span>
            <input type="password" name="password" required autofocus autocomplete="current-password">
        </label>
        <button class="btn btn-primary btn-block" type="submit">Sign in and join</button>
    <?php else: ?>
        <label class="field">
            <span>Your name</span>
            <input type="text" name="name" value="<?= e(old('name')) ?>" required autofocus autocomplete="name">
        </label>
        <div class="field-row">
            <label class="field">
                <span>Password</span>
                <input type="password" name="password" required minlength="8" autocomplete="new-password">
            </label>
            <label class="field">
                <span>Confirm</span>
                <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
            </label>
        </div>
        <label class="field">
            <span>Timezone</span>
            <select name="timezone" data-detect-timezone>
                <?php foreach ($timezones as $tz): ?>
                    <option value="<?= e($tz) ?>"><?= e(str_replace('_', ' ', $tz)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <button class="btn btn-primary btn-block" type="submit">Create account and join</button>
    <?php endif; ?>
</form>
<?php
}

}

namespace {

// ---- view: auth/login ----
function mc_view_auth_login(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><form class="card auth-card" method="post" action="<?= e(url('/login')) ?>">
    <?= csrf_field() ?>
    <h1>Sign in</h1>
    <label class="field">
        <span>Email</span>
        <input type="email" name="email" value="<?= e(old('email')) ?>" required autofocus autocomplete="email">
    </label>
    <label class="field">
        <span>Password</span>
        <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="btn btn-primary btn-block" type="submit">Sign in</button>
    <p class="muted small center">New here? Ask a workspace admin for an invite link.</p>
</form>
<?php
}

}

namespace {

// ---- view: auth/setup ----
function mc_view_auth_setup(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><form class="card auth-card" method="post" action="<?= e(url('/setup')) ?>">
    <?= csrf_field() ?>
    <h1>Welcome to MixCreate</h1>
    <p class="muted">Create the admin account for this install. You can invite your team afterwards.</p>
    <label class="field">
        <span>Your name</span>
        <input type="text" name="name" value="<?= e(old('name')) ?>" required autofocus autocomplete="name">
    </label>
    <label class="field">
        <span>Email</span>
        <input type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email">
    </label>
    <div class="field-row">
        <label class="field">
            <span>Password</span>
            <input type="password" name="password" required minlength="8" autocomplete="new-password">
        </label>
        <label class="field">
            <span>Confirm</span>
            <input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password">
        </label>
    </div>
    <label class="field">
        <span>Timezone</span>
        <select name="timezone" data-detect-timezone>
            <?php foreach ($timezones as $tz): ?>
                <option value="<?= e($tz) ?>" <?= old('timezone', 'UTC') === $tz ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $tz)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="field">
        <span>First workspace</span>
        <input type="text" name="workspace" value="<?= e(old('workspace')) ?>" placeholder="e.g. Acme Inc." required>
        <small class="hint">A workspace holds its own accounts, posts and team. Make one per brand or client.</small>
    </label>
    <button class="btn btn-primary btn-block" type="submit">Create account</button>
</form>
<?php
}

}

namespace {
use App\Autolists;

// ---- view: autolists/form ----
function mc_view_autolists_form(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php


$sel = $list ? array_map('intval', json_arr($list['accounts'])) : [];
$slots = $list ? json_arr($list['slots']) : [];
$days = array_unique(array_map(fn($s) => (int) $s['day'], $slots));
$times = array_values(array_unique(array_map(fn($s) => $s['time'], $slots)));
if (!$times) {
    $times = ['09:00'];
}
?>
<form method="post" action="<?= e(url($list ? '/autolists/' . $list['id'] : '/autolists')) ?>" class="autolist-form">
    <?= csrf_field() ?>
    <label class="field"><span>Name</span><input type="text" name="name" value="<?= e($list['name'] ?? '') ?>" placeholder="Evergreen tips" required></label>
    <fieldset class="field">
        <legend>Accounts</legend>
        <div class="account-picker">
            <?php foreach ($accounts as $a): ?>
                <label class="account-chip">
                    <input type="checkbox" name="accounts[]" value="<?= (int) $a['id'] ?>" <?= in_array((int) $a['id'], $sel, true) ? 'checked' : '' ?>>
                    <?= account_avatar($a, 'sm') ?><span class="account-chip-name"><?= e($a['name']) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <fieldset class="field">
        <legend>Days</legend>
        <div class="day-picker">
            <?php foreach ([1, 2, 3, 4, 5, 6, 0] as $d): ?>
                <label class="tag-chip" style="--tc: var(--accent)"><input type="checkbox" name="days[]" value="<?= $d ?>" <?= in_array($d, $days, true) || (!$list && $d >= 1 && $d <= 5) ? 'checked' : '' ?>><span><?= Autolists::DAYS[$d] ?></span></label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <fieldset class="field">
        <legend>Times <small class="muted">(<?= e(str_replace('_', ' ', $list['timezone'] ?? user_tz())) ?>)</small></legend>
        <div class="times" data-times>
            <?php foreach ($times as $t): ?>
                <span class="time-row"><input type="time" name="times[]" value="<?= e($t) ?>"><button type="button" class="icon-btn" data-remove-time aria-label="Remove time"><?= icon('x') ?></button></span>
            <?php endforeach; ?>
            <button type="button" class="btn btn-sm" data-add-time><?= icon('plus') ?> Time</button>
        </div>
    </fieldset>
    <label class="field"><span>Order</span>
        <select name="order_mode">
            <option value="sequence">In order, then start over</option>
            <option value="random" <?= ($list['order_mode'] ?? '') === 'random' ? 'selected' : '' ?>>Shuffle (every post gets a turn before repeats)</option>
        </select>
    </label>
    <?php if ($list): ?>
        <label class="check"><input type="checkbox" name="active" value="1" <?= $list['active'] ? 'checked' : '' ?>> <span>Active</span></label>
    <?php endif; ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit"><?= $list ? 'Save' : 'Create autolist' ?></button></div>
</form>
<?php
}

}

namespace {
use App\Autolists;
use App\Workspace;

// ---- view: autolists/index ----
function mc_view_autolists_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php   ?>
<div class="page-head">
    <div>
        <h1>Autolists</h1>
        <p class="muted">Evergreen queues. At each time slot the next post in the list goes out, and the list starts over when it reaches the end.</p>
    </div>
</div>

<?php if ($lists): ?>
<div class="card post-list">
    <?php foreach ($lists as $l): ?>
        <a class="post-row" href="<?= e(url('/autolists/' . $l['id'])) ?>">
            <div class="post-row-main">
                <div class="post-row-meta">
                    <strong><?= e($l['name']) ?></strong>
                    <?= $l['active'] ? '<span class="badge status-published">Active</span>' : '<span class="badge">Paused</span>' ?>
                </div>
                <div class="muted small"><?= (int) $l['items'] ?> posts · <?= count(json_arr($l['slots'])) ?> slots a week · <?= (int) $l['runs'] ?> published<?= $l['next'] && $l['active'] ? ' · next ' . e($l['next']->format('D, M j g:i A')) : '' ?></div>
            </div>
        </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if (Workspace::can('member')): ?>
<details class="card add-block" <?= $lists ? '' : 'open' ?>>
    <summary class="btn"><?= icon('plus') ?> New autolist</summary>
    <?php partial('autolists/form', ['list' => null, 'accounts' => $accounts]); ?>
</details>
<?php endif; ?>
<?php
}

}

namespace {
use App\Workspace;

// ---- view: autolists/show ----
function mc_view_autolists_show(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php  $canEdit = Workspace::can('member'); ?>
<div class="page-head">
    <div>
        <a class="link" href="<?= e(url('/autolists')) ?>">← Autolists</a>
        <h1><?= e($list['name']) ?></h1>
        <p class="muted"><?= count($items) ?> posts · <?= $list['active'] ? 'Active' : 'Paused' ?><?= $upcoming && $list['active'] ? ' · next ' . e($upcoming[0]->format('D, M j g:i A')) : '' ?></p>
    </div>
    <?php if ($canEdit): ?>
    <div class="head-actions">
        <form method="post" action="<?= e(url('/autolists/' . $list['id'] . '/publish')) ?>" data-confirm="Publish the next item now?"><?= csrf_field() ?><button class="btn" type="submit">Publish next now</button></form>
    </div>
    <?php endif; ?>
</div>

<div class="grid-2">
    <section class="card">
        <h2>Posts in this list</h2>
        <?php if (!$items): ?><p class="muted">Empty. Add posts below, or use “Add to autolist” on any existing post.</p><?php endif; ?>
        <div class="lib-list">
            <?php foreach ($items as $i => $it): $isNext = $list['order_mode'] === 'sequence' && $items && $i === ((int) $list['next_index'] % count($items)); ?>
                <details class="lib-item">
                    <summary>
                        <span class="muted small nowrap">#<?= $i + 1 ?></span>
                        <span class="grow ellipsis"><?= e(str_limit($it['content'] ?: '(media only)', 90)) ?></span>
                        <?php if ($isNext): ?><span class="badge status-scheduled">Next</span><?php endif; ?>
                        <span class="muted small nowrap"><?= (int) $it['times_posted'] ?>×</span>
                    </summary>
                    <?php if ($canEdit): ?>
                    <form method="post" action="<?= e(url('/autolists/' . $list['id'] . '/items')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="item_id" value="<?= (int) $it['id'] ?>">
                        <textarea name="content" rows="4"><?= e($it['content']) ?></textarea>
                        <div class="mini-media" data-media-field>
                            <?php foreach ($it['media_items'] as $m): ?>
                                <span class="mini-thumb"><input type="hidden" name="media[]" value="<?= (int) $m['id'] ?>"><?php if (str_starts_with($m['mime'], 'image/')): ?><img src="<?= e(media_url($m)) ?>" alt=""><?php else: ?><?= icon('play') ?><?php endif; ?><button type="button" data-remove-media aria-label="Remove"><?= icon('x') ?></button></span>
                            <?php endforeach; ?>
                            <button type="button" class="btn btn-sm" data-pick-media><?= icon('image') ?> Media</button>
                        </div>
                        <div class="form-actions">
                            <button class="btn btn-ghost btn-danger-text" type="submit" form="del-item-<?= (int) $it['id'] ?>">Remove</button>
                            <span class="grow"></span>
                            <button class="btn btn-primary btn-sm" type="submit">Save</button>
                        </div>
                    </form>
                    <form id="del-item-<?= (int) $it['id'] ?>" method="post" action="<?= e(url('/autolists/' . $list['id'] . '/items/' . $it['id'] . '/delete')) ?>"><?= csrf_field() ?></form>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        </div>
        <?php if ($canEdit): ?>
        <form method="post" action="<?= e(url('/autolists/' . $list['id'] . '/items')) ?>" class="add-item">
            <?= csrf_field() ?>
            <label class="field"><span>New post</span><textarea name="content" rows="3" placeholder="Write a post for this list. {{variables}} work here too."></textarea></label>
            <div class="mini-media" data-media-field><button type="button" class="btn btn-sm" data-pick-media><?= icon('image') ?> Media</button></div>
            <div class="form-actions"><button class="btn btn-primary" type="submit"><?= icon('plus') ?> Add</button></div>
        </form>
        <?php endif; ?>
    </section>

    <div class="stack">
        <section class="card">
            <h2>Coming up</h2>
            <?php if (!$upcoming || !$list['active']): ?><p class="muted"><?= $list['active'] ? 'No slots.' : 'Paused.' ?></p><?php endif; ?>
            <?php if ($list['active']): foreach ($upcoming as $u): ?><div class="muted small"><?= e($u->format('l, M j · g:i A')) ?></div><?php endforeach; endif; ?>
        </section>
        <?php if ($recent): ?>
        <section class="card">
            <h2>Recently created</h2>
            <div class="post-list compact"><?php foreach ($recent as $p) { partial('partials/post_row', ['post' => $p]); } ?></div>
        </section>
        <?php endif; ?>
        <?php if ($canEdit): ?>
        <details class="card">
            <summary class="btn btn-sm">Settings</summary>
            <?php partial('autolists/form', ['list' => $list, 'accounts' => $accounts]); ?>
            <form method="post" action="<?= e(url('/autolists/' . $list['id'] . '/delete')) ?>" data-confirm="Delete this autolist?"><?= csrf_field() ?><button class="btn btn-ghost btn-danger-text" type="submit">Delete autolist</button></form>
        </details>
        <?php endif; ?>
    </div>
</div>

<dialog id="pick-media-dialog" class="dialog dialog-wide">
    <div class="dialog-head"><h2>Choose media</h2><button type="button" class="icon-btn" data-close-dialog aria-label="Close"><?= icon('x') ?></button></div>
    <div class="media-grid" id="pick-media-grid"><p class="muted">Loading…</p></div>
    <div class="form-actions"><button type="button" class="btn btn-primary" id="pick-media-done">Add selected</button></div>
</dialog>
<?php
}

}

namespace {
use App\Workspace;

// ---- view: bio/edit ----
function mc_view_bio_edit(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php  $canEdit = Workspace::can('member'); ?>
<div class="page-head">
    <div>
        <h1>Link in bio</h1>
        <p class="muted">One page with all your links. Put its address in your profiles.</p>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e(url('@' . $page['slug'], ['preview' => 1])) ?>" target="_blank" rel="noopener"><?= icon('external') ?> Preview</a>
    </div>
</div>

<div class="notice <?= $page['published'] ? 'notice-success' : 'notice-info' ?>">
    <?= icon($page['published'] ? 'check' : 'clock') ?>
    <div class="grow">
        <?php if ($page['published']): ?><strong>Live at</strong><?php else: ?><strong>Not published yet.</strong> It will live at<?php endif; ?>
        <code class="copy" data-copy><?= e($publicUrl) ?></code>
    </div>
</div>

<div class="stats">
    <div class="stat"><span class="stat-label">Page views (30 days)</span><span class="stat-value"><?= number_format($views) ?></span></div>
    <div class="stat"><span class="stat-label">Link clicks (30 days)</span><span class="stat-value"><?= number_format(array_sum($clicks) - $views) ?></span></div>
    <div class="stat"><span class="stat-label">Click rate</span><span class="stat-value"><?= $views ? round((array_sum($clicks) - $views) / $views * 100) . '%' : '—' ?></span></div>
</div>

<div class="grid-2">
    <section class="card">
        <h2>Links</h2>
        <div class="bio-links" data-bio-sortable>
            <?php foreach ($links as $l): ?>
                <details class="bio-link" data-id="<?= (int) $l['id'] ?>">
                    <summary>
                        <?php if ($canEdit): ?><span class="grip" aria-hidden="true"><?= icon('grip') ?></span><?php endif; ?>
                        <span class="grow"><strong><?= e($l['label']) ?></strong><br><small class="muted ellipsis"><?= e($l['url']) ?></small></span>
                        <?php if (!$l['active']): ?><span class="badge">Hidden</span><?php endif; ?>
                        <span class="muted small nowrap"><?= (int) ($clicks[$l['id']] ?? 0) ?> clicks</span>
                    </summary>
                    <?php if ($canEdit): ?>
                    <form method="post" action="<?= e(url('/bio/links/' . $l['id'])) ?>">
                        <?= csrf_field() ?>
                        <div class="field-row">
                            <label class="field"><span>Label</span><input type="text" name="label" value="<?= e($l['label']) ?>" required></label>
                            <label class="field"><span>URL</span><input type="text" name="url" value="<?= e($l['url']) ?>" required></label>
                        </div>
                        <label class="check"><input type="checkbox" name="active" value="1" <?= $l['active'] ? 'checked' : '' ?>> <span>Show on page</span></label>
                        <div class="form-actions">
                            <button class="btn btn-ghost btn-danger-text" type="submit" form="del-link-<?= (int) $l['id'] ?>">Delete</button>
                            <span class="grow"></span>
                            <button class="btn btn-primary btn-sm" type="submit">Save</button>
                        </div>
                    </form>
                    <form id="del-link-<?= (int) $l['id'] ?>" method="post" action="<?= e(url('/bio/links/' . $l['id'] . '/delete')) ?>" data-confirm="Delete this link?"><?= csrf_field() ?></form>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        </div>
        <?php if (!$links): ?><p class="muted">No links yet.</p><?php endif; ?>
        <?php if ($canEdit): ?>
        <form class="field-row align-end" method="post" action="<?= e(url('/bio/links')) ?>">
            <?= csrf_field() ?>
            <label class="field"><span>Label</span><input type="text" name="label" placeholder="Shop the collection" required></label>
            <label class="field"><span>URL</span><input type="text" name="url" placeholder="https://…" required></label>
            <button class="btn btn-primary" type="submit"><?= icon('plus') ?> Add</button>
        </form>
        <?php endif; ?>
        <h3>Views per day</h3>
        <div class="chart short" data-chart="bar" data-series="<?= e(json_encode($series)) ?>" data-label="Views"></div>
    </section>

    <form class="card" method="post" action="<?= e(url('/bio')) ?>">
        <?= csrf_field() ?>
        <h2>Page</h2>
        <label class="field"><span>Address</span>
            <div class="input-prefix"><span><?= e(preg_replace('#^https?://#', '', abs_url('@'))) ?></span><input type="text" name="slug" value="<?= e($page['slug']) ?>" required <?= $canEdit ? '' : 'disabled' ?>></div>
        </label>
        <label class="field"><span>Title</span><input type="text" name="title" value="<?= e($page['title']) ?>" maxlength="80" <?= $canEdit ? '' : 'disabled' ?>></label>
        <label class="field"><span>Bio</span><textarea name="bio" rows="3" maxlength="300" <?= $canEdit ? '' : 'disabled' ?>><?= e($page['bio']) ?></textarea></label>
        <div class="field">
            <span>Profile picture</span>
            <div class="bio-avatar-row">
                <span class="avatar"><?= e(initials($page['title'] ?: '?')) ?><?php if ($avatar): ?><img src="<?= e(media_url($avatar)) ?>" alt="" data-bio-avatar-img><?php endif; ?></span>
                <input type="hidden" name="avatar_media_id" value="<?= (int) $page['avatar_media_id'] ?>" data-bio-avatar>
                <?php if ($canEdit): ?><button class="btn btn-sm" type="button" data-pick-avatar>Choose from media</button><?php endif; ?>
            </div>
        </div>
        <div class="field-row">
            <label class="field"><span>Style</span>
                <select name="style" <?= $canEdit ? '' : 'disabled' ?>><?php foreach ($themes as $k => $t): ?><option value="<?= e($k) ?>" <?= ($theme['style'] ?? 'light') === $k ? 'selected' : '' ?>><?= e($t['label']) ?></option><?php endforeach; ?></select>
            </label>
            <label class="field"><span>Buttons</span>
                <select name="button" <?= $canEdit ? '' : 'disabled' ?>><option value="rounded">Rounded</option><option value="square" <?= ($theme['button'] ?? '') === 'square' ? 'selected' : '' ?>>Square</option></select>
            </label>
            <label class="field"><span>Accent</span><input type="color" name="accent" value="<?= e($theme['accent'] ?? '#4f46e5') ?>" <?= $canEdit ? '' : 'disabled' ?>></label>
        </div>
        <label class="check"><input type="checkbox" name="show_socials" value="1" <?= $page['show_socials'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> <span>Show icons for this workspace’s social profiles</span></label>
        <label class="check"><input type="checkbox" name="show_posts" value="1" <?= $page['show_posts'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> <span>Show a grid of recent image posts, each linking to the post</span></label>
        <label class="check"><input type="checkbox" name="published" value="1" <?= $page['published'] ? 'checked' : '' ?> <?= $canEdit ? '' : 'disabled' ?>> <span><strong>Published</strong> — anyone with the address can see it</span></label>
        <?php if ($canEdit): ?><div class="form-actions"><button class="btn btn-primary" type="submit">Save page</button></div><?php endif; ?>
    </form>
</div>

<dialog id="avatar-dialog" class="dialog dialog-wide">
    <div class="dialog-head"><h2>Choose a picture</h2><button type="button" class="icon-btn" data-close-dialog aria-label="Close"><?= icon('x') ?></button></div>
    <div class="media-grid" id="avatar-library"><p class="muted">Loading…</p></div>
</dialog>
<?php
}

}

namespace {

// ---- view: bio/public ----
function mc_view_bio_public(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php
$style = $themes[$theme['style'] ?? 'light'] ?? $themes['light'];
$accent = $theme['accent'] ?? '#4f46e5';
$bg = $style['bg'] === 'accent' ? $accent : $style['bg'];
$radius = ($theme['button'] ?? 'rounded') === 'square' ? '8px' : '999px';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page['title'] ?: 'Links') ?></title>
<meta name="description" content="<?= e(str_limit($page['bio'], 150)) ?>">
<meta property="og:title" content="<?= e($page['title']) ?>">
<meta property="og:description" content="<?= e(str_limit($page['bio'], 150)) ?>">
<?php if ($avatar): ?><meta property="og:image" content="<?= e(abs_url(ltrim(substr($avatar, strlen(base_path())), '/'))) ?>"><?php endif; ?>
<?php if ($preview): ?><meta name="robots" content="noindex"><?php endif; ?>
<style>
    *{box-sizing:border-box}
    body{margin:0;min-height:100vh;font:16px/1.5 ui-sans-serif,system-ui,-apple-system,"Segoe UI",Roboto,Arial,sans-serif;background:<?= e($bg) ?>;color:<?= e($style['text']) ?>;-webkit-font-smoothing:antialiased}
    .wrap{max-width:560px;margin:0 auto;padding:48px 20px 40px;display:flex;flex-direction:column;align-items:center;gap:14px}
    .avatar{width:96px;height:96px;border-radius:50%;object-fit:cover;background:<?= e($style['card']) ?>;display:grid;place-items:center;font-weight:700;font-size:32px;color:<?= e($accent) ?>;box-shadow:0 4px 16px rgba(0,0,0,.12)}
    h1{font-size:22px;margin:6px 0 0;text-align:center;letter-spacing:-.01em<?= $style['bg'] === 'accent' ? ';color:#fff' : '' ?>}
    .bio{margin:0;text-align:center;opacity:.8;max-width:440px;white-space:pre-line<?= $style['bg'] === 'accent' ? ';color:#fff' : '' ?>}
    .socials{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin:4px 0}
    .socials a{width:38px;height:38px;border-radius:50%;display:grid;place-items:center;background:<?= e($style['card']) ?>;color:<?= e($style['text']) ?>;text-decoration:none;font-weight:800;font-size:12px;box-shadow:0 1px 3px rgba(0,0,0,.1)}
    .links{width:100%;display:grid;gap:12px;margin-top:8px}
    .links a{display:block;text-align:center;padding:15px 20px;border-radius:<?= $radius ?>;background:<?= e($style['card']) ?>;color:<?= e($style['text']) ?>;text-decoration:none;font-weight:600;box-shadow:0 1px 3px rgba(0,0,0,.08);border:2px solid transparent;transition:transform .12s,border-color .12s}
    .links a:hover{transform:translateY(-2px);border-color:<?= e($accent) ?>}
    .grid{width:100%;display:grid;grid-template-columns:repeat(3,1fr);gap:6px;margin-top:18px}
    .grid a{aspect-ratio:1;border-radius:10px;overflow:hidden;display:block;background:<?= e($style['card']) ?>}
    .grid img{width:100%;height:100%;object-fit:cover;display:block}
    .foot{margin-top:28px;font-size:12px;opacity:.55<?= $style['bg'] === 'accent' ? ';color:#fff' : '' ?>}
    .preview{position:fixed;top:0;left:0;right:0;background:#111;color:#fff;font-size:13px;text-align:center;padding:6px}
</style>
</head>
<body>
<?php if ($preview && !$page['published']): ?><div class="preview">Preview — this page isn’t published yet.</div><?php endif; ?>
<main class="wrap">
    <?php if ($avatar): ?>
        <img class="avatar" src="<?= e($avatar) ?>" alt="">
    <?php else: ?>
        <div class="avatar"><?= e(initials($page['title'] ?: '?')) ?></div>
    <?php endif; ?>
    <h1><?= e($page['title']) ?></h1>
    <?php if ($page['bio'] !== ''): ?><p class="bio"><?= e($page['bio']) ?></p><?php endif; ?>
    <?php if ($socials): ?>
        <nav class="socials" aria-label="Social profiles">
            <?php foreach ($socials as $href => $a): $abbr = ['x' => 'X', 'mastodon' => 'M', 'bluesky' => 'B', 'facebook' => 'f', 'instagram' => 'IG', 'linkedin' => 'in', 'youtube' => 'YT', 'threads' => '@', 'tiktok' => 'TT', 'pinterest' => 'P'][$a['provider']] ?? '•'; ?>
                <a href="<?= e($href) ?>" target="_blank" rel="noopener me" title="<?= e(provider_meta($a['provider'])['name']) ?>" aria-label="<?= e(provider_meta($a['provider'])['name']) ?>"><?= e($abbr) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>
    <div class="links">
        <?php foreach ($links as $l): ?>
            <a href="<?= e(url('@' . $page['slug'] . '/l/' . $l['id'])) ?>" rel="noopener"><?= e($l['label']) ?></a>
        <?php endforeach; ?>
    </div>
    <?php if ($posts): ?>
        <div class="grid">
            <?php foreach ($posts as $p): ?>
                <a href="<?= e($p['url']) ?>" target="_blank" rel="noopener"><img src="<?= e($p['img']) ?>" alt="<?= e($p['alt']) ?>" loading="lazy"></a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    <div class="foot"><?= e(App\Config::get('app_name', 'MixCreate')) ?></div>
</main>
</body>
</html>
<?php
}

}

namespace {
use App\Workspace;

// ---- view: calendar/index ----
function mc_view_calendar_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php


$canCreate = Workspace::can('member');
$month = $anchor->format('m');
?>
<div class="page-head">
    <h1><?= e($label) ?></h1>
    <div class="head-actions">
        <div class="segmented" role="group" aria-label="View">
            <a href="<?= e(url('/calendar', ['view' => 'month', 'date' => $anchor->format('Y-m-d')])) ?>" class="<?= $view === 'month' ? 'active' : '' ?>">Month</a>
            <a href="<?= e(url('/calendar', ['view' => 'week', 'date' => $anchor->format('Y-m-d')])) ?>" class="<?= $view === 'week' ? 'active' : '' ?>">Week</a>
        </div>
        <a class="icon-btn" href="<?= e(url('/calendar', ['view' => $view, 'date' => $prev])) ?>" aria-label="Previous"><?= icon('chevron-left') ?></a>
        <a class="btn" href="<?= e(url('/calendar', ['view' => $view])) ?>">Today</a>
        <a class="icon-btn" href="<?= e(url('/calendar', ['view' => $view, 'date' => $next])) ?>" aria-label="Next"><?= icon('chevron-right') ?></a>
    </div>
</div>

<?php if ($view === 'month'): ?>
<div class="calendar card">
    <div class="cal-weekdays">
        <?php foreach (['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'] as $d): ?><div><?= $d ?></div><?php endforeach; ?>
    </div>
    <div class="cal-grid">
        <?php foreach ($days as $d):
            $key = $d->format('Y-m-d');
            $items = $byDay[$key] ?? [];
            $classes = ['cal-day'];
            if ($d->format('m') !== $month) $classes[] = 'other-month';
            if ($key === $today) $classes[] = 'today';
            if ($key < $today) $classes[] = 'past';
        ?>
            <div class="<?= implode(' ', $classes) ?>">
                <div class="cal-day-head">
                    <span class="cal-num"><?= $d->format('j') ?></span>
                    <?php if ($canCreate && $key >= $today): ?>
                        <a class="cal-add" href="<?= e(url('/posts/new', ['date' => $key])) ?>" aria-label="New post on <?= e($d->format('F j')) ?>"><?= icon('plus') ?></a>
                    <?php endif; ?>
                </div>
                <div class="cal-items">
                    <?php foreach (array_slice($items, 0, 4) as $p): ?>
                        <a class="cal-item status-<?= e($p['status']) ?>" href="<?= e(url('/posts/' . $p['id'])) ?>" title="<?= e(str_limit($p['content'], 120)) ?>">
                            <span class="cal-time"><?= e($p['time']) ?></span>
                            <span class="cal-dots"><?php foreach (array_slice($p['targets'], 0, 4) as $t) { echo provider_badge($t['provider'], 'pdot'); } ?></span>
                            <span class="cal-text"><?= e(str_limit($p['content'] ?: '(media)', 40)) ?></span>
                        </a>
                    <?php endforeach; ?>
                    <?php if (count($items) > 4): ?>
                        <a class="cal-more" href="<?= e(url('/calendar', ['view' => 'week', 'date' => $key])) ?>">+<?= count($items) - 4 ?> more</a>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>
<?php else: ?>
<div class="week card">
    <?php foreach ($days as $d):
        $key = $d->format('Y-m-d');
        $items = $byDay[$key] ?? [];
    ?>
        <section class="week-day<?= $key === $today ? ' today' : '' ?>">
            <header class="week-day-head">
                <span class="week-dow"><?= $d->format('D') ?></span>
                <span class="week-num"><?= $d->format('j') ?></span>
                <?php if ($canCreate && $key >= $today): ?>
                    <a class="cal-add" href="<?= e(url('/posts/new', ['date' => $key])) ?>" aria-label="New post on <?= e($d->format('F j')) ?>"><?= icon('plus') ?></a>
                <?php endif; ?>
            </header>
            <div class="week-items">
                <?php if (!$items): ?><p class="muted small week-empty">No posts</p><?php endif; ?>
                <?php foreach ($items as $p): ?>
                    <a class="week-item status-<?= e($p['status']) ?>" href="<?= e(url('/posts/' . $p['id'])) ?>">
                        <div class="week-item-top">
                            <span class="cal-time"><?= e($p['time']) ?></span>
                            <?= status_badge($p['status']) ?>
                        </div>
                        <p><?= e(str_limit($p['content'] ?: '(media only)', 140)) ?></p>
                        <div class="avatar-stack"><?php foreach (array_slice($p['targets'], 0, 6) as $t) { echo account_avatar($t, 'xs'); } ?></div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="legend">
    <?php foreach (['scheduled' => 'Scheduled', 'pending_approval' => 'Needs approval', 'published' => 'Published', 'failed' => 'Failed', 'draft' => 'Draft'] as $k => $l): ?>
        <span class="legend-item status-<?= $k ?>"><i></i><?= $l ?></span>
    <?php endforeach; ?>
</div>
<?php
}

}

namespace {
use App\Workspace;

// ---- view: competitors/index ----
function mc_view_competitors_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php


$fmt = fn($n) => $n === null ? '—' : ($n >= 10000 ? number_format($n / 1000, 1) . 'k' : number_format((int) $n));
$ownBy = [];
foreach ($own as $o) {
    $ownBy[$o['provider']][] = $o;
}
?>
<div class="page-head">
    <div>
        <h1>Competitors</h1>
        <p class="muted">Public stats, refreshed daily. Growth builds up from the day you start tracking.</p>
    </div>
    <div class="segmented" role="group" aria-label="Range">
        <?php foreach ([7, 30, 90] as $d): ?>
            <a href="<?= e(url('/competitors', ['days' => $d])) ?>" class="<?= $days === $d ? 'active' : '' ?>"><?= $d ?>d</a>
        <?php endforeach; ?>
    </div>
</div>

<?php if (Workspace::can('member')): ?>
<form class="card field-row align-end" method="post" action="<?= e(url('/competitors')) ?>">
    <?= csrf_field() ?>
    <label class="field"><span>Network</span>
        <select name="network" data-placeholder-target="#comp-handle">
            <?php foreach ($networks as $k => $n): ?><option value="<?= e($k) ?>" data-placeholder="<?= e($n['placeholder']) ?>"><?= e($n['label']) ?></option><?php endforeach; ?>
        </select>
    </label>
    <label class="field grow"><span>Account</span><input id="comp-handle" type="text" name="handle" required placeholder="handle.bsky.social"></label>
    <button class="btn btn-primary" type="submit"><?= icon('plus') ?> Track</button>
</form>
<p class="muted small">X, YouTube and Instagram use one of your connected accounts on that network to look others up (Instagram needs a Facebook Page linked to an Instagram business account). TikTok, LinkedIn, Facebook, Threads and Pinterest don’t offer this.</p>
<?php endif; ?>

<?php if (!$competitors): ?>
    <div class="card empty-state"><?= icon('target', 'empty-icon') ?><p class="muted">Add an account above to start comparing.</p></div>
<?php endif; ?>

<?php foreach ($networks as $key => $n):
    $list = array_values(array_filter($competitors, fn($c) => $c['provider'] === $key));
    if (!$list) continue;
    $max = max(1, max(array_map(fn($c) => (int) $c['followers'], $list)), max(array_map(fn($o) => (int) $o['followers'], $ownBy[$key] ?? [['followers' => 0]])));
?>
<section class="card">
    <div class="card-head"><h2><?= provider_badge($key, 'pdot') ?> <?= e($n['label']) ?></h2></div>
    <div class="table-wrap">
    <table class="table comp-table">
        <thead><tr><th>Account</th><th class="num">Followers</th><th class="num">Change (<?= $days ?>d)</th><th class="num">Posts / 30d</th><th class="num">Avg engagement</th><th>Trend</th><th></th></tr></thead>
        <?php foreach ($ownBy[$key] ?? [] as $o): ?>
            <tr class="is-own">
                <td><span class="acct-cell"><?= account_avatar($o, 'xs') ?> <strong><?= e($o['name']) ?></strong> <span class="badge">You</span></span></td>
                <td class="num"><?= $fmt($o['followers'] !== null ? (int) $o['followers'] : null) ?><span class="bar-mini"><i style="width: <?= round((int) $o['followers'] / $max * 100) ?>%"></i></span></td>
                <td class="num">—</td><td class="num">—</td><td class="num">—</td><td></td><td></td>
            </tr>
        <?php endforeach; ?>
        <?php foreach ($list as $c): ?>
            <tr>
                <td>
                    <span class="acct-cell">
                        <span class="avatar xs"><?= e(initials($c['name'] ?: $c['handle'])) ?><?php if ($c['avatar']): ?><img src="<?= e($c['avatar']) ?>" alt="" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?></span>
                        <span><?= e($c['name'] ?: $c['handle']) ?><br><small class="muted"><?= e($c['handle']) ?></small></span>
                    </span>
                    <?php if ($c['error']): ?><div class="error-text small"><?= e($c['error']) ?></div><?php endif; ?>
                </td>
                <td class="num"><?= $fmt($c['followers']) ?><span class="bar-mini"><i style="width: <?= round((int) $c['followers'] / $max * 100) ?>%"></i></span></td>
                <td class="num <?= ($c['change'] ?? 0) >= 0 ? 'up' : 'down' ?>"><?= $c['change'] === null ? '—' : (($c['change'] >= 0 ? '+' : '') . number_format($c['change'])) ?></td>
                <td class="num"><?= $c['posts_30d'] === null ? ($c['sample'] ? $c['sample'] . '+' : '—') : $c['posts_30d'] ?></td>
                <td class="num"><?= $c['avg_engagement'] === null ? '—' : $c['avg_engagement'] ?></td>
                <td class="spark-cell"><?php if (count($c['series']) > 1): ?><div class="spark" data-chart="spark" data-series="<?= e(json_encode($c['series'])) ?>"></div><?php else: ?><span class="muted small">Building…</span><?php endif; ?></td>
                <td class="right nowrap">
                    <?php if (Workspace::can('member')): ?>
                    <form method="post" action="<?= e(url('/competitors/' . $c['id'] . '/refresh')) ?>" class="inline"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Refresh"><?= icon('refresh') ?></button></form>
                    <form method="post" action="<?= e(url('/competitors/' . $c['id'] . '/delete')) ?>" class="inline" data-confirm="Stop tracking <?= e($c['name'] ?: $c['handle']) ?>?"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Remove"><?= icon('trash') ?></button></form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
</section>
<?php endforeach; ?>
<?php
}

}

namespace {
use App\Auth;
use App\Workspace;

// ---- view: dashboard ----
function mc_view_dashboard(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php



$hour = (int) local_time(now(), 'G');
$greeting = $hour < 12 ? 'Good morning' : ($hour < 18 ? 'Good afternoon' : 'Good evening');
?>
<div class="page-head">
    <div>
        <h1><?= e($greeting) ?>, <?= e(explode(' ', $user['name'])[0]) ?></h1>
        <p class="muted"><?= e(local_time(now(), 'l, F j')) ?></p>
    </div>
    <?php if (Workspace::can('member')): ?>
        <a class="btn btn-primary" href="<?= e(url('/posts/new')) ?>"><?= icon('pen') ?> New post</a>
    <?php endif; ?>
</div>

<?php if ($overdue && ($cronAge === null || $cronAge > 300)): ?>
    <div class="notice notice-warn">
        <?= icon('alert') ?>
        <div>
            <strong><?= $overdue ?> scheduled post<?= $overdue === 1 ? ' is' : 's are' ?> overdue.</strong>
            <?php if (Auth::isAdmin()): ?>
                The scheduler isn’t running<?= $cronAge !== null ? ' (last run ' . e(round($cronAge / 60)) . ' minutes ago)' : '' ?>. Add this cron job on your server:
                <code class="copy" data-copy>* * * * * <?= e(cron_command()) ?> &gt;/dev/null 2&gt;&amp;1</code>
            <?php else: ?>
                Ask your MixCreate admin to check the scheduler.
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php if (!$accounts): ?>
    <div class="card empty-state">
        <?= icon('users', 'empty-icon') ?>
        <h2>Connect your first account</h2>
        <p class="muted">Link X, Mastodon, Bluesky, Instagram and more to start scheduling.</p>
        <a class="btn btn-primary" href="<?= e(url('/accounts')) ?>">Connect accounts</a>
    </div>
<?php endif; ?>

<?php if ($unauthorized): ?>
    <div class="notice notice-error">
        <?= icon('alert') ?>
        <div>
            <strong>Reconnect needed:</strong>
            <?= e(implode(', ', array_map(fn($a) => $a['name'] . ' (' . provider_meta($a['provider'])['name'] . ')', $unauthorized))) ?>.
            Posts to these accounts will fail until they’re reconnected. <a href="<?= e(url('/accounts')) ?>">Go to accounts</a>
        </div>
    </div>
<?php endif; ?>

<div class="stats">
    <a class="stat" href="<?= e(url('/posts', ['status' => 'scheduled'])) ?>">
        <span class="stat-label">Scheduled</span>
        <span class="stat-value"><?= (int) ($counts['scheduled'] ?? 0) ?></span>
    </a>
    <a class="stat" href="<?= e(url('/posts', ['status' => 'pending_approval'])) ?>">
        <span class="stat-label">Needs approval</span>
        <span class="stat-value"><?= (int) ($counts['pending_approval'] ?? 0) ?></span>
    </a>
    <a class="stat" href="<?= e(url('/posts', ['status' => 'draft'])) ?>">
        <span class="stat-label">Drafts</span>
        <span class="stat-value"><?= (int) ($counts['draft'] ?? 0) ?></span>
    </a>
    <a class="stat" href="<?= e(url('/posts', ['status' => 'published'])) ?>">
        <span class="stat-label">Published this week</span>
        <span class="stat-value"><?= $publishedWeek ?></span>
    </a>
</div>

<div class="grid-2">
    <section class="card">
        <div class="card-head">
            <h2>Up next</h2>
            <a class="link" href="<?= e(url('/calendar')) ?>">Calendar</a>
        </div>
        <?php if (!$upcoming): ?>
            <p class="muted">Nothing scheduled.</p>
        <?php endif; ?>
        <div class="post-list compact">
            <?php foreach ($upcoming as $post) { partial('partials/post_row', ['post' => $post]); } ?>
        </div>
    </section>

    <div class="stack">
        <?php if ($failed): ?>
        <section class="card">
            <div class="card-head">
                <h2>Failed</h2>
                <a class="link" href="<?= e(url('/posts', ['status' => 'failed'])) ?>">All failed</a>
            </div>
            <div class="post-list compact">
                <?php foreach ($failed as $post) { partial('partials/post_row', ['post' => $post]); } ?>
            </div>
        </section>
        <?php endif; ?>
        <section class="card">
            <div class="card-head">
                <h2>Recently published</h2>
                <a class="link" href="<?= e(url('/analytics')) ?>">Analytics</a>
            </div>
            <?php if (!$recent): ?>
                <p class="muted">Nothing published yet.</p>
            <?php endif; ?>
            <div class="post-list compact">
                <?php foreach ($recent as $post) { partial('partials/post_row', ['post' => $post]); } ?>
            </div>
        </section>
    </div>
</div>
<?php
}

}

namespace {

// ---- view: developers/index ----
function mc_view_developers_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><div class="page-head">
    <h1>API &amp; webhooks</h1>
</div>

<?php if ($newToken): ?>
    <div class="notice notice-success">
        <?= icon('key') ?>
        <div class="grow"><strong>Copy your token now.</strong> It won’t be shown again.
            <code class="copy" data-copy><?= e($newToken) ?></code></div>
    </div>
<?php endif; ?>
<?php if ($newSecret): ?>
    <div class="notice notice-success">
        <?= icon('key') ?>
        <div class="grow"><strong>Webhook created. Copy its signing secret now.</strong> It won’t be shown again.
            <code class="copy" data-copy><?= e($newSecret) ?></code></div>
    </div>
<?php endif; ?>

<section class="card">
    <h2>API tokens</h2>
    <p class="muted small">Tokens act as you, in this workspace, with your role. Send them as <code>Authorization: Bearer &lt;token&gt;</code> to <code><?= e($apiBase) ?></code>.</p>
    <?php if ($tokens): ?>
    <div class="table-wrap">
    <table class="table">
        <thead><tr><th>Name</th><th>Created</th><th>Last used</th><th></th></tr></thead>
        <?php foreach ($tokens as $t): ?>
            <tr>
                <td><?= e($t['name']) ?></td>
                <td class="muted"><?= e(local_time($t['created_at'], 'M j, Y')) ?></td>
                <td class="muted"><?= $t['last_used_at'] ? e(local_time($t['last_used_at'])) : 'Never' ?></td>
                <td class="right">
                    <form method="post" action="<?= e(url('/developers/tokens/' . $t['id'] . '/delete')) ?>" data-confirm="Revoke this token? Apps using it will stop working.">
                        <?= csrf_field() ?><button class="btn btn-sm" type="submit">Revoke</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <?php endif; ?>
    <form class="field-row align-end" method="post" action="<?= e(url('/developers/tokens')) ?>">
        <?= csrf_field() ?>
        <label class="field grow"><span>Token name</span><input type="text" name="name" placeholder="e.g. Zapier, my script" required></label>
        <button class="btn btn-primary" type="submit">Create token</button>
    </form>
    <p class="muted small">Use a token with Claude or any MCP client at <code class="copy" data-copy><?= e(abs_url('mcp')) ?></code>, or with Looker Studio through the <a href="<?= e(entry_url(['asset' => 'looker-studio-connector.gs'])) ?>" download="looker-studio-connector.gs">community connector script</a>.</p>

    <details class="api-docs">
        <summary>API reference</summary>
        <div class="docs">
            <table class="table">
                <tr><td><code>GET /me</code></td><td>Your user, role and workspace</td></tr>
                <tr><td><code>GET /accounts</code></td><td>Connected accounts</td></tr>
                <tr><td><code>GET /tags</code></td><td>Tags</td></tr>
                <tr><td><code>GET /media</code></td><td>Latest 100 media files</td></tr>
                <tr><td><code>POST /media</code></td><td>Upload a file (multipart field <code>file</code>)</td></tr>
                <tr><td><code>GET /posts?status=&amp;page=&amp;limit=</code></td><td>List posts</td></tr>
                <tr><td><code>POST /posts</code></td><td>Create a post</td></tr>
                <tr><td><code>GET /posts/{id}</code></td><td>Get a post with per-account results</td></tr>
                <tr><td><code>PUT /posts/{id}</code></td><td>Update a post</td></tr>
                <tr><td><code>DELETE /posts/{id}</code></td><td>Delete a post</td></tr>
                <tr><td><code>POST /posts/{id}/approve</code></td><td>Approve (admins)</td></tr>
                <tr><td><code>POST /posts/{id}/reject</code></td><td>Send back with <code>{"note": "…"}</code> (admins)</td></tr>
            </table>
            <p class="small muted">Create or update body:</p>
<pre class="code">{
  "content": "Hello from the API",
  "accounts": [1, 2],
  "media": [5],
  "tags": [3],
  "overrides": { "2": "Different text for account 2" },
  "scheduled_at": "2026-11-03T09:00:00-05:00",
  "action": "schedule"      // "draft", "schedule" or "now"
}</pre>
<pre class="code">curl -X POST <?= e($apiBase) ?>/posts \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"content":"Hi","accounts":[1],"action":"now"}'</pre>
        </div>
    </details>
</section>

<section class="card">
    <h2>Webhooks</h2>
    <p class="muted small">MixCreate POSTs JSON to your URL when things happen. Verify the <code>X-MixCreate-Signature</code> header: <code>sha256=</code> + HMAC-SHA256 of the raw body with the webhook’s secret. Failed deliveries are retried 5 times over about 8 hours.</p>

    <?php foreach ($webhooks as $h): $ev = json_arr($h['events']); ?>
        <details class="lib-item">
            <summary>
                <span class="badge <?= $h['active'] ? 'status-published' : '' ?>"><?= $h['active'] ? 'Active' : 'Paused' ?></span>
                <code class="grow ellipsis"><?= e($h['url']) ?></code>
                <span class="muted small"><?= $ev ? count($ev) . ' events' : 'All events' ?></span>
            </summary>
            <?php if ($isAdmin): ?>
            <form method="post" action="<?= e(url('/developers/webhooks')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="id" value="<?= (int) $h['id'] ?>">
                <label class="field"><span>URL</span><input type="url" name="url" value="<?= e($h['url']) ?>" required></label>
                <fieldset class="field">
                    <legend>Events <small class="muted">(none checked = all)</small></legend>
                    <div class="check-grid">
                        <?php foreach ($events as $k => $l): ?>
                            <label class="check"><input type="checkbox" name="events[]" value="<?= e($k) ?>" <?= in_array($k, $ev, true) ? 'checked' : '' ?>> <span><?= e($l) ?> <code class="small"><?= e($k) ?></code></span></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <label class="check"><input type="checkbox" name="active" value="1" <?= $h['active'] ? 'checked' : '' ?>> <span>Active</span></label>
                <div class="form-actions">
                    <button class="btn btn-ghost btn-danger-text" type="submit" form="del-hook-<?= (int) $h['id'] ?>">Delete</button>
                    <span class="grow"></span>
                    <button class="btn" type="submit" form="test-hook-<?= (int) $h['id'] ?>">Send test ping</button>
                    <button class="btn btn-primary" type="submit">Save</button>
                </div>
            </form>
            <form id="del-hook-<?= (int) $h['id'] ?>" method="post" action="<?= e(url('/developers/webhooks/' . $h['id'] . '/delete')) ?>" data-confirm="Delete this webhook?"><?= csrf_field() ?></form>
            <form id="test-hook-<?= (int) $h['id'] ?>" method="post" action="<?= e(url('/developers/webhooks/' . $h['id'] . '/test')) ?>"><?= csrf_field() ?></form>
            <?php endif; ?>
            <?php if ($h['deliveries']): ?>
                <h3>Recent deliveries</h3>
                <div class="table-wrap">
                <table class="table small">
                    <thead><tr><th>Event</th><th>Status</th><th>Attempts</th><th>When</th></tr></thead>
                    <?php foreach ($h['deliveries'] as $d): ?>
                        <tr>
                            <td><code><?= e($d['event']) ?></code></td>
                            <td><?php if ($d['delivered_at']): ?><span class="badge status-published"><?= (int) $d['status_code'] ?></span>
                                <?php elseif ($d['next_attempt_at']): ?><span class="badge status-scheduled">Retrying<?= $d['status_code'] ? ' · ' . (int) $d['status_code'] : '' ?></span>
                                <?php else: ?><span class="badge status-failed">Failed<?= $d['status_code'] ? ' · ' . (int) $d['status_code'] : '' ?></span><?php endif; ?></td>
                            <td><?= (int) $d['attempts'] ?></td>
                            <td class="muted"><?= e(local_time($d['created_at'])) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </table>
                </div>
            <?php endif; ?>
        </details>
    <?php endforeach; ?>

    <?php if ($isAdmin): ?>
    <details class="add-block">
        <summary class="btn"><?= icon('plus') ?> Add webhook</summary>
        <form method="post" action="<?= e(url('/developers/webhooks')) ?>">
            <?= csrf_field() ?>
            <label class="field"><span>URL</span><input type="url" name="url" required placeholder="https://example.com/hooks/mixcreate"></label>
            <fieldset class="field">
                <legend>Events <small class="muted">(none checked = all)</small></legend>
                <div class="check-grid">
                    <?php foreach ($events as $k => $l): ?>
                        <label class="check"><input type="checkbox" name="events[]" value="<?= e($k) ?>"> <span><?= e($l) ?></span></label>
                    <?php endforeach; ?>
                </div>
            </fieldset>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Add webhook</button></div>
        </form>
    </details>
    <?php elseif (!$webhooks): ?>
        <p class="muted">No webhooks. Workspace admins can add them.</p>
    <?php endif; ?>
</section>
<?php
}

}

namespace {

// ---- view: error ----
function mc_view_error(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><div class="card auth-card empty-state">
    <div class="error-code"><?= e($code) ?></div>
    <p><?= e($message) ?></p>
    <a class="btn" href="<?= e(url('/')) ?>">Go home</a>
</div>
<?php
}

}

namespace {
use App\Workspace;

// ---- view: hashtags/index ----
function mc_view_hashtags_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php


$qs = fn(array $over) => url('/hashtags', array_filter(array_merge(['tag' => $current['id'] ?? null, 'days' => $days, 'from' => $days ? null : $from, 'to' => $days ? null : $to], $over), fn($v) => $v !== null && $v !== ''));
$netName = fn($k) => $networks[$k] ?? $k;
?>
<div class="page-head">
    <div>
        <h1>Hashtag tracker</h1>
        <p class="muted">Follow a campaign or topic hashtag: who’s using it, how often, and which posts take off. Checked every 30 minutes.</p>
    </div>
</div>

<?php if ($tags): ?>
<nav class="tabs">
    <?php foreach ($tags as $t): ?>
        <a href="<?= e(url('/hashtags', ['tag' => $t['id']])) ?>" class="<?= (int) $t['id'] === (int) ($current['id'] ?? 0) ? 'active' : '' ?>">#<?= e($t['tag']) ?> <span class="count"><?= (int) $t['total'] ?></span><?= $t['active'] ? '' : ' <span class="count">(paused)</span>' ?></a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>

<?php if ($current && $r): ?>
    <div class="analytics-filters">
        <div class="segmented" role="group" aria-label="Range">
            <?php foreach ([7, 30, 90] as $d): ?><a href="<?= e($qs(['days' => $d, 'from' => null, 'to' => null])) ?>" class="<?= $days === $d ? 'active' : '' ?>"><?= $d ?>d</a><?php endforeach; ?>
        </div>
        <span class="muted small">On <?= e(implode(', ', array_map($netName, json_arr($current['networks'])))) ?><?= $current['last_checked_at'] ? ' · checked ' . e(local_time($current['last_checked_at'], 'M j, g:i A')) : '' ?></span>
        <span class="grow"></span>
        <?php if (Workspace::can('member')): ?>
            <form method="post" action="<?= e(url('/hashtags/' . $current['id'] . '/refresh')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= icon('refresh') ?> Check now</button></form>
            <a class="btn btn-sm" href="<?= e(url('/hashtags/' . $current['id'] . '/export')) ?>"><?= icon('download') ?> CSV</a>
            <form method="post" action="<?= e(url('/hashtags/' . $current['id'] . '/toggle')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit"><?= $current['active'] ? 'Pause' : 'Resume' ?></button></form>
            <form method="post" action="<?= e(url('/hashtags/' . $current['id'] . '/delete')) ?>" data-confirm="Stop tracking #<?= e($current['tag']) ?> and delete its posts?"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Delete"><?= icon('trash') ?></button></form>
        <?php endif; ?>
    </div>
    <?php if ($current['error']): ?><div class="notice notice-warn"><?= icon('alert') ?><div><?= e($current['error']) ?></div></div><?php endif; ?>
    <div class="stats">
        <div class="stat"><span class="stat-label">Posts</span><span class="stat-value"><?= number_format($r['posts']) ?></span></div>
        <div class="stat"><span class="stat-label">People posting</span><span class="stat-value"><?= number_format($r['authors']) ?></span></div>
        <div class="stat"><span class="stat-label">Engagement</span><span class="stat-value"><?= number_format($r['engagement']) ?></span></div>
        <?php foreach ($r['networks'] as $n): ?>
            <div class="stat"><span class="stat-label"><?= e($netName($n['network'])) ?></span><span class="stat-value"><?= number_format((int) $n['posts']) ?></span></div>
        <?php endforeach; ?>
    </div>
    <section class="card"><h2>Posts per day</h2><div class="chart short" data-chart="bar" data-series="<?= e(json_encode($r['series'])) ?>" data-label="Posts"></div></section>
    <div class="grid-2">
        <section class="card">
            <h2>Top posts</h2>
            <?php if (!$r['top']): ?><p class="muted">None yet.</p><?php endif; ?>
            <?php foreach ($r['top'] as $p): ?>
                <div class="tag-post">
                    <div class="muted small"><?= provider_badge($p['network'], 'pdot') ?> <?= e($p['author_name'] ?: ($p['author_handle'] ? '@' . $p['author_handle'] : $netName($p['network']))) ?> · <?= e(local_time($p['posted_at'], 'M j')) ?> · <?= (int) ($p['likes'] + $p['reposts'] + $p['replies']) ?> engagements</div>
                    <p><?= e(str_limit($p['text'], 220)) ?></p>
                    <?php if ($p['url']): ?><a class="link small" href="<?= e($p['url']) ?>" target="_blank" rel="noopener">Open <?= icon('external') ?></a><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </section>
        <section class="card">
            <h2>Top contributors</h2>
            <?php if (!$r['contributors']): ?><p class="muted">None yet.</p><?php endif; ?>
            <?php foreach ($r['contributors'] as $c): ?>
                <div class="account-row">
                    <span class="avatar xs"><?= e(initials($c['author_name'] ?: $c['author_handle'])) ?><?php if ($c['author_avatar']): ?><img src="<?= e($c['author_avatar']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?></span>
                    <div class="grow small"><strong><?= e($c['author_name'] ?: $c['author_handle']) ?></strong> <span class="muted">@<?= e($c['author_handle']) ?></span></div>
                    <span class="muted small"><?= (int) $c['posts'] ?> posts · <?= (int) $c['engagement'] ?></span>
                </div>
            <?php endforeach; ?>
        </section>
    </div>
<?php elseif (!$tags): ?>
    <div class="card empty-state"><?= icon('hash', 'empty-icon') ?><p class="muted">Track your first hashtag below.</p></div>
<?php endif; ?>

<?php if (Workspace::can('member')): ?>
<details class="card add-block" <?= $tags ? '' : 'open' ?>>
    <summary class="btn btn-sm"><?= icon('plus') ?> Track a hashtag</summary>
    <form method="post" action="<?= e(url('/hashtags')) ?>">
        <?= csrf_field() ?>
        <label class="field"><span>Hashtag</span><input type="text" name="tag" placeholder="#yourcampaign" required></label>
        <fieldset class="field"><legend>Networks</legend>
            <div class="check-grid">
                <label class="check"><input type="checkbox" name="networks[]" value="bluesky" checked> <span>Bluesky</span></label>
                <label class="check"><input type="checkbox" name="networks[]" value="mastodon" checked> <span>Mastodon</span></label>
                <label class="check"><input type="checkbox" name="networks[]" value="x"> <span>X <small class="muted">(needs a connected X account with search access)</small></span></label>
                <label class="check"><input type="checkbox" name="networks[]" value="instagram"> <span>Instagram <small class="muted">(needs a Facebook Page linked to Instagram; 30 hashtags a week)</small></span></label>
            </div>
        </fieldset>
        <div class="form-actions"><button class="btn btn-primary" type="submit">Start tracking</button></div>
    </form>
</details>
<?php endif; ?>
<?php
}

}

namespace {

// ---- view: import/index ----
function mc_view_import_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php
$byId = array_column($accounts, null, 'id');
?>
<div class="page-head">
    <div>
        <h1>Bulk upload</h1>
        <p class="muted">Schedule many posts at once from a CSV file (Excel and Google Sheets can export one).</p>
    </div>
    <a class="btn" href="<?= e(url('/import/sample')) ?>"><?= icon('download') ?> Sample CSV</a>
</div>

<?php if (!$preview): ?>
<form class="card" method="post" action="<?= e(url('/import')) ?>" enctype="multipart/form-data">
    <?= csrf_field() ?>
    <label class="field"><span>CSV file</span><input type="file" name="csv" accept=".csv,text/csv" required></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Preview</button></div>
    <details class="api-docs">
        <summary>Columns</summary>
        <div class="table-wrap">
        <table class="table small">
            <tr><td><code>text</code></td><td>Post text (required)</td></tr>
            <tr><td><code>date</code>, <code>time</code></td><td>When to publish, in your timezone (<?= e(str_replace('_', ' ', user_tz())) ?>). e.g. 2026-11-03 and 09:00</td></tr>
            <tr><td><code>accounts</code></td><td><code>all</code>, or a list separated by semicolons: account names, @usernames, IDs, or platforms (<code>x</code>, <code>bluesky</code>, <code>instagram</code>…)</td></tr>
            <tr><td><code>media</code></td><td>Image/video URLs or media IDs, separated by spaces or semicolons. URLs are downloaded into your library.</td></tr>
            <tr><td><code>first_comment</code></td><td>Optional first comment</td></tr>
            <tr><td><code>tags</code></td><td>Tag names, separated by semicolons (created if new)</td></tr>
            <tr><td><code>status</code></td><td><code>schedule</code> (default) or <code>draft</code></td></tr>
        </table>
        </div>
    </details>
</form>
<?php else:
    $good = count(array_filter($preview['rows'], fn($r) => !$r['errors']));
?>
<div class="notice <?= $good === count($preview['rows']) ? 'notice-success' : 'notice-warn' ?>">
    <?= icon($good === count($preview['rows']) ? 'check' : 'alert') ?>
    <div class="grow"><strong><?= $good ?> of <?= count($preview['rows']) ?> rows are ready.</strong> Rows with problems will be skipped.</div>
</div>
<section class="card">
    <div class="table-wrap">
    <table class="table small">
        <thead><tr><th>Line</th><th>When</th><th>Text</th><th>Accounts</th><th>Media</th><th></th></tr></thead>
        <?php foreach ($preview['rows'] as $r): ?>
            <tr>
                <td class="muted"><?= (int) $r['line'] ?></td>
                <td class="nowrap"><?= $r['status'] === 'draft' ? '<span class="badge status-draft">Draft</span>' : e($r['scheduled_at'] ? local_time($r['scheduled_at'], 'M j, g:i A') : '—') ?></td>
                <td><?= e(str_limit($r['text'], 80)) ?><?php if ($r['first_comment'] !== ''): ?><div class="muted">+ first comment</div><?php endif; ?></td>
                <td><div class="avatar-stack" style="margin: 0"><?php foreach ($r['accounts'] as $aid) { if (isset($byId[$aid])) echo account_avatar($byId[$aid], 'xs'); } ?></div></td>
                <td><?= count($r['media']) ?: '—' ?></td>
                <td><?php if ($r['errors']): ?><span class="error-text"><?= e(implode(' ', $r['errors'])) ?></span><?php else: ?><span class="badge status-published">OK</span><?php endif; ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
    </div>
    <div class="form-actions">
        <form method="post" action="<?= e(url('/import/cancel')) ?>"><?= csrf_field() ?><button class="btn" type="submit">Start over</button></form>
        <form method="post" action="<?= e(url('/import/confirm')) ?>"><?= csrf_field() ?><button class="btn btn-primary" type="submit" <?= $good ? '' : 'disabled' ?>>Import <?= $good ?> post<?= $good === 1 ? '' : 's' ?></button></form>
    </div>
</section>
<?php endif; ?>
<?php
}

}

namespace {
use App\Workspace;

// ---- view: inbox/index ----
function mc_view_inbox_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php


$open = (int) ($counts['new'] ?? 0) + (int) ($counts['read'] ?? 0);
$tabs = ['open' => ['Open', $open], 'replied' => ['Replied', (int) ($counts['replied'] ?? 0)], 'archived' => ['Archived', (int) ($counts['archived'] ?? 0)], 'all' => ['All', array_sum($counts)]];
$canReply = Workspace::can('member');
?>
<div class="page-head">
    <div>
        <h1>Inbox</h1>
        <p class="muted">Comments and replies on posts published in the last 14 days. Checked every 15 minutes.</p>
    </div>
    <div class="head-actions">
        <?php if (($counts['new'] ?? 0) > 0): ?>
            <form method="post" action="<?= e(url('/inbox/read-all')) ?>"><?= csrf_field() ?><button class="btn" type="submit">Mark all read</button></form>
        <?php endif; ?>
        <form method="post" action="<?= e(url('/inbox/refresh')) ?>"><?= csrf_field() ?><button class="btn" type="submit"><?= icon('refresh') ?> Check now</button></form>
    </div>
</div>

<nav class="tabs">
    <?php foreach ($tabs as $k => [$label, $n]): ?>
        <a href="<?= e(url('/inbox', array_filter(['status' => $k, 'account' => $accountId ?: null]))) ?>" class="<?= $status === $k ? 'active' : '' ?>"><?= e($label) ?> <span class="count"><?= $n ?></span></a>
    <?php endforeach; ?>
</nav>

<form class="filters" method="get" action="<?= e(url('/inbox')) ?>">
    <input type="hidden" name="status" value="<?= e($status) ?>">
    <select name="account" data-autosubmit aria-label="Account">
        <option value="">All accounts</option>
        <?php foreach ($accounts as $a): ?>
            <option value="<?= (int) $a['id'] ?>" <?= $accountId === (int) $a['id'] ? 'selected' : '' ?>><?= e(provider_meta($a['provider'])['name'] . ' · ' . $a['name']) ?></option>
        <?php endforeach; ?>
    </select>
</form>

<?php if (!$items): ?>
    <div class="card empty-state">
        <?= icon('inbox', 'empty-icon') ?>
        <p class="muted">Nothing here. Comments show up for <?= e(implode(', ', $supported)) ?>.</p>
    </div>
<?php else: ?>
<div class="inbox-list">
    <?php foreach ($items as $i): ?>
        <article class="card inbox-item<?= $i['status'] === 'new' ? ' is-new' : '' ?>" data-inbox-item="<?= (int) $i['id'] ?>">
            <div class="inbox-head">
                <span class="avatar sm"><?= e(initials($i['author_name'] ?: $i['author_handle'] ?: '?')) ?><?php if ($i['author_avatar']): ?><img src="<?= e($i['author_avatar']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" onerror="this.remove()"><?php endif; ?></span>
                <div class="grow">
                    <strong><?= e($i['author_name'] ?: $i['author_handle']) ?></strong>
                    <?php if ($i['author_handle'] && $i['author_handle'] !== $i['author_name']): ?><span class="muted small">@<?= e($i['author_handle']) ?></span><?php endif; ?>
                    <div class="muted small"><?= provider_badge($i['provider'], 'pdot') ?> on <?= e($i['account_name']) ?> · <?= e(local_time($i['remote_created_at'], 'M j, g:i A')) ?></div>
                </div>
                <?php if ($i['status'] === 'new'): ?><span class="badge status-scheduled">New</span><?php endif; ?>
                <?php if ($i['status'] === 'replied'): ?><span class="badge status-published">Replied</span><?php endif; ?>
            </div>
            <p class="inbox-text"><?= nl2br(e($i['text'])) ?></p>
            <?php if ($i['post_content'] !== null): ?>
                <a class="inbox-context" href="<?= e(url('/posts/' . $i['post_id'])) ?>">On: <?= e(str_limit($i['post_content'] ?: '(media)', 100)) ?></a>
            <?php endif; ?>
            <?php if ($i['status'] === 'replied'): ?>
                <div class="inbox-reply-done"><?= icon('reply') ?> <span><?= e($i['reply_text']) ?></span> <span class="muted small">— <?= e($i['replied_by_name'] ?? '') ?>, <?= e(local_time($i['replied_at'], 'M j')) ?></span></div>
            <?php elseif ($canReply): ?>
                <form class="inbox-reply" method="post" action="<?= e(url('/inbox/' . $i['id'] . '/reply')) ?>">
                    <?= csrf_field() ?>
                    <textarea name="text" rows="1" placeholder="Reply as <?= e($i['account_name']) ?>…" required></textarea>
                    <button class="btn btn-primary btn-sm" type="submit">Reply</button>
                </form>
            <?php endif; ?>
            <div class="inbox-actions">
                <?php if ($i['url']): ?><a class="link" href="<?= e($i['url']) ?>" target="_blank" rel="noopener">Open <?= icon('external') ?></a><?php endif; ?>
                <?php if ($canReply): ?>
                    <?php if ($i['status'] !== 'archived'): ?>
                        <form method="post" action="<?= e(url('/inbox/' . $i['id'] . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="archived"><button class="link" type="submit">Archive</button></form>
                    <?php else: ?>
                        <form method="post" action="<?= e(url('/inbox/' . $i['id'] . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="read"><button class="link" type="submit">Unarchive</button></form>
                    <?php endif; ?>
                    <?php if ($i['status'] === 'new'): ?>
                        <form method="post" action="<?= e(url('/inbox/' . $i['id'] . '/status')) ?>"><?= csrf_field() ?><input type="hidden" name="status" value="read"><button class="link" type="submit">Mark read</button></form>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </article>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php
}

}

namespace {
use App\Auth;
use App\Db;
use App\Hooks;
use App\Workspace;

// ---- view: layouts/app ----
function mc_view_layouts_app(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php





$user = Auth::user();
$ws = Workspace::current();
$workspaces = $user ? Workspace::forUser((int) $user['id']) : [];
$path = '/' . trim(substr((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH), strlen(base_path())), '/');
$pending = $ws && Workspace::can('admin')
    ? (int) Db::value("SELECT COUNT(*) FROM posts WHERE workspace_id = ? AND status = 'pending_approval'", [$ws['id']])
    : 0;
$unread = $ws ? (int) Db::value("SELECT COUNT(*) FROM inbox_items WHERE workspace_id = ? AND status = 'new'", [$ws['id']]) : 0;

$nav = Hooks::filter('nav', [
    ['label' => 'Dashboard', 'href' => '/', 'icon' => 'home', 'match' => '#^/$#'],
    ['label' => 'Inbox', 'href' => '/inbox', 'icon' => 'inbox', 'match' => '#^/inbox#', 'badge' => $unread ?: null],
    ['section' => 'Publish'],
    ['label' => 'Calendar', 'href' => '/calendar', 'icon' => 'calendar', 'match' => '#^/calendar#'],
    ['label' => 'Posts', 'href' => '/posts', 'icon' => 'list', 'match' => '#^/(posts(?!/new)|import)#', 'badge' => $pending ?: null],
    ['label' => 'Autolists', 'href' => '/autolists', 'icon' => 'refresh', 'match' => '#^/autolists#'],
    ['label' => 'Media', 'href' => '/media', 'icon' => 'image', 'match' => '#^/media#'],
    ['label' => 'Library', 'href' => '/library', 'icon' => 'book', 'match' => '#^/library#'],
    ['label' => 'Link in bio', 'href' => '/bio', 'icon' => 'link', 'match' => '#^/bio#'],
    ['section' => 'Measure'],
    ['label' => 'Analytics', 'href' => '/analytics', 'icon' => 'chart', 'match' => '#^/analytics#'],
    ['label' => 'Reports', 'href' => '/reports', 'icon' => 'file', 'match' => '#^/reports#'],
    ['label' => 'Competitors', 'href' => '/competitors', 'icon' => 'target', 'match' => '#^/competitors#'],
    ['label' => 'Hashtags', 'href' => '/hashtags', 'icon' => 'hash', 'match' => '#^/hashtags#'],
    ['label' => 'Website', 'href' => '/websites', 'icon' => 'globe', 'match' => '#^/websites#'],
    ['label' => 'Ads', 'href' => '/ads', 'icon' => 'megaphone', 'match' => '#^/ads#'],
]);
$wsNav = [
    ['label' => 'Accounts', 'href' => '/accounts', 'icon' => 'users', 'match' => '#^/accounts#'],
    ['label' => 'Team', 'href' => '/members', 'icon' => 'team', 'match' => '#^/members#'],
    ['label' => 'API & webhooks', 'href' => '/developers', 'icon' => 'code', 'match' => '#^/developers#'],
];
if (Workspace::can('admin')) {
    $wsNav[] = ['label' => 'Settings', 'href' => '/settings', 'icon' => 'settings', 'match' => '#^/settings#'];
}
$adminNav = Auth::isAdmin() ? [
    ['label' => 'Platform API keys', 'href' => '/admin/services', 'icon' => 'key', 'match' => '#^/admin/services#'],
    ['label' => 'Integrations', 'href' => '/admin/integrations', 'icon' => 'plug', 'match' => '#^/admin/integrations#'],
    ['label' => 'Users & workspaces', 'href' => '/admin/users', 'icon' => 'shield', 'match' => '#^/admin/users#'],
] : [];

$navLink = function (array $item) use ($path): string {
    if (isset($item['section'])) {
        return '<div class="nav-section">' . e($item['section']) . '</div>';
    }
    $active = preg_match($item['match'] ?? '#^' . preg_quote($item['href'], '#') . '#', $path);
    $badge = !empty($item['badge']) ? '<span class="nav-badge">' . e($item['badge']) . '</span>' : '';
    return '<a href="' . e(url($item['href'])) . '" class="nav-link' . ($active ? ' active' : '') . '"' . ($active ? ' aria-current="page"' : '') . '>'
        . icon($item['icon'] ?? 'dot') . '<span>' . e($item['label']) . '</span>' . $badge . '</a>';
};
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(base_path()) ?>">
<meta name="mc-scope" content="<?= $user ? (int) $user['id'] . '-' . ($ws ? (int) $ws['id'] : 0) : '' ?>">
<title><?= e(($title ? $title . ' · ' : '') . App\Config::get('app_name', 'MixCreate')) ?></title>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<link rel="manifest" href="<?= e(entry_url(['asset' => 'manifest.webmanifest'])) ?>">
<meta name="mc-sw" content="<?= e(entry_url(['asset' => 'sw.js'])) ?>">
<link rel="apple-touch-icon" href="<?= e(asset('icon-192.png')) ?>">
<meta name="theme-color" content="#4f46e5">
<meta name="apple-mobile-web-app-capable" content="yes">
<script>try{var t=localStorage.getItem('mc-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
</head>
<body>
<div class="shell">
    <aside class="sidebar" id="sidebar">
        <div class="sidebar-head">
            <a class="brand" href="<?= e(url('/')) ?>"><?= icon('logo', 'brand-mark') ?><span><?= e(App\Config::get('app_name', 'MixCreate')) ?></span></a>
            <button class="icon-btn sidebar-close" type="button" data-toggle-sidebar aria-label="Close menu"><?= icon('x') ?></button>
        </div>

        <?php if ($ws): ?>
        <details class="ws-switch">
            <summary>
                <span class="ws-dot" style="background: <?= e($ws['color']) ?>"></span>
                <span class="ws-name"><?= e($ws['name']) ?></span>
                <?= icon('chevrons') ?>
            </summary>
            <div class="ws-menu">
                <?php foreach ($workspaces as $w): ?>
                    <form method="post" action="<?= e(url('/workspaces/' . $w['id'] . '/switch')) ?>">
                        <?= csrf_field() ?>
                        <button type="submit" class="ws-item<?= (int) $w['id'] === (int) $ws['id'] ? ' current' : '' ?>">
                            <span class="ws-dot" style="background: <?= e($w['color']) ?>"></span>
                            <span><?= e($w['name']) ?></span>
                            <small><?= e(Workspace::ROLES[$w['role']] ?? $w['role']) ?></small>
                        </button>
                    </form>
                <?php endforeach; ?>
                <?php if (Auth::isAdmin()): ?>
                    <a class="ws-item" href="<?= e(url('/workspaces/new')) ?>"><?= icon('plus') ?><span>New workspace</span></a>
                <?php endif; ?>
            </div>
        </details>

        <?php if (Workspace::can('member')): ?>
            <a class="btn btn-primary btn-block new-post" href="<?= e(url('/posts/new')) ?>"><?= icon('pen') ?> New post</a>
        <?php endif; ?>
        <?php endif; ?>

        <nav class="nav" aria-label="Main">
            <?php if ($ws): foreach ($nav as $item) { echo $navLink($item); } ?>
            <div class="nav-section">Workspace</div>
            <?php foreach ($wsNav as $item) { echo $navLink($item); } endif; ?>
            <?php if ($adminNav): ?>
                <div class="nav-section">Instance</div>
                <?php foreach ($adminNav as $item) { echo $navLink($item); } ?>
            <?php endif; ?>
        </nav>

        <div class="sidebar-foot">
            <a class="user-chip" href="<?= e(url('/profile')) ?>">
                <span class="avatar sm"><?= e(initials($user['name'] ?? '')) ?></span>
                <span class="user-meta"><strong><?= e($user['name'] ?? '') ?></strong><small><?= e($user['email'] ?? '') ?></small></span>
            </a>
            <div class="foot-actions">
                <button class="icon-btn" type="button" data-theme-toggle aria-label="Toggle dark mode"><?= icon('moon') ?></button>
                <form method="post" action="<?= e(url('/logout')) ?>" data-logout>
                    <?= csrf_field() ?>
                    <button class="icon-btn" type="submit" aria-label="Sign out" title="Sign out"><?= icon('logout') ?></button>
                </form>
            </div>
        </div>
    </aside>
    <div class="scrim" data-toggle-sidebar></div>

    <div class="main">
        <header class="topbar">
            <button class="icon-btn" type="button" data-toggle-sidebar aria-label="Open menu"><?= icon('menu') ?></button>
            <span class="topbar-title"><?= e($title) ?></span>
            <?php if ($ws && Workspace::can('member')): ?>
                <a class="icon-btn" href="<?= e(url('/posts/new')) ?>" aria-label="New post"><?= icon('pen') ?></a>
            <?php endif; ?>
        </header>

        <div class="offline-bar" id="offline-bar" role="status" hidden></div>

        <div class="flashes" aria-live="polite">
            <?php foreach (flashes() as $f): ?>
                <div class="flash flash-<?= e($f['type']) ?>" role="status">
                    <?= icon($f['type'] === 'error' ? 'alert' : 'check') ?>
                    <span><?= e($f['message']) ?></span>
                    <button type="button" class="flash-close" aria-label="Dismiss"><?= icon('x') ?></button>
                </div>
            <?php endforeach; ?>
        </div>

        <main class="content">
            <?= $content ?>
        </main>
    </div>
</div>
<div class="toast-root" id="toasts" aria-live="polite"></div>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
<?php
}

}

namespace {

// ---- view: layouts/auth ----
function mc_view_layouts_auth(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e(($title ? $title . ' · ' : '') . App\Config::get('app_name', 'MixCreate')) ?></title>
<link rel="icon" href="<?= e(asset('favicon.svg')) ?>" type="image/svg+xml">
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<script>try{var t=localStorage.getItem('mc-theme');if(t)document.documentElement.dataset.theme=t;}catch(e){}</script>
</head>
<body class="auth-body">
<main class="auth-wrap">
    <a class="brand auth-brand" href="<?= e(url('/')) ?>"><?= icon('logo', 'brand-mark') ?><span><?= e(App\Config::get('app_name', 'MixCreate')) ?></span></a>
    <div class="flashes">
        <?php foreach (flashes() as $f): ?>
            <div class="flash flash-<?= e($f['type']) ?>" role="status">
                <?= icon($f['type'] === 'error' ? 'alert' : 'check') ?><span><?= e($f['message']) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?= $content ?>
</main>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
<?php
}

}

namespace {
use App\Workspace;

// ---- view: library/index ----
function mc_view_library_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php


$canEdit = Workspace::can('member');
$isAdmin = Workspace::can('admin');
?>
<div class="page-head">
    <h1>Library</h1>
</div>
<nav class="tabs" data-tabs>
    <a href="#templates">Templates</a>
    <a href="#hashtags">Hashtag groups</a>
    <a href="#variables">Variables</a>
    <a href="#tags">Tags</a>
</nav>

<section class="tab-panel" id="templates">
    <div class="card">
        <div class="card-head"><h2>Templates</h2></div>
        <p class="muted small">Reusable post text. Pick one from the composer toolbar, or start a post from it here.</p>
        <?php if (!$templates): ?><p class="muted">No templates yet.</p><?php endif; ?>
        <div class="lib-list">
            <?php foreach ($templates as $t): ?>
                <details class="lib-item">
                    <summary>
                        <strong><?= e($t['name']) ?></strong>
                        <span class="muted small grow"><?= e(str_limit($t['content'], 90)) ?></span>
                        <?php if ($canEdit): ?><a class="btn btn-sm" href="<?= e(url('/posts/new', ['template' => $t['id']])) ?>">Use</a><?php endif; ?>
                    </summary>
                    <?php if ($canEdit): ?>
                    <form method="post" action="<?= e(url('/library/templates')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $t['id'] ?>">
                        <?php foreach (json_arr($t['media']) as $mid): ?><input type="hidden" name="media[]" value="<?= (int) $mid ?>"><?php endforeach; ?>
                        <label class="field"><span>Name</span><input type="text" name="name" value="<?= e($t['name']) ?>" required></label>
                        <label class="field"><span>Text</span><textarea name="content" rows="5"><?= e($t['content']) ?></textarea></label>
                        <div class="form-actions">
                            <button class="btn btn-ghost btn-danger-text" type="submit" form="del-tpl-<?= (int) $t['id'] ?>">Delete</button>
                            <span class="grow"></span>
                            <button class="btn btn-primary" type="submit">Save</button>
                        </div>
                    </form>
                    <form id="del-tpl-<?= (int) $t['id'] ?>" method="post" action="<?= e(url('/library/templates/' . $t['id'] . '/delete')) ?>" data-confirm="Delete this template?"><?= csrf_field() ?></form>
                    <?php else: ?>
                        <pre class="pre-wrap"><?= e($t['content']) ?></pre>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        </div>
        <?php if ($canEdit): ?>
        <details class="add-block">
            <summary class="btn"><?= icon('plus') ?> New template</summary>
            <form method="post" action="<?= e(url('/library/templates')) ?>">
                <?= csrf_field() ?>
                <label class="field"><span>Name</span><input type="text" name="name" required></label>
                <label class="field"><span>Text</span><textarea name="content" rows="5" placeholder="Use {{variables}} if you like"></textarea></label>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Add template</button></div>
            </form>
        </details>
        <?php endif; ?>
    </div>
</section>

<section class="tab-panel" id="hashtags">
    <div class="card">
        <div class="card-head"><h2>Hashtag groups</h2></div>
        <p class="muted small">Saved sets of hashtags you can drop into a post with one click.</p>
        <?php if (!$groups): ?><p class="muted">No hashtag groups yet.</p><?php endif; ?>
        <div class="lib-list">
            <?php foreach ($groups as $g): ?>
                <details class="lib-item">
                    <summary><strong><?= e($g['name']) ?></strong><span class="muted small grow"><?= e(str_limit($g['hashtags'], 90)) ?></span></summary>
                    <?php if ($canEdit): ?>
                    <form method="post" action="<?= e(url('/library/hashtags')) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int) $g['id'] ?>">
                        <label class="field"><span>Name</span><input type="text" name="name" value="<?= e($g['name']) ?>" required></label>
                        <label class="field"><span>Hashtags</span><textarea name="hashtags" rows="3"><?= e($g['hashtags']) ?></textarea></label>
                        <div class="form-actions">
                            <button class="btn btn-ghost btn-danger-text" type="submit" form="del-grp-<?= (int) $g['id'] ?>">Delete</button>
                            <span class="grow"></span>
                            <button class="btn btn-primary" type="submit">Save</button>
                        </div>
                    </form>
                    <form id="del-grp-<?= (int) $g['id'] ?>" method="post" action="<?= e(url('/library/hashtags/' . $g['id'] . '/delete')) ?>" data-confirm="Delete this group?"><?= csrf_field() ?></form>
                    <?php endif; ?>
                </details>
            <?php endforeach; ?>
        </div>
        <?php if ($canEdit): ?>
        <details class="add-block">
            <summary class="btn"><?= icon('plus') ?> New hashtag group</summary>
            <form method="post" action="<?= e(url('/library/hashtags')) ?>">
                <?= csrf_field() ?>
                <label class="field"><span>Name</span><input type="text" name="name" required placeholder="e.g. Product launch"></label>
                <label class="field"><span>Hashtags</span><textarea name="hashtags" rows="3" placeholder="#launch #startup design, product"></textarea><small class="hint">Separate with spaces or commas; # is added for you.</small></label>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Add group</button></div>
            </form>
        </details>
        <?php endif; ?>
    </div>
</section>

<section class="tab-panel" id="variables">
    <div class="card">
        <div class="card-head"><h2>Variables</h2></div>
        <p class="muted small">Write <code>{{name}}</code> in any post and it’s replaced with the value when publishing. Change a value once and every scheduled post picks it up.</p>
        <?php if (!$variables): ?><p class="muted">No variables yet.</p><?php endif; ?>
        <div class="table-wrap">
        <table class="table">
            <?php foreach ($variables as $v): ?>
                <tr>
                    <td><code>{{<?= e($v['name']) ?>}}</code></td>
                    <td class="grow"><?php if ($isAdmin): ?>
                        <form class="inline-form" method="post" action="<?= e(url('/library/variables')) ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="id" value="<?= (int) $v['id'] ?>">
                            <input type="hidden" name="name" value="<?= e($v['name']) ?>">
                            <input type="text" name="value" value="<?= e($v['value']) ?>" aria-label="Value">
                            <button class="btn btn-sm" type="submit">Save</button>
                        </form>
                    <?php else: ?><?= e($v['value']) ?><?php endif; ?></td>
                    <td class="right"><?php if ($isAdmin): ?>
                        <form method="post" action="<?= e(url('/library/variables/' . $v['id'] . '/delete')) ?>" data-confirm="Delete {{<?= e($v['name']) ?>}}? Posts using it will show the raw placeholder.">
                            <?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Delete"><?= icon('trash') ?></button>
                        </form>
                    <?php endif; ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php if ($isAdmin): ?>
        <form class="field-row align-end" method="post" action="<?= e(url('/library/variables')) ?>">
            <?= csrf_field() ?>
            <label class="field"><span>Name</span><input type="text" name="name" required pattern="[\w.\-]{1,40}" placeholder="website"></label>
            <label class="field grow"><span>Value</span><input type="text" name="value" placeholder="https://example.com"></label>
            <button class="btn btn-primary" type="submit">Add</button>
        </form>
        <?php endif; ?>
    </div>
</section>

<section class="tab-panel" id="tags">
    <div class="card">
        <div class="card-head"><h2>Tags</h2></div>
        <p class="muted small">Label posts by campaign or topic, then filter the post list by tag.</p>
        <div class="tag-cloud">
            <?php foreach ($tags as $t): ?>
                <span class="tag big" style="--tc: <?= e($t['color']) ?>">
                    <?= e($t['name']) ?> <small><?= (int) $t['posts'] ?></small>
                    <?php if ($canEdit): ?>
                    <form method="post" action="<?= e(url('/tags/' . $t['id'] . '/delete')) ?>" data-confirm="Delete the tag “<?= e($t['name']) ?>”?">
                        <?= csrf_field() ?><button type="submit" class="tag-x" aria-label="Delete tag"><?= icon('x') ?></button>
                    </form>
                    <?php endif; ?>
                </span>
            <?php endforeach; ?>
            <?php if (!$tags): ?><p class="muted">No tags yet.</p><?php endif; ?>
        </div>
        <?php if ($canEdit): ?>
        <form class="field-row align-end" method="post" action="<?= e(url('/tags')) ?>">
            <?= csrf_field() ?>
            <label class="field grow"><span>New tag</span><input type="text" name="name" maxlength="40" required></label>
            <label class="field"><span>Color</span><input type="color" name="color" value="#4f46e5"></label>
            <button class="btn btn-primary" type="submit">Add</button>
        </form>
        <?php endif; ?>
    </div>
</section>
<?php
}

}

namespace {
use App\Workspace;

// ---- view: media/index ----
function mc_view_media_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php  ?>
<div class="page-head">
    <h1>Media</h1>
    <?php if ((App\Canva::enabled() || App\Drive::enabled()) && Workspace::can('member')): ?>
        <div class="head-actions">
            <?php foreach (['canva' => ['Canva', 'palette', App\Canva::class], 'drive' => ['Google Drive', 'download', App\Drive::class]] as $src => [$label, $ic, $cls]): if (!$cls::enabled()) continue; ?>
                <?php if ($cls::connected((int) App\Auth::id())): ?>
                    <button class="btn" type="button" data-open-dialog="cloud-dialog" data-cloud-page="<?= $src ?>"><?= icon($ic) ?> Import from <?= e($label) ?></button>
                <?php else: ?>
                    <a class="btn" href="<?= e(url('/' . $src . '/connect', ['back' => '/media'])) ?>"><?= icon($ic) ?> Connect <?= e($label) ?></a>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
<dialog id="cloud-dialog" class="dialog dialog-wide">
    <div class="dialog-head"><h2 id="cloud-title">Import</h2><button type="button" class="icon-btn" data-close-dialog aria-label="Close"><?= icon('x') ?></button></div>
    <div class="canva-head"><input type="search" id="canva-q" placeholder="Search"></div>
    <div class="media-grid" id="canva-grid"><p class="muted">Loading…</p></div>
    <div class="center"><button type="button" class="btn btn-sm" id="canva-more" hidden>Load more</button></div>
</dialog>

<?php if (Workspace::can('member')): ?>
<form class="dropzone-form" method="post" action="<?= e(url('/media')) ?>" enctype="multipart/form-data" data-media-page>
    <?= csrf_field() ?>
    <label class="dropzone">
        <input type="file" name="file" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,video/webm" multiple hidden>
        <?= icon('upload') ?>
        <span><strong>Upload files</strong> or drop them here</span>
        <small class="muted">Up to <?= (int) App\Config::get('max_upload_mb', 200) ?> MB each</small>
    </label>
    <div class="upload-progress"></div>
</form>
<?php endif; ?>

<?php if (!$media): ?>
    <div class="card empty-state"><?= icon('image', 'empty-icon') ?><p class="muted">No media uploaded yet.</p></div>
<?php else: ?>
<div class="media-grid page-media">
    <?php foreach ($media as $m): ?>
        <figure class="media-tile">
            <a href="<?= e($m['url']) ?>" target="_blank" rel="noopener" class="media-thumb">
                <?php if ($m['is_video']): ?>
                    <video src="<?= e($m['url']) ?>#t=0.1" muted preload="metadata"></video><span class="play"><?= icon('play') ?></span>
                <?php else: ?>
                    <img src="<?= e($m['url']) ?>" alt="<?= e($m['name']) ?>" loading="lazy">
                <?php endif; ?>
            </a>
            <figcaption>
                <span class="media-name" title="<?= e($m['name']) ?>"><?= e($m['name']) ?></span>
                <span class="muted small"><?= e(number_format($m['size'] / 1048576, 1)) ?> MB<?= $m['width'] ? ' · ' . $m['width'] . '×' . $m['height'] : '' ?></span>
            </figcaption>
            <?php if (Workspace::can('member')): ?>
            <form method="post" action="<?= e(url('/media/' . $m['id'] . '/delete')) ?>" data-confirm="Delete this file? Posts using it will lose it.">
                <?= csrf_field() ?>
                <button class="icon-btn media-del" type="submit" aria-label="Delete"><?= icon('trash') ?></button>
            </form>
            <?php endif; ?>
        </figure>
    <?php endforeach; ?>
</div>
<?php endif; ?>
<?php
}

}

namespace {
use App\Auth;
use App\Workspace;

// ---- view: members/index ----
function mc_view_members_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php



$isAdmin = Workspace::can('admin');
unset($_SESSION['_invite_link']);
?>
<div class="page-head">
    <h1>Team</h1>
</div>

<?php if ($inviteLink): ?>
    <div class="notice notice-success">
        <?= icon('check') ?>
        <div class="grow">
            <strong>Invitation created for <?= e($inviteLink['email']) ?>.</strong> Send them this link (valid for 7 days):
            <code class="copy" data-copy><?= e($inviteLink['url']) ?></code>
        </div>
    </div>
<?php endif; ?>

<section class="card">
    <h2>Members</h2>
    <div class="account-list">
        <?php foreach ($members as $m): ?>
            <div class="account-row">
                <span class="avatar"><?= e(initials($m['name'])) ?></span>
                <div class="grow">
                    <strong><?= e($m['name']) ?><?= (int) $m['id'] === Auth::id() ? ' <span class="muted small">(you)</span>' : '' ?></strong>
                    <div class="muted small"><?= e($m['email']) ?></div>
                </div>
                <?php if ($isAdmin): ?>
                    <form method="post" action="<?= e(url('/members/' . $m['id'] . '/role')) ?>">
                        <?= csrf_field() ?>
                        <select name="role" data-autosubmit aria-label="Role for <?= e($m['name']) ?>">
                            <?php foreach (Workspace::ROLES as $k => $l): ?>
                                <option value="<?= e($k) ?>" <?= $m['role'] === $k ? 'selected' : '' ?>><?= e($l) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                    <form method="post" action="<?= e(url('/members/' . $m['id'] . '/remove')) ?>" data-confirm="Remove <?= e($m['name']) ?> from this workspace?">
                        <?= csrf_field() ?>
                        <button class="icon-btn" type="submit" aria-label="Remove"><?= icon('trash') ?></button>
                    </form>
                <?php else: ?>
                    <span class="badge"><?= e(Workspace::ROLES[$m['role']] ?? $m['role']) ?></span>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</section>

<?php if ($isAdmin): ?>
<section class="card">
    <h2>Invite someone</h2>
    <form class="field-row align-end" method="post" action="<?= e(url('/members/invite')) ?>">
        <?= csrf_field() ?>
        <label class="field grow">
            <span>Email</span>
            <input type="email" name="email" required placeholder="name@example.com">
        </label>
        <label class="field">
            <span>Role</span>
            <select name="role">
                <option value="member">Member</option>
                <option value="admin">Admin</option>
                <option value="viewer">Viewer</option>
            </select>
        </label>
        <button class="btn btn-primary" type="submit">Create invite link</button>
    </form>
    <dl class="role-help">
        <div><dt>Admin</dt><dd>Manages accounts, team, settings and approvals.</dd></div>
        <div><dt>Member</dt><dd>Creates and schedules posts (needs approval if that’s turned on).</dd></div>
        <div><dt>Viewer</dt><dd>Sees the calendar, posts and analytics, but can’t change anything.</dd></div>
    </dl>

    <?php if ($invitations): ?>
        <h3>Pending invitations</h3>
        <div class="account-list">
            <?php foreach ($invitations as $i): ?>
                <div class="account-row">
                    <span class="avatar"><?= icon('mail') ?></span>
                    <div class="grow"><strong><?= e($i['email']) ?></strong><div class="muted small"><?= e(Workspace::ROLES[$i['role']] ?? $i['role']) ?> · sent <?= e(local_time($i['created_at'], 'M j')) ?></div></div>
                    <form method="post" action="<?= e(url('/invitations/' . $i['id'] . '/revoke')) ?>">
                        <?= csrf_field() ?>
                        <button class="btn btn-sm" type="submit">Revoke</button>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
        <p class="muted small">Links are shown only once. To resend, revoke and invite again.</p>
    <?php endif; ?>
</section>
<?php endif; ?>
<?php
}

}

namespace {

// ---- view: partials/analytics_filters ----
function mc_view_partials_analytics_filters(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php
/** @var array $accounts @var int $accountId @var ?int $days @var array $a @var string $base @var array $extra */
$extra = $extra ?? [];
$qs = fn(array $over) => url($base, array_filter(array_merge(['account' => $accountId ?: null, 'days' => $days, 'from' => $days ? null : $a['from'], 'to' => $days ? null : $a['to']], $extra, $over), fn($v) => $v !== null && $v !== '' && $v !== 0));
?>
<form class="analytics-filters" method="get" action="<?= e(url($base)) ?>">
    <?php foreach ($extra as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach; ?>
    <select name="account" data-autosubmit aria-label="Account">
        <option value="">All accounts</option>
        <?php foreach ($accounts as $acc): ?>
            <option value="<?= (int) $acc['id'] ?>" <?= $accountId === (int) $acc['id'] ? 'selected' : '' ?>><?= e(provider_meta($acc['provider'])['name'] . ' · ' . $acc['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <div class="segmented" role="group" aria-label="Range">
        <?php foreach ([7, 30, 90, 365] as $d): ?>
            <a href="<?= e($qs(['days' => $d, 'from' => null, 'to' => null])) ?>" class="<?= $days === $d ? 'active' : '' ?>"><?= $d === 365 ? '1y' : $d . 'd' ?></a>
        <?php endforeach; ?>
    </div>
    <details class="dropdown range-custom">
        <summary class="btn btn-sm"><?= icon('calendar') ?> <?= $days ? 'Custom' : e(date('M j', strtotime($a['from'])) . ' – ' . date('M j, Y', strtotime($a['to']))) ?></summary>
        <div class="dropdown-menu range-menu">
            <label class="field"><span>From</span><input type="date" name="from" value="<?= e($a['from']) ?>"></label>
            <label class="field"><span>To</span><input type="date" name="to" value="<?= e($a['to']) ?>"></label>
            <button class="btn btn-primary btn-sm" type="submit">Apply</button>
        </div>
    </details>
</form>
<?php
}

}

namespace {

// ---- view: partials/post_row ----
function mc_view_partials_post_row(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php
/** @var array $post decorated by PostsController::decorate */
$when = $post['published_at'] ?? $post['scheduled_at'];
?>
<a class="post-row" href="<?= e(url('/posts/' . $post['id'])) ?>">
    <div class="post-row-main">
        <div class="post-row-meta">
            <?= status_badge($post['status']) ?>
            <?php if ($when): ?>
                <span class="muted small"><?= e(local_time($when, 'D, M j · g:i A')) ?></span>
            <?php endif; ?>
            <?php foreach ($post['tags'] as $t): ?>
                <span class="tag" style="--tc: <?= e($t['color']) ?>"><?= e($t['name']) ?></span>
            <?php endforeach; ?>
        </div>
        <p class="post-row-text"><?= $post['content'] !== '' ? e(str_limit($post['content'], 220)) : '<span class="muted">No text</span>' ?></p>
        <div class="avatar-stack">
            <?php foreach (array_slice($post['targets'], 0, 8) as $t): ?>
                <span class="target<?= $t['status'] === 'failed' ? ' is-failed' : '' ?>"><?= account_avatar($t, 'xs') ?></span>
            <?php endforeach; ?>
            <?php if (count($post['targets']) > 8): ?><span class="muted small">+<?= count($post['targets']) - 8 ?></span><?php endif; ?>
        </div>
    </div>
    <?php if (!empty($post['thumb'])): ?>
        <div class="post-row-thumb">
            <?php if (str_starts_with($post['thumb']['mime'], 'video/')): ?>
                <video src="<?= e(media_url($post['thumb'])) ?>#t=0.1" muted preload="metadata"></video>
                <span class="play"><?= icon('play') ?></span>
            <?php else: ?>
                <img src="<?= e(media_url($post['thumb'])) ?>" alt="" loading="lazy">
            <?php endif; ?>
        </div>
    <?php endif; ?>
</a>
<?php
}

}

namespace {
use App\Workspace;

// ---- view: posts/composer ----
function mc_view_posts_composer(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php


$selected = array_map(fn($t) => (int) $t['account_id'], $targets);
$overrides = [];
foreach ($targets as $t) {
    if (!empty($t['content'])) {
        $overrides[(int) $t['account_id']] = $t['content'];
    }
}
$targetById = array_column($targets, null, 'account_id');
$typesById = [];
foreach ($targets as $t) {
    $typesById[(int) $t['account_id']] = json_arr($t['options'] ?? '{}')['type'] ?? 'feed';
}
$date = $post['scheduled_at'] ? local_time($post['scheduled_at'], 'Y-m-d') : '';
$time = $post['scheduled_at'] ? local_time($post['scheduled_at'], 'H:i') : '';
$dis = $readonly ? 'disabled' : '';
$data = [
    'accounts' => array_map(fn($a) => [
        'id' => (int) $a['id'], 'provider' => $a['provider'], 'name' => $a['name'], 'username' => $a['username'],
        'avatar' => $a['avatar'], 'authorized' => (bool) $a['authorized'],
    ], $accounts),
    'providers' => $providers,
    'media' => array_map(fn($m) => ['id' => $m['id'], 'url' => media_url($m), 'mime' => $m['mime'], 'name' => $m['name']], $media),
    'variables' => array_column($variables, 'value', 'name'),
    'readonly' => $readonly,
];
?>
<form class="composer" method="post" id="composer" action="<?= e(url($post['id'] ? '/posts/' . $post['id'] : '/posts')) ?>" data-composer>
    <?= csrf_field() ?>
    <script type="application/json" id="composer-data"><?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES) ?></script>

    <div class="page-head">
        <div>
            <h1><?= e($title) ?></h1>
            <?php if ($post['id']): ?><div class="muted small"><?= status_badge($post['status']) ?></div><?php endif; ?>
        </div>
        <?php if ($post['id']): ?>
            <div class="head-actions">
                <?php if (Workspace::can('member')): ?>
                    <?php if ($autolists): ?>
                        <details class="dropdown">
                            <summary class="btn"><?= icon('refresh') ?> Add to autolist</summary>
                            <div class="dropdown-menu">
                                <?php foreach ($autolists as $al): ?>
                                    <button type="submit" form="autolist-form" name="autolist" value="<?= (int) $al['id'] ?>"><strong><?= e($al['name']) ?></strong></button>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                    <button class="btn" type="submit" form="duplicate-form"><?= icon('copy') ?> Duplicate</button>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if ($post['review_note']): ?>
        <div class="notice notice-warn"><?= icon('alert') ?><div><strong>Sent back for changes:</strong> <?= e($post['review_note']) ?></div></div>
    <?php endif; ?>

    <?php if ($post['status'] === 'pending_approval' && $canApprove): ?>
        <div class="notice notice-info approval-bar">
            <?= icon('shield') ?>
            <div class="grow"><strong>Waiting for approval.</strong> Review it below, then approve or send it back.</div>
            <button class="btn btn-primary" type="submit" name="action" value="approve">Approve</button>
            <button class="btn" type="button" data-open-dialog="reject-dialog">Send back</button>
        </div>
    <?php elseif ($post['status'] === 'pending_approval'): ?>
        <div class="notice notice-info"><?= icon('clock') ?><div>Waiting for an admin to approve. You can still edit it.</div></div>
    <?php endif; ?>

    <?php if (in_array($post['status'], ['published', 'failed', 'publishing'], true) && $targets): ?>
        <section class="card results">
            <h2>Results</h2>
            <?php foreach ($targets as $t):
                $acct = current(array_filter($accounts, fn($a) => (int) $a['id'] === (int) $t['account_id']));
                if (!$acct) continue; ?>
                <div class="result-row">
                    <?= account_avatar($acct, 'sm') ?>
                    <div class="grow">
                        <strong><?= e($acct['name']) ?></strong>
                        <?php if ($t['status'] === 'failed'): ?>
                            <div class="error-text"><?= e($t['error'] ?: 'Failed') ?></div>
                        <?php elseif ($t['status'] === 'published'): ?>
                            <div class="muted small">Published <?= e(local_time($t['published_at'])) ?></div>
                        <?php else: ?>
                            <div class="muted small">Pending</div>
                        <?php endif; ?>
                    </div>
                    <?php if ($t['url']): ?><a class="btn btn-sm" href="<?= e($t['url']) ?>" target="_blank" rel="noopener">View <?= icon('external') ?></a><?php endif; ?>
                </div>
            <?php endforeach; ?>
            <?php if ($post['status'] === 'failed' && Workspace::can('member')): ?>
                <div class="form-actions"><button class="btn btn-primary" type="submit" form="retry-form"><?= icon('refresh') ?> Retry failed</button></div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <div class="composer-grid">
        <div class="composer-main">
            <section class="card">
                <div class="card-head">
                    <h2>Accounts</h2>
                    <?php if (!$readonly && count($accounts) > 1): ?>
                        <button type="button" class="link" data-select-all-accounts>Select all</button>
                    <?php endif; ?>
                </div>
                <?php if (!$accounts): ?>
                    <p class="muted">No accounts connected. <a href="<?= e(url('/accounts')) ?>">Connect one</a> first.</p>
                <?php endif; ?>
                <div class="account-picker">
                    <?php foreach ($accounts as $a): ?>
                        <label class="account-chip<?= $a['authorized'] ? '' : ' is-unauthorized' ?>" title="<?= e(provider_meta($a['provider'])['name'] . ' · @' . $a['username']) ?>">
                            <input type="checkbox" name="accounts[]" value="<?= (int) $a['id'] ?>" <?= in_array((int) $a['id'], $selected, true) ? 'checked' : '' ?> <?= $dis ?>>
                            <?= account_avatar($a, 'sm') ?>
                            <span class="account-chip-name"><?= e($a['name']) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="card">
                <div class="editor">
                    <textarea name="content" id="content" rows="8" placeholder="What do you want to share?" <?= $dis ?>><?= e($post['content']) ?></textarea>
                    <?php if (!$readonly): ?>
                    <div class="editor-toolbar">
                        <button type="button" class="tool" data-open-media title="Add media"><?= icon('image') ?><span>Media</span></button>
                        <?php if ($aiEnabled): ?>
                            <button type="button" class="tool tool-ai" data-open-dialog="ai-dialog" title="Write with AI"><?= icon('sparkles') ?><span>AI</span></button>
                        <?php endif; ?>
                        <?php if ($hashtagGroups): ?>
                        <details class="dropdown">
                            <summary class="tool" title="Insert hashtags"><?= icon('hash') ?><span>Hashtags</span></summary>
                            <div class="dropdown-menu">
                                <?php foreach ($hashtagGroups as $g): ?>
                                    <button type="button" data-insert="<?= e(' ' . $g['hashtags']) ?>"><strong><?= e($g['name']) ?></strong><small><?= e(str_limit($g['hashtags'], 60)) ?></small></button>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php endif; ?>
                        <?php if ($variables): ?>
                        <details class="dropdown">
                            <summary class="tool" title="Insert variable"><?= icon('braces') ?><span>Variables</span></summary>
                            <div class="dropdown-menu">
                                <?php foreach ($variables as $v): ?>
                                    <button type="button" data-insert="<?= e('{{' . $v['name'] . '}}') ?>"><strong>{{<?= e($v['name']) ?>}}</strong><small><?= e(str_limit($v['value'], 60)) ?></small></button>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php endif; ?>
                        <?php if ($templates): ?>
                        <details class="dropdown">
                            <summary class="tool" title="Use a template"><?= icon('book') ?><span>Templates</span></summary>
                            <div class="dropdown-menu">
                                <?php foreach ($templates as $tpl): ?>
                                    <button type="button" data-template="<?= e($tpl['content']) ?>"><strong><?= e($tpl['name']) ?></strong><small><?= e(str_limit($tpl['content'], 60)) ?></small></button>
                                <?php endforeach; ?>
                            </div>
                        </details>
                        <?php endif; ?>
                        <span class="grow"></span>
                        <div class="counters" id="counters" aria-live="polite"></div>
                    </div>
                    <?php endif; ?>
                </div>

                <div class="media-strip" id="media-strip" data-sortable></div>

                <details class="first-comment" <?= !empty($post['first_comment']) ? 'open' : '' ?>>
                    <summary>First comment</summary>
                    <p class="muted small">Posted as a comment right after publishing, on platforms that allow it. Handy for hashtags or links.</p>
                    <textarea name="first_comment" rows="2" placeholder="#hashtags or a link…" <?= $dis ?>><?= e($post['first_comment'] ?? '') ?></textarea>
                </details>

                <details class="overrides" id="overrides" <?= $overrides ? 'open' : '' ?>>
                    <summary>Customize per account</summary>
                    <p class="muted small">Leave text blank to use the main text. Stories use one image or video and ignore text.</p>
                    <div id="override-fields">
                        <?php foreach ($accounts as $a): ?>
                            <label class="field override" data-override-for="<?= (int) $a['id'] ?>" <?= in_array((int) $a['id'], $selected, true) ? '' : 'hidden' ?>>
                                <span><?= account_avatar($a, 'xs') ?> <?= e($a['name']) ?> <small class="muted counter-inline" data-override-count></small>
                                    <?php $ptypes = $providers[$a['provider']]['types'] ?? []; if (count($ptypes) > 1): ?>
                                        <select class="type-select" name="types[<?= (int) $a['id'] ?>]" aria-label="Post type for <?= e($a['name']) ?>" <?= $dis ?>>
                                            <?php foreach ($ptypes as $tk => $tl): ?><option value="<?= e($tk) ?>" <?= ($typesById[(int) $a['id']] ?? 'feed') === $tk ? 'selected' : '' ?>><?= e($tl) ?></option><?php endforeach; ?>
                                        </select>
                                    <?php endif; ?>
                                </span>
                                <textarea name="overrides[<?= (int) $a['id'] ?>]" rows="3" <?= $dis ?>><?= e($overrides[(int) $a['id']] ?? '') ?></textarea>
                            </label>
                        <?php endforeach; ?>
                    </div>
                </details>
            </section>

            <section class="card">
                <div class="field-row schedule-row">
                    <label class="field">
                        <span>Date</span>
                        <input type="date" name="date" value="<?= e($date) ?>" <?= $dis ?>>
                    </label>
                    <label class="field">
                        <span>Time <small class="muted">(<?= e(str_replace('_', ' ', user_tz())) ?>)</small></span>
                        <input type="time" name="time" value="<?= e($time) ?>" <?= $dis ?>>
                    </label>
                </div>
                <?php if ($bestTimes): ?>
                    <div class="best-chips">
                        <span class="muted small"><?= icon('clock') ?> Your best times:</span>
                        <?php foreach ($bestTimes as $bt): ?>
                            <button type="button" class="chip" data-best-day="<?= (int) $bt['day'] ?>" data-best-hour="<?= (int) $bt['hour'] ?>"><?= e(substr(App\Analytics::dayName($bt['day']), 0, 3) . ' ' . App\Analytics::hourLabel($bt['hour'])) ?></button>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <?php if ($tags || !$readonly): ?>
                <fieldset class="field">
                    <legend>Tags</legend>
                    <div class="tag-picker" id="tag-picker">
                        <?php foreach ($tags as $t): ?>
                            <label class="tag-chip" style="--tc: <?= e($t['color']) ?>">
                                <input type="checkbox" name="tags[]" value="<?= (int) $t['id'] ?>" <?= in_array((int) $t['id'], $tagIds, true) ? 'checked' : '' ?> <?= $dis ?>>
                                <span><?= e($t['name']) ?></span>
                            </label>
                        <?php endforeach; ?>
                        <?php if (!$readonly): ?>
                            <button type="button" class="tag-add" data-new-tag><?= icon('plus') ?> Tag</button>
                        <?php endif; ?>
                    </div>
                </fieldset>
                <?php endif; ?>

                <?php foreach ($panels as $panel) { echo $panel; } ?>

                <?php if (!$readonly): ?>
                <div class="form-actions composer-actions">
                    <button class="btn" type="submit" name="action" value="draft">Save draft</button>
                    <span class="grow"></span>
                    <button class="btn" type="button" data-save-template title="Save the text as a reusable template"><?= icon('book') ?> Save as template</button>
                    <?php if ($needsApproval): ?>
                        <button class="btn btn-primary" type="submit" name="action" value="schedule" data-needs-date>Submit for approval</button>
                    <?php else: ?>
                        <button class="btn" type="submit" name="action" value="now" data-confirm="Publish to the selected accounts now?">Publish now</button>
                        <button class="btn btn-primary" type="submit" name="action" value="schedule" data-needs-date><?= icon('clock') ?> Schedule</button>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </section>
        </div>

        <aside class="composer-preview">
            <div class="preview-head">
                <h2>Preview</h2>
            </div>
            <div id="previews" class="previews">
                <p class="muted small">Select accounts to see how the post will look.</p>
            </div>
        </aside>
    </div>

    <?php if ($post['id'] && Workspace::can('member') && $post['status'] !== 'publishing'): ?>
        <div class="delete-row">
            <button class="btn btn-ghost btn-danger-text" type="submit" form="delete-form"><?= icon('trash') ?> Delete post</button>
        </div>
    <?php endif; ?>
</form>

<?php if ($post['id']): ?>
    <form id="delete-form" method="post" action="<?= e(url('/posts/' . $post['id'] . '/delete')) ?>" data-confirm="Delete this post? Anything already published stays on the platforms."><?= csrf_field() ?></form>
    <form id="duplicate-form" method="post" action="<?= e(url('/posts/' . $post['id'] . '/duplicate')) ?>"><?= csrf_field() ?></form>
    <form id="autolist-form" method="post" action="<?= e(url('/posts/' . $post['id'] . '/autolist')) ?>"><?= csrf_field() ?></form>
    <form id="retry-form" method="post" action="<?= e(url('/posts/' . $post['id'] . '/retry')) ?>"><?= csrf_field() ?></form>
<?php endif; ?>

<?php if ($post['status'] === 'pending_approval' && $canApprove): ?>
<dialog id="reject-dialog" class="dialog">
    <form method="post" action="<?= e(url('/posts/' . $post['id'])) ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reject">
        <h2>Send back for changes</h2>
        <label class="field">
            <span>Note for the author</span>
            <textarea name="note" rows="3" placeholder="What should change?"></textarea>
        </label>
        <div class="form-actions">
            <button type="button" class="btn" data-close-dialog>Cancel</button>
            <button type="submit" class="btn btn-primary">Send back</button>
        </div>
    </form>
</dialog>
<?php endif; ?>

<dialog id="media-dialog" class="dialog dialog-wide">
    <div class="dialog-head">
        <h2>Media</h2>
        <button type="button" class="icon-btn" data-close-dialog aria-label="Close"><?= icon('x') ?></button>
    </div>
    <label class="dropzone" id="dropzone">
        <input type="file" id="media-file" accept="image/jpeg,image/png,image/gif,image/webp,video/mp4,video/quicktime,video/webm" multiple hidden>
        <?= icon('upload') ?>
        <span><strong>Upload files</strong> or drop them here</span>
        <small class="muted">JPG, PNG, GIF, WebP, MP4, MOV, WebM</small>
    </label>
    <div class="upload-progress" id="upload-progress"></div>
    <?php if ($canvaEnabled || $driveEnabled): ?>
        <div class="canva-bar">
            <?php if ($canvaEnabled): ?><button type="button" class="btn btn-sm" data-cloud-open="canva"><?= icon('palette') ?> Import from Canva</button><?php endif; ?>
            <?php if ($driveEnabled): ?><button type="button" class="btn btn-sm" data-cloud-open="drive"><?= icon('download') ?> Import from Google Drive</button><?php endif; ?>
        </div>
        <div class="canva-panel" id="canva-panel" hidden>
            <div class="canva-head"><input type="search" id="canva-q" placeholder="Search"><button type="button" class="btn btn-sm" data-canva-close>Back to library</button></div>
            <div class="media-grid" id="canva-grid"></div>
            <div class="center"><button type="button" class="btn btn-sm" id="canva-more" hidden>Load more</button></div>
        </div>
    <?php endif; ?>
    <div class="media-grid" id="media-library"><p class="muted">Loading…</p></div>
    <div class="form-actions">
        <button type="button" class="btn" data-close-dialog>Cancel</button>
        <button type="button" class="btn btn-primary" id="media-insert">Add selected</button>
    </div>
</dialog>

<?php if ($aiEnabled && !$readonly): ?>
<dialog id="ai-dialog" class="dialog dialog-wide">
    <div class="dialog-head"><h2><?= icon('sparkles') ?> Write with AI</h2><button type="button" class="icon-btn" data-close-dialog aria-label="Close"><?= icon('x') ?></button></div>
    <label class="field"><span>What should the post be about? <small class="muted">(for new posts and variations)</small></span>
        <textarea id="ai-idea" rows="2" placeholder="e.g. Announce our spring sale, 20% off everything until Sunday"></textarea>
    </label>
    <div class="ai-actions">
        <?php foreach (App\Ai::ACTIONS as $k => $label): ?>
            <button type="button" class="btn btn-sm<?= $k === 'write' ? ' btn-primary' : '' ?>" data-ai-action="<?= e($k) ?>"><?= e($label) ?></button>
        <?php endforeach; ?>
    </div>
    <p class="muted small">Improve, shorten and the other edits work on the text currently in the post. The character limit follows the accounts you’ve selected.</p>
    <div id="ai-results" class="ai-results" aria-live="polite"></div>
</dialog>
<?php endif; ?>
<?php
}

}

namespace {
use App\Controllers\PostsController;
use App\Workspace;

// ---- view: posts/index ----
function mc_view_posts_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php



$tabs = ['' => 'All'] + PostsController::STATUSES;
unset($tabs['publishing']);
$qs = fn(array $over) => url('/posts', array_filter(array_merge(['status' => $status] + $filters, $over), fn($v) => $v !== '' && $v !== 0 && $v !== null));
?>
<div class="page-head">
    <h1>Posts</h1>
    <?php if (Workspace::can('member')): ?>
        <div class="head-actions">
            <a class="btn" href="<?= e(url('/import')) ?>"><?= icon('upload') ?> Bulk upload</a>
            <a class="btn btn-primary" href="<?= e(url('/posts/new')) ?>"><?= icon('pen') ?> New post</a>
        </div>
    <?php endif; ?>
</div>

<nav class="tabs" aria-label="Filter by status">
    <?php foreach ($tabs as $key => $label): ?>
        <?php $n = $key === '' ? array_sum($counts) : (int) ($counts[$key] ?? 0); ?>
        <?php if ($key === 'pending_approval' && !$n && $status !== $key) continue; ?>
        <a href="<?= e($qs(['status' => $key, 'page' => null])) ?>" class="<?= $status === $key ? 'active' : '' ?>">
            <?= e($label) ?> <span class="count"><?= $n ?></span>
        </a>
    <?php endforeach; ?>
</nav>

<form class="filters" method="get" action="<?= e(url('/posts')) ?>">
    <?php if ($status): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <input type="search" name="q" value="<?= e($filters['q']) ?>" placeholder="Search text" aria-label="Search text">
    <select name="account" aria-label="Account" data-autosubmit>
        <option value="">All accounts</option>
        <?php foreach ($accounts as $a): ?>
            <option value="<?= (int) $a['id'] ?>" <?= $filters['account'] === (int) $a['id'] ? 'selected' : '' ?>><?= e(provider_meta($a['provider'])['name'] . ' · ' . $a['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php if ($tags): ?>
    <select name="tag" aria-label="Tag" data-autosubmit>
        <option value="">All tags</option>
        <?php foreach ($tags as $t): ?>
            <option value="<?= (int) $t['id'] ?>" <?= $filters['tag'] === (int) $t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <button class="btn" type="submit">Filter</button>
</form>

<?php if (!$posts): ?>
    <div class="card empty-state">
        <?= icon('list', 'empty-icon') ?>
        <p class="muted">No posts here yet.</p>
    </div>
<?php else: ?>
    <div class="card post-list">
        <?php foreach ($posts as $post) { partial('partials/post_row', ['post' => $post]); } ?>
    </div>
<?php endif; ?>

<?php if ($pages > 1): ?>
    <nav class="pager" aria-label="Pages">
        <?php if ($page > 1): ?><a class="btn" href="<?= e($qs(['page' => $page - 1])) ?>">Previous</a><?php endif; ?>
        <span class="muted">Page <?= $page ?> of <?= $pages ?></span>
        <?php if ($page < $pages): ?><a class="btn" href="<?= e($qs(['page' => $page + 1])) ?>">Next</a><?php endif; ?>
    </nav>
<?php endif; ?>
<?php
}

}

namespace {

// ---- view: profile/edit ----
function mc_view_profile_edit(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><div class="page-head">
    <h1>Your profile</h1>
</div>
<form class="card narrow" method="post" action="<?= e(url('/profile')) ?>">
    <?= csrf_field() ?>
    <h2>Details</h2>
    <label class="field"><span>Name</span><input type="text" name="name" value="<?= e($user['name']) ?>" required></label>
    <label class="field"><span>Email</span><input type="email" name="email" value="<?= e($user['email']) ?>" required></label>
    <label class="field">
        <span>Timezone</span>
        <select name="timezone">
            <?php foreach ($timezones as $tz): ?>
                <option value="<?= e($tz) ?>" <?= $user['timezone'] === $tz ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $tz)) ?></option>
            <?php endforeach; ?>
        </select>
        <small class="hint">Times you pick in the composer and calendar use this timezone.</small>
    </label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div>
</form>

<form class="card narrow" method="post" action="<?= e(url('/profile/password')) ?>">
    <?= csrf_field() ?>
    <h2>Password</h2>
    <label class="field"><span>Current password</span><input type="password" name="current_password" required autocomplete="current-password"></label>
    <div class="field-row">
        <label class="field"><span>New password</span><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
        <label class="field"><span>Confirm</span><input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label>
    </div>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Change password</button></div>
</form>

<?php $prefs = json_arr($user['notify'] ?? '{}'); ?>
<section class="card narrow" id="notifications">
    <h2><?= icon('bell') ?> Notifications</h2>
    <p class="muted small">Get push notifications on this phone or computer. On iPhone, first add MixCreate to your Home Screen (Share → Add to Home Screen), then open it from there. Needs HTTPS.</p>
    <div class="push-status" data-push>
        <span class="muted small" data-push-state>Checking…</span>
        <button class="btn btn-primary btn-sm" type="button" data-push-enable hidden>Turn on for this device</button>
        <button class="btn btn-sm" type="button" data-push-disable hidden>Turn off for this device</button>
    </div>
    <form method="post" action="<?= e(url('/push/preferences')) ?>">
        <?= csrf_field() ?>
        <?php foreach (App\Notify::EVENTS as $k => $label): ?>
            <label class="check"><input type="checkbox" name="<?= e($k) ?>" value="1" <?= !empty($prefs[$k]) ? 'checked' : '' ?>> <span><?= e($label) ?></span></label>
        <?php endforeach; ?>
        <div class="form-actions">
            <button class="btn" type="submit" form="push-test">Send a test</button>
            <button class="btn btn-primary" type="submit">Save</button>
        </div>
    </form>
    <form id="push-test" method="post" action="<?= e(url('/push/test')) ?>"><?= csrf_field() ?></form>
</section>

<section class="card narrow">
    <h2>Install the app</h2>
    <p class="muted small">MixCreate works as an app on your phone and computer. In Chrome or Edge use the install icon in the address bar; on iPhone use Share → Add to Home Screen; on Android use the browser menu → Install app.</p>
    <button class="btn btn-sm" type="button" data-install hidden><?= icon('download') ?> Install</button>
</section>
<?php
}

}

namespace {

// ---- view: reports/email ----
function mc_view_reports_email(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php
$fmt = fn($n) => $n === null ? '—' : number_format((int) $n);
$cell = 'padding:12px 14px;background:#f4f5f8;border-radius:8px;';
?>
<?php $brand = $brand ?? ['color' => '#4f46e5', 'logo_url' => null, 'footer' => '']; ?>
<div style="font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#15181e;max-width:560px;margin:0 auto;border-top:4px solid <?= e($brand['color']) ?>;padding-top:16px;">
    <?php if (!empty($brand['logo_url'])): ?><img src="<?= e(abs_url(ltrim(substr($brand['logo_url'], strlen(base_path())), '/'))) ?>" alt="" style="max-height:40px;max-width:160px;margin-bottom:10px;"><?php endif; ?>
    <h1 style="font-size:20px;margin:0 0 4px;"><?= e($workspace) ?></h1>
    <p style="color:#6b7280;margin:0 0 20px;"><?= e($name) ?> · <?= e($scope) ?> · <?= e(date('M j', strtotime($a['from'])) . ' – ' . date('M j, Y', strtotime($a['to']))) ?></p>
    <table role="presentation" cellspacing="8" cellpadding="0" style="width:100%;margin:0 -8px;">
        <tr>
            <td style="<?= $cell ?>"><div style="color:#6b7280;font-size:12px;">Followers</div><div style="font-size:22px;font-weight:700;"><?= $fmt($a['followersNow']) ?></div>
                <?php if ($a['followersChange'] !== null): ?><div style="font-size:12px;color:<?= $a['followersChange'] >= 0 ? '#15803d' : '#dc2626' ?>;"><?= $a['followersChange'] >= 0 ? '+' : '' ?><?= number_format($a['followersChange']) ?></div><?php endif; ?></td>
            <td style="<?= $cell ?>"><div style="color:#6b7280;font-size:12px;">Posts</div><div style="font-size:22px;font-weight:700;"><?= $a['totals']['posts'] ?></div></td>
            <td style="<?= $cell ?>"><div style="color:#6b7280;font-size:12px;">Engagement</div><div style="font-size:22px;font-weight:700;"><?= $fmt($a['totals']['engagement']) ?></div></td>
        </tr>
    </table>
    <?php if ($a['top']): $t = $a['top'][0]; ?>
        <h2 style="font-size:15px;margin:24px 0 6px;">Top post</h2>
        <p style="margin:0;padding:12px 14px;border:1px solid #e5e7eb;border-radius:8px;"><?= e(str_limit($t['content'] ?: '(media)', 200)) ?><br><span style="color:#6b7280;font-size:12px;"><?= e(App\Analytics::providerName($t['provider'])) ?> · <?= number_format($t['engagement']) ?> engagements</span></p>
    <?php endif; ?>
    <p style="color:#6b7280;font-size:13px;margin-top:24px;">The full report is attached as a PDF.</p>
    <?php if (!empty($brand['footer'])): ?><p style="color:#9ca3af;font-size:12px;"><?= e($brand['footer']) ?></p><?php endif; ?>
</div>
<?php
}

}

namespace {
use App\Workspace;

// ---- view: reports/index ----
function mc_view_reports_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php  ?>
<div class="page-head">
    <div>
        <h1>Reports</h1>
        <p class="muted"><?= e($scope) ?> · <?= e(date('M j', strtotime($a['from'])) . ' – ' . date('M j, Y', strtotime($a['to']))) ?></p>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e(url('/reports/pptx', $params)) ?>"><?= icon('download') ?> PowerPoint</a>
        <a class="btn btn-primary" href="<?= e(url('/reports/pdf', $params)) ?>"><?= icon('download') ?> Download PDF</a>
    </div>
</div>

<?php partial('partials/analytics_filters', ['accounts' => $accounts, 'accountId' => $accountId, 'days' => $days, 'a' => $a, 'base' => '/reports', 'extra' => []]); ?>

<?php if ($newShare): ?>
    <div class="notice notice-success"><?= icon('link') ?><div class="grow"><strong>Share link created.</strong> Anyone with it can view this report without signing in.<code class="copy" data-copy><?= e($newShare) ?></code></div></div>
<?php endif; ?>

<div class="grid-2 report-tools">
    <section class="card">
        <h2>Share with a client</h2>
        <p class="muted small">A read-only link to this report. <?= $days ? 'It always shows the last ' . (int) $days . ' days.' : 'It shows this exact date range.' ?></p>
        <form class="field-row align-end" method="post" action="<?= e(url('/reports/share', $params)) ?>">
            <?= csrf_field() ?>
            <label class="field"><span>Expires</span>
                <select name="expires"><option value="0">Never</option><option value="7">In 7 days</option><option value="30">In 30 days</option><option value="90">In 90 days</option></select>
            </label>
            <button class="btn" type="submit"><?= icon('link') ?> Create link</button>
        </form>
        <?php if ($shares): ?>
            <div class="account-list">
                <?php foreach ($shares as $s): $sp = json_arr($s['params']); ?>
                    <div class="account-row">
                        <div class="grow small"><strong><?= !empty($sp['days']) ? 'Last ' . (int) $sp['days'] . ' days' : e(($sp['from'] ?? '') . ' – ' . ($sp['to'] ?? '')) ?></strong>
                            <div class="muted">Created <?= e(local_time($s['created_at'], 'M j')) ?><?= $s['expires_at'] ? ' · expires ' . e(local_time($s['expires_at'], 'M j')) : '' ?></div></div>
                        <form method="post" action="<?= e(url('/reports/shares/' . $s['id'] . '/revoke')) ?>" data-confirm="Revoke this link? Anyone using it loses access.">
                            <?= csrf_field() ?><button class="btn btn-sm" type="submit">Revoke</button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </section>

    <section class="card">
        <h2>Email it on a schedule</h2>
        <?php if (!$mailReady): ?>
            <p class="muted small">Email isn’t set up yet. <?= App\Auth::isAdmin() ? '<a href="' . e(url('/admin/integrations#smtp')) . '">Add your mail server</a>.' : 'Ask your MixCreate admin to add a mail server.' ?></p>
        <?php endif; ?>
        <?php foreach ($schedules as $s): ?>
            <div class="account-row">
                <span class="avatar sm"><?= icon('mail') ?></span>
                <div class="grow small"><strong><?= e($s['name']) ?></strong>
                    <div class="muted"><?= $s['frequency'] === 'monthly' ? 'Monthly' : 'Weekly' ?> · <?= e(str_replace(',', ', ', $s['recipients'])) ?><?= $s['last_sent_at'] ? ' · last sent ' . e(local_time($s['last_sent_at'], 'M j')) : '' ?></div></div>
                <?php if (Workspace::can('member')): ?>
                <form method="post" action="<?= e(url('/reports/schedules/' . $s['id'] . '/send')) ?>"><?= csrf_field() ?><button class="btn btn-sm" type="submit">Send now</button></form>
                <form method="post" action="<?= e(url('/reports/schedules/' . $s['id'] . '/delete')) ?>" data-confirm="Delete this schedule?"><?= csrf_field() ?><button class="icon-btn" type="submit" aria-label="Delete"><?= icon('trash') ?></button></form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        <?php if (Workspace::can('member')): ?>
        <details class="add-block" <?= $schedules ? '' : 'open' ?>>
            <summary class="btn btn-sm"><?= icon('plus') ?> New schedule</summary>
            <form method="post" action="<?= e(url('/reports/schedules')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="account" value="<?= (int) $accountId ?>">
                <label class="field"><span>Name</span><input type="text" name="name" placeholder="Weekly social report"></label>
                <label class="field"><span>Send to</span><input type="text" name="recipients" placeholder="client@example.com, boss@example.com" required></label>
                <label class="field"><span>How often</span>
                    <select name="frequency"><option value="weekly">Weekly, Monday morning (last 7 days)</option><option value="monthly">Monthly, on the 1st (last month)</option></select>
                </label>
                <p class="muted small">Covers <?= e($scope) ?> with a PDF attached.</p>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Schedule</button></div>
            </form>
        </details>
        <?php endif; ?>
    </section>
</div>

<h2 class="section-title">Preview</h2>
<?php partial('reports/report', ['a' => $a]); ?>
<?php
}

}

namespace {

// ---- view: reports/public ----
function mc_view_reports_public(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?></title>
<link rel="stylesheet" href="<?= e(asset('app.css')) ?>">
<style>:root { --accent: <?= e($brand['color']) ?>; --accent-soft: color-mix(in srgb, <?= e($brand['color']) ?> 12%, white); } .public-head { border-top: 4px solid <?= e($brand['color']) ?>; }</style>
</head>
<body class="public-report">
<header class="public-head">
    <div class="public-brand">
        <?php if ($brand['logo_url']): ?><img class="public-logo" src="<?= e($brand['logo_url']) ?>" alt=""><?php endif; ?>
        <div>
        <h1><?= e($brand['name']) ?></h1>
        <p class="muted"><?= e($scope) ?> · <?= e(date('M j', strtotime($a['from'])) . ' – ' . date('M j, Y', strtotime($a['to']))) ?></p>
        </div>
    </div>
    <div class="head-actions">
        <a class="btn" href="<?= e($pdfUrl) ?>"><?= icon('download') ?> PDF</a>
        <a class="btn" href="<?= e($pptxUrl) ?>"><?= icon('download') ?> PowerPoint</a>
    </div>
</header>
<main class="content">
    <?php partial('reports/report', ['a' => $a, 'tz' => $tz]); ?>
    <?php if ($brand['footer'] !== ''): ?><p class="muted small center"><?= e($brand['footer']) ?></p><?php endif; ?>
    <?php if (!$brand['hide_powered_by']): ?><p class="muted small center">Made with <?= e(App\Config::get('app_name', 'MixCreate')) ?></p><?php endif; ?>
</main>
<script src="<?= e(asset('app.js')) ?>"></script>
</body>
</html>
<?php
}

}

namespace {
use App\Analytics;

// ---- view: reports/report ----
function mc_view_reports_report(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php
/** Report body shared by the in-app preview and the public share page. @var array $a */


$fmt = fn($n) => $n === null ? '—' : ($n >= 10000 ? number_format($n / 1000, 1) . 'k' : number_format((int) $n));
$tz = $tz ?? user_tz();
?>
<div class="report">
    <div class="stats">
        <div class="stat">
            <span class="stat-label">Followers</span>
            <span class="stat-value"><?= $fmt($a['followersNow']) ?></span>
            <?php if ($a['followersChange'] !== null): ?>
                <span class="stat-delta <?= $a['followersChange'] >= 0 ? 'up' : 'down' ?>"><?= $a['followersChange'] >= 0 ? '+' : '' ?><?= number_format($a['followersChange']) ?></span>
            <?php endif; ?>
        </div>
        <div class="stat"><span class="stat-label">Posts</span><span class="stat-value"><?= $a['totals']['posts'] ?></span></div>
        <div class="stat"><span class="stat-label">Engagement</span><span class="stat-value"><?= $fmt($a['totals']['engagement']) ?></span></div>
        <div class="stat"><span class="stat-label">Impressions</span><span class="stat-value"><?= $fmt($a['totals']['impressions'] ?: null) ?></span></div>
        <div class="stat"><span class="stat-label">Engagement rate</span><span class="stat-value"><?= $a['rate'] === null ? '—' : $a['rate'] . '%' ?></span></div>
    </div>
    <div class="grid-2">
        <section class="card"><h2>Followers</h2><div class="chart" data-chart="line" data-series="<?= e(json_encode($a['followers'])) ?>" data-label="Followers"></div></section>
        <section class="card"><h2>Engagement per day</h2><div class="chart" data-chart="bar" data-series="<?= e(json_encode($a['engagementSeries'])) ?>" data-label="Engagement"></div></section>
    </div>
    <?php if ($a['perNetwork']): ?>
    <section class="card">
        <h2>By network</h2>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Network</th><th class="num">Followers</th><th class="num">Change</th><th class="num">Posts</th><th class="num">Engagement</th><th class="num">Eng. rate</th></tr></thead>
            <?php foreach ($a['perNetwork'] as $n): ?>
                <tr>
                    <td><?= provider_badge($n['provider'], 'pdot') ?> <?= e(Analytics::providerName($n['provider'])) ?></td>
                    <td class="num"><?= $fmt($n['followers']) ?></td>
                    <td class="num"><?= $n['followers_change'] === null ? '—' : (($n['followers_change'] >= 0 ? '+' : '') . number_format($n['followers_change'])) ?></td>
                    <td class="num"><?= $n['posts'] ?></td>
                    <td class="num"><?= $fmt($n['engagement']) ?></td>
                    <td class="num"><?= $n['rate'] === null ? '—' : $n['rate'] . '%' ?></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
    </section>
    <?php endif; ?>
    <section class="card">
        <h2>Top posts</h2>
        <?php if (!$a['top']): ?><p class="muted">No engagement data for this period.</p><?php else: ?>
        <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Post</th><th>Network</th><th class="num">Likes</th><th class="num">Comments</th><th class="num">Total</th></tr></thead>
            <?php foreach ($a['top'] as $p): $m = $p['m']; ?>
                <tr>
                    <td><?= e(str_limit($p['content'] ?: '(media)', 90)) ?><div class="muted small"><?= e(local_time($p['published_at'], 'M j', $tz)) ?><?php if ($p['url']): ?> · <a href="<?= e($p['url']) ?>" target="_blank" rel="noopener">View</a><?php endif; ?></div></td>
                    <td><?= provider_badge($p['provider'], 'pdot') ?> <?= e($p['account_name']) ?></td>
                    <td class="num"><?= number_format((int) ($m['likes'] ?? $m['reactions'] ?? 0)) ?></td>
                    <td class="num"><?= number_format((int) ($m['replies'] ?? $m['comments'] ?? 0)) ?></td>
                    <td class="num"><strong><?= number_format($p['engagement']) ?></strong></td>
                </tr>
            <?php endforeach; ?>
        </table>
        </div>
        <?php endif; ?>
    </section>
    <?php if ($a['best']['top']): ?>
    <section class="card">
        <h2>Best times to post</h2>
        <div class="best-times">
            <?php foreach ($a['best']['top'] as $i => $c): ?>
                <div class="best-slot"><span class="best-rank"><?= $i + 1 ?></span><strong><?= e(Analytics::dayName($c['day']) . ', ' . Analytics::hourLabel($c['hour'])) ?></strong></div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
<?php
}

}

namespace {
use App\Workspace;

// ---- view: websites/index ----
function mc_view_websites_index(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php


$fmtDur = fn(?int $s) => $s === null ? '—' : ($s >= 60 ? intdiv($s, 60) . 'm ' . ($s % 60) . 's' : $s . 's');
$list = function (string $title, array $rows, string $label, ?callable $fmtKey = null) {
    echo '<section class="card"><h2>' . e($title) . '</h2>';
    if (!$rows) {
        echo '<p class="muted small">No data.</p></section>';
        return;
    }
    $max = max(1, max(array_column($rows, 'visitors')));
    echo '<div class="hbars">';
    foreach ($rows as $r) {
        $k = $fmtKey ? $fmtKey($r['k']) : $r['k'];
        echo '<div class="hbar"><span class="hbar-label" title="' . e($r['k']) . '">' . e($k) . '</span><span class="hbar-track"><i style="width:' . round($r['visitors'] / $max * 100) . '%"></i></span><span class="hbar-value">' . (int) $r['visitors'] . '</span></div>';
    }
    echo '</div></section>';
};
$qs = fn(array $over) => url('/websites', array_filter(array_merge(['site' => $site['id'] ?? null, 'days' => $days, 'from' => $days ? null : $from, 'to' => $days ? null : $to], $over), fn($v) => $v !== null && $v !== ''));
?>
<div class="page-head">
    <div>
        <h1>Website</h1>
        <p class="muted">Cookieless visitor stats for your site or blog, and how much traffic social brings in.</p>
    </div>
</div>

<?php if ($sites): ?>
<div class="analytics-filters">
    <select onchange="location.href=this.value" aria-label="Site">
        <?php foreach ($sites as $s): ?><option value="<?= e(url('/websites', ['site' => $s['id']])) ?>" <?= (int) $s['id'] === (int) ($site['id'] ?? 0) ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
    </select>
    <div class="segmented" role="group" aria-label="Range">
        <?php foreach ([7, 30, 90] as $d): ?><a href="<?= e($qs(['days' => $d, 'from' => null, 'to' => null])) ?>" class="<?= $days === $d ? 'active' : '' ?>"><?= $d ?>d</a><?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php if ($site && $r): ?>
    <?php if (!$r['views']): ?>
        <div class="notice notice-info"><?= icon('code') ?>
            <div class="grow"><strong>Waiting for the first visit.</strong> Add this to the <code>&lt;head&gt;</code> of every page on <?= e($site['domain']) ?>:
                <code class="copy" data-copy><?= e($snippet) ?></code></div></div>
    <?php endif; ?>
    <div class="stats">
        <div class="stat"><span class="stat-label">Visitors</span><span class="stat-value"><?= number_format($r['visitors']) ?></span></div>
        <div class="stat"><span class="stat-label">Page views</span><span class="stat-value"><?= number_format($r['views']) ?></span></div>
        <div class="stat"><span class="stat-label">Bounce rate</span><span class="stat-value"><?= $r['bounce'] === null ? '—' : $r['bounce'] . '%' ?></span></div>
        <div class="stat"><span class="stat-label">Time on page</span><span class="stat-value"><?= $fmtDur($r['duration']) ?></span></div>
        <div class="stat"><span class="stat-label">From social</span><span class="stat-value"><?= number_format(array_sum($r['social'])) ?></span></div>
    </div>
    <section class="card"><h2>Visitors per day</h2><div class="chart" data-chart="bar" data-series="<?= e(json_encode($r['series'])) ?>" data-label="Visitors"></div></section>
    <div class="grid-2">
        <?php $list('Top pages', $r['pages'], 'Page'); ?>
        <section class="card">
            <h2>Social traffic</h2>
            <?php if (!$r['social']): ?><p class="muted small">No visits from social networks yet. Links in your posts show up here.</p><?php else: $mx = max($r['social']); ?>
            <div class="hbars"><?php foreach ($r['social'] as $net => $n): ?>
                <div class="hbar"><span class="hbar-label"><?= e($net) ?></span><span class="hbar-track"><i style="width: <?= round($n / $mx * 100) ?>%"></i></span><span class="hbar-value"><?= (int) $n ?></span></div>
            <?php endforeach; ?></div>
            <?php endif; ?>
        </section>
        <?php $list('Referrers', $r['referrers'], 'Site'); ?>
        <?php $list('Campaigns (utm_campaign)', $r['campaigns'], 'Campaign'); ?>
        <?php $list('Devices', $r['devices'], 'Device', fn($k) => ucfirst($k)); ?>
        <?php $list('Browsers', $r['browsers'], 'Browser'); ?>
        <?php $list('Operating systems', $r['os'], 'OS'); ?>
        <?php if ($r['countries']) { $list('Countries', $r['countries'], 'Country'); } ?>
    </div>
    <?php if ($r['views']): ?>
        <details class="card"><summary class="link">Tracking snippet</summary><code class="copy" data-copy><?= e($snippet) ?></code></details>
    <?php endif; ?>
<?php elseif (!$sites): ?>
    <div class="card empty-state"><?= icon('chart', 'empty-icon') ?><p class="muted">Add your website to start counting visitors. No cookies, no consent banner needed for this script.</p></div>
<?php endif; ?>

<?php if (Workspace::can('admin')): ?>
<details class="card add-block" <?= $sites ? '' : 'open' ?>>
    <summary class="btn btn-sm"><?= icon('plus') ?> Add a website</summary>
    <form class="field-row align-end" method="post" action="<?= e(url('/websites')) ?>">
        <?= csrf_field() ?>
        <label class="field"><span>Name</span><input type="text" name="name" placeholder="Company blog"></label>
        <label class="field grow"><span>Domain</span><input type="text" name="domain" placeholder="example.com" required></label>
        <button class="btn btn-primary" type="submit">Add</button>
    </form>
    <?php if ($site): ?>
        <form method="post" action="<?= e(url('/websites/' . $site['id'] . '/delete')) ?>" data-confirm="Remove <?= e($site['name']) ?> and all its stats?"><?= csrf_field() ?><button class="btn btn-ghost btn-danger-text btn-sm" type="submit">Remove <?= e($site['name']) ?></button></form>
    <?php endif; ?>
</details>
<?php endif; ?>
<?php
}

}

namespace {

// ---- view: workspaces/create ----
function mc_view_workspaces_create(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><div class="page-head">
    <h1>New workspace</h1>
</div>
<form class="card narrow" method="post" action="<?= e(url('/workspaces')) ?>">
    <?= csrf_field() ?>
    <label class="field">
        <span>Name</span>
        <input type="text" name="name" required autofocus placeholder="Brand or client name">
    </label>
    <fieldset class="field">
        <legend>Color</legend>
        <div class="swatches">
            <?php foreach ($colors as $i => $c): ?>
                <label class="swatch" style="--c: <?= e($c) ?>">
                    <input type="radio" name="color" value="<?= e($c) ?>" <?= $i === 0 ? 'checked' : '' ?>>
                    <span class="sr-only"><?= e($c) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Create workspace</button>
    </div>
</form>
<?php
}

}

namespace {

// ---- view: workspaces/edit ----
function mc_view_workspaces_edit(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><div class="page-head">
    <h1>Workspace settings</h1>
</div>
<?php if ($tabs): ?>
    <nav class="tabs"><?php foreach ($tabs as $t): ?><a href="<?= e(url($t['href'])) ?>"><?= e($t['label']) ?></a><?php endforeach; ?></nav>
<?php endif; ?>

<form class="card narrow" method="post" action="<?= e(url('/settings')) ?>">
    <?= csrf_field() ?>
    <h2>General</h2>
    <label class="field">
        <span>Name</span>
        <input type="text" name="name" value="<?= e($workspace['name']) ?>" required>
    </label>
    <fieldset class="field">
        <legend>Color</legend>
        <div class="swatches">
            <?php foreach ($colors as $c): ?>
                <label class="swatch" style="--c: <?= e($c) ?>">
                    <input type="radio" name="color" value="<?= e($c) ?>" <?= $workspace['color'] === $c ? 'checked' : '' ?>>
                    <span class="sr-only"><?= e($c) ?></span>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <label class="check">
        <input type="checkbox" name="require_approval" value="1" <?= $workspace['require_approval'] ? 'checked' : '' ?>>
        <span><strong>Require approval</strong><br><small class="muted">Members’ posts wait for an admin to approve them before they’re scheduled. Admins’ posts skip the queue.</small></span>
    </label>
    <div class="form-actions">
        <button class="btn btn-primary" type="submit">Save</button>
    </div>
</form>

<form class="card narrow" method="post" action="<?= e(url('/settings/branding')) ?>">
    <?= csrf_field() ?>
    <h2>Report branding</h2>
    <p class="muted small">Used on PDF and PowerPoint reports, shared report links and report emails.</p>
    <label class="field"><span>Brand name</span><input type="text" name="brand_name" value="<?= e($branding['name']) ?>" placeholder="<?= e($workspace['name']) ?>"></label>
    <div class="field-row">
        <label class="field"><span>Brand color</span><input type="color" name="brand_color" value="<?= e($branding['color']) ?>"></label>
        <div class="field">
            <span>Logo</span>
            <div class="bio-avatar-row">
                <span class="logo-preview"><?php if ($branding['logo_url']): ?><img src="<?= e($branding['logo_url']) ?>" alt="" data-bio-avatar-img><?php else: ?><span class="muted small">None</span><?php endif; ?></span>
                <input type="hidden" name="logo_media_id" value="<?= (int) ($branding['logo']['id'] ?? 0) ?>" data-bio-avatar>
                <button class="btn btn-sm" type="button" data-pick-avatar>Choose from media</button>
            </div>
        </div>
    </div>
    <label class="field"><span>Footer text</span><input type="text" name="brand_footer" value="<?= e($branding['footer']) ?>" maxlength="120" placeholder="e.g. Prepared by Acme Agency · acme.agency"></label>
    <label class="check"><input type="checkbox" name="hide_powered_by" value="1" <?= $branding['hide_powered_by'] ? 'checked' : '' ?>> <span>Hide “Made with <?= e(App\Config::get('app_name', 'MixCreate')) ?>”</span></label>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save branding</button></div>
</form>

<dialog id="avatar-dialog" class="dialog dialog-wide">
    <div class="dialog-head"><h2>Choose a logo</h2><button type="button" class="icon-btn" data-close-dialog aria-label="Close"><?= icon('x') ?></button></div>
    <div class="media-grid" id="avatar-library"><p class="muted">Loading…</p></div>
</dialog>

<form class="card narrow" method="post" action="<?= e(url('/settings/ai')) ?>" id="ai">
    <?= csrf_field() ?>
    <h2>AI writing</h2>
    <p class="muted small">Use this workspace’s own OpenRouter key (BYOK) instead of the instance key, so its AI usage is billed to that OpenRouter account. Leave blank to use the instance settings<?= App\Ai::enabled() ? '' : ' (none set yet)' ?>.</p>
    <label class="field"><span>OpenRouter API key</span><input type="password" name="api_key" placeholder="<?= $aiWs['api_key'] ? '•••••••• (saved — leave blank to keep)' : 'sk-or-v1-…' ?>" autocomplete="off"></label>
    <label class="field"><span>Model</span><input type="text" name="model" value="<?= e($aiWs['model'] ?? '') ?>" list="openrouter-models" placeholder="Same as instance (or openrouter/auto)" autocomplete="off"></label>
    <label class="field"><span>Brand voice</span><textarea name="brand_voice" rows="2" placeholder="e.g. Warm, playful, short sentences, no corporate jargon"><?= e($aiWs['brand_voice'] ?? '') ?></textarea></label>
    <?php if ($aiWs['api_key']): ?><label class="check"><input type="checkbox" name="remove_key" value="1"> <span>Remove this workspace’s key</span></label><?php endif; ?>
    <div class="form-actions"><button class="btn btn-primary" type="submit">Save</button></div>
</form>
<datalist id="openrouter-models">
    <option value="openrouter/auto">
    <?php foreach ($models as $m): ?><option value="<?= e($m['id']) ?>"><?= e($m['name']) ?></option><?php endforeach; ?>
</datalist>

<form class="card narrow danger-zone" method="post" action="<?= e(url('/settings/delete')) ?>" data-confirm="Delete this workspace and everything in it? This can’t be undone.">
    <?= csrf_field() ?>
    <h2>Delete workspace</h2>
    <p class="muted">Removes its accounts, posts, media and team access. Posts already on social platforms stay there.</p>
    <label class="field">
        <span>Type <strong><?= e($workspace['name']) ?></strong> to confirm</span>
        <input type="text" name="confirm" autocomplete="off">
    </label>
    <button class="btn btn-danger" type="submit">Delete workspace</button>
</form>
<?php
}

}

namespace {

// ---- view: workspaces/none ----
function mc_view_workspaces_none(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><div class="card auth-card empty-state">
    <h1>No workspace yet</h1>
    <p class="muted">You’re not a member of any workspace. Ask an admin to invite you.</p>
    <form method="post" action="<?= e(url('/logout')) ?>">
        <?= csrf_field() ?>
        <button class="btn" type="submit">Sign out</button>
    </form>
</div>
<?php
}

}

namespace App {

/** Database schema changes, applied in order, once each. */
final class Migrations
{
    private const ALL = [
        '001_initial.sql' => <<<'MC_SQL_1'
CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password TEXT NOT NULL,
    is_admin INTEGER NOT NULL DEFAULT 0,
    timezone TEXT NOT NULL DEFAULT 'UTC',
    current_workspace_id INTEGER,
    created_at TEXT NOT NULL
);

CREATE TABLE workspaces (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT '#4f46e5',
    require_approval INTEGER NOT NULL DEFAULT 0,  -- members' posts need an admin's approval before scheduling
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL
);

-- role: admin (manage workspace, members, accounts), member (create and publish posts), viewer (read only)
CREATE TABLE workspace_user (
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role TEXT NOT NULL DEFAULT 'member',
    joined_at TEXT NOT NULL,
    PRIMARY KEY (workspace_id, user_id)
);

CREATE TABLE invitations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    email TEXT NOT NULL COLLATE NOCASE,
    role TEXT NOT NULL DEFAULT 'member',
    token TEXT NOT NULL UNIQUE,
    invited_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    accepted_at TEXT
);

-- App-wide API credentials per platform (client id/secret etc.), encrypted JSON.
CREATE TABLE services (
    provider TEXT PRIMARY KEY,
    credentials TEXT,
    active INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT
);

CREATE TABLE accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    provider TEXT NOT NULL,
    provider_id TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '',
    username TEXT NOT NULL DEFAULT '',
    avatar TEXT,
    token TEXT,               -- encrypted JSON (access/refresh tokens, expiry)
    data TEXT NOT NULL DEFAULT '{}',
    authorized INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (workspace_id, provider, provider_id)
);

CREATE TABLE media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    path TEXT NOT NULL,
    name TEXT NOT NULL,
    mime TEXT NOT NULL,
    size INTEGER NOT NULL,
    width INTEGER,
    height INTEGER,
    created_at TEXT NOT NULL
);

CREATE TABLE tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT '#64748b',
    UNIQUE (workspace_id, name)
);

-- status: draft, pending_approval, scheduled, publishing, published, failed
CREATE TABLE posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    content TEXT NOT NULL DEFAULT '',
    media TEXT NOT NULL DEFAULT '[]',
    status TEXT NOT NULL DEFAULT 'draft',
    scheduled_at TEXT,
    published_at TEXT,
    reviewed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    reviewed_at TEXT,
    review_note TEXT,          -- reason given when an admin sends a post back
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX posts_due ON posts (status, scheduled_at);
CREATE INDEX posts_workspace ON posts (workspace_id, scheduled_at);

-- One row per target account. content overrides the post text for that account when set.
CREATE TABLE post_accounts (
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    content TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    provider_post_id TEXT,
    url TEXT,
    error TEXT,
    published_at TEXT,
    PRIMARY KEY (post_id, account_id)
);

CREATE TABLE post_tag (
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (post_id, tag_id)
);

-- Daily snapshots for analytics (filled by the cron importer).
CREATE TABLE account_metrics (
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    date TEXT NOT NULL,
    followers INTEGER,
    data TEXT NOT NULL DEFAULT '{}',
    PRIMARY KEY (account_id, date)
);

CREATE TABLE post_metrics (
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    data TEXT NOT NULL DEFAULT '{}',
    updated_at TEXT NOT NULL,
    PRIMARY KEY (post_id, account_id)
);

CREATE TABLE logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    level TEXT NOT NULL,
    message TEXT NOT NULL,
    context TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    content TEXT NOT NULL DEFAULT '',
    media TEXT NOT NULL DEFAULT '[]',
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE hashtag_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    hashtags TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

-- Workspace-level text variables usable in posts as {{name}}.
CREATE TABLE variables (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    value TEXT NOT NULL DEFAULT '',
    UNIQUE (workspace_id, name)
);

CREATE TABLE api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    token TEXT NOT NULL UNIQUE,   -- sha256 of the token; the token itself is shown once
    last_used_at TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE webhooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    url TEXT NOT NULL,
    events TEXT NOT NULL DEFAULT '[]',
    secret TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

CREATE TABLE webhook_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    webhook_id INTEGER NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
    event TEXT NOT NULL,
    payload TEXT NOT NULL,
    status_code INTEGER,
    response TEXT,
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TEXT,
    delivered_at TEXT,
    created_at TEXT NOT NULL
);
CREATE INDEX webhook_deliveries_pending ON webhook_deliveries (delivered_at, next_attempt_at);
MC_SQL_1,
        '002_growth.sql' => <<<'MC_SQL_2'
-- Audience snapshots (demographics) where platforms provide them.
CREATE TABLE account_audience (
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    date TEXT NOT NULL,
    data TEXT NOT NULL DEFAULT '{}',
    PRIMARY KEY (account_id, date)
);

-- Reports
CREATE TABLE report_schedules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    recipients TEXT NOT NULL,            -- comma-separated emails
    frequency TEXT NOT NULL DEFAULT 'weekly',  -- weekly | monthly
    accounts TEXT NOT NULL DEFAULT '[]',  -- account ids, empty = all
    last_sent_at TEXT,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE report_shares (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    token TEXT NOT NULL UNIQUE,           -- sha256 of the public token
    params TEXT NOT NULL DEFAULT '{}',    -- {from, to, accounts}
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    expires_at TEXT
);

-- Competitors
CREATE TABLE competitors (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    provider TEXT NOT NULL,
    handle TEXT NOT NULL,
    external_id TEXT,
    name TEXT NOT NULL DEFAULT '',
    avatar TEXT,
    error TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (workspace_id, provider, handle)
);

CREATE TABLE competitor_metrics (
    competitor_id INTEGER NOT NULL REFERENCES competitors(id) ON DELETE CASCADE,
    date TEXT NOT NULL,
    followers INTEGER,
    posts INTEGER,
    data TEXT NOT NULL DEFAULT '{}',      -- recent_posts, avg_engagement, posts_per_week
    PRIMARY KEY (competitor_id, date)
);

-- Inbox: comments and replies on published posts
CREATE TABLE inbox_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    post_id INTEGER REFERENCES posts(id) ON DELETE SET NULL,
    provider_item_id TEXT NOT NULL,
    root_ref TEXT NOT NULL DEFAULT '',     -- provider id of the published post
    reply_ref TEXT NOT NULL DEFAULT '',    -- what a reply should target (some platforms need extra data)
    author_name TEXT NOT NULL DEFAULT '',
    author_handle TEXT NOT NULL DEFAULT '',
    author_avatar TEXT,
    text TEXT NOT NULL DEFAULT '',
    url TEXT,
    remote_created_at TEXT,
    status TEXT NOT NULL DEFAULT 'new',    -- new | read | replied | archived
    reply_text TEXT,
    replied_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    replied_at TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (account_id, provider_item_id)
);
CREATE INDEX inbox_workspace ON inbox_items (workspace_id, status, remote_created_at);

-- Link in bio
CREATE TABLE bio_pages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL UNIQUE REFERENCES workspaces(id) ON DELETE CASCADE,
    slug TEXT NOT NULL UNIQUE COLLATE NOCASE,
    title TEXT NOT NULL DEFAULT '',
    bio TEXT NOT NULL DEFAULT '',
    avatar_media_id INTEGER REFERENCES media(id) ON DELETE SET NULL,
    theme TEXT NOT NULL DEFAULT '{}',       -- {accent, background, style}
    show_posts INTEGER NOT NULL DEFAULT 1,
    show_socials INTEGER NOT NULL DEFAULT 1,
    published INTEGER NOT NULL DEFAULT 0,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE bio_links (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    page_id INTEGER NOT NULL REFERENCES bio_pages(id) ON DELETE CASCADE,
    label TEXT NOT NULL,
    url TEXT NOT NULL,
    position INTEGER NOT NULL DEFAULT 0,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

-- link_id 0 = page view
CREATE TABLE bio_stats (
    page_id INTEGER NOT NULL REFERENCES bio_pages(id) ON DELETE CASCADE,
    link_id INTEGER NOT NULL DEFAULT 0,
    date TEXT NOT NULL,
    count INTEGER NOT NULL DEFAULT 0,
    PRIMARY KEY (page_id, link_id, date)
);

-- Ads
CREATE TABLE ad_accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    provider TEXT NOT NULL,                 -- meta_ads | google_ads | tiktok_ads
    external_id TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '',
    currency TEXT NOT NULL DEFAULT 'USD',
    token TEXT,
    data TEXT NOT NULL DEFAULT '{}',
    authorized INTEGER NOT NULL DEFAULT 1,
    last_import_at TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (workspace_id, provider, external_id)
);

CREATE TABLE ad_metrics (
    ad_account_id INTEGER NOT NULL REFERENCES ad_accounts(id) ON DELETE CASCADE,
    date TEXT NOT NULL,
    campaign_id TEXT NOT NULL,
    campaign_name TEXT NOT NULL DEFAULT '',
    spend REAL NOT NULL DEFAULT 0,
    impressions INTEGER NOT NULL DEFAULT 0,
    clicks INTEGER NOT NULL DEFAULT 0,
    conversions REAL NOT NULL DEFAULT 0,
    PRIMARY KEY (ad_account_id, date, campaign_id)
);

-- Per-user connections to tools (e.g. Canva)
CREATE TABLE user_connections (
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    provider TEXT NOT NULL,
    token TEXT NOT NULL,
    created_at TEXT NOT NULL,
    PRIMARY KEY (user_id, provider)
);
MC_SQL_2,
        '003_pro.sql' => <<<'MC_SQL_3'
-- First comment and per-account post type (feed / story / reel)
ALTER TABLE posts ADD COLUMN first_comment TEXT;
ALTER TABLE posts ADD COLUMN autolist_id INTEGER;
ALTER TABLE post_accounts ADD COLUMN options TEXT NOT NULL DEFAULT '{}';
ALTER TABLE post_accounts ADD COLUMN first_comment_status TEXT;

-- White-label branding per workspace: {name, color, logo_media_id, hide_powered_by}
ALTER TABLE workspaces ADD COLUMN branding TEXT NOT NULL DEFAULT '{}';

-- Notification preferences per user
ALTER TABLE users ADD COLUMN notify TEXT NOT NULL DEFAULT '{"failed":1,"comments":1,"approvals":1,"published":0}';

-- Autolists: evergreen queues that publish the next item at each time slot
CREATE TABLE autolists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    accounts TEXT NOT NULL DEFAULT '[]',
    slots TEXT NOT NULL DEFAULT '[]',        -- [{"day": 1, "time": "09:00"}], day 0 = Sunday
    timezone TEXT NOT NULL DEFAULT 'UTC',
    order_mode TEXT NOT NULL DEFAULT 'sequence', -- sequence | random
    active INTEGER NOT NULL DEFAULT 1,
    next_index INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE autolist_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    autolist_id INTEGER NOT NULL REFERENCES autolists(id) ON DELETE CASCADE,
    content TEXT NOT NULL DEFAULT '',
    media TEXT NOT NULL DEFAULT '[]',
    position INTEGER NOT NULL DEFAULT 0,
    times_posted INTEGER NOT NULL DEFAULT 0,
    last_posted_at TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE autolist_runs (
    autolist_id INTEGER NOT NULL REFERENCES autolists(id) ON DELETE CASCADE,
    slot TEXT NOT NULL,                      -- UTC "Y-m-d H:i" of the slot that ran
    post_id INTEGER,
    PRIMARY KEY (autolist_id, slot)
);

-- Website analytics (cookieless)
CREATE TABLE sites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    domain TEXT NOT NULL,
    site_key TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL
);

CREATE TABLE pageviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    ts TEXT NOT NULL,                        -- UTC
    path TEXT NOT NULL,
    title TEXT,
    referrer TEXT,                           -- host only
    utm_source TEXT,
    utm_medium TEXT,
    utm_campaign TEXT,
    device TEXT,                             -- desktop | mobile | tablet
    browser TEXT,
    os TEXT,
    country TEXT,
    visitor TEXT NOT NULL,                   -- daily-rotating hash, not reversible to an IP
    pid TEXT,                                -- random id from the browser, links the leave beacon to this view
    duration INTEGER                         -- seconds on page, filled by the leave beacon
);
CREATE INDEX pageviews_pid ON pageviews (site_id, pid);
CREATE INDEX pageviews_site_ts ON pageviews (site_id, ts);

-- Hashtag tracker
CREATE TABLE hashtags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    tag TEXT NOT NULL,
    networks TEXT NOT NULL DEFAULT '[]',
    active INTEGER NOT NULL DEFAULT 1,
    last_checked_at TEXT,
    error TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (workspace_id, tag)
);

CREATE TABLE hashtag_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hashtag_id INTEGER NOT NULL REFERENCES hashtags(id) ON DELETE CASCADE,
    network TEXT NOT NULL,
    external_id TEXT NOT NULL,
    author_handle TEXT NOT NULL DEFAULT '',
    author_name TEXT NOT NULL DEFAULT '',
    author_avatar TEXT,
    text TEXT NOT NULL DEFAULT '',
    url TEXT,
    likes INTEGER NOT NULL DEFAULT 0,
    reposts INTEGER NOT NULL DEFAULT 0,
    replies INTEGER NOT NULL DEFAULT 0,
    posted_at TEXT NOT NULL,
    UNIQUE (hashtag_id, network, external_id)
);
CREATE INDEX hashtag_posts_time ON hashtag_posts (hashtag_id, posted_at);

-- Web push subscriptions
CREATE TABLE push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    endpoint TEXT NOT NULL UNIQUE,
    p256dh TEXT NOT NULL,
    auth TEXT NOT NULL,
    user_agent TEXT,
    created_at TEXT NOT NULL
);
MC_SQL_3,
        '004_single_file.sql' => <<<'MC_SQL_4'
-- Files live in the database (no uploads folder), and small runtime state lives in kv (no storage folder).
CREATE TABLE IF NOT EXISTS media_chunks (
    key TEXT NOT NULL,
    n INTEGER NOT NULL,
    data BLOB NOT NULL,
    PRIMARY KEY (key, n)
);

CREATE TABLE IF NOT EXISTS kv (
    k TEXT PRIMARY KEY,
    v TEXT,
    updated_at INTEGER NOT NULL DEFAULT 0
);
MC_SQL_4,
    ];

    public static function all(): array
    {
        return self::ALL;
    }
}

}

namespace App {

/** Static files (CSS, JS, icons). The single-file build embeds them; here they're read from disk. */
final class Assets
{
    private const FILES = [
        'app.css' => [false, <<<'MC_ASSET_1'
/* MixCreate — plain CSS, no build step. */

:root {
    --bg: #f6f7f9;
    --surface: #ffffff;
    --surface-2: #f1f3f6;
    --border: #e3e6eb;
    --border-strong: #cfd4dc;
    --text: #15181e;
    --text-2: #4b5260;
    --muted: #7a8290;
    --accent: #4f46e5;
    --accent-hover: #4338ca;
    --accent-soft: #eef0ff;
    --accent-text: #3730a3;
    --danger: #dc2626;
    --danger-soft: #fdecec;
    --warn: #b45309;
    --warn-soft: #fef5e6;
    --ok: #15803d;
    --ok-soft: #e8f7ee;
    --info: #1d4ed8;
    --info-soft: #e9f0ff;
    --s-draft: #94a3b8;
    --s-pending: #d97706;
    --s-scheduled: #4f46e5;
    --s-published: #16a34a;
    --s-failed: #dc2626;
    --radius: 10px;
    --radius-sm: 7px;
    --shadow: 0 1px 2px rgba(16, 24, 40, .05);
    --shadow-lg: 0 12px 32px -8px rgba(16, 24, 40, .18), 0 2px 6px rgba(16, 24, 40, .06);
    --sidebar: 248px;
    --font: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    color-scheme: light;
}

@media (prefers-color-scheme: dark) {
    :root:not([data-theme="light"]) {
        --bg: #0f1115; --surface: #171a20; --surface-2: #1e222a; --border: #272c35; --border-strong: #353b47;
        --text: #eceef2; --text-2: #b6bcc8; --muted: #858d9b;
        --accent: #7c74ff; --accent-hover: #918aff; --accent-soft: #23234a; --accent-text: #c4c0ff;
        --danger: #f26363; --danger-soft: #3a1c1f; --warn: #f0b056; --warn-soft: #362a17; --ok: #4ccf7c; --ok-soft: #173123; --info: #7aa5ff; --info-soft: #18233d;
        --s-scheduled: #7c74ff;
        --shadow: 0 1px 2px rgba(0, 0, 0, .3); --shadow-lg: 0 16px 40px -8px rgba(0, 0, 0, .6);
        color-scheme: dark;
    }
}
:root[data-theme="dark"] {
    --bg: #0f1115; --surface: #171a20; --surface-2: #1e222a; --border: #272c35; --border-strong: #353b47;
    --text: #eceef2; --text-2: #b6bcc8; --muted: #858d9b;
    --accent: #7c74ff; --accent-hover: #918aff; --accent-soft: #23234a; --accent-text: #c4c0ff;
    --danger: #f26363; --danger-soft: #3a1c1f; --warn: #f0b056; --warn-soft: #362a17; --ok: #4ccf7c; --ok-soft: #173123; --info: #7aa5ff; --info-soft: #18233d;
    --s-scheduled: #7c74ff;
    --shadow: 0 1px 2px rgba(0, 0, 0, .3); --shadow-lg: 0 16px 40px -8px rgba(0, 0, 0, .6);
    color-scheme: dark;
}

*, *::before, *::after { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body { margin: 0; font: 14px/1.5 var(--font); color: var(--text); background: var(--bg); -webkit-font-smoothing: antialiased; }
a { color: var(--accent); text-decoration: none; }
a:hover { text-decoration: underline; }
h1, h2, h3 { margin: 0 0 .5em; line-height: 1.25; letter-spacing: -.01em; }
h1 { font-size: 22px; font-weight: 650; }
h2 { font-size: 15px; font-weight: 600; }
h3 { font-size: 13px; font-weight: 600; margin-top: 1.25em; color: var(--text-2); }
p { margin: 0 0 .75em; }
code { font: 12.5px var(--mono); background: var(--surface-2); padding: 1px 5px; border-radius: 5px; }
pre { margin: 0; }
img, video { max-width: 100%; display: block; }
button, input, select, textarea { font: inherit; color: inherit; }
:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; }
.muted { color: var(--muted); }
.small { font-size: 12.5px; }
.center { text-align: center; }
.right { text-align: right; }
.grow { flex: 1 1 auto; min-width: 0; }
.ellipsis { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.error-text { color: var(--danger); font-size: 13px; }
.pre-wrap { white-space: pre-wrap; font: inherit; }
.stack { display: grid; gap: 16px; align-content: start; }

.icon { width: 18px; height: 18px; flex: none; vertical-align: middle; }

/* ---------- Shell ---------- */
.shell { display: flex; min-height: 100vh; }
.sidebar {
    width: var(--sidebar); flex: none; background: var(--surface); border-right: 1px solid var(--border);
    display: flex; flex-direction: column; gap: 12px; padding: 14px 12px; position: sticky; top: 0; height: 100vh; overflow-y: auto;
}
.sidebar-head { display: flex; align-items: center; justify-content: space-between; padding: 2px 6px; }
.brand { display: inline-flex; align-items: center; gap: 9px; font-weight: 700; font-size: 16px; color: var(--text); letter-spacing: -.01em; }
.brand:hover { text-decoration: none; }
.brand-mark { width: 26px; height: 26px; color: var(--accent); }
.sidebar .sidebar-close { display: none; }
.main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.content { padding: 28px 32px 64px; max-width: 1280px; width: 100%; margin: 0 auto; }
.topbar { display: none; }
.scrim { display: none; }

.ws-switch { position: relative; }
.ws-switch summary {
    list-style: none; display: flex; align-items: center; gap: 9px; padding: 8px 10px; border: 1px solid var(--border);
    border-radius: var(--radius-sm); cursor: pointer; background: var(--surface);
}
.ws-switch summary::-webkit-details-marker { display: none; }
.ws-switch summary:hover { border-color: var(--border-strong); }
.ws-name { flex: 1; font-weight: 600; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ws-dot { width: 10px; height: 10px; border-radius: 3px; display: inline-block; flex: none; }
.ws-menu {
    position: absolute; z-index: 30; left: 0; right: 0; top: calc(100% + 4px); background: var(--surface);
    border: 1px solid var(--border); border-radius: var(--radius-sm); box-shadow: var(--shadow-lg); padding: 4px; max-height: 320px; overflow: auto;
}
.ws-item {
    display: flex; align-items: center; gap: 9px; width: 100%; padding: 7px 9px; border: 0; background: none; border-radius: 6px;
    cursor: pointer; text-align: left; color: var(--text);
}
.ws-item:hover { background: var(--surface-2); text-decoration: none; }
.ws-item.current { background: var(--accent-soft); }
.ws-item span { flex: 1; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.ws-item small { color: var(--muted); }

.nav { display: flex; flex-direction: column; gap: 1px; flex: 1; }
.nav-link {
    display: flex; align-items: center; gap: 10px; padding: 7px 10px; border-radius: var(--radius-sm); color: var(--text-2); font-weight: 500;
}
.nav-link:hover { background: var(--surface-2); color: var(--text); text-decoration: none; }
.nav-link.active { background: var(--accent-soft); color: var(--accent-text); }
.nav-link .icon { width: 17px; height: 17px; }
.nav-link span { flex: 1; }
.nav-badge { flex: none !important; background: var(--s-pending); color: #fff; font-size: 11px; font-weight: 700; padding: 0 6px; border-radius: 9px; line-height: 18px; }
.nav-section { font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: .06em; color: var(--muted); padding: 16px 10px 6px; }

.sidebar-foot { display: flex; align-items: center; gap: 6px; padding-top: 10px; border-top: 1px solid var(--border); }
.user-chip { display: flex; align-items: center; gap: 9px; flex: 1; min-width: 0; padding: 5px 6px; border-radius: var(--radius-sm); color: var(--text); }
.user-chip:hover { background: var(--surface-2); text-decoration: none; }
.user-meta { display: flex; flex-direction: column; min-width: 0; line-height: 1.25; }
.user-meta strong, .user-meta small { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.user-meta small { color: var(--muted); font-size: 12px; }
.foot-actions { display: flex; }

/* ---------- Buttons ---------- */
.btn {
    display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 7px 13px; border-radius: var(--radius-sm);
    border: 1px solid var(--border-strong); background: var(--surface); color: var(--text); font-weight: 550; cursor: pointer;
    line-height: 20px; white-space: nowrap; transition: background .12s, border-color .12s, box-shadow .12s;
}
.btn:hover { background: var(--surface-2); text-decoration: none; }
.btn:disabled { opacity: .55; cursor: not-allowed; }
.btn .icon { width: 16px; height: 16px; }
.btn-primary { background: var(--accent); border-color: var(--accent); color: #fff; }
.btn-primary:hover { background: var(--accent-hover); border-color: var(--accent-hover); }
.btn-danger { background: var(--danger); border-color: var(--danger); color: #fff; }
.btn-danger:hover { filter: brightness(.95); background: var(--danger); }
.btn-ghost { border-color: transparent; background: transparent; }
.btn-danger-text { color: var(--danger); }
.btn-sm { padding: 4px 10px; font-size: 13px; }
.btn-block { width: 100%; }
.icon-btn {
    display: inline-grid; place-items: center; width: 34px; height: 34px; border-radius: var(--radius-sm); border: 1px solid transparent;
    background: transparent; color: var(--text-2); cursor: pointer;
}
.icon-btn:hover { background: var(--surface-2); color: var(--text); }
.link { background: none; border: 0; padding: 0; color: var(--accent); cursor: pointer; font-weight: 500; font-size: 13px; }
.link:hover { text-decoration: underline; }
.new-post { margin: 0 2px; }

/* ---------- Layout bits ---------- */
.page-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; margin-bottom: 20px; flex-wrap: wrap; }
.page-head h1 { margin: 0; }
.page-head p { margin: 4px 0 0; }
.head-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.card {
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 18px 20px; box-shadow: var(--shadow);
    margin-bottom: 16px;
}
.card.narrow { max-width: 620px; }
.card-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 10px; }
.card-head h2 { margin: 0; display: flex; align-items: center; gap: 8px; }
.grid-2 { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 16px; align-items: start; }
.grid-2 > .card { margin-bottom: 0; }
.grid-2 + * { margin-top: 16px; }
.empty-state { text-align: center; padding: 40px 20px; }
.empty-icon { width: 36px; height: 36px; color: var(--muted); margin: 0 auto 10px; display: block; }
.error-code { font-size: 48px; font-weight: 800; color: var(--muted); }
.danger-zone { border-color: color-mix(in srgb, var(--danger) 35%, var(--border)); }

/* ---------- Forms ---------- */
.field { display: flex; flex-direction: column; gap: 5px; margin: 0 0 14px; border: 0; padding: 0; min-width: 0; }
.field > span, .field > legend { font-weight: 550; font-size: 13px; color: var(--text-2); padding: 0; }
.field legend { margin-bottom: 6px; }
.hint { color: var(--muted); font-size: 12.5px; }
input[type=text], input[type=email], input[type=password], input[type=url], input[type=search], input[type=date], input[type=time], input[type=number], select, textarea {
    width: 100%; padding: 8px 11px; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); background: var(--surface);
    line-height: 20px; transition: border-color .12s, box-shadow .12s;
}
input:focus, select:focus, textarea:focus { outline: none; border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 22%, transparent); }
input:disabled, select:disabled, textarea:disabled { background: var(--surface-2); color: var(--text-2); }
input[type=color] { width: 46px; height: 38px; padding: 3px; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); background: var(--surface); }
textarea { resize: vertical; min-height: 70px; }
select { appearance: none; padding-right: 32px; background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%237a8290' stroke-width='2' stroke-linecap='round'%3E%3Cpath d='m6 9 6 6 6-6'/%3E%3C/svg%3E"); background-repeat: no-repeat; background-position: right 9px center; background-size: 16px; }
.field-row { display: flex; gap: 12px; flex-wrap: wrap; }
.field-row > .field { flex: 1 1 180px; }
.field-row.align-end { align-items: flex-end; }
.field-row.align-end > .btn { margin-bottom: 14px; }
.check { display: flex; align-items: flex-start; gap: 9px; margin: 0 0 12px; cursor: pointer; }
.check input { margin-top: 3px; accent-color: var(--accent); width: 16px; height: 16px; flex: none; }
.check-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(220px, 1fr)); gap: 0 14px; }
.form-actions { display: flex; align-items: center; gap: 8px; justify-content: flex-end; flex-wrap: wrap; margin-top: 6px; }
.inline-form { display: flex; gap: 6px; align-items: center; }
.swatches { display: flex; gap: 8px; flex-wrap: wrap; }
.swatch { width: 28px; height: 28px; border-radius: 8px; background: var(--c); cursor: pointer; position: relative; }
.swatch input { position: absolute; opacity: 0; inset: 0; cursor: pointer; }
.swatch:has(input:checked) { box-shadow: 0 0 0 2px var(--surface), 0 0 0 4px var(--c); }
.swatch:has(input:focus-visible) { outline: 2px solid var(--accent); outline-offset: 4px; }

/* ---------- Notices, flashes, badges ---------- */
.flashes { position: fixed; top: 14px; right: 14px; z-index: 100; display: grid; gap: 8px; max-width: min(420px, calc(100vw - 28px)); }
.auth-body .flashes { position: static; max-width: none; margin-bottom: 12px; }
.flash {
    display: flex; align-items: flex-start; gap: 10px; padding: 11px 12px; border-radius: var(--radius-sm); background: var(--surface);
    border: 1px solid var(--border); box-shadow: var(--shadow-lg); animation: slide-in .2s ease-out;
}
.flash span { flex: 1; }
.flash-success .icon { color: var(--ok); }
.flash-error { border-color: color-mix(in srgb, var(--danger) 40%, var(--border)); }
.flash-error .icon { color: var(--danger); }
.flash-close { background: none; border: 0; color: var(--muted); cursor: pointer; padding: 0; }
.flash-close .icon { width: 16px; height: 16px; }
@keyframes slide-in { from { transform: translateY(-6px); opacity: 0; } }
.toast-root { position: fixed; bottom: 16px; left: 50%; transform: translateX(-50%); z-index: 100; display: grid; gap: 8px; }
.toast { background: var(--text); color: var(--bg); padding: 9px 14px; border-radius: 8px; box-shadow: var(--shadow-lg); font-weight: 500; animation: slide-in .2s; }
.toast.error { background: var(--danger); color: #fff; }

.notice { display: flex; gap: 11px; align-items: flex-start; padding: 12px 14px; border-radius: var(--radius); margin-bottom: 16px; border: 1px solid transparent; }
.notice > .icon { margin-top: 1px; }
.notice-warn { background: var(--warn-soft); color: var(--text); border-color: color-mix(in srgb, var(--warn) 30%, transparent); }
.notice-warn > .icon { color: var(--warn); }
.notice-error { background: var(--danger-soft); border-color: color-mix(in srgb, var(--danger) 30%, transparent); }
.notice-error > .icon { color: var(--danger); }
.notice-success { background: var(--ok-soft); border-color: color-mix(in srgb, var(--ok) 30%, transparent); }
.notice-success > .icon { color: var(--ok); }
.notice-info { background: var(--info-soft); border-color: color-mix(in srgb, var(--info) 25%, transparent); }
.notice-info > .icon { color: var(--info); }
.approval-bar { align-items: center; flex-wrap: wrap; }

code.copy, .copy { display: block; margin-top: 6px; padding: 8px 10px; background: var(--surface); border: 1px dashed var(--border-strong); border-radius: 6px; cursor: copy; word-break: break-all; }
code.copy:hover { border-color: var(--accent); }

.badge {
    display: inline-flex; align-items: center; gap: 5px; padding: 1px 8px; border-radius: 20px; font-size: 12px; font-weight: 600;
    background: var(--surface-2); color: var(--text-2); white-space: nowrap; line-height: 20px;
}
.badge::before { content: ""; width: 6px; height: 6px; border-radius: 50%; background: currentColor; opacity: .9; }
.status-draft { --sc: var(--s-draft); }
.status-pending_approval { --sc: var(--s-pending); }
.status-scheduled, .status-publishing { --sc: var(--s-scheduled); }
.status-published { --sc: var(--s-published); }
.status-failed { --sc: var(--s-failed); }
.badge[class*="status-"] { color: var(--sc); background: color-mix(in srgb, var(--sc) 12%, transparent); }
.tag {
    display: inline-flex; align-items: center; gap: 5px; padding: 0 8px; border-radius: 5px; font-size: 12px; font-weight: 550;
    color: var(--tc); background: color-mix(in srgb, var(--tc) 13%, transparent); line-height: 20px;
}
.tag.big { font-size: 13px; padding: 3px 4px 3px 10px; }
.tag.big small { opacity: .7; }
.tag form { display: inline; }
.tag-x { background: none; border: 0; color: inherit; cursor: pointer; padding: 0 2px; display: grid; opacity: .6; }
.tag-x:hover { opacity: 1; }
.tag-x .icon { width: 14px; height: 14px; }
.tag-cloud { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px; }

/* ---------- Avatars ---------- */
.avatar {
    width: 36px; height: 36px; border-radius: 50%; background: var(--accent-soft); color: var(--accent-text); display: inline-grid;
    place-items: center; font-weight: 650; font-size: 13px; flex: none; position: relative; overflow: hidden;
}
.avatar img { position: absolute; inset: 0; width: 100%; height: 100%; object-fit: cover; }
.avatar .icon { width: 16px; height: 16px; }
.avatar.sm { width: 30px; height: 30px; font-size: 11.5px; }
.avatar.xs { width: 24px; height: 24px; font-size: 10px; }
.acct-avatar { position: relative; display: inline-flex; flex: none; }
.pbadge {
    position: absolute; right: -4px; bottom: -3px; min-width: 16px; height: 16px; padding: 0 3px; border-radius: 8px;
    background: var(--pc); color: #fff; font-size: 8.5px; font-weight: 800; display: grid; place-items: center;
    border: 2px solid var(--surface); line-height: 1; letter-spacing: -.02em;
}
.acct-avatar.xs .pbadge { min-width: 13px; height: 13px; font-size: 7px; right: -3px; bottom: -2px; border-width: 1.5px; }
.pmark {
    display: inline-grid; place-items: center; width: 34px; height: 34px; border-radius: 9px; background: var(--pc); color: #fff;
    font-weight: 800; font-size: 13px; letter-spacing: -.02em; flex: none;
}
.pmark.sm { width: 24px; height: 24px; font-size: 10px; border-radius: 6px; }
.pdot {
    display: inline-grid; place-items: center; min-width: 15px; height: 15px; padding: 0 2px; border-radius: 4px; background: var(--pc);
    color: #fff; font-size: 7.5px; font-weight: 800; vertical-align: middle;
}
.avatar-stack { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 8px; }
.target.is-failed .avatar { box-shadow: 0 0 0 2px var(--danger); }

/* ---------- Stats ---------- */
.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 12px; margin-bottom: 16px; }
.stat {
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; display: flex;
    flex-direction: column; gap: 2px; color: var(--text); box-shadow: var(--shadow);
}
a.stat:hover { border-color: var(--border-strong); text-decoration: none; }
.stat-label { color: var(--muted); font-size: 12.5px; font-weight: 500; }
.stat-value { font-size: 26px; font-weight: 700; letter-spacing: -.02em; font-variant-numeric: tabular-nums; }
.stat-delta { font-size: 12.5px; font-weight: 600; }
.stat-delta.up { color: var(--ok); }
.stat-delta.down { color: var(--danger); }

/* ---------- Post list ---------- */
.post-list { padding: 0; overflow: hidden; }
.post-list.compact { margin: 0 -20px -18px; border-top: 1px solid var(--border); }
.post-row { display: flex; gap: 14px; padding: 14px 20px; border-bottom: 1px solid var(--border); color: var(--text); }
.post-row:last-child { border-bottom: 0; }
.post-row:hover { background: var(--surface-2); text-decoration: none; }
.post-row-main { flex: 1; min-width: 0; }
.post-row-meta { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 4px; }
.post-row-text { margin: 0; white-space: pre-line; overflow-wrap: anywhere; display: -webkit-box; -webkit-line-clamp: 3; -webkit-box-orient: vertical; overflow: hidden; }
.post-row-thumb { width: 72px; height: 72px; border-radius: 8px; overflow: hidden; flex: none; background: var(--surface-2); position: relative; }
.post-row-thumb img, .post-row-thumb video { width: 100%; height: 100%; object-fit: cover; }
.play { position: absolute; inset: 0; display: grid; place-items: center; color: #fff; pointer-events: none; }
.play .icon { width: 22px; height: 22px; filter: drop-shadow(0 1px 3px rgba(0, 0, 0, .5)); }

.tabs { display: flex; gap: 2px; border-bottom: 1px solid var(--border); margin-bottom: 16px; overflow-x: auto; scrollbar-width: none; }
.tabs a { padding: 8px 12px; color: var(--text-2); font-weight: 550; border-bottom: 2px solid transparent; margin-bottom: -1px; white-space: nowrap; }
.tabs a:hover { color: var(--text); text-decoration: none; }
.tabs a.active { color: var(--accent); border-bottom-color: var(--accent); }
.tabs .count { color: var(--muted); font-weight: 500; font-size: 12px; margin-left: 3px; }
.filters { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
.filters input[type=search] { flex: 1 1 220px; width: auto; }
.filters select { width: auto; flex: 0 1 220px; }
.pager { display: flex; align-items: center; justify-content: center; gap: 12px; }
.segmented { display: inline-flex; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); overflow: hidden; background: var(--surface); }
.segmented a { padding: 6px 12px; color: var(--text-2); font-weight: 550; border-right: 1px solid var(--border); }
.segmented a:last-child { border-right: 0; }
.segmented a:hover { background: var(--surface-2); text-decoration: none; }
.segmented a.active { background: var(--accent-soft); color: var(--accent-text); }

/* ---------- Composer ---------- */
.composer-grid { display: grid; grid-template-columns: minmax(0, 1fr) 380px; gap: 20px; align-items: start; }
.composer-preview { position: sticky; top: 20px; max-height: calc(100vh - 40px); overflow-y: auto; }
.preview-head h2 { color: var(--text-2); }
.account-picker { display: flex; flex-wrap: wrap; gap: 8px; }
.account-chip {
    display: inline-flex; align-items: center; gap: 8px; padding: 5px 12px 5px 5px; border: 1px solid var(--border-strong);
    border-radius: 30px; cursor: pointer; user-select: none; background: var(--surface); transition: border-color .12s, background .12s; max-width: 240px;
}
.account-chip input { position: absolute; opacity: 0; pointer-events: none; }
.account-chip:hover { border-color: var(--accent); }
.account-chip:has(input:checked) { border-color: var(--accent); background: var(--accent-soft); }
.account-chip:has(input:focus-visible) { outline: 2px solid var(--accent); outline-offset: 2px; }
.account-chip:not(:has(input:checked)) .acct-avatar { filter: grayscale(1); opacity: .55; }
.account-chip-name { font-weight: 550; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.account-chip.is-unauthorized { border-style: dashed; }
.account-chip:has(input:disabled) { cursor: default; }

.editor { border: 1px solid var(--border-strong); border-radius: var(--radius-sm); background: var(--surface); }
.editor:focus-within { border-color: var(--accent); box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 22%, transparent); }
.editor textarea { border: 0; box-shadow: none !important; min-height: 170px; font-size: 15px; line-height: 1.55; padding: 12px 14px; border-radius: var(--radius-sm) var(--radius-sm) 0 0; }
.editor-toolbar { display: flex; align-items: center; gap: 2px; padding: 5px 6px; border-top: 1px solid var(--border); flex-wrap: wrap; }
.tool {
    display: inline-flex; align-items: center; gap: 6px; padding: 5px 9px; border-radius: 6px; border: 0; background: none;
    color: var(--text-2); cursor: pointer; font-weight: 550; font-size: 13px; list-style: none;
}
.tool::-webkit-details-marker { display: none; }
.tool:hover, details[open] > .tool { background: var(--surface-2); color: var(--text); }
.tool .icon { width: 16px; height: 16px; }
.counters { display: flex; gap: 6px; flex-wrap: wrap; justify-content: flex-end; }
.counter { display: inline-flex; align-items: center; gap: 4px; font-size: 12px; font-variant-numeric: tabular-nums; color: var(--muted); padding: 2px 6px; border-radius: 5px; background: var(--surface-2); }
.counter.over { color: #fff; background: var(--danger); }
.counter.over .pdot { background: rgba(255, 255, 255, .25); }
.dropdown { position: relative; }
.dropdown-menu {
    position: absolute; z-index: 40; top: calc(100% + 4px); left: 0; min-width: 260px; max-width: 340px; max-height: 300px; overflow: auto;
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm); box-shadow: var(--shadow-lg); padding: 4px;
}
.dropdown-menu button { display: flex; flex-direction: column; align-items: flex-start; width: 100%; padding: 7px 10px; border: 0; background: none; border-radius: 6px; cursor: pointer; text-align: left; }
.dropdown-menu button:hover { background: var(--surface-2); }
.dropdown-menu small { color: var(--muted); font-size: 12px; }

.media-strip { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
.media-strip:empty { display: none; }
.media-item { width: 92px; height: 92px; border-radius: 8px; overflow: hidden; position: relative; background: var(--surface-2); cursor: grab; border: 1px solid var(--border); }
.media-item.dragging { opacity: .4; }
.media-item img, .media-item video { width: 100%; height: 100%; object-fit: cover; }
.media-item .remove {
    position: absolute; top: 4px; right: 4px; width: 22px; height: 22px; border-radius: 50%; border: 0; background: rgba(0, 0, 0, .65);
    color: #fff; display: grid; place-items: center; cursor: pointer;
}
.media-item .remove .icon { width: 13px; height: 13px; }

.overrides { margin-top: 14px; border-top: 1px solid var(--border); padding-top: 12px; }
.overrides summary { cursor: pointer; font-weight: 550; color: var(--text-2); }
.overrides[open] summary { margin-bottom: 8px; }
.override > span { display: flex; align-items: center; gap: 7px; }
.counter-inline.over { color: var(--danger); font-weight: 600; }

.tag-picker { display: flex; flex-wrap: wrap; gap: 6px; }
.tag-chip { display: inline-flex; cursor: pointer; }
.tag-chip input { position: absolute; opacity: 0; }
.tag-chip span { padding: 3px 10px; border-radius: 6px; font-size: 13px; font-weight: 550; border: 1px solid var(--border-strong); color: var(--text-2); }
.tag-chip:has(input:checked) span { color: var(--tc); border-color: var(--tc); background: color-mix(in srgb, var(--tc) 12%, transparent); }
.tag-chip:has(input:focus-visible) span { outline: 2px solid var(--accent); }
.tag-add { display: inline-flex; align-items: center; gap: 4px; padding: 3px 10px; border-radius: 6px; font-size: 13px; border: 1px dashed var(--border-strong); background: none; color: var(--muted); cursor: pointer; }
.tag-add .icon { width: 14px; height: 14px; }
.tag-add:hover { color: var(--accent); border-color: var(--accent); }
.composer-actions { border-top: 1px solid var(--border); padding-top: 14px; margin-top: 4px; }
.schedule-row .field { flex: 1 1 160px; }
.delete-row { margin-top: -4px; }
.results .result-row { display: flex; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border); }
.results .result-row:last-of-type { border-bottom: 0; }

.previews { display: grid; gap: 12px; }
.preview { background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 14px; box-shadow: var(--shadow); }
.preview-top { display: flex; gap: 10px; align-items: center; margin-bottom: 8px; }
.preview-name { font-weight: 650; line-height: 1.2; }
.preview-handle { color: var(--muted); font-size: 12.5px; }
.preview-text { white-space: pre-wrap; overflow-wrap: anywhere; font-size: 14px; }
.preview-text .hl { color: var(--accent); }
.preview-text .var { background: var(--accent-soft); color: var(--accent-text); border-radius: 4px; padding: 0 3px; }
.preview-truncated { color: var(--danger); font-size: 12px; margin-top: 4px; }
.preview-media { display: grid; gap: 3px; margin-top: 10px; border-radius: 10px; overflow: hidden; }
.preview-media.n2, .preview-media.n4 { grid-template-columns: 1fr 1fr; }
.preview-media.n3 { grid-template-columns: 1fr 1fr; }
.preview-media.n3 > :first-child { grid-row: span 2; }
.preview-media > * { width: 100%; height: 100%; aspect-ratio: 16/10; object-fit: cover; background: var(--surface-2); }
.preview-media.n1 > * { aspect-ratio: auto; max-height: 360px; }
.preview-actions { display: flex; gap: 22px; margin-top: 10px; color: var(--muted); }
.preview-actions .icon { width: 16px; height: 16px; }
.preview-warn { margin-top: 8px; font-size: 12.5px; color: var(--warn); display: flex; gap: 6px; align-items: flex-start; }
.preview-warn .icon { width: 15px; height: 15px; margin-top: 1px; }

/* ---------- Dialog / media picker ---------- */
.dialog { border: 0; border-radius: 14px; padding: 20px; width: min(520px, calc(100vw - 24px)); background: var(--surface); color: var(--text); box-shadow: var(--shadow-lg); }
.dialog-wide { width: min(860px, calc(100vw - 24px)); }
.dialog::backdrop { background: rgba(15, 17, 21, .5); backdrop-filter: blur(2px); }
.dialog-head { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.dialog-head h2 { margin: 0; }
.dropzone {
    display: flex; flex-direction: column; align-items: center; gap: 4px; padding: 22px; border: 2px dashed var(--border-strong); border-radius: var(--radius);
    color: var(--text-2); cursor: pointer; text-align: center; background: var(--surface); margin-bottom: 14px; transition: border-color .12s, background .12s;
}
.dropzone:hover, .dropzone.over { border-color: var(--accent); background: var(--accent-soft); }
.dropzone > .icon { width: 26px; height: 26px; color: var(--accent); }
.upload-progress { display: grid; gap: 6px; margin-bottom: 10px; }
.upload-progress:empty { display: none; }
.progress-row { font-size: 12.5px; display: grid; gap: 3px; }
.progress-bar { height: 4px; border-radius: 3px; background: var(--surface-2); overflow: hidden; }
.progress-bar i { display: block; height: 100%; background: var(--accent); width: 0; transition: width .15s; }
.media-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(118px, 1fr)); gap: 10px; max-height: 50vh; overflow-y: auto; padding: 2px; }
.page-media { max-height: none; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 14px; }
.media-pick { position: relative; aspect-ratio: 1; border-radius: 8px; overflow: hidden; border: 2px solid transparent; cursor: pointer; background: var(--surface-2); padding: 0; }
.media-pick img, .media-pick video { width: 100%; height: 100%; object-fit: cover; }
.media-pick.selected { border-color: var(--accent); }
.media-pick .num { position: absolute; top: 5px; right: 5px; width: 22px; height: 22px; border-radius: 50%; background: var(--accent); color: #fff; font-size: 12px; font-weight: 700; display: none; place-items: center; }
.media-pick.selected .num { display: grid; }
.media-tile { margin: 0; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden; position: relative; }
.media-thumb { display: block; aspect-ratio: 4/3; background: var(--surface-2); position: relative; }
.media-thumb img, .media-thumb video { width: 100%; height: 100%; object-fit: cover; }
.media-tile figcaption { padding: 8px 10px; display: flex; flex-direction: column; min-width: 0; }
.media-name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; font-weight: 550; font-size: 13px; }
.media-del { position: absolute; top: 6px; right: 6px; background: rgba(0, 0, 0, .55); color: #fff; width: 30px; height: 30px; opacity: 0; transition: opacity .12s; }
.media-tile:hover .media-del, .media-del:focus-visible { opacity: 1; }
.media-del:hover { background: var(--danger); color: #fff; }

/* ---------- Calendar ---------- */
.calendar { padding: 0; overflow: hidden; }
.cal-weekdays, .cal-grid { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); }
.cal-weekdays div { padding: 8px 10px; font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; letter-spacing: .04em; border-bottom: 1px solid var(--border); }
.cal-day { min-height: 118px; border-right: 1px solid var(--border); border-bottom: 1px solid var(--border); padding: 6px; display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.cal-day:nth-child(7n) { border-right: 0; }
.cal-day.other-month { background: color-mix(in srgb, var(--surface-2) 55%, transparent); }
.cal-day.other-month .cal-num { color: var(--muted); }
.cal-day-head { display: flex; justify-content: space-between; align-items: center; }
.cal-num { font-weight: 600; font-size: 13px; width: 24px; height: 24px; display: grid; place-items: center; border-radius: 50%; }
.cal-day.today .cal-num { background: var(--accent); color: #fff; }
.cal-add { opacity: 0; color: var(--muted); display: grid; place-items: center; width: 22px; height: 22px; border-radius: 5px; }
.cal-add .icon { width: 15px; height: 15px; }
.cal-day:hover .cal-add, .week-day:hover .cal-add, .cal-add:focus-visible { opacity: 1; }
.cal-add:hover { background: var(--accent-soft); color: var(--accent); }
.cal-items { display: flex; flex-direction: column; gap: 3px; min-width: 0; }
.cal-item {
    display: flex; align-items: center; gap: 4px; padding: 2px 6px; border-radius: 5px; font-size: 12px; color: var(--text);
    background: color-mix(in srgb, var(--sc) 12%, var(--surface)); border-left: 3px solid var(--sc); min-width: 0;
}
.cal-item:hover { text-decoration: none; background: color-mix(in srgb, var(--sc) 20%, var(--surface)); }
.cal-time { font-weight: 600; font-variant-numeric: tabular-nums; white-space: nowrap; font-size: 11.5px; }
.cal-dots { display: inline-flex; gap: 2px; flex: none; }
.cal-text { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; min-width: 0; color: var(--text-2); }
.cal-more { font-size: 12px; color: var(--muted); padding-left: 6px; }
.week { display: grid; grid-template-columns: repeat(7, minmax(0, 1fr)); padding: 0; overflow: hidden; }
.week-day { border-right: 1px solid var(--border); min-height: 420px; display: flex; flex-direction: column; min-width: 0; }
.week-day:last-child { border-right: 0; }
.week-day-head { display: flex; align-items: center; gap: 6px; padding: 10px; border-bottom: 1px solid var(--border); }
.week-dow { font-size: 12px; font-weight: 600; color: var(--muted); text-transform: uppercase; }
.week-num { font-weight: 700; font-size: 16px; width: 28px; height: 28px; display: grid; place-items: center; border-radius: 50%; }
.week-day.today .week-num { background: var(--accent); color: #fff; }
.week-day-head .cal-add { margin-left: auto; }
.week-items { padding: 8px; display: grid; gap: 8px; align-content: start; }
.week-empty { padding: 4px; }
.week-item {
    display: block; padding: 8px; border-radius: 8px; color: var(--text); border: 1px solid var(--border); border-left: 3px solid var(--sc);
    background: var(--surface); font-size: 12.5px;
}
.week-item:hover { text-decoration: none; border-color: var(--sc); }
.week-item p { margin: 4px 0 0; overflow-wrap: anywhere; }
.week-item-top { display: flex; justify-content: space-between; align-items: center; gap: 4px; flex-wrap: wrap; }
.week-item .badge { font-size: 10.5px; padding: 0 6px; }
.legend { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 12px; font-size: 12.5px; color: var(--muted); }
.legend-item { display: inline-flex; align-items: center; gap: 6px; }
.legend-item i { width: 10px; height: 10px; border-radius: 3px; background: var(--sc); }

/* ---------- Accounts / providers ---------- */
.account-list { display: grid; }
.account-row { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--border); }
.account-row:last-child { border-bottom: 0; }
.account-row select { width: auto; }
.check-row { cursor: pointer; }
.check-row input { width: 16px; height: 16px; accent-color: var(--accent); }
.provider-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(170px, 1fr)); gap: 10px; }
.provider-tile {
    display: flex; align-items: center; gap: 10px; padding: 12px; border: 1px solid var(--border); border-radius: var(--radius);
    color: var(--text); font-weight: 600; background: var(--surface); flex-wrap: wrap; position: relative;
}
.provider-tile:hover { border-color: var(--accent); box-shadow: var(--shadow); text-decoration: none; }
.provider-tile small { width: 100%; color: var(--muted); font-weight: 500; font-size: 12px; margin-top: -4px; padding-left: 44px; }
.provider-tile.is-disabled { opacity: .65; }
.provider-tile.is-disabled .pmark { filter: grayscale(.7); }
div.provider-tile.is-disabled:hover { border-color: var(--border); box-shadow: none; }
.service-list { display: grid; gap: 0; }
.service { scroll-margin-top: 20px; }
.service:target { border-color: var(--accent); box-shadow: 0 0 0 3px var(--accent-soft); }
.role-help { display: grid; gap: 6px; margin: 4px 0 0; font-size: 13px; }
.role-help div { display: flex; gap: 8px; }
.role-help dt { font-weight: 600; min-width: 60px; }
.role-help dd { margin: 0; color: var(--muted); }

/* ---------- Tables, library, docs ---------- */
.table-wrap { overflow-x: auto; margin: 0 -4px; }
.table { width: 100%; border-collapse: collapse; }
.table th { text-align: left; font-size: 12px; font-weight: 600; color: var(--muted); padding: 8px 10px; border-bottom: 1px solid var(--border); white-space: nowrap; }
.table td { padding: 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.table tr:last-child td { border-bottom: 0; }
.table .num { text-align: right; font-variant-numeric: tabular-nums; }
.table.small td { padding: 6px 10px; font-size: 13px; }
.lib-list { display: grid; margin-bottom: 12px; }
.lib-item { border-bottom: 1px solid var(--border); }
.lib-item:last-child { border-bottom: 0; }
.lib-item > summary { display: flex; align-items: center; gap: 12px; padding: 11px 0; cursor: pointer; list-style: none; min-width: 0; }
.lib-item > summary::-webkit-details-marker { display: none; }
.lib-item > summary .muted { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.lib-item[open] > summary { padding-bottom: 6px; }
.lib-item > form, .lib-item > pre, .lib-item > h3, .lib-item > .table-wrap { margin-bottom: 14px; }
.add-block > summary { list-style: none; display: inline-flex; }
.add-block > summary::-webkit-details-marker { display: none; }
.add-block[open] > summary { margin-bottom: 12px; }
.tab-panel { scroll-margin-top: 20px; }
[data-tabs-ready] .tab-panel:not(.active) { display: none; }
.api-docs { margin-top: 10px; }
.api-docs summary { cursor: pointer; font-weight: 550; color: var(--accent); }
.docs { margin-top: 10px; }
.code { background: var(--surface-2); padding: 12px 14px; border-radius: 8px; overflow-x: auto; font: 12.5px/1.55 var(--mono); margin-bottom: 10px; }

/* ---------- Charts ---------- */
.chart { height: 220px; position: relative; }
.chart svg { width: 100%; height: 100%; overflow: visible; }
.chart .grid line { stroke: var(--border); }
.chart .axis text { fill: var(--muted); font-size: 11px; }
.chart .line { fill: none; stroke: var(--accent); stroke-width: 2.2; stroke-linejoin: round; stroke-linecap: round; }
.chart .area { fill: var(--accent); opacity: .1; }
.chart .bar { fill: var(--accent); opacity: .85; }
.chart .bar:hover { opacity: 1; }
.chart .dot { fill: var(--accent); }
.chart-tip { position: absolute; pointer-events: none; background: var(--text); color: var(--bg); font-size: 12px; padding: 4px 8px; border-radius: 6px; transform: translate(-50%, -120%); white-space: nowrap; }
.chart-empty { height: 100%; display: grid; place-items: center; color: var(--muted); font-size: 13px; }

/* ---------- Auth pages ---------- */
.auth-body { display: grid; place-items: center; min-height: 100vh; padding: 24px 16px; background: radial-gradient(1200px 500px at 50% -10%, var(--accent-soft), transparent), var(--bg); }
.auth-wrap { width: 100%; max-width: 440px; }
.auth-brand { justify-content: center; display: flex; margin-bottom: 20px; font-size: 19px; }
.auth-card { padding: 28px; }
.auth-card h1 { font-size: 21px; }

/* ---------- Responsive ---------- */
@media (max-width: 1100px) {
    .composer-grid { grid-template-columns: minmax(0, 1fr); }
    .composer-preview { position: static; max-height: none; }
}
@media (max-width: 860px) {
    .sidebar {
        position: fixed; left: 0; top: 0; bottom: 0; z-index: 60; transform: translateX(-100%); transition: transform .2s ease;
        box-shadow: var(--shadow-lg); height: 100%;
    }
    .sidebar-open .sidebar { transform: none; }
    .sidebar-open .scrim { display: block; position: fixed; inset: 0; z-index: 55; background: rgba(15, 17, 21, .45); }
    .sidebar .sidebar-close { display: inline-grid; }
    .topbar {
        display: flex; align-items: center; gap: 8px; padding: 8px 10px; background: var(--surface); border-bottom: 1px solid var(--border);
        position: sticky; top: 0; z-index: 20;
    }
    .topbar-title { flex: 1; font-weight: 650; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .content { padding: 18px 16px 48px; }
    .grid-2 { grid-template-columns: minmax(0, 1fr); }
    .page-head h1 { font-size: 20px; }
    .card { padding: 16px; }
    .post-list.compact { margin: 0 -16px -16px; }
    .post-row { padding: 12px 16px; }
    .post-row-thumb { width: 56px; height: 56px; }
    .cal-weekdays div { padding: 6px 4px; font-size: 10.5px; text-align: center; }
    .cal-day { min-height: 74px; padding: 3px; }
    .cal-num { font-size: 12px; margin: 0 auto; }
    .cal-add { display: none; }
    .cal-item { padding: 0; border-left: 0; justify-content: center; height: 6px; border-radius: 3px; background: var(--sc); }
    .cal-item > * { display: none; }
    .cal-more { font-size: 10px; padding: 0; text-align: center; }
    .week { grid-template-columns: minmax(0, 1fr); }
    .week-day { min-height: 0; border-right: 0; border-bottom: 1px solid var(--border); }
    .week-day:last-child { border-bottom: 0; }
    .flashes { left: 14px; right: 14px; max-width: none; }
    .stats { grid-template-columns: repeat(2, minmax(0, 1fr)); }
    .stat-value { font-size: 22px; }
    .composer-actions .grow { display: none; }
    .composer-actions .btn { flex: 1 1 auto; }
}
@media (prefers-reduced-motion: reduce) {
    *, *::before, *::after { animation: none !important; transition: none !important; }
}

/* ---------- Analytics & reports ---------- */
.analytics-filters { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
.analytics-filters > select { width: auto; flex: 0 1 260px; }
.range-custom { position: relative; }
.range-custom summary { list-style: none; }
.range-custom summary::-webkit-details-marker { display: none; }
.range-menu { padding: 12px; min-width: 220px; right: 0; left: auto; }
.range-menu .field { margin-bottom: 10px; }
.compact-stats .stat { padding: 10px 14px; }
.compact-stats .stat-value { font-size: 19px; }
.chart.short { height: 150px; }
.num.up { color: var(--ok); }
.num.down { color: var(--danger); }
.acct-cell { display: inline-flex; align-items: center; gap: 8px; color: var(--text); }
.acct-cell:hover { text-decoration: none; }
.hbars { display: grid; gap: 8px; }
.hbar { display: grid; grid-template-columns: minmax(110px, 38%) 1fr 52px; gap: 10px; align-items: center; font-size: 13px; }
.hbar-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.hbar-track { height: 8px; border-radius: 4px; background: var(--surface-2); overflow: hidden; }
.hbar-track i { display: block; height: 100%; background: var(--accent); border-radius: 4px; }
.hbar-value { text-align: right; font-variant-numeric: tabular-nums; font-weight: 600; }
.audience-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 8px 28px; }
.audience-grid h3 { margin-top: 4px; }
.best-times { display: flex; gap: 12px; flex-wrap: wrap; margin: 8px 0 16px; }
.best-slot { display: flex; align-items: center; gap: 10px; padding: 10px 14px; border: 1px solid var(--border); border-radius: var(--radius); background: var(--surface); }
.best-rank { width: 26px; height: 26px; border-radius: 50%; background: var(--accent); color: #fff; display: grid; place-items: center; font-weight: 700; font-size: 13px; flex: none; }
.heatmap-wrap { overflow-x: auto; }
.heatmap { border-collapse: separate; border-spacing: 3px; font-size: 11px; color: var(--muted); table-layout: fixed; }
.heatmap th { font-weight: 500; padding: 0 4px; text-align: left; white-space: nowrap; }
.heatmap thead th { text-align: left; height: 18px; width: 22px; max-width: 22px; overflow: visible; font-size: 10px; }
.heatmap thead th:first-child, .heatmap tbody th { width: 34px; }
.heatmap td { width: 22px; height: 22px; border-radius: 4px; background: color-mix(in srgb, var(--accent) calc(var(--a) * 100%), var(--surface-2)); }
.heatmap td.empty { background: var(--surface-2); }
.best-chips { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; margin: -4px 0 14px; }
.best-chips .icon { width: 14px; height: 14px; }
.chip { border: 1px solid var(--border-strong); background: var(--surface); border-radius: 20px; padding: 2px 10px; font-size: 12.5px; cursor: pointer; font-weight: 550; color: var(--text-2); }
.chip:hover { border-color: var(--accent); color: var(--accent); }
.section-title { margin: 8px 0 12px; color: var(--text-2); }
.report-tools .account-row { padding: 8px 0; }
.public-report { background: var(--bg); }
.public-head { display: flex; align-items: center; justify-content: space-between; gap: 16px; padding: 22px 32px; background: var(--surface); border-bottom: 1px solid var(--border); }
.public-head h1 { margin: 0; }
.public-head p { margin: 2px 0 0; }
@media print {
    .sidebar, .topbar, .analytics-filters, .report-tools, .page-head .head-actions, .public-head .btn, .flashes { display: none !important; }
    .content { padding: 0; max-width: none; }
    .card, .stat { box-shadow: none; break-inside: avoid; }
}

/* ---------- Competitors ---------- */
.comp-table td { vertical-align: middle; }
.comp-table tr.is-own td { background: color-mix(in srgb, var(--accent) 5%, transparent); }
.bar-mini { display: block; height: 4px; border-radius: 2px; background: var(--surface-2); margin-top: 4px; min-width: 60px; }
.bar-mini i { display: block; height: 100%; border-radius: 2px; background: var(--accent); }
.spark-cell { width: 120px; }
.inline { display: inline; }
.nowrap { white-space: nowrap; }

/* ---------- Inbox ---------- */
.inbox-list { display: grid; gap: 12px; }
.inbox-item { margin: 0; padding: 14px 18px; }
.inbox-item.is-new { border-left: 3px solid var(--accent); }
.inbox-head { display: flex; gap: 10px; align-items: flex-start; }
.inbox-head .pdot { vertical-align: -2px; }
.inbox-text { margin: 10px 0 6px; white-space: pre-line; overflow-wrap: anywhere; }
.inbox-context { display: block; font-size: 12.5px; color: var(--muted); border-left: 2px solid var(--border-strong); padding-left: 8px; margin-bottom: 10px; }
.inbox-reply { display: flex; gap: 8px; align-items: flex-end; }
.inbox-reply textarea { min-height: 38px; resize: none; }
.inbox-reply-done { display: flex; gap: 8px; align-items: flex-start; padding: 8px 10px; background: var(--surface-2); border-radius: var(--radius-sm); font-size: 13.5px; }
.inbox-reply-done .icon { width: 16px; height: 16px; color: var(--ok); margin-top: 2px; }
.inbox-actions { display: flex; gap: 14px; margin-top: 8px; align-items: center; }
.inbox-actions form { display: inline; }
.inbox-actions .icon { width: 13px; height: 13px; }

/* ---------- Link in bio editor ---------- */
.bio-links { display: grid; gap: 8px; margin-bottom: 12px; }
.bio-link { border: 1px solid var(--border); border-radius: var(--radius-sm); background: var(--surface); }
.bio-link.dragging { opacity: .5; }
.bio-link > summary { display: flex; align-items: center; gap: 10px; padding: 9px 12px; cursor: pointer; list-style: none; min-width: 0; }
.bio-link > summary::-webkit-details-marker { display: none; }
.bio-link > summary .grow { min-width: 0; }
.bio-link > summary small { display: block; }
.bio-link > form { padding: 0 12px 12px; }
.grip { cursor: grab; color: var(--muted); display: grid; }
.input-prefix { display: flex; align-items: stretch; border: 1px solid var(--border-strong); border-radius: var(--radius-sm); overflow: hidden; background: var(--surface); }
.input-prefix span { padding: 8px 4px 8px 11px; color: var(--muted); white-space: nowrap; background: var(--surface-2); font-size: 13px; display: flex; align-items: center; }
.input-prefix input { border: 0 !important; box-shadow: none !important; border-radius: 0; }
.bio-avatar-row { display: flex; align-items: center; gap: 12px; }
.bio-avatar-row .avatar { width: 52px; height: 52px; font-size: 18px; }

/* ---------- AI & Canva ---------- */
.tool-ai { color: var(--accent); }
.ai-actions { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 8px; }
.ai-results { display: grid; gap: 10px; margin-top: 10px; max-height: 50vh; overflow-y: auto; }
.ai-result { border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 12px; background: var(--surface); }
.ai-text { white-space: pre-wrap; overflow-wrap: anywhere; }
.ai-result-actions { display: flex; align-items: center; gap: 8px; margin-top: 10px; }
.dialog-head h2 { display: flex; align-items: center; gap: 8px; }
.canva-bar { margin: -4px 0 12px; }
.canva-head { display: flex; gap: 8px; margin-bottom: 12px; }
.canva-head input { flex: 1; }
.canva-design { display: flex; flex-direction: column; aspect-ratio: auto; height: 150px; align-items: stretch; }
.canva-design img { height: 118px; object-fit: cover; }
.canva-title { font-size: 11.5px; padding: 4px 6px; text-align: left; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; color: var(--text-2); }
.canva-design.busy { opacity: .5; cursor: progress; }

/* ---------- Post types, first comment ---------- */
.type-select { width: auto; padding: 2px 26px 2px 8px; font-size: 12.5px; margin-left: auto; line-height: 18px; background-position: right 6px center; }
.override > span { flex-wrap: wrap; }
.first-comment { margin-top: 12px; }
.first-comment summary { cursor: pointer; font-weight: 550; color: var(--text-2); }
.first-comment[open] summary { margin-bottom: 6px; }
.public-brand { display: flex; align-items: center; gap: 14px; }
.public-logo { max-height: 48px; max-width: 160px; object-fit: contain; }
.logo-preview { width: 120px; height: 52px; border: 1px dashed var(--border-strong); border-radius: 8px; display: grid; place-items: center; overflow: hidden; background: #fff; }
.logo-preview img { max-width: 100%; max-height: 100%; object-fit: contain; }
.head-actions .dropdown-menu { left: auto; right: 0; }

/* ---------- Autolists, import, hashtags, push ---------- */
.day-picker { display: flex; gap: 6px; flex-wrap: wrap; }
.times { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
.time-row { display: inline-flex; align-items: center; gap: 2px; }
.time-row input { width: 120px; }
.mini-media { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; margin: 8px 0; }
.mini-thumb { position: relative; width: 56px; height: 56px; border-radius: 6px; overflow: hidden; background: var(--surface-2); display: grid; place-items: center; border: 1px solid var(--border); }
.mini-thumb img { width: 100%; height: 100%; object-fit: cover; }
.mini-thumb button { position: absolute; top: 2px; right: 2px; width: 18px; height: 18px; border-radius: 50%; border: 0; background: rgba(0, 0, 0, .6); color: #fff; display: grid; place-items: center; cursor: pointer; padding: 0; }
.mini-thumb button .icon { width: 11px; height: 11px; }
.lib-item textarea { margin-bottom: 4px; }
.add-item { border-top: 1px solid var(--border); padding-top: 12px; margin-top: 4px; }
.tag-post { padding: 10px 0; border-bottom: 1px solid var(--border); }
.tag-post:last-child { border-bottom: 0; }
.tag-post p { margin: 4px 0; white-space: pre-line; overflow-wrap: anywhere; }
.push-status { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-bottom: 14px; }
.card h2 .icon { width: 17px; height: 17px; vertical-align: -3px; }

/* ---------- Offline bar and local drafts ---------- */
.offline-bar { display: grid; gap: 6px; padding: 20px 32px 0; max-width: 1280px; width: 100%; margin: 0 auto; }
.offline-bar[hidden] { display: none; }
.ob-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; padding: 9px 12px; border-radius: var(--radius-sm); background: var(--surface); border: 1px solid var(--border); font-size: 13.5px; }
.ob-row > span { flex: 1; min-width: 200px; }
.ob-row > .icon { color: var(--muted); flex: none; }
.ob-error { border-color: color-mix(in srgb, var(--danger) 40%, var(--border)); }
.ob-error > .icon { color: var(--danger); }
.draft-restore { align-items: center; flex-wrap: wrap; background: var(--accent-soft, var(--surface)); border-color: color-mix(in srgb, var(--accent) 25%, transparent); }
.draft-restore > span { flex: 1; min-width: 200px; }
@media (max-width: 860px) { .offline-bar { padding: 12px 16px 0; } }
MC_ASSET_1],
        'app.js' => [false, <<<'MC_ASSET_2'
/* MixCreate — vanilla JS, no build step. */
(function () {
    'use strict';

    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));
    const base = ($('meta[name="base-url"]') || {}).content || '';
    const csrf = () => ($('meta[name="csrf-token"]') || {}).content || '';
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const iconUrl = (() => { const u = $('use[href*="icons.svg"]'); return u ? u.getAttribute('href').split('#')[0] : base + '/assets/icons.svg'; })();
    const icon = (name) => `<svg class="icon" aria-hidden="true"><use href="${iconUrl}#${name}"></use></svg>`;

    function toast(msg, type) {
        const root = $('#toasts');
        if (!root) return alert(msg);
        const el = document.createElement('div');
        el.className = 'toast' + (type === 'error' ? ' error' : '');
        el.textContent = msg;
        root.appendChild(el);
        setTimeout(() => el.remove(), 3500);
    }

    async function api(path, opts = {}) {
        const headers = Object.assign({ 'X-CSRF-Token': csrf(), 'X-Requested-With': 'fetch', Accept: 'application/json' }, opts.headers || {});
        let body = opts.body;
        if (body && !(body instanceof FormData) && typeof body !== 'string') {
            body = JSON.stringify(body);
            headers['Content-Type'] = 'application/json';
        }
        const res = await fetch(base + path, { method: opts.method || 'GET', headers, body, credentials: 'same-origin' });
        const data = await res.json().catch(() => ({}));
        if (!res.ok) throw new Error(data.error || 'Request failed (' + res.status + ')');
        return data;
    }

    /** Upload with progress, resolves with the media object. */
    function upload(file, onProgress) {
        return new Promise((resolve, reject) => {
            const fd = new FormData();
            fd.append('file', file);
            fd.append('_token', csrf());
            const xhr = new XMLHttpRequest();
            xhr.open('POST', base + '/media');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.setRequestHeader('X-Requested-With', 'fetch');
            xhr.upload.onprogress = (e) => e.lengthComputable && onProgress && onProgress(e.loaded / e.total);
            xhr.onload = () => {
                let data = {};
                try { data = JSON.parse(xhr.responseText); } catch (e) { /* ignore */ }
                xhr.status >= 200 && xhr.status < 300 ? resolve(data.media) : reject(new Error(data.error || 'Upload failed (' + xhr.status + ')'));
            };
            xhr.onerror = () => reject(new Error('Upload failed. Check your connection.'));
            xhr.send(fd);
        });
    }

    function progressRow(container, name) {
        const row = document.createElement('div');
        row.className = 'progress-row';
        row.innerHTML = `<span>${esc(name)}</span><div class="progress-bar"><i></i></div>`;
        container.appendChild(row);
        return { set: (p) => { row.querySelector('i').style.width = Math.round(p * 100) + '%'; }, done: () => row.remove() };
    }

    function wireDropzone(zone, input, onFiles) {
        input.addEventListener('change', () => { onFiles(Array.from(input.files)); input.value = ''; });
        ['dragenter', 'dragover'].forEach((ev) => zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.add('over'); }));
        ['dragleave', 'drop'].forEach((ev) => zone.addEventListener(ev, (e) => { e.preventDefault(); zone.classList.remove('over'); }));
        zone.addEventListener('drop', (e) => onFiles(Array.from(e.dataTransfer.files)));
    }

    /* ---------- Local storage: composer drafts and an offline outbox, kept on this device ---------- */
    const scope = ($('meta[name="mc-scope"]') || {}).content || '';
    const store = {
        get(key, fallback) { try { const v = localStorage.getItem(key); return v === null ? fallback : JSON.parse(v); } catch (e) { return fallback; } },
        set(key, value) { try { localStorage.setItem(key, JSON.stringify(value)); return true; } catch (e) { return false; } },
        del(key) { try { localStorage.removeItem(key); } catch (e) { /* ignore */ } },
        keys(prefix) { try { return Object.keys(localStorage).filter((k) => k.startsWith(prefix)); } catch (e) { return []; } },
    };
    const draftKey = (path) => `mc:d:${scope}:${path}`;
    const outboxKey = `mc:o:${scope}`;
    const outbox = () => store.get(outboxKey, []);
    const pathOf = (url) => new URL(url, location.href).pathname.slice(base.length) || '/';
    const ago = (t) => {
        const m = Math.round((Date.now() - t) / 60000);
        if (m < 1) return 'just now';
        if (m < 60) return m + ' min ago';
        const h = Math.round(m / 60);
        return h < 24 ? h + ' h ago' : new Date(t).toLocaleString([], { month: 'short', day: 'numeric', hour: 'numeric', minute: '2-digit' });
    };

    const bar = $('#offline-bar');
    function renderBar(status) {
        if (!bar || !scope) return;
        const items = outbox();
        const failed = items.filter((i) => i.error);
        const waiting = items.length - failed.length;
        let html = '';
        if (!navigator.onLine) {
            html += `<div class="ob-row">${icon('alert')}<span><strong>You’re offline.</strong> Pages you’ve opened before still work, and posts you save are kept on this device${waiting ? ` (${waiting} waiting)` : ''} until you’re back online.</span></div>`;
        } else if (waiting) {
            html += `<div class="ob-row">${icon('refresh')}<span>${status || `${waiting} post${waiting > 1 ? 's' : ''} saved on this device ${waiting > 1 ? 'are' : 'is'} waiting to send.`}</span>${status ? '' : '<button type="button" class="btn btn-sm" data-ob-send>Send now</button>'}</div>`;
        }
        failed.forEach((i) => {
            html += `<div class="ob-row ob-error">${icon('alert')}<span>“${esc(i.title)}” couldn’t be sent: ${esc(i.error)}</span>
                <button type="button" class="btn btn-sm" data-ob-open="${esc(i.id)}">Open</button>
                <button type="button" class="btn btn-sm btn-ghost" data-ob-discard="${esc(i.id)}">Discard</button></div>`;
        });
        bar.innerHTML = html;
        bar.hidden = html === '';
    }

    let flushing = false;
    async function flushOutbox() {
        if (flushing || !navigator.onLine || !scope) return;
        const pending = outbox().filter((i) => !i.error);
        if (!pending.length) return;
        flushing = true;
        let sent = 0;
        let last = null;
        for (const item of pending) {
            renderBar(`Sending “${esc(item.title)}”…`);
            const fd = new FormData();
            item.entries.forEach(([k, v]) => fd.append(k, v));
            fd.set('_token', csrf());
            let res, html;
            try {
                res = await fetch(base + item.url, { method: 'POST', body: fd, credentials: 'same-origin' });
                html = await res.text();
            } catch (err) { break; } // still offline after all; try again later
            if (/\/(login|setup)(\?|$)/.test(new URL(res.url).pathname)) { toast('Sign in again to send the posts saved on this device.', 'error'); break; }
            const doc = new DOMParser().parseFromString(html, 'text/html');
            const errors = $$('.flash-error span', doc).map((s) => s.textContent.trim());
            const items = outbox();
            const idx = items.findIndex((i) => i.id === item.id);
            if (idx < 0) continue;
            if (errors.length || !res.ok) {
                items[idx].error = errors.join(' ') || 'The server returned an error (' + res.status + ').';
            } else {
                items.splice(idx, 1);
                sent++;
                last = { url: res.url, from: item.from };
            }
            store.set(outboxKey, items);
        }
        flushing = false;
        renderBar();
        if (sent) {
            toast(sent > 1 ? `${sent} saved posts sent` : 'Saved post sent');
            // If the composer this post came from is still open, show the saved post.
            const f = $('[data-composer]');
            if (last && f && f.dataset.queued && pathOf(location.href) === last.from) location.href = last.url;
        }
    }

    if (bar) {
        bar.addEventListener('click', (e) => {
            const send = e.target.closest('[data-ob-send]');
            if (send) return flushOutbox();
            const open = e.target.closest('[data-ob-open]');
            const discard = e.target.closest('[data-ob-discard]');
            const id = (open || discard)?.dataset.obOpen || (open || discard)?.dataset.obDiscard;
            if (!id) return;
            const items = outbox();
            const item = items.find((i) => i.id === id);
            if (discard && !confirm('Delete this post from this device? It was never sent.')) return;
            store.set(outboxKey, items.filter((i) => i.id !== id));
            if (open && item) {
                // Reopen it in the composer as an unsaved draft.
                store.set(draftKey(item.url), { at: item.at, entries: item.entries.filter(([k]) => k !== 'action'), media: item.media || [] });
                location.href = base + item.from;
                return;
            }
            renderBar();
        });
        window.addEventListener('online', () => { renderBar(); flushOutbox(); });
        window.addEventListener('offline', () => renderBar());
        renderBar();
        flushOutbox();
    }

    // Signing out removes this account's drafts and saved pages from the device.
    $('[data-logout]')?.addEventListener('submit', (e) => {
        const n = outbox().length;
        if (n && !confirm(`${n} post${n > 1 ? 's' : ''} saved on this device ${n > 1 ? 'haven’t' : 'hasn’t'} been sent yet and will be deleted if you sign out. Sign out anyway?`)) {
            e.preventDefault();
            return;
        }
        const uid = scope.split('-')[0];
        store.keys('mc:').filter((k) => k.startsWith(`mc:d:${uid}-`) || k.startsWith(`mc:o:${uid}-`)).forEach((k) => store.del(k));
        if (window.caches) caches.keys().then((keys) => keys.filter((k) => k.startsWith('mixcreate-pages')).forEach((k) => caches.delete(k)));
    });

    /* ---------- Global UI ---------- */
    document.addEventListener('click', (e) => {
        const t = e.target.closest('[data-toggle-sidebar]');
        if (t) document.body.classList.toggle('sidebar-open');

        const fc = e.target.closest('.flash-close');
        if (fc) fc.closest('.flash').remove();

        const copy = e.target.closest('[data-copy]');
        if (copy) {
            navigator.clipboard?.writeText(copy.textContent.trim()).then(() => toast('Copied'), () => {
                const r = document.createRange(); r.selectNodeContents(copy);
                const s = getSelection(); s.removeAllRanges(); s.addRange(r);
            });
        }

        const theme = e.target.closest('[data-theme-toggle]');
        if (theme) {
            const dark = document.documentElement.dataset.theme
                ? document.documentElement.dataset.theme === 'dark'
                : matchMedia('(prefers-color-scheme: dark)').matches;
            document.documentElement.dataset.theme = dark ? 'light' : 'dark';
            try { localStorage.setItem('mc-theme', document.documentElement.dataset.theme); } catch (err) { /* private mode */ }
        }

        const open = e.target.closest('[data-open-dialog]');
        if (open) $('#' + open.dataset.openDialog)?.showModal();
        const close = e.target.closest('[data-close-dialog]');
        if (close) close.closest('dialog')?.close();

        // Close open dropdowns/workspace menu when clicking elsewhere.
        $$('details.dropdown[open], details.ws-switch[open]').forEach((d) => { if (!d.contains(e.target)) d.open = false; });
    });

    // Confirm before destructive submits. Works for forms and for individual submit buttons.
    document.addEventListener('submit', (e) => {
        const form = e.target;
        const btn = e.submitter;
        const msg = (btn && btn.dataset.confirm) || form.dataset.confirm;
        if (msg && !confirm(msg)) { e.preventDefault(); return; }
        if (btn && btn.hasAttribute('data-needs-date')) {
            const d = form.querySelector('[name="date"]');
            if (d && !d.value) { e.preventDefault(); toast('Pick a date to schedule.', 'error'); d.focus(); return; }
        }
        // Prevent double submits.
        setTimeout(() => { if (!e.defaultPrevented) $$('button[type="submit"]', form).forEach((b) => { b.disabled = true; }); }, 0);
    }, true);
    // A form whose submit is triggered by a button belonging to another form via form="" still fires on that form.

    $$('[data-autosubmit]').forEach((el) => el.addEventListener('change', () => el.form.submit()));

    $$('.flash-success').forEach((f) => setTimeout(() => f.remove(), 5000));

    const tzSelect = $('[data-detect-timezone]');
    if (tzSelect) {
        try {
            const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;
            if (tz && $$('option', tzSelect).some((o) => o.value === tz) && tzSelect.value === 'UTC') tzSelect.value = tz;
        } catch (e) { /* ignore */ }
    }

    // Hash tabs (Library page).
    const tabs = $('[data-tabs]');
    if (tabs) {
        const links = $$('a', tabs);
        const show = () => {
            const id = (location.hash || links[0].getAttribute('href')).slice(1);
            const target = document.getElementById(id) ? id : links[0].getAttribute('href').slice(1);
            $$('.tab-panel').forEach((p) => p.classList.toggle('active', p.id === target));
            links.forEach((l) => l.classList.toggle('active', l.getAttribute('href') === '#' + target));
        };
        document.body.setAttribute('data-tabs-ready', '');
        window.addEventListener('hashchange', show);
        show();
        window.scrollTo(0, 0);
    }

    /* ---------- Media page ---------- */
    const mediaPage = $('[data-media-page]');
    if (mediaPage) {
        const zone = $('.dropzone', mediaPage);
        const input = $('input[type=file]', mediaPage);
        const prog = $('.upload-progress', mediaPage);
        wireDropzone(zone, input, async (files) => {
            let ok = 0;
            for (const f of files) {
                const p = progressRow(prog, f.name);
                try { await upload(f, p.set); ok++; } catch (err) { toast(f.name + ': ' + err.message, 'error'); }
                p.done();
            }
            if (ok) location.reload();
        });
    }

    /* ---------- Charts (inline SVG) ---------- */
    $$('[data-chart]:not([data-chart="spark"])').forEach((el) => {
        const series = JSON.parse(el.dataset.series || '[]');
        const type = el.dataset.chart;
        const pts = series.filter((s) => s.value !== null);
        if (!pts.length || (type === 'line' && pts.length < 2) || pts.every((p) => p.value === 0 && type === 'bar')) {
            el.innerHTML = '<div class="chart-empty">No data yet</div>';
            return;
        }
        const W = el.clientWidth || 500, H = el.clientHeight || 220, padL = 40, padB = 22, padT = 8, padR = 6;
        const vals = pts.map((p) => p.value);
        let min = type === 'bar' ? 0 : Math.min(...vals), max = Math.max(...vals);
        if (min === max) { max += 1; if (type !== 'bar') min = Math.max(0, min - 1); }
        const span = max - min; min = type === 'bar' ? 0 : Math.max(0, Math.floor(min - span * 0.1)); max = Math.ceil(max + span * 0.1);
        const n = series.length;
        const x = (i) => padL + (n === 1 ? (W - padL - padR) / 2 : i * (W - padL - padR) / (n - 1));
        const y = (v) => padT + (H - padT - padB) * (1 - (v - min) / (max - min || 1));
        const fmt = (v) => v >= 10000 ? (v / 1000).toFixed(1) + 'k' : (max - min <= 8 && v % 1 ? v.toFixed(1) : Math.round(v).toLocaleString());
        let svg = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${esc(el.dataset.label)} chart"><g class="grid">`;
        const ticks = 4;
        for (let t = 0; t <= ticks; t++) {
            const v = min + (max - min) * t / ticks;
            svg += `<line x1="${padL}" x2="${W - padR}" y1="${y(v)}" y2="${y(v)}"/>`;
        }
        svg += '</g><g class="axis">';
        for (let t = 0; t <= ticks; t++) {
            const v = min + (max - min) * t / ticks;
            svg += `<text x="${padL - 6}" y="${y(v) + 4}" text-anchor="end">${fmt(v)}</text>`;
        }
        const every = Math.max(1, Math.ceil(n / 6));
        series.forEach((s, i) => {
            if (i % every === 0 || i === n - 1) {
                const d = new Date(s.date + 'T00:00:00');
                svg += `<text x="${x(i)}" y="${H - 4}" text-anchor="middle">${d.toLocaleDateString(undefined, { month: 'short', day: 'numeric' })}</text>`;
            }
        });
        svg += '</g>';
        if (type === 'bar') {
            const bw = Math.max(2, (W - padL - padR) / n * 0.6);
            series.forEach((s, i) => {
                const v = s.value || 0;
                svg += `<rect class="bar" x="${x(i) - bw / 2}" y="${y(v)}" width="${bw}" height="${Math.max(0, y(min) - y(v))}" rx="2" data-i="${i}"/>`;
            });
        } else {
            let d = '', first = null, last = null;
            series.forEach((s, i) => {
                if (s.value === null) return;
                d += (d ? 'L' : 'M') + x(i) + ' ' + y(s.value);
                if (first === null) first = i;
                last = i;
            });
            svg += `<path class="area" d="${d}L${x(last)} ${y(min)}L${x(first)} ${y(min)}Z"/><path class="line" d="${d}"/>`;
        }
        svg += '</svg><div class="chart-tip" hidden></div>';
        el.innerHTML = svg;
        const tip = $('.chart-tip', el);
        const svgEl = $('svg', el);
        svgEl.addEventListener('mousemove', (e) => {
            const r = svgEl.getBoundingClientRect();
            const px = (e.clientX - r.left) * (W / r.width);
            const i = Math.max(0, Math.min(n - 1, Math.round((px - padL) / ((W - padL - padR) / Math.max(1, n - 1)))));
            const s = series[i];
            if (!s || s.value === null) { tip.hidden = true; return; }
            tip.hidden = false;
            tip.textContent = new Date(s.date + 'T00:00:00').toLocaleDateString(undefined, { month: 'short', day: 'numeric' }) + ': ' + Number(s.value).toLocaleString();
            tip.style.left = (x(i) * r.width / W) + 'px';
            tip.style.top = (y(s.value || 0) * r.height / H) + 'px';
        });
        svgEl.addEventListener('mouseleave', () => { tip.hidden = true; });
    });


    /* ---------- Canva design browser (composer media dialog and media page) ---------- */
    const CLOUD = {
        canva: { list: '/canva/designs', import: '/canva/import', connect: '/canva/connect', name: 'Canva' },
        drive: { list: '/drive/files', import: '/drive/import', connect: '/drive/connect', name: 'Google Drive' },
    };
    function canvaBrowser({ grid, q, more, back, onImport, source = 'canva' }) {
        const src = CLOUD[source];
        let continuation = null;
        let timer = null;
        async function load(reset) {
            if (reset) { continuation = null; grid.innerHTML = '<p class="muted">Loading…</p>'; }
            try {
                const r = await api(src.list + '?' + new URLSearchParams({ q: q.value, continuation: continuation || '' }));
                if (!r.connected) {
                    grid.innerHTML = `<div class="empty-state"><p class="muted">Connect your ${src.name} account to import from it.</p><a class="btn btn-primary" href="${base}${src.connect}?back=${encodeURIComponent(back)}">Connect ${src.name}</a></div>`;
                    more.hidden = true;
                    return;
                }
                if (reset) grid.innerHTML = '';
                r.items.forEach((d) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'media-pick canva-design';
                    b.title = d.title;
                    b.innerHTML = (d.thumbnail ? `<img src="${esc(d.thumbnail)}" alt="" loading="lazy" referrerpolicy="no-referrer">` : '<span class="muted small">No preview</span>') + `<span class="canva-title">${esc(d.title)}</span>`;
                    b.addEventListener('click', async () => {
                        b.classList.add('busy');
                        b.disabled = true;
                        try {
                            const res = await api(src.import, { method: 'POST', body: { design: d.id, format: 'png', _token: csrf() } });
                            onImport(res.media);
                        } catch (err) { toast(err.message, 'error'); }
                        b.classList.remove('busy');
                        b.disabled = false;
                    });
                    grid.appendChild(b);
                });
                if (!grid.children.length) grid.innerHTML = '<p class="muted">No designs found.</p>';
                continuation = r.continuation;
                more.hidden = !continuation;
            } catch (err) {
                grid.innerHTML = `<p class="error-text">${esc(err.message)}</p>`;
            }
        }
        const onInput = () => { clearTimeout(timer); timer = setTimeout(() => load(true), 350); };
        const onMore = () => load(false);
        q.addEventListener('input', onInput);
        more.addEventListener('click', onMore);
        q.value = '';
        load(true);
        return { destroy: () => { q.removeEventListener('input', onInput); more.removeEventListener('click', onMore); } };
    }

    let pageBrowser = null;
    $$('[data-cloud-page]').forEach((btn) => btn.addEventListener('click', () => {
        const source = btn.dataset.cloudPage;
        $('#cloud-title').textContent = 'Import from ' + CLOUD[source].name;
        pageBrowser?.destroy();
        pageBrowser = canvaBrowser({ source, grid: $('#canva-grid'), q: $('#canva-q'), more: $('#canva-more'), back: '/media', onImport: () => location.reload() });
    }));
    const params = new URLSearchParams(location.search);
    const returned = params.get('canva') ? 'canva' : (params.get('drive') ? 'drive' : null);
    if (returned) {
        if ($('[data-cloud-page="' + returned + '"]')) setTimeout(() => $('[data-cloud-page="' + returned + '"]').click(), 200);
        else if ($('[data-cloud-open="' + returned + '"]')) setTimeout(() => { $('[data-open-media]')?.click(); setTimeout(() => $('[data-cloud-open="' + returned + '"]')?.click(), 300); }, 300);
    }

    /* ---------- Small helpers for other pages ---------- */
    $$('[data-placeholder-target]').forEach((sel) => {
        const target = $(sel.dataset.placeholderTarget);
        const sync = () => { const o = sel.selectedOptions[0]; if (o && target) target.placeholder = o.dataset.placeholder || ''; };
        sel.addEventListener('change', sync);
        sync();
    });

    // Sparklines (competitors).
    $$('[data-chart="spark"]').forEach((el) => {
        const s = JSON.parse(el.dataset.series || '[]').map((p) => p.value);
        if (s.length < 2) return;
        const W = 110, H = 28, min = Math.min(...s), max = Math.max(...s), span = max - min || 1;
        const pts = s.map((v, i) => `${(i / (s.length - 1) * W).toFixed(1)},${(H - 2 - (v - min) / span * (H - 4)).toFixed(1)}`).join(' ');
        const up = s[s.length - 1] >= s[0];
        el.innerHTML = `<svg viewBox="0 0 ${W} ${H}" width="${W}" height="${H}" aria-hidden="true"><polyline points="${pts}" fill="none" stroke="${up ? 'var(--ok)' : 'var(--danger)'}" stroke-width="1.6" stroke-linejoin="round" stroke-linecap="round"/></svg>`;
    });

    // Inbox: grow reply boxes, Cmd/Ctrl+Enter to send.
    $$('.inbox-reply textarea').forEach((t) => {
        t.addEventListener('input', () => { t.style.height = 'auto'; t.style.height = Math.min(200, t.scrollHeight) + 'px'; });
        t.addEventListener('keydown', (e) => { if (e.key === 'Enter' && (e.metaKey || e.ctrlKey)) t.form.requestSubmit(); });
    });

    // Link in bio: drag to reorder, pick an avatar.
    const bioList = $('[data-bio-sortable]');
    if (bioList) {
        let dragging = null;
        $$('.bio-link', bioList).forEach((item) => {
            const grip = $('.grip', item);
            if (!grip) return;
            grip.addEventListener('mousedown', () => { item.draggable = true; });
            item.addEventListener('dragstart', () => { dragging = item; item.classList.add('dragging'); });
            item.addEventListener('dragend', async () => {
                item.classList.remove('dragging');
                item.draggable = false;
                dragging = null;
                try { await api('/bio/links/reorder', { method: 'POST', body: { order: $$('.bio-link', bioList).map((x) => x.dataset.id), _token: csrf() } }); toast('Order saved'); }
                catch (err) { toast(err.message, 'error'); }
            });
        });
        bioList.addEventListener('dragover', (e) => {
            e.preventDefault();
            const over = e.target.closest('.bio-link');
            if (!dragging || !over || over === dragging) return;
            const r = over.getBoundingClientRect();
            over.parentNode.insertBefore(dragging, e.clientY > r.top + r.height / 2 ? over.nextSibling : over);
        });
    }
    const pickAvatar = $('[data-pick-avatar]');
    if (pickAvatar) {
        pickAvatar.addEventListener('click', async () => {
            const dlg = $('#avatar-dialog');
            const grid = $('#avatar-library');
            dlg.showModal();
            try {
                const { media } = await api('/media');
                const imgs = media.filter((m) => !m.is_video);
                grid.innerHTML = imgs.length ? '' : '<p class="muted">Upload an image on the Media page first.</p>';
                imgs.forEach((m) => {
                    const b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'media-pick';
                    b.innerHTML = `<img src="${esc(m.url)}" alt="" loading="lazy">`;
                    b.addEventListener('click', () => {
                        $('[data-bio-avatar]').value = m.id;
                        const holder = pickAvatar.closest('.bio-avatar-row').querySelector('.avatar, .logo-preview');
                        holder.querySelector('.muted')?.remove();
                        holder.querySelector('img')?.remove();
                        holder.insertAdjacentHTML('beforeend', `<img src="${esc(m.url)}" alt="">`);
                        dlg.close();
                        toast('Picture chosen. Save the page to apply.');
                    });
                    grid.appendChild(b);
                });
            } catch (err) { grid.innerHTML = `<p class="error-text">${esc(err.message)}</p>`; }
        });
    }


    /* ---------- Installable app & push notifications ---------- */
    if ('serviceWorker' in navigator && $('meta[name="mc-sw"]') && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
        navigator.serviceWorker.register($('meta[name="mc-sw"]').content, { scope: base + '/' }).catch(() => {});
    }
    let installPrompt = null;
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        installPrompt = e;
        $$('[data-install]').forEach((b) => { b.hidden = false; });
    });
    $$('[data-install]').forEach((b) => b.addEventListener('click', async () => {
        if (!installPrompt) return;
        installPrompt.prompt();
        await installPrompt.userChoice;
        installPrompt = null;
        b.hidden = true;
    }));

    const pushBox = $('[data-push]');
    if (pushBox) {
        const state = $('[data-push-state]', pushBox);
        const on = $('[data-push-enable]', pushBox);
        const off = $('[data-push-disable]', pushBox);
        const urlB64 = (s) => { const p = '='.repeat((4 - s.length % 4) % 4); const raw = atob((s + p).replace(/-/g, '+').replace(/_/g, '/')); return Uint8Array.from(raw, (c) => c.charCodeAt(0)); };
        const refresh = async () => {
            if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
                state.textContent = 'This browser doesn’t support push notifications here (it needs HTTPS, or the Home Screen app on iPhone).';
                return;
            }
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.getSubscription();
            state.textContent = sub ? 'On for this device.' : (Notification.permission === 'denied' ? 'Blocked in browser settings.' : 'Off for this device.');
            on.hidden = !!sub || Notification.permission === 'denied';
            off.hidden = !sub;
        };
        on.addEventListener('click', async () => {
            try {
                const perm = await Notification.requestPermission();
                if (perm !== 'granted') { refresh(); return; }
                const reg = await navigator.serviceWorker.ready;
                const { key } = await api('/push/key');
                const sub = await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: urlB64(key) });
                await api('/push/subscribe', { method: 'POST', body: Object.assign(sub.toJSON(), { _token: csrf() }) });
                toast('Notifications on');
            } catch (err) { toast(err.message, 'error'); }
            refresh();
        });
        off.addEventListener('click', async () => {
            const reg = await navigator.serviceWorker.ready;
            const sub = await reg.pushManager.getSubscription();
            if (sub) {
                await api('/push/unsubscribe', { method: 'POST', body: { endpoint: sub.endpoint, _token: csrf() } }).catch(() => {});
                await sub.unsubscribe();
            }
            refresh();
        });
        refresh();
    }

    /* ---------- Autolists: time rows and media picking ---------- */
    document.addEventListener('click', (e) => {
        const add = e.target.closest('[data-add-time]');
        if (add) {
            const row = document.createElement('span');
            row.className = 'time-row';
            row.innerHTML = `<input type="time" name="times[]" value="12:00"><button type="button" class="icon-btn" data-remove-time aria-label="Remove time">${icon('x')}</button>`;
            add.before(row);
        }
        const rm = e.target.closest('[data-remove-time]');
        if (rm && $$('.time-row', rm.closest('[data-times]')).length > 1) rm.closest('.time-row').remove();
        const rmMedia = e.target.closest('[data-remove-media]');
        if (rmMedia) rmMedia.closest('.mini-thumb').remove();
    });
    const pickDlg = $('#pick-media-dialog');
    if (pickDlg) {
        let target = null;
        let chosen = [];
        let items = [];
        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('[data-pick-media]');
            if (!btn) return;
            target = btn.closest('[data-media-field]');
            chosen = [];
            pickDlg.showModal();
            const grid = $('#pick-media-grid');
            try {
                items = (await api('/media')).media;
                const draw = () => {
                    grid.innerHTML = items.length ? items.map((m) => `<button type="button" class="media-pick${chosen.includes(m.id) ? ' selected' : ''}" data-id="${m.id}">${m.is_video ? `<video src="${esc(m.url)}#t=0.1" muted preload="metadata"></video>` : `<img src="${esc(m.url)}" alt="" loading="lazy">`}<span class="num">${chosen.indexOf(m.id) + 1}</span></button>`).join('') : '<p class="muted">Upload media on the Media page first.</p>';
                };
                grid.onclick = (ev) => {
                    const b = ev.target.closest('.media-pick');
                    if (!b) return;
                    const id = Number(b.dataset.id);
                    const i = chosen.indexOf(id);
                    i >= 0 ? chosen.splice(i, 1) : chosen.push(id);
                    draw();
                };
                draw();
            } catch (err) { grid.innerHTML = `<p class="error-text">${esc(err.message)}</p>`; }
        });
        $('#pick-media-done').addEventListener('click', () => {
            chosen.forEach((id) => {
                const m = items.find((x) => x.id === id);
                const el = document.createElement('span');
                el.className = 'mini-thumb';
                el.innerHTML = `<input type="hidden" name="media[]" value="${m.id}">${m.is_video ? icon('play') : `<img src="${esc(m.url)}" alt="">`}<button type="button" data-remove-media aria-label="Remove">${icon('x')}</button>`;
                target.querySelector('[data-pick-media]').before(el);
            });
            pickDlg.close();
        });
    }

    /* ---------- Composer ---------- */
    const form = $('[data-composer]');
    if (form) composer(form);

    function composer(form) {
        const cfg = JSON.parse($('#composer-data').textContent);
        const accounts = new Map(cfg.accounts.map((a) => [a.id, a]));
        const content = $('#content');
        const strip = $('#media-strip');
        const counters = $('#counters');
        const previews = $('#previews');
        let media = cfg.media.slice();

        const selected = () => $$('input[name="accounts[]"]:checked', form).map((i) => accounts.get(Number(i.value))).filter(Boolean);
        const overrideFor = (id) => { const t = form.querySelector(`textarea[name="overrides[${id}]"]`); return t ? t.value.trim() : ''; };
        const applyVars = (text) => text.replace(/\{\{\s*([\w.-]+)\s*\}\}/g, (m, k) => (k in cfg.variables ? cfg.variables[k] : m));
        const countFor = (text, provider) => {
            text = applyVars(text);
            if (provider === 'x') text = text.replace(/https?:\/\/\S+/g, 'x'.repeat(23));
            return Array.from(text).length;
        };

        function renderMedia() {
            strip.innerHTML = '';
            media.forEach((m, i) => {
                const el = document.createElement('div');
                el.className = 'media-item';
                el.draggable = !cfg.readonly;
                el.dataset.index = i;
                const isVideo = m.mime.startsWith('video/');
                el.innerHTML = (isVideo ? `<video src="${esc(m.url)}#t=0.1" muted preload="metadata"></video><span class="play">${icon('play')}</span>` : `<img src="${esc(m.url)}" alt="">`)
                    + `<input type="hidden" name="media[]" value="${m.id}">`
                    + (cfg.readonly ? '' : `<button type="button" class="remove" aria-label="Remove">${icon('x')}</button>`);
                strip.appendChild(el);
            });
            update();
        }

        strip.addEventListener('click', (e) => {
            const rm = e.target.closest('.remove');
            if (!rm) return;
            media.splice(Number(rm.closest('.media-item').dataset.index), 1);
            renderMedia();
        });

        // Drag to reorder media.
        let dragFrom = null;
        strip.addEventListener('dragstart', (e) => { const it = e.target.closest('.media-item'); if (!it) return; dragFrom = Number(it.dataset.index); it.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
        strip.addEventListener('dragend', () => { dragFrom = null; renderMedia(); });
        strip.addEventListener('dragover', (e) => {
            e.preventDefault();
            const it = e.target.closest('.media-item');
            if (dragFrom === null || !it) return;
            const to = Number(it.dataset.index);
            if (to === dragFrom) return;
            const [m] = media.splice(dragFrom, 1);
            media.splice(to, 0, m);
            dragFrom = to;
            $$('.media-item', strip).forEach((el, i) => { el.dataset.index = i; });
            renderMediaKeepDrag();
        });
        function renderMediaKeepDrag() {
            const items = $$('.media-item', strip);
            media.forEach((m, i) => {
                const el = items.find((x) => x.querySelector('input').value == m.id);
                if (el) { strip.appendChild(el); el.dataset.index = i; el.classList.toggle('dragging', i === dragFrom); }
            });
        }

        function linkify(text) {
            return esc(text)
                .replace(/\{\{\s*([\w.-]+)\s*\}\}/g, (m, k) => `<span class="var">${k in cfg.variables ? esc(cfg.variables[k]) : m}</span>`)
                .replace(/(https?:\/\/[^\s<]+)/g, '<span class="hl">$1</span>')
                .replace(/(^|\s)([#@][\w.]+)/gu, '$1<span class="hl">$2</span>');
        }

        function update() {
            const accts = selected();
            const main = content.value;

            // Show override fields only for selected accounts.
            $$('[data-override-for]', form).forEach((el) => {
                const id = Number(el.dataset.overrideFor);
                const on = accts.some((a) => a.id === id);
                el.hidden = !on;
                const a = accounts.get(id);
                const ta = el.querySelector('textarea');
                if (!cfg.readonly) ta.placeholder = main;
                const p = cfg.providers[a.provider];
                const counter = el.querySelector('[data-override-count]');
                if (ta.value.trim() && p) {
                    const n = countFor(ta.value, a.provider);
                    counter.textContent = n + '/' + p.limit;
                    counter.classList.toggle('over', n > p.limit);
                } else counter.textContent = '';
            });

            // Character counters, one per platform in use.
            if (counters) {
                const seen = new Set();
                let html = '';
                accts.forEach((a) => {
                    if (seen.has(a.provider) || overrideFor(a.id)) return;
                    seen.add(a.provider);
                    const p = cfg.providers[a.provider];
                    if (!p) return;
                    const n = countFor(main, a.provider);
                    const left = p.limit - n;
                    html += `<span class="counter${left < 0 ? ' over' : ''}" title="${esc(p.name)}: ${n} of ${p.limit}"><span class="pdot" style="--pc:${p.color}">${esc(abbr(a.provider))}</span>${left}</span>`;
                });
                counters.innerHTML = html;
            }

            // Previews.
            if (!accts.length) {
                previews.innerHTML = '<p class="muted small">Select accounts to see how the post will look.</p>';
                return;
            }
            const videos = media.filter((m) => m.mime.startsWith('video/')).length;
            previews.innerHTML = accts.map((a) => {
                const p = cfg.providers[a.provider] || { name: a.provider, limit: 99999, rules: { max_media: 4 } };
                const text = overrideFor(a.id) || main;
                const n = countFor(text, a.provider);
                const shown = media.slice(0, p.rules.max_media);
                const warns = [];
                if (n > p.limit) warns.push(`${n - p.limit} characters over the ${p.limit} limit`);
                if (p.rules.requires_video && !videos) warns.push(p.name + ' needs a video');
                else if (p.rules.requires_media && !media.length) warns.push(p.name + ' needs an image or video');
                if (media.length > p.rules.max_media) warns.push(`Only the first ${p.rules.max_media} media file${p.rules.max_media > 1 ? 's' : ''} allowed`);
                if (!a.authorized) warns.push('This account needs reconnecting');
                const av = a.avatar ? `<img src="${esc(a.avatar)}" alt="" referrerpolicy="no-referrer" onerror="this.remove()">` : '';
                return `<article class="preview">
                    <div class="preview-top">
                        <span class="acct-avatar"><span class="avatar sm">${esc(initials(a.name))}${av}</span><span class="pbadge" style="--pc:${p.color}">${esc(abbr(a.provider))}</span></span>
                        <div><div class="preview-name">${esc(a.name)}</div><div class="preview-handle">${a.username ? '@' + esc(a.username) + ' · ' : ''}${esc(p.name)}</div></div>
                    </div>
                    <div class="preview-text">${text.trim() ? linkify(text) : '<span class="muted">No text</span>'}</div>
                    ${shown.length ? `<div class="preview-media n${Math.min(shown.length, 4)}">${shown.slice(0, 4).map((m) => m.mime.startsWith('video/') ? `<video src="${esc(m.url)}#t=0.1" muted preload="metadata"></video>` : `<img src="${esc(m.url)}" alt="">`).join('')}</div>` : ''}
                    <div class="preview-actions">${icon('reply')}${icon('repost')}${icon('heart')}</div>
                    ${warns.map((w) => `<div class="preview-warn">${icon('alert')}<span>${esc(w)}</span></div>`).join('')}
                </article>`;
            }).join('');
        }

        function initials(name) {
            return String(name || '?').trim().split(/\s+/).slice(0, 2).map((w) => w[0]).join('').toUpperCase() || '?';
        }
        function abbr(key) {
            return { x: 'X', mastodon: 'M', bluesky: 'B', facebook: 'f', instagram: 'IG', linkedin: 'in', youtube: 'YT', threads: 'Th', tiktok: 'TT', pinterest: 'P', google_business: 'G', sandbox: 'S' }[key] || key[0].toUpperCase();
        }

        form.addEventListener('input', update);
        form.addEventListener('change', update);

        $('[data-select-all-accounts]', form)?.addEventListener('click', () => {
            const boxes = $$('input[name="accounts[]"]', form);
            const all = boxes.every((b) => b.checked);
            boxes.forEach((b) => { b.checked = !all; });
            update();
        });

        // Insert text at the cursor (hashtag groups, variables).
        function insertAtCursor(text) {
            const s = content.selectionStart ?? content.value.length, e = content.selectionEnd ?? s;
            content.value = content.value.slice(0, s) + text + content.value.slice(e);
            content.focus();
            content.selectionStart = content.selectionEnd = s + text.length;
            update();
        }
        $$('[data-insert]', form).forEach((b) => b.addEventListener('click', () => { insertAtCursor(b.dataset.insert); b.closest('details').open = false; }));
        $$('[data-template]', form).forEach((b) => b.addEventListener('click', () => {
            if (content.value.trim() && !confirm('Replace the current text with this template?')) return;
            content.value = b.dataset.template;
            b.closest('details').open = false;
            update();
        }));

        $('[data-save-template]', form)?.addEventListener('click', async () => {
            if (!content.value.trim()) return toast('Write something first.', 'error');
            const name = prompt('Template name');
            if (!name) return;
            try {
                await api('/library/templates', { method: 'POST', body: { name, content: content.value, media: media.map((m) => m.id), _token: csrf() } });
                toast('Template saved');
            } catch (err) { toast(err.message, 'error'); }
        });

        $('[data-new-tag]', form)?.addEventListener('click', async (e) => {
            const name = prompt('New tag name');
            if (!name) return;
            const colors = ['#4f46e5', '#0891b2', '#059669', '#ca8a04', '#ea580c', '#dc2626', '#db2777', '#7c3aed'];
            try {
                const { tag } = await api('/tags', { method: 'POST', body: { name, color: colors[Math.floor(Math.random() * colors.length)], _token: csrf() } });
                if (form.querySelector(`input[name="tags[]"][value="${tag.id}"]`)) { form.querySelector(`input[name="tags[]"][value="${tag.id}"]`).checked = true; return; }
                const label = document.createElement('label');
                label.className = 'tag-chip';
                label.style.setProperty('--tc', tag.color);
                label.innerHTML = `<input type="checkbox" name="tags[]" value="${tag.id}" checked><span>${esc(tag.name)}</span>`;
                e.target.closest('[data-new-tag]').before(label);
            } catch (err) { toast(err.message, 'error'); }
        });

        // Media picker dialog.
        const dlg = $('#media-dialog');
        let picked = [];
        let library = [];
        async function loadLibrary() {
            const grid = $('#media-library');
            try {
                const data = await api('/media');
                library = data.media;
                renderLibrary();
            } catch (err) { grid.innerHTML = `<p class="error-text">${esc(err.message)}</p>`; }
        }
        function renderLibrary() {
            const grid = $('#media-library');
            if (!library.length) { grid.innerHTML = '<p class="muted">No media yet. Upload some above.</p>'; return; }
            grid.innerHTML = library.map((m) => {
                const idx = picked.indexOf(m.id);
                return `<button type="button" class="media-pick${idx >= 0 ? ' selected' : ''}" data-id="${m.id}" title="${esc(m.name)}">
                    ${m.is_video ? `<video src="${esc(m.url)}#t=0.1" muted preload="metadata"></video><span class="play">${icon('play')}</span>` : `<img src="${esc(m.url)}" alt="" loading="lazy">`}
                    <span class="num">${idx >= 0 ? idx + 1 : ''}</span></button>`;
            }).join('');
        }
        $('#media-library')?.addEventListener('click', (e) => {
            const b = e.target.closest('.media-pick');
            if (!b) return;
            const id = Number(b.dataset.id);
            const i = picked.indexOf(id);
            i >= 0 ? picked.splice(i, 1) : picked.push(id);
            renderLibrary();
        });
        $('#media-insert')?.addEventListener('click', () => {
            picked.forEach((id) => {
                const m = library.find((x) => x.id === id);
                if (m && !media.some((x) => x.id === id)) media.push({ id: m.id, url: m.url, mime: m.mime, name: m.name });
            });
            dlg.close();
            renderMedia();
        });
        $$('[data-open-media]', form).forEach((b) => b.addEventListener('click', () => {
            picked = [];
            dlg.showModal();
            loadLibrary();
        }));
        const dz = $('#dropzone');
        if (dz) {
            wireDropzone(dz, $('#media-file'), async (files) => {
                const prog = $('#upload-progress');
                for (const f of files) {
                    const p = progressRow(prog, f.name);
                    try {
                        const m = await upload(f, p.set);
                        library.unshift(m);
                        picked.push(m.id);
                    } catch (err) { toast(f.name + ': ' + err.message, 'error'); }
                    p.done();
                }
                renderLibrary();
            });
        }
        // Paste images straight into the editor.
        content?.addEventListener('paste', async (e) => {
            const files = Array.from(e.clipboardData?.files || []).filter((f) => f.type.startsWith('image/'));
            if (!files.length) return;
            e.preventDefault();
            for (const f of files) {
                try { const m = await upload(f); media.push({ id: m.id, url: m.url, mime: m.mime, name: m.name }); renderMedia(); toast('Image added'); }
                catch (err) { toast(err.message, 'error'); }
            }
        });

        // Warn before leaving with unsaved changes.
        let dirty = false;
        form.addEventListener('input', () => { dirty = true; });
        form.addEventListener('submit', () => { dirty = false; });
        window.addEventListener('beforeunload', (e) => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });


        // Best-time chips: jump the date/time inputs to the next occurrence of that weekday + hour.
        $$('[data-best-day]', form).forEach((chip) => chip.addEventListener('click', () => {
            const day = Number(chip.dataset.bestDay), hour = Number(chip.dataset.bestHour);
            const d = new Date();
            d.setMinutes(0, 0, 0);
            let add = (day - d.getDay() + 7) % 7;
            if (add === 0 && d.getHours() >= hour) add = 7;
            d.setDate(d.getDate() + add);
            const pad = (n) => String(n).padStart(2, '0');
            form.querySelector('[name="date"]').value = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())}`;
            form.querySelector('[name="time"]').value = `${pad(hour)}:00`;
            toast('Set to ' + chip.textContent.trim());
        }));

        // AI writing.
        const aiDlg = $('#ai-dialog');
        if (aiDlg) {
            const results = $('#ai-results');
            const setText = (t, mode) => {
                if (mode === 'insert') {
                    content.value = content.value.trim() ? content.value.replace(/\s*$/, '') + (t.startsWith('#') ? ' ' : '\n\n') + t : t;
                } else {
                    content.value = t;
                }
                content.dispatchEvent(new Event('input', { bubbles: true }));
                aiDlg.close();
            };
            $$('[data-ai-action]', aiDlg).forEach((b) => b.addEventListener('click', async () => {
                const action = b.dataset.aiAction;
                $$('[data-ai-action]', aiDlg).forEach((x) => { x.disabled = true; });
                results.innerHTML = '<p class="muted">Writing…</p>';
                try {
                    const r = await api('/ai/assist', { method: 'POST', body: {
                        action, text: content.value, idea: $('#ai-idea').value,
                        accounts: $$('input[name="accounts[]"]:checked', form).map((i) => Number(i.value)), _token: csrf(),
                    } });
                    results.innerHTML = r.variants.map((v, i) => `<div class="ai-result"><div class="ai-text">${esc(v)}</div>
                        <div class="ai-result-actions"><span class="muted small">${Array.from(v).length} chars</span><span class="grow"></span>
                        <button type="button" class="btn btn-sm" data-ai-insert="${i}">Add to post</button>
                        <button type="button" class="btn btn-sm btn-primary" data-ai-use="${i}">${action === 'hashtags' ? 'Add hashtags' : 'Use this'}</button></div></div>`).join('');
                    $$('[data-ai-use]', results).forEach((u) => u.addEventListener('click', () => setText(r.variants[u.dataset.aiUse], action === 'hashtags' ? 'insert' : 'replace')));
                    $$('[data-ai-insert]', results).forEach((u) => u.addEventListener('click', () => setText(r.variants[u.dataset.aiInsert], 'insert')));
                } catch (err) {
                    results.innerHTML = `<p class="error-text">${esc(err.message)}</p>`;
                }
                $$('[data-ai-action]', aiDlg).forEach((x) => { x.disabled = false; });
            }));
        }

        // Canva / Google Drive inside the media dialog.
        const cloudBtns = $$('[data-cloud-open]');
        if (cloudBtns.length) {
            const panel = $('#canva-panel');
            let browser = null;
            cloudBtns.forEach((btn) => btn.addEventListener('click', () => {
                panel.hidden = false;
                $('#media-library').hidden = true;
                btn.parentElement.hidden = true;
                browser?.destroy();
                browser = canvaBrowser({
                    source: btn.dataset.cloudOpen,
                    grid: $('#canva-grid'), q: $('#canva-q'), more: $('#canva-more'),
                    back: location.pathname,
                    onImport: (items) => {
                        items.forEach((m) => { library.unshift(m); picked.push(m.id); });
                        $('[data-canva-close]').click();
                        renderLibrary();
                        toast(items.length > 1 ? `Imported ${items.length} files` : 'Imported');
                    },
                });
            }));
            $('[data-canva-close]').addEventListener('click', () => {
                panel.hidden = true;
                $('#media-library').hidden = false;
                cloudBtns[0].parentElement.hidden = false;
            });
        }
        renderMedia();
        if (!cfg.readonly && scope) localDraft();

        /** Autosaves the composer on this device, offers to restore it, and holds posts made offline. */
        function localDraft() {
            const path = pathOf(form.getAttribute("action") || location.href);
            const key = draftKey(path);
            const snapshot = () => ({
                entries: Array.from(new FormData(form).entries()).filter(([k, v]) => k !== '_token' && typeof v === 'string'),
                media: media.map((m) => ({ id: m.id, url: m.url, mime: m.mime, name: m.name })),
            });
            const same = (a, b) => JSON.stringify(a.entries) === JSON.stringify(b.entries) && JSON.stringify(a.media.map((m) => m.id)) === JSON.stringify(b.media.map((m) => m.id));
            const initial = snapshot();
            const saved = store.get(key, null);

            function restore(d) {
                const names = new Set(d.entries.map(([k]) => k));
                $$('input, textarea, select', form).forEach((el) => {
                    if (!el.name || el.name === '_token' || el.type === 'submit' || el.type === 'file') return;
                    if (el.type === 'checkbox' || el.type === 'radio') {
                        el.checked = d.entries.some(([k, v]) => k === el.name && v === el.value);
                    } else if (names.has(el.name) && el.name !== 'media[]') {
                        el.value = d.entries.find(([k]) => k === el.name)[1];
                    }
                });
                media = d.media.slice();
                renderMedia();
            }

            if (saved && !same(saved, initial)) {
                const note = document.createElement('div');
                note.className = 'notice notice-info draft-restore';
                note.innerHTML = `${icon('clock')}<span>You have unsaved changes from ${esc(ago(saved.at))} on this device.</span>
                    <button type="button" class="btn btn-sm btn-primary" data-restore>Restore</button>
                    <button type="button" class="btn btn-sm btn-ghost" data-discard>Discard</button>`;
                form.prepend(note);
                note.querySelector('[data-restore]').addEventListener('click', () => { restore(saved); note.remove(); toast('Restored'); });
                note.querySelector('[data-discard]').addEventListener('click', () => { store.del(key); note.remove(); });
            } else if (saved) {
                store.del(key);
            }

            let timer = null;
            const save = () => {
                clearTimeout(timer);
                timer = setTimeout(() => {
                    const now = snapshot();
                    if (same(now, initial)) store.del(key);
                    else store.set(key, Object.assign(now, { at: Date.now() }));
                }, 400);
            };
            form.addEventListener('input', save);
            form.addEventListener('change', save);
            new MutationObserver(save).observe(strip, { childList: true });

            form.addEventListener('submit', (e) => {
                if (e.defaultPrevented) return;
                clearTimeout(timer);
                const action = e.submitter && e.submitter.name === 'action' ? e.submitter.value : 'draft';
                if (navigator.onLine) {
                    store.del(key); // the server keeps your input if it rejects the post
                    return;
                }
                e.preventDefault();
                if (!['draft', 'schedule', 'now'].includes(action)) return toast('You’re offline. Try again when you’re back online.', 'error');
                if (form.dataset.queued) return toast('This post is already saved on this device and will send when you’re back online.');
                const snap = snapshot();
                const entries = snap.entries.filter(([k]) => k !== 'action').concat([['action', action]]);
                const text = (content.value || '').trim().replace(/\s+/g, ' ');
                const items = outbox();
                items.push({
                    id: Date.now().toString(36) + Math.random().toString(36).slice(2, 7),
                    url: path, from: pathOf(location.href), entries, media: snap.media, at: Date.now(),
                    title: text ? (text.length > 50 ? text.slice(0, 50) + '…' : text) : '(media only)',
                });
                if (!store.set(outboxKey, items)) return toast('This device’s storage is full, so the post couldn’t be saved offline.', 'error');
                store.del(key);
                dirty = false;
                form.dataset.queued = '1';
                renderBar();
                toast(action === 'now' ? 'Saved on this device. It will publish when you’re back online.' : 'Saved on this device. It will be sent when you’re back online.');
            });
        }
    }
})();
MC_ASSET_2],
        'favicon.svg' => [false, <<<'MC_ASSET_3'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="6" fill="#4f46e5"/><path d="M7 16V8l5 5 5-5v8" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></svg>
MC_ASSET_3],
        'icon-192-maskable.png' => [true, 'iVBORw0KGgoAAAANSUhEUgAAAMAAAADACAYAAABS3GwHAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAEDElEQVR4nO3bTVIcRwCE0cThU/hAaImO6q0PhK6BN1IEQQg0M/1TXZXvHQCquvOb2cDTy/PrW6DUX6MPACMJgGoCoJoAqCYAqgmAagKgmgCoJgCqCYBqAqCaAKgmAKoJgGoCoJoAqCYAqgmAagKgmgCoJgCqCYBqAqCaAKgmAKoJgGoCoJoAqCYAqgmAagKgmgCoJgCqCYBqAqCaAKg2TQD//vfP6COwoGkCSETA/qYKIBEB+5ougEQE7GfKABIRsI9pA0hEwHZTB5CIgG2mDyARAY9bIoBEBDxmmQASEXC/pQJIRMB9lgsgEQG3e3p5fn0bfYjkmNF+//Zj95/Z6sgPlZHvaclvgF98E+xj1fEniweQiGCrlcefFASQiOBRq48/KQkgEcG9Wp5XTQBJz0vdquk5VQWQdL3cR7Q9n7oAkr6XfKvG51IZQNL5sr/S+jxqA0h6X/pHzc+hOoCk++Un7l8fQNI7gtZ7vyeAn9rG0HbfzwjgnZZRtNzzFgL4YPVxrH6/ewngN1Ydyar32kIAn1htLKvdZy8C+MIqo1nlHkcQwB/MPp7Zz380Adxg1hHNeu4zCeBGs41ptvOOslQAR/+X0SyjavhPrr0sFUAiAuO/z3IBJL0RGP/9lgwg6YvA+B+zbABJTwTG/7ilA0jWj8D4t1k+gGTdCIx/u4oAknUjYJuaAJK1IvDpv4+qAJI1IjD+/dQFkMwdgfHvqzKAZM4IjH9/tQEkc0Vg/MeoDiCZIwLjP059AMm1IzD+YwngpytGYPzHE8A7V4rA+M8hgA+uEIHxn0cAvzEyAuM/lwA+MSIC4z+fAL5wZgTGP4YA/uCMCIx/HAHcYNYRzXruMwngRrONabbzjiKAO8wyqlnOeQUCuNPVx3X1812NAB5w1ZFd9VxXJoAHXW1sVzvPLASwwVVGd5VzzEgAG40e3+jfPzsB7GDUCI1/OwHs5OwxGv8+BLCjs0Zp/PsRwM6OHqfx7+vv0QdY0fdvP+76AzejHsc3wEGMeg4COJAIrk8ABxPBtQngBCK4LgGcRATXJIATieB6BHAyEVyLAAYQwXUIYBARXIMABhLBeAIYTARjCYBqAqCaAKgmAKoJgGoCoJoAqCYAqgmAagKgmgCoJgCqCYBqAqCaAKgmAKoJgGoCoJoAqCYAqgmAagKgmgCoJgCqCYBqAqCaAKj29PL8+jb6EDCKbwCqCYBqAqCaAKgmAKoJgGoCoJoAqCYAqgmAagKgmgCoJgCqCYBqAqCaAKgmAKoJgGoCoJoAqCYAqgmAagKgmgCoJgCqCYBqAqCaAKgmAKoJgGoCoJoAqCYAqgmAagKgmgCoJgCqCYBqAqCaAKgmAKoJgGoCoJoAqCYAqgmAagKgmgCo9j961huhX07iJAAAAABJRU5ErkJggg=='],
        'icon-192.png' => [true, 'iVBORw0KGgoAAAANSUhEUgAAAMAAAADACAYAAABS3GwHAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAFPklEQVR4nO3bTXLUSBRF4SuCVeD9wBCWiod4P3gbMEGEKEpVSil/Xr57vlF3D+wsfI9SRLQXBfT1889fo8+ANl7fXpbRZ9gafhjGjpFRDPnGjB57esfQ7ZsxepTqEUPzb8DwcVXLEJp9YYaP2lqE0CQAxo9WakdQ9YsxfPRSK4RqATB+9FYjgstfgOFjtCshXAqA8SOKsxGcDoDxI5ozEZwKgPEjqtIIigNg/IiuJIKiABg/ZnE0gsMBMH7M5kgEhwJg/JjVswg+9DoIENHTG4CnP2b36BZ4GADjRxZ7EfAKBGu7NwBPf2Rz7xa4GwDjR1a3EfAKBGv/3QA8/ZHd9hbgBoC1f24Anv5wsd4C3ACwRgCw9vcViNcfuHl9e1m4AWCNAGBtkXj9gS9uAFgjAFgjAFgjAFhb+AswnHEDwBoBwBoBwNq0AXz/8Wn0EZDAtAFIRIDrpg5AIgJcM30AEhHgvBQBSESAc9IEIBEByqUKQCIClEkXgEQEOC5lABIR4Ji0AUhEgOdSByARAR5LH4BEBNgX9vcBWoz225f36l8zq5YPjUg/B4sbYMVNcIzL+CWzACQieMZp/JJhABIR7HEbv2QagEQEt1z/PGwDkHx/6Lec/xysA5C8f/gSn98+AMl3BK6fe4sA/nAbg9vn3UMAGy6jcPmcRxDAjezjyP75ShHAHVlHkvVzXUEAO7KNJdvnqYUAHsgymiyfowUCeGL28cx+/tYI4IBZRzTruXsigINmG9Ns5x2FAArMMqpZzhkBARSKPq7o54uGAE6IOrKo54qMAE6KNrZo55lF6gBa/xZSlNE5/iZXLakDkPJHwPivSR+AlDcCxn+dRQBSvggYfx02AUh5ImD89VgFIOWJAHXYBSDNHQFP/7osA5DmjIDx12cbgDRXBIy/DesApDkiYPzt2AcgxY6A8bdFAH9EjIDxt0cAG5EiYPx9EMCNCBEw/n4I4I6RETD+vghgx4gIGH9/BPBAzwgY/xgE8ESPCBj/OARwwKwjmvXcPRHAQbONabbzjkIABWYZ1SznjIAACkUfV/TzRUMAJ0QdWdRzRUYAJ0UbW7TzzIIALogyuijnmBEBXDR6fKO//+wIoIJRI2T81xFAJb3HyPjrIICKeo2S8ddDAJW1Hifjr+vj6ANk9O3Le9H/4Maox+EGaIRRz4EAGiKC+AigMSKIjQA6IIK4CKATIoiJADoigngIoDMiiIUABiCCOAhgECKIgQAGIoLxCGAwIhiLAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGCNAGBt+fr556/RhwBG4QaANQKANQKANQKANQKANQKANQKANQKANQKANQKANQKANQKANQKANQKANQKANQKAtUWS+KUYuOIGgDUCgDUCgDUCgLVl/Qf+Igw3r28vCzcArBEArC3bf+E1CC5e314WiRsA5ggA1pbb/8BrELJbX38kbgCY++8GkLgFkNf26S9xA8Dc3RtA4hZAPrdPf+lBABIRII9745d4BYK5hzeAxC2A+e09/aUDAUhEgHk9Gr/EKxDMHboBJG4BzOfZ018qCEAiAszjyPilwgAkIkB8R8cvnQhAIgLEVTJ+6WQAEhEgntLxSxcCkIgAcZwZv3QxAIkIMN7Z8UsVAlgRAnq7MvxVtQAkIkA/NcYvVQ5gRQhopdbwV00CkIgA9dUev9QwgBUh4KoWw181D2BFCCjVcvirbgFsEQP29Bj91pAAtogBvUe/9RtOj8RipvUjTwAAAABJRU5ErkJggg=='],
        'icon-512-maskable.png' => [true, 'iVBORw0KGgoAAAANSUhEUgAAAgAAAAIACAYAAAD0eNT6AAAACXBIWXMAAA7EAAAOxAGVKw4bAAAOOUlEQVR4nO3Yy3UUyRZAUfHMaINgCKb2tA0CN3gDmkYClVSf/ETE2dsBZWVG3HuWPnz++PX7EwCQ8r+zHwAAOJ4AAIAgAQAAQQIAAIIEAAAECQAACBIAABAkAAAgSAAAQJAAAIAgAQAAQQIAAIIEAAAECQAACBIAABAkAAAgSAAAQJAAAIAgAQAAQQIAAIIEAAAECQAACBIAABAkAAAgSAAAQJAAAIAgAQAAQQIAAIIEAAAECQAACBIAABAkAAAgSAAAQJAAAIAgAQAAQQIAAIIEAAAECQAACBIAABAkAAAgSAAAQJAAAIAgAQAAQQIAAIIEAAAECQAACBIAABAkAAAgSAAAQJAAAIAgAQAAQQIAAIIEAAAECQAACBIAABAkAAAgSAAAQJAAAIAgAQAAQQIAAIIEAAAECQAACBIAABAkAAAgSAAAQJAAAIAgAQAAQQIAAIIEAAAECQAACBIAABAkAAAgSAAAQJAAAIAgAQAAQQIAAIIEAAAECQAACBIAABAkAAAgSAAAQJAAAIAgAQAAQQIAAIIEAAAECQAACBIAABAkAAAgSAAAQJAAAIAgAQAAQQIAAIIEAAAECQAACBIAABAkAAAgSAAAQJAAAIAgAQAAQQIAAIIEwOT+/uevsx8BCDJ75icAFuAiAkcyc9YgABbhQgJHMGvWIQAW4mICezJj1iIAFuOCAnswW9YjABbkogJbMlPWJAAW5cICWzBL1iUAFubiAo8wQ9YmABbnAgP3MDvWJwACXGTgFmZGgwCIcKGBa5gVHQIgxMUG3mJGtAiAGBcceI3Z0CMAglx04DkzoUkARLnwwNOTWVAmAMJcfGgzA9oEQJwBAE3uPgIAgwBi3HmengQA/zIQoMFd5ycBwH8MBlibO85zAoAXDAhYk7vN7wQAfzAoYC3uNK8RALzKwIA1uMtcIgC4yOCAubnDvEUA8CYDBObk7vIeAcC7DBKYizvLNQQAVzFQYA7uKtcSAFzNYIGxuaPcQgBwEwMGxuRucisBwM0MGhiLO8k9BAB3MXBgDO4i9xIA3M3ggXO5gzziw+ePX7+f/RCrW/2Sfvn07exHgJyV54qZcgz/AeBhKw8iGJE7xxYEAJswkOAY7hpbEQBsxmCCfbljbEkAsCkDCvbhbrE1AcDmDCrYljvFHgQAuzCwYBvuEnsRAOzG4ILHuEPsSQCwKwMM7uPusDcBwO4MMriNO8MRBACHMNDgOu4KRxEAHMZgg7e5IxxJAHAoAw5e525wNAHA4Qw6eMmd4AwCgFMYePCDu8BZBACnMfiocwc4kwDgVAYgVc4+ZxMAnM4gpMaZZwQCgCEYiFQ464xCADAMg5HVOeOMRAAwFAOSVTnbjEYAMByDktU404xIADAkA5NVOMuMSgAwLIOT2TnDjEwAMDQDlFk5u4xOADA8g5TZOLPMQAAwBQOVWTirzEIAMA2DldE5o8xEADAVA5ZROZvMRgAwHYOW0TiTzEgAMCUDl1E4i8xKADAtg5ezOYPMTAAwNQOYszh7zE4AMD2DmKM5c6xAALAEA5mjOGusQgCwDIOZvTljrEQAsBQDmr04W6xGALAcg5qtOVOsSACwJAObrThLrEoAsCyDm0c5Q6xMALC0v//5yxDnLs4NqxMAJBjm3MJ5oUAAkGGocw3nhAoBQIrhzlucD0oEADmGPK9xLqgRACQZ9jznPFAkAMgy9Hl6cg7oEgCkGf5tvj9lAoA8S6DJd6dOAMCTZVDje4MAgP9YCg2+M/wgAOAZy2Ftvi/8IgDgN5bEmnxXeEkAwCssi7X4nvAnAQAXWBpr8B3hdQKAi758+nb2I5zO8pib7weXCQDeJAIskVn5bvA2AcC7RIBlMhvfy73lfQKAqxgmlsosfCf3lesIAK5mqFguo/N93FOuJwC4ieFiyYzKd3E/uY0A4GaGjGUzGt/DveR2AoC7GDaWzih8B/eR+wgA7mboWD5n8/7dQ+4nAHiI4WMJncV7d/94jADgYYaQZXQ079u943ECgE0YRpbSUbxn941tCAA2YyhZTnvzft0ztiMA2JThZEntxXt1v9iWAGBzhpRltTXv071iewKAXRhWltZWvEf3iX0IAHZjaFlej/L+3CP2IwDYleFlid3Le3N/2JcAYHeGmGV2K+/LvWF/AoBDGGaW2rW8J/eFYwgADmOoWW7v8X7cE44jADiU4WbJXeK9uB8cSwBwOEPOsvud9+FecDwBwCkMO0vvJ+/BfeAcAoDTGHqWX/33Pz25B5xHAHAqw6+7BKu/+znnnzMJAE5nCPaWYe33vsa552wCgCEYhp2lWPmdb3HeGYEAYBiG4vrLcfXfdw3nnFEIAIZiOK67JFf9XbdwvhmJAGA4huR6y3K133MP55rRCACGZFiuszRX+R2PcJ4ZkQBgWIbm/Mtz9uffgnPMqAQAQzM8512isz73lpxfRiYAGJ4hOt8yne159+DcMjoBwBQM03mW6izPuSfnlRkIAKZhqI6/XEd/viM4p8xCADAVw3XcJTvqcx3J+WQmAoDpGLLjLdvRnucMziWzEQBMybAdZ+mO8hxnch6ZkQBgWobu+cv37L8/AueQWQkApmb4nreELX/nj7kJAKZnCB+/jC1/5475CQCWYBgft5Qtf+eNNQgAlmEo77+cLX/njHUIAJZiOO+3pC1/54u1CACWY0hvv6wtf+eK9QgAlmRYb7e0LX/niTUJAJZlaD++vC1/54h1CQCW9uXTt/wAv3eJW/6WP2sTACTUB/mty9zyd2ZYnwAgoz7Qr13qlr+zQoMAIKU+2N9b7pa/M0KHACCnPuAvLXnL39mgRQCQVB/0vy97y9+ZoEcAkFUf+D+XvuXvLNAkAEirD37L3xmgSwCQZwF0+faUCQB4sgiKfHPqBAD8y0Lo8K1BAMALFsP6fGP4QQDAbyyIdfm28IsAgFdYFOvxTeElAQAXWBjr8C3hTwIA3mBxzM83hNcJAHiHBTIv3w4uEwBwBYtkPr4ZvE0AwJUslHn4VvA+AQA3sFjG5xvBdQQA3MiCGZdvA9cTAHAHi2Y8vgncRgDAnSyccfgWcDsBAA+weM7nG8B9BAA8yAI6j3cP9xMAsAGL6HjeOTxGAMBGLKTjeNfwOAEAG7KY9ucdwzYEAGzMgtqPdwvbEQCwA4tqe94pbEsAwE4srO14l7A9AQA7srge5x3CPgQA7MwCu593B/sRAHAAi+x23hnsSwDAQSy063lXsD8BAAey2N7nHcExBAAczIK7zLuB4wgAOIFF9yfvBI4lAOAkFt4v3gUcTwDAiSw+7wDOIgDgZOUFWP7tcDYBAAMoLsLib4aRCAAYRGkhln4rjEoAwEAKi7HwG2EGAgAGs/KCXPm3wWwEAAxoxUW54m+CmQkAGNRKC3Ol3wKrEAAwsBUW5wq/AVYkAGBwMy/QmZ8dVicAYAIzLtIZnxlKBABMYqaFOtOzQpUAgInMsFhneEZAAMB0Rl6wIz8b8JIAgAmNuGhHfCbgMgEAkxpp4Y70LMB1BABMbITFO8IzALcTADC5Mxew5Q/zEgCwgDMWseUPcxMAsIgjF7LlD/MTALCQIxaz5Q9rEACwmD0XtOUP6xAAsKA9FrXlD2sRALCoLRe25Q/rEQCwsC0Wt+UPaxIAsLhHFrjlD+sSABBwzyK3/GFtAgAiblnolj+sTwBAyDWL3fKHBgEAMW8teMsfOgQABL226C1/aBEAEPV84Vv+0CMAIM7yhyYBAGGWP3QJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgKAPnz9+/X72QwAAx/IfAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABAkAAAgCABAABBAgAAggQAAAQJAAAIEgAAECQAACBIAABA0P8BuBkondbV0YMAAAAASUVORK5CYII='],
        'icon-512.png' => [true, 'iVBORw0KGgoAAAANSUhEUgAAAgAAAAIACAYAAAD0eNT6AAAACXBIWXMAAA7EAAAOxAGVKw4bAAARy0lEQVR4nO3bS2LcxhUF0Mcsw9qPPJSXag2t/djbUAZyWyTFJgF0AfW554ySTAIUCu9eVFNPxZC+fP77e+9rAGjl67dPT72vgZc8kI6EPIBy0ItFv4CgB9hPMTiXxT2BwAdoTyFoy2I2IvQBrqMMPM4CHiTwAcahEOxnwXYQ+gDjUwa2sUgbCH6A+SgC77M4dwh9gHUoA7+yIK8IfoB1KQI/WYh/CX6AHIqAAiD4AYIlF4HYGxf8ANwkFoG4Gxb8ANyTVARiblTwA7BVQhFY/gYFPwBHrVwElr0xwQ9AKysWgeVuqEr4A9DeaiVgqZsR/ACcbZUisMRNVAl/AK6zQgmY/gYEPwC9zFwEpr3wKuEPQH+zloApL7pK+AMwjhlLwHQXLPgBGNVMRWCaC60S/gCMb5YSMMVFVgl/AOYxQwkY/gKrhD8A8xm9BAx9cYIfgNmNWgSGvKgq4Q/AOkYsAcNdUJXwB2A9o5WAoS6mSvgDsK6RSsAwF1Il/AFY3yglYIiLqBL+AOQYoQR0v4Aq4Q9Ant4loHsBEP4ApOpZAroWAOEPQLpeJaBbARD+APBDjxLQpQAIfwB46eoScHkBEP4A8LYrS8ClBUD4A8D7rioB/7vi/wQAGMtlJwC+/gFgmytOAS4pAMIfAPY5uwScXgCEPwAcc2YJOLUACH8AeMxZJcAfAQJAoNNOAHz9A0AbZ5wCnFIAhD8AtNW6BDQvAMIfAM7RsgT4GwAACNT0BMDXPwCcq9UpQLMCIPwB4BotSoCfAAAgUJMTAF//AHCtR08BHi4Awh8A+nikBPgJAAACPXQC4OsfAPo6egrgBAAAAh0+AfD1DwBjOHIK4AQAAAIdOgHw9Q8AY9l7CuAEAAAC7T4B8PUPAGPacwrgBAAAAu06AfD1DwBj23oK4AQAAAJtPgHw9Q8Ac9hyCuAEAAACbToB8PUPAHP56BTACQAABPrwBMDXPwDM6b1TACcAABBIAQCAQO/+BOD4HwDmdu9nACcAABDo7gmAr38AWMNbpwBOAAAgkAIAAIHe/AnA8T8ArOX1zwBOAAAgkAIAAIEUAAAI9MvfAPj9HwDW9PzvAJwAAEAgBQAAAr34CcDxPwCs7fYzgBMAAAikAABAIAUAAAL99zcAfv8HgAxfv316cgIAAIEUAAAIpAAAQCAFAAACKQAAEOipyr8AAIA0TgAAIJACAACBFAAACKQAAEAgBQAAAikAABDoyT8BBIA8TgAAIJACAACBFAAACKQAAEAgBQAAAikAABBIAQCAQAoAAARSAEL8+ddvvS8BmIBZkUMBCOLFBt5jRmRRAMJ4wYG3mA15FIBAXnTgOTMhkwIQygsPVJkFyRSAYF58yGYGZFMAwhkAkMm7jwKAQQBhvPNUKQD8y0CADN51bhQA/mMwwNq84zynAPCCAQFr8m7zmgLALwwKWIt3mrcoALzJwIA1eJe5RwHgLoMD5uYd5j0KAO8yQGBO3l0+ogDwIYME5uKdZQsFgE0MFJiDd5WtFAA2M1hgbN5R9lAA2MWAgTF5N9lLAWA3gwbG4p3kCAWAQwwcGIN3kaMUAA4zeKAv7yCPUAB4iAEEfXj3eJQCwMMMIriWd44WFACaMJDgGt41WlEAaMZggnN5x2hJAaApAwrO4d2iNQWA5gwqaMs7xRkUAE5hYEEb3iXOogBwGoMLHuMd4kwKAKcywOAY7w5nUwA4nUEG+3hnuMLTl89/f+99Efyw+kv/x+//9L4EGN7Kc8AMGIsTAC6z8mCDFrwjXEkB4FIGHLzNu8HVFAAuZ9DBS94JelAA6MLAgx+8C/SiANCNwUc67wA9KQB0ZQCSyt6nNwWA7gxC0tjzjEABYAgGIinsdUahADAMg5HV2eOMRAFgKAYkq7K3GY0CwHAMSlZjTzMiBYAhGZiswl5mVAoAwzI4mZ09zMgUAIZmgDIre5fRKQAMzyBlNvYsM1AAmIKByizsVWahADANg5XR2aPMRAFgKgYso7I3mY0CwHQMWkZjTzIjBYApGbiMwl5kVgoA0zJ46c0eZGYKAFMzgOnF3mN2CgDTM4i5mj3HChQAlmAgcxV7jVUoACzDYOZs9hgrUQBYigHNWewtVqMAsByDmtbsKVakALAkA5tW7CVWpQCwLIObR9lDrEwBYGkGOEfZO6xOAWB5Bjl72TMkUACIYKCzlb1CCgWAGAY7H7FHSKIAEMWA5x57gzQKAHH+/Os3w54X7AcSKQDEMvSpsg/IpQAQzfDP5vmTTAEgnhDI5LmTTgGAEgZpPG9QAOA/QiGD5ww/KADwjHBYm+cLPykA8IqQWJPnCi8pAPAGYbEWzxN+pQDAHUJjDZ4jvE0BgHcIj7l5fnCfAgAfECJz8tzgfQoAbCBM5uJ5wccUANhIqMzBc4JtFADYQbiMzfOB7RQA2EnIjMlzgX0UADhA2IzF84D9FAA4SOiMwXOAYxQAeIDw6cv6w3EKADxICPVh3eExCgAP++P3f3pfQnfC6FrWGx6nANCEEiCUrmKdoQ0FgGaUAOF0NuvrPaMdBYCmDCchdRbr6v2iLQWA5gwpYdWa9fRe0Z4CwCkMK6HVinX0PnEOBYDTGFrC61HWz3vEeRQATmV4CbGjrJv3h3MpAJzOEBNme1kv7w3nUwC4hGEm1LayTt4XrqEAcBlDTbh9xPp4T7iOAsClDDchd4918X5wLQWAyxlywu416+G94HoKAF0YdkLvxjp4H+hDAaAbQ0/4pd9/lfeAfhQAujL8ckMw9b6fs//pSQGgO0MwLwzT7vct9j29KQAMwTDMCcWU+3yP/c4IFACGYSiuH46r398W9jmjUAAYiuG4bkiuel972N+MRAFgOIbkemG52v0cYV8zGgWAIRmW64TmKvfxCPuZESkADMvQnD88Z7/+FuxjRqUAMDTDc94QnfW6W7J/GZkCwPAM0fnCdLbrPYN9y+gUAKZgmM4TqrNc55nsV2agADANQ3X8cB39+q5gnzILBYCpGK7jhuyo13Ul+5OZKABMx5AdL2xHu54e7EtmowAwJcN2nNAd5Tp6sh+ZkQLAtAzd/uHb+/9/BPYhs1IAmJrh2y+Ehb/9x9wUAKZnCF8fxsLfvmN+CgBLMIyvC2Xhb7+xBgWAZRjK54ez8LfPWIcCwFIM5/NCWvjbX6xFAWA5hnT7sBb+9hXrUQBYkmHdLrSFv/3EmhQAlmVoPx7ewt8+Yl0KAEszvI+HuPC3f1ibAsDyDPH9YS787RvWpwAQwTDfHurC334hgwJADEP943AX/vYJORQAohju90Ne+NsfZFEAiPPH7//ED/rXYS/8hT95FABipQ/8W+gLf3uBTAoA0dIHv/C3B8ilABBPAOTy7EmmAEAJgkSeOekUAPiXQMjhWYMCAC8IhvV5xvCDAgCvCIh1ebbwkwIAbxAU6/FM4SUFAO4QGOvwLOFXCgC8Q3DMzzOEtykA8AEBMi/PDu5TAGADQTIfzwzepwDARgJlHp4VfEwBgB0Ey/g8I9hGAYCdBMy4PBvYTgGAAwTNeDwT2EcBgIMEzjg8C9hPAYAHCJ7+PAM4RgGABwmgfqw9HKcAQAOC6HrWHB6jAEAjAuk61hoepwBAQ4LpfNYY2lAAoDEBdR5rC+0oAHACQdWeNYW2FAA4icBqx1pCewoAnEhwPc4awjkUADiZADvO2sF5FAC4gCDbz5rBuRQAuIhA285awfkUALiQYPuYNYJrKABwMQF3n7WB6ygA0IGg+5U1gWspANCJwPvJWsD1FADoSPBZA+hFAYDOkgMw+d6hNwUABpAYhIn3DCNRAGAQSYGYdK8wKgUABpIQjAn3CDNQAGAwKwfkyvcGs1EAYEArBuWK9wQzUwBgUCsF5kr3AqtQAGBgKwTnCvcAK1IAYHAzB+jM1w6rUwBgAjMG6YzXDEkUAJjETIE607VCKgUAJjJDsM5wjYACANMZOWBHvjbgJQUAJjRi0I54TcB9CgBMaqTAHelagG0UAJjYCME7wjUA+ykAMLmeASz8YV4KACygRxALf5ibAgCLuDKQhT/MTwGAhVwRzMIf1qAAwGLODGjhD+tQAGBBZwS18Ie1KACwqJaBLfxhPQoALKxFcAt/WJMCAIt7JMCFP6xLAYAAR4Jc+MPaFAAIsSfQhT+sTwGAIFuCXfhDBgUAwrwX8MIfcigAEOitoBf+kEUBgFDPA1/4Qx4FAMIJf8ikAEAw4Q+5FAAACKQAAEAgBQAAAikAABBIAQCAQAoAAARSAAAgkAIAAIEUAAAIpAAAQCAFAAACKQAAEEgBAIBACgAABFIAACCQAgAAgRQAAAikAABAIAUAAAIpAAAQSAEAgEAKAAAEUgAAIJACAACBFAAACKQAAEAgBQAAAikAABBIAQCAQAoAAARSAAAgkAIAAIEUAAAIpAAAQCAFAAACKQAAEEgBAIBACgAABFIAACCQAgAAgRQAAAikAABAIAUAAAIpAAAQSAEAgEAKAAAEUgAAIJACAACBFAAACKQAAEAgBQAAAikAABBIAQCAQAoAAARSAAAgkAIAAIEUAAAIpAAAQCAFAAACPX35/Pf33hcBAFzLCQAABFIAACCQAgAAgRQAAAikAABAIAUAAAIpAAAQSAEAgEAKAAAEUgAAIJACAACBFAAACKQAAEAgBQAAAikAABBIAQCAQAoAAARSAAAgkAIAAIEUAAAIpAAAQCAFAAACKQAAEEgBAIBACgAABFIAACCQAgAAgRQAAAikAABAIAUAAAIpAAAQSAEAgEAKAAAEUgAAIJACAACBFAAACKQAAEAgBQAAAikAABBIAQCAQAoAAAR6qqr68vnv770vBAC4jhMAAAikAABAIAUAAAIpAAAQSAEAgEAKAAAEerr9B/8UEAAyfP326ckJAAAEUgAAIJACAACBFAAACKQAAECgp+f/xb8EAIC1ff326anKCQAARFIAACCQAgAAgZ5e/w/+DgAA1nT7/b/KCQAARFIAACDQLz8BVPkZAABW8/z4v8oJAABEUgAAIJACAACB3vwbgCp/BwAAq3j9+3+VEwAAiKQAAECguz8BVPkZAABm99bxf5UTAACI9O4JQJVTAACY1b2v/yonAAAQSQEAgEAf/gRQ5WcAAJjNe8f/VU4AACDSphOAKqcAADCLj77+q5wAAECkzScAVU4BAGB0W77+q5wAAECkXScAVU4BAGBUW7/+q5wAAECk3ScAVU4BAGA0e77+q5wAAECkQycAVU4BAGAUe7/+q5wAAECkwycAVU4BAKC3I1//VU4AACDSQycAVU4BAKCXo1//VU4AACDSwycAVU4BAOBqj3z9VzUqAFVKAABc5dHwr/ITAABEanYCUOUUAADO1uLrv6pxAahSAgDgLK3Cv8pPAAAQqfkJQJVTAABoreXXf9VJBaBKCQCAVlqHf9WJBaBKCQCAR50R/lX+BgAAIp16AlDlFAAAjjrr67/qggJQpQQAwF5nhn/VRQWgSgkAgK3ODv+qCwtAlRIAAB+5Ivyr/BEgAES69ASgyikAANxz1dd/VYcCUKUEAMBrV4Z/VacCUKUEAMDN1eFf1bEAVCkBANAj/Ks6F4AqJQCAXL3Cv2qAAlClBACQp2f4Vw1SAKqUAABy9A7/qoEKQJUSAMD6Rgj/qsEKQJUSAMC6Rgn/qgELQJUSAMB6Rgr/qkELQJUSAMA6Rgv/qoELQJUSAMD8Rgz/qsELwI0iAMBsRg3+m6Ev7jklAIBZjB7+VRMVgColAIDxzRD+VZMVgColAIBxzRL+VRMWgBtFAIBRzBT8N9Nd8HNKAAC9zRj+VZMXgColAIB+Zg3/qgUKwI0iAMBVZg7+m+lv4DklAICzrRD+VYsVgBtFAIDWVgn+m6Vu5jklAIBWVgv/qoULwI0iAMBRKwb/zbI39poiAMBWKwf/zfI3+JoiAMA9CcF/E3OjrykCANwkBf9N3A2/pggA5EoM/pvYG39NEQDIkRz8N/EL8JoiALAuwf+ThbhDEQBYh+D/lQXZQBkAmI/Qf5/F2UERABif4N/GIh2kDACMQ+jvZ8EaUQgAriPwH2cBT6AMALQn9NuymBdQCAD2E/jnsrgdKQYAgr6X/wNpt/UbXntutgAAAABJRU5ErkJggg=='],
        'icons.svg' => [false, <<<'MC_ASSET_4'
<svg xmlns="http://www.w3.org/2000/svg" style="display:none">
<defs>
<symbol id="logo" viewBox="0 0 24 24"><rect x="2" y="2" width="20" height="20" rx="6" fill="currentColor"/><path d="M7 16V8l5 5 5-5v8" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"/></symbol>
<symbol id="home" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V20h5v-6h4v6h5V9.5"/></symbol>
<symbol id="calendar" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4.5" width="18" height="16" rx="2.5"/><path d="M3 9.5h18M8 2.5v4M16 2.5v4"/></symbol>
<symbol id="list" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M9 6h11M9 12h11M9 18h11"/><circle cx="4.5" cy="6" r="1" fill="currentColor"/><circle cx="4.5" cy="12" r="1" fill="currentColor"/><circle cx="4.5" cy="18" r="1" fill="currentColor"/></symbol>
<symbol id="chart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 3v18h18"/><path d="m7 15 4-4 3 3 6-6"/></symbol>
<symbol id="image" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2.5"/><circle cx="9" cy="9" r="1.8"/><path d="m21 15-4.5-4.5L6 21"/></symbol>
<symbol id="book" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5V5a2 2 0 0 1 2-2h14v15H6.5A2.5 2.5 0 0 0 4 20.5 2.5 2.5 0 0 0 6.5 23H20v-5"/></symbol>
<symbol id="users" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="8" r="4"/><path d="M4 21c0-4 3.6-6.5 8-6.5s8 2.5 8 6.5"/></symbol>
<symbol id="team" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.5 3-5.5 6.5-5.5s6.5 2 6.5 5.5"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8M18 14.8c2.2.6 3.5 2.4 3.5 5.2"/></symbol>
<symbol id="code" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m8 7-5 5 5 5M16 7l5 5-5 5"/></symbol>
<symbol id="settings" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.8l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.8-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1.1-1.5 1.7 1.7 0 0 0-1.8.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.8 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1.1 1.7 1.7 0 0 0-.3-1.8l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.8.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.8-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.8V9a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1Z"/></symbol>
<symbol id="key" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="7.5" cy="15.5" r="4.5"/><path d="m10.7 12.3 9.8-9.8M16 7l3 3M18.5 4.5l2 2"/></symbol>
<symbol id="shield" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/></symbol>
<symbol id="x" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M18 6 6 18M6 6l12 12"/></symbol>
<symbol id="chevrons" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m7 15 5 5 5-5M7 9l5-5 5 5"/></symbol>
<symbol id="chevron-left" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m15 18-6-6 6-6"/></symbol>
<symbol id="chevron-right" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m9 18 6-6-6-6"/></symbol>
<symbol id="plus" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></symbol>
<symbol id="pen" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></symbol>
<symbol id="moon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></symbol>
<symbol id="logout" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5M21 12H9"/></symbol>
<symbol id="menu" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M3 6h18M3 12h18M3 18h18"/></symbol>
<symbol id="alert" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="M12 8v4M12 16h.01"/></symbol>
<symbol id="check" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m8 12 3 3 5-6"/></symbol>
<symbol id="dot" viewBox="0 0 24 24"><circle cx="12" cy="12" r="3" fill="currentColor"/></symbol>
<symbol id="copy" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="9" y="9" width="12" height="12" rx="2"/><path d="M5 15H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h10a1 1 0 0 1 1 1v1"/></symbol>
<symbol id="clock" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.5"/><path d="M12 7v5l3 2"/></symbol>
<symbol id="external" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/></symbol>
<symbol id="refresh" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 12a9 9 0 0 1-15.5 6.2L3 16M3 12a9 9 0 0 1 15.5-6.2L21 8"/><path d="M21 3v5h-5M3 21v-5h5"/></symbol>
<symbol id="hash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 9h16M4 15h16M10 3 8 21M16 3l-2 18"/></symbol>
<symbol id="braces" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3H7a2 2 0 0 0-2 2v5a2 2 0 0 1-2 2 2 2 0 0 1 2 2v5a2 2 0 0 0 2 2h1M16 21h1a2 2 0 0 0 2-2v-5a2 2 0 0 1 2-2 2 2 0 0 1-2-2V5a2 2 0 0 0-2-2h-1"/></symbol>
<symbol id="upload" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M17 8l-5-5-5 5M12 3v12"/></symbol>
<symbol id="trash" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6"/></symbol>
<symbol id="play" viewBox="0 0 24 24"><path d="M8 5.5v13l11-6.5Z" fill="currentColor"/></symbol>
<symbol id="mail" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="4.5" width="19" height="15" rx="2"/><path d="m3 6 9 7 9-7"/></symbol>
<symbol id="grip" viewBox="0 0 24 24"><g fill="currentColor"><circle cx="9" cy="6" r="1.5"/><circle cx="15" cy="6" r="1.5"/><circle cx="9" cy="12" r="1.5"/><circle cx="15" cy="12" r="1.5"/><circle cx="9" cy="18" r="1.5"/><circle cx="15" cy="18" r="1.5"/></g></symbol>
<symbol id="heart" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21.2l8.8-8.8a5.5 5.5 0 0 0 0-7.8Z"/></symbol>
<symbol id="reply" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 11.5a8.4 8.4 0 0 1-12.4 7.4L3 21l2.1-5.6A8.4 8.4 0 1 1 21 11.5Z"/></symbol>
<symbol id="repost" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m17 2 4 4-4 4"/><path d="M3 11v-1a4 4 0 0 1 4-4h14M7 22l-4-4 4-4"/><path d="M21 13v1a4 4 0 0 1-4 4H3"/></symbol>
<symbol id="file" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h5"/></symbol>
<symbol id="download" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4M7 10l5 5 5-5M12 15V3"/></symbol>
<symbol id="link" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10 13a5 5 0 0 0 7.5.5l3-3a5 5 0 0 0-7-7l-1.7 1.7"/><path d="M14 11a5 5 0 0 0-7.5-.5l-3 3a5 5 0 0 0 7 7l1.7-1.7"/></symbol>
<symbol id="inbox" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 12h-6l-2 3h-4l-2-3H2"/><path d="M5.5 5.1 2 12v6a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2v-6l-3.5-6.9A2 2 0 0 0 16.8 4H7.2a2 2 0 0 0-1.7 1.1Z"/></symbol>
<symbol id="target" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="9.5"/><circle cx="12" cy="12" r="5.5"/><circle cx="12" cy="12" r="1.5" fill="currentColor"/></symbol>
<symbol id="megaphone" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m3 11 15-6v14L3 13Z"/><path d="M11.6 16.8a3 3 0 1 1-5.8-1.6"/><path d="M21 9v6"/></symbol>
<symbol id="plug" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22v-5M9 8V2M15 8V2M18 8v5a6 6 0 0 1-12 0V8Z"/></symbol>
<symbol id="sparkles" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3 10.1 8.8a2 2 0 0 1-1.3 1.3L3 12l5.8 1.9a2 2 0 0 1 1.3 1.3L12 21l1.9-5.8a2 2 0 0 1 1.3-1.3L21 12l-5.8-1.9a2 2 0 0 1-1.3-1.3Z"/><path d="M5 3v4M3 5h4M19 17v4M17 19h4"/></symbol>
<symbol id="palette" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22a10 10 0 1 1 10-10c0 2.8-2.2 4-4 4h-1.8a1.7 1.7 0 0 0-1.2 2.9A1.7 1.7 0 0 1 13.8 22Z"/><circle cx="7.5" cy="10.5" r="1" fill="currentColor"/><circle cx="12" cy="7.5" r="1" fill="currentColor"/><circle cx="16.5" cy="10.5" r="1" fill="currentColor"/></symbol>
<symbol id="globe" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9.5"/><path d="M2.5 12h19M12 2.5a14.5 14.5 0 0 1 0 19M12 2.5a14.5 14.5 0 0 0 0 19"/></symbol>
<symbol id="bell" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.9 1.9 0 0 0 3.4 0"/></symbol>
</defs>
</svg>
MC_ASSET_4],
        'looker-studio-connector.gs' => [false, <<<'MC_ASSET_5'
/**
 * MixCreate community connector for Looker Studio.
 *
 * Setup:
 *  1. Go to script.google.com, create a project and paste this file in.
 *  2. In Project Settings, tick "Show appsscript.json" and add:
 *       "dataStudio": { "name": "MixCreate", "company": "You", "logoUrl": "https://your-site/assets/favicon.svg",
 *                       "addonUrl": "https://your-site", "supportUrl": "https://your-site", "description": "Social analytics from MixCreate" }
 *  3. Deploy → New deployment → type "Add-on" (or use "Test deployments" for yourself) and open it in Looker Studio.
 *  4. Enter your MixCreate address (e.g. https://social.example.com) and an API token from API & webhooks.
 */
var cc = DataStudioApp.createCommunityConnector();

function getAuthType() {
  return cc.newAuthTypeResponse().setAuthType(cc.AuthType.NONE).build();
}

function isAdminUser() { return false; }

function getConfig() {
  var config = cc.getConfig();
  config.newTextInput().setId('baseUrl').setName('MixCreate address').setPlaceholder('https://social.example.com');
  config.newTextInput().setId('token').setName('API token').setPlaceholder('mc_...');
  config.setDateRangeRequired(true);
  return config.build();
}

function fields() {
  var f = cc.getFields();
  var t = cc.FieldType;
  var a = cc.AggregationType;
  f.newDimension().setId('date').setType(t.YEAR_MONTH_DAY);
  f.newDimension().setId('account').setType(t.TEXT);
  f.newDimension().setId('network').setType(t.TEXT);
  f.newMetric().setId('followers').setType(t.NUMBER).setAggregation(a.MAX);
  ['posts', 'likes', 'comments', 'shares', 'impressions', 'engagement'].forEach(function (id) {
    f.newMetric().setId(id).setType(t.NUMBER).setAggregation(a.SUM);
  });
  return f;
}

function getSchema() {
  return { schema: fields().build() };
}

function getData(request) {
  var base = request.configParams.baseUrl.replace(/\/+$/, '');
  var url = base + '/api/v1/analytics?from=' + request.dateRange.startDate + '&to=' + request.dateRange.endDate + '&api_token=' + encodeURIComponent(request.configParams.token);
  var res = UrlFetchApp.fetch(url, { muteHttpExceptions: true });
  if (res.getResponseCode() !== 200) {
    cc.newUserError().setText('MixCreate returned ' + res.getResponseCode() + ': ' + res.getContentText().slice(0, 200)).throwException();
  }
  var data = JSON.parse(res.getContentText()).data;
  var requested = fields().forIds(request.fields.map(function (f) { return f.name; }));
  var ids = requested.asArray().map(function (f) { return f.getId(); });
  var rows = data.map(function (r) {
    return { values: ids.map(function (id) { return id === 'date' ? r.date.replace(/-/g, '') : (r[id] === null ? 0 : r[id]); }) };
  });
  return { schema: requested.build(), rows: rows };
}
MC_ASSET_5],
        'manifest.webmanifest' => [false, <<<'MC_ASSET_6'
{
    "name": "MixCreate",
    "short_name": "MixCreate",
    "description": "Self-hosted social media scheduling and analytics",
    "start_url": "./",
    "scope": "./",
    "display": "standalone",
    "background_color": "#f6f7f9",
    "theme_color": "#4f46e5",
    "icons": [
        { "src": "index.php?asset=icon-192.png", "sizes": "192x192", "type": "image/png" },
        { "src": "index.php?asset=icon-512.png", "sizes": "512x512", "type": "image/png" },
        { "src": "index.php?asset=icon-512-maskable.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable" }
    ],
    "shortcuts": [
        { "name": "New post", "url": "./posts/new" },
        { "name": "Calendar", "url": "./calendar" },
        { "name": "Inbox", "url": "./inbox" }
    ]
}
MC_ASSET_6],
        'offline.html' => [false, <<<'MC_ASSET_7'
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Offline</title>
<style>body{margin:0;min-height:100vh;display:grid;place-items:center;font:16px/1.5 system-ui,sans-serif;background:#f6f7f9;color:#15181e;text-align:center;padding:24px}@media(prefers-color-scheme:dark){body{background:#0f1115;color:#eceef2}}button{font:inherit;padding:8px 16px;border-radius:8px;border:0;background:#4f46e5;color:#fff;cursor:pointer}</style>
</head><body><div><h1>You’re offline</h1><p>MixCreate needs a connection. Your scheduled posts still go out from the server.</p><button onclick="location.reload()">Try again</button></div></body></html>
MC_ASSET_7],
        'sw.js' => [false, <<<'MC_ASSET_8'
/* MixCreate service worker: offline pages, cached assets and push notifications. */
const STATIC = 'mixcreate-static-v3';
const PAGES = 'mixcreate-pages';
const SCOPE = new URL(self.registration.scope);
const OFFLINE = new URL(self.location.pathname + '?asset=offline.html', self.location.origin).toString();
const MAX_PAGES = 80;
// Pages that are never saved for offline use.
const SKIP = /^(logout|login|setup|invite|password|r\/|b\/|cron|mcp|collect|api\/|drive\/|canva\/|accounts\/(connect|callback)|ads\/(connect|callback)|reports\/(pdf|pptx)|import\/sample)/;

self.addEventListener('install', (e) => {
    e.waitUntil(caches.open(STATIC).then((c) => c.add(OFFLINE)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
    e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== STATIC && k !== PAGES).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

async function trim(cache) {
    const keys = await cache.keys();
    for (let i = 0; i < keys.length - MAX_PAGES; i++) await cache.delete(keys[i]);
}

self.addEventListener('fetch', (e) => {
    const req = e.request;
    if (req.method !== 'GET') return;
    const url = new URL(req.url);
    if (url.origin !== SCOPE.origin || !url.pathname.startsWith(SCOPE.pathname)) return;
    const rel = url.pathname.slice(SCOPE.pathname.length);

    // App pages: always try the network; keep a copy so the page opens offline later.
    if (req.mode === 'navigate') {
        e.respondWith((async () => {
            try {
                const res = await fetch(req);
                const type = res.headers.get('content-type') || '';
                if (res.ok && !res.redirected && type.includes('text/html') && !SKIP.test(rel)) {
                    const cache = await caches.open(PAGES);
                    await cache.put(req, res.clone());
                    trim(cache);
                }
                return res;
            } catch (err) {
                return (await caches.match(req, { cacheName: PAGES })) || (await caches.match(OFFLINE));
            }
        })());
        return;
    }

    // Styles, scripts and icons: serve the saved copy, refresh it in the background.
    if (url.searchParams.has('asset') && !['sw.js', 'manifest.webmanifest'].includes(url.searchParams.get('asset'))) {
        e.respondWith((async () => {
            const cache = await caches.open(STATIC);
            const hit = await cache.match(req);
            const net = fetch(req).then(async (res) => {
                if (res.ok) {
                    // Assets are versioned (?v=…): drop older copies of the same file.
                    for (const k of await cache.keys()) if (new URL(k.url).searchParams.get('asset') === url.searchParams.get('asset') && k.url !== req.url) cache.delete(k);
                    cache.put(req, res.clone());
                }
                return res;
            }).catch(() => null);
            if (hit) { e.waitUntil(net); return hit; }
            return (await net) || Response.error();
        })());
    }
});

self.addEventListener('push', (e) => {
    let data = {};
    try { data = e.data ? e.data.json() : {}; } catch (err) { data = { title: 'MixCreate', body: e.data ? e.data.text() : '' }; }
    e.waitUntil(self.registration.showNotification(data.title || 'MixCreate', {
        body: data.body || '',
        icon: new URL(self.location.pathname + '?asset=icon-192.png', self.location.origin).toString(),
        badge: new URL(self.location.pathname + '?asset=icon-192.png', self.location.origin).toString(),
        tag: data.tag,
        data: { url: data.url || self.registration.scope },
    }));
});

self.addEventListener('notificationclick', (e) => {
    e.notification.close();
    const url = e.notification.data && e.notification.data.url;
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((list) => {
        for (const c of list) {
            if (c.url === url && 'focus' in c) return c.focus();
        }
        return self.clients.openWindow(url);
    }));
});
MC_ASSET_8],
        't.js' => [false, <<<'MC_ASSET_9'
/* MixCreate website analytics. Cookieless; no personal data stored. */
(function () {
    var s = document.currentScript;
    if (!s || navigator.doNotTrack === '1' || /bot|crawl|spider|headless/i.test(navigator.userAgent)) return;
    var site = s.getAttribute('data-site');
    var endpoint = s.getAttribute('data-endpoint') || s.src.replace(/\/assets\/t\.js.*$/, '/collect');
    var pid, start;
    function send(data) {
        var body = JSON.stringify(data);
        if (navigator.sendBeacon) navigator.sendBeacon(endpoint, body);
        else fetch(endpoint, { method: 'POST', body: body, keepalive: true, mode: 'no-cors' });
    }
    function view() {
        pid = Math.random().toString(36).slice(2) + Date.now().toString(36);
        start = Date.now();
        send({ e: 'view', s: site, p: pid, u: location.href, r: document.referrer, t: document.title, w: window.innerWidth });
    }
    function leave() {
        if (!pid) return;
        // Sent every time the tab is hidden; the server keeps the latest total.
        send({ e: 'leave', s: site, p: pid, d: Math.round((Date.now() - start) / 1000) });
    }
    document.addEventListener('visibilitychange', function () { if (document.visibilityState === 'hidden') leave(); });
    // Single-page apps
    var push = history.pushState;
    history.pushState = function () { leave(); push.apply(this, arguments); view(); };
    window.addEventListener('popstate', function () { leave(); view(); });
    view();
})();
MC_ASSET_9],
    ];

    public const TYPES = [
        'css' => 'text/css; charset=utf-8', 'js' => 'text/javascript; charset=utf-8', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'html' => 'text/html; charset=utf-8', 'webmanifest' => 'application/manifest+json',
        'gs' => 'text/plain; charset=utf-8',
    ];

    public static function get(string $name): ?string
    {
        $f = self::FILES[$name] ?? null;
        if ($f === null) {
            return null;
        }
        return $f[0] ? base64_decode($f[1]) : $f[1] . "\n";
    }

    public static function version(): string
    {
        return 'b11d1cb1e4';
    }

    /** Serves an asset with long caching (URLs carry a version). */
    public static function serve(string $name, bool $immutable = true): never
    {
        $body = self::get($name);
        if ($body === null) {
            http_response_code(404);
            exit('Not found');
        }
        $type = self::TYPES[pathinfo($name, PATHINFO_EXTENSION)] ?? 'application/octet-stream';
        $etag = '"' . substr(md5($body), 0, 16) . '"';
        header('Content-Type: ' . $type);
        header('ETag: ' . $etag);
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: ' . ($immutable && isset($_GET['v']) ? 'public, max-age=31536000, immutable' : 'no-cache'));
        if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
            http_response_code(304);
            exit;
        }
        if ($name === 'sw.js') {
            // Lets the worker (served from index.php?asset=sw.js) control every page of the app.
            header('Service-Worker-Allowed: ' . rtrim(dirname((string) parse_url(entry_url([]), PHP_URL_PATH)), '/') . '/');
        }
        header('Content-Length: ' . strlen($body));
        echo $body;
        exit;
    }
}

}

namespace {

use App\Controllers\AccountsController;
use App\Controllers\AutolistsController;
use App\Controllers\DriveController;
use App\Controllers\HashtagsController;
use App\Controllers\ImportController;
use App\Controllers\McpController;
use App\Controllers\PushController;
use App\Controllers\WebsitesController;
use App\Controllers\AdsController;
use App\Controllers\AiController;
use App\Controllers\BioController;
use App\Controllers\CanvaController;
use App\Controllers\CompetitorsController;
use App\Controllers\InboxController;
use App\Controllers\IntegrationsController;
use App\Controllers\ReportsController;
use App\Controllers\AnalyticsController;
use App\Controllers\ApiController;
use App\Controllers\AuthController;
use App\Controllers\CalendarController;
use App\Controllers\CronController;
use App\Controllers\DashboardController;
use App\Controllers\DevelopersController;
use App\Controllers\LibraryController;
use App\Controllers\MediaController;
use App\Controllers\MembersController;
use App\Controllers\PostsController;
use App\Controllers\ProfileController;
use App\Controllers\ServicesController;
use App\Controllers\TagsController;
use App\Controllers\UsersController;
use App\Controllers\WorkspacesController;
use App\Hooks;
use App\Router;

// ---- startup ----
define('BASE_PATH', __DIR__);
define('APP_FILE', __FILE__);



date_default_timezone_set('UTC');
App\Config::load();

if (App\Config::get('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

ini_set('log_errors', '1');

// ---- routes ----
// Scripts and tests can load the app without handling a request.
if (defined('MIXCREATE_LIBRARY')) {
    return;
}

// Command line: `php index.php cron` runs the scheduler (add it to crontab, every minute).
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') === 'cron') {
        Hooks::loadModules();
        echo json_encode(App\Cron::tick(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(0);
    }
    fwrite(STDERR, "Usage: php " . basename(APP_FILE) . " cron\n");
    exit(1);
}

// On Apache, add the URL rewriting rules once so pages have clean addresses.
if (preg_match('/apache|litespeed/i', $_SERVER['SERVER_SOFTWARE'] ?? '') && !is_file(BASE_PATH . '/.htaccess') && is_writable(BASE_PATH)) {
    @file_put_contents(BASE_PATH . '/.htaccess', "# Added by MixCreate: send every request to index.php.\nDirectoryIndex index.php\n<IfModule mod_rewrite.c>\n    RewriteEngine On\n    RewriteCond %{HTTP:Authorization} .\n    RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]\n    RewriteCond %{REQUEST_FILENAME} !-f\n    RewriteRule ^ index.php [L]\n</IfModule>\n");
}

// Static files and stored media need no session.
$earlyPath = '/' . trim(substr((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), strlen(base_path())), '/');
if (in_array($earlyPath, ['/', '/' . basename(APP_FILE)], true)) {
    if (isset($_GET['asset']) && is_string($_GET['asset'])) {
        App\Assets::serve($_GET['asset'], !in_array($_GET['asset'], ['sw.js', 'manifest.webmanifest', 'offline.html'], true));
    }
    if (isset($_GET['file']) && is_string($_GET['file'])) {
        App\Blob::serve($_GET['file']);
    }
}
if (preg_match('#^/assets/([\w.-]+)$#', $earlyPath, $em)) {
    App\Assets::serve($em[1]);
}
if (in_array($earlyPath, ['/sw.js', '/manifest.webmanifest', '/offline.html', '/favicon.ico', '/looker-studio-connector.gs'], true)) {
    App\Assets::serve($earlyPath === '/favicon.ico' ? 'favicon.svg' : substr($earlyPath, 1), false);
}
if (preg_match('#^/f/([\w.-]+)$#', $earlyPath, $em)) {
    App\Blob::serve($em[1]);
}
// Never serve the hidden settings or database files, even if the web server passes them here.
if (str_starts_with($earlyPath, '/.ht')) {
    http_response_code(404);
    exit;
}

// The analytics beacon is high volume and anonymous: answer it before sessions start.
if ($earlyPath === '/collect') {
    (new App\Controllers\WebsitesController())->collect();
}
// MCP clients authenticate with API tokens; no session or CSRF.
if ($earlyPath === '/mcp') {
    App\Hooks::loadModules();
    (new App\Controllers\McpController())->handle();
    exit;
}

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('mixcreate_session');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => (base_path() ?: '') . '/',
    'secure' => $https,
    'httponly' => true,
    'samesite' => 'Lax',
]);
session_start();

// Old form input survives exactly one redirect.
$_SESSION['_old_now'] = $_SESSION['_old'] ?? [];
unset($_SESSION['_old']);

header('X-Frame-Options: SAMEORIGIN');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

Hooks::loadModules();

$r = new Router();

$r->group(['guest'], function (Router $r) {
    $r->get('/login', [AuthController::class, 'showLogin']);
    $r->post('/login', [AuthController::class, 'login']);
    $r->get('/setup', [AuthController::class, 'showSetup']);
    $r->post('/setup', [AuthController::class, 'setup']);
});
$r->get('/invite/{token}', [AuthController::class, 'showInvite']);
$r->post('/invite/{token}', [AuthController::class, 'acceptInvite']);
$r->post('/logout', [AuthController::class, 'logout'], ['auth']);

$r->get('/cron', [CronController::class, 'run']);
$r->options('/collect', [WebsitesController::class, 'collect']);

// Public pages: shared reports and link-in-bio.
$r->get('/r/{token}', [ReportsController::class, 'public']);
$r->get('/@{slug}', [BioController::class, 'show']);
$r->get('/@{slug}/l/{id}', [BioController::class, 'go']);

$r->group(['auth'], function (Router $r) {
    $r->get('/workspaces/new', [WorkspacesController::class, 'create']);
    $r->post('/workspaces', [WorkspacesController::class, 'store']);
    $r->post('/workspaces/{id}/switch', [WorkspacesController::class, 'switch']);
    $r->get('/profile', [ProfileController::class, 'edit']);
    $r->post('/profile', [ProfileController::class, 'update']);
    $r->post('/profile/password', [ProfileController::class, 'password']);
    $r->get('/push/key', [PushController::class, 'key']);
    $r->post('/push/subscribe', [PushController::class, 'subscribe']);
    $r->post('/push/unsubscribe', [PushController::class, 'unsubscribe']);
    $r->post('/push/test', [PushController::class, 'test']);
    $r->post('/push/preferences', [PushController::class, 'preferences']);

    $r->group(['admin'], function (Router $r) {
        $r->get('/admin/services', [ServicesController::class, 'index']);
        $r->post('/admin/services/{provider}', [ServicesController::class, 'update']);
        $r->get('/admin/integrations', [IntegrationsController::class, 'index']);
        $r->post('/admin/integrations/smtp/test', [IntegrationsController::class, 'testEmail']);
        $r->post('/admin/integrations/ai/test', [IntegrationsController::class, 'testAi']);
        $r->post('/admin/integrations/{group}', [IntegrationsController::class, 'save']);
        $r->get('/admin/users', [UsersController::class, 'index']);
        $r->post('/admin/users/{id}/delete', [UsersController::class, 'delete']);
        $r->post('/admin/users/{id}/admin', [UsersController::class, 'toggleAdmin']);
    });

    $r->group(['workspace'], function (Router $r) {
        $r->get('/', [DashboardController::class, 'index']);
        $r->get('/calendar', [CalendarController::class, 'index']);
        $r->get('/posts', [PostsController::class, 'index']);
        $r->get('/posts/new', [PostsController::class, 'create'], ['role:member']);
        $r->post('/posts', [PostsController::class, 'store'], ['role:member']);
        $r->get('/posts/{id}', [PostsController::class, 'edit']);
        $r->post('/posts/{id}', [PostsController::class, 'update'], ['role:member']);
        $r->post('/posts/{id}/delete', [PostsController::class, 'delete'], ['role:member']);
        $r->post('/posts/{id}/duplicate', [PostsController::class, 'duplicate'], ['role:member']);
        $r->post('/posts/{id}/retry', [PostsController::class, 'retry'], ['role:member']);
        $r->post('/posts/{id}/autolist', [AutolistsController::class, 'addPost'], ['role:member']);

        $r->get('/import', [ImportController::class, 'form'], ['role:member']);
        $r->get('/import/sample', [ImportController::class, 'sample'], ['role:member']);
        $r->post('/import', [ImportController::class, 'upload'], ['role:member']);
        $r->post('/import/confirm', [ImportController::class, 'confirm'], ['role:member']);
        $r->post('/import/cancel', [ImportController::class, 'cancel'], ['role:member']);

        $r->get('/autolists', [AutolistsController::class, 'index']);
        $r->post('/autolists', [AutolistsController::class, 'store'], ['role:member']);
        $r->get('/autolists/{id}', [AutolistsController::class, 'show']);
        $r->post('/autolists/{id}', [AutolistsController::class, 'update'], ['role:member']);
        $r->post('/autolists/{id}/delete', [AutolistsController::class, 'delete'], ['role:member']);
        $r->post('/autolists/{id}/items', [AutolistsController::class, 'addItem'], ['role:member']);
        $r->post('/autolists/{id}/items/{item}/delete', [AutolistsController::class, 'deleteItem'], ['role:member']);
        $r->post('/autolists/{id}/publish', [AutolistsController::class, 'publishNow'], ['role:member']);

        $r->get('/websites', [WebsitesController::class, 'index']);
        $r->post('/websites', [WebsitesController::class, 'store'], ['role:admin']);
        $r->post('/websites/{id}/delete', [WebsitesController::class, 'delete'], ['role:admin']);

        $r->get('/hashtags', [HashtagsController::class, 'index']);
        $r->post('/hashtags', [HashtagsController::class, 'store'], ['role:member']);
        $r->post('/hashtags/{id}/toggle', [HashtagsController::class, 'toggle'], ['role:member']);
        $r->post('/hashtags/{id}/refresh', [HashtagsController::class, 'refresh'], ['role:member']);
        $r->post('/hashtags/{id}/delete', [HashtagsController::class, 'delete'], ['role:member']);
        $r->get('/hashtags/{id}/export', [HashtagsController::class, 'export']);

        $r->get('/drive/connect', [DriveController::class, 'connect'], ['role:member']);
        $r->get('/drive/callback', [DriveController::class, 'callback'], ['role:member']);
        $r->post('/drive/disconnect', [DriveController::class, 'disconnect']);
        $r->get('/drive/files', [DriveController::class, 'files'], ['role:member']);
        $r->post('/drive/import', [DriveController::class, 'import'], ['role:member']);

        $r->get('/media', [MediaController::class, 'index']);
        $r->post('/media', [MediaController::class, 'upload'], ['role:member']);
        $r->post('/media/{id}/delete', [MediaController::class, 'delete'], ['role:member']);

        $r->get('/analytics', [AnalyticsController::class, 'index']);

        $r->get('/reports', [ReportsController::class, 'index']);
        $r->get('/reports/pdf', [ReportsController::class, 'pdf']);
        $r->get('/reports/pptx', [ReportsController::class, 'pptx']);
        $r->post('/reports/share', [ReportsController::class, 'share'], ['role:member']);
        $r->post('/reports/shares/{id}/revoke', [ReportsController::class, 'revokeShare'], ['role:member']);
        $r->post('/reports/schedules', [ReportsController::class, 'saveSchedule'], ['role:member']);
        $r->post('/reports/schedules/{id}/delete', [ReportsController::class, 'deleteSchedule'], ['role:member']);
        $r->post('/reports/schedules/{id}/send', [ReportsController::class, 'sendNow'], ['role:member']);

        $r->get('/competitors', [CompetitorsController::class, 'index']);
        $r->post('/competitors', [CompetitorsController::class, 'store'], ['role:member']);
        $r->post('/competitors/{id}/delete', [CompetitorsController::class, 'delete'], ['role:member']);
        $r->post('/competitors/{id}/refresh', [CompetitorsController::class, 'refresh'], ['role:member']);

        $r->get('/inbox', [InboxController::class, 'index']);
        $r->post('/inbox/refresh', [InboxController::class, 'refresh']);
        $r->post('/inbox/read-all', [InboxController::class, 'markAllRead'], ['role:member']);
        $r->post('/inbox/{id}/reply', [InboxController::class, 'reply'], ['role:member']);
        $r->post('/inbox/{id}/status', [InboxController::class, 'status'], ['role:member']);

        $r->get('/bio', [BioController::class, 'edit']);
        $r->post('/bio', [BioController::class, 'update'], ['role:member']);
        $r->post('/bio/links', [BioController::class, 'addLink'], ['role:member']);
        $r->post('/bio/links/reorder', [BioController::class, 'reorder'], ['role:member']);
        $r->post('/bio/links/{id}', [BioController::class, 'updateLink'], ['role:member']);
        $r->post('/bio/links/{id}/delete', [BioController::class, 'deleteLink'], ['role:member']);

        $r->get('/ads', [AdsController::class, 'index']);
        $r->get('/ads/connect/{provider}', [AdsController::class, 'connect'], ['role:admin']);
        $r->get('/ads/callback/{provider}', [AdsController::class, 'callback'], ['role:admin']);
        $r->get('/ads/choose', [AdsController::class, 'choose'], ['role:admin']);
        $r->post('/ads/choose', [AdsController::class, 'chooseSubmit'], ['role:admin']);
        $r->post('/ads/{id}/sync', [AdsController::class, 'sync'], ['role:admin']);
        $r->post('/ads/{id}/delete', [AdsController::class, 'delete'], ['role:admin']);

        $r->post('/ai/assist', [AiController::class, 'assist'], ['role:member']);
        $r->get('/canva/connect', [CanvaController::class, 'connect'], ['role:member']);
        $r->get('/canva/callback', [CanvaController::class, 'callback'], ['role:member']);
        $r->post('/canva/disconnect', [CanvaController::class, 'disconnect']);
        $r->get('/canva/designs', [CanvaController::class, 'designs'], ['role:member']);
        $r->post('/canva/import', [CanvaController::class, 'import'], ['role:member']);

        $r->get('/accounts', [AccountsController::class, 'index']);
        $r->get('/accounts/connect/{provider}', [AccountsController::class, 'connect'], ['role:admin']);
        $r->post('/accounts/connect/{provider}', [AccountsController::class, 'connectSubmit'], ['role:admin']);
        $r->get('/accounts/callback/{provider}', [AccountsController::class, 'callback'], ['role:admin']);
        $r->get('/accounts/choose', [AccountsController::class, 'choose'], ['role:admin']);
        $r->post('/accounts/choose', [AccountsController::class, 'chooseSubmit'], ['role:admin']);
        $r->post('/accounts/{id}/delete', [AccountsController::class, 'delete'], ['role:admin']);

        $r->get('/library', [LibraryController::class, 'index']);
        $r->post('/library/templates', [LibraryController::class, 'saveTemplate'], ['role:member']);
        $r->post('/library/templates/{id}/delete', [LibraryController::class, 'deleteTemplate'], ['role:member']);
        $r->post('/library/hashtags', [LibraryController::class, 'saveGroup'], ['role:member']);
        $r->post('/library/hashtags/{id}/delete', [LibraryController::class, 'deleteGroup'], ['role:member']);
        $r->post('/library/variables', [LibraryController::class, 'saveVariable'], ['role:admin']);
        $r->post('/library/variables/{id}/delete', [LibraryController::class, 'deleteVariable'], ['role:admin']);

        $r->get('/developers', [DevelopersController::class, 'index']);
        $r->post('/developers/tokens', [DevelopersController::class, 'createToken']);
        $r->post('/developers/tokens/{id}/delete', [DevelopersController::class, 'deleteToken']);
        $r->post('/developers/webhooks', [DevelopersController::class, 'saveWebhook'], ['role:admin']);
        $r->post('/developers/webhooks/{id}/delete', [DevelopersController::class, 'deleteWebhook'], ['role:admin']);
        $r->post('/developers/webhooks/{id}/test', [DevelopersController::class, 'testWebhook'], ['role:admin']);

        $r->get('/tags', [TagsController::class, 'index']);
        $r->post('/tags', [TagsController::class, 'store'], ['role:member']);
        $r->post('/tags/{id}/delete', [TagsController::class, 'delete'], ['role:member']);

        $r->get('/settings', [WorkspacesController::class, 'edit'], ['role:admin']);
        $r->post('/settings', [WorkspacesController::class, 'update'], ['role:admin']);
        $r->post('/settings/delete', [WorkspacesController::class, 'delete'], ['role:admin']);
        $r->post('/settings/branding', [WorkspacesController::class, 'branding'], ['role:admin']);
        $r->post('/settings/ai', [WorkspacesController::class, 'ai'], ['role:admin']);
        $r->get('/members', [MembersController::class, 'index']);
        $r->post('/members/invite', [MembersController::class, 'invite'], ['role:admin']);
        $r->post('/members/{id}/role', [MembersController::class, 'role'], ['role:admin']);
        $r->post('/members/{id}/remove', [MembersController::class, 'remove'], ['role:admin']);
        $r->post('/invitations/{id}/revoke', [MembersController::class, 'revoke'], ['role:admin']);
    });
});

// REST API: bearer-token auth, no session or CSRF.
$r->group(['no_csrf'], function (Router $r) {
    $r->get('/api/v1/me', [ApiController::class, 'me']);
    $r->get('/api/v1/analytics', [ApiController::class, 'analytics']);
    $r->get('/api/v1/accounts', [ApiController::class, 'accounts']);
    $r->get('/api/v1/tags', [ApiController::class, 'tags']);
    $r->get('/api/v1/media', [ApiController::class, 'media']);
    $r->post('/api/v1/media', [ApiController::class, 'upload']);
    $r->get('/api/v1/posts', [ApiController::class, 'posts']);
    $r->post('/api/v1/posts', [ApiController::class, 'store']);
    $r->get('/api/v1/posts/{id}', [ApiController::class, 'show']);
    $r->put('/api/v1/posts/{id}', [ApiController::class, 'update']);
    $r->post('/api/v1/posts/{id}', [ApiController::class, 'update']);
    $r->delete('/api/v1/posts/{id}', [ApiController::class, 'destroy']);
    $r->post('/api/v1/posts/{id}/{decision}', function (string $id, string $decision) {
        if (!in_array($decision, ['approve', 'reject'], true)) {
            json_response(['error' => 'Not found.'], 404);
        }
        (new ApiController())->review($id, $decision);
    });
});

// Modules add routes here and wrap them in their own middleware groups.
Hooks::fire('routes', $r);

try {
    $r->dispatch($_SERVER['REQUEST_METHOD'] ?? 'GET', $_SERVER['REQUEST_URI'] ?? '/');
} catch (Throwable $e) {
    error_log((string) $e);
    if (App\Config::get('debug')) {
        http_response_code(500);
        echo '<pre>' . e((string) $e) . '</pre>';
        exit;
    }
    abort(500, 'Something went wrong. The error was logged.');
}

}
