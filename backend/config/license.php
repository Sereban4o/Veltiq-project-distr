<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Licensing gate
    |--------------------------------------------------------------------------
    |
    | The compiled core service owns license verification (see docs/12-licensing.md).
    | The application only *verifies* a short-lived ticket signed by the core, so
    | there is no single `if` to patch out: without a valid signature the product
    | degrades.
    |
    | `enabled`  — ask the core for a ticket and expose the state to the UI.
    | `enforce`  — actually block or make read-only. Default is off, so a rollout
    |              never surprises an existing installation (grandfathered).
    |
    */

    // Licensing is always enabled and enforced. Removing the environment
    // toggles prevents an operator from bypassing it by editing .env. Feature
    // tests may still override these values at runtime via config().
    'enabled' => true,

    'enforce' => true,

    // Public key of the *core* (not the vendor key). Pinned from the core on the
    // first successful call and cached in `Setting`.
    'core_public_key' => (string) env('LICENSE_CORE_PUBLIC_KEY', ''),

    // Vendor public key — used only to validate the license file before handing
    // it to the core (defence in depth, the core verifies it too).
    'public_key' => (string) env('LICENSE_PUBLIC_KEY', ''),

    // Veltiq LK machine API base (e.g. https://lk.veltiq.ru/api) for online
    // activation by account credentials. The product posts to
    // `{lk_api_url}/v1/account/activate` with the project token below.
    'lk_api_url' => rtrim((string) env('LICENSE_SERVER_URL', ''), '/'),
    'project_token' => (string) env('LICENSE_PROJECT_TOKEN', ''),
    'api_token_header' => (string) env('LICENSE_API_TOKEN_HEADER', 'X-License-Token'),

    // How long a verified ticket/state is trusted. Short on purpose: the core is
    // internal and cheap, and a long window would hide an activation until the
    // cache expires. The ticket itself lives 12h in the core.
    'ticket_cache_seconds' => (int) env('LICENSE_TICKET_TTL', 60),

    // Paths that stay reachable when no license is present: the admin panel must
    // always be able to activate one, and login/branding must render the notice.
    'whitelist' => [
        'admin',
        'admin/*',
        'api/v1/auth',
        'api/v1/auth/*',
        'api/v1/branding',
        'api/v1/license',
        'api/v1/license/*',
        'api/v1/extensions',
        'api/v1/install',
        'api/v1/admin',
        'api/v1/admin/*',
        'up',
    ],

    'messages' => [
        'blocked' => 'Лицензия отсутствует или отозвана. Обратитесь к администратору.',
        'read_only' => 'Срок лицензии истёк: система работает в режиме чтения.',
    ],
];
