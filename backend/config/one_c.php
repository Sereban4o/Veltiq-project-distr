<?php

return [
    /*
    |--------------------------------------------------------------------------
    | 1С integration
    |--------------------------------------------------------------------------
    |
    | Inbound requests from 1С are authenticated with a bearer token and an
    | HMAC signature (hex sha256 of "{timestamp}.{rawBody}") to prevent
    | tampering and replay.
    |
    */
    'enabled' => env('ONE_C_ENABLED', false),

    // Base URL of the 1С HTTP service used for outgoing calls (Laravel -> 1С).
    'base_url' => env('ONE_C_BASE_URL'),

    // Token for inbound requests (1С -> Laravel).
    'token' => env('ONE_C_TOKEN'),

    // Shared secret for HMAC signatures.
    'hmac_secret' => env('ONE_C_HMAC_SECRET'),

    // Optional IP allowlist for inbound 1С requests (comma separated in env).
    // Empty = no IP restriction (rely on token/HMAC).
    'allowed_ips' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('ONE_C_ALLOWED_IPS', '')),
    ))),

    // Allowed clock skew for the X-Timestamp header, seconds.
    'tolerance' => (int) env('ONE_C_TIMESTAMP_TOLERANCE', 300),

    'timeout' => (int) env('ONE_C_TIMEOUT', 30),

    'verify' => (bool) env('ONE_C_VERIFY', true),

    /*
    |--------------------------------------------------------------------------
    | Counterparties SOAP service (WebService.CounterpartiesExchange)
    |--------------------------------------------------------------------------
    |
    | Outgoing SOAP calls to the 1С «Сервис контрагентов». The service returns
    | a JSON document wrapped into the SOAP `return` element (see docs/1c.md).
    | Uses its own URL + HTTP Basic credentials, independent of the inbound
    | token/HMAC contour above.
    |
    */

    'counterparties' => [
        // Endpoint: http://<server>/<base>/ws/CounterpartiesExchange.1cws
        'url' => env('ONE_C_COUNTERPARTIES_URL'),
        // HTTP Basic credentials of the 1С infobase user.
        'login' => env('ONE_C_COUNTERPARTIES_LOGIN'),
        'password' => env('ONE_C_COUNTERPARTIES_PASSWORD'),
        'enabled' => (bool) env('ONE_C_COUNTERPARTIES_ENABLED', true),
        // Namespace declared by WebService.CounterpartiesExchange.
        'namespace' => 'http://gertorg.ru/exchange/counterparties',
        // Rows per SOAP call for the bulk import; 1С clamps to 1000.
        'portion' => (int) env('ONE_C_COUNTERPARTIES_PORTION', 500),
        // Hard cap for the sync loop (max rows = portion * max_pages).
        'max_pages' => (int) env('ONE_C_COUNTERPARTIES_MAX_PAGES', 100),
        'timeout' => (int) env('ONE_C_COUNTERPARTIES_TIMEOUT', env('ONE_C_TIMEOUT', 60)),
        'verify' => (bool) env('ONE_C_COUNTERPARTIES_VERIFY', env('ONE_C_VERIFY', true)),
    ],
];
