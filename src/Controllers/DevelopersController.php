<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Webhooks;
use App\Workspace;

/** API tokens (per user, per workspace) and webhooks (per workspace, admins). */
final class DevelopersController
{
    public function index(): void
    {
        $wsId = Workspace::id();
        $hooks = Db::all('SELECT * FROM webhooks WHERE workspace_id = ? ORDER BY id', [$wsId]);
        foreach ($hooks as &$h) {
            $h['deliveries'] = Db::all('SELECT id, event, status_code, attempts, delivered_at, next_attempt_at, created_at FROM webhook_deliveries WHERE webhook_id = ? ORDER BY id DESC LIMIT 10', [$h['id']]);
        }
        unset($h);
        $newToken = $_SESSION['_new_token'] ?? null;
        unset($_SESSION['_new_token']);
        $newSecret = $_SESSION['_new_secret'] ?? null;
        unset($_SESSION['_new_secret']);
        view('developers/index', [
            'title' => 'API & webhooks',
            'tokens' => Db::all('SELECT * FROM api_tokens WHERE user_id = ? AND workspace_id = ? ORDER BY id DESC', [Auth::id(), $wsId]),
            'webhooks' => $hooks,
            'events' => Webhooks::EVENTS,
            'newToken' => $newToken,
            'newSecret' => $newSecret,
            'apiBase' => abs_url('api/v1'),
            'isAdmin' => Workspace::can('admin'),
        ]);
    }

    public function createToken(): void
    {
        $name = mb_substr((string) input('name', ''), 0, 60) ?: 'API token';
        $token = 'mc_' . bin2hex(random_bytes(24));
        Db::insert('api_tokens', [
            'user_id' => Auth::id(),
            'workspace_id' => Workspace::id(),
            'name' => $name,
            'token' => hash('sha256', $token),
            'created_at' => now(),
        ]);
        $_SESSION['_new_token'] = $token;
        redirect('/developers');
    }

    public function deleteToken(string $id): void
    {
        Db::query('DELETE FROM api_tokens WHERE id = ? AND user_id = ?', [(int) $id, Auth::id()]);
        flash('success', 'Token revoked.');
        redirect('/developers');
    }

    public function saveWebhook(): void
    {
        $id = (int) input('id', 0);
        $url = (string) input('url', '');
        $events = array_values(array_intersect((array) input('events', []), array_keys(Webhooks::EVENTS)));
        if (!Webhooks::safeUrl($url)) {
            flash('error', 'Enter a public http(s) URL.');
            redirect('/developers');
        }
        if ($id) {
            Db::update('webhooks', ['url' => $url, 'events' => json_encode($events), 'active' => input('active') ? 1 : 0], 'id = ? AND workspace_id = ?', [$id, Workspace::id()]);
            flash('success', 'Webhook saved.');
        } else {
            $secret = 'whsec_' . bin2hex(random_bytes(20));
            Db::insert('webhooks', ['workspace_id' => Workspace::id(), 'url' => $url, 'events' => json_encode($events), 'secret' => $secret, 'active' => 1, 'created_at' => now()]);
            $_SESSION['_new_secret'] = $secret;
        }
        redirect('/developers');
    }

    public function deleteWebhook(string $id): void
    {
        Db::query('DELETE FROM webhooks WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Webhook deleted.');
        redirect('/developers');
    }

    public function testWebhook(string $id): void
    {
        $hook = Db::one('SELECT * FROM webhooks WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$hook) {
            abort(404);
        }
        $payload = json_encode(['event' => 'ping', 'workspace_id' => Workspace::id(), 'sent_at' => gmdate('c'), 'data' => ['message' => 'Hello from MixCreate']]);
        $did = Db::insert('webhook_deliveries', ['webhook_id' => $hook['id'], 'event' => 'ping', 'payload' => $payload, 'created_at' => now()]);
        $ok = Webhooks::send(['id' => $did, 'attempts' => 5, 'event' => 'ping', 'payload' => $payload, 'url' => $hook['url'], 'secret' => $hook['secret']]);
        $d = Db::one('SELECT status_code, response FROM webhook_deliveries WHERE id = ?', [$did]);
        flash($ok ? 'success' : 'error', $ok ? "Ping delivered (HTTP {$d['status_code']})." : 'Ping failed: ' . ($d['status_code'] ? "HTTP {$d['status_code']}" : str_limit((string) $d['response'], 160)));
        redirect('/developers');
    }
}
