<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;
use App\Workspace;

final class AuthController
{
    public function showLogin(): void
    {
        if (!Db::value('SELECT COUNT(*) FROM users')) {
            redirect('/setup');
        }
        view('auth/login', ['title' => 'Sign in'], 'auth');
    }

    public function login(): void
    {
        $email = (string) input('email', '');
        $password = (string) input('password', '');

        $attempts = $_SESSION['_login_attempts'] ?? ['n' => 0, 't' => time()];
        if (time() - $attempts['t'] > 600) {
            $attempts = ['n' => 0, 't' => time()];
        }
        if ($attempts['n'] >= 8) {
            flash('error', 'Too many attempts. Wait a few minutes and try again.');
            redirect('/login');
        }

        if (!Auth::attempt($email, $password)) {
            $attempts['n']++;
            $_SESSION['_login_attempts'] = $attempts;
            with_old(['email' => $email]);
            flash('error', 'Those details don’t match an account.');
            redirect('/login');
        }
        unset($_SESSION['_login_attempts']);
        $intended = $_SESSION['_intended'] ?? null;
        unset($_SESSION['_intended']);
        if ($intended && str_starts_with($intended, base_path() . '/') && !str_contains($intended, '//')) {
            header('Location: ' . $intended);
            exit;
        }
        redirect('/');
    }

    public function logout(): void
    {
        Auth::logout();
        redirect('/login');
    }

    /** First-run: create the instance admin and their first workspace. */
    public function showSetup(): void
    {
        if (Db::value('SELECT COUNT(*) FROM users')) {
            redirect('/login');
        }
        view('auth/setup', ['title' => 'Set up MixCreate', 'timezones' => \DateTimeZone::listIdentifiers()], 'auth');
    }

    public function setup(): void
    {
        if (Db::value('SELECT COUNT(*) FROM users')) {
            redirect('/login');
        }
        $data = [
            'name' => (string) input('name', ''),
            'email' => (string) input('email', ''),
            'workspace' => (string) input('workspace', ''),
            'timezone' => (string) input('timezone', 'UTC'),
        ];
        $errors = self::validateUser($data['name'], $data['email'], (string) input('password', ''), (string) input('password_confirmation', ''));
        if ($data['workspace'] === '') {
            $errors[] = 'Give your first workspace a name.';
        }
        if ($errors) {
            with_old($data);
            foreach ($errors as $err) {
                flash('error', $err);
            }
            redirect('/setup');
        }
        $tz = in_array($data['timezone'], \DateTimeZone::listIdentifiers(), true) ? $data['timezone'] : 'UTC';
        $userId = Db::insert('users', [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => password_hash((string) input('password'), PASSWORD_DEFAULT),
            'is_admin' => 1,
            'timezone' => $tz,
            'created_at' => now(),
        ]);
        $wsId = Workspace::create($data['workspace'], $userId);
        Db::update('users', ['current_workspace_id' => $wsId], 'id = ?', [$userId]);
        Auth::login($userId);
        flash('success', 'You’re all set. Next, add your platform API keys and connect accounts.');
        redirect('/accounts');
    }

    public function showInvite(string $token): void
    {
        $invite = self::findInvite($token);
        $existing = Db::one('SELECT id FROM users WHERE email = ?', [$invite['email']]);
        view('auth/invite', [
            'title' => 'Join ' . $invite['workspace_name'],
            'invite' => $invite,
            'existing' => (bool) $existing,
            'current' => Auth::user(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ], 'auth');
    }

    public function acceptInvite(string $token): void
    {
        $invite = self::findInvite($token);
        $current = Auth::user();
        $existing = Db::one('SELECT * FROM users WHERE email = ?', [$invite['email']]);

        if ($current) {
            if (strcasecmp($current['email'], $invite['email']) !== 0) {
                flash('error', 'This invitation is for ' . $invite['email'] . '. Sign out and open the link again.');
                redirect('/invite/' . $token);
            }
            $userId = (int) $current['id'];
        } elseif ($existing) {
            if (!password_verify((string) input('password', ''), $existing['password'])) {
                flash('error', 'Wrong password.');
                redirect('/invite/' . $token);
            }
            $userId = (int) $existing['id'];
        } else {
            $name = (string) input('name', '');
            $errors = self::validateUser($name, $invite['email'], (string) input('password', ''), (string) input('password_confirmation', ''));
            if ($errors) {
                with_old(['name' => $name]);
                foreach ($errors as $err) {
                    flash('error', $err);
                }
                redirect('/invite/' . $token);
            }
            $tz = (string) input('timezone', 'UTC');
            $userId = Db::insert('users', [
                'name' => $name,
                'email' => $invite['email'],
                'password' => password_hash((string) input('password'), PASSWORD_DEFAULT),
                'timezone' => in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'UTC',
                'created_at' => now(),
            ]);
        }

        Db::transaction(function () use ($invite, $userId) {
            Db::query(
                'INSERT OR IGNORE INTO workspace_user (workspace_id, user_id, role, joined_at) VALUES (?, ?, ?, ?)',
                [$invite['workspace_id'], $userId, $invite['role'], now()]
            );
            Db::update('invitations', ['accepted_at' => now()], 'id = ?', [$invite['id']]);
            Db::update('users', ['current_workspace_id' => $invite['workspace_id']], 'id = ?', [$userId]);
        });
        if (!$current) {
            Auth::login($userId);
        }
        flash('success', 'Welcome to ' . $invite['workspace_name'] . '.');
        redirect('/');
    }

    private static function findInvite(string $token): array
    {
        $invite = Db::one(
            'SELECT i.*, w.name AS workspace_name FROM invitations i JOIN workspaces w ON w.id = i.workspace_id
             WHERE i.token = ? AND i.accepted_at IS NULL',
            [hash('sha256', $token)]
        );
        if (!$invite || strtotime($invite['created_at'] . ' UTC') < time() - 7 * 86400) {
            abort(404, 'This invitation link is invalid or has expired. Ask for a new one.');
        }
        return $invite;
    }

    public static function validateUser(string $name, string $email, string $password, string $confirm): array
    {
        $errors = [];
        if ($name === '') {
            $errors[] = 'Enter your name.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address.';
        } elseif (Db::value('SELECT 1 FROM users WHERE email = ?', [$email])) {
            $errors[] = 'An account with that email already exists.';
        }
        if (strlen($password) < 8) {
            $errors[] = 'Use a password of at least 8 characters.';
        } elseif ($password !== $confirm) {
            $errors[] = 'The passwords don’t match.';
        }
        return $errors;
    }
}
