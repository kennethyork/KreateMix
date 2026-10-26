        '006_hosted.sql' => <<<'MC_SQL_6'
-- Hosted service: customers on plans, one-off orders (setup service, support plan), leads, Stripe events.
CREATE TABLE IF NOT EXISTS billing_customers (
    user_id INTEGER PRIMARY KEY REFERENCES users(id) ON DELETE CASCADE,
    plan TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'trialing', -- trialing, active, past_due, canceled, comp
    trial_ends_at TEXT,
    stripe_customer_id TEXT,
    stripe_subscription_id TEXT,
    current_period_end TEXT,
    cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
    reminded_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS billing_customers_sub ON billing_customers (stripe_subscription_id);
CREATE INDEX IF NOT EXISTS billing_customers_cus ON billing_customers (stripe_customer_id);

CREATE TABLE IF NOT EXISTS orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL,                       -- setup, support
    email TEXT,
    name TEXT,
    status TEXT NOT NULL DEFAULT 'paid',      -- paid, active, canceled, done
    stripe_session_id TEXT UNIQUE,
    stripe_customer_id TEXT,
    stripe_subscription_id TEXT,
    amount_total INTEGER,
    currency TEXT,
    details TEXT NOT NULL DEFAULT '{}',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS leads (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    kind TEXT NOT NULL DEFAULT 'managed',
    name TEXT NOT NULL,
    email TEXT NOT NULL,
    company TEXT,
    budget TEXT,
    message TEXT,
    handled_at TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS stripe_events (
    id TEXT PRIMARY KEY,
    type TEXT NOT NULL,
    received_at TEXT NOT NULL
);
MC_SQL_6,
