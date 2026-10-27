namespace {
use App\Cloud;

// ---- view: cloud/admin_customers ----
function mc_view_cloud_admin_customers(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php


$active = ($counts['active'] ?? 0) + ($counts['past_due'] ?? 0);
$fmtMoney = fn(int $cents, string $cur) => $cents ? number_format($cents / 100, 2) . ' ' . $cur : '';
?>
<div class="page-head">
    <div>
        <h1>Customers</h1>
        <p class="muted">Hosted-service sign-ups, orders and enquiries.</p>
    </div>
    <a class="btn" href="<?= e(url('/admin/cloud')) ?>"><?= icon('settings') ?> Settings</a>
</div>

<div class="stats">
    <div class="stat"><span class="stat-label">Customers</span><span class="stat-value"><?= count($customers) ?></span></div>
    <div class="stat"><span class="stat-label">On trial</span><span class="stat-value"><?= (int) ($counts['trialing'] ?? 0) ?></span></div>
    <div class="stat"><span class="stat-label">Paying</span><span class="stat-value"><?= $active ?></span></div>
    <div class="stat"><span class="stat-label">Monthly revenue (est.)</span><span class="stat-value">$<?= e(number_format($mrr, 0)) ?></span></div>
    <div class="stat"><span class="stat-label">Open enquiries</span><span class="stat-value"><?= count(array_filter($leads, fn($l) => !$l['handled_at'])) ?></span></div>
</div>

<section class="card">
    <h2>Customers</h2>
    <?php if (!$customers): ?>
        <p class="muted">No sign-ups yet. Share <a href="<?= e(url('/signup')) ?>"><?= e(abs_url('signup')) ?></a>.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table customers-table">
            <thead><tr><th>Customer</th><th>Plan</th><th>Status</th><th>Usage</th><th>Joined</th><th>Change</th></tr></thead>
            <?php foreach ($customers as $c): $days = Cloud::trialDaysLeft($c); ?>
                <tr>
                    <td><strong><?= e($c['name']) ?></strong><br><span class="muted small"><?= e($c['email']) ?></span></td>
                    <td><?= e($plans[$c['plan']]['name'] ?? $c['plan']) ?></td>
                    <td><span class="badge billing-<?= e($c['status']) ?>"><?= e(Cloud::STATUSES[$c['status']] ?? $c['status']) ?></span>
                        <div class="muted small"><?= $days !== null ? ($days ? $days . ' days left' : 'ends today') : ($c['current_period_end'] ? ($c['cancel_at_period_end'] ? 'cancels ' : 'renews ') . e(local_time($c['current_period_end'], 'M j')) : '') ?><?= $c['status'] === 'trialing' && $c['trial_ends_at'] && $c['trial_ends_at'] < now() && empty($c['stripe_subscription_id']) ? 'trial over' : '' ?></div></td>
                    <td class="small"><?= (int) $c['workspaces'] ?> brands · <?= (int) $c['accounts'] ?> accounts</td>
                    <td class="small muted"><?= e(local_time($c['joined'], 'M j, Y')) ?></td>
                    <td>
                        <form class="inline-form" method="post" action="<?= e(url('/admin/customers/' . $c['user_id'])) ?>">
                            <?= csrf_field() ?>
                            <select name="plan" aria-label="Plan"><?php foreach ($plans as $k => $p): ?><option value="<?= e($k) ?>" <?= $k === $c['plan'] ? 'selected' : '' ?>><?= e($p['name']) ?></option><?php endforeach; ?></select>
                            <select name="status" aria-label="Status"><?php foreach (Cloud::STATUSES as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === $c['status'] ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                            <select name="extend_days" aria-label="Extend trial"><option value="0">Trial: no change</option><option value="7">+7 days</option><option value="14">+14 days</option><option value="30">+30 days</option></select>
                            <button class="btn btn-sm" type="submit">Save</button>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <p class="muted small">Plan and status normally follow Stripe. Change them here for complimentary accounts or to fix something by hand; Stripe’s next update can override them unless the status is Complimentary.</p>
    <?php endif; ?>
</section>

<section class="card" id="orders">
    <h2>Orders</h2>
    <?php if (!$orders): ?>
        <p class="muted">No setup or support orders yet.</p>
    <?php else: ?>
    <div class="table-wrap">
        <table class="table">
            <thead><tr><th>Date</th><th>Order</th><th>Customer</th><th>Amount</th><th>Details</th><th>Status</th></tr></thead>
            <?php foreach ($orders as $o): $d = json_arr($o['details']); ?>
                <tr>
                    <td class="small muted"><?= e(local_time($o['created_at'], 'M j, Y')) ?></td>
                    <td><?= $o['kind'] === 'setup' ? 'Setup service' : 'Support plan' ?></td>
                    <td><?= e($o['name'] ?: '—') ?><br><span class="muted small"><?= $o['email'] ? '<a href="mailto:' . e($o['email']) . '">' . e($o['email']) . '</a>' : '' ?></span></td>
                    <td class="small"><?= e($fmtMoney((int) $o['amount_total'], (string) $o['currency'])) ?></td>
                    <td class="small"><?php if ($d): ?><?= e($d['domain'] ?? '') ?><?= !empty($d['hosting']) ? ' · ' . e($d['hosting']) : '' ?><?= !empty($d['platforms']) ? '<br>' . e(implode(', ', $d['platforms'])) : '' ?><?= !empty($d['notes']) ? '<br><span class="muted">' . e(str_limit($d['notes'], 140)) . '</span>' : '' ?><?php else: ?><span class="muted">Not sent yet</span><?php endif; ?></td>
                    <td>
                        <form class="inline-form" method="post" action="<?= e(url('/admin/orders/' . $o['id'])) ?>"><?= csrf_field() ?>
                            <select name="status" data-autosubmit aria-label="Order status"><?php foreach (['pending' => 'Awaiting payment', 'paid' => 'Paid, to do', 'active' => 'Active', 'done' => 'Done', 'canceled' => 'Canceled'] as $k => $l): ?><option value="<?= e($k) ?>" <?= $k === $o['status'] ? 'selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?></select>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        </table>
    </div>
    <?php endif; ?>
</section>

<section class="card" id="leads">
    <h2>Enquiries</h2>
    <?php if (!$leads): ?>
        <p class="muted">No enquiries yet. They come from the contact form on your site.</p>
    <?php else: ?>
        <div class="lead-list">
        <?php foreach ($leads as $l): ?>
            <article class="lead<?= $l['handled_at'] ? ' is-handled' : '' ?>">
                <div class="lead-head">
                    <strong><?= e($l['name']) ?></strong>
                    <a href="mailto:<?= e($l['email']) ?>"><?= e($l['email']) ?></a>
                    <?= $l['company'] ? '<span class="muted">· ' . e($l['company']) . '</span>' : '' ?>
                    <span class="badge"><?= e(['managed' => 'Done for you', 'setup' => 'Setup', 'support' => 'Support', 'other' => 'Other'][$l['kind']] ?? $l['kind']) ?></span>
                    <?= $l['budget'] ? '<span class="muted small">' . e($l['budget']) . '</span>' : '' ?>
                    <span class="grow"></span>
                    <span class="muted small"><?= e(local_time($l['created_at'], 'M j, g:i A')) ?></span>
                    <form method="post" action="<?= e(url('/admin/leads/' . $l['id'])) ?>"><?= csrf_field() ?><?php if ($l['handled_at']): ?><input type="hidden" name="undo" value="1"><?php endif; ?><button class="btn btn-sm" type="submit"><?= $l['handled_at'] ? 'Reopen' : 'Mark handled' ?></button></form>
                </div>
                <?php if ($l['message']): ?><p class="lead-msg"><?= nl2br(e($l['message'])) ?></p><?php endif; ?>
            </article>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php
}

}

namespace {

// ---- view: cloud/admin_settings ----
function mc_view_cloud_admin_settings(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php
$webhook = abs_url('stripe/webhook');
?>
<div class="page-head">
    <div>
        <h1>Hosted service</h1>
        <p class="muted">Sell KreateMix as a hosted service: a public site with pricing, sign-ups with a free trial, and Stripe billing.</p>
    </div>
    <?php if ($cloud['enabled']): ?><a class="btn" href="<?= e(url('/admin/customers')) ?>"><?= icon('card') ?> Customers</a><?php endif; ?>
</div>

<form method="post" action="<?= e(url('/admin/cloud')) ?>" class="cloud-settings">
    <?= csrf_field() ?>

    <section class="card">
        <h2>Hosted mode</h2>
        <label class="check"><input type="checkbox" name="enabled" value="1" <?= $cloud['enabled'] ? 'checked' : '' ?>> <span><strong>Run as a hosted service</strong><br><span class="muted small">Visitors who aren’t signed in see your sales page at <?= e(abs_url('')) ?>. Customers are limited by their plan and pause when their trial or subscription ends. You (instance admins) are never limited.</span></span></label>
        <label class="check"><input type="checkbox" name="signup_open" value="1" <?= $cloud['signup_open'] ? 'checked' : '' ?>> <span>Allow new sign-ups</span></label>
        <div class="field-row">
            <label class="field"><span>Business name</span><input type="text" name="company" value="<?= e($cloud['company']) ?>" placeholder="<?= e(App\Config::get('app_name', 'KreateMix')) ?>"></label>
            <label class="field"><span>Support email</span><input type="email" name="support_email" value="<?= e($cloud['support_email']) ?>" placeholder="hello@example.com"></label>
            <label class="field" style="max-width: 160px"><span>Free trial (days)</span><input type="number" name="trial_days" min="0" max="90" value="<?= (int) $cloud['trial_days'] ?>"></label>
        </div>
        <label class="field"><span>Source code link</span><input type="url" name="source_url" value="<?= e($cloud['source_url']) ?>"><small class="hint">The AGPL license asks you to offer your users the source code, including your changes. This link appears in the app and on your site.</small></label>
    </section>

    <section class="card">
        <h2>Stripe</h2>
        <p class="muted small">Payments, invoices, card updates and cancellations happen in Stripe, so no card details touch your server.</p>
        <ol class="steps small">
            <li>In the Stripe dashboard, create a product for each plan with a monthly price, plus one for the setup service (one-time) and the support plan (yearly). Copy each price ID (it starts with <code>price_</code>) into the fields below.</li>
            <li>Under Developers → API keys, copy your secret key.</li>
            <li>Under Developers → Webhooks, add this endpoint: <code class="copy" data-copy><?= e($webhook) ?></code> with the events <code>checkout.session.completed</code>, <code>customer.subscription.created</code>, <code>customer.subscription.updated</code>, <code>customer.subscription.deleted</code> and <code>invoice.payment_failed</code>. Copy its signing secret.</li>
            <li>Under Settings → Billing → Customer portal, turn on cancelling and switching between your plan prices.</li>
        </ol>
        <div class="field-row">
            <label class="field"><span>Secret key</span><input type="password" name="stripe_secret" autocomplete="off" placeholder="<?= $cloud['stripe_secret'] !== '' ? '•••••••• (saved' . (str_contains($cloud['stripe_secret'], '_test_') ? ', test mode' : '') . ')' : 'sk_live_…' ?>"></label>
            <label class="field"><span>Webhook signing secret</span><input type="password" name="stripe_webhook_secret" autocomplete="off" placeholder="<?= $cloud['stripe_webhook_secret'] !== '' ? '•••••••• (saved)' : 'whsec_…' ?>"></label>
        </div>
        <?php if ($cloud['stripe_secret'] !== ''): ?><label class="check"><input type="checkbox" name="remove_stripe" value="1"> <span class="small">Remove the saved Stripe keys</span></label><?php endif; ?>
    </section>

    <section class="card">
        <h2>Plans</h2>
        <p class="muted small">Every plan includes every feature; plans differ by limits. 0 means unlimited.</p>
        <div class="plan-edit">
            <?php foreach ($cloud['plans'] as $key => $p): $n = 'plans[' . $key . ']'; ?>
                <fieldset class="plan-edit-col">
                    <label class="check"><input type="checkbox" name="<?= $n ?>[enabled]" value="1" <?= $p['enabled'] ? 'checked' : '' ?>> <span><strong>Offer this plan</strong></span></label>
                    <label class="field"><span>Name</span><input type="text" name="<?= $n ?>[name]" value="<?= e($p['name']) ?>"></label>
                    <label class="field"><span>Price shown</span><input type="text" name="<?= $n ?>[price]" value="<?= e($p['price']) ?>"></label>
                    <label class="field"><span>One-line description</span><input type="text" name="<?= $n ?>[blurb]" value="<?= e($p['blurb']) ?>"></label>
                    <label class="field"><span>Stripe price ID</span><input type="text" name="<?= $n ?>[stripe_price]" value="<?= e($p['stripe_price']) ?>" placeholder="price_…"></label>
                    <div class="field-row">
                        <label class="field"><span>Brands</span><input type="number" min="0" name="<?= $n ?>[workspaces]" value="<?= (int) $p['workspaces'] ?>"></label>
                        <label class="field"><span>Accounts</span><input type="number" min="0" name="<?= $n ?>[accounts]" value="<?= (int) $p['accounts'] ?>"></label>
                        <label class="field"><span>Team</span><input type="number" min="0" name="<?= $n ?>[members]" value="<?= (int) $p['members'] ?>"></label>
                    </div>
                </fieldset>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="card">
        <h2>Services</h2>
        <div class="plan-edit">
            <fieldset class="plan-edit-col">
                <label class="check"><input type="checkbox" name="setup_enabled" value="1" <?= $cloud['setup_enabled'] ? 'checked' : '' ?>> <span><strong>Setup service</strong></span></label>
                <label class="field"><span>Price shown</span><input type="text" name="setup_price" value="<?= e($cloud['setup_price']) ?>"></label>
                <label class="field"><span>Stripe price ID (one-time)</span><input type="text" name="setup_stripe_price" value="<?= e($cloud['setup_stripe_price']) ?>" placeholder="price_…"></label>
                <label class="field"><span>Description</span><textarea name="setup_description" rows="4"><?= e($cloud['setup_description']) ?></textarea></label>
            </fieldset>
            <fieldset class="plan-edit-col">
                <label class="check"><input type="checkbox" name="support_enabled" value="1" <?= $cloud['support_enabled'] ? 'checked' : '' ?>> <span><strong>Support plan</strong></span></label>
                <label class="field"><span>Price shown</span><input type="text" name="support_price" value="<?= e($cloud['support_price']) ?>"></label>
                <label class="field"><span>Stripe price ID (recurring)</span><input type="text" name="support_stripe_price" value="<?= e($cloud['support_stripe_price']) ?>" placeholder="price_…"></label>
                <label class="field"><span>Description</span><textarea name="support_description" rows="4"><?= e($cloud['support_description']) ?></textarea></label>
            </fieldset>
            <fieldset class="plan-edit-col">
                <label class="check"><input type="checkbox" name="managed_enabled" value="1" <?= $cloud['managed_enabled'] ? 'checked' : '' ?>> <span><strong>Done for you</strong></span></label>
                <label class="field"><span>Description</span><textarea name="managed_description" rows="4"><?= e($cloud['managed_description']) ?></textarea></label>
                <p class="muted small">Enquiries arrive under Customers → Leads. Without a Stripe price, the setup and support offers show an enquiry button instead of “Buy”.</p>
            </fieldset>
        </div>
    </section>

    <section class="card">
        <h2>Legal pages</h2>
        <p class="muted small">Your terms, privacy policy and data deletion page are edited under <a href="<?= e(url('/admin/legal')) ?>">Legal pages</a>. The sign-up form links to them.</p>
    </section>

    <div class="form-actions sticky-actions"><button class="btn btn-primary" type="submit">Save</button></div>
</form>
<?php
}

}

namespace {
use App\Cloud;

// ---- view: cloud/billing ----
function mc_view_cloud_billing(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php


$plan = $customer ? Cloud::plan($customer['plan']) : null;
$daysLeft = $customer ? Cloud::trialDaysLeft($customer) : null;
$fmt = fn(?string $d) => $d ? local_time($d, 'M j, Y') : '';
?>
<div class="page-head">
    <div>
        <h1>Billing</h1>
        <p class="muted">Your plan, usage and payment details.</p>
    </div>
    <?php if ($isOwner && !empty($customer['stripe_customer_id']) && $stripe): ?>
        <form method="post" action="<?= e(url('/billing/portal')) ?>"><?= csrf_field() ?><button class="btn" type="submit"><?= icon('card') ?> Payment method & invoices</button></form>
    <?php endif; ?>
</div>

<?php if (!$isOwner): ?>
    <div class="notice <?= $active ? 'notice-info' : 'notice-warn' ?>"><?= icon($active ? 'card' : 'alert') ?>
        <div><?= $active
            ? 'This workspace is on ' . e($ownerName) . '’s plan. They manage billing.'
            : 'This workspace is paused because ' . e($ownerName) . '’s subscription isn’t active. Ask them to choose a plan under Billing.' ?></div>
    </div>
<?php elseif (!$customer): ?>
    <div class="notice notice-info"><?= icon('card') ?><div>You’re the instance admin, so no plan is needed.</div></div>
<?php else: ?>
    <?php if (!$active): ?>
        <div class="notice notice-warn"><?= icon('alert') ?><div><strong><?= $customer['status'] === 'canceled' ? 'Your subscription has ended.' : 'Your free trial has ended.' ?></strong> Scheduled posts are paused. Choose a plan below to pick up where you left off; nothing has been deleted.</div></div>
    <?php elseif ($customer['status'] === 'past_due'): ?>
        <div class="notice notice-warn"><?= icon('alert') ?><div><strong>Your last payment didn’t go through.</strong> Update your card under “Payment method & invoices” to keep publishing.</div></div>
    <?php endif; ?>

    <section class="card billing-summary">
        <div>
            <div class="muted small">Current plan</div>
            <div class="billing-plan"><?= e($plan['name'] ?? ucfirst($customer['plan'])) ?> <span class="badge billing-<?= e($customer['status']) ?>"><?= e(Cloud::STATUSES[$customer['status']] ?? $customer['status']) ?></span></div>
            <div class="muted small">
                <?php if ($customer['status'] === 'trialing' && $daysLeft !== null): ?>
                    <?= $daysLeft === 0 ? 'Trial ends today' : 'Trial ends ' . e($fmt($customer['trial_ends_at'])) . ' (' . $daysLeft . ' day' . ($daysLeft === 1 ? '' : 's') . ' left)' ?><?= !empty($customer['stripe_subscription_id']) ? ' · card on file, billing starts then' : '' ?>
                <?php elseif ($customer['cancel_at_period_end'] && $customer['current_period_end']): ?>
                    Cancels on <?= e($fmt($customer['current_period_end'])) ?>
                <?php elseif ($customer['current_period_end'] && in_array($customer['status'], ['active', 'past_due'], true)): ?>
                    Renews on <?= e($fmt($customer['current_period_end'])) ?>
                <?php elseif ($customer['status'] === 'comp'): ?>
                    Complimentary, on the house
                <?php endif; ?>
            </div>
        </div>
        <div class="usage">
            <?php foreach (['workspaces' => 'Brands', 'accounts' => 'Social accounts', 'members' => 'Team members'] as $k => $label):
                $max = (int) ($plan[$k] ?? 0); $used = (int) $usage[$k]; ?>
                <div class="usage-row">
                    <div class="usage-label"><span><?= e($label) ?></span><span class="muted"><?= $used ?><?= $max ? ' of ' . $max : '' ?></span></div>
                    <div class="usage-bar"><i style="width: <?= $max ? min(100, round($used / $max * 100)) : 4 ?>%"<?= $max && $used >= $max ? ' class="full"' : '' ?>></i></div>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <h2 class="section-title">Plans</h2>
    <?php if (!$stripe): ?>
        <div class="notice notice-info"><?= icon('card') ?><div>Online payment isn’t set up yet<?= $cloud['support_email'] ? '. Email <a href="mailto:' . e($cloud['support_email']) . '">' . e($cloud['support_email']) . '</a> to subscribe' : '' ?>.</div></div>
    <?php endif; ?>
    <div class="plan-cards">
        <?php foreach ($plans as $key => $p): $current = $key === $customer['plan'] && in_array($customer['status'], ['active', 'past_due', 'comp'], true); ?>
            <article class="card plan-card<?= $key === $customer['plan'] ? ' is-current' : '' ?>">
                <h3><?= e($p['name']) ?></h3>
                <p class="plan-price"><strong><?= e($p['price']) ?></strong> / month</p>
                <p class="muted small"><?= e($p['blurb']) ?></p>
                <ul class="mk-list">
                    <li><?= icon('check') ?><?= (int) $p['workspaces'] ?: 'Unlimited' ?> brand<?= (int) $p['workspaces'] === 1 ? '' : 's' ?></li>
                    <li><?= icon('check') ?><?= (int) $p['accounts'] ?: 'Unlimited' ?> social accounts</li>
                    <li><?= icon('check') ?><?= (int) $p['members'] ? 'You + ' . (int) $p['members'] . ' people' : 'Unlimited team' ?></li>
                </ul>
                <?php if ($current): ?>
                    <button class="btn btn-block" type="button" disabled>Current plan</button>
                <?php elseif (!empty($customer['stripe_subscription_id']) && in_array($customer['status'], ['active', 'past_due', 'trialing'], true)): ?>
                    <form method="post" action="<?= e(url('/billing/portal')) ?>"><?= csrf_field() ?><button class="btn btn-block" type="submit">Switch in billing portal</button></form>
                <?php elseif ($stripe && $p['stripe_price'] !== ''): ?>
                    <form method="post" action="<?= e(url('/billing/checkout')) ?>"><?= csrf_field() ?><input type="hidden" name="plan" value="<?= e($key) ?>"><button class="btn btn-primary btn-block" type="submit"><?= $key === $customer['plan'] ? 'Subscribe' : 'Choose ' . e($p['name']) ?></button></form>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>
    </div>
    <p class="muted small">Prices exclude any sales tax Stripe adds for your country. Cancel anytime from “Payment method & invoices”; you keep access until the end of the paid month.</p>
<?php endif; ?>
<?php
}

}

namespace {

// ---- view: cloud/landing ----
function mc_view_cloud_landing(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><?php
$trial = (int) $cloud['trial_days'];
$signup = !empty($cloud['signup_open']);
$networks = ['Instagram', 'Facebook', 'X', 'LinkedIn', 'TikTok', 'YouTube', 'Threads', 'Pinterest', 'Bluesky', 'Mastodon', 'Pixelfed', 'PeerTube', 'Google Business'];
$features = [
    ['calendar', 'Plan and schedule', 'One post to every network with per-network text, a drag-and-drop calendar, autolists that recycle your best posts, and bulk upload from a spreadsheet.'],
    ['chart', 'Analytics that explain', 'Followers, reach and engagement for every account, your best times to post, and which formats and hashtags work.'],
    ['inbox', 'One inbox', 'Comments from all your accounts in one place. Reply without switching apps.'],
    ['file', 'Branded reports', 'PDF and PowerPoint reports with your logo and colors, shareable links, and weekly or monthly emails to clients.'],
    ['target', 'Competitors', 'Follow other accounts’ growth, posting rhythm and engagement next to your own.'],
    ['sparkles', 'AI writing', 'Draft, rewrite, shorten or expand posts, suggest hashtags, or get three variations in your brand voice.'],
    ['team', 'Teams and clients', 'Workspaces per brand, roles, and approvals before anything goes live.'],
    ['globe', 'Website and links', 'A link-in-bio page and cookieless website analytics that show what social brings you.'],
    ['megaphone', 'Ads and hashtags', 'Meta, Google and TikTok ad results, plus hashtag tracking across networks.'],
];
$limit = fn($n, $one, $many) => (int) $n === 0 ? 'Unlimited ' . $many : (int) $n . ' ' . ((int) $n === 1 ? $one : $many);
$keys = array_keys($plans);
$popular = $keys[1] ?? $keys[0] ?? null;
?>
<section class="mk-hero">
    <div class="mk-wrap mk-hero-in">
        <div class="mk-hero-copy">
            <p class="mk-eyebrow">Social media scheduling and analytics</p>
            <h1>Plan, publish and measure every social account in one place.</h1>
            <p class="mk-lead">Schedule to <?= count($networks) ?> networks, answer comments, keep an eye on competitors and send clients reports with your branding.<?= $trial ? " Try it free for $trial days, no card needed." : '' ?></p>
            <div class="mk-cta">
                <?php if ($signup): ?><a class="btn btn-primary btn-lg" href="<?= e(url('/signup')) ?>">Start free trial</a><?php endif; ?>
                <a class="btn btn-lg" href="#pricing">See pricing</a>
            </div>
        </div>
        <figure class="mk-shot">
            <img src="<?= e(asset('screenshot-wide.webp')) ?>" width="1280" height="800" alt="The dashboard: upcoming and recently published posts" fetchpriority="high">
        </figure>
    </div>
    <div class="mk-wrap">
        <ul class="mk-networks" aria-label="Supported networks">
            <?php foreach ($networks as $n): ?><li><?= e($n) ?></li><?php endforeach; ?>
        </ul>
    </div>
</section>

<section class="mk-section" id="features">
    <div class="mk-wrap">
        <h2 class="mk-h2">Everything you need, on every plan</h2>
        <p class="mk-sub">No feature tiers. Plans differ only in how many brands, accounts and people you manage.</p>
        <div class="mk-features">
            <?php foreach ($features as [$ic, $h, $p]): ?>
                <article class="mk-feature"><span class="mk-feature-icon"><?= icon($ic) ?></span><h3><?= e($h) ?></h3><p><?= e($p) ?></p></article>
            <?php endforeach; ?>
        </div>
        <p class="mk-also">Also included: an installable app that works offline, push notifications, first comments and Stories, a REST API and webhooks, an MCP server for AI assistants, and a Looker Studio connector.</p>
    </div>
</section>

<section class="mk-section mk-alt" id="pricing">
    <div class="mk-wrap">
        <h2 class="mk-h2">Simple pricing</h2>
        <p class="mk-sub"><?= $trial ? "Every plan starts with a $trial-day free trial. " : '' ?>Cancel anytime.</p>
        <div class="mk-plans">
            <?php foreach ($plans as $key => $p): ?>
                <article class="mk-plan<?= $key === $popular && count($plans) > 2 ? ' is-popular' : '' ?>">
                    <?php if ($key === $popular && count($plans) > 2): ?><span class="mk-plan-tag">Most popular</span><?php endif; ?>
                    <h3><?= e($p['name']) ?></h3>
                    <p class="mk-plan-blurb"><?= e($p['blurb']) ?></p>
                    <p class="mk-price"><strong><?= e($p['price']) ?></strong><span>/ month</span></p>
                    <ul class="mk-list">
                        <li><?= icon('check') ?><?= e($limit($p['workspaces'], 'brand (workspace)', 'brands (workspaces)')) ?></li>
                        <li><?= icon('check') ?><?= e($limit($p['accounts'], 'social account', 'social accounts')) ?></li>
                        <li><?= icon('check') ?><?= e((int) $p['members'] === 0 ? 'Unlimited team members' : 'You + ' . $p['members'] . ' team member' . ((int) $p['members'] === 1 ? '' : 's')) ?></li>
                        <li><?= icon('check') ?>Every feature included</li>
                    </ul>
                    <?php if ($signup): ?><a class="btn <?= $key === $popular ? 'btn-primary' : '' ?> btn-block" href="<?= e(url('/signup', ['plan' => $key])) ?>">Start free trial</a><?php endif; ?>
                </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section class="mk-section" id="services">
    <div class="mk-wrap">
        <h2 class="mk-h2">Prefer a hand?</h2>
        <p class="mk-sub">Run KreateMix on your own server for free, or let us do the work.</p>
        <div class="mk-services">
            <?php if ($cloud['setup_enabled']): ?>
                <article class="mk-service">
                    <h3><?= icon('settings') ?> Setup service</h3>
                    <p class="mk-price small-price"><strong><?= e($cloud['setup_price']) ?></strong><span>one time</span></p>
                    <p><?= e($cloud['setup_description']) ?></p>
                    <?php if ($cloud['setup_stripe_price'] !== ''): ?>
                        <a class="btn btn-primary" href="<?= e(url('/buy/setup')) ?>">Buy setup</a>
                    <?php else: ?>
                        <a class="btn" href="#contact" data-lead-kind="setup">Ask about setup</a>
                    <?php endif; ?>
                </article>
            <?php endif; ?>
            <?php if ($cloud['support_enabled']): ?>
                <article class="mk-service">
                    <h3><?= icon('shield') ?> Support plan</h3>
                    <p class="mk-price small-price"><strong><?= e($cloud['support_price']) ?></strong></p>
                    <p><?= e($cloud['support_description']) ?></p>
                    <?php if ($cloud['support_stripe_price'] !== ''): ?>
                        <a class="btn btn-primary" href="<?= e(url('/buy/support')) ?>">Get support</a>
                    <?php else: ?>
                        <a class="btn" href="#contact" data-lead-kind="support">Ask about support</a>
                    <?php endif; ?>
                </article>
            <?php endif; ?>
            <?php if ($cloud['managed_enabled']): ?>
                <article class="mk-service">
                    <h3><?= icon('sparkles') ?> Done for you</h3>
                    <p class="mk-price small-price"><strong>Custom</strong><span>monthly</span></p>
                    <p><?= e($cloud['managed_description']) ?></p>
                    <a class="btn" href="#contact" data-lead-kind="managed">Tell us about your business</a>
                </article>
            <?php endif; ?>
        </div>

        <form class="card mk-contact" id="contact" method="post" action="<?= e(url('/contact')) ?>">
            <?= csrf_field() ?>
            <h3>Get in touch</h3>
            <div class="field-row">
                <label class="field"><span>Name</span><input type="text" name="name" required autocomplete="name"></label>
                <label class="field"><span>Email</span><input type="email" name="email" required autocomplete="email"></label>
            </div>
            <div class="field-row">
                <label class="field"><span>Business</span><input type="text" name="company" autocomplete="organization"></label>
                <label class="field"><span>Interested in</span>
                    <select name="kind" data-lead-select>
                        <option value="managed">Done for you</option>
                        <option value="setup">Setup service</option>
                        <option value="support">Support plan</option>
                        <option value="other">Something else</option>
                    </select>
                </label>
                <label class="field"><span>Monthly budget</span>
                    <select name="budget"><option value="">Not sure yet</option><option>Under $500</option><option>$500–$1,500</option><option>$1,500+</option></select>
                </label>
            </div>
            <label class="field"><span>What do you need?</span><textarea name="message" rows="4" placeholder="Which networks, how often you post, anything else"></textarea></label>
            <label class="mk-trap" aria-hidden="true">Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
            <div class="form-actions"><button class="btn btn-primary" type="submit">Send</button></div>
        </form>
    </div>
</section>

<section class="mk-section mk-alt">
    <div class="mk-wrap mk-faq">
        <h2 class="mk-h2">Questions</h2>
        <details><summary>Do I need a credit card for the trial?</summary><p>No. Add one under Billing whenever you’re ready; anything left of your trial carries over.</p></details>
        <details><summary>Which networks can I post to?</summary><p><?= e(implode(', ', $networks)) ?>. Facebook Pages, Instagram professional accounts and LinkedIn company pages are supported.</p></details>
        <details><summary>What happens if I cancel?</summary><p>You keep access until the end of the period you paid for. After that, scheduled posts pause and your data stays in your account in case you come back. Ask us any time to delete it.</p></details>
        <details><summary>Can I run it on my own server instead?</summary><p>Yes. KreateMix is open source under the AGPL. <a href="<?= e($cloud['source_url']) ?>" target="_blank" rel="noopener">Download it</a> and upload one file to any PHP host, or buy the setup service and we’ll do it for you.</p></details>
        <details><summary>Is my data safe?</summary><p>Access tokens are encrypted, nothing is sold or used for ads, and you can delete everything at any time. See the <a href="<?= e(url('/privacy')) ?>">privacy policy</a>.</p></details>
    </div>
</section>

<?php if ($signup): ?>
<section class="mk-final">
    <div class="mk-wrap">
        <h2>Ready when you are.</h2>
        <a class="btn btn-primary btn-lg" href="<?= e(url('/signup')) ?>">Start free trial</a>
    </div>
</section>
<?php endif; ?>
<?php
}

}

namespace {

// ---- view: cloud/signup ----
function mc_view_cloud_signup(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><form class="card auth-card signup-card" method="post" action="<?= e(url('/signup')) ?>">
    <?= csrf_field() ?>
    <h1>Start your free trial</h1>
    <p class="muted"><?= $trialDays ? e($trialDays) . ' days free, no card needed.' : 'Create your account.' ?></p>
    <fieldset class="field">
        <legend>Plan</legend>
        <div class="plan-pick">
            <?php foreach ($plans as $key => $p): ?>
                <label class="plan-option">
                    <input type="radio" name="plan" value="<?= e($key) ?>" <?= $key === (old('plan') ?: $plan) ? 'checked' : '' ?>>
                    <span><strong><?= e($p['name']) ?></strong><small><?= e($p['price']) ?>/mo</small></span>
                </label>
            <?php endforeach; ?>
        </div>
    </fieldset>
    <label class="field"><span>Your name</span><input type="text" name="name" value="<?= e(old('name')) ?>" required autocomplete="name"></label>
    <label class="field"><span>Email</span><input type="email" name="email" value="<?= e(old('email')) ?>" required autocomplete="email"></label>
    <label class="field"><span>Brand or business name</span><input type="text" name="workspace" value="<?= e(old('workspace')) ?>" required placeholder="e.g. Northwind Coffee"></label>
    <div class="field-row">
        <label class="field"><span>Password</span><input type="password" name="password" required minlength="8" autocomplete="new-password"></label>
        <label class="field"><span>Confirm</span><input type="password" name="password_confirmation" required minlength="8" autocomplete="new-password"></label>
    </div>
    <label class="field">
        <span>Timezone</span>
        <select name="timezone" data-detect-timezone>
            <?php foreach ($timezones as $tz): ?>
                <option value="<?= e($tz) ?>" <?= (old('timezone') ?: 'UTC') === $tz ? 'selected' : '' ?>><?= e(str_replace('_', ' ', $tz)) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label class="check"><input type="checkbox" name="agree" value="1" required> <span class="small">I agree to the <a href="<?= e(url('/terms')) ?>" target="_blank">terms of service</a> and <a href="<?= e(url('/privacy')) ?>" target="_blank">privacy policy</a>.</span></label>
    <label class="mk-trap" aria-hidden="true">Website <input type="text" name="website" tabindex="-1" autocomplete="off"></label>
    <button class="btn btn-primary btn-block" type="submit">Create account</button>
    <p class="muted small center">Already have an account? <a href="<?= e(url('/login')) ?>">Sign in</a></p>
</form>
<?php
}

}

namespace {

// ---- view: cloud/thanks ----
function mc_view_cloud_thanks(array $__vars): void
{
    extract($__vars, EXTR_SKIP);
?><section class="mk-wrap mk-thanks">
    <div class="card">
        <h1><?= icon('check') ?> Thank you<?= $order && $order['name'] ? ', ' . e(explode(' ', $order['name'])[0]) : '' ?>!</h1>
        <?php if ($product === 'setup'): ?>
            <p>Your payment for the setup service went through<?= $order && $order['email'] ? ' and a receipt is on its way to ' . e($order['email']) : '' ?>. Tell us a little about your setup so we can get started:</p>
            <?php $d = $order ? json_arr($order['details']) : []; ?>
            <form method="post" action="<?= e(url('/orders/details')) ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="session_id" value="<?= e($sessionId) ?>">
                <div class="field-row">
                    <label class="field"><span>Your domain</span><input type="text" name="domain" value="<?= e($d['domain'] ?? '') ?>" placeholder="social.example.com"></label>
                    <label class="field"><span>Hosting</span><input type="text" name="hosting" value="<?= e($d['hosting'] ?? '') ?>" placeholder="e.g. CloudPanel on Hetzner, cPanel, not sure"></label>
                </div>
                <fieldset class="field"><legend>Networks you want</legend>
                    <div class="check-grid">
                        <?php foreach (['facebook' => 'Facebook', 'instagram' => 'Instagram', 'linkedin' => 'LinkedIn', 'x' => 'X', 'tiktok' => 'TikTok', 'youtube' => 'YouTube', 'threads' => 'Threads', 'pinterest' => 'Pinterest', 'google_business' => 'Google Business', 'bluesky' => 'Bluesky', 'mastodon' => 'Mastodon'] as $k => $l): ?>
                            <label class="check"><input type="checkbox" name="platforms[]" value="<?= e($k) ?>" <?= in_array($k, $d['platforms'] ?? [], true) ? 'checked' : '' ?>> <span><?= e($l) ?></span></label>
                        <?php endforeach; ?>
                    </div>
                </fieldset>
                <label class="field"><span>Anything else?</span><textarea name="notes" rows="3"><?= e($d['notes'] ?? '') ?></textarea></label>
                <div class="form-actions"><button class="btn btn-primary" type="submit">Send details</button></div>
            </form>
        <?php else: ?>
            <p>Your support plan is active<?= $order && $order['email'] ? '. We’ll write to ' . e($order['email']) . ' shortly' : '' ?>. <?= $cloud['support_email'] ? 'For help, email <a href="mailto:' . e($cloud['support_email']) . '">' . e($cloud['support_email']) . '</a>.' : '' ?></p>
        <?php endif; ?>
    </div>
</section>
<?php
}

}

