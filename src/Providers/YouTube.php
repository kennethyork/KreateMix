<?php
declare(strict_types=1);

namespace App\Providers;

final class YouTube extends Provider
{
    public static function key(): string { return 'youtube'; }
    public static function name(): string { return 'YouTube'; }
    public static function color(): string { return '#ff0000'; }
    public static function textLimit(): int { return 5000; }

    public static function mediaRules(): array
    {
        return ["max_media" => 1, "video" => true, "requires_media" => true, "requires_video" => true];
    }

    public static function credentialFields(): array
    {
        return ["client_id" => "Google OAuth Client ID", "client_secret" => "Google OAuth Client Secret"];
    }

    public static function connectFields(): array
    {
        return [];
    }

    public static function setupHint(): string
    {
        return 'In Google Cloud Console enable YouTube Data API v3, create an OAuth client (web application) and add the callback URL below. The first line of the post becomes the video title.';
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
