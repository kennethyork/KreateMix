<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Inbox;
use App\Providers\Registry;
use App\Workspace;

final class InboxController
{
    public function index(): void
    {
        $wsId = Workspace::id();
        $status = in_array(input('status'), ['open', 'replied', 'archived', 'all'], true) ? input('status') : 'open';
        $accountId = (int) input('account', 0);
        $where = ['i.workspace_id = ?'];
        $params = [$wsId];
        $where[] = match ($status) {
            'open' => "i.status IN ('new', 'read')",
            'replied' => "i.status = 'replied'",
            'archived' => "i.status = 'archived'",
            default => '1 = 1',
        };
        if ($accountId) {
            $where[] = 'i.account_id = ?';
            $params[] = $accountId;
        }
        $items = Db::all(
            'SELECT i.*, a.provider, a.name AS account_name, a.username AS account_username, a.avatar AS account_avatar, p.content AS post_content, u.name AS replied_by_name
             FROM inbox_items i JOIN accounts a ON a.id = i.account_id LEFT JOIN posts p ON p.id = i.post_id LEFT JOIN users u ON u.id = i.replied_by
             WHERE ' . implode(' AND ', $where) . ' ORDER BY i.remote_created_at DESC LIMIT 200',
            $params
        );
        $counts = array_column(Db::all('SELECT status, COUNT(*) AS n FROM inbox_items WHERE workspace_id = ? GROUP BY status', [$wsId]), 'n', 'status');
        $supported = [];
        foreach (Registry::all() as $k => $c) {
            if ($c::supportsComments()) {
                $supported[] = $c::name();
            }
        }
        view('inbox/index', [
            'title' => 'Inbox',
            'items' => $items,
            'status' => $status,
            'counts' => $counts,
            'accounts' => PostsController::accounts(),
            'accountId' => $accountId,
            'supported' => $supported,
        ]);
    }

    private function item(string $id): array
    {
        $item = Db::one('SELECT * FROM inbox_items WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$item) {
            abort(404);
        }
        return $item;
    }

    public function reply(string $id): void
    {
        $item = $this->item($id);
        $text = trim((string) input('text', ''));
        if ($text === '') {
            flash('error', 'Write a reply first.');
            back('/inbox');
        }
        try {
            Inbox::reply($item, $text, (int) Auth::id());
            if (wants_json()) {
                json_response(['ok' => true]);
            }
            flash('success', 'Reply sent.');
        } catch (\Throwable $e) {
            if (wants_json()) {
                json_response(['error' => $e->getMessage()], 422);
            }
            flash('error', 'Couldn’t reply: ' . $e->getMessage());
        }
        back('/inbox');
    }

    public function status(string $id): void
    {
        $item = $this->item($id);
        $status = in_array(input('status'), ['read', 'archived', 'new'], true) ? input('status') : 'read';
        Db::update('inbox_items', ['status' => $status], 'id = ?', [$item['id']]);
        if (wants_json()) {
            json_response(['ok' => true]);
        }
        back('/inbox');
    }

    public function markAllRead(): void
    {
        Db::query("UPDATE inbox_items SET status = 'read' WHERE workspace_id = ? AND status = 'new'", [Workspace::id()]);
        back('/inbox');
    }

    public function refresh(): void
    {
        @unlink(BASE_PATH . '/storage/inbox.stamp');
        $n = Inbox::fetchDue(0);
        flash('success', $n ? "$n new comment" . ($n === 1 ? '' : 's') . '.' : 'No new comments.');
        redirect('/inbox');
    }
}
