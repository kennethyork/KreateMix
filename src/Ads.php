<?php
declare(strict_types=1);

namespace App;

/** Ads reporting for Meta (Facebook/Instagram), Google Ads and TikTok Ads. Campaign-level daily stats. */
final class Ads
{
    public const PROVIDERS = [
        'meta_ads' => ['name' => 'Meta Ads', 'color' => '#1877f2', 'abbr' => 'f'],
        'google_ads' => ['name' => 'Google Ads', 'color' => '#4285f4', 'abbr' => 'G'],
        'tiktok_ads' => ['name' => 'TikTok Ads', 'color' => '#000000', 'abbr' => 'TT'],
    ];
    private const GOOGLE_VERSION = 'v26';
    private const META_GRAPH = 'https://graph.facebook.com/' . Providers\Facebook::VERSION;
    private const TIKTOK = 'https://business-api.tiktok.com/open_api/v1.3';

    public static function ready(string $provider): bool
    {
        return match ($provider) {
            'meta_ads' => Settings::has('meta_ads', ['app_id', 'app_secret']),
            'google_ads' => Settings::has('google_ads', ['client_id', 'client_secret', 'developer_token']),
            'tiktok_ads' => Settings::has('tiktok_ads', ['app_id', 'app_secret']),
            default => false,
        };
    }

    public static function authUrl(string $provider, string $state): string
    {
        $redirect = abs_url('ads/callback/' . $provider);
        $s = Settings::get($provider);
        return match ($provider) {
            'meta_ads' => 'https://www.facebook.com/' . Providers\Facebook::VERSION . '/dialog/oauth?' . http_build_query(['client_id' => $s['app_id'], 'redirect_uri' => $redirect, 'state' => $state, 'scope' => 'ads_read,business_management', 'response_type' => 'code']),
            'google_ads' => 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query(['client_id' => $s['client_id'], 'redirect_uri' => $redirect, 'state' => $state, 'scope' => 'https://www.googleapis.com/auth/adwords', 'response_type' => 'code', 'access_type' => 'offline', 'prompt' => 'consent']),
            'tiktok_ads' => 'https://business-api.tiktok.com/portal/auth?' . http_build_query(['app_id' => $s['app_id'], 'redirect_uri' => $redirect, 'state' => $state]),
        };
    }

