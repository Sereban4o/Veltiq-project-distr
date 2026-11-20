<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__ . '/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->throttleAPI('300,1');

        // Reports the frontend bundle id (X-Veltiq-Bundle) against the pinned
        // release; mismatches are audited, never blocking.
        $middleware->api(append: [\App\Http\Middleware\VerifyBundleIntegrity::class]);

        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'admin.auth' => \App\Http\Middleware\SanitizeAdminAuth::class,
            'query.token' => \App\Http\Middleware\QueryTokenAuth::class,
            'debug' => \App\Http\Middleware\DebugMiddleware::class,
            'one.c' => \App\Http\Middleware\VerifyOneCRequest::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Avoid reporting the same exception twice (e.g. log-and-rethrow).
        $exceptions->dontReportDuplicates();
    })->create(App\Exceptions\Handler::class);
