<?php
declare(strict_types=1);

namespace App\Providers;

final class Facebook extends Provider
{
    public static function key(): string { return 'facebook'; }
    public static function name(): string { return 'Facebook Pages'; }
    public static function color(): string { return '#1877f2'; }
    public static function textLimit(): int { return 63206; }

    public static function mediaRules(): array
    {
        return ["max_media" => 10, "video" => true, "requires_media" => false, "requires_video" => false];
    }

    public static function credentialFields(): array
    {
        return ["app_id" => "App ID", "app_secret" => "App Secret"];
    }

    public static function connectFields(): array
    {
        return [];
    }

    public static function setupHint(): string
    {
        return 'Create a Business app at developers.facebook.com, add Facebook Login, and add the callback URL below as a valid OAuth redirect URI. Publishing needs pages_manage_posts and pages_read_engagement.';
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