    /** Completes OAuth and returns ad accounts: [['external_id','name','currency','token' => array,'data' => array]] */
    public static function connect(string $provider, array $query): array
    {
        $redirect = abs_url('ads/callback/' . $provider);
        $s = Settings::get($provider);
        if ($provider === 'meta_ads') {
            $r = Http::get(self::META_GRAPH . '/oauth/access_token', ['query' => ['client_id' => $s['app_id'], 'client_secret' => $s['app_secret'], 'redirect_uri' => $redirect, 'code' => $query['code'] ?? '']]);
            $short = $r['json']['access_token'] ?? throw new \RuntimeException('Meta: ' . ($r['json']['error']['message'] ?? 'login failed'));
            $l = Http::get(self::META_GRAPH . '/oauth/access_token', ['query' => ['grant_type' => 'fb_exchange_token', 'client_id' => $s['app_id'], 'client_secret' => $s['app_secret'], 'fb_exchange_token' => $short]])['json'];
            $token = ['access_token' => $l['access_token'] ?? $short, 'expires_at' => time() + (int) ($l['expires_in'] ?? 5184000)];
            $accs = Http::get(self::META_GRAPH . '/me/adaccounts', ['query' => ['fields' => 'id,name,currency,account_status', 'limit' => 200, 'access_token' => $token['access_token']]])['json']['data'] ?? [];
            return array_map(fn($a) => ['external_id' => $a['id'], 'name' => $a['name'] ?? $a['id'], 'currency' => $a['currency'] ?? 'USD', 'token' => $token, 'data' => []], $accs);
        }
        if ($provider === 'google_ads') {
            $r = Http::post('https://oauth2.googleapis.com/token', ['form' => ['grant_type' => 'authorization_code', 'code' => $query['code'] ?? '', 'redirect_uri' => $redirect, 'client_id' => $s['client_id'], 'client_secret' => $s['client_secret']]]);
            if (empty($r['json']['access_token'])) {
                throw new \RuntimeException('Google: ' . ($r['json']['error_description'] ?? 'login failed'));
            }
            $token = ['access_token' => $r['json']['access_token'], 'refresh_token' => $r['json']['refresh_token'] ?? null, 'expires_at' => time() + (int) $r['json']['expires_in']];
            $list = self::google($token['access_token'], 'GET', '/customers:listAccessibleCustomers');
            $out = [];
            foreach ($list['resourceNames'] ?? [] as $rn) {
                $cid = substr($rn, strlen('customers/'));
                try {
                    $rows = self::googleQuery($token['access_token'], $cid, 'SELECT customer.id, customer.descriptive_name, customer.currency_code, customer.manager FROM customer');
                    $c = $rows[0]['customer'] ?? [];
                    if (!empty($c['manager'])) {
                        continue; // manager accounts have no campaigns of their own
                    }
                    $out[] = ['external_id' => $cid, 'name' => $c['descriptiveName'] ?? $cid, 'currency' => $c['currencyCode'] ?? 'USD', 'token' => $token, 'data' => []];
                } catch (\RuntimeException) {
                    $out[] = ['external_id' => $cid, 'name' => 'Customer ' . $cid, 'currency' => 'USD', 'token' => $token, 'data' => []];
                }
            }
            return $out;
        }
        // TikTok
        $r = Http::post(self::TIKTOK . '/oauth2/access_token/', ['json' => ['app_id' => $s['app_id'], 'secret' => $s['app_secret'], 'auth_code' => $query['auth_code'] ?? $query['code'] ?? '']]);
        $data = $r['json']['data'] ?? [];
        if (empty($data['access_token'])) {
            throw new \RuntimeException('TikTok: ' . ($r['json']['message'] ?? 'login failed'));
        }
        $token = ['access_token' => $data['access_token']];
        $ids = $data['advertiser_ids'] ?? [];
        $info = $ids ? (Http::get(self::TIKTOK . '/advertiser/info/', ['query' => ['advertiser_ids' => json_encode(array_map('strval', $ids))], 'headers' => ['Access-Token' => $token['access_token']]])['json']['data']['list'] ?? []) : [];
        $byId = array_column($info, null, 'advertiser_id');
        return array_map(fn($id) => ['external_id' => (string) $id, 'name' => $byId[$id]['name'] ?? ('Advertiser ' . $id), 'currency' => $byId[$id]['currency'] ?? 'USD', 'token' => $token, 'data' => []], $ids);
    }

    private static function google(string $token, string $method, string $path, ?array $json = null): array
    {
        $s = Settings::get('google_ads');
        $headers = ['Authorization' => "Bearer $token", 'developer-token' => $s['developer_token']];
        if (!empty($s['login_customer_id'])) {
            $headers['login-customer-id'] = preg_replace('/\D/', '', $s['login_customer_id']);
        }
        $r = Http::request($method, 'https://googleads.googleapis.com/' . self::GOOGLE_VERSION . $path, array_filter(['headers' => $headers, 'json' => $json]));
        if (!Http::ok($r)) {
            $err = $r['json'][0]['error'] ?? $r['json']['error'] ?? [];
            throw new \RuntimeException('Google Ads: ' . ($err['details'][0]['errors'][0]['message'] ?? $err['message'] ?? 'HTTP ' . $r['status']));
        }
        return $r['json'];
    }

    private static function googleQuery(string $token, string $customerId, string $gaql): array
    {
        $batches = self::google($token, 'POST', "/customers/$customerId/googleAds:searchStream", ['query' => $gaql]);
        $rows = [];
        foreach ($batches as $b) {
            foreach ($b['results'] ?? [] as $row) {
                $rows[] = $row;
            }
        }
        return $rows;
    }

