<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\Workspace;

final class CalendarController
{
    public function index(): void
    {
        $tz = new \DateTimeZone(user_tz());
        $view = input('view') === 'week' ? 'week' : 'month';
        $today = new \DateTimeImmutable('today', $tz);
        $date = (string) input('date', '');
        $anchor = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? new \DateTimeImmutable($date, $tz) : $today;

        if ($view === 'month') {
            $first = $anchor->modify('first day of this month');
            $start = $first->modify('-' . (int) $first->format('w') . ' days');
            $last = $anchor->modify('last day of this month');
            $end = $last->modify('+' . (6 - (int) $last->format('w')) . ' days')->modify('+1 day');
            $prev = $first->modify('-1 month')->format('Y-m-d');
            $next = $first->modify('+1 month')->format('Y-m-d');
            $label = $first->format('F Y');
        } else {
            $start = $anchor->modify('-' . (int) $anchor->format('w') . ' days');
            $end = $start->modify('+7 days');
            $prev = $start->modify('-7 days')->format('Y-m-d');
            $next = $start->modify('+7 days')->format('Y-m-d');
            $label = $start->format('M j') . ' – ' . $end->modify('-1 day')->format('M j, Y');
        }

        $utc = new \DateTimeZone('UTC');
        $posts = Db::all(
            "SELECT * FROM posts WHERE workspace_id = ?
             AND COALESCE(published_at, scheduled_at) >= ? AND COALESCE(published_at, scheduled_at) < ?
             ORDER BY COALESCE(published_at, scheduled_at)",
            [Workspace::id(), $start->setTimezone($utc)->format('Y-m-d H:i:s'), $end->setTimezone($utc)->format('Y-m-d H:i:s')]
        );
        $posts = PostsController::decorate($posts);

        $byDay = [];
        foreach ($posts as $p) {
            $when = $p['published_at'] ?? $p['scheduled_at'];
            $day = local_time($when, 'Y-m-d');
            $p['time'] = local_time($when, 'g:i A');
            $p['hour'] = (int) local_time($when, 'G');
            $byDay[$day][] = $p;
        }

        $days = [];
        for ($d = $start; $d < $end; $d = $d->modify('+1 day')) {
            $days[] = $d;
        }

        view('calendar/index', [
            'title' => 'Calendar',
            'view' => $view,
            'days' => $days,
            'byDay' => $byDay,
            'anchor' => $anchor,
            'today' => $today->format('Y-m-d'),
            'label' => $label,
            'prev' => $prev,
            'next' => $next,
        ]);
    }
}
