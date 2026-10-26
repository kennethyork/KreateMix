<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Analytics;
use App\Db;
use App\Inbox;
use App\PostService;

/**
 * MCP server over Streamable HTTP at /mcp, so AI assistants can read analytics and draft or schedule posts.
 * Authenticate with an API token: "Authorization: Bearer mc_...".
 * Speaks both the stateless 2026-07-28 protocol (server/discover, per-request _meta) and the
 * 2025-11-25 handshake (initialize), answering with plain JSON responses.
 */
final class McpController
{
    private const VERSIONS = ['2026-07-28', '2025-11-25', '2025-06-18', '2025-03-26'];
    private array $user;
    private int $workspaceId;
    private string $role;
    private string $version = self::VERSIONS[0];

    public function handle(): void
    {
        header('Cache-Control: no-store');
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
            header('Allow: POST');
            http_response_code(405);
            exit;
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        $row = preg_match('/^Bearer\s+(\S+)$/i', $auth, $m) ? Db::one(
            'SELECT t.id AS token_id, t.workspace_id, u.*, wu.role FROM api_tokens t JOIN users u ON u.id = t.user_id
             JOIN workspace_user wu ON wu.user_id = t.user_id AND wu.workspace_id = t.workspace_id WHERE t.token = ?',
            [hash('sha256', $m[1])]
        ) : null;
        if (!$row) {
            header('WWW-Authenticate: Bearer realm="MixCreate"');
            json_response(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32001, 'message' => 'Unauthorized: send an API token from MixCreate → API & webhooks.']], 401);
        }
        Db::update('api_tokens', ['last_used_at' => now()], 'id = ?', [$row['token_id']]);
        $this->user = $row;
        $this->workspaceId = (int) $row['workspace_id'];
        $this->role = $row['is_admin'] ? 'admin' : $row['role'];

        $body = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($body)) {
            json_response(['jsonrpc' => '2.0', 'id' => null, 'error' => ['code' => -32700, 'message' => 'Parse error']], 400);
        }
        $batch = array_is_list($body) ? $body : [$body];
        $out = [];
        foreach ($batch as $msg) {
            $res = $this->dispatch(is_array($msg) ? $msg : []);
            if ($res !== null) {
                $out[] = $res;
            }
        }
        if (!$out) {
            http_response_code(202); // notifications only
            exit;
        }
        json_response(array_is_list($body) ? $out : $out[0]);
    }

    private function dispatch(array $msg): ?array
    {
        $id = $msg['id'] ?? null;
        $method = (string) ($msg['method'] ?? '');
        $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
        if ($id === null) {
            return null; // notification (e.g. notifications/initialized)
        }
        $requested = $params['_meta']['io.modelcontextprotocol/protocolVersion'] ?? null;
        if ($requested !== null) {
            if (!in_array($requested, self::VERSIONS, true)) {
                return $this->error($id, -32022, 'Unsupported protocol version', ['supported' => self::VERSIONS, 'requested' => $requested]);
            }
            $this->version = $requested;
        }
        try {
            $result = match ($method) {
                'initialize' => $this->initialize($params),
                'server/discover' => $this->discover(),
                'ping' => [],
                'tools/list' => ['tools' => $this->tools(), 'ttlMs' => 3600000, 'cacheScope' => 'private'],
                'tools/call' => $this->call((string) ($params['name'] ?? ''), (array) ($params['arguments'] ?? [])),
                'resources/list' => ['resources' => [], 'ttlMs' => 3600000, 'cacheScope' => 'private'],
                'prompts/list' => ['prompts' => [], 'ttlMs' => 3600000, 'cacheScope' => 'private'],
                default => throw new \BadMethodCallException("Method not found: $method"),
            };
        } catch (\BadMethodCallException $e) {
            return $this->error($id, -32601, $e->getMessage());
        } catch (\InvalidArgumentException $e) {
            return $this->error($id, -32602, $e->getMessage());
        }
        $result['resultType'] = 'complete';
        $result['_meta']['io.modelcontextprotocol/serverInfo'] = $this->serverInfo();
        return ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result];
    }

    private function error(mixed $id, int $code, string $message, ?array $data = null): array
    {
        return ['jsonrpc' => '2.0', 'id' => $id, 'error' => array_filter(['code' => $code, 'message' => $message, 'data' => $data], fn($v) => $v !== null)];
    }

    private function serverInfo(): array
    {
        return ['name' => 'mixcreate', 'title' => \App\Config::get('app_name', 'MixCreate'), 'version' => '1.0.0'];
    }

    private function initialize(array $params): array
    {
        $v = in_array($params['protocolVersion'] ?? '', self::VERSIONS, true) ? $params['protocolVersion'] : '2025-11-25';
        return ['protocolVersion' => $v, 'capabilities' => ['tools' => ['listChanged' => false]], 'serverInfo' => $this->serverInfo(),
            'instructions' => 'Tools for the MixCreate workspace this token belongs to. Times are ISO 8601; drafts are safest unless the user asked to schedule or publish.'];
    }

    private function discover(): array
    {
        return ['supportedVersions' => self::VERSIONS, 'capabilities' => ['tools' => new \stdClass()], 'serverInfo' => $this->serverInfo()];
    }

    private function tools(): array
    {
        $obj = fn(array $props, array $required = []) => ['type' => 'object', 'properties' => $props ?: new \stdClass(), 'required' => $required];
        return [
            ['name' => 'create_post', 'title' => 'Create post', 'description' => 'Create a draft, schedule a post, or publish now to connected accounts. Use list_accounts for account ids.',
                'inputSchema' => $obj([
                    'content' => ['type' => 'string', 'description' => 'Post text'],
                    'account_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'action' => ['type' => 'string', 'enum' => ['draft', 'schedule', 'now'], 'default' => 'draft'],
                    'scheduled_at' => ['type' => 'string', 'description' => 'ISO 8601 date-time, required for schedule'],
                    'first_comment' => ['type' => 'string'],
                    'media_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                ], ['content'])],
            ['name' => 'get_analytics', 'title' => 'Get analytics', 'description' => 'Followers, engagement, top posts, per-network results and best times to post for a date range.',
                'inputSchema' => $obj(['from' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'to' => ['type' => 'string', 'description' => 'YYYY-MM-DD'], 'account_id' => ['type' => 'integer']]),
                'annotations' => ['readOnlyHint' => true]],
            ['name' => 'get_post', 'title' => 'Get post', 'description' => 'One post with per-account status, links and errors.',
                'inputSchema' => $obj(['id' => ['type' => 'integer']], ['id']), 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'list_accounts', 'title' => 'List accounts', 'description' => 'Connected social accounts in this workspace.',
                'inputSchema' => $obj([]), 'annotations' => ['readOnlyHint' => true]],
            ['name' => 'list_inbox', 'title' => 'List inbox', 'description' => 'Recent comments on published posts.',
                'inputSchema' => $obj(['status' => ['type' => 'string', 'enum' => ['open', 'replied', 'all'], 'default' => 'open'], 'limit' => ['type' => 'integer', 'default' => 20]]),
                'annotations' => ['readOnlyHint' => true]],
            ['name' => 'list_posts', 'title' => 'List posts', 'description' => 'Posts by status, newest first.',
                'inputSchema' => $obj(['status' => ['type' => 'string', 'enum' => ['draft', 'pending_approval', 'scheduled', 'published', 'failed']], 'limit' => ['type' => 'integer', 'default' => 20]]),
                'annotations' => ['readOnlyHint' => true]],
            ['name' => 'reply_to_comment', 'title' => 'Reply to comment', 'description' => 'Reply to an inbox comment as the account it was left on.',
                'inputSchema' => $obj(['id' => ['type' => 'integer', 'description' => 'Inbox item id'], 'text' => ['type' => 'string']], ['id', 'text'])],
        ];
    }

    private function requireMember(): void
    {
        if (!in_array($this->role, ['member', 'admin'], true)) {
            throw new \InvalidArgumentException('Your role in this workspace is read-only.');
        }
    }

    private function call(string $name, array $args): array
    {
        try {
            $data = match ($name) {
                'list_accounts' => array_map([AccountsController::class, 'toArray'], Db::all('SELECT * FROM accounts WHERE workspace_id = ? ORDER BY provider, name', [$this->workspaceId])),
                'list_posts' => $this->listPosts($args),
                'get_post' => $this->getPost($args),
                'create_post' => $this->createPost($args),
                'get_analytics' => $this->analytics($args),
                'list_inbox' => $this->inbox($args),
                'reply_to_comment' => $this->reply($args),
                default => throw new \InvalidArgumentException("Unknown tool: $name"),
            };
        } catch (\InvalidArgumentException $e) {
            if (str_starts_with($e->getMessage(), 'Unknown tool')) {
                throw $e;
            }
            return ['content' => [['type' => 'text', 'text' => $e->getMessage()]], 'isError' => true];
        } catch (\Throwable $e) {
            return ['content' => [['type' => 'text', 'text' => 'Error: ' . $e->getMessage()]], 'isError' => true];
        }
        $structured = array_is_list($data) ? ['items' => $data] : $data;
        return ['content' => [['type' => 'text', 'text' => json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)]], 'structuredContent' => $structured];
    }

    private function listPosts(array $a): array
    {
        $limit = max(1, min(50, (int) ($a['limit'] ?? 20)));
        $where = 'workspace_id = ?';
        $p = [$this->workspaceId];
        if (!empty($a['status'])) {
            $where .= ' AND status = ?';
            $p[] = (string) $a['status'];
        }
        return array_map([PostService::class, 'toArray'], Db::all("SELECT * FROM posts WHERE $where ORDER BY id DESC LIMIT $limit", $p));
    }

    private function getPost(array $a): array
    {
        $post = PostService::find((int) ($a['id'] ?? 0), $this->workspaceId);
        if (!$post) {
            throw new \InvalidArgumentException('Post not found.');
        }
        return PostService::toArray($post);
    }

    private function createPost(array $a): array
    {
        $this->requireMember();
        $action = in_array($a['action'] ?? 'draft', ['draft', 'schedule', 'now'], true) ? ($a['action'] ?? 'draft') : 'draft';
        $scheduled = null;
        if (!empty($a['scheduled_at'])) {
            $ts = strtotime((string) $a['scheduled_at']);
            if ($ts === false) {
                throw new \InvalidArgumentException('scheduled_at must be ISO 8601.');
            }
            $scheduled = gmdate('Y-m-d H:i:s', $ts);
        }
        $result = PostService::save(null, [
            'content' => (string) ($a['content'] ?? ''),
            'accounts' => (array) ($a['account_ids'] ?? []),
            'media' => (array) ($a['media_ids'] ?? []),
            'first_comment' => (string) ($a['first_comment'] ?? ''),
            'scheduled_at' => $scheduled,
        ], $action, $this->workspaceId, (int) $this->user['id'], $this->role === 'admin');
        if ($result['errors']) {
            throw new \InvalidArgumentException('Not saved: ' . implode(' ', $result['errors']));
        }
        if ($action === 'now' && $result['post']['status'] === 'scheduled') {
            PostService::publishNowInBackground((int) $result['post']['id']);
        }
        return PostService::toArray($result['post']);
    }

    private function analytics(array $a): array
    {
        $tz = $this->user['timezone'] ?: 'UTC';
        $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($a['to'] ?? '')) ? $a['to'] : (new \DateTimeImmutable('today', new \DateTimeZone($tz)))->format('Y-m-d');
        $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($a['from'] ?? '')) ? $a['from'] : date('Y-m-d', strtotime("$to -29 days"));
        $ids = array_map('intval', array_column(Db::all('SELECT id FROM accounts WHERE workspace_id = ?', [$this->workspaceId]), 'id'));
        if (!empty($a['account_id'])) {
            $ids = array_values(array_intersect($ids, [(int) $a['account_id']]));
        }
        $d = Analytics::build($this->workspaceId, $ids, $from, $to, $tz);
        return [
            'from' => $from, 'to' => $to, 'timezone' => $tz,
            'followers' => $d['followersNow'], 'followers_change' => $d['followersChange'],
            'totals' => $d['totals'], 'engagement_rate_percent' => $d['rate'],
            'by_network' => array_map(fn($n) => ['network' => $n['provider'], 'followers' => $n['followers'], 'posts' => $n['posts'], 'engagement' => $n['engagement'], 'engagement_rate_percent' => $n['rate']], $d['perNetwork']),
            'top_posts' => array_map(fn($p) => ['post_id' => (int) $p['id'], 'network' => $p['provider'], 'text' => str_limit($p['content'], 200), 'engagement' => $p['engagement'], 'url' => $p['url']], array_slice($d['top'], 0, 5)),
            'formats' => $d['types'],
            'best_times' => array_map(fn($c) => ['day' => Analytics::dayName($c['day']), 'hour' => Analytics::hourLabel($c['hour']), 'avg_engagement' => $c['avg']], $d['best']['top']),
        ];
    }

    private function inbox(array $a): array
    {
        $limit = max(1, min(100, (int) ($a['limit'] ?? 20)));
        $status = $a['status'] ?? 'open';
        $where = match ($status) { 'replied' => "AND i.status = 'replied'", 'all' => '', default => "AND i.status IN ('new', 'read')" };
        return array_map(fn($i) => ['id' => (int) $i['id'], 'network' => $i['provider'], 'account' => $i['account_name'], 'author' => $i['author_name'] ?: $i['author_handle'],
            'text' => $i['text'], 'on_post' => str_limit((string) $i['post_content'], 120), 'status' => $i['status'], 'at' => gmdate('c', strtotime($i['remote_created_at'] . ' UTC'))],
            Db::all("SELECT i.*, a.provider, a.name AS account_name, p.content AS post_content FROM inbox_items i JOIN accounts a ON a.id = i.account_id LEFT JOIN posts p ON p.id = i.post_id
                     WHERE i.workspace_id = ? $where ORDER BY i.remote_created_at DESC LIMIT $limit", [$this->workspaceId]));
    }

    private function reply(array $a): array
    {
        $this->requireMember();
        $item = Db::one('SELECT * FROM inbox_items WHERE id = ? AND workspace_id = ?', [(int) ($a['id'] ?? 0), $this->workspaceId]);
        if (!$item) {
            throw new \InvalidArgumentException('Inbox item not found.');
        }
        $text = trim((string) ($a['text'] ?? ''));
        if ($text === '') {
            throw new \InvalidArgumentException('Reply text is empty.');
        }
        Inbox::reply($item, $text, (int) $this->user['id']);
        return ['ok' => true, 'id' => (int) $item['id'], 'status' => 'replied'];
    }
}
