<?php

$currentVersion = (string) env('UPDATE_SERVICE_CURRENT_VERSION', '');

if ($currentVersion === '') {
    $currentVersion = (string) env('APP_VERSION', '1.0.0');
}

return [
    /*
    |--------------------------------------------------------------------------
    | Central update service (update.veltiq.ru)
    |--------------------------------------------------------------------------
    |
    | `mode` = "client" enables outgoing version checks; any other value keeps
    | the admin page in the "not configured" state and performs no network
    | calls.
    |
    */

    'mode' => (string) env('UPDATE_SERVICE_MODE', 'disabled'),

    // Base URL of the service WITHOUT the /api suffix — the client appends
    // /system-updates/api/latest.
    'url' => rtrim((string) env('UPDATE_SERVICE_URL', ''), '/'),

    // Per-installation token issued in the update-service admin.
    'token' => (string) env('UPDATE_SERVICE_TOKEN', ''),

    'channel' => (string) env('UPDATE_SERVICE_CHANNEL', 'stable'),

    // Version of THIS installation, compared with the latest release.
    'current_version' => $currentVersion,

    'cache_ttl' => (int) env('UPDATE_SERVICE_CACHE_TTL', 600),

    'timeout' => (int) env('UPDATE_SERVICE_TIMEOUT', 10),

    // Deploy command run by SystemUpdateApplier (cwd = base_path()).
    'apply_command' => (string) env('UPDATE_SERVICE_APPLY_COMMAND', ''),

    'allow_apply' => (bool) env('UPDATE_SERVICE_ALLOW_APPLY', false),
];
