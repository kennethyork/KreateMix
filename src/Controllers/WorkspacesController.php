<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Hooks;
use App\Workspace;

final class WorkspacesController
{
    public const COLORS = ['#4f46e5', '#0891b2', '#059669', '#ca8a04', '#ea580c', '#dc2626', '#db2777', '#7c3aed', '#475569'];

    public function create(): void
    {
        // Only instance admins create workspaces; others land here only if they belong to none.
        if (!Auth::isAdmin()) {
            if (Workspace::current()) {
                redirect('/');
            }
            view('workspaces/none', ['title' => 'No workspace'], 'auth');
        }
        view('workspaces/create', ['title' => 'New workspace', 'colors' => self::COLORS]);
    }

    public function store(): void
    {
        if (!Auth::isAdmin()) {
            abort(403);
        }
        $name = (string) input('name', '');
        if ($name === '') {
            flash('error', 'Give the workspace a name.');
            redirect('/workspaces/new');
        }
        $color = in_array(input('color'), self::COLORS, true) ? input('color') : self::COLORS[0];
        $id = Workspace::create($name, (int) Auth::id(), $color);
        Workspace::switchTo($id);
        flash('success', "Workspace “{$name}” created.");
        redirect('/accounts');
    }

    public function switch(string $id): void
    {
        if (!Workspace::switchTo((int) $id)) {
            abort(403);
        }
        redirect('/');
    }

    public function edit(): void
    {
        view('workspaces/edit', [
            'title' => 'Workspace settings',
            'workspace' => Workspace::current(),
            'colors' => self::COLORS,
            'tabs' => Hooks::filter('settings.tabs', []),
        ]);
    }

    public function update(): void
    {
        $name = (string) input('name', '');
        if ($name === '') {
            flash('error', 'The name can’t be empty.');
            redirect('/settings');
        }
        $color = in_array(input('color'), self::COLORS, true) ? input('color') : Workspace::current()['color'];
        Db::update('workspaces', [
            'name' => $name,
            'color' => $color,
            'require_approval' => input('require_approval') ? 1 : 0,
        ], 'id = ?', [Workspace::id()]);
        flash('success', 'Saved.');
        redirect('/settings');
    }

    public function delete(): void
    {
        $ws = Workspace::current();
        if (input('confirm') !== $ws['name']) {
            flash('error', 'Type the workspace name exactly to delete it.');
            redirect('/settings');
        }
        $files = Db::all('SELECT path FROM media WHERE workspace_id = ?', [$ws['id']]);
        Db::query('DELETE FROM workspaces WHERE id = ?', [$ws['id']]);
        foreach ($files as $f) {
            @unlink(BASE_PATH . '/public/' . $f['path']);
        }
        Db::query('UPDATE users SET current_workspace_id = NULL WHERE current_workspace_id = ?', [$ws['id']]);
        flash('success', "Deleted “{$ws['name']}”.");
        redirect('/');
    }
}
