namespace App\Controllers {

// ---- Controllers/CloudController.php ----
use App\Auth;
use App\Cloud;
use App\Db;
use App\Notify;
use App\Settings;
use App\Workspace;

/** Public site, sign-up, billing and the admin side of the hosted service. */
final class CloudController
{
    /* ---------- Public pages ---------- */

    public function landing(): void
    {
        view('cloud/landing', ['title' => '', 'cloud' => Cloud::settings(), 'plans' => Cloud::plans()], 'public');
    }

    public function showSignup(): void
    {
        if (!Cloud::enabled() || !Cloud::settings()['signup_open']) {
            abort(404);
        }
        $plan = (string) input('plan', 'solo');
        view('cloud/signup', [
            'title' => 'Start your free trial',
            'plans' => Cloud::plans(),
            'plan' => isset(Cloud::plans()[$plan]) ? $plan : array_key_first(Cloud::plans()),
            'trialDays' => Cloud::trialDays(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ], 'auth');
    }

    public function signup(): void
    {
        if (!Cloud::enabled() || !Cloud::settings()['signup_open']) {
            abort(404);
        }
        if (trim((string) input('website', '')) !== '') {
            redirect('/'); // spam trap
        }
        // Rate limit first, so the "already an account" answer can't be used to test many emails.
        if (!kv_rate('signup:' . client_ip(), 5, 3600)) {
            flash('error', 'Too many sign-ups from your network. Try again later.');
            redirect('/signup', ['plan' => (string) input('plan', 'solo')]);
        }
        $data = [
            'name' => trim((string) input('name', '')),
            'email' => trim((string) input('email', '')),
            'workspace' => trim((string) input('workspace', '')),
            'timezone' => (string) input('timezone', 'UTC'),
            'plan' => (string) input('plan', 'solo'),
        ];
        $errors = AuthController::validateUser($data['name'], $data['email'], (string) input('password', ''), (string) input('password_confirmation', ''));
        if ($data['workspace'] === '') {
            $errors[] = 'Give your brand or business a name.';
        }
        if (!isset(Cloud::plans()[$data['plan']])) {
            $errors[] = 'Choose a plan.';
        }
        if (!input('agree')) {
            $errors[] = 'Please accept the terms of service.';
        }
        if (!$errors && Db::value('SELECT 1 FROM users WHERE email = ?', [$data['email']])) {
            $errors[] = 'There’s already an account with that email. Sign in instead.';
        }
        if ($errors) {
            with_old($data);
            foreach ($errors as $err) {
                flash('error', $err);
            }
            redirect('/signup', ['plan' => $data['plan']]);
        }
        $tz = in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true) ? $data['timezone'] : 'UTC';
        $userId = Db::insert('users', [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash((string) input('password'), PASSWORD_DEFAULT),
            'is_admin' => 0,
            'timezone' => $tz,
            'notify' => json_encode(['failed' => 1, 'approvals' => 1, 'comments' => 1, 'published' => 0]),
            'created_at' => now(),
        ]);
        Cloud::startTrial($userId, $data['plan']);
        $wsId = Workspace::create($data['workspace'], $userId);
        Db::update('users', ['current_workspace_id' => $wsId], 'id = ?', [$userId]);
        Auth::login($userId);
        Notify::users(Cloud::adminIds(), 'business', 'New sign-up', "{$data['name']} ({$data['email']}) started a trial.", '/admin/customers');
        $days = Cloud::trialDays();
        flash('success', "Welcome! Your $days-day free trial has started. Connect your first social account.");
        redirect('/accounts');
    }




    /** Buy the setup service or support plan (Stripe Checkout; no account needed). */
    public function buy(string $product): void
    {
        if (!Cloud::enabled() || !in_array($product, ['setup', 'support'], true)) {
            abort(404);
        }
        try {
            redirect(Cloud::productCheckoutUrl($product));
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/');
        }
    }

    public function thanks(string $product): void
    {
        if (!in_array($product, ['setup', 'support'], true)) {
            abort(404);
        }
        $sessionId = (string) input('session_id', '');
        $order = $sessionId !== '' ? Db::one('SELECT * FROM orders WHERE stripe_session_id = ?', [$sessionId]) : null;
        view('cloud/thanks', ['title' => 'Thank you', 'product' => $product, 'sessionId' => $sessionId, 'order' => $order, 'cloud' => Cloud::settings()], 'public');
    }

