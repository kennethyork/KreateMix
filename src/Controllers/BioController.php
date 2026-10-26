<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\Publisher;
use App\Workspace;

/** Link-in-bio page: one public page per workspace at /@slug. */
final class BioController
{
    public const THEMES = [
        'light' => ['label' => 'Light', 'bg' => '#f6f7f9', 'card' => '#ffffff', 'text' => '#15181e'],
        'dark' => ['label' => 'Dark', 'bg' => '#0f1115', 'card' => '#1b1f27', 'text' => '#eceef2'],
        'tint' => ['label' => 'Tinted', 'bg' => 'accent', 'card' => '#ffffff', 'text' => '#15181e'],
    ];

    public static function page(int $workspaceId): ?array
    {
        return Db::one('SELECT * FROM bio_pages WHERE workspace_id = ?', [$workspaceId]);
    }

    public function edit(): void
    {
        $ws = Workspace::current();
        $page = self::page((int) $ws['id']);
        if (!$page) {
            $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($ws['name'])), '-') ?: 'page';
            $slug = $base;
            for ($i = 2; Db::value('SELECT 1 FROM bio_pages WHERE slug = ?', [$slug]); $i++) {
                $slug = "$base-$i";
            }
            Db::insert('bio_pages', [
                'workspace_id' => $ws['id'], 'slug' => $slug, 'title' => $ws['name'], 'bio' => '',
                'theme' => json_encode(['accent' => $ws['color'], 'style' => 'light', 'button' => 'rounded']),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $page = self::page((int) $ws['id']);
        }
        $links = Db::all('SELECT * FROM bio_links WHERE page_id = ? ORDER BY position, id', [$page['id']]);
        $since = gmdate('Y-m-d', time() - 29 * 86400);
        $stats = Db::all('SELECT link_id, SUM(count) AS n FROM bio_stats WHERE page_id = ? AND date >= ? GROUP BY link_id', [$page['id'], $since]);
        $byLink = array_column($stats, 'n', 'link_id');
        $views = Db::all('SELECT date, count FROM bio_stats WHERE page_id = ? AND link_id = 0 AND date >= ? ORDER BY date', [$page['id'], $since]);
        $viewMap = array_column($views, 'count', 'date');
        $series = [];
        for ($t = strtotime($since); $t <= time(); $t += 86400) {
            $d = gmdate('Y-m-d', $t);
            $series[] = ['date' => $d, 'value' => (int) ($viewMap[$d] ?? 0)];
        }
        view('bio/edit', [
            'title' => 'Link in bio',
            'page' => $page,
            'theme' => json_arr($page['theme']),
            'links' => $links,
            'clicks' => $byLink,
            'views' => (int) ($byLink[0] ?? 0),
            'series' => $series,
            'publicUrl' => abs_url('@' . $page['slug']),
            'avatar' => $page['avatar_media_id'] ? (Publisher::resolveMedia([(int) $page['avatar_media_id']])[0] ?? null) : null,
            'themes' => self::THEMES,
        ]);
    }

