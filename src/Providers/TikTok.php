<?php
declare(strict_types=1);

namespace App\Providers;

final class TikTok extends Provider
{
    public static function key(): string { return 'tiktok'; }
    public static function name(): string { return 'TikTok'; }
    public static function color(): string { return '#000000'; }
    public static function textLimit(): int { return 2200; }

    public static function mediaRules(): array
    {
        return ["max_media" => 35, "video" => true, "requires_media" => true, "requires_video" => false];
    }

    public static function credentialFields(): array
    {
        return ["client_key" => "Client Key", "client_secret" => "Client Secret"];
    }

    public static function connectFields(): array
    {
        return [];
    }

    public static function setupHint(): string
    {
        return 'Create an app at developers.tiktok.com with Login Kit and the Content Posting API (Direct Post), and add the callback URL below. Until TikTok audits the app, posts can only be private.';
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
