<?php
declare(strict_types=1);

namespace App;

/** Report parameters, data and scheduled delivery. */
final class Reports
{
    /**
     * Resolves stored params into data. Params: account (int|0), days (int|null) or from/to (Y-m-d).
     * Rolling "days" ranges end today in $tz.
     */
    public static function data(int $workspaceId, array $params, string $tz): array
    {
        $accounts = Db::all('SELECT id, name FROM accounts WHERE workspace_id = ?', [$workspaceId]);
        $all = array_map('intval', array_column($accounts, 'id'));
        $account = (int) ($params['account'] ?? 0);
        $ids = $account && in_array($account, $all, true) ? [$account] : $all;
        if (!empty($params['days'])) {
            $today = new \DateTimeImmutable('today', new \DateTimeZone($tz));
            $from = $today->modify('-' . ((int) $params['days'] - 1) . ' days')->format('Y-m-d');
            $to = $today->format('Y-m-d');
        } else {
            $from = $params['from'];
            $to = $params['to'];
        }
        $scope = 'All accounts';
        if ($account && count($ids) === 1) {
            foreach ($accounts as $a) {
                if ((int) $a['id'] === $account) {
                    $scope = $a['name'];
                }
            }
        }
        return ['a' => Analytics::build($workspaceId, $ids, $from, $to, $tz), 'scope' => $scope];
    }

    /** Sends scheduled reports that are due. Weekly: Mondays, covering the previous 7 days. Monthly: the 1st, covering last month. */
    public static function sendDue(): int
    {
        $sent = 0;
        foreach (Db::all('SELECT s.*, w.name AS workspace_name, u.timezone FROM report_schedules s JOIN workspaces w ON w.id = s.workspace_id LEFT JOIN users u ON u.id = s.created_by') as $s) {
            $tz = $s['timezone'] ?: 'UTC';
            $now = new \DateTimeImmutable('now', new \DateTimeZone($tz));
            if ((int) $now->format('G') < 8) {
                continue; // send in the morning, local time
            }
            $last = $s['last_sent_at'] ? new \DateTimeImmutable($s['last_sent_at'] . ' UTC') : null;
            if ($s['frequency'] === 'monthly') {
                $due = $now->format('j') === '1' && (!$last || $last->setTimezone($now->getTimezone())->format('Y-m-d') !== $now->format('Y-m-d'));
                $from = $now->modify('first day of last month')->format('Y-m-d');
                $to = $now->modify('last day of last month')->format('Y-m-d');
            } else {
                $due = $now->format('N') === '1' && (!$last || $last < $now->modify('-6 days'));
                $from = $now->modify('-7 days')->format('Y-m-d');
                $to = $now->modify('-1 day')->format('Y-m-d');
            }
            if (!$due) {
                continue;
            }
            try {
                self::send($s, $from, $to, $tz);
                $sent++;
            } catch (\Throwable $e) {
                log_event('error', "Scheduled report {$s['id']} failed: " . $e->getMessage());
            }
            // Mark as sent either way so a broken mail setup doesn't retry every minute.
            Db::update('report_schedules', ['last_sent_at' => now()], 'id = ?', [$s['id']]);
        }
        return $sent;
    }

    public static function send(array $schedule, string $from, string $to, string $tz): void
    {
        $accounts = json_arr($schedule['accounts']);
        $data = self::data((int) $schedule['workspace_id'], ['account' => $accounts[0] ?? 0, 'from' => $from, 'to' => $to], $tz);
        $workspace = $schedule['workspace_name'] ?? (string) Db::value('SELECT name FROM workspaces WHERE id = ?', [$schedule['workspace_id']]);
        $pdf = Report::pdf($data['a'], $workspace, $data['scope'], $tz);
        $a = $data['a'];
        $html = render('reports/email', ['a' => $a, 'workspace' => $workspace, 'scope' => $data['scope'], 'name' => $schedule['name']], null);
        Mailer::send(
            explode(',', $schedule['recipients']),
            $schedule['name'] . ' · ' . date('M j', strtotime($from)) . '–' . date('M j', strtotime($to)),
            $html,
            [['name' => self::filename($workspace, $from, $to), 'mime' => 'application/pdf', 'data' => $pdf]]
        );
    }

    public static function filename(string $workspace, string $from, string $to): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($workspace)), '-') ?: 'report';
        return "$slug-report-$from-to-$to.pdf";
    }
}
