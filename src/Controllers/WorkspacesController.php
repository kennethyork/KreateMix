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
            'branding' => \App\Branding::for(Workspace::id()),
            'aiWs' => \App\Settings::get('ai.ws.' . Workspace::id()) + ['api_key' => null],
            'models' => \App\Ai::openRouterModels(),
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

    public function branding(): void
    {
        $logo = (int) input('logo_media_id', 0);
        if ($logo && !Db::value("SELECT 1 FROM media WHERE id = ? AND workspace_id = ? AND mime LIKE 'image/%'", [$logo, Workspace::id()])) {
            $logo = 0;
        }
        Db::update('workspaces', ['branding' => json_encode([
            'name' => mb_substr((string) input('brand_name', ''), 0, 80),
            'color' => preg_match('/^#[0-9a-f]{6}$/i', (string) input('brand_color', '')) ? input('brand_color') : null,
            'logo_media_id' => $logo ?: null,
            'footer' => mb_substr((string) input('brand_footer', ''), 0, 120),
            'hide_powered_by' => (bool) input('hide_powered_by'),
        ])], 'id = ?', [Workspace::id()]);
        flash('success', 'Branding saved.');
        redirect('/settings');
    }

    public function ai(): void
    {
        $group = 'ai.ws.' . Workspace::id();
        $cur = \App\Settings::get($group);
        $key = trim((string) input('api_key', ''));
        if (input('remove_key')) {
            $cur['api_key'] = null;
        } elseif ($key !== '') {
            $cur['api_key'] = $key;
        }
        $cur['model'] = mb_substr(trim((string) input('model', '')), 0, 120);
        $cur['brand_voice'] = mb_substr(trim((string) input('brand_voice', '')), 0, 500);
        \App\Settings::save($group, $cur);
        flash('success', 'AI settings saved.');
        redirect('/settings#ai');
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
