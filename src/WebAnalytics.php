<?php
declare(strict_types=1);

namespace App;

/** Cookieless website analytics: collection and reporting. */
final class WebAnalytics
{
    public const SOCIAL = [
        'x.com' => 'X', 't.co' => 'X', 'twitter.com' => 'X', 'facebook.com' => 'Facebook', 'm.facebook.com' => 'Facebook', 'l.facebook.com' => 'Facebook', 'lm.facebook.com' => 'Facebook',
        'instagram.com' => 'Instagram', 'l.instagram.com' => 'Instagram', 'linkedin.com' => 'LinkedIn', 'lnkd.in' => 'LinkedIn', 'youtube.com' => 'YouTube', 'm.youtube.com' => 'YouTube',
        'threads.net' => 'Threads', 'threads.com' => 'Threads', 'l.threads.com' => 'Threads', 'tiktok.com' => 'TikTok', 'pinterest.com' => 'Pinterest', 'pin.it' => 'Pinterest',
        'bsky.app' => 'Bluesky', 'reddit.com' => 'Reddit', 'out.reddit.com' => 'Reddit', 'news.ycombinator.com' => 'Hacker News',
    ];

    /** Handles one beacon. Returns nothing; bad input is ignored. */
    public static function collect(array $d, array $server): void
    {
        $site = Db::one('SELECT * FROM sites WHERE site_key = ?', [(string) ($d['s'] ?? '')]);
        if (!$site) {
            return;
        }
        $pid = substr(preg_replace('/[^a-z0-9]/i', '', (string) ($d['p'] ?? '')), 0, 40);
        if (($d['e'] ?? '') === 'leave') {
            $dur = max(0, min(3600 * 4, (int) ($d['d'] ?? 0)));
            Db::query('UPDATE pageviews SET duration = ? WHERE site_id = ? AND pid = ? AND ts >= ?', [$dur, $site['id'], $pid, gmdate('Y-m-d H:i:s', time() - 6 * 3600)]);
            return;
        }
        $ua = (string) ($server['HTTP_USER_AGENT'] ?? '');
        if ($ua === '' || preg_match('/bot|crawl|spider|slurp|headless|preview|monitor|lighthouse/i', $ua)) {
            return;
        }
        $url = parse_url((string) ($d['u'] ?? ''));
        if (!$url || empty($url['host'])) {
            return;
        }
        // Only count pages from the registered domain (and its subdomains).
        $host = strtolower(preg_replace('/^www\./', '', $url['host']));
        $domain = strtolower(preg_replace('/^www\./', '', $site['domain']));
        if ($host !== $domain && !str_ends_with($host, '.' . $domain) && !Config::get('debug')) {
            return;
        }
        parse_str($url['query'] ?? '', $q);
        $refHost = strtolower((string) parse_url((string) ($d['r'] ?? ''), PHP_URL_HOST));
        $refHost = preg_replace('/^www\./', '', $refHost);
        if ($refHost === $host || $refHost === $domain || str_ends_with($refHost, '.' . $domain)) {
            $refHost = ''; // internal navigation
        }
        $ip = $server['HTTP_CF_CONNECTING_IP'] ?? $server['REMOTE_ADDR'] ?? '';
        $visitor = substr(hash('sha256', Config::get('app_key') . gmdate('Y-m-d') . $site['id'] . $ip . $ua), 0, 20);
        $width = (int) ($d['w'] ?? 0);
        Db::insert('pageviews', [
            'site_id' => $site['id'],
            'ts' => now(),
            'path' => mb_substr(($url['path'] ?? '/') ?: '/', 0, 300),
            'title' => mb_substr((string) ($d['t'] ?? ''), 0, 200) ?: null,
            'referrer' => $refHost ?: null,
            'utm_source' => isset($q['utm_source']) ? mb_substr((string) $q['utm_source'], 0, 100) : null,
            'utm_medium' => isset($q['utm_medium']) ? mb_substr((string) $q['utm_medium'], 0, 100) : null,
            'utm_campaign' => isset($q['utm_campaign']) ? mb_substr((string) $q['utm_campaign'], 0, 100) : null,
            'device' => preg_match('/iPad|Tablet/i', $ua) || ($width >= 600 && $width < 1024 && preg_match('/Android/i', $ua)) ? 'tablet' : (preg_match('/Mobi|iPhone|Android/i', $ua) ? 'mobile' : 'desktop'),
            'browser' => self::browser($ua),
            'os' => self::os($ua),
            'country' => isset($server['HTTP_CF_IPCOUNTRY']) && preg_match('/^[A-Z]{2}$/', $server['HTTP_CF_IPCOUNTRY']) ? $server['HTTP_CF_IPCOUNTRY'] : null,
            'visitor' => $visitor,
            'pid' => $pid ?: null,
        ]);
    }

