<?php
declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

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

// Let PHP's built-in server serve real files (assets, uploads) directly.
if (PHP_SAPI === 'cli-server') {
    $file = __DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    if ($file !== __DIR__ . '/' && is_file($file)) {
        return false;
    }
}

// The analytics beacon is high volume and anonymous: answer it before sessions start.
$earlyPath = rtrim((string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH), '/');
if ($earlyPath === base_path() . '/collect') {
    (new App\Controllers\WebsitesController())->collect();
}
// MCP clients authenticate with API tokens; no session or CSRF.
if ($earlyPath === base_path() . '/mcp') {
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
