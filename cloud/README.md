# KreateMix Cloud

The hosted, paid edition of [KreateMix](https://github.com/kennethyork/KreateMix): social media scheduling and analytics that you run as a subscription service. It's the same single `index.php` as KreateMix, plus:

- **Public sales site** at your domain: features, pricing, services, FAQ, legal pages
- **Self-service sign-up** with a **7-day free trial**, no card needed
- **Stripe billing**: Checkout for subscriptions, the Stripe customer portal for card changes, plan switches and cancellations, and webhooks that keep everything in sync
- **Plans with limits** on brands (workspaces), social accounts and team members. Every plan has every feature
- **Automatic pausing**: when a trial ends without a card, or a subscription is canceled, the customer is sent to Billing and their scheduled posts, API and MCP access pause until they subscribe. Nothing is deleted
- **Trial reminders** by push notification and email three days before a trial ends
- **Setup service** (one-time payment) and **support plan** (yearly subscription) sold through Stripe, with an intake form after purchase
- **"Done for you" enquiries** from a contact form, with spam protection
- **Customers dashboard**: sign-ups, trials, paying customers, estimated monthly revenue, orders and enquiries; comp accounts, extend trials, change plans by hand
- **Legal pages and Meta's data deletion callback**, required for platform app review

KreateMix itself (free, self-hosted) has no billing code. Both editions are open source under the AGPL; the hosted edition is paid for as a service, not for the code.

## Building

The hosted edition isn't kept as a second copy of `index.php`. This folder holds only what it adds, and `build.php` puts it together:

```
php cloud/build.php                        # writes dist/cloud/index.php
php cloud/build.php path/to/index.php      # or anywhere you like
```

- `overlay.php` lists the edits: replace the free `Cloud` class with the real one, add `CloudController`, the views, the `006_hosted.sql` migration and the routes. Each edit is pinned to text that must appear exactly once in `index.php`.
- `parts/` holds the larger blocks those edits insert.

Changes to the app itself go in the root `index.php`; rebuild afterwards and Cloud picks them up. If a change moves or removes the text an edit is pinned to, the build stops and names the edit, so the two editions can't drift apart without you noticing. The build also runs `php -l` on the result.

## Quick start

1. Build (see above) and upload the result as `index.php` to your web root. On CloudPanel: create a PHP site and put it in `htdocs/your-domain`.
2. Open the domain and create your admin account. Your sales page goes live at the same address for visitors who aren't signed in.
3. Add the scheduler to cron (CloudPanel: the site's **Cron Jobs** tab), every minute:
   ```
   php /home/<site-user>/htdocs/<your-domain>/index.php cron
   ```
4. Under **Platform API keys**, add your developer apps for each network (see below).
5. Under **Hosted service**, set your business name and support email, connect Stripe and review the plans.
6. Under **Legal pages**, review the terms, privacy policy and data deletion page.

Requirements, Nginx/Apache notes and everything about the app itself are in the [KreateMix README](https://github.com/kennethyork/KreateMix#readme).

## Stripe

All payments happen on Stripe's pages; no card data touches your server.

1. In the Stripe dashboard, create products and prices:

   | Product | Price | Default shown on the site |
   |---|---|---|
   | Solo | recurring, monthly | $12 |
   | Team | recurring, monthly | $29 |
   | Agency | recurring, monthly | $59 |
   | Setup service | one-time | $149 |
   | Support plan | recurring, yearly | $149 / year |

   Copy each price ID (`price_…`) into **Hosted service**.
2. **Developers → API keys**: copy the secret key (`sk_live_…`, or `sk_test_…` while testing).
3. **Developers → Webhooks → Add endpoint**: `https://your-domain/stripe/webhook` with these events:
   - `checkout.session.completed`
   - `customer.subscription.created`, `customer.subscription.updated`, `customer.subscription.deleted`
   - `invoice.payment_failed`

   Copy the signing secret (`whsec_…`).
4. **Settings → Billing → Customer portal**: allow cancelling, updating payment methods and switching between your three plan prices.
5. Turn on **Stripe Tax** if you need to charge sales tax or VAT.

Test the whole flow in Stripe test mode first with the card `4242 4242 4242 4242`, then swap in your live keys and live price IDs.

How it fits together:

- A trial customer who subscribes keeps the rest of their trial; Stripe starts charging when it ends.
- Plan changes made in the customer portal come back by webhook, and the customer's limits follow the new price.
- A failed payment shows a warning but keeps things running while Stripe retries. A canceled subscription pauses the account at the end of the paid period.
- Every webhook is signature-checked, rejected if older than 5 minutes, and applied once even if Stripe retries it.

## Plans and limits

Edit names, prices shown, descriptions, Stripe price IDs and limits under **Hosted service**; 0 means unlimited. The defaults:

| | Solo | Team | Agency |
|---|---|---|---|
| Brands (workspaces) | 1 | 5 | 25 |
| Social accounts | 10 | 50 | 200 |
| Team members | 2 | 10 | Unlimited |

The free trial length (7 days by default) is set on the same page. Admins (you) are never limited, and any customer can be marked **Complimentary** under **Customers**.

## Platform developer apps

Customers connect their social accounts through *your* developer apps, so set them up once:

- **Meta** (Facebook, Instagram, Threads): create a business app, add the products, and submit for App Review with your privacy policy URL, terms URL and data deletion callback URL (all shown under **Legal pages**). Until approved, only people with a role on your app can connect.
- **LinkedIn**: company pages need the Community Management API.
- **TikTok**: posts stay private until your app passes TikTok's audit.
- **X**: needs a paid API plan with write access; check that its rate limits cover all your customers.
- **Google** (YouTube, Business Profile): OAuth consent screen verification, plus Business Profile API access.

The callback URL for each platform is shown under **Platform API keys**.

## Running the business

- **Customers** lists sign-ups with their plan, status, usage and renewal date. Comp an account, extend a trial or change a plan there.
- **Orders** shows setup and support purchases with the details buyers sent after paying. Mark them done as you go.
- **Enquiries** collects "done for you" and other contact-form messages.
- Turn on **New sign-ups, subscriptions and orders** under your Profile to get a push notification for each, and add a mail server under Integrations to also get them by email (and to send trial reminders).
- Switch hosted mode off under **Hosted service** to hide the sales site and stop sign-ups; the app keeps working for existing users without limits.

## Backups

Back up the settings file (`.ht.kreatemix-config.php`, next to `index.php`) and the database (`.ht.kreatemix-<random>.sqlite`, usually one folder up; `db_path` in the settings file says where). The key in the first decrypts the tokens in the second. The database holds everything, including uploaded images and videos.

## Testing against a fake Stripe

Add `'stripe_api_base' => 'http://127.0.0.1:12111'` to the settings file to point the Stripe client at [stripe-mock](https://github.com/stripe/stripe-mock) or another test server.

## Updating

Build from the latest code and replace `index.php` on the server. The database is upgraded automatically on the next request.

## License

GNU Affero General Public License v3.0 or later (see `LICENSE` in the repository root). Copyright (C) 2026 Kenneth York.

The **Source code** link in the app's footer and menu comes from `source_url` under **Hosted service**. It points to the main [KreateMix repository](https://github.com/kennethyork/KreateMix) by default, which is where this code lives.
