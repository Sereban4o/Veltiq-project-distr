<?php

$url = (string) env('ONLYOFFICE_URL', '');
$secret = (string) env('ONLYOFFICE_JWT_SECRET', '');
$enabledEnv = env('ONLYOFFICE_ENABLED');

return [
    /*
    |--------------------------------------------------------------------------
    | ONLYOFFICE Document Server
    |--------------------------------------------------------------------------
    |
    | Enables full MS-Office-like editing of real .docx/.xlsx/.pptx files from
    | the file storage. Requires a running ONLYOFFICE Document Server instance
    | (see docker-compose.onlyoffice.yml).
    |
    */

    // Enabled explicitly, or implicitly once both the URL and JWT secret are set.
    'enabled' => $enabledEnv === null
        ? ($url !== '' && $secret !== '')
        : (bool) filter_var($enabledEnv, FILTER_VALIDATE_BOOL),

    // URL of the Document Server as reachable from the browser
    // (e.g. http://212.109.16.56:8082). The editor loads api.js from here.
    'url' => rtrim($url, '/'),

    // Base URL the Document Server uses to reach THIS backend for the file
    // download and the save callback (must be resolvable from the DS container,
    // e.g. http://nginx or http://app).
    'callback_base_url' => rtrim((string) env('ONLYOFFICE_CALLBACK_BASE_URL', 'http://nginx'), '/'),

    // JWT secret shared with the Document Server (JWT_ENABLED=true, JWT_SECRET).
    'jwt_secret' => $secret,

    // Header the Document Server uses for the JWT (default: Authorization).
    'jwt_header' => (string) env('ONLYOFFICE_JWT_HEADER', 'Authorization'),

    // How long the signed download/callback links stay valid (seconds).
    'token_ttl' => (int) env('ONLYOFFICE_TOKEN_TTL', 86400),

    // Maximum size the edited document may be written back (bytes).
    'max_write_size' => (int) env('ONLYOFFICE_MAX_WRITE_SIZE', 50 * 1024 * 1024),
];
