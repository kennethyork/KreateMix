<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Auth;
use App\Crypt;
use App\Db;
use App\Providers\ProviderException;
use App\Providers\Registry;
use App\Webhooks;
use App\Workspace;

final class AccountsController
{
    public function index(): void
    {
        $accounts = Db::all('SELECT * FROM accounts WHERE workspace_id = ? ORDER BY provider, name', [Workspace::id()]);
        $providers = [];
        foreach (Registry::all() as $key => $class) {
            $providers[$key] = [
                'name' => $class::name(),
                'color' => $class::color(),
                'implemented' => $class::implemented(),
                'ready' => Registry::ready($key),
            ];
        }
        view('accounts/index', [
            'title' => 'Accounts',
            'accounts' => $accounts,
            'providers' => $providers,
        ]);
    }

    public function connect(string $provider): void
    {
        $class = $this->readyProvider($provider);
        if ($class::connectFields()) {
            view('accounts/connect', [
                'title' => 'Connect ' . $class::name(),
                'provider' => $provider,
                'name' => $class::name(),
                'fields' => $class::connectFields(),
                'hint' => $class::setupHint(),
            ]);
        }
        $this->startOAuth($provider, []);
    }

    public function connectSubmit(string $provider): void
    {
        $class = $this->readyProvider($provider);
        $form = [];
        foreach (array_keys($class::connectFields()) as $field) {
            $form[$field] = (string) input($field, '');
        }
        if ($class::usesOAuth()) {
            $this->startOAuth($provider, $form);
        }
        try {
            $accounts = Registry::make($provider)->connect($form, '');
        } catch (\Throwable $e) {
            flash('error', $this->message($e));
            redirect('/accounts/connect/' . $provider);
        }
        $this->saveAccounts($provider, $accounts);
    }

    public function callback(string $provider): void
    {
        $this->readyProvider($provider);
        $pending = $_SESSION['_oauth'] ?? null;
        unset($_SESSION['_oauth']);
        if (!$pending || $pending['provider'] !== $provider || !hash_equals($pending['state'], (string) input('state', ''))) {
            flash('error', 'The connection expired or was started in another browser. Try again.');
            redirect('/accounts');
        }
        if (input('error')) {
            flash('error', 'Connection cancelled: ' . (string) (input('error_description') ?: input('error')));
            redirect('/accounts');
        }
        try {
            $p = Registry::make($provider);
            $p->stash = $pending['stash'];
            $accounts = $p->connect($_GET + ['_form' => $pending['form']], abs_url('accounts/callback/' . $provider));
        } catch (\Throwable $e) {
            log_event('error', "Connect $provider failed: " . $e->getMessage());
            flash('error', $this->message($e));
            redirect('/accounts');
        }
        $this->saveAccounts($provider, $accounts);
    }

    /** Lets the admin pick which pages, boards or locations to add when a login returns several. */
    public function choose(): void
    {
        $pending = $_SESSION['_choose'] ?? null;
        if (!$pending) {
            redirect('/accounts');
        }
        $accounts = json_decode((string) Crypt::decrypt($pending['accounts']), true) ?: [];
        view('accounts/choose', [
            'title' => 'Choose accounts',
            'provider' => $pending['provider'],
            'name' => Registry::class($pending['provider'])::name(),
            'accounts' => $accounts,
        ]);
    }

    public function chooseSubmit(): void
    {
        $pending = $_SESSION['_choose'] ?? null;
        unset($_SESSION['_choose']);
        if (!$pending) {
            redirect('/accounts');
        }
        $accounts = json_decode((string) Crypt::decrypt($pending['accounts']), true) ?: [];
        $picked = array_map('strval', (array) input('pick', []));
        $accounts = array_values(array_filter($accounts, fn($a) => in_array((string) $a['provider_id'], $picked, true)));
        if (!$accounts) {
            flash('error', 'Nothing was selected.');
            redirect('/accounts');
        }
        $this->store($pending['provider'], $accounts);
    }

