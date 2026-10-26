<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Config;
use App\Cron;

/** Web cron for hosts without a real crontab: GET /cron?key=CRON_KEY every minute. */
final class CronController
{
    public function run(): void
    {
        $key = (string) Config::get('cron_key', '');
        if ($key === '' || !hash_equals($key, (string) input('key', ''))) {
            abort(403, 'Invalid cron key.');
        }
        ignore_user_abort(true);
        json_response(Cron::tick());
    }
}
