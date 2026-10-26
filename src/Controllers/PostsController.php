<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Hooks;
use App\PostService;
use App\Providers\Registry;
use App\Publisher;
use App\Workspace;

final class PostsController
{
    public const STATUSES = [
        'draft' => 'Draft',
        'pending_approval' => 'Needs approval',
        'scheduled' => 'Scheduled',
        'publishing' => 'Publishing',
        'published' => 'Published',
        'failed' => 'Failed',
    ];

    public function index(): void
    {
        $wsId = Workspace::id();
        $status = (string) input('status', '');
        $accountId = (int) input('account', 0);
        $tagId = (int) input('tag', 0);
        $q = (string) input('q', '');
        $page = max(1, (int) input('page', 1));
        $per = 25;

        $where = ['p.workspace_id = ?'];
        $params = [$wsId];
        if (isset(self::STATUSES[$status])) {
            $where[] = 'p.status = ?';
            $params[] = $status;
        }
        if ($accountId) {
            $where[] = 'EXISTS (SELECT 1 FROM post_accounts pa WHERE pa.post_id = p.id AND pa.account_id = ?)';
            $params[] = $accountId;
        }
        if ($tagId) {
            $where[] = 'EXISTS (SELECT 1 FROM post_tag pt WHERE pt.post_id = p.id AND pt.tag_id = ?)';
            $params[] = $tagId;
        }
        if ($q !== '') {
            $where[] = 'p.content LIKE ?';
            $params[] = '%' . $q . '%';
        }
        $whereSql = implode(' AND ', $where);
        $total = (int) Db::value("SELECT COUNT(*) FROM posts p WHERE $whereSql", $params);
        $order = $status === 'published' ? 'p.published_at DESC' : "CASE WHEN p.scheduled_at IS NULL THEN 1 ELSE 0 END, p.scheduled_at DESC, p.updated_at DESC";
        $posts = Db::all(
            "SELECT p.*, u.name AS author FROM posts p LEFT JOIN users u ON u.id = p.user_id
             WHERE $whereSql ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per),
            $params
        );

        $counts = array_column(
            Db::all('SELECT status, COUNT(*) AS n FROM posts WHERE workspace_id = ? GROUP BY status', [$wsId]),
            'n',
            'status'
        );

        view('posts/index', [
            'title' => 'Posts',
            'posts' => self::decorate($posts),
            'status' => $status,
            'counts' => $counts,
            'accounts' => self::accounts(),
            'tags' => Db::all('SELECT * FROM tags WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'filters' => ['account' => $accountId, 'tag' => $tagId, 'q' => $q],
            'page' => $page,
            'pages' => max(1, (int) ceil($total / $per)),
        ]);
    }

