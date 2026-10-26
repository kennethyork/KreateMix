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
        // ?api_token= is accepted for tools that can't send headers (Google Sheets IMPORTDATA, Looker Studio).
        if (!preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) && !(is_string($_GET['api_token'] ?? null) && preg_match('/^(mc_[0-9a-f]+)$/', $_GET['api_token'], $m))) {
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

    /**
     * GET /api/v1/analytics?from=YYYY-MM-DD&to=YYYY-MM-DD&format=json|csv
     * One row per account per day: followers, posts, likes, comments, shares, impressions, engagement.
     */
    public function analytics(): void
    {
        $tz = $this->user['timezone'] ?: 'UTC';
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) input('to', '')) ? input('to') : (new \DateTimeImmutable('today', new \DateTimeZone($tz)))->format('Y-m-d');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) input('from', '')) ? input('from') : date('Y-m-d', strtotime($to . ' -29 days'));
        if ($from > $to || (strtotime($to) - strtotime($from)) > 400 * 86400) {
            json_response(['error' => 'Use a range of up to 400 days.'], 422);
        }
        $accounts = Db::all('SELECT id, provider, name FROM accounts WHERE workspace_id = ?', [$this->workspaceId]);
        $rows = [];
        foreach ($accounts as $acc) {
            $followers = array_column(Db::all('SELECT date, followers FROM account_metrics WHERE account_id = ? AND date <= ? AND followers IS NOT NULL ORDER BY date', [$acc['id'], $to]), 'followers', 'date');
            $posts = Db::all(
                "SELECT pa.published_at, pm.data FROM post_accounts pa LEFT JOIN post_metrics pm ON pm.post_id = pa.post_id AND pm.account_id = pa.account_id
                 WHERE pa.account_id = ? AND pa.status = 'published' AND pa.published_at BETWEEN ? AND ?",
                [$acc['id'], to_utc("$from 00:00", $tz), to_utc("$to 23:59:59", $tz)]
            );
            $byDay = [];
            foreach ($posts as $p) {
                $d = local_time($p['published_at'], 'Y-m-d', $tz);
                $m = json_arr($p['data']);
                $byDay[$d] ??= ['posts' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'impressions' => 0, 'engagement' => 0];
                $byDay[$d]['posts']++;
                $byDay[$d]['likes'] += (int) ($m['likes'] ?? $m['reactions'] ?? 0);
                $byDay[$d]['comments'] += (int) ($m['replies'] ?? $m['comments'] ?? 0);
                $byDay[$d]['shares'] += (int) ($m['shares'] ?? $m['reposts'] ?? 0);
                $byDay[$d]['impressions'] += (int) ($m['impressions'] ?? $m['views'] ?? 0);
                $byDay[$d]['engagement'] += \App\Metrics::engagement($m);
            }
            $last = null;
            foreach ($followers as $d => $f) {
                if ($d < $from) {
                    $last = (int) $f;
                }
            }
            for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
                $d = date('Y-m-d', $t);
                if (isset($followers[$d])) {
                    $last = (int) $followers[$d];
                }
                $rows[] = ['date' => $d, 'account_id' => (int) $acc['id'], 'account' => $acc['name'], 'network' => $acc['provider'], 'followers' => $last]
                    + ($byDay[$d] ?? ['posts' => 0, 'likes' => 0, 'comments' => 0, 'shares' => 0, 'impressions' => 0, 'engagement' => 0]);
            }
        }
        if (input('format') === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'account_id', 'account', 'network', 'followers', 'posts', 'likes', 'comments', 'shares', 'impressions', 'engagement']);
            foreach ($rows as $r) {
                fputcsv($out, array_values($r));
            }
            fclose($out);
            exit;
        }
        json_response(['data' => $rows, 'meta' => ['from' => $from, 'to' => $to, 'timezone' => $tz]]);
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
            'types' => (array) input('types', []),
            'first_comment' => (string) input('first_comment', ''),
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