    public function delete(string $id): void
    {
        $a = Db::one('SELECT * FROM accounts WHERE id = ? AND workspace_id = ?', [(int) $id, Workspace::id()]);
        if (!$a) {
            abort(404);
        }
        Db::query('DELETE FROM accounts WHERE id = ?', [$a['id']]);
        Webhooks::dispatch(Workspace::id(), 'account.disconnected', ['account' => self::toArray($a)]);
        flash('success', 'Removed ' . $a['name'] . '. Its scheduled posts will skip it.');
        redirect('/accounts');
    }

    private function startOAuth(string $provider, array $form): never
    {
        $state = bin2hex(random_bytes(16));
        try {
            $p = Registry::make($provider);
            $url = $p->authUrl(abs_url('accounts/callback/' . $provider), $state, $form);
        } catch (\Throwable $e) {
            flash('error', $this->message($e));
            redirect('/accounts');
        }
        $_SESSION['_oauth'] = ['provider' => $provider, 'state' => $state, 'form' => $form, 'stash' => $p->stash];
        redirect($url);
    }

    private function saveAccounts(string $provider, array $accounts): never
    {
        if (!$accounts) {
            flash('error', 'No accounts were found to connect.');
            redirect('/accounts');
        }
        if (count($accounts) > 1) {
            $_SESSION['_choose'] = ['provider' => $provider, 'accounts' => Crypt::encrypt(json_encode($accounts))];
            redirect('/accounts/choose');
        }
        $this->store($provider, $accounts);
    }

    private function store(string $provider, array $accounts): never
    {
        $wsId = Workspace::id();
        foreach ($accounts as $a) {
            $row = [
                'name' => mb_substr((string) ($a['name'] ?? ''), 0, 200),
                'username' => mb_substr((string) ($a['username'] ?? ''), 0, 200),
                'avatar' => $a['avatar'] ?? null,
                'token' => Crypt::encrypt($a['token'] ?? []),
                'data' => json_encode($a['data'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES),
                'authorized' => 1,
                'updated_at' => now(),
            ];
            $existing = Db::one('SELECT id FROM accounts WHERE workspace_id = ? AND provider = ? AND provider_id = ?', [$wsId, $provider, (string) $a['provider_id']]);
            if ($existing) {
                Db::update('accounts', $row, 'id = ?', [$existing['id']]);
                $id = (int) $existing['id'];
            } else {
                $id = Db::insert('accounts', $row + [
                    'workspace_id' => $wsId,
                    'provider' => $provider,
                    'provider_id' => (string) $a['provider_id'],
                    'created_at' => now(),
                ]);
            }
            Webhooks::dispatch($wsId, 'account.connected', ['account' => self::toArray(Db::one('SELECT * FROM accounts WHERE id = ?', [$id]))]);
        }
        $n = count($accounts);
        flash('success', $n === 1 ? 'Connected ' . $accounts[0]['name'] . '.' : "Connected $n accounts.");
        redirect('/accounts');
    }

    /** @return class-string<\App\Providers\Provider> */
    private function readyProvider(string $provider): string
    {
        $class = Registry::class($provider);
        if (!$class || !$class::implemented()) {
            abort(404);
        }
        if (!Registry::ready($provider)) {
            flash('error', $class::name() . ' needs API keys first.' . (Auth::isAdmin() ? '' : ' Ask your MixCreate admin to add them.'));
            redirect(Auth::isAdmin() ? '/admin/services#' . $provider : '/accounts');
        }
        return $class;
    }

    private function message(\Throwable $e): string
    {
        return $e instanceof ProviderException || $e instanceof \RuntimeException
            ? $e->getMessage()
            : 'Something went wrong while connecting.';
    }

    public static function toArray(array $a): array
    {
        return [
            'id' => (int) $a['id'],
            'provider' => $a['provider'],
            'name' => $a['name'],
            'username' => $a['username'],
            'avatar' => $a['avatar'],
            'authorized' => (bool) $a['authorized'],
        ];
    }
}
