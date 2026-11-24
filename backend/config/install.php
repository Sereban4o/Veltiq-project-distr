<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Installer lock
    |--------------------------------------------------------------------------
    |
    | The web installer is available only while this file is absent. Once the
    | installation succeeds the lock is written and `/install` redirects to the
    | site (docs/15-installer.md). The path is configurable so tests never touch
    | the real install state.
    |
    */

    'lock_path' => (string) env('INSTALL_LOCK_PATH', storage_path('app/installed.lock')),

    /*
    |--------------------------------------------------------------------------
    | Force reinstall
    |--------------------------------------------------------------------------
    |
    | By default an existing database (schema + at least one user) closes the
    | installer even when the lock file is missing — deploying a fresh
    | distribution over a live installation must not open the wizard.
    | Set INSTALL_FORCE=true to deliberately reopen it anyway.
    */

    'force' => (bool) env('INSTALL_FORCE', false),

];
