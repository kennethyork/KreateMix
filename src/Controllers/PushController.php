<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Notify;
use App\WebPush;

final class PushController
{
    public function key(): void
    {
        json_response(['key' => WebPush::vapid()['public']]);
    }

    public function subscribe(): void
    {
        $endpoint = (string) input('endpoint', '');
        $keys = (array) input('keys', []);
        if (!preg_match('#^https://#', $endpoint) || empty($keys['p256dh']) || empty($keys['auth'])) {
            json_response(['error' => 'Invalid subscription.'], 422);
        }
        Db::query(
            'INSERT INTO push_subscriptions (user_id, endpoint, p256dh, auth, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(endpoint) DO UPDATE SET user_id = excluded.user_id, p256dh = excluded.p256dh, auth = excluded.auth',
            [Auth::id(), $endpoint, (string) $keys['p256dh'], (string) $keys['auth'], mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), now()]
        );
        json_response(['ok' => true]);
    }

    public function unsubscribe(): void
    {
        Db::query('DELETE FROM push_subscriptions WHERE endpoint = ? AND user_id = ?', [(string) input('endpoint', ''), Auth::id()]);
        json_response(['ok' => true]);
    }

    public function test(): void
    {
        $subs = Db::all('SELECT * FROM push_subscriptions WHERE user_id = ?', [Auth::id()]);
        $ok = 0;
        $codes = [];
        foreach ($subs as $s) {
            try {
                $code = WebPush::send($s, ['title' => 'MixCreate', 'body' => 'Notifications work on this device.', 'url' => abs_url(''), 'tag' => 'test']);
                $codes[] = $code;
                $ok += $code >= 200 && $code < 300 ? 1 : 0;
            } catch (\Throwable $e) {
                $codes[] = $e->getMessage();
            }
        }
        flash($ok ? 'success' : 'error', $subs ? "Sent to $ok of " . count($subs) . ' device(s).' . ($ok < count($subs) ? ' Responses: ' . implode(', ', $codes) : '') : 'No devices are subscribed yet. Turn on notifications first.');
        redirect('/profile#notifications');
    }

    public function preferences(): void
    {
        $prefs = [];
        foreach (array_keys(Notify::EVENTS) as $k) {
            $prefs[$k] = input($k) ? 1 : 0;
        }
        Db::update('users', ['notify' => json_encode($prefs)], 'id = ?', [Auth::id()]);
        flash('success', 'Notification settings saved.');
        redirect('/profile#notifications');
    }
}
