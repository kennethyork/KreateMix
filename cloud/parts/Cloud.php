/**
 * KreateMix Cloud: run KreateMix as a paid hosted service.
 * On by default in this edition; the instance admin can switch it off under Hosted service.
 *
 * Customers sign up, get a free trial, then pay monthly through Stripe. Each plan limits how many workspaces,
 * social accounts and team members a customer can have. The instance admin is never limited.
 * The same Stripe account also sells the one-off setup service and the support plan.
 */
final class Cloud
{
    /** 'cloud' in KreateMix Cloud (the hosted, paid edition); the free self-hosted edition ships a stub with 'oss'. */
    public const EDITION = 'cloud';

    public const PLAN_KEYS = ['solo', 'team', 'agency'];

    private const DEFAULT_PLANS = [
        'solo' => ['name' => 'Solo', 'price' => '$12', 'blurb' => 'One brand, all features.', 'workspaces' => 1, 'accounts' => 10, 'members' => 2, 'stripe_price' => '', 'enabled' => 1],
        'team' => ['name' => 'Team', 'price' => '$29', 'blurb' => 'A few brands and a small team.', 'workspaces' => 5, 'accounts' => 50, 'members' => 10, 'stripe_price' => '', 'enabled' => 1],
        'agency' => ['name' => 'Agency', 'price' => '$59', 'blurb' => 'Clients, approvals and white-label reports.', 'workspaces' => 25, 'accounts' => 200, 'members' => 0, 'stripe_price' => '', 'enabled' => 1],
    ];

    public const STATUSES = [
        'trialing' => 'Free trial',
        'active' => 'Active',
        'past_due' => 'Payment failed',
        'canceled' => 'Canceled',
        'comp' => 'Complimentary',
    ];

    public static function settings(): array
    {
        $s = Settings::get('cloud');
        $plans = [];
        foreach (self::DEFAULT_PLANS as $key => $def) {
            $plans[$key] = array_merge($def, (array) ($s['plans'][$key] ?? []));
        }
        return array_merge([
            'enabled' => 1,
            'signup_open' => 1,
            'company' => '',
            'support_email' => '',
            'trial_days' => 7,
            'stripe_secret' => '',
            'stripe_webhook_secret' => '',
            'setup_enabled' => 1,
            'setup_price' => '$149',
            'setup_stripe_price' => '',
            'setup_description' => 'We install KreateMix on your server, set up your platform developer apps (Meta, LinkedIn, TikTok, X, Google) and walk you through app review. Done within a week.',
            'support_enabled' => 1,
            'support_price' => '$149 / year',
            'support_stripe_price' => '',
            'support_description' => 'Priority email help for your self-hosted KreateMix, update installs and a yearly health check.',
            'managed_enabled' => 1,
            'managed_description' => 'Rather not do it yourself? We plan, write and publish your posts, answer comments and send you a monthly report.',
            'source_url' => 'https://github.com/kennethyork/KreateMix',
        ], $s, ['plans' => $plans]);
    }

    public static function enabled(): bool
    {
        return (bool) (self::settings()['enabled'] ?? false);
    }

    public static function company(): string
    {
        return trim((string) self::settings()['company']) ?: (string) Config::get('app_name', 'KreateMix');
    }

    public static function plans(bool $onlyEnabled = true): array
    {
        $plans = self::settings()['plans'];
        return $onlyEnabled ? array_filter($plans, fn($p) => !empty($p['enabled'])) : $plans;
    }

    public static function plan(?string $key): ?array
    {
        return $key !== null ? (self::settings()['plans'][$key] ?? null) : null;
    }

    public static function trialDays(): int
    {
        return max(0, (int) self::settings()['trial_days']);
    }

    /* ---------- Customers ---------- */

    public static function customer(int $userId): ?array
    {
        return Db::one('SELECT * FROM billing_customers WHERE user_id = ?', [$userId]);
    }

    /** Starts a customer on a plan with a free trial. */
    public static function startTrial(int $userId, string $plan): void
    {
        Db::query(
            'INSERT INTO billing_customers (user_id, plan, status, trial_ends_at, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(user_id) DO NOTHING',
            [$userId, $plan, 'trialing', gmdate('Y-m-d H:i:s', time() + self::trialDays() * 86400), now(), now()]
        );
    }

