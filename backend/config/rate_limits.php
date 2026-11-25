<?php

return [
    /*
    |--------------------------------------------------------------------------
    | API rate limiting
    |--------------------------------------------------------------------------
    |
    | Requests per minute allowed for the `api` middleware group (keyed per
    | authenticated user, or per IP for guests). Raise it if the SPA / chat
    | polling trips HTTP 429. The value is applied by the named `api` limiter
    | in RouteServiceProvider.
    |
    */

    'api_per_minute' => (int) env('THROTTLE_API_PER_MINUTE', 600),
];
