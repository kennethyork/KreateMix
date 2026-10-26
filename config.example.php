<?php
// Copied to config.php on first run with app_key and cron_key generated.
// Never commit config.php.
return [
    'app_name' => 'MixCreate',

    // Public URL of the app without a trailing slash, e.g. https://social.example.com
    // Leave empty to detect it from the request. Needed for OAuth callbacks and cron-built links.
    'app_url' => '',

    // 64 hex chars. Encrypts stored API keys and access tokens. Changing it breaks connected accounts.
    'app_key' => '',

    // Secret for the web cron URL: /cron?key=...
    'cron_key' => '',

    'db_path' => __DIR__ . '/storage/database.sqlite',

    // Max upload size in megabytes (also bounded by php.ini upload_max_filesize).
    'max_upload_mb' => 200,

    // Shows errors and enables the Sandbox provider for testing publishing without real APIs.
    'debug' => false,
];
