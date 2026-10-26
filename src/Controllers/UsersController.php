<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;

/** Instance admins: everyone with an account on this MixCreate install. */
final class UsersController
{
    public function index(): void
    {
        $users = Db::all(
            "SELECT u.*, (SELECT GROUP_CONCAT(w.name, ', ') FROM workspace_user wu JOIN workspaces w ON w.id = wu.workspace_id WHERE wu.user_id = u.id) AS workspaces
             FROM users u ORDER BY u.name"
        );
        $workspaces = Db::all(
            'SELECT w.*, (SELECT COUNT(*) FROM workspace_user WHERE workspace_id = w.id) AS members,
                    (SELECT COUNT(*) FROM accounts WHERE workspace_id = w.id) AS accounts
             FROM workspaces w ORDER BY w.name'
        );
        view('admin/users', ['title' => 'Users', 'users' => $users, 'workspaces' => $workspaces]);
    }

    public function toggleAdmin(string $id): void
    {
        $id = (int) $id;
        if ($id === Auth::id()) {
            flash('error', 'You can’t change your own admin status.');
            redirect('/admin/users');
        }
        Db::query('UPDATE users SET is_admin = 1 - is_admin WHERE id = ?', [$id]);
        flash('success', 'Updated.');
        redirect('/admin/users');
    }

    public function delete(string $id): void
    {
        $id = (int) $id;
        if ($id === Auth::id()) {
            flash('error', 'You can’t delete yourself.');
            redirect('/admin/users');
        }
        Db::query('DELETE FROM users WHERE id = ?', [$id]);
        flash('success', 'User deleted. Their posts stay in the workspace.');
        redirect('/admin/users');
    }
}
