<?php
declare(strict_types=1);

namespace App;

/**
 * Extension points for modules in /modules/<name>/module.php.
 *
 * Actions (Hooks::on / Hooks::fire):
 *   routes(Router $router)                 register extra routes
 *   post.saved(array $post)                after a post is created or updated
 *   post.published(array $post, array $results)
 *   post.failed(array $post, array $results)
 *   cron(array &$report)                   runs every cron tick
 *
 * Filters (Hooks::addFilter / Hooks::filter):
 *   providers(array $classes)              provider class names
 *   nav(array $items)                      sidebar items [['label','href','icon','role','match']]
 *   post.status(string $status, array $post, string $action)   e.g. turn 'scheduled' into 'pending_approval'
 *   composer.panels(array $html)           extra HTML blocks below the composer
 *   settings.tabs(array $tabs)             extra workspace settings tabs [['label','href']]
 */
final class Hooks
{
    private static array $actions = [];
    private static array $filters = [];

    public static function on(string $event, callable $callback, int $priority = 10): void
    {
        self::$actions[$event][$priority][] = $callback;
    }

    public static function fire(string $event, mixed ...$args): void
    {
        foreach (self::sorted(self::$actions[$event] ?? []) as $cb) {
            $cb(...$args);
        }
    }

    public static function addFilter(string $name, callable $callback, int $priority = 10): void
    {
        self::$filters[$name][$priority][] = $callback;
    }

    public static function filter(string $name, mixed $value, mixed ...$args): mixed
    {
        foreach (self::sorted(self::$filters[$name] ?? []) as $cb) {
            $value = $cb($value, ...$args);
        }
        return $value;
    }

    private static function sorted(array $byPriority): array
    {
        ksort($byPriority);
        return $byPriority ? array_merge(...array_values($byPriority)) : [];
    }

    /** Loads every modules/<name>/module.php, alphabetically. */
    public static function loadModules(): void
    {
        $files = glob(BASE_PATH . '/modules/*/module.php') ?: [];
        sort($files);
        foreach ($files as $file) {
            require_once $file;
        }
    }
}
