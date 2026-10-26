<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Workspace;

final class MembersController
{
    public function index(): void
    {
        $wsId = Workspace::id();
        view('members/index', [
            'title' => 'Team',
            'members' => Db::all(
                'SELECT u.id, u.name, u.email, u.is_admin, wu.role, wu.joined_at FROM workspace_user wu
                 JOIN users u ON u.id = wu.user_id WHERE wu.workspace_id = ? ORDER BY u.name',
                [$wsId]
            ),
            'invitations' => Db::all(
                'SELECT * FROM invitations WHERE workspace_id = ? AND accepted_at IS NULL ORDER BY created_at DESC',
                [$wsId]
            ),
            'inviteLink' => $_SESSION['_invite_link'] ?? null,
        ]);
    }

    public function invite(): void
    {
        unset($_SESSION['_invite_link']);
        $email = (string) input('email', '');
        $role = (string) input('role', 'member');
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !isset(Workspace::ROLES[$role])) {
            flash('error', 'Enter a valid email and role.');
            redirect('/members');
        }
        $wsId = Workspace::id();
        $existing = Db::one('SELECT id FROM users WHERE email = ?', [$email]);
        if ($existing && Db::value('SELECT 1 FROM workspace_user WHERE workspace_id = ? AND user_id = ?', [$wsId, $existing['id']])) {
            flash('error', "$email is already in this workspace.");
            redirect('/members');
        }
        Db::query('DELETE FROM invitations WHERE workspace_id = ? AND email = ? AND accepted_at IS NULL', [$wsId, $email]);
        $token = bin2hex(random_bytes(24));
        Db::insert('invitations', [
            'workspace_id' => $wsId,
            'email' => $email,
            'role' => $role,
            'token' => hash('sha256', $token),
            'invited_by' => Auth::id(),
            'created_at' => now(),
        ]);
        // No mail server is assumed; the admin copies the link and sends it however they like.
        $_SESSION['_invite_link'] = ['email' => $email, 'url' => abs_url('invite/' . $token)];
        redirect('/members');
    }

    public function role(string $id): void
    {
        $role = (string) input('role', '');
        if (!isset(Workspace::ROLES[$role])) {
            abort(422, 'Unknown role.');
        }
        $this->guardLastAdmin((int) $id, $role !== 'admin');
        Db::update('workspace_user', ['role' => $role], 'workspace_id = ? AND user_id = ?', [Workspace::id(), (int) $id]);
        flash('success', 'Role updated.');
        redirect('/members');
    }

    public function remove(string $id): void
    {
        $this->guardLastAdmin((int) $id, true);
        Db::query('DELETE FROM workspace_user WHERE workspace_id = ? AND user_id = ?', [Workspace::id(), (int) $id]);
        Db::query('UPDATE users SET current_workspace_id = NULL WHERE id = ? AND current_workspace_id = ?', [(int) $id, Workspace::id()]);
        flash('success', 'Removed from workspace.');
        redirect((int) $id === Auth::id() ? '/' : '/members');
    }

    public function revoke(string $id): void
    {
        Db::query('DELETE FROM invitations WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        unset($_SESSION['_invite_link']);
        flash('success', 'Invitation revoked.');
        redirect('/members');
    }

    private function guardLastAdmin(int $userId, bool $losingAdmin): void
    {
        if (!$losingAdmin) {
            return;
        }
        $role = Db::value('SELECT role FROM workspace_user WHERE workspace_id = ? AND user_id = ?', [Workspace::id(), $userId]);
        $admins = (int) Db::value("SELECT COUNT(*) FROM workspace_user WHERE workspace_id = ? AND role = 'admin'", [Workspace::id()]);
        if ($role === 'admin' && $admins <= 1) {
            flash('error', 'A workspace needs at least one admin.');
            redirect('/members');
        }
    }
}
