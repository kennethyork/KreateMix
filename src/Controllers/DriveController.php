<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Drive;
use App\Workspace;

final class DriveController
{
    public function connect(): void
    {
        if (!Drive::enabled()) {
            flash('error', 'Google Drive isn’t set up. An instance admin can add it under Integrations.');
            back('/media');
        }
        $state = bin2hex(random_bytes(16));
        $_SESSION['_gdrive'] = ['state' => $state, 'back' => (string) input('back', '/media')];
        redirect(Drive::authUrl($state));
    }

    public function callback(): void
    {
        $pending = $_SESSION['_gdrive'] ?? null;
        unset($_SESSION['_gdrive']);
        $back = str_starts_with((string) ($pending['back'] ?? ''), '/') ? $pending['back'] : '/media';
        if (!$pending || !hash_equals($pending['state'], (string) input('state', '')) || input('error')) {
            flash('error', 'Google Drive connection cancelled or expired.');
            redirect($back);
        }
        try {
            Drive::connect((int) Auth::id(), (string) input('code', ''));
            flash('success', 'Google Drive connected.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($back, ['drive' => 1]);
    }

    public function disconnect(): void
    {
        Drive::disconnect((int) Auth::id());
        flash('success', 'Google Drive disconnected.');
        redirect('/media');
    }

    public function files(): void
    {
        if (!Drive::connected((int) Auth::id())) {
            json_response(['connected' => false]);
        }
        try {
            json_response(['connected' => true] + Drive::files((int) Auth::id(), input('continuation') ?: null, (string) input('q', '')));
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage(), 'connected' => Drive::connected((int) Auth::id())], 422);
        }
    }

    public function import(): void
    {
        set_time_limit(600);
        try {
            json_response(['media' => Drive::import((int) Auth::id(), Workspace::id(), (string) input('design', ''))]);
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
    }
}
