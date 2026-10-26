<?php
declare(strict_types=1);

namespace App\Providers;

final class Threads extends Provider
{
    public static function key(): string { return 'threads'; }
    public static function name(): string { return 'Threads'; }
    public static function color(): string { return '#000000'; }
    public static function textLimit(): int { return 500; }

    public static function mediaRules(): array
    {
        return ["max_media" => 10, "video" => true, "requires_media" => false, "requires_video" => false];
    }

    public static function credentialFields(): array
    {
        return ["app_id" => "Threads App ID", "app_secret" => "Threads App Secret"];
    }

    public static function connectFields(): array
    {
        return [];
    }

    public static function setupHint(): string
    {
        return 'In your Meta app add the Threads API use case with threads_basic and threads_content_publish, and add the callback URL below as a redirect URI.';
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