    /** Refreshes tokens where possible and returns the access token. */
    private static function accessToken(array $acc): string
    {
        $t = Crypt::decryptArray($acc['token']);
        if ($acc['provider'] === 'google_ads' && ($t['expires_at'] ?? 0) < time() + 120) {
            $s = Settings::get('google_ads');
            $r = Http::post('https://oauth2.googleapis.com/token', ['form' => ['grant_type' => 'refresh_token', 'refresh_token' => $t['refresh_token'] ?? '', 'client_id' => $s['client_id'], 'client_secret' => $s['client_secret']]]);
            if (empty($r['json']['access_token'])) {
                Db::update('ad_accounts', ['authorized' => 0], 'id = ?', [$acc['id']]);
                throw new \RuntimeException('Google Ads access expired. Reconnect.');
            }
            $t['access_token'] = $r['json']['access_token'];
            $t['expires_at'] = time() + (int) $r['json']['expires_in'];
            Db::update('ad_accounts', ['token' => Crypt::encrypt($t)], 'id = ?', [$acc['id']]);
        }
        if ($acc['provider'] === 'meta_ads' && ($t['expires_at'] ?? PHP_INT_MAX) < time()) {
            Db::update('ad_accounts', ['authorized' => 0], 'id = ?', [$acc['id']]);
            throw new \RuntimeException('Meta Ads access expired. Reconnect.');
        }
        return (string) ($t['access_token'] ?? '');
    }

    /**
     * Fetches daily campaign stats between two dates.
     * @return array<array{date: string, campaign_id: string, campaign_name: string, spend: float, impressions: int, clicks: int, conversions: float}>
     */
    public static function fetch(array $acc, string $from, string $to): array
    {
        $token = self::accessToken($acc);
        $out = [];
        if ($acc['provider'] === 'meta_ads') {
            $url = self::META_GRAPH . '/' . $acc['external_id'] . '/insights';
            $query = ['level' => 'campaign', 'fields' => 'campaign_id,campaign_name,spend,impressions,clicks,actions', 'time_increment' => 1,
                'time_range' => json_encode(['since' => $from, 'until' => $to]), 'limit' => 500, 'access_token' => $token];
            while ($url) {
                $r = Http::get($url, ['query' => $query]);
                if (!Http::ok($r)) {
                    if (($r['json']['error']['code'] ?? 0) === 190) {
                        Db::update('ad_accounts', ['authorized' => 0], 'id = ?', [$acc['id']]);
                    }
                    throw new \RuntimeException('Meta Ads: ' . ($r['json']['error']['message'] ?? 'HTTP ' . $r['status']));
                }
                foreach ($r['json']['data'] ?? [] as $row) {
                    $conv = 0;
                    foreach ($row['actions'] ?? [] as $a) {
                        if (in_array($a['action_type'], ['purchase', 'lead', 'complete_registration'], true)) {
                            $conv += (float) $a['value'];
                        }
                    }
                    $out[] = ['date' => $row['date_start'], 'campaign_id' => (string) $row['campaign_id'], 'campaign_name' => $row['campaign_name'] ?? '', 'spend' => (float) ($row['spend'] ?? 0),
                        'impressions' => (int) ($row['impressions'] ?? 0), 'clicks' => (int) ($row['clicks'] ?? 0), 'conversions' => $conv];
                }
                $url = $r['json']['paging']['next'] ?? null;
                $query = [];
            }
        } elseif ($acc['provider'] === 'google_ads') {
            $rows = self::googleQuery($token, $acc['external_id'],
                "SELECT campaign.id, campaign.name, segments.date, metrics.cost_micros, metrics.impressions, metrics.clicks, metrics.conversions FROM campaign WHERE segments.date BETWEEN '$from' AND '$to'");
            foreach ($rows as $row) {
                $out[] = ['date' => $row['segments']['date'], 'campaign_id' => (string) $row['campaign']['id'], 'campaign_name' => $row['campaign']['name'] ?? '',
                    'spend' => ((int) ($row['metrics']['costMicros'] ?? 0)) / 1e6, 'impressions' => (int) ($row['metrics']['impressions'] ?? 0),
                    'clicks' => (int) ($row['metrics']['clicks'] ?? 0), 'conversions' => (float) ($row['metrics']['conversions'] ?? 0)];
            }
        } else {
            $page = 1;
            do {
                $r = Http::get(self::TIKTOK . '/report/integrated/get/', ['headers' => ['Access-Token' => $token], 'query' => [
                    'advertiser_id' => $acc['external_id'], 'report_type' => 'BASIC', 'data_level' => 'AUCTION_CAMPAIGN',
                    'dimensions' => json_encode(['campaign_id', 'stat_time_day']),
                    'metrics' => json_encode(['campaign_name', 'spend', 'impressions', 'clicks', 'conversion']),
                    'start_date' => $from, 'end_date' => $to, 'page' => $page, 'page_size' => 1000,
                ]]);
                if (($r['json']['code'] ?? -1) !== 0) {
                    throw new \RuntimeException('TikTok Ads: ' . ($r['json']['message'] ?? 'HTTP ' . $r['status']));
                }
                foreach ($r['json']['data']['list'] ?? [] as $row) {
                    $m = $row['metrics'] ?? [];
                    $out[] = ['date' => substr($row['dimensions']['stat_time_day'], 0, 10), 'campaign_id' => (string) $row['dimensions']['campaign_id'], 'campaign_name' => $m['campaign_name'] ?? '',
                        'spend' => (float) ($m['spend'] ?? 0), 'impressions' => (int) ($m['impressions'] ?? 0), 'clicks' => (int) ($m['clicks'] ?? 0), 'conversions' => (float) ($m['conversion'] ?? 0)];
                }
                $total = (int) ($r['json']['data']['page_info']['total_page'] ?? 1);
                $page++;
            } while ($page <= $total && $page <= 20);
        }
        return $out;
    }

