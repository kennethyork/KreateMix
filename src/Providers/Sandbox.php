<?php
declare(strict_types=1);

namespace App\Providers;

/** Fake platform for testing the scheduler without real APIs. Only available when debug is on. */
final class Sandbox extends Provider
{
    public static function key(): string { return 'sandbox'; }
    public static function name(): string { return 'Sandbox'; }
    public static function color(): string { return '#0d9488'; }
    public static function textLimit(): int { return 500; }
    public static function implemented(): bool { return true; }

    public static function usesOAuth(): bool { return false; }

    public static function connectFields(): array
    {
        return [
            'name' => ['label' => 'Account name', 'type' => 'text', 'placeholder' => 'Test account'],
            'fail' => ['label' => 'Fail every publish (to test errors)', 'type' => 'checkbox'],
        ];
    }

    public function connect(array $input, string $redirectUri): array
    {
        $name = trim((string) ($input['name'] ?? '')) ?: 'Test account';
        return [[
            'provider_id' => 'sandbox-' . bin2hex(random_bytes(4)),
            'name' => $name,
            'username' => strtolower(preg_replace('/\W+/', '', $name)) ?: 'test',
            'avatar' => null,
            'token' => ['fake' => true],
            'data' => ['fail' => !empty($input['fail'])],
        ]];
    }

    public function publish(string $text, array $media): array
    {
        if (!empty($this->accountData()['fail'])) {
            throw new ProviderException('Sandbox account is set to fail.');
        }
        $id = bin2hex(random_bytes(6));
        file_put_contents(
            BASE_PATH . '/storage/logs/sandbox.log',
            sprintf("[%s] @%s %s | media: %d\n", now(), $this->account['username'] ?? '', str_replace("\n", ' ', $text), count($media)),
            FILE_APPEND
        );
        return ['id' => $id, 'url' => null];
    }

    public function accountMetrics(): array
    {
        return ['followers' => 100 + crc32((string) ($this->account['id'] ?? 0) . date('Y-m-d')) % 50];
    }

    public function postMetrics(string $providerPostId): array
    {
        $n = crc32($providerPostId);
        return ['likes' => $n % 40, 'replies' => $n % 7, 'shares' => $n % 11];
    }
}
