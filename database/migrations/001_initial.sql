CREATE TABLE users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    email TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password TEXT NOT NULL,
    is_admin INTEGER NOT NULL DEFAULT 0,
    timezone TEXT NOT NULL DEFAULT 'UTC',
    current_workspace_id INTEGER,
    created_at TEXT NOT NULL
);

CREATE TABLE workspaces (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT '#4f46e5',
    require_approval INTEGER NOT NULL DEFAULT 0,  -- members' posts need an admin's approval before scheduling
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL
);

-- role: admin (manage workspace, members, accounts), member (create and publish posts), viewer (read only)
CREATE TABLE workspace_user (
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role TEXT NOT NULL DEFAULT 'member',
    joined_at TEXT NOT NULL,
    PRIMARY KEY (workspace_id, user_id)
);

CREATE TABLE invitations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    email TEXT NOT NULL COLLATE NOCASE,
    role TEXT NOT NULL DEFAULT 'member',
    token TEXT NOT NULL UNIQUE,
    invited_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    accepted_at TEXT
);

-- App-wide API credentials per platform (client id/secret etc.), encrypted JSON.
CREATE TABLE services (
    provider TEXT PRIMARY KEY,
    credentials TEXT,
    active INTEGER NOT NULL DEFAULT 0,
    updated_at TEXT
);

CREATE TABLE accounts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    provider TEXT NOT NULL,
    provider_id TEXT NOT NULL,
    name TEXT NOT NULL DEFAULT '',
    username TEXT NOT NULL DEFAULT '',
    avatar TEXT,
    token TEXT,               -- encrypted JSON (access/refresh tokens, expiry)
    data TEXT NOT NULL DEFAULT '{}',
    authorized INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE (workspace_id, provider, provider_id)
);

CREATE TABLE media (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    path TEXT NOT NULL,
    name TEXT NOT NULL,
    mime TEXT NOT NULL,
    size INTEGER NOT NULL,
    width INTEGER,
    height INTEGER,
    created_at TEXT NOT NULL
);

CREATE TABLE tags (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    color TEXT NOT NULL DEFAULT '#64748b',
    UNIQUE (workspace_id, name)
);

-- status: draft, pending_approval, scheduled, publishing, published, failed
CREATE TABLE posts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    user_id INTEGER REFERENCES users(id) ON DELETE SET NULL,
    content TEXT NOT NULL DEFAULT '',
    media TEXT NOT NULL DEFAULT '[]',
    status TEXT NOT NULL DEFAULT 'draft',
    scheduled_at TEXT,
    published_at TEXT,
    reviewed_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    reviewed_at TEXT,
    review_note TEXT,          -- reason given when an admin sends a post back
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE INDEX posts_due ON posts (status, scheduled_at);
CREATE INDEX posts_workspace ON posts (workspace_id, scheduled_at);

-- One row per target account. content overrides the post text for that account when set.
CREATE TABLE post_accounts (
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    content TEXT,
    status TEXT NOT NULL DEFAULT 'pending',
    provider_post_id TEXT,
    url TEXT,
    error TEXT,
    published_at TEXT,
    PRIMARY KEY (post_id, account_id)
);

CREATE TABLE post_tag (
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    tag_id INTEGER NOT NULL REFERENCES tags(id) ON DELETE CASCADE,
    PRIMARY KEY (post_id, tag_id)
);

-- Daily snapshots for analytics (filled by the cron importer).
CREATE TABLE account_metrics (
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    date TEXT NOT NULL,
    followers INTEGER,
    data TEXT NOT NULL DEFAULT '{}',
    PRIMARY KEY (account_id, date)
);

CREATE TABLE post_metrics (
    post_id INTEGER NOT NULL REFERENCES posts(id) ON DELETE CASCADE,
    account_id INTEGER NOT NULL REFERENCES accounts(id) ON DELETE CASCADE,
    data TEXT NOT NULL DEFAULT '{}',
    updated_at TEXT NOT NULL,
    PRIMARY KEY (post_id, account_id)
);

CREATE TABLE logs (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    level TEXT NOT NULL,
    message TEXT NOT NULL,
    context TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    content TEXT NOT NULL DEFAULT '',
    media TEXT NOT NULL DEFAULT '[]',
    created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE hashtag_groups (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    hashtags TEXT NOT NULL DEFAULT '',
    created_at TEXT NOT NULL
);

-- Workspace-level text variables usable in posts as {{name}}.
CREATE TABLE variables (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    value TEXT NOT NULL DEFAULT '',
    UNIQUE (workspace_id, name)
);

CREATE TABLE api_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    name TEXT NOT NULL,
    token TEXT NOT NULL UNIQUE,   -- sha256 of the token; the token itself is shown once
    last_used_at TEXT,
    created_at TEXT NOT NULL
);

CREATE TABLE webhooks (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workspace_id INTEGER NOT NULL REFERENCES workspaces(id) ON DELETE CASCADE,
    url TEXT NOT NULL,
    events TEXT NOT NULL DEFAULT '[]',
    secret TEXT NOT NULL,
    active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT NOT NULL
);

CREATE TABLE webhook_deliveries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    webhook_id INTEGER NOT NULL REFERENCES webhooks(id) ON DELETE CASCADE,
    event TEXT NOT NULL,
    payload TEXT NOT NULL,
    status_code INTEGER,
    response TEXT,
    attempts INTEGER NOT NULL DEFAULT 0,
    next_attempt_at TEXT,
    delivered_at TEXT,
    created_at TEXT NOT NULL
);
CREATE INDEX webhook_deliveries_pending ON webhook_deliveries (delivered_at, next_attempt_at);
