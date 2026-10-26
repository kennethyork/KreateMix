<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Autolists;
use App\Db;
use App\Publisher;
use App\Workspace;

final class AutolistsController
{
    public function index(): void
    {
        $lists = Db::all(
            'SELECT l.*, (SELECT COUNT(*) FROM autolist_items WHERE autolist_id = l.id) AS items,
                    (SELECT COUNT(*) FROM autolist_runs WHERE autolist_id = l.id AND post_id IS NOT NULL) AS runs
             FROM autolists l WHERE workspace_id = ? ORDER BY name',
            [Workspace::id()]
        );
        foreach ($lists as &$l) {
            $l['next'] = Autolists::upcoming($l, 1)[0] ?? null;
        }
        unset($l);
        view('autolists/index', ['title' => 'Autolists', 'lists' => $lists, 'accounts' => PostsController::accounts()]);
    }

    public function show(string $id): void
    {
        $list = $this->find($id);
        $items = Db::all('SELECT * FROM autolist_items WHERE autolist_id = ? ORDER BY position, id', [$list['id']]);
        foreach ($items as &$it) {
            $it['media_items'] = Publisher::resolveMedia(json_arr($it['media']));
        }
        unset($it);
        view('autolists/show', [
            'title' => $list['name'],
            'list' => $list,
            'items' => $items,
            'accounts' => PostsController::accounts(),
            'upcoming' => Autolists::upcoming($list, 6),
            'recent' => PostsController::decorate(Db::all('SELECT * FROM posts WHERE autolist_id = ? ORDER BY id DESC LIMIT 5', [$list['id']])),
        ]);
    }

    private function find(string $id): array
    {
        $list = Db::one('SELECT * FROM autolists WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$list) {
            abort(404);
        }
        return $list;
    }

    private function fields(): array
    {
        $accounts = array_values(array_intersect(array_map('intval', (array) input('accounts', [])), array_map('intval', array_column(PostsController::accounts(), 'id'))));
        $slots = [];
        $times = array_filter((array) input('times', []), fn($t) => preg_match('/^\d{2}:\d{2}$/', (string) $t));
        foreach (array_map('intval', (array) input('days', [])) as $d) {
            if ($d >= 0 && $d <= 6) {
                foreach ($times as $t) {
                    $slots[] = ['day' => $d, 'time' => $t];
                }
            }
        }
        $name = mb_substr((string) input('name', ''), 0, 80);
        if ($name === '' || !$accounts || !$slots) {
            flash('error', 'Give the autolist a name, at least one account, and at least one day and time.');
            back('/autolists');
        }
        return [
            'name' => $name,
            'accounts' => json_encode($accounts),
            'slots' => json_encode($slots),
            'order_mode' => input('order_mode') === 'random' ? 'random' : 'sequence',
            'timezone' => user_tz(),
        ];
    }

    public function store(): void
    {
        $id = Db::insert('autolists', $this->fields() + ['workspace_id' => Workspace::id(), 'created_by' => Auth::id(), 'created_at' => now()]);
        flash('success', 'Autolist created. Add posts to it.');
        redirect('/autolists/' . $id);
    }

    public function update(string $id): void
    {
        $list = $this->find($id);
        Db::update('autolists', $this->fields() + ['active' => input('active') ? 1 : 0], 'id = ?', [$list['id']]);
        flash('success', 'Saved.');
        redirect('/autolists/' . $list['id']);
    }

    public function delete(string $id): void
    {
        $list = $this->find($id);
        Db::query('DELETE FROM autolists WHERE id = ?', [$list['id']]);
        flash('success', 'Autolist deleted. Posts it already created stay.');
        redirect('/autolists');
    }

    public function addItem(string $id): void
    {
        $list = $this->find($id);
        $content = str_replace("\r\n", "\n", trim((string) input('content', '')));
        $media = array_values(array_filter(array_map('intval', (array) input('media', []))));
        if ($media) {
            $owned = Db::all('SELECT id FROM media WHERE workspace_id = ? AND id IN (' . implode(',', $media) . ')', [Workspace::id()]);
            $media = array_values(array_intersect($media, array_map('intval', array_column($owned, 'id'))));
        }
        if ($content === '' && !$media) {
            flash('error', 'Add some text or media.');
            redirect('/autolists/' . $list['id']);
        }
        $itemId = (int) input('item_id', 0);
        if ($itemId) {
            Db::update('autolist_items', ['content' => $content, 'media' => json_encode($media)], 'id = ? AND autolist_id = ?', [$itemId, $list['id']]);
        } else {
            $pos = (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM autolist_items WHERE autolist_id = ?', [$list['id']]);
            Db::insert('autolist_items', ['autolist_id' => $list['id'], 'content' => $content, 'media' => json_encode($media), 'position' => $pos, 'created_at' => now()]);
        }
        redirect('/autolists/' . $list['id']);
    }

    public function deleteItem(string $id, string $item): void
    {
        $list = $this->find($id);
        Db::query('DELETE FROM autolist_items WHERE id = ? AND autolist_id = ?', [(int) $item, $list['id']]);
        redirect('/autolists/' . $list['id']);
    }

    /** Copies an existing post into an autolist. */
    public function addPost(string $id): void
    {
        $post = Db::one('SELECT * FROM posts WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        $list = $this->find((string) input('autolist', '0'));
        if (!$post) {
            abort(404);
        }
        $pos = (int) Db::value('SELECT COALESCE(MAX(position), 0) + 1 FROM autolist_items WHERE autolist_id = ?', [$list['id']]);
        Db::insert('autolist_items', ['autolist_id' => $list['id'], 'content' => $post['content'], 'media' => $post['media'], 'position' => $pos, 'created_at' => now()]);
        flash('success', 'Added to “' . $list['name'] . '”.');
        redirect('/posts/' . $post['id']);
    }

    public function publishNow(string $id): void
    {
        $list = $this->find($id);
        $postId = Autolists::publishNext($list);
        if ($postId) {
            \App\PostService::publishNowInBackground($postId);
            flash('success', 'Publishing the next item now.');
            redirect('/posts/' . $postId);
        }
        flash('error', 'Nothing to publish. Check the list has items and accounts.');
        redirect('/autolists/' . $list['id']);
    }
}
