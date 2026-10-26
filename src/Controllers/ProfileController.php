<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Db;

final class ProfileController
{
    public function edit(): void
    {
        view('profile/edit', [
            'title' => 'Your profile',
            'user' => Auth::user(),
            'timezones' => \DateTimeZone::listIdentifiers(),
        ]);
    }

    public function update(): void
    {
        $name = (string) input('name', '');
        $email = (string) input('email', '');
        $tz = (string) input('timezone', 'UTC');
        if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Enter your name and a valid email.');
            redirect('/profile');
        }
        if (Db::value('SELECT 1 FROM users WHERE email = ? AND id != ?', [$email, Auth::id()])) {
            flash('error', 'Another account uses that email.');
            redirect('/profile');
        }
        if (!in_array($tz, \DateTimeZone::listIdentifiers(), true)) {
            $tz = 'UTC';
        }
        Db::update('users', ['name' => $name, 'email' => $email, 'timezone' => $tz], 'id = ?', [Auth::id()]);
        flash('success', 'Profile saved.');
        redirect('/profile');
    }

    public function password(): void
    {
        $user = Auth::user();
        if (!password_verify((string) input('current_password', ''), $user['password'])) {
            flash('error', 'Your current password is wrong.');
            redirect('/profile');
        }
        $new = (string) input('password', '');
        if (strlen($new) < 8 || $new !== input('password_confirmation')) {
            flash('error', 'Use at least 8 characters and make sure both entries match.');
            redirect('/profile');
        }
        Db::update('users', ['password' => password_hash($new, PASSWORD_DEFAULT)], 'id = ?', [$user['id']]);
        session_regenerate_id(true);
        flash('success', 'Password changed.');
        redirect('/profile');
    }
}