    /** Imports the last 30 days the first time, then the last 3 days (late conversions settle) every 6 hours. */
    public static function importDue(): int
    {
        $n = 0;
        $rows = Db::all("SELECT * FROM ad_accounts WHERE authorized = 1 AND (last_import_at IS NULL OR last_import_at < ?)", [gmdate('Y-m-d H:i:s', time() - 6 * 3600)]);
        foreach ($rows as $acc) {
            $from = gmdate('Y-m-d', time() - ($acc['last_import_at'] ? 3 : 30) * 86400);
            try {
                self::import($acc, $from, gmdate('Y-m-d'));
                $n++;
            } catch (\Throwable $e) {
                log_event('warning', "Ads import failed for {$acc['provider']} {$acc['external_id']}: " . $e->getMessage());
                Db::update('ad_accounts', ['last_import_at' => now()], 'id = ?', [$acc['id']]);
            }
        }
        return $n;
    }

    public static function import(array $acc, string $from, string $to): void
    {
        $rows = self::fetch($acc, $from, $to);
        Db::transaction(function () use ($acc, $rows, $from, $to) {
            Db::query('DELETE FROM ad_metrics WHERE ad_account_id = ? AND date BETWEEN ? AND ?', [$acc['id'], $from, $to]);
            foreach ($rows as $r) {
                Db::query(
                    'INSERT OR REPLACE INTO ad_metrics (ad_account_id, date, campaign_id, campaign_name, spend, impressions, clicks, conversions) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$acc['id'], $r['date'], $r['campaign_id'], mb_substr($r['campaign_name'], 0, 200), $r['spend'], $r['impressions'], $r['clicks'], $r['conversions']]
                );
            }
            Db::update('ad_accounts', ['last_import_at' => now()], 'id = ?', [$acc['id']]);
        });
    }
}
