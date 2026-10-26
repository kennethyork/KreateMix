<?php
declare(strict_types=1);

// Run every minute:  * * * * * php /path/to/mixcreate/cron.php >/dev/null 2>&1
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/src/bootstrap.php';

App\Hooks::loadModules();
$report = App\Cron::tick();
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL;
