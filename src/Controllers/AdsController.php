<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Ads;
use App\Crypt;
use App\Db;
use App\Workspace;

final class AdsController
{
    public function index(): void
    {
        [$from, $to, $days] = AnalyticsController::range();
        $wsId = Workspace::id();
        $accounts = Db::all('SELECT * FROM ad_accounts WHERE workspace_id = ? ORDER BY provider, name', [$wsId]);
        $pick = (int) input('ad_account', 0);
        $ids = $pick ? [$pick] : array_map('intval', array_column($accounts, 'id'));
        $ids = array_values(array_intersect($ids, array_map('intval', array_column($accounts, 'id'))));
        $in = $ids ? implode(',', $ids) : '0';

        $totals = Db::one("SELECT COALESCE(SUM(spend),0) AS spend, COALESCE(SUM(impressions),0) AS impressions, COALESCE(SUM(clicks),0) AS clicks, COALESCE(SUM(conversions),0) AS conversions FROM ad_metrics WHERE ad_account_id IN ($in) AND date BETWEEN ? AND ?", [$from, $to]);
        $daily = array_column(Db::all("SELECT date, SUM(spend) AS spend FROM ad_metrics WHERE ad_account_id IN ($in) AND date BETWEEN ? AND ? GROUP BY date", [$from, $to]), 'spend', 'date');
        $series = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
            $d = date('Y-m-d', $t);
            $series[] = ['date' => $d, 'value' => round((float) ($daily[$d] ?? 0), 2)];
        }
        $campaigns = Db::all(
            "SELECT m.ad_account_id, a.provider, a.name AS account_name, a.currency, m.campaign_id, MAX(m.campaign_name) AS campaign_name,
                    SUM(m.spend) AS spend, SUM(m.impressions) AS impressions, SUM(m.clicks) AS clicks, SUM(m.conversions) AS conversions
             FROM ad_metrics m JOIN ad_accounts a ON a.id = m.ad_account_id
             WHERE m.ad_account_id IN ($in) AND m.date BETWEEN ? AND ? GROUP BY m.ad_account_id, m.campaign_id ORDER BY spend DESC LIMIT 100",
            [$from, $to]
        );
        $currencies = array_unique(array_column(array_filter($accounts, fn($a) => in_array((int) $a['id'], $ids, true)), 'currency'));
        $ready = [];
        foreach (array_keys(Ads::PROVIDERS) as $p) {
            $ready[$p] = Ads::ready($p);
        }
        view('ads/index', [
            'title' => 'Ads',
            'accounts' => $accounts,
            'pick' => $pick,
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'totals' => $totals,
            'series' => $series,
            'campaigns' => $campaigns,
            'currency' => count($currencies) === 1 ? reset($currencies) : null,
            'mixedCurrency' => count($currencies) > 1,
            'ready' => $ready,
        ]);
    }

    public function connect(string $provider): void
    {
        if (!isset(Ads::PROVIDERS[$provider]) || !Ads::ready($provider)) {
            flash('error', 'That ads platform isn’t set up. An instance admin can add it under Integrations.');
            redirect('/ads');
        }
        $state = bin2hex(random_bytes(16));
        $_SESSION['_ads'] = ['provider' => $provider, 'state' => $state];
        redirect(Ads::authUrl($provider, $state));
    }

    public function callback(string $provider): void
    {
        $pending = $_SESSION['_ads'] ?? null;
        unset($_SESSION['_ads']);
        if (!$pending || $pending['provider'] !== $provider || !hash_equals($pending['state'], (string) input('state', ''))) {
            flash('error', 'The connection expired. Try again.');
            redirect('/ads');
        }
        try {
            $found = Ads::connect($provider, $_GET);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/ads');
        }
        if (!$found) {
            flash('error', 'No ad accounts were found for that login.');
            redirect('/ads');
        }
        $_SESSION['_ads_choose'] = ['provider' => $provider, 'accounts' => Crypt::encrypt(json_encode($found))];
        redirect('/ads/choose');
    }

    public function choose(): void
    {
        $p = $_SESSION['_ads_choose'] ?? null;
        if (!$p) {
            redirect('/ads');
        }
        view('ads/choose', ['title' => 'Choose ad accounts', 'provider' => $p['provider'], 'accounts' => json_decode((string) Crypt::decrypt($p['accounts']), true) ?: []]);
    }

    public function chooseSubmit(): void
    {
        $p = $_SESSION['_ads_choose'] ?? null;
        unset($_SESSION['_ads_choose']);
        if (!$p) {
            redirect('/ads');
        }
        $picked = array_map('strval', (array) input('pick', []));
        $n = 0;
        foreach (json_decode((string) Crypt::decrypt($p['accounts']), true) ?: [] as $a) {
            if (!in_array((string) $a['external_id'], $picked, true)) {
                continue;
            }
            Db::query(
                'INSERT INTO ad_accounts (workspace_id, provider, external_id, name, currency, token, data, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT(workspace_id, provider, external_id) DO UPDATE SET name = excluded.name, currency = excluded.currency, token = excluded.token, authorized = 1',
                [Workspace::id(), $p['provider'], $a['external_id'], $a['name'], $a['currency'], Crypt::encrypt($a['token']), json_encode($a['data']), now()]
            );
            $n++;
        }
        flash('success', $n ? "Connected $n ad account" . ($n === 1 ? '' : 's') . '. The last 30 days import within a few minutes.' : 'Nothing selected.');
        redirect('/ads');
    }

    public function sync(string $id): void
    {
        $acc = Db::one('SELECT * FROM ad_accounts WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$acc) {
            abort(404);
        }
        try {
            Ads::import($acc, gmdate('Y-m-d', time() - 30 * 86400), gmdate('Y-m-d'));
            flash('success', 'Synced ' . $acc['name'] . '.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect('/ads');
    }

    public function delete(string $id): void
    {
        Db::query('DELETE FROM ad_accounts WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Ad account removed.');
        redirect('/ads');
    }
}
