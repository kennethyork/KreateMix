<?php
declare(strict_types=1);

namespace App\Providers;

final class GoogleBusiness extends Provider
{
    public static function key(): string { return 'google_business'; }
    public static function name(): string { return 'Google Business Profile'; }
    public static function color(): string { return '#4285f4'; }
    public static function textLimit(): int { return 1500; }

    public static function mediaRules(): array
    {
        return ["max_media" => 1, "video" => false, "requires_media" => false, "requires_video" => false];
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
        return 'In Google Cloud Console enable the Business Profile APIs (access must be requested from Google), create an OAuth web client, and add the callback URL below. Each location becomes its own account.';
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
