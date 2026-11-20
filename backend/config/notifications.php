<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notification digests
    |--------------------------------------------------------------------------
    */

    'digest' => [
        'enabled' => env('NOTIFICATIONS_DIGEST_ENABLED', true),
        'default_frequency' => env('NOTIFICATIONS_DIGEST_DEFAULT', 'off'),
        'daily_at' => env('NOTIFICATIONS_DIGEST_DAILY_AT', '08:00'),
        'weekly_on' => env('NOTIFICATIONS_DIGEST_WEEKLY_ON', 'monday'),
        'max_items' => (int) env('NOTIFICATIONS_DIGEST_MAX_ITEMS', 50),
    ],

    'retention' => [
        'notifications_days' => (int) env('NOTIFICATION_RETENTION_DAYS', 90),
    ],

];