    /** Setup customers tell us about their server after paying. */
    public function orderDetails(): void
    {
        $sessionId = (string) input('session_id', '');
        if ($sessionId === '' || !preg_match('/^cs_[\w]+$/', $sessionId)) {
            abort(404);
        }
        if (!kv_rate('intake:' . client_ip(), 10, 3600)) {
            flash('error', 'Too many submissions from your network. Try again later.');
            redirect('/thanks/setup', ['session_id' => $sessionId]);
        }
        $details = [
            'domain' => mb_substr(trim((string) input('domain', '')), 0, 200),
            'hosting' => mb_substr(trim((string) input('hosting', '')), 0, 200),
            'platforms' => array_values(array_intersect((array) input('platforms', []), ['facebook', 'instagram', 'linkedin', 'x', 'tiktok', 'youtube', 'pinterest', 'threads', 'google_business', 'bluesky', 'mastodon'])),
            'notes' => mb_substr(trim((string) input('notes', '')), 0, 4000),
        ];
        $order = Db::one('SELECT * FROM orders WHERE stripe_session_id = ?', [$sessionId]);
        if ($order) {
            Db::update('orders', ['details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'updated_at' => now()], 'id = ?', [$order['id']]);
        } else {
            // The webhook may not have arrived yet; keep the details and attach them when it does.
            Db::insert('orders', ['kind' => 'setup', 'status' => 'pending', 'stripe_session_id' => $sessionId, 'details' => json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
        }
        Notify::users(Cloud::adminIds(), 'business', 'Setup details received', $details['domain'] ?: 'New setup order', '/admin/customers');
        flash('success', 'Thanks! We’ll be in touch within one business day.');
        redirect('/thanks/setup', ['session_id' => $sessionId]);
    }

    /** "Run it for me" enquiries. */
    public function contact(): void
    {
        if (trim((string) input('website', '')) !== '') {
            redirect('/');
        }
        $name = mb_substr(trim((string) input('name', '')), 0, 200);
        $email = trim((string) input('email', ''));
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Add your name and a valid email.');
            back('/');
        }
        if (!kv_rate('contact:' . client_ip(), 5, 3600)) {
            flash('error', 'Too many messages from your network. Try again later.');
            back('/');
        }
        Db::insert('leads', [
            'kind' => in_array(input('kind'), ['managed', 'setup', 'support', 'other'], true) ? input('kind') : 'managed',
            'name' => $name,
            'email' => $email,
            'company' => mb_substr(trim((string) input('company', '')), 0, 200),
            'budget' => mb_substr(trim((string) input('budget', '')), 0, 100),
            'message' => mb_substr(trim((string) input('message', '')), 0, 5000),
            'created_at' => now(),
        ]);
        Notify::users(Cloud::adminIds(), 'business', 'New enquiry', "$name ($email)", '/admin/customers');
        Cloud::mailAdmins('New enquiry from ' . $name, '<p>' . e($name) . ' &lt;' . e($email) . '&gt;</p><p>' . nl2br(e((string) input('message', ''))) . '</p>');
        flash('success', 'Thanks! We’ll reply by email soon.');
        redirect('/', [], '#services');
    }

    /** Stripe webhook endpoint. */
    public function stripeWebhook(): never
    {
        $payload = (string) file_get_contents('php://input');
        $secret = (string) Cloud::settings()['stripe_webhook_secret'];
        if (!Cloud::verifySignature($payload, (string) ($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? ''), $secret)) {
            json_response(['error' => 'Bad signature'], 400);
        }
        $event = json_decode($payload, true);
        if (!is_array($event)) {
            json_response(['error' => 'Bad payload'], 400);
        }
        try {
            $new = Cloud::handleEvent($event);
        } catch (\Throwable $e) {
            log_event('error', 'Stripe webhook failed: ' . $e->getMessage());
            json_response(['error' => 'Failed'], 500);
        }
        // Attach setup details saved before the webhook arrived.
        if (($event['type'] ?? '') === 'checkout.session.completed') {
            $sid = (string) ($event['data']['object']['id'] ?? '');
            $pending = Db::one("SELECT * FROM orders WHERE stripe_session_id = ? AND status = 'pending'", [$sid]);
            if ($pending) {
                $o = $event['data']['object'];
                Db::update('orders', ['status' => 'paid', 'email' => (string) ($o['customer_details']['email'] ?? ''), 'name' => (string) ($o['customer_details']['name'] ?? ''), 'amount_total' => (int) ($o['amount_total'] ?? 0), 'currency' => strtoupper((string) ($o['currency'] ?? '')), 'stripe_customer_id' => (string) ($o['customer'] ?? ''), 'updated_at' => now()], 'id = ?', [$pending['id']]);
            }
        }
        json_response(['received' => true, 'duplicate' => !$new]);
    }

    /* ---------- Customer billing ---------- */

    public function billing(): void
    {
        $user = Auth::user();
        $ws = Workspace::current();
        $owner = $ws ? Cloud::ownerOf((int) $ws['id']) : (int) $user['id'];
        $isOwner = $owner === (int) $user['id'] || ($owner === null && Auth::isAdmin());
        $c = Cloud::customer($isOwner ? (int) $user['id'] : (int) $owner);
        if ($isOwner && !$c && !Auth::isAdmin()) {
            Cloud::startTrial((int) $user['id'], array_key_first(Cloud::plans()) ?? 'solo');
            $c = Cloud::customer((int) $user['id']);
        }
        if (input('checkout') === 'success') {
            flash('success', 'Thanks! Your subscription is being set up; this page updates in a few seconds.');
        }
        view('cloud/billing', [
            'title' => 'Billing',
            'customer' => $c,
            'isOwner' => $isOwner,
            'ownerName' => $isOwner ? null : Db::value('SELECT name FROM users WHERE id = ?', [$owner]),
            'plans' => Cloud::plans(),
            'usage' => Cloud::usage($isOwner ? (int) $user['id'] : (int) $owner),
            'active' => $isOwner ? Cloud::isActive($c) : ($ws ? Cloud::workspaceActive((int) $ws['id']) : false),
            'over' => $isOwner ? Cloud::overLimits((int) $user['id']) : [],
            'stripe' => Cloud::stripeReady(),
            'cloud' => Cloud::settings(),
        ]);
    }

    public function checkout(): void
    {
        $plan = (string) input('plan', '');
        try {
            redirect(Cloud::planCheckoutUrl((int) Auth::id(), $plan));
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/billing');
        }
    }

    public function portal(): void
    {
        try {
            redirect(Cloud::portalUrl((int) Auth::id()));
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/billing');
        }
    }

    /* ---------- Admin ---------- */

    public function settings(): void
    {
        view('cloud/admin_settings', ['title' => 'Hosted service', 'cloud' => Cloud::settings()]);
    }

    public function saveSettings(): void
    {
        $old = Cloud::settings();
        $plans = [];
        foreach (Cloud::PLAN_KEYS as $k) {
            $in = (array) (input('plans', [])[$k] ?? []);
            $plans[$k] = [
                'name' => trim((string) ($in['name'] ?? '')) ?: $old['plans'][$k]['name'],
                'price' => trim((string) ($in['price'] ?? '')),
                'blurb' => trim((string) ($in['blurb'] ?? '')),
                'stripe_price' => trim((string) ($in['stripe_price'] ?? '')),
                'workspaces' => max(0, (int) ($in['workspaces'] ?? 0)),
                'accounts' => max(0, (int) ($in['accounts'] ?? 0)),
                'members' => max(0, (int) ($in['members'] ?? 0)),
                'enabled' => !empty($in['enabled']) ? 1 : 0,
            ];
        }
        $secret = trim((string) input('stripe_secret', ''));
        $whsec = trim((string) input('stripe_webhook_secret', ''));
        $values = [
            'enabled' => input('enabled') ? 1 : 0,
            'signup_open' => input('signup_open') ? 1 : 0,
            'company' => trim((string) input('company', '')),
            'support_email' => trim((string) input('support_email', '')),
            'trial_days' => max(0, min(90, (int) input('trial_days', 14))),
            'stripe_secret' => $secret !== '' ? $secret : $old['stripe_secret'],
            'stripe_webhook_secret' => $whsec !== '' ? $whsec : $old['stripe_webhook_secret'],
            'setup_enabled' => input('setup_enabled') ? 1 : 0,
            'setup_price' => trim((string) input('setup_price', '')),
            'setup_stripe_price' => trim((string) input('setup_stripe_price', '')),
            'setup_description' => trim((string) input('setup_description', '')),
            'support_enabled' => input('support_enabled') ? 1 : 0,
            'support_price' => trim((string) input('support_price', '')),
            'support_stripe_price' => trim((string) input('support_stripe_price', '')),
            'support_description' => trim((string) input('support_description', '')),
            'managed_enabled' => input('managed_enabled') ? 1 : 0,
            'managed_description' => trim((string) input('managed_description', '')),
            'source_url' => trim((string) input('source_url', '')) ?: $old['source_url'],
            'plans' => $plans,
        ];
        if (input('remove_stripe')) {
            $values['stripe_secret'] = '';
            $values['stripe_webhook_secret'] = '';
        }
        if ($values['stripe_secret'] !== '' && !preg_match('/^(sk|rk)_(test|live)_/', $values['stripe_secret'])) {
            flash('error', 'That doesn’t look like a Stripe secret key (it starts with sk_live_ or sk_test_).');
            redirect('/admin/cloud');
        }
        Settings::save('cloud', $values);
        flash('success', 'Saved.');
        redirect('/admin/cloud');
    }

    public function customers(): void
    {
        $customers = Db::all(
            "SELECT bc.*, u.name, u.email, u.created_at AS joined,
                (SELECT COUNT(*) FROM workspaces w WHERE w.created_by = u.id) AS workspaces,
                (SELECT COUNT(*) FROM accounts a JOIN workspaces w ON w.id = a.workspace_id WHERE w.created_by = u.id) AS accounts
             FROM billing_customers bc JOIN users u ON u.id = bc.user_id ORDER BY bc.created_at DESC"
        );
        $mrr = 0.0;
        foreach ($customers as $c) {
            if (in_array($c['status'], ['active', 'past_due'], true)) {
                $mrr += (float) preg_replace('/[^\d.]/', '', (string) (Cloud::plan($c['plan'])['price'] ?? '0'));
            }
        }
        view('cloud/admin_customers', [
            'title' => 'Customers',
            'customers' => $customers,
            'orders' => Db::all('SELECT * FROM orders ORDER BY created_at DESC LIMIT 200'),
            'leads' => Db::all('SELECT * FROM leads ORDER BY handled_at IS NOT NULL, created_at DESC LIMIT 200'),
            'mrr' => $mrr,
            'counts' => array_count_values(array_column($customers, 'status')),
            'plans' => Cloud::plans(false),
        ]);
    }

    public function updateCustomer(string $id): void
    {
        $c = Cloud::customer((int) $id);
        if (!$c) {
            abort(404);
        }
        $row = ['updated_at' => now()];
        if (isset(Cloud::plans(false)[(string) input('plan')])) {
            $row['plan'] = (string) input('plan');
        }
        if (isset(Cloud::STATUSES[(string) input('status')])) {
            $row['status'] = (string) input('status');
        }
        $extend = (int) input('extend_days', 0);
        if ($extend > 0) {
            $base = max(time(), strtotime(($c['trial_ends_at'] ?? now()) . ' UTC'));
            $row['trial_ends_at'] = gmdate('Y-m-d H:i:s', $base + $extend * 86400);
            $row['status'] = 'trialing';
            $row['reminded_at'] = null;
        }
        Db::update('billing_customers', $row, 'user_id = ?', [$c['user_id']]);
        flash('success', 'Customer updated.');
        redirect('/admin/customers');
    }

    public function updateOrder(string $id): void
    {
        if (in_array(input('status'), ['paid', 'active', 'done', 'canceled'], true)) {
            Db::update('orders', ['status' => input('status'), 'updated_at' => now()], 'id = ?', [(int) $id]);
        }
        redirect('/admin/customers', [], '#orders');
    }

    public function handleLead(string $id): void
    {
        Db::update('leads', ['handled_at' => input('undo') ? null : now()], 'id = ?', [(int) $id]);
        redirect('/admin/customers', [], '#leads');
    }
}

}

