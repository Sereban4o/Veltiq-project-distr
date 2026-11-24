<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Extensions platform
    |--------------------------------------------------------------------------
    |
    | Vendor extensions live inside the image (`backend/extensions`). Third-party
    | extensions live in a writable volume. Installing untrusted code is only
    | allowed in developer mode — there is no sandbox for PHP extension code.
    |
    | Old `MODULES_*` environment names are still honoured for one release.
    |
    */

    // Writable root for third-party extensions (mount a volume here in production).
    'third_party_path' => env('EXTENSIONS_PATH', env('MODULES_PATH', storage_path('extensions'))),

    // Allows installing/plugging unsigned extensions. Off by default: a third-party
    // extension is arbitrary PHP with full access to data and files.
    'allow_unsigned' => (bool) env('EXTENSIONS_ALLOW_UNSIGNED', env('MODULES_ALLOW_UNSIGNED', false)),

    // Enforce the extension gate in automated tests. Off by default: the suite must
    // not depend on the operator's live extension state (parity tests talk to a
    // real core). Tests that verify the gate itself enable this explicitly.
    'enforce_in_tests' => (bool) env('EXTENSIONS_ENFORCE_IN_TESTS', env('MODULES_ENFORCE_IN_TESTS', false)),

    /*
     * Trusted signing keys of extension authors: `kid=base64key` pairs, comma
     * separated. The vendor key (license.public_key) is always trusted as `vendor`.
     * A manifest may carry `signature.kid` to pick one of these keys; a signed
     * manifest cannot be edited in place because the signature would stop matching
     * (docs/11-extensions.md).
     */
    'trusted_keys' => (string) env('EXTENSIONS_TRUSTED_KEYS', env('MODULES_TRUSTED_KEYS', '')),
];
