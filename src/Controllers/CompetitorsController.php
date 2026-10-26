<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Competitors;
use App\Db;
use App\Workspace;

final class CompetitorsController
{
    public function index(): void
    {
        $wsId = Workspace::id();
        $days = in_array((int) input('days', 30), [7, 30, 90], true) ? (int) input('days', 30) : 30;
        $since = gmdate('Y-m-d', time() - ($days - 1) * 86400);
        $rows = Db::all('SELECT * FROM competitors WHERE workspace_id = ? ORDER BY provider, name', [$wsId]);
        foreach ($rows as &$c) {
            $metrics = Db::all('SELECT date, followers, posts, data FROM competitor_metrics WHERE competitor_id = ? AND date >= ? ORDER BY date', [$c['id'], gmdate('Y-m-d', time() - ($days + 30) * 86400)]);
            $inRange = array_values(array_filter($metrics, fn($m) => $m['date'] >= $since));
            $latest = end($metrics) ?: null;
            $base = null;
            foreach ($metrics as $m) {
                if ($m['date'] <= $since) {
                    $base = $m;
                }
            }
            $base ??= $inRange[0] ?? null;
            $data = $latest ? json_arr($latest['data']) : [];
            $c['followers'] = $latest ? (int) $latest['followers'] : null;
            $c['change'] = ($latest && $base && $base !== $latest) ? (int) $latest['followers'] - (int) $base['followers'] : null;
            $c['avg_engagement'] = $data['avg_engagement'] ?? null;
            $c['posts_30d'] = $data['posts_30d'] ?? null;
            $c['sample'] = $data['sample'] ?? 0;
            $c['series'] = array_map(fn($m) => ['date' => $m['date'], 'value' => (int) $m['followers']], $inRange);
            $c['updated'] = $latest['date'] ?? null;
        }
        unset($c);

        // Your own accounts on the same networks, for comparison.
        $own = Db::all(
            "SELECT a.id, a.provider, a.name, a.username, a.avatar,
                    (SELECT followers FROM account_metrics m WHERE m.account_id = a.id AND followers IS NOT NULL ORDER BY date DESC LIMIT 1) AS followers
             FROM accounts a WHERE a.workspace_id = ? AND a.provider IN ('" . implode("','", array_keys(Competitors::NETWORKS)) . "')",
            [$wsId]
        );

        view('competitors/index', [
            'title' => 'Competitors',
            'competitors' => $rows,
            'own' => $own,
            'days' => $days,
            'networks' => Competitors::NETWORKS,
        ]);
    }

    public function store(): void
    {
        $network = (string) input('network', '');
        $handle = trim((string) input('handle', ''));
        if (!isset(Competitors::NETWORKS[$network]) || $handle === '') {
            flash('error', 'Pick a network and enter a handle.');
            redirect('/competitors');
        }
        $handle = mb_substr($handle, 0, 120);
        try {
            $d = Competitors::fetch(Workspace::id(), $network, $handle);
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
            redirect('/competitors');
        }
        Db::query('INSERT OR IGNORE INTO competitors (workspace_id, provider, handle, name, created_at) VALUES (?, ?, ?, ?, ?)', [Workspace::id(), $network, $handle, $d['name'], now()]);
        $c = Db::one('SELECT * FROM competitors WHERE workspace_id = ? AND provider = ? AND handle = ?', [Workspace::id(), $network, $handle]);
        Competitors::refresh($c);
        flash('success', 'Now tracking ' . $d['name'] . '. Growth shows up as daily snapshots build.');
        redirect('/competitors');
    }

    public function delete(string $id): void
    {
        Db::query('DELETE FROM competitors WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Stopped tracking.');
        redirect('/competitors');
    }

    public function refresh(string $id): void
    {
        $c = Db::one('SELECT * FROM competitors WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if ($c) {
            Competitors::refresh($c);
        }
        redirect('/competitors');
    }
}
