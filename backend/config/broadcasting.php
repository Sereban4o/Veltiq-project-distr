<?php

return [

    'default' => env('BROADCAST_DRIVER', 'reverb'),

    'connections' => [

        'reverb' => [
            'driver' => 'reverb',
            // Fall back to the defaults when the env vars are missing/empty:
            // a null key would make Pusher throw a TypeError while the
            // application boots and 500 every request (including login).
            'key' => env('REVERB_APP_KEY') ?: 'chat-key',
            'secret' => env('REVERB_APP_SECRET') ?: 'chat-secret',
            'app_id' => env('REVERB_APP_ID') ?: 'chat-app',
            'options' => [
                'host' => env('REVERB_HOST') ?: '127.0.0.1',
                'port' => (int) (env('REVERB_PORT') ?: 8080),
                'scheme' => env('REVERB_SCHEME') ?: 'http',
                'useTLS' => (env('REVERB_SCHEME') ?: 'http') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'pusher' => [
            'driver' => 'pusher',
            'key' => env('PUSHER_APP_KEY'),
            'secret' => env('PUSHER_APP_SECRET'),
            'app_id' => env('PUSHER_APP_ID'),
            'options' => [
                'cluster' => env('PUSHER_APP_CLUSTER'),
                'host' => env('PUSHER_HOST') ?: 'api-' . env('PUSHER_APP_CLUSTER', 'mt1') . '.pusher.com',
                'port' => env('PUSHER_PORT', 443),
                'scheme' => env('PUSHER_SCHEME', 'https'),
                'encrypted' => true,
                'useTLS' => env('PUSHER_SCHEME', 'https') === 'https',
            ],
            'client_options' => [
                // Guzzle client options: https://docs.guzzlephp.org/en/stable/request-options.html
            ],
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => 'default',
        ],

        'log' => [
            'driver' => 'log',
        ],

        'null' => [
            'driver' => 'null',
        ],

    ],

];
