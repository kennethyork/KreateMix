<?php
declare(strict_types=1);

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
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/'));
        $dir = preg_replace('#/public$#', '', $dir);
        $base = rtrim($dir, '/');
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

function asset(string $path): string
{
    $file = BASE_PATH . '/public/assets/' . ltrim($path, '/');
    $v = is_file($file) ? filemtime($file) : 0;
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
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
    $file = BASE_PATH . '/views/' . $template . '.php';
    if (!is_file($file)) {
        throw new RuntimeException("View not found: $template");
    }
    extract($data, EXTR_SKIP);
    ob_start();
    require $file;
    $content = ob_get_clean();
    if ($layout === null) {
        return $content;
    }
    $title = $data['title'] ?? '';
    ob_start();
    require BASE_PATH . '/views/layouts/' . $layout . '.php';
    return ob_get_clean();
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
