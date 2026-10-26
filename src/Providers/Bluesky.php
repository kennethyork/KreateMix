<?php
declare(strict_types=1);

namespace App\Providers;

final class Bluesky extends Provider
{
    public static function key(): string { return 'bluesky'; }
    public static function name(): string { return 'Bluesky'; }
    public static function color(): string { return '#1185fe'; }
    public static function textLimit(): int { return 300; }

    public static function mediaRules(): array
    {
        return ["max_media" => 4, "video" => true, "requires_media" => false, "requires_video" => false];
    }

    public static function credentialFields(): array
    {
        return [];
    }

    public static function usesOAuth(): bool { return false; }

    public static function connectFields(): array
    {
        return ["handle" => ["label" => "Handle", "type" => "text", "placeholder" => "you.bsky.social"], "app_password" => ["label" => "App password", "type" => "password", "placeholder" => "xxxx-xxxx-xxxx-xxxx"], "service" => ["label" => "PDS (leave blank for bsky.social)", "type" => "text", "placeholder" => "https://bsky.social"]];
    }

    public static function setupHint(): string
    {
        return 'No keys needed. Accounts connect with a handle and an app password from Bluesky Settings → Privacy and security → App passwords.';
    }

    public function connect(array $input, string $redirectUri): array
    {
        throw new ProviderException(static::name() . ' is not available yet.');
    }

    public function publish(string $text, array $media): array
    {
        throw new ProviderException(static::name() . ' is not available yet.');
    }
}
