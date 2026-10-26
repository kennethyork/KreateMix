<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Db;
use App\Workspace;

final class TagsController
{
    public function index(): void
    {
        $tags = Db::all(
            'SELECT t.*, (SELECT COUNT(*) FROM post_tag WHERE tag_id = t.id) AS posts FROM tags t WHERE workspace_id = ? ORDER BY name',
            [Workspace::id()]
        );
        if (wants_json()) {
            json_response(['tags' => $tags]);
        }
        redirect('/library#tags');
    }

    public function store(): void
    {
        $name = mb_substr((string) input('name', ''), 0, 40);
        $color = preg_match('/^#[0-9a-f]{6}$/i', (string) input('color', '')) ? input('color') : '#64748b';
        if ($name === '') {
            if (wants_json()) {
                json_response(['error' => 'Name required.'], 422);
            }
            flash('error', 'Give the tag a name.');
            redirect('/library#tags');
        }
        Db::query('INSERT OR IGNORE INTO tags (workspace_id, name, color) VALUES (?, ?, ?)', [Workspace::id(), $name, $color]);
        $tag = Db::one('SELECT * FROM tags WHERE workspace_id = ? AND name = ?', [Workspace::id(), $name]);
        if (wants_json()) {
            json_response(['tag' => $tag]);
        }
        flash('success', 'Tag added.');
        redirect('/library#tags');
    }

    public function delete(string $id): void
    {
        Db::query('DELETE FROM tags WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Tag deleted.');
        redirect('/library#tags');
    }
}
