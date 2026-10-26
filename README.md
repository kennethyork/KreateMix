# MixCreate

Self-hosted social media scheduling, written in plain PHP, HTML, CSS and JavaScript. No framework, no Composer, no npm, no build step. Inspired by Mixpost; all code here is original.

## Features

- **Composer** — one post to many accounts, per-account text, media with drag-to-reorder, live previews, per-platform character counters, paste images straight in
- **Scheduling** — month and week calendar, publish now, retries that skip accounts already posted to
- **Workspaces** — one per brand or client, each with its own accounts, posts, media and team
- **Teams** — admin, member and viewer roles, invite links (no mail server needed)
- **Approvals** — optionally require an admin to approve members' posts
- **Library** — templates, hashtag groups, `{{variables}}`, tags
- **Analytics** — follower growth, posts per day, top posts by engagement
- **REST API** — bearer tokens; create, schedule, approve posts and upload media
- **Webhooks** — signed with HMAC-SHA256, retried with backoff
- **Platforms** — X, Mastodon, Bluesky, Facebook Pages, Instagram, LinkedIn, YouTube, Threads, TikTok, Pinterest, Google Business Profile
- Dark mode, mobile layout, encrypted tokens at rest

## Requirements

- PHP 8.1+ with `pdo_sqlite`, `curl`, `openssl`, `mbstring`, `fileinfo` (and `gd` for image sizes)
- Any web server that runs PHP (Apache, Nginx + PHP-FPM, Caddy, shared hosting)
- A cron job, or an external service that can hit a URL every minute

## Install

1. Upload the folder to your server.
2. Point your domain's document root at the `public/` folder.
   On shared hosting where you can't change the document root, upload everything as is: the root `.htaccess` routes requests into `public/` and blocks the rest.
3. Make `storage/` and `public/uploads/` writable by the web server (and the project folder, for the first run).
4. Open the site. MixCreate creates `config.php` with fresh secrets and walks you through creating the admin account.
5. Add the scheduler to cron:

   ```
   * * * * * php /path/to/mixcreate/cron.php >/dev/null 2>&1
   ```

   No cron access? Call `https://your-site/cron?key=CRON_KEY` every minute from any uptime or cron service. `CRON_KEY` is in `config.php`.

6. As the admin, open **Platform API keys** and add keys for the platforms you want. Each one shows the callback URL to register with the platform. Mastodon and Bluesky need no keys.

### Nginx

```nginx
root /var/www/mixcreate/public;
index index.php;
client_max_body_size 200M;

location / { try_files $uri /index.php?$query_string; }
location ~ \.php$ {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
}
location ^~ /uploads/ { location ~ \.php$ { deny all; } }
```

Raise `upload_max_filesize` and `post_max_size` in `php.ini` if you post videos.

### Local development

```
php -S localhost:8000 -t public public/index.php
```

Set `'debug' => true` in `config.php` to see errors and enable the **Sandbox** platform, which fakes publishing so you can test scheduling without real accounts.

## Configuration

`config.php` (created on first run, never commit it):

| Key | Purpose |
|---|---|
| `app_url` | Public URL without trailing slash. Leave empty to detect it. Set it if you're behind a proxy or need exact OAuth callback URLs. |
| `app_key` | Encrypts API keys and access tokens. Changing it disconnects every account. |
| `cron_key` | Secret for the web cron URL. |
| `db_path` | SQLite database file. |
| `max_upload_mb` | Upload size limit. |
| `debug` | Show errors and enable the Sandbox platform. |

## REST API

Create a token under **API & webhooks**. Tokens act as you, in that workspace, with your role.

```
curl -X POST https://your-site/api/v1/posts \
  -H "Authorization: Bearer mc_..." \
  -H "Content-Type: application/json" \
  -d '{"content":"Hello","accounts":[1,2],"action":"schedule","scheduled_at":"2026-11-03T09:00:00-05:00"}'
```

| Method | Path | |
|---|---|---|
| GET | `/api/v1/me` | user, role, workspace |
| GET | `/api/v1/accounts` | connected accounts |
| GET | `/api/v1/tags` | tags |
| GET / POST | `/api/v1/media` | list / upload (`file` field) |
| GET | `/api/v1/posts?status=&page=&limit=` | list posts |
| POST | `/api/v1/posts` | create |
| GET / PUT / DELETE | `/api/v1/posts/{id}` | read / update / delete |
| POST | `/api/v1/posts/{id}/approve` | approve (admins) |
| POST | `/api/v1/posts/{id}/reject` | send back with `{"note": "..."}` (admins) |

`action` is `draft`, `schedule` or `now`.

## Webhooks

Each delivery is a `POST` with a JSON body:

```json
{ "event": "post.published", "workspace_id": 1, "sent_at": "2026-10-26T14:00:00+00:00", "data": { "post": { ... } } }
```

Verify `X-MixCreate-Signature`, which is `sha256=` followed by the HMAC-SHA256 of the raw body using the webhook secret. Events: `post.created`, `post.updated`, `post.scheduled`, `post.approval_requested`, `post.approved`, `post.rejected`, `post.published`, `post.failed`, `post.deleted`, `account.connected`, `account.disconnected`.

## Extending

Drop a folder in `modules/` with a `module.php`. It's loaded on every request and can register routes, platforms, sidebar items and event listeners through `App\Hooks`; see the comment at the top of `src/Hooks.php`.

## Project layout

```
public/          web root: index.php, assets, uploads
src/             application code (App\ namespace)
src/Providers/   one class per social platform
views/           PHP templates
database/        SQL migrations, applied automatically
storage/         SQLite database, logs (not web-accessible)
cron.php         scheduler entry point
```

## License

MIT
