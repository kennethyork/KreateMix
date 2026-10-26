<?php
declare(strict_types=1);

namespace App;

/** One scheduler tick: publish due posts, deliver webhooks, import analytics. */
final class Cron
{
    public static function tick(): array
    {
        $lock = fopen(BASE_PATH . '/storage/cron.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return ['skipped' => 'Another run is in progress.'];
        }
        touch(BASE_PATH . '/storage/cron.stamp');
        set_time_limit(0);

        // Each job is isolated so one failing integration can't stop publishing or the others.
        $report = [];
        $jobs = [
            'autolists' => fn() => Autolists::runDue(),
            'posts' => fn() => Publisher::runDue(),
            'webhooks' => fn() => Webhooks::deliverPending(),
            'metrics' => fn() => Metrics::import(),
            'inbox' => fn() => Inbox::fetchDue(),
            'competitors' => fn() => Competitors::refreshDue(),
            'ads' => fn() => Ads::importDue(),
            'reports' => fn() => Reports::sendDue(),
            'hashtags' => fn() => Hashtags::checkDue(),
        ];
        foreach ($jobs as $name => $job) {
            try {
                $report[$name] = $job();
            } catch (\Throwable $e) {
                $report[$name] = ['error' => $e->getMessage()];
                log_event('error', "Cron job $name failed: " . $e->getMessage());
            }
        }
        Hooks::fire('cron', $report);

        // Housekeeping once a day.
        $daily = BASE_PATH . '/storage/daily.stamp';
        if (!is_file($daily) || filemtime($daily) < time() - 86400) {
            touch($daily);
            Webhooks::prune();
            WebAnalytics::prune();
            Db::query('DELETE FROM logs WHERE created_at < ?', [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
            Db::query('DELETE FROM invitations WHERE accepted_at IS NULL AND created_at < ?', [gmdate('Y-m-d H:i:s', time() - 7 * 86400)]);
        }

        flock($lock, LOCK_UN);
        fclose($lock);
        return $report;
    }
}
