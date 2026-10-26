<?php
declare(strict_types=1);

namespace App;

use App\Providers\ProviderException;
use App\Providers\Registry;

/**
 * Public stats for accounts you don't own. Only networks that expose them are supported.
 * X, Instagram and YouTube borrow a connected account's token from the same workspace.
 */
final class Competitors
{
    public const NETWORKS = [
        'bluesky' => ['label' => 'Bluesky', 'placeholder' => 'handle.bsky.social'],
        'mastodon' => ['label' => 'Mastodon', 'placeholder' => 'user@mastodon.social'],
        'x' => ['label' => 'X', 'placeholder' => 'username'],
        'instagram' => ['label' => 'Instagram', 'placeholder' => 'username (business or creator)'],
        'youtube' => ['label' => 'YouTube', 'placeholder' => '@handle'],
    ];

    /**
     * Fetches profile + recent post stats. Returns ['external_id','name','avatar','followers','posts','recent' => [['date','engagement']...]].
     */
    public static function fetch(int $workspaceId, string $network, string $handle): array
    {
        $handle = trim($handle);
        return match ($network) {
            'bluesky' => self::bluesky(ltrim($handle, '@')),
            'mastodon' => self::mastodon(ltrim($handle, '@')),
            'x' => self::x($workspaceId, ltrim($handle, '@')),
            'instagram' => self::instagram($workspaceId, ltrim($handle, '@')),
            'youtube' => self::youtube($workspaceId, $handle),
            default => throw new ProviderException('Unsupported network.'),
        };
    }

    private static function bluesky(string $handle): array
    {
        $p = Http::get('https://public.api.bsky.app/xrpc/app.bsky.actor.getProfile', ['query' => ['actor' => $handle]]);
        if (!Http::ok($p)) {
            throw new ProviderException('Bluesky account not found.');
        }
        $feed = Http::get('https://public.api.bsky.app/xrpc/app.bsky.feed.getAuthorFeed', ['query' => ['actor' => $handle, 'limit' => 30, 'filter' => 'posts_no_replies']])['json']['feed'] ?? [];
        $recent = [];
        foreach ($feed as $item) {
            if (isset($item['reason'])) {
                continue; // reposts
            }
            $post = $item['post'];
            $recent[] = ['date' => substr($post['indexedAt'] ?? '', 0, 10), 'engagement' => (int) ($post['likeCount'] ?? 0) + (int) ($post['repostCount'] ?? 0) + (int) ($post['replyCount'] ?? 0) + (int) ($post['quoteCount'] ?? 0)];
        }
        $j = $p['json'];
        return ['external_id' => $j['did'], 'name' => ($j['displayName'] ?? '') ?: $j['handle'], 'avatar' => $j['avatar'] ?? null, 'followers' => (int) ($j['followersCount'] ?? 0), 'posts' => (int) ($j['postsCount'] ?? 0), 'recent' => $recent];
    }

    private static function mastodon(string $handle): array
    {
        if (!preg_match('/^([^@\s]+)@([a-z0-9.-]+\.[a-z]{2,})$/i', $handle, $m)) {
            throw new ProviderException('Use the full handle, like user@mastodon.social.');
        }
        $server = strtolower($m[2]);
        $acct = Http::get("https://$server/api/v1/accounts/lookup", ['query' => ['acct' => $m[1]]]);
        if (!Http::ok($acct)) {
            throw new ProviderException('Mastodon account not found.');
        }
        $a = $acct['json'];
        $statuses = Http::get("https://$server/api/v1/accounts/{$a['id']}/statuses", ['query' => ['limit' => 30, 'exclude_replies' => 'true', 'exclude_reblogs' => 'true']])['json'];
        $recent = [];
        foreach (is_array($statuses) ? $statuses : [] as $s) {
            $recent[] = ['date' => substr($s['created_at'] ?? '', 0, 10), 'engagement' => (int) ($s['favourites_count'] ?? 0) + (int) ($s['reblogs_count'] ?? 0) + (int) ($s['replies_count'] ?? 0)];
        }
        return ['external_id' => $server . ':' . $a['id'], 'name' => ($a['display_name'] ?? '') ?: $a['username'], 'avatar' => $a['avatar'] ?? null, 'followers' => (int) ($a['followers_count'] ?? 0), 'posts' => (int) ($a['statuses_count'] ?? 0), 'recent' => $recent];
    }

