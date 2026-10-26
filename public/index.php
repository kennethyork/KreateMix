<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

use App\Controllers\AccountsController;
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

// Let PHP's built-in server serve real files (assets, uploads) directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file)) {
        return false;
    }
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

$r->group(['auth'], function (Router $r) {
    $r->get('/workspaces/new', [WorkspacesController::class, 'create']);
    $r->post('/workspaces', [WorkspacesController::class, 'store']);
    $r->post('/workspaces/{id}/switch', [WorkspacesController::class, 'switch']);
    $r->get('/profile', [ProfileController::class, 'edit']);
    $r->post('/profile', [ProfileController::class, 'update']);
    $r->post('/profile/password', [ProfileController::class, 'password']);

    $r->group(['admin'], function (Router $r) {
        $r->get('/admin/services', [ServicesController::class, 'index']);
        $r->post('/admin/services/{provider}', [ServicesController::class, 'update']);
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

        $r->get('/media', [MediaController::class, 'index']);
        $r->post('/media', [MediaController::class, 'upload'], ['role:member']);
        $r->post('/media/{id}/delete', [MediaController::class, 'delete'], ['role:member']);

        $r->get('/analytics', [AnalyticsController::class, 'index']);

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
