<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\Hashtags;
use App\Workspace;

final class HashtagsController
{
    public function index(): void
    {
        $tags = Db::all('SELECT h.*, (SELECT COUNT(*) FROM hashtag_posts WHERE hashtag_id = h.id) AS total FROM hashtags h WHERE workspace_id = ? ORDER BY tag', [Workspace::id()]);
        $id = (int) input('tag', 0) ?: (int) ($tags[0]['id'] ?? 0);
        $current = null;
        foreach ($tags as $t) {
            if ((int) $t['id'] === $id) {
                $current = $t;
            }
        }
        [$from, $to, $days] = AnalyticsController::range();
        $tz = user_tz();
        $dayList = [];
        for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
            $dayList[] = date('Y-m-d', $t);
        }
        view('hashtags/index', [
            'title' => 'Hashtag tracker',
            'tags' => $tags,
            'current' => $current,
            'days' => $days,
            'from' => $from,
            'to' => $to,
            'r' => $current ? Hashtags::report((int) $current['id'], to_utc("$from 00:00"), to_utc("$to 23:59:59"), $dayList, $tz) : null,
            'networks' => Hashtags::NETWORKS,
        ]);
    }

    public function store(): void
    {
        $tag = Hashtags::clean((string) input('tag', ''));
        $nets = array_values(array_intersect((array) input('networks', []), array_keys(Hashtags::NETWORKS)));
        if ($tag === '' || !$nets) {
            flash('error', 'Enter a hashtag and pick at least one network.');
            redirect('/hashtags');
        }
        Db::query('INSERT OR IGNORE INTO hashtags (workspace_id, tag, networks, created_at) VALUES (?, ?, ?, ?)', [Workspace::id(), $tag, json_encode($nets), now()]);
        $h = Db::one('SELECT * FROM hashtags WHERE workspace_id = ? AND tag = ?', [Workspace::id(), $tag]);
        Db::update('hashtags', ['networks' => json_encode($nets), 'active' => 1], 'id = ?', [$h['id']]);
        $h['networks'] = json_encode($nets);
        $n = Hashtags::check($h);
        $err = Db::value('SELECT error FROM hashtags WHERE id = ?', [$h['id']]);
        flash($err ? 'error' : 'success', "Tracking #$tag. Found $n posts so far." . ($err ? " Some networks failed: $err" : ''));
        redirect('/hashtags', ['tag' => $h['id']]);
    }

    public function toggle(string $id): void
    {
        Db::query('UPDATE hashtags SET active = 1 - active WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        redirect('/hashtags', ['tag' => (int) $id]);
    }

    public function refresh(string $id): void
    {
        $h = Db::one('SELECT * FROM hashtags WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if ($h) {
            $n = Hashtags::check($h);
            flash('success', "$n new posts.");
        }
        redirect('/hashtags', ['tag' => (int) $id]);
    }

    public function delete(string $id): void
    {
        Db::query('DELETE FROM hashtags WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Stopped tracking and deleted collected posts.');
        redirect('/hashtags');
    }

    public function export(string $id): void
    {
        $h = Db::one('SELECT * FROM hashtags WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$h) {
            abort(404);
        }
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="hashtag-' . $h['tag'] . '.csv"');
        $out = fopen('php://output', 'w');
        fputcsv($out, ['network', 'posted_at_utc', 'author', 'text', 'likes', 'reposts', 'replies', 'url']);
        foreach (Db::all('SELECT * FROM hashtag_posts WHERE hashtag_id = ? ORDER BY posted_at DESC', [$h['id']]) as $p) {
            fputcsv($out, [$p['network'], $p['posted_at'], $p['author_handle'], $p['text'], $p['likes'], $p['reposts'], $p['replies'], $p['url']]);
        }
        fclose($out);
        exit;
    }
}
