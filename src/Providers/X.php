<?php
declare(strict_types=1);

namespace App\Providers;

final class X extends Provider
{
    public static function key(): string { return 'x'; }
    public static function name(): string { return 'X'; }
    public static function color(): string { return '#000000'; }
    public static function textLimit(): int { return 280; }

    public static function mediaRules(): array
    {
        return ["max_media" => 4, "video" => true, "requires_media" => false, "requires_video" => false];
    }

    public static function credentialFields(): array
    {
        return ["client_id" => "OAuth 2.0 Client ID", "client_secret" => "OAuth 2.0 Client Secret"];
    }

    public static function connectFields(): array
    {
        return [];
    }

    public static function setupHint(): string
    {
        return 'Create a project and app at developer.x.com, enable OAuth 2.0 (confidential client) with read and write access, and add the callback URL below. Posting requires an API plan that includes write access.';
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
