<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Calendar limits
    |--------------------------------------------------------------------------
    |
    | Guardrails for the calendar aggregation/recurrence expansion. Recurring
    | rules such as FREQ=SECONDLY over a wide range can be extremely expensive,
    | so both the requested range and the number of generated occurrences are
    | capped.
    |
    */

    'max_range_days' => (int) env('CALENDAR_MAX_RANGE_DAYS', 366),
    'max_occurrences_per_event' => (int) env('CALENDAR_MAX_OCCURRENCES', 1000),
    'max_items' => (int) env('CALENDAR_MAX_ITEMS', 5000),

    /*
    |--------------------------------------------------------------------------
    | External ICS feeds
    |--------------------------------------------------------------------------
    |
    | The server fetches user-provided URLs, so the fetcher is hardened against
    | SSRF (private/reserved IPs, non-standard ports, unlimited redirects and
    | oversized responses).
    |
    */

    'feed' => [
        'timeout' => (int) env('CALENDAR_FEED_TIMEOUT', 15),
        'connect_timeout' => (int) env('CALENDAR_FEED_CONNECT_TIMEOUT', 5),
        'max_bytes' => (int) env('CALENDAR_FEED_MAX_BYTES', 5 * 1024 * 1024),
        'max_redirects' => (int) env('CALENDAR_FEED_MAX_REDIRECTS', 3),
        'allowed_ports' => [80, 443],
        'min_interval_minutes' => (int) env('CALENDAR_FEED_MIN_INTERVAL', 15),
    ],

    'ics' => [
        'max_bytes' => (int) env('CALENDAR_ICS_MAX_BYTES', 5 * 1024 * 1024),
        'max_events' => (int) env('CALENDAR_ICS_MAX_EVENTS', 500),
    ],
];
