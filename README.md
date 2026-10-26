# MixCreate

Self-hosted social media scheduling, written in plain PHP, HTML, CSS and JavaScript. No framework, no Composer, no npm, no build step. Inspired by Mixpost; all code here is original.

## Features

- **Composer** — one post to many accounts, per-account text, media with drag-to-reorder, live previews, per-platform character counters, paste images straight in
- **Post types** — feed posts, Facebook and Instagram Stories, and an automatic **first comment** (Facebook, Instagram, LinkedIn)
- **Scheduling** — month and week calendar, publish now, retries that skip accounts already posted to
- **Autolists** — evergreen queues that publish the next item at set days and times, in order or shuffled, looping when they reach the end
- **Bulk import** — schedule many posts at once from a CSV (preview and fix errors before anything is saved)
- **Workspaces** — one per brand or client, each with its own accounts, posts, media and team
- **Teams** — admin, member and viewer roles, invite links (no mail server needed)
- **Approvals** — optionally require an admin to approve members' posts
- **Library** — templates, hashtag groups, `{{variables}}`, tags
- **Analytics** — followers, engagement rate, impressions, per-network and per-account breakdowns, formats and hashtags that perform, top posts, audience demographics (Instagram, Threads)
- **Best time to post** — a day × hour heatmap from your own engagement, with one-click suggestions in the composer
- **Reports** — PDF and PowerPoint downloads, client share links (no login), weekly or monthly reports emailed with the PDF attached, and white-label branding per workspace (logo, color, footer)
- **Website analytics** — a cookieless tracking snippet: visitors, pages, referrers, UTM campaigns, devices, and how much traffic comes from social
- **Hashtag tracker** — follow a hashtag on Mastodon, Bluesky, X and Instagram: posts, reach, top posts and the people using it
- **Competitors** — track public accounts on X, Bluesky, Mastodon, Instagram and YouTube: followers, growth, posting frequency, average engagement
- **Inbox** — comments on your posts from X, Mastodon, Bluesky, Facebook, Instagram, Threads and YouTube, with replies sent from MixCreate
- **Link in bio** — a public page per workspace with links, social icons, a recent-posts grid and click tracking
- **Ads** — spend, impressions, clicks, CTR, CPC and conversions from Meta, Google and TikTok ad accounts
- **AI writing** — write, rewrite, shorten, add hashtags or get variations. Bring your own [OpenRouter](https://openrouter.ai) key to use any major model; each workspace can use its own key. OpenAI, Anthropic and OpenAI-compatible servers (Ollama, LM Studio) work too
- **Canva and Google Drive** — import designs and files straight into the media library
- **REST API** — bearer tokens; create, schedule, approve posts, upload media, export analytics (JSON or CSV)
- **MCP server** — let Claude or any MCP client draft posts, read analytics and answer comments
- **Looker Studio** — a community connector for your analytics
- **Push notifications** — failed posts, approvals, new comments; installable as an app on phone and desktop
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

6. Optional: under **Integrations**, add a mail server (for emailed reports), an OpenRouter key for AI, Canva, Google Drive and ads platform keys.
7. As the admin, open **Platform API keys** and add keys for the platforms you want. Each one shows the callback URL to register with the platform. Mastodon and Bluesky need no keys.

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

## Platform notes

| Platform | Keys | Notes |
|---|---|---|
| X | OAuth 2.0 client ID/secret | Needs an API plan with write access. Images and video via chunked upload. |
| Mastodon | none | Registers itself on each server automatically. |
| Bluesky | none | Connect with a handle and an app password. Video goes through Bluesky's video service. |
| Facebook Pages | App ID/secret | Each Page is its own account. |
| Instagram | Instagram app ID/secret | Professional accounts only. PNG/WebP are converted to JPEG automatically. Single videos post as Reels. |
| Threads | Threads app ID/secret | Text, images, video and carousels. |
| LinkedIn | Client ID/secret | Personal profile; company pages too if your app has the Community Management API. |
| YouTube | Google OAuth client | First line of the post is the title. |
| TikTok | Client key/secret | Unaudited apps can only post privately. Photo posts need your domain verified with TikTok. |
| Pinterest | App ID/secret | Each board is its own account. First line is the pin title; a link in the text becomes the pin link. |
| Google Business Profile | Google OAuth client | Requires Google to grant API access. Each location is its own account. |

Facebook, Instagram, Threads, TikTok photos and Google Business fetch media from your server by URL, so your install must be reachable from the internet (set `app_url`). API versions are constants at the top of each class in `src/Providers/` (e.g. `Facebook::VERSION`, `LinkedIn::VERSION`) if a platform retires one.

### Integrations notes

- **Inbox** covers comments and replies, not direct messages; DM access needs separate app reviews on every platform. X comment search needs an API plan that includes recent search.
- **Competitors**: X, Instagram and YouTube lookups borrow one of the workspace's connected accounts on that network. Instagram needs a connected Facebook Page that is linked to an Instagram business account.
- **Ads**: Google Ads needs a developer token; Meta needs the `ads_read` permission; TikTok needs a TikTok for Business developer app.
- **Google Drive** uses a Google OAuth client (it can be the same one as YouTube) with the Drive API enabled. Register the redirect URL shown under Integrations. Access is read-only.
- **Hashtags**: Mastodon and Bluesky need nothing; X needs a connected X account on an API plan that includes recent search; Instagram needs a connected Instagram business account (Instagram limits you to 30 hashtags a week). The tracker refreshes every 30 minutes.
- **Push notifications** need HTTPS. On iPhone, add MixCreate to the Home Screen first and turn notifications on from the app. Each person picks which events they get under **Profile**.

## AI (OpenRouter, bring your own key)

1. Create a key at [openrouter.ai/keys](https://openrouter.ai/keys) and add credits, or link your own OpenAI/Anthropic/Google keys there.
2. As admin, open **Integrations → AI**, leave the provider on **OpenRouter**, paste the key and pick a model (start typing to search the list, or use `openrouter/auto`). Press **Test**.
3. To bill a client's usage to their own account, open that workspace's **Settings → AI writing** and add their key. A workspace key always overrides the instance key. You can also set a model and brand voice per workspace.

Post text is sent to OpenRouter and the model provider you choose. To keep everything on your own hardware, switch the provider to **OpenAI-compatible** and point it at a local Ollama or LM Studio server.

## Website analytics

Add a site under **Website** and paste the snippet before `</head>`:

```html
<script defer src="https://your-site/assets/t.js" data-site="SITE_KEY"></script>
```

No cookies are set and no IP addresses are stored; visitors are counted with a salted hash that changes every day. Bots are ignored. Tag your social links with `utm_source`/`utm_campaign` to see campaigns.

## Bulk import (CSV)

Download the sample from **Import**. Columns: `date`, `time`, `text`, `accounts`, `media`, `first_comment`, `tags`, `status`. Only `text` is required. `accounts` takes account names, `@handles` or network names separated by `;` or `|` (or `all`); `media` takes media library IDs or image URLs; `status` is `schedule` or `draft`. The file can use commas or semicolons as separators.

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
| GET | `/api/v1/analytics?days=30&format=json\|csv` | daily followers, posts, engagement and impressions per account |

`action` is `draft`, `schedule` or `now`. Where a header can't be set, pass the token as `?api_token=`.

### Looker Studio

Paste `docs/looker-studio-connector.gs` into a new [Apps Script](https://script.google.com) project, deploy it as a community connector, then enter your site URL and an API token. Every account's daily metrics become a Looker Studio data source.

## MCP server

MixCreate is an MCP server at `https://your-site/mcp` (Streamable HTTP). Authenticate with an API token as `Authorization: Bearer mc_...`. Tools: `list_accounts`, `list_posts`, `get_post`, `create_post`, `get_analytics`, `list_inbox`, `reply_to_comment`. For Claude Code:

```
claude mcp add --transport http mixcreate https://your-site/mcp --header "Authorization: Bearer mc_..."
```

Posts created through MCP follow the same approval rules as the token's owner.

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
