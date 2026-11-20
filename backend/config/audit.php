<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Audit trail retention
    |--------------------------------------------------------------------------
    */

    'retention_days' => (int) env('AUDIT_RETENTION_DAYS', 365),

    'login_retention_days' => (int) env('LOGIN_LOG_RETENTION_DAYS', 180),

    'export_chunk' => (int) env('AUDIT_EXPORT_CHUNK', 1000),

];
