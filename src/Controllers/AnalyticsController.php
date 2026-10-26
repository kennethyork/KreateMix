<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Analytics;
use App\Workspace;

final class AnalyticsController
{
    /** Resolves ?days= or ?from=&to= into a Y-m-d range in the user's timezone. */
    public static function range(): array
    {
        $tz = new \DateTimeZone(user_tz());
        $today = new \DateTimeImmutable('today', $tz);
        $from = (string) input('from', '');
        $to = (string) input('to', '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) && $from <= $to) {
            $span = (new \DateTimeImmutable($from))->diff(new \DateTimeImmutable($to))->days;
            if ($span <= 366) {
                return [$from, $to, null];
            }
        }
        $days = in_array((int) input('days', 30), [7, 30, 90, 365], true) ? (int) input('days', 30) : 30;
        return [$today->modify('-' . ($days - 1) . ' days')->format('Y-m-d'), $today->format('Y-m-d'), $days];
    }

    public static function selectedAccounts(): array
    {
        $accounts = PostsController::accounts();
        $all = array_map('intval', array_column($accounts, 'id'));
        $pick = (int) input('account', 0);
        return [$accounts, $pick && in_array($pick, $all, true) ? [$pick] : $all, $pick];
    }

    public function index(): void
    {
        [$from, $to, $days] = self::range();
        [$accounts, $ids, $accountId] = self::selectedAccounts();
        $tab = in_array(input('tab'), ['overview', 'networks', 'content', 'timing', 'audience'], true) ? input('tab') : 'overview';
        $data = Analytics::build(Workspace::id(), $ids, $from, $to, user_tz());
        view('analytics/index', [
            'title' => 'Analytics',
            'tab' => $tab,
            'days' => $days,
            'accounts' => $accounts,
            'accountId' => $accountId,
            'a' => $data,
        ]);
    }
}
