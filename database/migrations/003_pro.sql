-- First comment and per-account post type (feed / story / reel)
ALTER TABLE posts ADD COLUMN first_comment TEXT;
ALTER TABLE posts ADD COLUMN autolist_id INTEGER;
ALTER TABLE post_accounts ADD COLUMN options TEXT NOT NULL DEFAULT '{}';
ALTER TABLE post_accounts ADD COLUMN first_comment_status TEXT;

-- White-label branding per workspace: {name, color, logo_media_id, hide_powered_by}
ALTER TABLE workspaces ADD COLUMN branding TEXT NOT NULL DEFAULT '{}';

-- Notification preferences per user
ALTER TABLE users ADD COLUMN notify TEXT NOT NULL DEFAULT '{"failed":1,"comments":1,"approvals":1,"published":0}';

-- Autolists: evergreen queues that publish the next item at each time slot
CREATE TABLE autolists (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    accounts TEXT NOT NULL DEFAULT '[]',
    slots TEXT NOT NULL DEFAULT '[]',        -- [{"day": 1, "time": "09:00"}], day 0 = Sunday
    timezone TEXT NOT NULL DEFAULT 'UTC',
    order_mode TEXT NOT NULL DEFAULT 'sequence', -- sequence | random
    active INTEGER NOT NULL DEFAULT 1,
    next_index INTEGER NOT NULL DEFAULT 0,
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL
);

CREATE TABLE autolist_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    autolist_id INTEGER NOT NULL REFERENCES autolists(id) ON DELETE CASCADE,
    content TEXT NOT NULL DEFAULT '',
    media TEXT NOT NULL DEFAULT '[]',
    position INTEGER NOT NULL DEFAULT 0,
    times_posted INTEGER NOT NULL DEFAULT 0,
    last_posted_at TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE autolist_runs (
    autolist_id INTEGER NOT NULL REFERENCES autolists(id) ON DELETE CASCADE,
    slot TEXT NOT NULL,                      -- UTC "Y-m-d H:i" of the slot that ran
    post_id INTEGER,
    PRIMARY KEY (autolist_id, slot)
);

-- Website analytics (cookieless)
CREATE TABLE sites (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    domain TEXT NOT NULL,
    site_key TEXT NOT NULL UNIQUE,
    created_at TEXT NOT NULL
);

CREATE TABLE pageviews (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    site_id INTEGER NOT NULL REFERENCES sites(id) ON DELETE CASCADE,
    ts TEXT NOT NULL,                        -- UTC
    path TEXT NOT NULL,
    title TEXT,
    referrer TEXT,                           -- host only
    utm_source TEXT,
    utm_medium TEXT,
    utm_campaign TEXT,
    device TEXT,                             -- desktop | mobile | tablet
    browser TEXT,
    os TEXT,
    country TEXT,
    visitor TEXT NOT NULL,                   -- daily-rotating hash, not reversible to an IP
    pid TEXT,                                -- random id from the browser, links the leave beacon to this view
    duration INTEGER                         -- seconds on page, filled by the leave beacon
);
CREATE INDEX pageviews_pid ON pageviews (site_id, pid);
CREATE INDEX pageviews_site_ts ON pageviews (site_id, ts);

-- Hashtag tracker
CREATE TABLE hashtags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    tag TEXT NOT NULL,
    networks TEXT NOT NULL DEFAULT '[]',
    active INTEGER NOT NULL DEFAULT 1,
    last_checked_at TEXT,
    error TEXT,
    created_at TEXT NOT NULL,
    UNIQUE (workspace_id, tag)
);

CREATE TABLE hashtag_posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    hashtag_id INTEGER NOT NULL REFERENCES hashtags(id) ON DELETE CASCADE,
    network TEXT NOT NULL,
    external_id TEXT NOT NULL,
    author_handle TEXT NOT NULL DEFAULT '',
    author_name TEXT NOT NULL DEFAULT '',
    author_avatar TEXT,
    text TEXT NOT NULL DEFAULT '',
    url TEXT,
    likes INTEGER NOT NULL DEFAULT 0,
    reposts INTEGER NOT NULL DEFAULT 0,
    replies INTEGER NOT NULL DEFAULT 0,
    posted_at TEXT NOT NULL,
    UNIQUE (hashtag_id, network, external_id)
);
CREATE INDEX hashtag_posts_time ON hashtag_posts (hashtag_id, posted_at);

-- Web push subscriptions
CREATE TABLE push_subscriptions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    endpoint TEXT NOT NULL UNIQUE,
    p256dh TEXT NOT NULL,
    auth TEXT NOT NULL,
    user_agent TEXT,
    created_at TEXT NOT NULL
);
