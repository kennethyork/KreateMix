<?php
declare(strict_types=1);

namespace App;

/** Evergreen queues: at each time slot, publish the next item in the list, cycling forever. */
final class Autolists
{
    public const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

    /** Creates posts for slots that came due in the last 30 minutes. Runs from cron before publishing. */
    public static function runDue(?int $now = null): int
    {
        $now ??= time();
        $created = 0;
        foreach (Db::all('SELECT * FROM autolists WHERE active = 1') as $list) {
            $tz = new \DateTimeZone($list['timezone'] ?: 'UTC');
            $local = (new \DateTimeImmutable('@' . $now))->setTimezone($tz);
            foreach (json_arr($list['slots']) as $slot) {
                // Check today's and yesterday's occurrence so a slot near midnight isn't missed.
                foreach ([0, 1] as $back) {
                    $day = $local->modify("-$back day");
                    if ((int) $day->format('w') !== (int) $slot['day']) {
                        continue;
                    }
                    [$h, $m] = array_map('intval', explode(':', $slot['time']) + [1 => 0]);
                    $at = $day->setTime($h, $m);
                    $ts = $at->getTimestamp();
                    if ($ts > $now || $ts < $now - 1800) {
                        continue;
                    }
                    $key = gmdate('Y-m-d H:i', $ts);
                    $claimed = Db::query('INSERT OR IGNORE INTO autolist_runs (autolist_id, slot) VALUES (?, ?)', [$list['id'], $key])->rowCount();
                    if ($claimed && ($postId = self::publishNext($list))) {
                        Db::update('autolist_runs', ['post_id' => $postId], 'autolist_id = ? AND slot = ?', [$list['id'], $key]);
                        $created++;
                    }
                }
            }
        }
        return $created;
    }

    /** Picks the next item and creates a post for it. Returns the post id or null. */
    public static function publishNext(array $list): ?int
    {
        $items = Db::all('SELECT * FROM autolist_items WHERE autolist_id = ? ORDER BY position, id', [$list['id']]);
        $accounts = json_arr($list['accounts']);
        if (!$items || !$accounts) {
            return null;
        }
        if ($list['order_mode'] === 'random') {
            // Random among the least-posted items, so everything gets a turn.
            $min = min(array_column($items, 'times_posted'));
            $pool = array_values(array_filter($items, fn($i) => (int) $i['times_posted'] === (int) $min));
            $item = $pool[array_rand($pool)];
        } else {
            $item = $items[(int) $list['next_index'] % count($items)];
            Db::update('autolists', ['next_index' => ((int) $list['next_index'] + 1) % count($items)], 'id = ?', [$list['id']]);
        }
        $result = PostService::save(null, [
            'content' => $item['content'],
            'media' => json_arr($item['media']),
            'accounts' => $accounts,
            'scheduled_at' => now(),
            'autolist_id' => (int) $list['id'],
        ], 'schedule', (int) $list['workspace_id'], (int) ($list['created_by'] ?? 0), true);
        if ($result['errors']) {
            log_event('warning', "Autolist {$list['id']} item {$item['id']} skipped: " . implode(' ', $result['errors']));
            return null;
        }
        Db::query('UPDATE autolist_items SET times_posted = times_posted + 1, last_posted_at = ? WHERE id = ?', [now(), $item['id']]);
        return (int) $result['post']['id'];
    }

    /** Next N slot times (UTC) for display. */
    public static function upcoming(array $list, int $n = 5): array
    {
        $tz = new \DateTimeZone($list['timezone'] ?: 'UTC');
        $now = new \DateTimeImmutable('now', $tz);
        $out = [];
        for ($d = 0; $d < 14 && count($out) < 50; $d++) {
            $day = $now->modify("+$d day");
            foreach (json_arr($list['slots']) as $slot) {
                if ((int) $day->format('w') !== (int) $slot['day']) {
                    continue;
                }
                [$h, $m] = array_map('intval', explode(':', $slot['time']) + [1 => 0]);
                $at = $day->setTime($h, $m);
                if ($at > $now) {
                    $out[] = $at;
                }
            }
        }
        usort($out, fn($a, $b) => $a <=> $b);
        return array_slice($out, 0, $n);
    }
}
