<?php
declare(strict_types=1);

namespace App;

use App\Providers\Registry;

/** Builds analytics for a workspace and date range. Used by the Analytics page and reports. */
final class Analytics
{
    /**
     * @param int[] $accountIds accounts to include (already filtered to the workspace)
     * @param string $from Y-m-d (inclusive, in $tz)
     * @param string $to   Y-m-d (inclusive, in $tz)
     */
    public static function build(int $workspaceId, array $accountIds, string $from, string $to, string $tz): array
    {
        $in = $accountIds ? implode(',', array_map('intval', $accountIds)) : '0';
        $zone = new \DateTimeZone($tz);
        $utc = new \DateTimeZone('UTC');
        $fromUtc = (new \DateTimeImmutable($from . ' 00:00:00', $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $toUtc = (new \DateTimeImmutable($to . ' 23:59:59', $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $days = [];
        for ($d = new \DateTimeImmutable($from); $d <= new \DateTimeImmutable($to); $d = $d->modify('+1 day')) {
            $days[] = $d->format('Y-m-d');
        }

        $accounts = $accountIds ? Db::all("SELECT id, provider, name, username, avatar FROM accounts WHERE id IN ($in) ORDER BY provider, name") : [];

        // ---- Followers: per account, carry last value forward across days ----
        $rows = Db::all(
            "SELECT account_id, date, followers, data FROM account_metrics WHERE account_id IN ($in) AND date <= ? AND date >= ? ORDER BY date",
            [$to, (new \DateTimeImmutable($from))->modify('-60 days')->format('Y-m-d')]
        );
        $byAccount = [];
        foreach ($rows as $r) {
            if ($r['followers'] !== null) {
                $byAccount[$r['account_id']][$r['date']] = (int) $r['followers'];
            }
        }
        $last = [];
        $startVal = [];
        foreach ($byAccount as $aid => $vals) {
            foreach ($vals as $d => $v) {
                if ($d < $from) {
                    $last[$aid] = $v;
                }
            }
        }
        $followerSeries = [];
        foreach ($days as $d) {
            foreach ($byAccount as $aid => $vals) {
                if (isset($vals[$d])) {
                    $last[$aid] = $vals[$d];
                }
                if (!isset($startVal[$aid]) && isset($last[$aid])) {
                    $startVal[$aid] = $last[$aid];
                }
            }
            $followerSeries[] = ['date' => $d, 'value' => $last ? array_sum($last) : null];
        }
        $followersNow = $last ? array_sum($last) : null;
        $followersStart = $startVal ? array_sum($startVal) : null;

        // ---- Published posts with metrics ----
        $published = Db::all(
            "SELECT p.id, p.content, p.media, pa.account_id, pa.published_at, pa.url, a.provider, a.name AS account_name, pm.data AS metrics
             FROM post_accounts pa JOIN posts p ON p.id = pa.post_id JOIN accounts a ON a.id = pa.account_id
             LEFT JOIN post_metrics pm ON pm.post_id = pa.post_id AND pm.account_id = pa.account_id
             WHERE p.workspace_id = ? AND pa.account_id IN ($in) AND pa.status = 'published' AND pa.published_at BETWEEN ? AND ?
             ORDER BY pa.published_at DESC",
            [$workspaceId, $fromUtc, $toUtc]
        );
        $mediaTypes = self::mediaTypes($published);

        $totals = ['posts' => 0, 'likes' => 0, 'replies' => 0, 'shares' => 0, 'saves' => 0, 'clicks' => 0, 'impressions' => 0, 'engagement' => 0];
        $perAccount = [];
        foreach ($accounts as $a) {
            $perAccount[$a['id']] = $a + ['posts' => 0, 'engagement' => 0, 'impressions' => 0, 'likes' => 0, 'replies' => 0, 'shares' => 0,
                'followers' => $last[$a['id']] ?? null, 'followers_change' => isset($last[$a['id']], $startVal[$a['id']]) ? $last[$a['id']] - $startVal[$a['id']] : null];
        }
        $perDay = array_fill_keys($days, 0);
        $engDay = array_fill_keys($days, 0);
        $types = ['text' => ['posts' => 0, 'engagement' => 0], 'image' => ['posts' => 0, 'engagement' => 0], 'carousel' => ['posts' => 0, 'engagement' => 0], 'video' => ['posts' => 0, 'engagement' => 0]];
        $hashtags = [];
        foreach ($published as &$p) {
            $m = json_arr($p['metrics']);
            $p['m'] = $m;
            $p['engagement'] = Metrics::engagement($m);
            $p['impressions'] = (int) ($m['impressions'] ?? $m['views'] ?? 0);
            $likes = (int) ($m['likes'] ?? $m['reactions'] ?? 0);
            $replies = (int) ($m['replies'] ?? $m['comments'] ?? 0);
            $shares = (int) ($m['shares'] ?? $m['reposts'] ?? 0);
            $totals['posts']++;
            $totals['likes'] += $likes;
            $totals['replies'] += $replies;
            $totals['shares'] += $shares;
            $totals['saves'] += (int) ($m['saves'] ?? 0);
            $totals['clicks'] += (int) ($m['clicks'] ?? 0);
            $totals['impressions'] += $p['impressions'];
            $totals['engagement'] += $p['engagement'];

            if (isset($perAccount[$p['account_id']])) {
                $pa = &$perAccount[$p['account_id']];
                $pa['posts']++;
                $pa['engagement'] += $p['engagement'];
                $pa['impressions'] += $p['impressions'];
                $pa['likes'] += $likes;
                $pa['replies'] += $replies;
                $pa['shares'] += $shares;
                unset($pa);
            }
            $day = local_time($p['published_at'], 'Y-m-d', $tz);
            if (isset($perDay[$day])) {
                $perDay[$day]++;
                $engDay[$day] += $p['engagement'];
            }
            $type = $mediaTypes[$p['id']] ?? 'text';
            $p['type'] = $type;
            $types[$type]['posts']++;
            $types[$type]['engagement'] += $p['engagement'];

            if (preg_match_all('/(?<=^|\s)#([\p{L}\p{N}_]+)/u', $p['content'], $hm)) {
                foreach (array_unique(array_map('mb_strtolower', $hm[1])) as $tag) {
                    $hashtags[$tag] ??= ['tag' => $tag, 'posts' => 0, 'engagement' => 0];
                    $hashtags[$tag]['posts']++;
                    $hashtags[$tag]['engagement'] += $p['engagement'];
                }
            }
        }
        unset($p);

        // Per network
        $perNetwork = [];
        foreach ($perAccount as $a) {
            $k = $a['provider'];
            $perNetwork[$k] ??= ['provider' => $k, 'accounts' => 0, 'posts' => 0, 'engagement' => 0, 'impressions' => 0, 'followers' => null, 'followers_change' => null];
            $perNetwork[$k]['accounts']++;
            $perNetwork[$k]['posts'] += $a['posts'];
            $perNetwork[$k]['engagement'] += $a['engagement'];
            $perNetwork[$k]['impressions'] += $a['impressions'];
            if ($a['followers'] !== null) {
                $perNetwork[$k]['followers'] = ($perNetwork[$k]['followers'] ?? 0) + $a['followers'];
            }
            if ($a['followers_change'] !== null) {
                $perNetwork[$k]['followers_change'] = ($perNetwork[$k]['followers_change'] ?? 0) + $a['followers_change'];
            }
        }
        foreach ($perNetwork as &$n) {
            $n['rate'] = self::rate($n['engagement'], $n['impressions'], $n['followers'], $n['posts']);
        }
        unset($n);
        foreach ($perAccount as &$a) {
            $a['rate'] = self::rate($a['engagement'], $a['impressions'], $a['followers'], $a['posts']);
        }
        unset($a);

        foreach ($types as &$t) {
            $t['avg'] = $t['posts'] ? round($t['engagement'] / $t['posts'], 1) : 0;
        }
        unset($t);
        $hashtags = array_values(array_filter($hashtags, fn($h) => $h['posts'] >= 1));
        foreach ($hashtags as &$h) {
            $h['avg'] = round($h['engagement'] / $h['posts'], 1);
        }
        unset($h);
        usort($hashtags, fn($a, $b) => [$b['avg'], $b['posts']] <=> [$a['avg'], $a['posts']]);

        $top = $published;
        usort($top, fn($a, $b) => $b['engagement'] <=> $a['engagement']);

        return [
            'from' => $from,
            'to' => $to,
            'days' => count($days),
            'accounts' => $accounts,
            'followers' => $followerSeries,
            'followersNow' => $followersNow,
            'followersChange' => ($followersNow !== null && $followersStart !== null) ? $followersNow - $followersStart : null,
            'postSeries' => array_map(fn($d) => ['date' => $d, 'value' => $perDay[$d]], $days),
            'engagementSeries' => array_map(fn($d) => ['date' => $d, 'value' => $engDay[$d]], $days),
            'totals' => $totals,
            'rate' => self::rate($totals['engagement'], $totals['impressions'], $followersNow, $totals['posts']),
            'perNetwork' => array_values($perNetwork),
            'perAccount' => array_values($perAccount),
            'types' => $types,
            'hashtags' => array_slice($hashtags, 0, 15),
            'top' => array_slice(array_values(array_filter($top, fn($p) => $p['engagement'] > 0)), 0, 10),
            'audience' => self::audience($accountIds),
            'best' => self::bestTimes($workspaceId, $accountIds, $tz),
            'lastImport' => Db::value("SELECT MAX(date) FROM account_metrics WHERE account_id IN ($in)"),
        ];
    }

    /** Engagement rate: per impression when we have impressions, otherwise per follower per post. */
    private static function rate(int $engagement, int $impressions, ?int $followers, int $posts): ?float
    {
        if ($impressions > 0) {
            return round($engagement / $impressions * 100, 2);
        }
        if ($followers && $posts) {
            return round($engagement / $posts / $followers * 100, 2);
        }
        return null;
    }

    /** text | image | carousel | video for each post id. */
    private static function mediaTypes(array $posts): array
    {
        $ids = [];
        foreach ($posts as $p) {
            foreach (json_arr($p['media']) as $m) {
                $ids[(int) $m] = true;
            }
        }
        $mimes = $ids ? array_column(Db::all('SELECT id, mime FROM media WHERE id IN (' . implode(',', array_keys($ids)) . ')'), 'mime', 'id') : [];
        $out = [];
        foreach ($posts as $p) {
            $media = json_arr($p['media']);
            if (!$media) {
                $out[$p['id']] = 'text';
                continue;
            }
            $video = false;
            foreach ($media as $m) {
                if (str_starts_with($mimes[(int) $m] ?? '', 'video/')) {
                    $video = true;
                }
            }
            $out[$p['id']] = $video ? 'video' : (count($media) > 1 ? 'carousel' : 'image');
        }
        return $out;
    }

    /**
     * Average engagement by weekday and hour from the last 180 days of posts, in the viewer's timezone.
     * Returns ['grid' => [weekday 0-6][hour 0-23] => avg|null, 'top' => [['day','hour','avg','posts']], 'posts' => n]
     */
    public static function bestTimes(int $workspaceId, array $accountIds, string $tz): array
    {
        $in = $accountIds ? implode(',', array_map('intval', $accountIds)) : '0';
        $rows = Db::all(
            "SELECT pa.published_at, pm.data FROM post_accounts pa JOIN posts p ON p.id = pa.post_id
             JOIN post_metrics pm ON pm.post_id = pa.post_id AND pm.account_id = pa.account_id
             WHERE p.workspace_id = ? AND pa.account_id IN ($in) AND pa.status = 'published' AND pa.published_at >= ?",
            [$workspaceId, gmdate('Y-m-d H:i:s', time() - 180 * 86400)]
        );
        $sum = $count = [];
        foreach ($rows as $r) {
            $dow = (int) local_time($r['published_at'], 'w', $tz);
            $hour = (int) local_time($r['published_at'], 'G', $tz);
            $sum[$dow][$hour] = ($sum[$dow][$hour] ?? 0) + Metrics::engagement(json_arr($r['data']));
            $count[$dow][$hour] = ($count[$dow][$hour] ?? 0) + 1;
        }
        $grid = [];
        $cells = [];
        for ($d = 0; $d < 7; $d++) {
            for ($h = 0; $h < 24; $h++) {
                $n = $count[$d][$h] ?? 0;
                $avg = $n ? $sum[$d][$h] / $n : null;
                $grid[$d][$h] = $avg;
                if ($n) {
                    $cells[] = ['day' => $d, 'hour' => $h, 'avg' => round($avg, 1), 'posts' => $n];
                }
            }
        }
        // Prefer slots with more than one post so a single lucky post doesn't dominate.
        usort($cells, fn($a, $b) => [min($b['posts'], 3), $b['avg']] <=> [min($a['posts'], 3), $a['avg']]);
        return ['grid' => $grid, 'top' => count($rows) >= 8 ? array_slice($cells, 0, 3) : [], 'posts' => count($rows)];
    }

    /** Latest audience snapshot per account (age/gender/country/city where available). */
    private static function audience(array $accountIds): array
    {
        if (!$accountIds) {
            return [];
        }
        $in = implode(',', array_map('intval', $accountIds));
        $rows = Db::all(
            "SELECT aa.account_id, aa.data, a.name, a.provider FROM account_audience aa JOIN accounts a ON a.id = aa.account_id
             WHERE aa.account_id IN ($in) AND aa.date = (SELECT MAX(date) FROM account_audience WHERE account_id = aa.account_id)"
        );
        return array_map(fn($r) => ['account' => $r['name'], 'provider' => $r['provider'], 'data' => json_arr($r['data'])], $rows);
    }

    public static function dayName(int $d): string
    {
        return ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'][$d];
    }

    public static function hourLabel(int $h): string
    {
        return ($h % 12 ?: 12) . ($h < 12 ? ' AM' : ' PM');
    }

    public static function providerName(string $key): string
    {
        $c = Registry::class($key);
        return $c ? $c::name() : $key;
    }
}
