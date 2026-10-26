<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\WebAnalytics;
use App\Workspace;

final class WebsitesController
{
    public function index(): void
    {
        $sites = Db::all('SELECT * FROM sites WHERE workspace_id = ? ORDER BY name', [Workspace::id()]);
        $siteId = (int) input('site', 0) ?: (int) ($sites[0]['id'] ?? 0);
        $site = null;
        foreach ($sites as $s) {
            if ((int) $s['id'] === $siteId) {
                $site = $s;
            }
        }
        [$from, $to, $days] = AnalyticsController::range();
        view('websites/index', [
            'title' => 'Website',
            'sites' => $sites,
            'site' => $site,
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'r' => $site ? WebAnalytics::report((int) $site['id'], $from, $to, user_tz()) : null,
            'snippet' => $site ? '<script defer src="' . abs_url('assets/t.js') . '" data-site="' . $site['site_key'] . '"></script>' : '',
        ]);
    }

    public function store(): void
    {
        $domain = strtolower(trim((string) input('domain', '')));
        $domain = preg_replace('#^https?://#', '', $domain);
        $domain = preg_replace('#^www\.#', '', explode('/', $domain)[0]);
        if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$|^localhost(:\d+)?$/', $domain)) {
            flash('error', 'Enter a domain like example.com.');
            redirect('/websites');
        }
        $id = Db::insert('sites', [
            'workspace_id' => Workspace::id(),
            'name' => mb_substr((string) input('name', '') ?: $domain, 0, 80),
            'domain' => $domain,
            'site_key' => bin2hex(random_bytes(10)),
            'created_at' => now(),
        ]);
        flash('success', 'Site added. Paste the snippet into your site’s <head>.');
        redirect('/websites', ['site' => $id]);
    }

    public function delete(string $id): void
    {
        Db::query('DELETE FROM sites WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Site removed with its stats.');
        redirect('/websites');
    }

    /** Public beacon endpoint for t.js. Always answers 204 so the browser never waits. */
    public function collect(): void
    {
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            $raw = (string) file_get_contents('php://input', false, null, 0, 8192);
            $data = json_decode($raw, true);
            if (is_array($data)) {
                try {
                    WebAnalytics::collect($data, $_SERVER);
                } catch (\Throwable $e) {
                    error_log('collect: ' . $e->getMessage());
                }
            }
        }
        http_response_code(204);
        exit;
    }
}
