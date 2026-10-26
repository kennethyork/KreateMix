<?php
declare(strict_types=1);

namespace App;

/**
 * Loads config.php from the project root. On first run it creates one from
 * config.example.php with freshly generated secrets, so a fresh upload works
 * without manual setup.
 */
final class Config
{
    private static array $values = [];

    public static function load(): void
    {
        $file = BASE_PATH . '/config.php';

        foreach (['storage', 'storage/logs', 'public/uploads'] as $dir) {
            if (!is_dir(BASE_PATH . '/' . $dir)) {
                @mkdir(BASE_PATH . '/' . $dir, 0775, true);
            }
        }

        if (!is_file($file)) {
            $example = file_get_contents(BASE_PATH . '/config.example.php');
            $example = str_replace(
                ["'app_key' => ''", "'cron_key' => ''"],
                ["'app_key' => '" . bin2hex(random_bytes(32)) . "'", "'cron_key' => '" . bin2hex(random_bytes(16)) . "'"],
                $example
            );
            if (@file_put_contents($file, $example) === false) {
                http_response_code(500);
                exit('MixCreate could not create config.php. Make the project folder writable or copy config.example.php to config.php and fill in app_key.');
            }
            @chmod($file, 0640);
        }

        self::$values = require $file;

        if (empty(self::$values['app_key'])) {
            http_response_code(500);
            exit('config.php is missing app_key. Set it to a random 64-character hex string.');
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $default;
    }
}
