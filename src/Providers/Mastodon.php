<?php
declare(strict_types=1);

namespace App\Providers;

final class Mastodon extends Provider
{
    public static function key(): string { return 'mastodon'; }
    public static function name(): string { return 'Mastodon'; }
    public static function color(): string { return '#6364ff'; }
    public static function textLimit(): int { return 500; }

    public static function mediaRules(): array
    {
        return ["max_media" => 4, "video" => true, "requires_media" => false, "requires_video" => false];
    }

    public static function credentialFields(): array
    {
        return [];
    }

    public static function connectFields(): array
    {
        return ["server" => ["label" => "Server", "type" => "text", "placeholder" => "mastodon.social"]];
    }

    public static function setupHint(): string
    {
        return 'No keys needed. MixCreate registers itself on each Mastodon server when someone connects an account.';
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
