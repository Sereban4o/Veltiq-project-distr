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

];
