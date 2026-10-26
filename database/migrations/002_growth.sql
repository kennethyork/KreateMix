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
