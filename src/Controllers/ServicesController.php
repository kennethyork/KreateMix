<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Crypt;
use App\Db;
use App\Providers\Registry;

/** Instance-wide API keys for each platform. Instance admins only. */
final class ServicesController
{
    public function index(): void
    {
        $services = [];
        foreach (Registry::all() as $key => $class) {
            $creds = Registry::credentials($key);
            $fields = [];
            foreach ($class::credentialFields() as $f => $label) {
                // Secrets are never sent back to the browser; show only whether they're set.
                $isSecret = str_contains($f, 'secret');
                $fields[$f] = [
                    'label' => $label,
                    'secret' => $isSecret,
                    'value' => $isSecret ? '' : ($creds[$f] ?? ''),
                    'set' => !empty($creds[$f]),
                ];
            }
            $services[$key] = [
                'name' => $class::name(),
                'color' => $class::color(),
                'implemented' => $class::implemented(),
                'ready' => Registry::ready($key),
                'hint' => $class::setupHint(),
                'fields' => $fields,
                'callback' => abs_url('accounts/callback/' . $key),
                'oauth' => $class::usesOAuth(),
            ];
        }
        view('admin/services', ['title' => 'Platform API keys', 'services' => $services]);
    }

    public function update(string $provider): void
    {
        $class = Registry::class($provider);
        if (!$class) {
            abort(404);
        }
        $creds = Registry::credentials($provider);
        foreach (array_keys($class::credentialFields()) as $f) {
            $v = (string) input($f, '');
            // Leaving a secret blank keeps the stored one.
            if ($v !== '' || !str_contains($f, 'secret')) {
                $creds[$f] = $v;
            }
        }
        Db::query(
            'INSERT INTO services (provider, credentials, active, updated_at) VALUES (?, ?, 1, ?)
             ON CONFLICT(provider) DO UPDATE SET credentials = excluded.credentials, updated_at = excluded.updated_at',
            [$provider, Crypt::encrypt($creds), now()]
        );
        flash('success', $class::name() . ' keys saved.');
        redirect('/admin/services#' . $provider);
    }
}
