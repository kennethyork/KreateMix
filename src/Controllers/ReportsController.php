<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Report;
use App\Reports;
use App\Settings;
use App\Workspace;

final class ReportsController
{
    private function params(): array
    {
        [$from, $to, $days] = AnalyticsController::range();
        return array_filter(['account' => (int) input('account', 0), 'days' => $days, 'from' => $days ? null : $from, 'to' => $days ? null : $to]);
    }

    public function index(): void
    {
        $params = $this->params();
        $data = Reports::data(Workspace::id(), $params, user_tz());
        [$accounts, , $accountId] = AnalyticsController::selectedAccounts();
        $newShare = $_SESSION['_new_share'] ?? null;
        unset($_SESSION['_new_share']);
        view('reports/index', [
            'title' => 'Reports',
            'a' => $data['a'],
            'scope' => $data['scope'],
            'accounts' => $accounts,
            'accountId' => $accountId,
            'days' => $params['days'] ?? null,
            'params' => $params,
            'schedules' => Db::all('SELECT * FROM report_schedules WHERE workspace_id = ? ORDER BY id', [Workspace::id()]),
            'shares' => Db::all('SELECT * FROM report_shares WHERE workspace_id = ? ORDER BY id DESC', [Workspace::id()]),
            'newShare' => $newShare,
            'mailReady' => Settings::value('smtp', 'from_email') !== null,
        ]);
    }

    public function pdf(): void
    {
        $data = Reports::data(Workspace::id(), $this->params(), user_tz());
        self::sendPdf($data, Workspace::current()['name'], user_tz());
    }

    private static function sendPdf(array $data, string $workspace, string $tz): never
    {
        $pdf = Report::pdf($data['a'], $workspace, $data['scope'], $tz);
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . Reports::filename($workspace, $data['a']['from'], $data['a']['to']) . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    public function share(): void
    {
        $token = bin2hex(random_bytes(20));
        $expires = (int) input('expires', 0);
        Db::insert('report_shares', [
            'workspace_id' => Workspace::id(),
            'token' => hash('sha256', $token),
            'params' => json_encode($this->params()),
            'created_by' => Auth::id(),
            'created_at' => now(),
            'expires_at' => $expires > 0 ? gmdate('Y-m-d H:i:s', time() + $expires * 86400) : null,
        ]);
        $_SESSION['_new_share'] = abs_url('r/' . $token);
        redirect('/reports', $this->params());
    }

    public function revokeShare(string $id): void
    {
        Db::query('DELETE FROM report_shares WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Share link revoked.');
        redirect('/reports');
    }

    /** Public, read-only report: /r/{token} and /r/{token}.pdf */
    public function public(string $token): void
    {
        $pdf = str_ends_with($token, '.pdf');
        $token = preg_replace('/\.pdf$/', '', $token);
        $share = Db::one('SELECT s.*, w.name AS workspace_name, u.timezone FROM report_shares s JOIN workspaces w ON w.id = s.workspace_id LEFT JOIN users u ON u.id = s.created_by WHERE s.token = ?', [hash('sha256', $token)]);
        if (!$share || ($share['expires_at'] && strtotime($share['expires_at'] . ' UTC') < time())) {
            abort(404, 'This report link is invalid or has expired.');
        }
        $tz = $share['timezone'] ?: 'UTC';
        $data = Reports::data((int) $share['workspace_id'], json_arr($share['params']), $tz);
        if ($pdf) {
            self::sendPdf($data, $share['workspace_name'], $tz);
        }
        header('X-Robots-Tag: noindex');
        view('reports/public', [
            'title' => $share['workspace_name'] . ' report',
            'a' => $data['a'],
            'scope' => $data['scope'],
            'workspace' => $share['workspace_name'],
            'pdfUrl' => url('r/' . $token . '.pdf'),
            'tz' => $tz,
        ], null);
    }

    public function saveSchedule(): void
    {
        $recipients = array_values(array_filter(array_map('trim', preg_split('/[,;\s]+/', (string) input('recipients', ''))), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        $name = mb_substr((string) input('name', ''), 0, 80) ?: 'Social media report';
        if (!$recipients) {
            flash('error', 'Add at least one valid email address.');
            redirect('/reports');
        }
        $account = (int) input('account', 0);
        Db::insert('report_schedules', [
            'workspace_id' => Workspace::id(),
            'name' => $name,
            'recipients' => implode(',', $recipients),
            'frequency' => input('frequency') === 'monthly' ? 'monthly' : 'weekly',
            'accounts' => json_encode($account ? [$account] : []),
            'created_by' => Auth::id(),
            'created_at' => now(),
        ]);
        flash('success', 'Scheduled. It goes out ' . (input('frequency') === 'monthly' ? 'on the 1st of each month' : 'every Monday') . ' at 8 AM your time.');
        redirect('/reports');
    }

    public function deleteSchedule(string $id): void
    {
        Db::query('DELETE FROM report_schedules WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Schedule deleted.');
        redirect('/reports');
    }

    public function sendNow(string $id): void
    {
        $s = Db::one('SELECT * FROM report_schedules WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$s) {
            abort(404);
        }
        $tz = user_tz();
        $today = new \DateTimeImmutable('today', new \DateTimeZone($tz));
        [$from, $to] = $s['frequency'] === 'monthly'
            ? [$today->modify('first day of last month')->format('Y-m-d'), $today->modify('last day of last month')->format('Y-m-d')]
            : [$today->modify('-7 days')->format('Y-m-d'), $today->modify('-1 day')->format('Y-m-d')];
        try {
            Reports::send($s, $from, $to, $tz);
            flash('success', 'Sent to ' . str_replace(',', ', ', $s['recipients']) . '.');
        } catch (\Throwable $e) {
            flash('error', 'Couldn’t send: ' . $e->getMessage());
        }
        redirect('/reports');
    }
}
