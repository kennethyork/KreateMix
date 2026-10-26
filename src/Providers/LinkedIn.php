<?php
declare(strict_types=1);

namespace App\Providers;

final class LinkedIn extends Provider
{
    public static function key(): string { return 'linkedin'; }
    public static function name(): string { return 'LinkedIn'; }
    public static function color(): string { return '#0a66c2'; }
    public static function textLimit(): int { return 3000; }

    public static function mediaRules(): array
    {
        return ["max_media" => 9, "video" => true, "requires_media" => false, "requires_video" => false];
    }

    public static function credentialFields(): array
    {
        return ["client_id" => "Client ID", "client_secret" => "Client Secret"];
    }

    public static function connectFields(): array
    {
        return [];
    }

    public static function setupHint(): string
    {
        return 'Create an app at linkedin.com/developers, add the Share on LinkedIn and Sign In with LinkedIn using OpenID Connect products, and add the callback URL below.';
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
