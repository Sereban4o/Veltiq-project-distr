<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Uploaded templates
    |--------------------------------------------------------------------------
    |
    | Where template archives are unpacked. Kept on the local disk (outside the
    | public root) because templates are user-supplied HTML/JS: the backend
    | serves them through a controller that verifies every path, never through
    | nginx statics of `public/`.
    |
    */

    'path' => (string) env('TEMPLATES_PATH', storage_path('app/templates')),

    // Public URL prefix of the same files (route: template.assets.serve).
    'assets_prefix' => '/template-assets',

    /*
     * PHP templates.
     *
     * A template may ship `.php` pages (WordPress-style themes). Those files are
     * executed by the backend, so a PHP template has the same trust level as a
     * third-party module: full access to the application. Only administrators can
     * upload templates; `.php` files are never served as static assets.
     *
     * Set TEMPLATES_ALLOW_PHP=false to reject PHP pages on upload.
     */
    'allow_php' => (bool) env('TEMPLATES_ALLOW_PHP', true),
];