    /** Returns a provider for any authorized account of $provider in the workspace. */
    private static function borrow(int $workspaceId, string $provider, string $why): array
    {
        $acc = Db::one('SELECT * FROM accounts WHERE workspace_id = ? AND provider = ? AND authorized = 1 ORDER BY id LIMIT 1', [$workspaceId, $provider]);
        if (!$acc) {
            throw new ProviderException($why);
        }
        $p = Registry::withFreshToken($acc);
        return [$acc, $p instanceof Providers\OAuth2Provider ? $p->lookupToken() : ''];
    }

    private static function x(int $workspaceId, string $username): array
    {
        [, $token] = self::borrow($workspaceId, 'x', 'Connect an X account in this workspace to track X competitors.');
        $h = ['Authorization' => "Bearer $token"];
        $u = Http::get('https://api.x.com/2/users/by/username/' . rawurlencode($username), ['query' => ['user.fields' => 'public_metrics,profile_image_url'], 'headers' => $h]);
        $d = $u['json']['data'] ?? null;
        if (!$d) {
            throw new ProviderException('X account not found' . (isset($u['json']['title']) ? ': ' . $u['json']['title'] : '.'));
        }
        $tweets = Http::get("https://api.x.com/2/users/{$d['id']}/tweets", ['query' => ['max_results' => 20, 'exclude' => 'replies,retweets', 'tweet.fields' => 'public_metrics,created_at'], 'headers' => $h])['json']['data'] ?? [];
        $recent = array_map(fn($t) => ['date' => substr($t['created_at'] ?? '', 0, 10), 'engagement' => array_sum(array_intersect_key($t['public_metrics'] ?? [], array_flip(['like_count', 'retweet_count', 'reply_count', 'quote_count'])))], $tweets);
        $pm = $d['public_metrics'] ?? [];
        return ['external_id' => $d['id'], 'name' => $d['name'], 'avatar' => $d['profile_image_url'] ?? null, 'followers' => (int) ($pm['followers_count'] ?? 0), 'posts' => (int) ($pm['tweet_count'] ?? 0), 'recent' => $recent];
    }

    /** Instagram business discovery needs a Facebook Page linked to an Instagram professional account. */
    private static function instagram(int $workspaceId, string $username): array
    {
        [$page, $token] = self::borrow($workspaceId, 'facebook', 'Connect a Facebook Page that’s linked to an Instagram business account to track Instagram competitors.');
        $graph = 'https://graph.facebook.com/' . Providers\Facebook::VERSION;
        $ig = Http::get("$graph/{$page['provider_id']}", ['query' => ['fields' => 'instagram_business_account', 'access_token' => $token]])['json']['instagram_business_account']['id'] ?? null;
        if (!$ig) {
            throw new ProviderException('Your connected Facebook Page isn’t linked to an Instagram business account.');
        }
        $fields = 'business_discovery.username(' . $username . '){username,name,profile_picture_url,followers_count,media_count,media.limit(25){like_count,comments_count,timestamp}}';
        $r = Http::get("$graph/$ig", ['query' => ['fields' => $fields, 'access_token' => $token]]);
        $bd = $r['json']['business_discovery'] ?? null;
        if (!$bd) {
            throw new ProviderException('Instagram account not found, or it isn’t a business/creator account. ' . ($r['json']['error']['message'] ?? ''));
        }
        $recent = array_map(fn($m) => ['date' => substr($m['timestamp'] ?? '', 0, 10), 'engagement' => (int) ($m['like_count'] ?? 0) + (int) ($m['comments_count'] ?? 0)], $bd['media']['data'] ?? []);
        return ['external_id' => $bd['id'] ?? $username, 'name' => ($bd['name'] ?? '') ?: $bd['username'], 'avatar' => $bd['profile_picture_url'] ?? null, 'followers' => (int) ($bd['followers_count'] ?? 0), 'posts' => (int) ($bd['media_count'] ?? 0), 'recent' => $recent];
    }

