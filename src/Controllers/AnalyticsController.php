<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\Metrics;
use App\Providers\Registry;
use App\Workspace;

final class AnalyticsController
{
    public function index(): void
    {
        $wsId = Workspace::id();
        $days = in_array((int) input('days', 30), [7, 30, 90], true) ? (int) input('days', 30) : 30;
        $accounts = PostsController::accounts();
        $accountId = (int) input('account', 0);
        $ids = $accountId ? [$accountId] : array_map('intval', array_column($accounts, 'id'));
        $ids = array_values(array_intersect($ids, array_map('intval', array_column($accounts, 'id'))));
        $since = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $in = $ids ? implode(',', $ids) : '0';

        // Followers per day: sum across selected accounts, carrying each account's last known value forward.
        $rows = Db::all("SELECT account_id, date, followers FROM account_metrics WHERE account_id IN ($in) AND date >= ? AND followers IS NOT NULL ORDER BY date", [gmdate('Y-m-d', strtotime($since) - 30 * 86400)]);
        $byAccount = [];
        foreach ($rows as $r) {
            $byAccount[$r['account_id']][$r['date']] = (int) $r['followers'];
        }
        $series = [];
        $last = [];
        for ($t = strtotime($since . ' -30 days'); $t <= time(); $t += 86400) {
            $d = gmdate('Y-m-d', $t);
            foreach ($byAccount as $aid => $vals) {
                if (isset($vals[$d])) {
                    $last[$aid] = $vals[$d];
                }
            }
            if ($d >= $since) {
                $series[] = ['date' => $d, 'value' => $last ? array_sum($last) : null];
            }
        }
        $known = array_values(array_filter($series, fn($s) => $s['value'] !== null));
        $followersNow = $known ? end($known)['value'] : null;
        $followersStart = $known ? $known[0]['value'] : null;

        $published = Db::all(
            "SELECT p.id, p.content, p.media, pa.account_id, pa.published_at, pa.url, a.provider, a.name AS account_name, pm.data AS metrics
             FROM post_accounts pa JOIN posts p ON p.id = pa.post_id JOIN accounts a ON a.id = pa.account_id
             LEFT JOIN post_metrics pm ON pm.post_id = pa.post_id AND pm.account_id = pa.account_id
             WHERE p.workspace_id = ? AND pa.account_id IN ($in) AND pa.status = 'published' AND pa.published_at >= ?
             ORDER BY pa.published_at DESC",
            [$wsId, $since . ' 00:00:00']
        );
        $totals = ['likes' => 0, 'replies' => 0, 'shares' => 0, 'impressions' => 0];
        $perDay = [];
        foreach ($published as &$p) {
            $m = json_arr($p['metrics']);
            $p['engagement'] = Metrics::engagement($m);
            $p['m'] = $m;
            $totals['likes'] += (int) ($m['likes'] ?? $m['reactions'] ?? 0);
            $totals['replies'] += (int) ($m['replies'] ?? $m['comments'] ?? 0);
            $totals['shares'] += (int) ($m['shares'] ?? $m['reposts'] ?? 0);
            $totals['impressions'] += (int) ($m['impressions'] ?? $m['views'] ?? 0);
            $day = substr($p['published_at'], 0, 10);
            $perDay[$day] = ($perDay[$day] ?? 0) + 1;
        }
        unset($p);
        $top = $published;
        usort($top, fn($a, $b) => $b['engagement'] <=> $a['engagement']);

        $postSeries = [];
        for ($t = strtotime($since); $t <= time(); $t += 86400) {
            $d = gmdate('Y-m-d', $t);
            $postSeries[] = ['date' => $d, 'value' => $perDay[$d] ?? 0];
        }

        $providers = [];
        foreach (Registry::all() as $k => $c) {
            $providers[$k] = ['name' => $c::name(), 'color' => $c::color()];
        }

        view('analytics/index', [
            'title' => 'Analytics',
            'days' => $days,
            'accounts' => $accounts,
            'accountId' => $accountId,
            'providers' => $providers,
            'followers' => $series,
            'followersNow' => $followersNow,
            'followersChange' => ($followersNow !== null && $followersStart !== null) ? $followersNow - $followersStart : null,
            'postSeries' => $postSeries,
            'postCount' => count($published),
            'totals' => $totals,
            'top' => array_slice(array_filter($top, fn($p) => $p['engagement'] > 0), 0, 10),
            'lastImport' => Db::value("SELECT MAX(date) FROM account_metrics WHERE account_id IN ($in)"),
        ]);
    }
}
