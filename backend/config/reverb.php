<?php

/*
|--------------------------------------------------------------------------
| Safe env helpers
|--------------------------------------------------------------------------
|
| Empty env values (`REVERB_APP_PING_INTERVAL=`) are returned by env() as an
| empty string, not as null, and Laravel Reverb's Application constructor has
| strict `int` parameter types. Passing "" there throws a TypeError while
| upgrading a WebSocket connection, which surfaces to the browser as
| "Error during WebSocket handshake: Unexpected response code: 500".
|
| These helpers coerce values to the expected types and fall back to the
| default when the variable is missing or empty.
|
*/

$intEnv = static function (string $key, int $default): int {
    $value = env($key);

    return ($value === null || $value === '') ? $default : (int) $value;
};

$boolEnv = static function (string $key, bool $default): bool {
    $value = env($key);

    return ($value === null || $value === '') ? $default : (bool) filter_var($value, FILTER_VALIDATE_BOOL);
};

$nullableIntEnv = static function (string $key): ?int {
    $value = env($key);

    return ($value === null || $value === '') ? null : (int) $value;
};

return [

    /*
    |--------------------------------------------------------------------------
    | Default Reverb Server
    |--------------------------------------------------------------------------
    |
    | This option controls the default server used by Reverb to handle
    | incoming messages as well as broadcasting message to all your
    | connected clients. At this time only "reverb" is supported.
    |
    */

    'default' => env('REVERB_SERVER', 'reverb'),

    /*
    |--------------------------------------------------------------------------
    | Reverb Servers
    |--------------------------------------------------------------------------
    |
    | Here you may define details for each of the supported Reverb servers.
    | Each server has its own configuration options that are defined in
    | the array below. You should ensure all the options are present.
    |
    */

    'servers' => [

        'reverb' => [
            'host' => env('REVERB_SERVER_HOST', '0.0.0.0'),
            'port' => $intEnv('REVERB_SERVER_PORT', 8080),
            'path' => env('REVERB_SERVER_PATH', ''),
            'hostname' => env('REVERB_HOST'),
            'options' => [
                'tls' => [],
            ],
            'max_request_size' => $intEnv('REVERB_MAX_REQUEST_SIZE', 10_000),
            'scaling' => [
                'enabled' => $boolEnv('REVERB_SCALING_ENABLED', false),
                'channel' => env('REVERB_SCALING_CHANNEL', 'reverb'),
                'server' => [
                    'url' => env('REDIS_URL'),
                    'host' => env('REDIS_HOST', '127.0.0.1'),
                    'port' => env('REDIS_PORT', '6379'),
                    'username' => env('REDIS_USERNAME'),
                    'password' => env('REDIS_PASSWORD'),
                    'database' => env('REDIS_DB', '0'),
                    'timeout' => env('REDIS_TIMEOUT', 60),
                ],
            ],
            'pulse_ingest_interval' => $intEnv('REVERB_PULSE_INGEST_INTERVAL', 15),
            'telescope_ingest_interval' => $intEnv('REVERB_TELESCOPE_INGEST_INTERVAL', 15),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Reverb Applications
    |--------------------------------------------------------------------------
    |
    | Here you may define how Reverb applications are managed. If you choose
    | to use the "config" provider, you may define an array of apps which
    | your server will support, including their connection credentials.
    |
    */

    'apps' => [

        'provider' => 'config',

        'apps' => [
            [
                'key' => (string) (env('REVERB_APP_KEY') ?: 'chat-key'),
                'secret' => (string) (env('REVERB_APP_SECRET') ?: 'chat-secret'),
                'app_id' => (string) (env('REVERB_APP_ID') ?: 'chat-app'),
                'options' => [
                    'host' => env('REVERB_HOST'),
                    'port' => env('REVERB_PORT', 443),
                    'scheme' => env('REVERB_SCHEME') ?: 'https',
                    'useTLS' => (env('REVERB_SCHEME') ?: 'https') === 'https',
                ],
                'allowed_origins' => ['*'],
                'ping_interval' => $intEnv('REVERB_APP_PING_INTERVAL', 60),
                'activity_timeout' => $intEnv('REVERB_APP_ACTIVITY_TIMEOUT', 30),
                'max_connections' => $nullableIntEnv('REVERB_APP_MAX_CONNECTIONS'),
                'max_message_size' => $intEnv('REVERB_APP_MAX_MESSAGE_SIZE', 10_000),
                'accept_client_events_from' => (string) (env('REVERB_APP_ACCEPT_CLIENT_EVENTS_FROM') ?: 'members'),
                'rate_limiting' => [
                    'enabled' => $boolEnv('REVERB_APP_RATE_LIMITING_ENABLED', false),
                    'max_attempts' => $intEnv('REVERB_APP_RATE_LIMIT_MAX_ATTEMPTS', 60),
                    'decay_seconds' => $intEnv('REVERB_APP_RATE_LIMIT_DECAY_SECONDS', 60),
                    'terminate_on_limit' => $boolEnv('REVERB_APP_RATE_LIMIT_TERMINATE', false),
                ],
            ],
        ],

    ],

];