    public static function isActive(?array $c): bool
    {
        if (!$c) {
            return false;
        }
        return match ($c['status']) {
            'active', 'comp' => true,
            // Stripe retries failed payments for a while; keep things running for up to 14 days and show a warning.
            'past_due' => (time() - self::pastDueSince((int) $c['user_id'])) < 14 * 86400,
            'trialing' => $c['trial_ends_at'] === null || $c['trial_ends_at'] > now() || !empty($c['stripe_subscription_id']),
            default => false,
        };
    }

    private static function pastDueSince(int $userId): int
    {
        $t = kv_get('billing.past_due:' . $userId);
        if (!is_int($t)) {
            kv_set('billing.past_due:' . $userId, $t = time());
        }
        return $t;
    }

    public static function trialDaysLeft(array $c): ?int
    {
        if ($c['status'] !== 'trialing' || !$c['trial_ends_at']) {
            return null;
        }
        return max(0, (int) ceil((strtotime($c['trial_ends_at'] . ' UTC') - time()) / 86400));
    }

    /** Who pays for a workspace: the user who created it. */
    public static function ownerOf(int $workspaceId): ?int
    {
        $id = Db::value('SELECT created_by FROM workspaces WHERE id = ?', [$workspaceId]);
        return $id !== null ? (int) $id : null;
    }

    private static function isInstanceAdmin(?int $userId): bool
    {
        return $userId !== null && (bool) Db::value('SELECT is_admin FROM users WHERE id = ?', [$userId]);
    }

    /** Can this workspace be used (and publish)? Always yes when hosted mode is off. */
    public static function workspaceActive(int $workspaceId): bool
    {
        if (!self::enabled()) {
            return true;
        }
        $owner = self::ownerOf($workspaceId);
        if ($owner === null) {
            // The owner's account was deleted: nobody pays for it, so it stays paused. An admin can reassign it.
            return false;
        }
        if (self::isInstanceAdmin($owner)) {
            return true;
        }
        $c = self::customer($owner);
        return self::isActive($c) && self::overLimits($owner) === [];
    }

    /** What the owner uses beyond their plan (e.g. after switching to a smaller plan): [what => [used, max]]. */
    public static function overLimits(int $userId): array
    {
        $c = self::customer($userId);
        $plan = self::plan($c['plan'] ?? null);
        if (!$c || !$plan || $c['status'] === 'comp' || self::isInstanceAdmin($userId)) {
            return [];
        }
        $over = [];
        foreach (self::usage($userId) as $what => $used) {
            $max = (int) ($plan[$what] ?? 0);
            if ($max > 0 && $used > $max) {
                $over[$what] = [$used, $max];
            }
        }
        return $over;
    }

    /** Before an admin deletes a user: stop their subscription so a deleted customer isn't billed. */
    public static function userDeleting(int $userId): void
    {
        $c = self::customer($userId);
        if (!empty($c['stripe_subscription_id']) && self::stripeReady()) {
            try {
                self::stripe('DELETE', 'subscriptions/' . $c['stripe_subscription_id']);
            } catch (\Throwable $e) {
                log_event('error', 'Couldn’t cancel the Stripe subscription of deleted user ' . $userId . ': ' . $e->getMessage());
            }
        }
    }

    /** Hosted-service customers (anyone with a billing record) can make their own workspaces. */
    public static function canCreateWorkspaces(int $userId): bool
    {
        return self::enabled() && self::customer($userId) !== null;
    }

