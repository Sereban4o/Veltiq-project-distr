<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Compiled "protected core" service
    |--------------------------------------------------------------------------
    |
    | Algorithmically valuable logic that is shipped as a compiled binary (see
    | /core) instead of readable PHP. Enabled only when both the URL and the
    | shared token are configured; otherwise the in-PHP implementation is used.
    |
    | The service must stay on the internal Docker network — it is never
    | published to the host, and every request is authenticated by CORE_TOKEN.
    |
    */

    'url' => rtrim((string) env('CORE_URL', ''), '/'),

    'token' => (string) env('CORE_TOKEN', ''),

    // Short timeout: a slow core must not stall calendar requests, because the
    // PHP implementation is always available as a fallback.
    'timeout' => (int) env('CORE_TIMEOUT', 3),

    // Consecutive transport failures before calls are skipped for a while, and
    // how long that pause lasts (seconds). Prevents an unhealthy service from
    // adding a timeout to every request.
    'breaker_threshold' => (int) env('CORE_BREAKER_THRESHOLD', 5),
    'breaker_cooldown' => (int) env('CORE_BREAKER_COOLDOWN', 60),
];