    private static function youtube(int $workspaceId, string $handle): array
    {
        [, $token] = self::borrow($workspaceId, 'youtube', 'Connect a YouTube channel in this workspace to track YouTube competitors.');
        $h = ['Authorization' => "Bearer $token"];
        $handle = '@' . ltrim(preg_replace('#^https?://(www\.)?youtube\.com/#', '', $handle), '@');
        $c = Http::get('https://www.googleapis.com/youtube/v3/channels', ['query' => ['part' => 'snippet,statistics,contentDetails', 'forHandle' => $handle], 'headers' => $h])['json']['items'][0] ?? null;
        if (!$c) {
            throw new ProviderException('YouTube channel not found.');
        }
        $uploads = $c['contentDetails']['relatedPlaylists']['uploads'] ?? '';
        $items = $uploads ? (Http::get('https://www.googleapis.com/youtube/v3/playlistItems', ['query' => ['part' => 'contentDetails', 'playlistId' => $uploads, 'maxResults' => 20], 'headers' => $h])['json']['items'] ?? []) : [];
        $ids = array_column(array_column($items, 'contentDetails'), 'videoId');
        $recent = [];
        if ($ids) {
            $vids = Http::get('https://www.googleapis.com/youtube/v3/videos', ['query' => ['part' => 'statistics,snippet', 'id' => implode(',', $ids)], 'headers' => $h])['json']['items'] ?? [];
            foreach ($vids as $v) {
                $s = $v['statistics'] ?? [];
                $recent[] = ['date' => substr($v['snippet']['publishedAt'] ?? '', 0, 10), 'engagement' => (int) ($s['likeCount'] ?? 0) + (int) ($s['commentCount'] ?? 0)];
            }
        }
        $s = $c['statistics'] ?? [];
        return ['external_id' => $c['id'], 'name' => $c['snippet']['title'] ?? $handle, 'avatar' => $c['snippet']['thumbnails']['default']['url'] ?? null, 'followers' => (int) ($s['subscriberCount'] ?? 0), 'posts' => (int) ($s['videoCount'] ?? 0), 'recent' => $recent];
    }

    /** Saves today's snapshot for one competitor. */
    public static function refresh(array $c): void
    {
        try {
            $d = self::fetch((int) $c['workspace_id'], $c['provider'], $c['handle']);
        } catch (\Throwable $e) {
            Db::update('competitors', ['error' => mb_substr($e->getMessage(), 0, 300)], 'id = ?', [$c['id']]);
            return;
        }
        $recent = $d['recent'];
        $cut = gmdate('Y-m-d', time() - 30 * 86400);
        $last30 = array_filter($recent, fn($r) => $r['date'] >= $cut);
        $data = [
            'avg_engagement' => $recent ? round(array_sum(array_column($recent, 'engagement')) / count($recent), 1) : null,
            'posts_30d' => count($last30) >= count($recent) && count($recent) >= 20 ? null : count($last30), // null = at least that many (sample full)
            'sample' => count($recent),
        ];
        Db::update('competitors', ['external_id' => $d['external_id'], 'name' => mb_substr($d['name'], 0, 200), 'avatar' => $d['avatar'], 'error' => null], 'id = ?', [$c['id']]);
        Db::query(
            'INSERT INTO competitor_metrics (competitor_id, date, followers, posts, data) VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(competitor_id, date) DO UPDATE SET followers = excluded.followers, posts = excluded.posts, data = excluded.data',
            [$c['id'], gmdate('Y-m-d'), $d['followers'], $d['posts'], json_encode($data)]
        );
    }

    /** Once a day per competitor, from cron. */
    public static function refreshDue(): int
    {
        $rows = Db::all(
            "SELECT c.* FROM competitors c WHERE NOT EXISTS (SELECT 1 FROM competitor_metrics m WHERE m.competitor_id = c.id AND m.date = ?) LIMIT 25",
            [gmdate('Y-m-d')]
        );
        foreach ($rows as $c) {
            self::refresh($c);
        }
        return count($rows);
    }
}
