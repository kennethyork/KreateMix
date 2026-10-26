<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Canva;
use App\Workspace;

final class CanvaController
{
    public function connect(): void
    {
        if (!Canva::enabled()) {
            flash('error', 'Canva isn’t set up. An instance admin can add it under Integrations.');
            back('/media');
        }
        $state = bin2hex(random_bytes(16));
        $verifier = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $_SESSION['_canva'] = ['state' => $state, 'verifier' => $verifier, 'back' => (string) input('back', '/media')];
        redirect(Canva::authUrl($state, $verifier));
    }

    public function callback(): void
    {
        $pending = $_SESSION['_canva'] ?? null;
        unset($_SESSION['_canva']);
        $back = $pending['back'] ?? '/media';
        if (!str_starts_with($back, '/')) {
            $back = '/media';
        }
        if (!$pending || !hash_equals($pending['state'], (string) input('state', ''))) {
            flash('error', 'The Canva connection expired. Try again.');
            redirect($back);
        }
        if (input('error')) {
            flash('error', 'Canva connection cancelled.');
            redirect($back);
        }
        try {
            Canva::connect((int) Auth::id(), (string) input('code', ''), $pending['verifier']);
            flash('success', 'Canva connected. Pick a design to import.');
        } catch (\Throwable $e) {
            flash('error', $e->getMessage());
        }
        redirect($back, ['canva' => 1]);
    }

    public function disconnect(): void
    {
        Canva::disconnect((int) Auth::id());
        flash('success', 'Canva disconnected.');
        redirect('/media');
    }

    public function designs(): void
    {
        if (!Canva::connected((int) Auth::id())) {
            json_response(['connected' => false]);
        }
        try {
            $r = Canva::designs((int) Auth::id(), input('continuation') ?: null, (string) input('q', ''));
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage(), 'connected' => Canva::connected((int) Auth::id())], 422);
        }
        json_response(['connected' => true] + $r);
    }

    public function import(): void
    {
        set_time_limit(300);
        try {
            $media = Canva::import((int) Auth::id(), Workspace::id(), (string) input('design', ''), (string) input('format', 'png'));
        } catch (\Throwable $e) {
            json_response(['error' => $e->getMessage()], 422);
        }
        json_response(['media' => $media]);
    }
}