    public static function usage(int $userId): array
    {
        $ws = array_map('intval', array_column(Db::all('SELECT id FROM workspaces WHERE created_by = ?', [$userId]), 'id'));
        if (!$ws) {
            return ['workspaces' => 0, 'accounts' => 0, 'members' => 0];
        }
        $in = implode(',', $ws);
        return [
            'workspaces' => count($ws),
            'accounts' => (int) Db::value("SELECT COUNT(*) FROM accounts WHERE workspace_id IN ($in)"),
            // People other than the owner, counted once even if they're in several workspaces; pending invites count too.
            'members' => (int) Db::value(
                "SELECT COUNT(*) FROM (SELECT user_id AS k FROM workspace_user WHERE workspace_id IN ($in) AND user_id != ?
                 UNION SELECT LOWER(email) FROM invitations WHERE workspace_id IN ($in) AND accepted_at IS NULL)",
                [$userId]
            ),
        ];
    }

    /**
     * Null when the owner of $workspaceId (or $userId for new workspaces) may add $add more of $what;
     * otherwise a message explaining the plan limit.
     */
    public static function limitMessage(string $what, ?int $workspaceId = null, ?int $userId = null, int $add = 1): ?string
    {
        if (!self::enabled()) {
            return null;
        }
        $owner = $userId ?? ($workspaceId ? self::ownerOf($workspaceId) : null);
        if ($owner === null) {
            return 'This workspace has no owner with a plan. Ask the instance admin to reassign it.';
        }
        if (self::isInstanceAdmin($owner)) {
            return null;
        }
        $c = self::customer($owner);
        $plan = self::plan($c['plan'] ?? null);
        if (!$c || !$plan) {
            return 'Choose a plan to continue.';
        }
        if ($c['status'] === 'comp') {
            return null;
        }
        $max = (int) ($plan[$what] ?? 0);
        if ($max <= 0) {
            return null; // unlimited
        }
        $used = self::usage($owner)[$what] ?? 0;
        if ($used + $add <= $max) {
            return null;
        }
        $label = ['workspaces' => 'workspace', 'accounts' => 'social account', 'members' => 'team member'][$what] ?? $what;
        return sprintf('The %s plan includes %d %s%s. Upgrade under Billing to add more.', $plan['name'], $max, $label, $max === 1 ? '' : 's');
    }

    /* ---------- Stripe ---------- */

    public static function stripeReady(): bool
    {
        return self::settings()['stripe_secret'] !== '';
    }

    /** Calls the Stripe API (form-encoded, as Stripe expects). Throws with Stripe's message on errors. */
    public static function stripe(string $method, string $path, array $params = []): array
    {
        $secret = (string) self::settings()['stripe_secret'];
        if ($secret === '') {
            throw new \RuntimeException('Stripe isn’t set up yet.');
        }
        $opts = ['headers' => ['Authorization' => 'Bearer ' . $secret, 'Stripe-Version' => '2024-06-20'], 'timeout' => 30];
        if ($method === 'GET') {
            $opts['query'] = $params;
        } else {
            $opts['form'] = $params;
        }
        // stripe_api_base in the settings file points at stripe-mock or another test server.
        $base = rtrim((string) Config::get('stripe_api_base', 'https://api.stripe.com'), '/');
        $opts['allow_private'] = Config::get('stripe_api_base') !== null; // a local test server, set by hand in the settings file
        $res = Http::request($method, $base . '/v1/' . ltrim($path, '/'), $opts);
        if ($res['status'] >= 400) {
            throw new \RuntimeException('Stripe: ' . ($res['json']['error']['message'] ?? 'HTTP ' . $res['status']));
        }
        return $res['json'];
    }

    /** A Stripe Checkout page for a plan subscription. Any trial time left carries over. */
    public static function planCheckoutUrl(int $userId, string $plan): string
    {
        $p = self::plans()[$plan] ?? null;
        if (!$p || empty($p['stripe_price'])) {
            throw new \RuntimeException('This plan isn’t available for purchase yet.');
        }
        $user = Db::one('SELECT * FROM users WHERE id = ?', [$userId]);
        $c = self::customer($userId);
        if (!empty($c['stripe_subscription_id']) && in_array($c['status'], ['active', 'trialing', 'past_due'], true)) {
            throw new \RuntimeException('You already have a subscription. Use Manage billing to change plans.');
        }
        $params = [
            'mode' => 'subscription',
            'line_items' => [['price' => $p['stripe_price'], 'quantity' => 1]],
            'client_reference_id' => (string) $userId,
            'metadata' => ['kind' => 'plan', 'plan' => $plan, 'user_id' => (string) $userId],
            'subscription_data' => ['metadata' => ['kind' => 'plan', 'plan' => $plan, 'user_id' => (string) $userId]],
            'allow_promotion_codes' => 'true',
            'success_url' => abs_url('billing') . '?checkout=success',
            'cancel_url' => abs_url('billing') . '?checkout=canceled',
        ];
        if (!empty($c['stripe_customer_id'])) {
            $params['customer'] = $c['stripe_customer_id'];
        } else {
            $params['customer_email'] = $user['email'];
        }
        // Stripe needs a trial end at least 48 hours away.
        if ($c && $c['status'] === 'trialing' && $c['trial_ends_at'] && strtotime($c['trial_ends_at'] . ' UTC') > time() + 2 * 86400 + 600) {
            $params['subscription_data']['trial_end'] = (string) strtotime($c['trial_ends_at'] . ' UTC');
        }
        return (string) self::stripe('POST', 'checkout/sessions', $params)['url'];
    }

    /** Checkout for the setup service (one payment) or support plan (subscription). No account needed. */
    public static function productCheckoutUrl(string $product): string
    {
        $s = self::settings();
        $price = $product === 'setup' ? $s['setup_stripe_price'] : ($product === 'support' ? $s['support_stripe_price'] : '');
        if ($price === '' || empty($s[$product . '_enabled'])) {
            throw new \RuntimeException('This isn’t available for purchase yet.');
        }
        $params = [
            'mode' => $product === 'setup' ? 'payment' : 'subscription',
            'line_items' => [['price' => $price, 'quantity' => 1]],
            'metadata' => ['kind' => $product],
            'success_url' => abs_url('thanks/' . $product) . '?session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => abs_url('') . '#services',
        ];
        if ($product === 'support') {
            $params['subscription_data'] = ['metadata' => ['kind' => 'support']];
        } else {
            $params['customer_creation'] = 'always';
        }
        return (string) self::stripe('POST', 'checkout/sessions', $params)['url'];
    }

    public static function portalUrl(int $userId): string
    {
        $c = self::customer($userId);
        if (empty($c['stripe_customer_id'])) {
            throw new \RuntimeException('There’s no billing account yet. Choose a plan first.');
        }
        return (string) self::stripe('POST', 'billing_portal/sessions', ['customer' => $c['stripe_customer_id'], 'return_url' => abs_url('billing')])['url'];
    }

    /** Checks the Stripe-Signature header (t=timestamp, v1=HMAC-SHA256 of "t.payload"). */
    public static function verifySignature(string $payload, string $header, string $secret, int $tolerance = 300): bool
    {
        if ($secret === '' || $header === '') {
            return false;
        }
        $t = null;
        $sigs = [];
        foreach (explode(',', $header) as $part) {
            [$k, $v] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($k === 't') {
                $t = (int) $v;
            } elseif ($k === 'v1') {
                $sigs[] = $v;
            }
        }
        if (!$t || abs(time() - $t) > $tolerance) {
            return false;
        }
        $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
        foreach ($sigs as $sig) {
            if (hash_equals($expected, $sig)) {
                return true;
            }
        }
        return false;
    }

    /** Applies a Stripe webhook event. Returns false when it was already handled. */
    public static function handleEvent(array $event): bool
    {
        $id = (string) ($event['id'] ?? '');
        if ($id === '' || Db::value('SELECT 1 FROM stripe_events WHERE id = ?', [$id])) {
            return false;
        }
        // Recorded and applied together: if applying fails, Stripe's retry gets a second chance.
        Db::transaction(function () use ($event, $id) {
            Db::insert('stripe_events', ['id' => $id, 'type' => (string) ($event['type'] ?? ''), 'received_at' => now()]);
            self::applyEvent($event);
        });
        return true;
    }

    private static function applyEvent(array $event): void
    {
        $o = $event['data']['object'] ?? [];
        if (str_starts_with((string) ($event['type'] ?? ''), 'customer.subscription.') && !empty($o['id']) && self::stripeReady()) {
            // Events can arrive out of order; use the subscription as it is now.
            try {
                $o = self::stripe('GET', 'subscriptions/' . $o['id']);
            } catch (\Throwable $e) {
                log_event('warning', 'Using the webhook copy of subscription ' . $o['id'] . ': ' . $e->getMessage());
            }
        }
        switch ($event['type'] ?? '') {
            case 'checkout.session.completed':
                self::checkoutCompleted($o);
                break;
            case 'customer.subscription.created':
            case 'customer.subscription.updated':
            case 'customer.subscription.deleted':
                self::syncSubscription($o);
                break;
            case 'invoice.payment_failed':
                if (!empty($o['subscription'])) {
                    $c = Db::one('SELECT * FROM billing_customers WHERE stripe_subscription_id = ?', [(string) $o['subscription']]);
                    if ($c) {
                        Db::update('billing_customers', ['status' => $c['status'] === 'comp' ? 'comp' : 'past_due', 'updated_at' => now()], 'user_id = ?', [$c['user_id']]);
                        self::trackPastDue((int) $c['user_id'], 'past_due');
                        Notify::users([(int) $c['user_id']], 'failed', 'Payment failed', 'Update your card under Billing to keep publishing.', '/billing');
                    }
                }
                break;
        }
    }

    private static function checkoutCompleted(array $s): void
    {
        $kind = $s['metadata']['kind'] ?? '';
        if ($kind === 'plan') {
            $userId = (int) ($s['client_reference_id'] ?? $s['metadata']['user_id'] ?? 0);
            if (!$userId || !Db::value('SELECT 1 FROM users WHERE id = ?', [$userId])) {
                return;
            }
            $plan = self::plan($s['metadata']['plan'] ?? null) ? $s['metadata']['plan'] : 'solo';
            self::startTrial($userId, $plan);
            $current = self::customer($userId);
            Db::update('billing_customers', [
                'plan' => $plan,
                'status' => ($current['status'] ?? '') === 'comp' ? 'comp' : 'active',
                'stripe_customer_id' => (string) ($s['customer'] ?? ''),
                'stripe_subscription_id' => (string) ($s['subscription'] ?? ''),
                'updated_at' => now(),
            ], 'user_id = ?', [$userId]);
            // The subscription itself says whether it's trialing; fetch it to be exact.
            if (!empty($s['subscription']) && self::stripeReady()) {
                try {
                    self::syncSubscription(self::stripe('GET', 'subscriptions/' . $s['subscription']));
                } catch (\Throwable $e) {
                    log_event('error', 'Stripe subscription sync failed: ' . $e->getMessage());
                }
            }
            $u = Db::one('SELECT name, email FROM users WHERE id = ?', [$userId]);
            Notify::users(self::adminIds(), 'business', 'New subscription', "{$u['name']} ({$u['email']}) subscribed to " . (self::plan($plan)['name'] ?? $plan) . '.', '/admin/customers');
            return;
        }
        if ($kind === 'setup' || $kind === 'support') {
            $email = (string) ($s['customer_details']['email'] ?? $s['customer_email'] ?? '');
            $name = (string) ($s['customer_details']['name'] ?? '');
            Db::query(
                'INSERT INTO orders (kind, email, name, status, stripe_session_id, stripe_customer_id, stripe_subscription_id, amount_total, currency, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON CONFLICT(stripe_session_id) DO NOTHING',
                [$kind, $email, $name, $kind === 'support' ? 'active' : 'paid', (string) ($s['id'] ?? ''), (string) ($s['customer'] ?? ''), (string) ($s['subscription'] ?? ''),
                 (int) ($s['amount_total'] ?? 0), strtoupper((string) ($s['currency'] ?? '')), now(), now()]
            );
            $label = $kind === 'setup' ? 'Setup service' : 'Support plan';
            Notify::users(self::adminIds(), 'business', "New order: $label", trim("$name $email"), '/admin/customers');
            self::mailAdmins("New order: $label", '<p>' . e(trim("$name <$email>")) . ' bought the ' . e(strtolower($label)) . '.</p><p><a href="' . e(abs_url('admin/customers')) . '">Open orders</a></p>');
        }
    }

    public static function syncSubscription(array $sub): void
    {
        $subId = (string) ($sub['id'] ?? '');
        if ($subId === '') {
            return;
        }
        $kind = $sub['metadata']['kind'] ?? null;
        $status = (string) ($sub['status'] ?? '');
        $periodEnd = isset($sub['current_period_end']) ? gmdate('Y-m-d H:i:s', (int) $sub['current_period_end']) : null;

        $order = Db::one('SELECT * FROM orders WHERE stripe_subscription_id = ?', [$subId]);
        if ($kind === 'support' || $order) {
            if ($order) {
                Db::update('orders', ['status' => in_array($status, ['active', 'trialing', 'past_due'], true) ? 'active' : 'canceled', 'updated_at' => now()], 'id = ?', [$order['id']]);
            }
            return;
        }

        $c = Db::one('SELECT * FROM billing_customers WHERE stripe_subscription_id = ?', [$subId]);
        if (!$c && !empty($sub['metadata']['user_id'])) {
            $c = self::customer((int) $sub['metadata']['user_id']);
        }
        if (!$c && !empty($sub['customer'])) {
            $c = Db::one('SELECT * FROM billing_customers WHERE stripe_customer_id = ?', [(string) $sub['customer']]);
        }
        if (!$c) {
            return;
        }
        $mapped = match ($status) {
            'trialing' => 'trialing',
            'active' => 'active',
            'past_due' => 'past_due',
            default => 'canceled', // canceled, unpaid (retries ran out), incomplete_expired, paused
        };
        if ($status === 'incomplete') {
            return; // first payment still being confirmed
        }
        $row = [
            'status' => $c['status'] === 'comp' ? 'comp' : $mapped,
            'stripe_subscription_id' => $subId,
            'stripe_customer_id' => (string) ($sub['customer'] ?? $c['stripe_customer_id']),
            'current_period_end' => $periodEnd,
            'cancel_at_period_end' => !empty($sub['cancel_at_period_end']) ? 1 : 0,
            'updated_at' => now(),
        ];
        if ($mapped === 'trialing' && !empty($sub['trial_end'])) {
            $row['trial_ends_at'] = gmdate('Y-m-d H:i:s', (int) $sub['trial_end']);
        }
        // The plan follows whichever price the subscription is on (customers can switch in the billing portal).
        $priceId = $sub['items']['data'][0]['price']['id'] ?? null;
        foreach (self::plans(false) as $key => $p) {
            if ($priceId && $p['stripe_price'] === $priceId) {
                $row['plan'] = $key;
            }
        }
        Db::update('billing_customers', $row, 'user_id = ?', [$c['user_id']]);
        self::trackPastDue((int) $c['user_id'], $row['status']);
    }

    /** Remembers when a customer's payment started failing, for the 14-day grace period. */
    private static function trackPastDue(int $userId, string $status): void
    {
        if ($status !== 'past_due') {
            kv_delete('billing.past_due:' . $userId);
        } elseif (kv_get('billing.past_due:' . $userId) === null) {
            kv_set('billing.past_due:' . $userId, time());
        }
    }

    /* ---------- Reminders ---------- */

    /** Daily: email customers whose trial ends in 3 days or less and who haven't added a card. */
    public static function remindDue(): int
    {
        if (!self::enabled()) {
            return 0;
        }
        $rows = Db::all(
            "SELECT bc.*, u.name, u.email FROM billing_customers bc JOIN users u ON u.id = bc.user_id
             WHERE bc.status = 'trialing' AND (bc.stripe_subscription_id IS NULL OR bc.stripe_subscription_id = '')
             AND bc.reminded_at IS NULL AND bc.trial_ends_at <= ? AND bc.trial_ends_at > ?",
            [gmdate('Y-m-d H:i:s', time() + 3 * 86400), now()]
        );
        $n = 0;
        foreach ($rows as $r) {
            Db::update('billing_customers', ['reminded_at' => now()], 'user_id = ?', [$r['user_id']]);
            $days = self::trialDaysLeft($r);
            Notify::users([(int) $r['user_id']], 'failed', 'Your trial ends soon', "Your free trial ends in $days day" . ($days === 1 ? '' : 's') . '. Choose a plan to keep publishing.', '/billing');
            try {
                if (Settings::has('smtp', ['host'])) {
                    Mailer::send([$r['email']], 'Your ' . self::company() . ' trial ends soon', '<p>Hi ' . e($r['name']) . ',</p><p>Your free trial ends in ' . $days . ' day' . ($days === 1 ? '' : 's') . '. Scheduled posts stop publishing when it ends.</p><p><a href="' . e(abs_url('billing')) . '">Choose a plan</a></p>');
                }
            } catch (\Throwable $e) {
                log_event('error', 'Trial reminder email failed: ' . $e->getMessage());
            }
            $n++;
        }
        return $n;
    }

    public static function adminIds(): array
    {
        return array_map('intval', array_column(Db::all('SELECT id FROM users WHERE is_admin = 1'), 'id'));
    }

    public static function mailAdmins(string $subject, string $html): void
    {
        try {
            if (!Settings::has('smtp', ['host'])) {
                return;
            }
            $to = array_filter([self::settings()['support_email'] ?: null, ...array_column(Db::all('SELECT email FROM users WHERE is_admin = 1'), 'email')]);
            if ($to) {
                Mailer::send(array_values(array_unique($to)), $subject, $html);
            }
        } catch (\Throwable $e) {
            log_event('error', 'Admin email failed: ' . $e->getMessage());
        }
    }
}
