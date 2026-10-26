<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

spl_autoload_register(function (string $class): void {
    if (str_starts_with($class, 'App\\')) {
        $file = BASE_PATH . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});

require BASE_PATH . '/src/helpers.php';

date_default_timezone_set('UTC');
App\Config::load();

if (App\Config::get('debug')) {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
}

ini_set('log_errors', '1');
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');
