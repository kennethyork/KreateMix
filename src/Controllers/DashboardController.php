<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Workspace;

final class DashboardController
{
    public function index(): void
    {
        $wsId = Workspace::id();
        $counts = array_column(Db::all('SELECT status, COUNT(*) AS n FROM posts WHERE workspace_id = ? GROUP BY status', [$wsId]), 'n', 'status');
        $upcoming = Db::all(
            "SELECT * FROM posts WHERE workspace_id = ? AND status IN ('scheduled', 'pending_approval') ORDER BY scheduled_at LIMIT 8",
            [$wsId]
        );
        $failed = Db::all("SELECT * FROM posts WHERE workspace_id = ? AND status = 'failed' ORDER BY updated_at DESC LIMIT 5", [$wsId]);
        $recent = Db::all("SELECT * FROM posts WHERE workspace_id = ? AND status = 'published' ORDER BY published_at DESC LIMIT 5", [$wsId]);
        $accounts = PostsController::accounts();
        $publishedWeek = (int) Db::value(
            "SELECT COUNT(*) FROM posts WHERE workspace_id = ? AND status = 'published' AND published_at >= ?",
            [$wsId, gmdate('Y-m-d H:i:s', time() - 7 * 86400)]
        );

        // Is cron running? Warn admins if scheduled posts are overdue.
        $overdue = (int) Db::value("SELECT COUNT(*) FROM posts WHERE workspace_id = ? AND status = 'scheduled' AND scheduled_at < ?", [$wsId, gmdate('Y-m-d H:i:s', time() - 300)]);
        $cronStamp = BASE_PATH . '/storage/cron.stamp';
        $cronAge = is_file($cronStamp) ? time() - filemtime($cronStamp) : null;

        view('dashboard', [
            'title' => 'Dashboard',
            'counts' => $counts,
            'upcoming' => PostsController::decorate($upcoming),
            'failed' => PostsController::decorate($failed),
            'recent' => PostsController::decorate($recent),
            'accounts' => $accounts,
            'unauthorized' => array_values(array_filter($accounts, fn($a) => !$a['authorized'])),
            'publishedWeek' => $publishedWeek,
            'overdue' => $overdue,
            'cronAge' => $cronAge,
            'user' => Auth::user(),
        ]);
    }
}
