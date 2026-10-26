<?php
declare(strict_types=1);

namespace App;

use App\Providers\Registry;

/** Hashtag tracker: collects public posts using a hashtag on Bluesky, Mastodon, X and Instagram. */
final class Hashtags
{
    public const NETWORKS = [
        'bluesky' => 'Bluesky',
        'mastodon' => 'Mastodon',
        'x' => 'X',
        'instagram' => 'Instagram',
    ];

    public static function clean(string $tag): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}_]/u', '', ltrim(trim($tag), '#')));
    }

    /** Runs each active hashtag at most every 30 minutes. */
    public static function checkDue(): int
    {
        $rows = Db::all('SELECT * FROM hashtags WHERE active = 1 AND (last_checked_at IS NULL OR last_checked_at < ?) LIMIT 20', [gmdate('Y-m-d H:i:s', time() - 1800)]);
        $n = 0;
        foreach ($rows as $h) {
            $n += self::check($h);
        }
        return $n;
    }

    /** Fetches new posts for one hashtag. Returns how many were new. */
    public static function check(array $h): int
    {
        $new = 0;
        $errors = [];
        foreach (json_arr($h['networks']) as $net) {
            try {
                $posts = match ($net) {
                    'bluesky' => self::bluesky((int) $h['workspace_id'], $h['tag']),
                    'mastodon' => self::mastodon((int) $h['workspace_id'], $h['tag']),
                    'x' => self::x((int) $h['workspace_id'], $h['tag']),
                    'instagram' => self::instagram((int) $h['workspace_id'], $h['tag']),
                    default => [],
                };
                foreach ($posts as $p) {
                    $inserted = Db::query(
                        'INSERT INTO hashtag_posts (hashtag_id, network, external_id, author_handle, author_name, author_avatar, text, url, likes, reposts, replies, posted_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                         ON CONFLICT(hashtag_id, network, external_id) DO UPDATE SET likes = excluded.likes, reposts = excluded.reposts, replies = excluded.replies',
                        [$h['id'], $net, $p['id'], mb_substr($p['handle'], 0, 200), mb_substr($p['name'], 0, 200), $p['avatar'], mb_substr($p['text'], 0, 3000), $p['url'],
                         $p['likes'], $p['reposts'], $p['replies'], $p['posted_at']]
                    );
                    $new += $inserted->rowCount() > 0 ? 1 : 0;
                }
            } catch (\Throwable $e) {
                $errors[] = self::NETWORKS[$net] . ': ' . $e->getMessage();
            }
        }
        Db::update('hashtags', ['last_checked_at' => now(), 'error' => $errors ? mb_substr(implode(' · ', $errors), 0, 500) : null], 'id = ?', [$h['id']]);
        return $new;
    }

    private static function ts(?string $iso): string
    {
        $t = $iso ? strtotime($iso) : false;
        return gmdate('Y-m-d H:i:s', $t ?: time());
    }

    private static function bluesky(int $wsId, string $tag): array
    {
        $query = ['q' => '#' . $tag, 'tag' => $tag, 'sort' => 'latest', 'limit' => 100];
        $acc = Db::one("SELECT * FROM accounts WHERE workspace_id = ? AND provider = 'bluesky' AND authorized = 1 LIMIT 1", [$wsId]);
        if ($acc) {
            // Search through the user's own PDS (it proxies to the AppView); public search may be rate limited.
            $p = Registry::forAccount($acc);
            $r = (new \ReflectionMethod($p, 'xrpc'))->invoke($p, 'GET', 'app.bsky.feed.searchPosts', ['query' => $query]);
        } else {
            $res = Http::get('https://public.api.bsky.app/xrpc/app.bsky.feed.searchPosts', ['query' => $query]);
            if (!Http::ok($res)) {
                throw new \RuntimeException('search needs a connected Bluesky account (HTTP ' . $res['status'] . ')');
            }
            $r = $res['json'];
        }
        $out = [];
        foreach ($r['posts'] ?? [] as $post) {
            $a = $post['author'] ?? [];
            $out[] = [
                'id' => $post['uri'], 'handle' => $a['handle'] ?? '', 'name' => $a['displayName'] ?? '', 'avatar' => $a['avatar'] ?? null,
                'text' => $post['record']['text'] ?? '', 'url' => 'https://bsky.app/profile/' . ($a['handle'] ?? '') . '/post/' . substr($post['uri'], strrpos($post['uri'], '/') + 1),
                'likes' => (int) ($post['likeCount'] ?? 0), 'reposts' => (int) ($post['repostCount'] ?? 0) + (int) ($post['quoteCount'] ?? 0), 'replies' => (int) ($post['replyCount'] ?? 0),
                'posted_at' => self::ts($post['record']['createdAt'] ?? $post['indexedAt'] ?? null),
            ];
        }
        return $out;
    }

    private static function mastodon(int $wsId, string $tag): array
    {
        $acc = Db::one("SELECT data FROM accounts WHERE workspace_id = ? AND provider = 'mastodon' LIMIT 1", [$wsId]);
        $server = json_arr($acc['data'] ?? null)['server'] ?? 'mastodon.social';
        $res = Http::get("https://$server/api/v1/timelines/tag/" . rawurlencode($tag), ['query' => ['limit' => 40]]);
        if (!Http::ok($res)) {
            throw new \RuntimeException("$server returned HTTP {$res['status']}");
        }
        $out = [];
        foreach ($res['json'] as $s) {
            $a = $s['account'] ?? [];
            $out[] = [
                'id' => $s['uri'] ?? $s['id'], 'handle' => $a['acct'] ?? '', 'name' => $a['display_name'] ?? '', 'avatar' => $a['avatar'] ?? null,
                'text' => trim(html_entity_decode(strip_tags(str_replace(['<br>', '<br />', '</p><p>'], "\n", $s['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')),
                'url' => $s['url'] ?? null, 'likes' => (int) ($s['favourites_count'] ?? 0), 'reposts' => (int) ($s['reblogs_count'] ?? 0), 'replies' => (int) ($s['replies_count'] ?? 0),
                'posted_at' => self::ts($s['created_at'] ?? null),
            ];
        }
        return $out;
    }

    private static function x(int $wsId, string $tag): array
    {
        [, $token] = Competitors::borrow($wsId, 'x', 'connect an X account (and an API plan with search).');
        $res = Http::get('https://api.x.com/2/tweets/search/recent', [
            'query' => ['query' => '#' . $tag . ' -is:retweet', 'max_results' => 100, 'tweet.fields' => 'public_metrics,created_at,author_id', 'expansions' => 'author_id', 'user.fields' => 'username,name,profile_image_url'],
            'headers' => ['Authorization' => "Bearer $token"],
        ]);
        if (!Http::ok($res)) {
            throw new \RuntimeException($res['json']['detail'] ?? $res['json']['title'] ?? 'HTTP ' . $res['status']);
        }
        $users = array_column($res['json']['includes']['users'] ?? [], null, 'id');
        $out = [];
        foreach ($res['json']['data'] ?? [] as $t) {
            $u = $users[$t['author_id']] ?? [];
            $m = $t['public_metrics'] ?? [];
            $out[] = [
                'id' => $t['id'], 'handle' => $u['username'] ?? '', 'name' => $u['name'] ?? '', 'avatar' => $u['profile_image_url'] ?? null, 'text' => $t['text'],
                'url' => 'https://x.com/' . ($u['username'] ?? 'i') . '/status/' . $t['id'],
                'likes' => (int) ($m['like_count'] ?? 0), 'reposts' => (int) ($m['retweet_count'] ?? 0) + (int) ($m['quote_count'] ?? 0), 'replies' => (int) ($m['reply_count'] ?? 0),
                'posted_at' => self::ts($t['created_at'] ?? null),
            ];
        }
        return $out;
    }

    private static function instagram(int $wsId, string $tag): array
    {
        [$page, $token] = Competitors::borrow($wsId, 'facebook', 'connect a Facebook Page linked to an Instagram business account.');
        $graph = 'https://graph.facebook.com/' . Providers\Facebook::VERSION;
        $ig = Http::get("$graph/{$page['provider_id']}", ['query' => ['fields' => 'instagram_business_account', 'access_token' => $token]])['json']['instagram_business_account']['id'] ?? null;
        if (!$ig) {
            throw new \RuntimeException('the connected Facebook Page isn’t linked to an Instagram business account.');
        }
        $hid = Http::get("$graph/ig_hashtag_search", ['query' => ['user_id' => $ig, 'q' => $tag, 'access_token' => $token]])['json']['data'][0]['id'] ?? null;
        if (!$hid) {
            throw new \RuntimeException('hashtag not found (Instagram allows 30 different hashtags per week).');
        }
        $res = Http::get("$graph/$hid/recent_media", ['query' => ['user_id' => $ig, 'fields' => 'id,caption,like_count,comments_count,timestamp,permalink', 'limit' => 50, 'access_token' => $token]]);
        if (!Http::ok($res)) {
            throw new \RuntimeException($res['json']['error']['message'] ?? 'HTTP ' . $res['status']);
        }
        return array_map(fn($m) => [
            'id' => $m['id'], 'handle' => '', 'name' => '', 'avatar' => null, 'text' => $m['caption'] ?? '', 'url' => $m['permalink'] ?? null,
            'likes' => (int) ($m['like_count'] ?? 0), 'reposts' => 0, 'replies' => (int) ($m['comments_count'] ?? 0), 'posted_at' => self::ts($m['timestamp'] ?? null),
        ], $res['json']['data'] ?? []);
    }

    public static function report(int $hashtagId, string $fromUtc, string $toUtc, array $days, string $tz): array
    {
        $w = 'hashtag_id = ? AND posted_at BETWEEN ? AND ?';
        $p = [$hashtagId, $fromUtc, $toUtc];
        $tot = Db::one("SELECT COUNT(*) AS posts, COUNT(DISTINCT network || author_handle) AS authors, COALESCE(SUM(likes + reposts + replies), 0) AS engagement FROM hashtag_posts WHERE $w", $p);
        $byNet = Db::all("SELECT network, COUNT(*) AS posts, SUM(likes + reposts + replies) AS engagement FROM hashtag_posts WHERE $w GROUP BY network ORDER BY posts DESC", $p);
        $rows = Db::all("SELECT posted_at FROM hashtag_posts WHERE $w", $p);
        $per = array_fill_keys($days, 0);
        foreach ($rows as $r) {
            $d = local_time($r['posted_at'], 'Y-m-d', $tz);
            if (isset($per[$d])) {
                $per[$d]++;
            }
        }
        return [
            'posts' => (int) $tot['posts'],
            'authors' => (int) $tot['authors'],
            'engagement' => (int) $tot['engagement'],
            'networks' => $byNet,
            'series' => array_map(fn($d) => ['date' => $d, 'value' => $per[$d]], $days),
            'top' => Db::all("SELECT * FROM hashtag_posts WHERE $w ORDER BY (likes + reposts + replies) DESC LIMIT 10", $p),
            'contributors' => Db::all("SELECT network, author_handle, MAX(author_name) AS author_name, MAX(author_avatar) AS author_avatar, COUNT(*) AS posts, SUM(likes + reposts + replies) AS engagement
                FROM hashtag_posts WHERE $w AND author_handle != '' GROUP BY network, author_handle ORDER BY posts DESC, engagement DESC LIMIT 10", $p),
            'latest' => Db::all("SELECT * FROM hashtag_posts WHERE $w ORDER BY posted_at DESC LIMIT 20", $p),
        ];
    }
}