    private static function browser(string $ua): string
    {
        return match (true) {
            str_contains($ua, 'Edg/') => 'Edge',
            str_contains($ua, 'OPR/') || str_contains($ua, 'Opera') => 'Opera',
            str_contains($ua, 'SamsungBrowser') => 'Samsung Internet',
            str_contains($ua, 'Firefox/') => 'Firefox',
            str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
            str_contains($ua, 'Safari/') => 'Safari',
            default => 'Other',
        };
    }

    private static function os(string $ua): string
    {
        return match (true) {
            (bool) preg_match('/iPhone|iPad|iPod/', $ua) => 'iOS',
            str_contains($ua, 'Android') => 'Android',
            str_contains($ua, 'Windows') => 'Windows',
            str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
            str_contains($ua, 'CrOS') => 'ChromeOS',
            str_contains($ua, 'Linux') => 'Linux',
            default => 'Other',
        };
    }

    public static function report(int $siteId, string $from, string $to, string $tz): array
    {
        $zone = new \DateTimeZone($tz);
        $utc = new \DateTimeZone('UTC');
        $a = (new \DateTimeImmutable("$from 00:00:00", $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $b = (new \DateTimeImmutable("$to 23:59:59", $zone))->setTimezone($utc)->format('Y-m-d H:i:s');
        $w = 'site_id = ? AND ts BETWEEN ? AND ?';
        $p = [$siteId, $a, $b];
        $tot = Db::one("SELECT COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors, AVG(NULLIF(duration, 0)) AS dur FROM pageviews WHERE $w", $p);
        // Bounce: visitors (per day) who viewed one page.
        $bounce = Db::one("SELECT SUM(n = 1) AS b, COUNT(*) AS t FROM (SELECT COUNT(*) AS n FROM pageviews WHERE $w GROUP BY visitor)", $p);
        $offset = $zone->getOffset(new \DateTimeImmutable($from)) / 60;
        $mod = sprintf('%+d minutes', $offset);
        $daily = Db::all("SELECT date(ts, '$mod') AS d, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM pageviews WHERE $w GROUP BY d", $p);
        $byDay = array_column($daily, null, 'd');
        $series = $viewSeries = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
            $d = date('Y-m-d', $t);
            $series[] = ['date' => $d, 'value' => (int) ($byDay[$d]['visitors'] ?? 0)];
            $viewSeries[] = ['date' => $d, 'value' => (int) ($byDay[$d]['views'] ?? 0)];
        }
        $top = fn(string $col, int $n = 10) => Db::all("SELECT $col AS k, COUNT(*) AS views, COUNT(DISTINCT visitor) AS visitors FROM pageviews WHERE $w AND $col IS NOT NULL AND $col != '' GROUP BY $col ORDER BY visitors DESC, views DESC LIMIT $n", $p);
        $refs = $top('referrer', 50);
        $social = [];
        foreach ($refs as $r) {
            $net = self::SOCIAL[$r['k']] ?? null;
            if ($net) {
                $social[$net] = ($social[$net] ?? 0) + (int) $r['visitors'];
            }
        }
        // UTM sources count as social when they name a network (e.g. utm_source=instagram).
        foreach ($top('utm_source', 50) as $u) {
            foreach (array_unique(self::SOCIAL) as $net) {
                if (strcasecmp($u['k'], $net) === 0 || strcasecmp(str_replace(' ', '', $u['k']), $net) === 0) {
                    $social[$net] = ($social[$net] ?? 0) + (int) $u['visitors'];
                }
            }
        }
        arsort($social);
        return [
            'views' => (int) $tot['views'],
            'visitors' => (int) $tot['visitors'],
            'duration' => $tot['dur'] !== null ? (int) round((float) $tot['dur']) : null,
            'bounce' => $bounce['t'] ? round($bounce['b'] / $bounce['t'] * 100) : null,
            'series' => $series,
            'viewSeries' => $viewSeries,
            'pages' => $top('path'),
            'referrers' => array_slice($refs, 0, 10),
            'social' => $social,
            'campaigns' => $top('utm_campaign'),
            'sources' => $top('utm_source'),
            'devices' => $top('device', 5),
            'browsers' => $top('browser', 6),
            'os' => $top('os', 6),
            'countries' => $top('country', 10),
        ];
    }

    public static function prune(): void
    {
        Db::query('DELETE FROM pageviews WHERE ts < ?', [gmdate('Y-m-d H:i:s', time() - 400 * 86400)]);
    }
}
