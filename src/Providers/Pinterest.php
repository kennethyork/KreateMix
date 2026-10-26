<?php
declare(strict_types=1);

namespace App\Providers;

final class Pinterest extends Provider
{
    public static function key(): string { return 'pinterest'; }
    public static function name(): string { return 'Pinterest'; }
    public static function color(): string { return '#e60023'; }
    public static function textLimit(): int { return 500; }

    public static function mediaRules(): array
    {
        return ["max_media" => 1, "video" => false, "requires_media" => true, "requires_video" => false];
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
        return 'Create an app at developers.pinterest.com with pins:write and boards:read scopes and add the callback URL below. Each board you pick becomes its own account. The first line of the post becomes the pin title.';
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
