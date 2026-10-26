<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Workspace;

/** Templates, hashtag groups, variables and tags: reusable pieces for the composer. */
final class LibraryController
{
    public function index(): void
    {
        $wsId = Workspace::id();
        view('library/index', [
            'title' => 'Library',
            'templates' => Db::all('SELECT * FROM templates WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'groups' => Db::all('SELECT * FROM hashtag_groups WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'variables' => Db::all('SELECT * FROM variables WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'tags' => Db::all('SELECT t.*, (SELECT COUNT(*) FROM post_tag WHERE tag_id = t.id) AS posts FROM tags t WHERE workspace_id = ? ORDER BY name', [$wsId]),
            'edit' => (int) input('edit', 0),
        ]);
    }

    public function saveTemplate(): void
    {
        $id = (int) input('id', 0);
        $name = mb_substr((string) input('name', ''), 0, 80);
        $content = str_replace("\r\n", "\n", (string) input('content', ''));
        $media = array_values(array_filter(array_map('intval', (array) input('media', []))));
        if ($name === '' || ($content === '' && !$media)) {
            if (wants_json()) {
                json_response(['error' => 'A template needs a name and some text.'], 422);
            }
            flash('error', 'A template needs a name and some text.');
            redirect('/library#templates');
        }
        $row = ['name' => $name, 'content' => $content, 'media' => json_encode($media), 'updated_at' => now()];
        if ($id) {
            Db::update('templates', $row, 'id = ? AND workspace_id = ?', [$id, Workspace::id()]);
        } else {
            $id = Db::insert('templates', $row + ['workspace_id' => Workspace::id(), 'created_by' => Auth::id(), 'created_at' => now()]);
        }
        if (wants_json()) {
            json_response(['template' => Db::one('SELECT id, name, content FROM templates WHERE id = ?', [$id])]);
        }
        flash('success', 'Template saved.');
        redirect('/library#templates');
    }

    public function deleteTemplate(string $id): void
    {
        Db::query('DELETE FROM templates WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Template deleted.');
        redirect('/library#templates');
    }

    public function saveGroup(): void
    {
        $id = (int) input('id', 0);
        $name = mb_substr((string) input('name', ''), 0, 80);
        $tags = self::normalizeHashtags((string) input('hashtags', ''));
        if ($name === '' || $tags === '') {
            flash('error', 'A hashtag group needs a name and at least one hashtag.');
            redirect('/library#hashtags');
        }
        if ($id) {
            Db::update('hashtag_groups', ['name' => $name, 'hashtags' => $tags], 'id = ? AND workspace_id = ?', [$id, Workspace::id()]);
        } else {
            Db::insert('hashtag_groups', ['workspace_id' => Workspace::id(), 'name' => $name, 'hashtags' => $tags, 'created_at' => now()]);
        }
        flash('success', 'Hashtag group saved.');
        redirect('/library#hashtags');
    }

    public function deleteGroup(string $id): void
    {
        Db::query('DELETE FROM hashtag_groups WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Hashtag group deleted.');
        redirect('/library#hashtags');
    }

    public function saveVariable(): void
    {
        $id = (int) input('id', 0);
        $name = (string) input('name', '');
        $value = (string) input('value', '');
        if (!preg_match('/^[\w.-]{1,40}$/', $name)) {
            flash('error', 'Variable names can use letters, numbers, dots, dashes and underscores.');
            redirect('/library#variables');
        }
        try {
            if ($id) {
                Db::update('variables', ['name' => $name, 'value' => $value], 'id = ? AND workspace_id = ?', [$id, Workspace::id()]);
            } else {
                Db::insert('variables', ['workspace_id' => Workspace::id(), 'name' => $name, 'value' => $value]);
            }
        } catch (\PDOException) {
            flash('error', "A variable called “{$name}” already exists.");
            redirect('/library#variables');
        }
        flash('success', 'Variable saved.');
        redirect('/library#variables');
    }

    public function deleteVariable(string $id): void
    {
        Db::query('DELETE FROM variables WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Variable deleted.');
        redirect('/library#variables');
    }

    /** "foo, #bar baz" → "#foo #bar #baz" */
    public static function normalizeHashtags(string $raw): string
    {
        $words = preg_split('/[\s,]+/u', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = [];
        foreach ($words as $w) {
            $w = '#' . ltrim($w, '#');
            if (mb_strlen($w) > 1 && !in_array($w, $out, true)) {
                $out[] = $w;
            }
        }
        return implode(' ', $out);
    }
}
