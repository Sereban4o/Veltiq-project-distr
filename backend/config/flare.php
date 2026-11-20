<?php

use Spatie\FlareClient\FlareMiddleware\AddGitInformation;
use Spatie\FlareClient\FlareMiddleware\CensorRequestBodyFields;
use Spatie\FlareClient\FlareMiddleware\CensorRequestHeaders;
use Spatie\FlareClient\FlareMiddleware\RemoveRequestIp;
use Spatie\LaravelIgnition\FlareMiddleware\AddContext;
use Spatie\LaravelIgnition\FlareMiddleware\AddDumps;
use Spatie\LaravelIgnition\FlareMiddleware\AddEnvironmentInformation;
use Spatie\LaravelIgnition\FlareMiddleware\AddExceptionHandledStatus;
use Spatie\LaravelIgnition\FlareMiddleware\AddExceptionInformation;
use Spatie\LaravelIgnition\FlareMiddleware\AddJobs;
use Spatie\LaravelIgnition\FlareMiddleware\AddLogs;
use Spatie\LaravelIgnition\FlareMiddleware\AddNotifierName;
use Spatie\LaravelIgnition\FlareMiddleware\AddQueries;

return [
    /*
    |
    |--------------------------------------------------------------------------
    | Flare API key
    |--------------------------------------------------------------------------
    |
    | Specify Flare's API key below to enable error reporting to the service.
    |
    | More info: https://flareapp.io/docs/general/projects
    |
    */

    'key' => env('FLARE_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware will modify the contents of the report sent to Flare.
    |
    */

    'flare_middleware' => [
        RemoveRequestIp::class,
        AddGitInformation::class,
        AddNotifierName::class,
        AddEnvironmentInformation::class,
        AddExceptionInformation::class,
        AddDumps::class,
        AddLogs::class => [
            'maximum_number_of_collected_logs' => 200,
        ],
        AddQueries::class => [
            'maximum_number_of_collected_queries' => 200,
            // Query bindings can contain personal data (mail, 1C payloads);
            // keep them out of third-party reports unless explicitly enabled.
            'report_query_bindings' => (bool) env('FLARE_REPORT_QUERY_BINDINGS', false),
        ],
        AddJobs::class => [
            'max_chained_job_reporting_depth' => 5,
        ],
        AddContext::class,
        AddExceptionHandledStatus::class,
        CensorRequestBodyFields::class => [
            'censor_fields' => [
                'password',
                'password_confirmation',
                'current_password',
                'token',
                'access_token',
                'refresh_token',
                'secret',
                'api_key',
                'ntfy_pass',
            ],
        ],
        CensorRequestHeaders::class => [
            'headers' => [
                'API-KEY',
                'Authorization',
                'Cookie',
                'Set-Cookie',
                'X-CSRF-TOKEN',
                'X-XSRF-TOKEN',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Reporting log statements
    |--------------------------------------------------------------------------
    |
    | If this setting is `false` log statements won't be sent as events to Flare,
    | no matter which error level you specified in the Flare log channel.
    |
    */

    'send_logs_as_events' => (bool) env('FLARE_SEND_LOGS_AS_EVENTS', true),
];
