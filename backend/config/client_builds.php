<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Preset client builds (admin-triggered)
    |--------------------------------------------------------------------------
    |
    | The admin "Сборки клиентов" page dispatches a GitHub Actions workflow that
    | builds branded APK/MSI artifacts. Values here are fallbacks; the admin UI
    | (settings table) overrides them at runtime. The token is stored encrypted.
    |
    */

    'repo' => (string) env('CLIENT_BUILD_REPO', ''),
    'workflow' => (string) env('CLIENT_BUILD_WORKFLOW', 'build-clients.yml'),
    'ref' => (string) env('CLIENT_BUILD_REF', 'main'),
    'token' => (string) env('CLIENT_BUILD_TOKEN', ''),
];