    public function update(): void
    {
        $page = self::page(Workspace::id()) ?? abort(404);
        $slug = strtolower(trim((string) input('slug', $page['slug'])));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,40}$/', $slug)) {
            flash('error', 'The address can use 2–41 lowercase letters, numbers and dashes.');
            redirect('/bio');
        }
        if ($slug !== $page['slug'] && Db::value('SELECT 1 FROM bio_pages WHERE slug = ?', [$slug])) {
            flash('error', 'That address is taken.');
            redirect('/bio');
        }
        $accent = preg_match('/^#[0-9a-f]{6}$/i', (string) input('accent', '')) ? input('accent') : '#4f46e5';
        $style = isset(self::THEMES[input('style')]) ? input('style') : 'light';
        $avatar = (int) input('avatar_media_id', 0);
        if ($avatar && !Db::value('SELECT 1 FROM media WHERE id = ? AND workspace_id = ?', [$avatar, Workspace::id()])) {
            $avatar = 0;
        }
        Db::update('bio_pages', [
            'slug' => $slug,
            'title' => mb_substr((string) input('title', ''), 0, 80),
            'bio' => mb_substr((string) input('bio', ''), 0, 300),
            'avatar_media_id' => $avatar ?: null,
            'theme' => json_encode(['accent' => $accent, 'style' => $style, 'button' => input('button') === 'square' ? 'square' : 'rounded']),
            'show_posts' => input('show_posts') ? 1 : 0,
            'show_socials' => input('show_socials') ? 1 : 0,
            'published' => input('published') ? 1 : 0,
            'updated_at' => now(),
        ], 'id = ?', [$page['id']]);
        flash('success', 'Saved.');
        redirect('/bio');
    }

    public function addLink(): void
    {
        $page = self::page(Workspace::id()) ?? abort(404);
        [$label, $url] = $this->validLink();
        $pos = (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM bio_links WHERE page_id = ?', [$page['id']]);
        Db::insert('bio_links', ['page_id' => $page['id'], 'label' => $label, 'url' => $url, 'position' => $pos, 'created_at' => now()]);
        redirect('/bio');
    }

    public function updateLink(string $id): void
    {
        $page = self::page(Workspace::id()) ?? abort(404);
        [$label, $url] = $this->validLink();
        Db::update('bio_links', ['label' => $label, 'url' => $url, 'active' => input('active') ? 1 : 0], 'id = ? AND page_id = ?', [(int) $id, $page['id']]);
        redirect('/bio');
    }

    public function deleteLink(string $id): void
    {
        $page = self::page(Workspace::id()) ?? abort(404);
        Db::query('DELETE FROM bio_links WHERE id = ? AND page_id = ?', [(int) $id, $page['id']]);
        redirect('/bio');
    }

    public function reorder(): void
    {
        $page = self::page(Workspace::id()) ?? abort(404);
        foreach (array_values((array) input('order', [])) as $i => $id) {
            Db::update('bio_links', ['position' => $i], 'id = ? AND page_id = ?', [(int) $id, $page['id']]);
        }
        json_response(['ok' => true]);
    }

    private function validLink(): array
    {
        $label = mb_substr(trim((string) input('label', '')), 0, 80);
        $url = trim((string) input('url', ''));
        if ($url !== '' && !preg_match('#^(https?://|mailto:|tel:)#i', $url)) {
            $url = 'https://' . $url;
        }
        if ($label === '' || !filter_var(preg_replace('#^(mailto|tel):#i', 'http://x.y/', $url), FILTER_VALIDATE_URL)) {
            flash('error', 'Each link needs a label and a valid URL.');
            redirect('/bio');
        }
        return [$label, $url];
    }

    // ---------- Public ----------

    public function show(string $slug): void
    {
        $page = Db::one('SELECT * FROM bio_pages WHERE slug = ?', [$slug]);
        $preview = isset($_GET['preview']) && \App\Auth::user() && $page && Db::value('SELECT 1 FROM workspace_user WHERE workspace_id = ? AND user_id = ?', [$page['workspace_id'], \App\Auth::id()]);
        if (!$page || (!$page['published'] && !$preview)) {
            abort(404, 'This page doesn’t exist.');
        }
        if (!$preview) {
            self::count((int) $page['id'], 0);
        }
        $links = Db::all('SELECT * FROM bio_links WHERE page_id = ? AND active = 1 ORDER BY position, id', [$page['id']]);
        $socials = [];
        if ($page['show_socials']) {
            foreach (Db::all('SELECT provider, provider_id, name, username, data FROM accounts WHERE workspace_id = ? ORDER BY provider', [$page['workspace_id']]) as $a) {
                $u = self::profileUrl($a);
                if ($u && !isset($socials[$u])) {
                    $socials[$u] = $a;
                }
            }
        }
        $posts = [];
        if ($page['show_posts']) {
            $rows = Db::all(
                "SELECT p.id, p.content, p.media, (SELECT pa.url FROM post_accounts pa WHERE pa.post_id = p.id AND pa.url IS NOT NULL ORDER BY pa.published_at LIMIT 1) AS url
                 FROM posts p WHERE p.workspace_id = ? AND p.status = 'published' AND p.media != '[]' ORDER BY p.published_at DESC LIMIT 30",
                [$page['workspace_id']]
            );
            foreach ($rows as $r) {
                $m = Publisher::resolveMedia(array_slice(json_arr($r['media']), 0, 1))[0] ?? null;
                if ($m && str_starts_with($m['mime'], 'image/') && $r['url']) {
                    $posts[] = ['img' => url(substr($m['path'], strlen(BASE_PATH . '/public/'))), 'url' => $r['url'], 'alt' => str_limit($r['content'], 100)];
                }
                if (count($posts) >= 9) {
                    break;
                }
            }
        }
        $avatar = $page['avatar_media_id'] ? (Publisher::resolveMedia([(int) $page['avatar_media_id']])[0] ?? null) : null;
        header('Cache-Control: no-store');
        view('bio/public', [
            'page' => $page,
            'theme' => json_arr($page['theme']),
            'links' => $links,
            'socials' => $socials,
            'posts' => $posts,
            'avatar' => $avatar ? url(substr($avatar['path'], strlen(BASE_PATH . '/public/'))) : null,
            'preview' => $preview,
            'themes' => self::THEMES,
        ], null);
    }

    public function go(string $slug, string $id): void
    {
        $link = Db::one('SELECT l.* FROM bio_links l JOIN bio_pages p ON p.id = l.page_id WHERE p.slug = ? AND l.id = ? AND l.active = 1 AND p.published = 1', [$slug, (int) $id]);
        if (!$link) {
            abort(404);
        }
        self::count((int) $link['page_id'], (int) $link['id']);
        header('Location: ' . $link['url'], true, 302);
        exit;
    }

    private static function count(int $pageId, int $linkId): void
    {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        if ($ua === '' || preg_match('/bot|crawl|spider|preview|facebookexternalhit|slurp|curl|wget/', $ua)) {
            return;
        }
        Db::query(
            'INSERT INTO bio_stats (page_id, link_id, date, count) VALUES (?, ?, ?, 1) ON CONFLICT(page_id, link_id, date) DO UPDATE SET count = count + 1',
            [$pageId, $linkId, gmdate('Y-m-d')]
        );
    }

    public static function profileUrl(array $a): ?string
    {
        $u = $a['username'] ?? '';
        $data = json_arr($a['data'] ?? null);
        return match ($a['provider']) {
            'x' => $u ? "https://x.com/$u" : null,
            'bluesky' => $u ? "https://bsky.app/profile/$u" : null,
            'mastodon' => str_contains($u, '@') ? 'https://' . explode('@', $u)[1] . '/@' . explode('@', $u)[0] : null,
            'instagram' => $u ? "https://www.instagram.com/$u/" : null,
            'threads' => $u ? "https://www.threads.net/@$u" : null,
            'tiktok' => $u ? "https://www.tiktok.com/@$u" : null,
            'youtube' => $u ? "https://www.youtube.com/@$u" : 'https://www.youtube.com/channel/' . $a['provider_id'],
            'pinterest' => $u ? "https://www.pinterest.com/$u/" : null,
            'facebook' => 'https://www.facebook.com/' . ($u ?: $a['provider_id']),
            'linkedin' => str_starts_with((string) $a['provider_id'], 'organization:') && $u && $u !== 'Company page' ? "https://www.linkedin.com/company/$u/" : null,
            default => null,
        };
    }
}
