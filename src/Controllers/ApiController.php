<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\PostService;

/**
 * REST API, v1. Authenticate with "Authorization: Bearer mc_...".
 * A token acts as its user inside the token's workspace with that user's current role.
 */
final class ApiController
{
    private array $user;
    private int $workspaceId;
    private string $role;

    public function __construct()
    {
        header('Content-Type: application/json; charset=utf-8');
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
            json_response(['error' => 'Missing bearer token.'], 401);
        }
        $row = Db::one(
            'SELECT t.id AS token_id, t.workspace_id, u.*, wu.role FROM api_tokens t
             JOIN users u ON u.id = t.user_id
             JOIN workspace_user wu ON wu.user_id = t.user_id AND wu.workspace_id = t.workspace_id
             WHERE t.token = ?',
            [hash('sha256', $m[1])]
        );
        if (!$row) {
            json_response(['error' => 'Invalid token.'], 401);
        }
        Db::update('api_tokens', ['last_used_at' => now()], 'id = ?', [$row['token_id']]);
        $this->user = $row;
        $this->workspaceId = (int) $row['workspace_id'];
        $this->role = $row['is_admin'] ? 'admin' : $row['role'];
    }

    private function requireRole(string $min): void
    {
        $rank = ['viewer' => 1, 'member' => 2, 'admin' => 3];
        if (($rank[$this->role] ?? 0) < $rank[$min]) {
            json_response(['error' => 'Your role in this workspace doesn’t allow this.'], 403);
        }
    }

    public function me(): void
    {
        $ws = Db::one('SELECT id, name, require_approval FROM workspaces WHERE id = ?', [$this->workspaceId]);
        json_response([
            'user' => ['id' => (int) $this->user['id'], 'name' => $this->user['name'], 'email' => $this->user['email'], 'role' => $this->role],
            'workspace' => ['id' => (int) $ws['id'], 'name' => $ws['name'], 'require_approval' => (bool) $ws['require_approval']],
        ]);
    }

    public function accounts(): void
    {
        $rows = Db::all('SELECT * FROM accounts WHERE workspace_id = ? ORDER BY provider, name', [$this->workspaceId]);
        json_response(['data' => array_map([AccountsController::class, 'toArray'], $rows)]);
    }

    public function tags(): void
    {
        $rows = Db::all('SELECT id, name, color FROM tags WHERE workspace_id = ? ORDER BY name', [$this->workspaceId]);
        json_response(['data' => array_map(fn($t) => ['id' => (int) $t['id'], 'name' => $t['name'], 'color' => $t['color']], $rows)]);
    }

    public function posts(): void
    {
        $where = 'workspace_id = ?';
        $params = [$this->workspaceId];
        if ($s = (string) input('status', '')) {
            $where .= ' AND status = ?';
            $params[] = $s;
        }
        $limit = min(100, max(1, (int) input('limit', 25)));
        $page = max(1, (int) input('page', 1));
        $total = (int) Db::value("SELECT COUNT(*) FROM posts WHERE $where", $params);
        $rows = Db::all("SELECT * FROM posts WHERE $where ORDER BY id DESC LIMIT $limit OFFSET " . (($page - 1) * $limit), $params);
        json_response([
            'data' => array_map([PostService::class, 'toArray'], $rows),
            'meta' => ['page' => $page, 'limit' => $limit, 'total' => $total],
        ]);
    }

    public function show(string $id): void
    {
        $post = PostService::find((int) $id, $this->workspaceId);
        if (!$post) {
            json_response(['error' => 'Post not found.'], 404);
        }
        json_response(['data' => PostService::toArray($post)]);
    }

    /**
     * POST /api/v1/posts  and  PUT /api/v1/posts/{id}
     * Body: {content, accounts: [ids], media: [ids], tags: [ids], overrides: {account_id: text},
     *        scheduled_at: ISO 8601, action: "draft"|"schedule"|"now"}
     */
    public function store(): void
    {
        $this->save(null);
    }

    public function update(string $id): void
    {
        $this->save((int) $id);
    }

    private function save(?int $id): void
    {
        $this->requireRole('member');
        $action = (string) input('action', 'draft');
        if (!in_array($action, ['draft', 'schedule', 'now'], true)) {
            json_response(['error' => 'action must be draft, schedule or now.'], 422);
        }
        $scheduledAt = null;
        if ($raw = (string) input('scheduled_at', '')) {
            $ts = strtotime($raw);
            if ($ts === false) {
                json_response(['error' => 'scheduled_at must be an ISO 8601 date-time, e.g. 2026-11-03T09:00:00-05:00.'], 422);
            }
            $scheduledAt = gmdate('Y-m-d H:i:s', $ts);
        }
        $in = [
            'content' => (string) input('content', ''),
            'accounts' => (array) input('accounts', []),
            'media' => (array) input('media', []),
            'tags' => (array) input('tags', []),
            'overrides' => (array) input('overrides', []),
            'scheduled_at' => $scheduledAt,
        ];
        $result = PostService::save($id, $in, $action, $this->workspaceId, (int) $this->user['id'], $this->role === 'admin');
        if ($result['errors']) {
            json_response(['error' => 'Validation failed.', 'errors' => $result['errors']], 422);
        }
        $post = $result['post'];
        if ($post['status'] === 'scheduled' && $action === 'now') {
            PostService::publishNowInBackground((int) $post['id']);
        }
        json_response(['data' => PostService::toArray($post)], $id ? 200 : 201);
    }

    public function destroy(string $id): void
    {
        $this->requireRole('member');
        $post = PostService::find((int) $id, $this->workspaceId);
        if (!$post) {
            json_response(['error' => 'Post not found.'], 404);
        }
        PostService::delete($post);
        json_response(['ok' => true]);
    }

    /** POST /api/v1/posts/{id}/approve and /reject (body: {note}) */
    public function review(string $id, string $decision): void
    {
        $this->requireRole('admin');
        $post = PostService::find((int) $id, $this->workspaceId);
        if (!$post || $post['status'] !== 'pending_approval') {
            json_response(['error' => 'Post is not waiting for approval.'], 422);
        }
        $decision === 'approve'
            ? PostService::approve($post, (int) $this->user['id'])
            : PostService::reject($post, (int) $this->user['id'], (string) input('note', ''));
        json_response(['data' => PostService::toArray(PostService::find((int) $id, $this->workspaceId))]);
    }

    /** POST /api/v1/media (multipart, field "file") */
    public function upload(): void
    {
        $this->requireRole('member');
        $result = MediaController::store($_FILES['file'] ?? null, $this->workspaceId, (int) $this->user['id']);
        if (isset($result['error'])) {
            json_response(['error' => $result['error']], 422);
        }
        $result['url'] = abs_url(ltrim(substr($result['url'], strlen(base_path())), '/'));
        json_response(['data' => $result], 201);
    }

    public function media(): void
    {
        $rows = Db::all('SELECT * FROM media WHERE workspace_id = ? ORDER BY id DESC LIMIT 100', [$this->workspaceId]);
        json_response(['data' => array_map(fn($m) => ['id' => (int) $m['id'], 'url' => abs_url($m['path']), 'mime' => $m['mime'], 'name' => $m['name']], $rows)]);
    }
}