    public function create(): void
    {
        $post = ['id' => null, 'content' => '', 'media' => '[]', 'status' => 'draft', 'scheduled_at' => null, 'review_note' => null, 'first_comment' => null];
        // Prefill from the calendar ("?date=2026-11-03&time=09:00") or a template.
        $date = (string) input('date', '');
        if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $time = preg_match('/^\d{2}:\d{2}$/', (string) input('time', '')) ? input('time') : '09:00';
            $post['scheduled_at'] = to_utc("$date $time");
        }
        if ($tid = (int) input('template', 0)) {
            $tpl = Db::one('SELECT * FROM templates WHERE id = ? AND workspace_id = ?', [$tid, Workspace::id()]);
            if ($tpl) {
                $post['content'] = $tpl['content'];
                $post['media'] = $tpl['media'];
            }
        }
        $this->composer($post, [], [], []);
    }

    public function edit(string $id): void
    {
        $post = PostService::find((int) $id, Workspace::id());
        if (!$post) {
            abort(404);
        }
        $targets = Db::all('SELECT * FROM post_accounts WHERE post_id = ?', [$post['id']]);
        $tagIds = array_map('intval', array_column(Db::all('SELECT tag_id FROM post_tag WHERE post_id = ?', [$post['id']]), 'tag_id'));
        $readonly = in_array($post['status'], ['publishing', 'published'], true) || !Workspace::can('member');
        $this->composer($post, $targets, $tagIds, [], $readonly);
    }

    private function composer(array $post, array $targets, array $tagIds, array $errors, bool $readonly = false): never
    {
        $wsId = Workspace::id();
        $old = $_SESSION['_old_now'] ?? [];
        if ($old) {
            $post['content'] = $old['content'] ?? $post['content'];
            $post['media'] = json_encode(array_map('intval', $old['media'] ?? []));
            $targets = array_map(fn($a) => ['account_id' => (int) $a, 'content' => $old['overrides'][$a] ?? null, 'options' => json_encode(['type' => $old['types'][$a] ?? 'feed']), 'status' => 'pending'], $old['accounts'] ?? []);
            $post['first_comment'] = $old['first_comment'] ?? ($post['first_comment'] ?? null);
            $tagIds = array_map('intval', $old['tags'] ?? []);
            if (!empty($old['date'])) {
                $post['scheduled_at'] = to_utc(($old['date'] ?? '') . ' ' . ($old['time'] ?? '09:00'));
            }
        }

        $providers = [];
        foreach (Registry::all() as $key => $class) {
            $providers[$key] = ['name' => $class::name(), 'color' => $class::color(), 'limit' => $class::textLimit(), 'rules' => $class::mediaRules(),
                'types' => $class::postTypes(), 'firstComment' => $class::supportsFirstComment()];
        }
        $ws = Workspace::current();

        view('posts/composer', [
            'title' => $post['id'] ? ($readonly ? 'View post' : 'Edit post') : 'New post',
            'post' => $post,
            'readonly' => $readonly,
            'targets' => $targets,
            'tagIds' => $tagIds,
            'accounts' => self::accounts(),
            'providers' => $providers,
            'media' => Publisher::resolveMedia(json_arr($post['media'])),
            'tags' => Db::all('SELECT * FROM tags WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'templates' => Db::all('SELECT id, name, content FROM templates WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'hashtagGroups' => Db::all('SELECT * FROM hashtag_groups WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'variables' => Db::all('SELECT name, value FROM variables WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'needsApproval' => $ws['require_approval'] && !Workspace::can('admin'),
            'canApprove' => Workspace::can('admin'),
            'panels' => Hooks::filter('composer.panels', [], $post),
            'bestTimes' => $readonly ? [] : \App\Analytics::bestTimes($wsId, array_map('intval', array_column(self::accounts(), 'id')), user_tz())['top'],
            'aiEnabled' => \App\Ai::enabled($wsId),
            'canvaEnabled' => \App\Canva::enabled(),
            'driveEnabled' => \App\Drive::enabled(),
            'autolists' => Db::all('SELECT id, name FROM autolists WHERE workspace_id = ? ORDER BY name', [$wsId]),
        ]);
    }

    public function store(): void
    {
        $this->save(null);
    }

    public function update(string $id): void
    {
        $action = (string) input('action', '');
        if (in_array($action, ['approve', 'reject'], true)) {
            $this->review((int) $id, $action);
        }
        $this->save((int) $id);
    }

    private function save(?int $id): void
    {
        $action = (string) input('action', 'draft');
        if (!in_array($action, ['draft', 'schedule', 'now'], true)) {
            $action = 'draft';
        }
        $date = (string) input('date', '');
        $time = (string) input('time', '');
        $scheduledAt = $date !== '' ? to_utc($date . ' ' . ($time !== '' ? $time : '09:00')) : null;

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
        $result = PostService::save($id, $in, $action, Workspace::id(), (int) Auth::id(), Workspace::can('admin'));

        if ($result['errors']) {
            with_old($in + ['date' => $date, 'time' => $time]);
            foreach ($result['errors'] as $err) {
                flash('error', $err);
            }
            redirect($id ? "/posts/$id" : '/posts/new');
        }

        $post = $result['post'];
        if ($post['status'] === 'scheduled' && $action === 'now') {
            PostService::publishNowInBackground((int) $post['id']);
            flash('success', 'Publishing now. Refresh in a moment to see the result.');
        } elseif ($post['status'] === 'pending_approval') {
            flash('success', 'Sent for approval.');
        } elseif ($post['status'] === 'scheduled') {
            flash('success', 'Scheduled for ' . local_time($post['scheduled_at']) . '.');
        } else {
            flash('success', 'Draft saved.');
        }
        redirect('/posts/' . $post['id']);
    }

    private function review(int $id, string $action): never
    {
        if (!Workspace::can('admin')) {
            abort(403);
        }
        $post = PostService::find($id, Workspace::id());
        if (!$post || $post['status'] !== 'pending_approval') {
            flash('error', 'This post isn’t waiting for approval.');
            redirect("/posts/$id");
        }
        if ($action === 'approve') {
            PostService::approve($post, (int) Auth::id());
            flash('success', 'Approved.');
        } else {
            PostService::reject($post, (int) Auth::id(), (string) input('note', ''));
            flash('success', 'Sent back to draft with your note.');
        }
        redirect("/posts/$id");
    }

    public function delete(string $id): void
    {
        $post = PostService::find((int) $id, Workspace::id());
        if (!$post) {
            abort(404);
        }
        if ($post['status'] === 'publishing') {
            flash('error', 'This post is publishing right now. Try again in a minute.');
            redirect("/posts/$id");
        }
        PostService::delete($post);
        flash('success', 'Post deleted. Anything already published stays on the platforms.');
        redirect('/posts');
    }

    public function duplicate(string $id): void
    {
        $post = PostService::find((int) $id, Workspace::id());
        if (!$post) {
            abort(404);
        }
        $newId = Db::transaction(function () use ($post) {
            $newId = Db::insert('posts', [
                'workspace_id' => $post['workspace_id'],
                'user_id' => Auth::id(),
                'content' => $post['content'],
                'media' => $post['media'],
                'first_comment' => $post['first_comment'],
                'status' => 'draft',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            Db::query("INSERT INTO post_accounts (post_id, account_id, content, options, status) SELECT ?, account_id, content, options, 'pending' FROM post_accounts WHERE post_id = ?", [$newId, $post['id']]);
            Db::query('INSERT INTO post_tag (post_id, tag_id) SELECT ?, tag_id FROM post_tag WHERE post_id = ?', [$newId, $post['id']]);
            return $newId;
        });
        flash('success', 'Copied to a new draft.');
        redirect("/posts/$newId");
    }

    /** Retries the accounts that failed. Already-published accounts are skipped. */
    public function retry(string $id): void
    {
        $post = PostService::find((int) $id, Workspace::id());
        if (!$post || $post['status'] !== 'failed') {
            abort(404);
        }
        Db::query("UPDATE post_accounts SET status = 'pending', error = NULL WHERE post_id = ? AND status = 'failed'", [$post['id']]);
        Db::update('posts', ['status' => 'scheduled', 'scheduled_at' => now(), 'updated_at' => now()], 'id = ?', [$post['id']]);
        PostService::publishNowInBackground((int) $post['id']);
        flash('success', 'Retrying now. Refresh in a moment.');
        redirect("/posts/$id");
    }

    public static function accounts(): array
    {
        $rows = Db::all('SELECT id, provider, name, username, avatar, authorized FROM accounts WHERE workspace_id = ? ORDER BY provider, name', [Workspace::id()]);
        return array_values(array_filter($rows, fn($a) => Registry::class($a['provider']) !== null));
    }

    /** Adds target accounts and tags to post rows for list views. */
    public static function decorate(array $posts): array
    {
        if (!$posts) {
            return [];
        }
        $ids = implode(',', array_map('intval', array_column($posts, 'id')));
        $targets = Db::all("SELECT pa.post_id, pa.status, pa.url, pa.error, a.id, a.provider, a.name, a.username, a.avatar FROM post_accounts pa JOIN accounts a ON a.id = pa.account_id WHERE pa.post_id IN ($ids)");
        $tags = Db::all("SELECT pt.post_id, t.name, t.color FROM post_tag pt JOIN tags t ON t.id = pt.tag_id WHERE pt.post_id IN ($ids)");
        $media = [];
        foreach ($posts as $p) {
            $first = json_arr($p['media'])[0] ?? null;
            if ($first) {
                $media[$p['id']] = (int) $first;
            }
        }
        $thumbs = $media ? array_column(Db::all('SELECT id, path, mime FROM media WHERE id IN (' . implode(',', $media) . ')'), null, 'id') : [];
        foreach ($posts as &$p) {
            $p['targets'] = array_values(array_filter($targets, fn($t) => (int) $t['post_id'] === (int) $p['id']));
            $p['tags'] = array_values(array_filter($tags, fn($t) => (int) $t['post_id'] === (int) $p['id']));
            $p['thumb'] = isset($media[$p['id']]) ? ($thumbs[$media[$p['id']]] ?? null) : null;
        }
        return $posts;
    }
}
