<?php
// Edits that turn the self-hosted index.php into KreateMix Cloud. Applied by build.php.
// 'replace' / 'after' / 'before' is text that must appear exactly once in the source index.php;
// 'file' is a file in parts/, 'text' is inserted as is. Keep the TXT markers at the start of the line.
return [
    [
        'replace' => <<<'TXT'
 * KreateMix: self-hosted social media scheduling and analytics, in one file.
 * https://github.com/kennethyork/KreateMix

TXT,
        'text' => <<<'TXT'
 * KreateMix Cloud: the hosted, paid edition of KreateMix (social media scheduling and analytics), in one file.
 *
 * Based on KreateMix: https://github.com/kennethyork/KreateMix

TXT,
    ],
    [
        'replace' => <<<'TXT'
/**
 * The free, self-hosted edition: no plans, billing or limits. Everything is always available.
 * (KreateMix Cloud, the hosted edition, replaces this class with the real one.)
 */
final class Cloud
{
    public const EDITION = 'oss';

    public static function enabled(): bool
    {
        return false;
    }

    public static function settings(): array
    {
        return [
            'enabled' => 0,
            'signup_open' => 0,
            'company' => '',
            'support_email' => '',
            'source_url' => 'https://github.com/kennethyork/KreateMix',
        ];
    }

    public static function company(): string
    {
        return (string) Config::get('app_name', 'KreateMix');
    }

    public static function customer(int $userId): ?array
    {
        return null;
    }

    public static function trialDaysLeft(array $c): ?int
    {
        return null;
    }

    public static function ownerOf(int $workspaceId): ?int
    {
        $id = Db::value('SELECT created_by FROM workspaces WHERE id = ?', [$workspaceId]);
        return $id !== null ? (int) $id : null;
    }

    public static function workspaceActive(int $workspaceId): bool
    {
        return true;
    }

    public static function canCreateWorkspaces(int $userId): bool
    {
        return false;
    }

    public static function limitMessage(string $what, ?int $workspaceId = null, ?int $userId = null, int $add = 1): ?string
    {
        return null;
    }

    public static function remindDue(): int
    {
        return 0;
    }

    public static function adminIds(): array
    {
        return array_map('intval', array_column(Db::all('SELECT id FROM users WHERE is_admin = 1'), 'id'));
    }
}

TXT,
        'file' => 'Cloud.php',
    ],
    [
        'before' => <<<'TXT'
namespace App\Controllers {

// ---- Controllers/CompetitorsController.php ----

TXT,
        'file' => 'CloudController.php',
    ],
    [
        'after' => <<<'TXT'
  'calendar/index' => 'mc_view_calendar_index',

TXT,
        'text' => <<<'TXT'
  'cloud/admin_customers' => 'mc_view_cloud_admin_customers',
  'cloud/admin_settings' => 'mc_view_cloud_admin_settings',
  'cloud/billing' => 'mc_view_cloud_billing',
  'cloud/landing' => 'mc_view_cloud_landing',
  'cloud/signup' => 'mc_view_cloud_signup',
  'cloud/thanks' => 'mc_view_cloud_thanks',

TXT,
    ],
    [
        'before' => <<<'TXT'
namespace {
use App\Workspace;

// ---- view: competitors/index ----

TXT,
        'file' => 'views.php',
    ],
    [
        'after' => <<<'TXT'
MC_SQL_5,

TXT,
        'file' => '006_hosted.sql',
    ],
    [
        'after' => <<<'TXT'
use App\Controllers\AccountsController;

TXT,
        'text' => <<<'TXT'
use App\Controllers\CloudController;

TXT,
    ],
    [
        'after' => <<<'TXT'
// Server-to-server callbacks; no session or CSRF.

TXT,
        'text' => <<<'TXT'
if ($earlyPath === '/stripe/webhook' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    (new CloudController())->stripeWebhook();
}

TXT,
    ],
    [
        'after' => <<<'TXT'
$r = new Router();


TXT,
        'text' => <<<'TXT'
// Hosted service: visitors who aren't signed in see the public site instead of the sign-in page.
if (App\Cloud::enabled() && !App\Auth::user() && $earlyPath === '/' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    (new CloudController())->landing();
}

TXT,
    ],
    [
        'after' => <<<'TXT'
    $r->post('/setup', [AuthController::class, 'setup']);

TXT,
        'text' => <<<'TXT'
    $r->get('/signup', [CloudController::class, 'showSignup']);
    $r->post('/signup', [CloudController::class, 'signup']);

TXT,
    ],
    [
        'after' => <<<'TXT'
$r->get('/data-deletion', [LegalController::class, 'deletionStatus']);

TXT,
        'text' => <<<'TXT'
$r->get('/buy/{product}', [CloudController::class, 'buy']);
$r->get('/thanks/{product}', [CloudController::class, 'thanks']);
$r->post('/orders/details', [CloudController::class, 'orderDetails']);
$r->post('/contact', [CloudController::class, 'contact']);

TXT,
    ],
    [
        'after' => <<<'TXT'
    $r->post('/push/preferences', [PushController::class, 'preferences']);

TXT,
        'text' => <<<'TXT'
    $r->get('/billing', [CloudController::class, 'billing']);
    $r->post('/billing/checkout', [CloudController::class, 'checkout']);
    $r->post('/billing/portal', [CloudController::class, 'portal']);

TXT,
    ],
    [
        'after' => <<<'TXT'
        $r->post('/admin/legal', [LegalController::class, 'save']);

TXT,
        'text' => <<<'TXT'
        $r->get('/admin/cloud', [CloudController::class, 'settings']);
        $r->post('/admin/cloud', [CloudController::class, 'saveSettings']);
        $r->get('/admin/customers', [CloudController::class, 'customers']);
        $r->post('/admin/customers/{id}', [CloudController::class, 'updateCustomer']);
        $r->post('/admin/orders/{id}', [CloudController::class, 'updateOrder']);
        $r->post('/admin/leads/{id}', [CloudController::class, 'handleLead']);

TXT,
    ],
];
