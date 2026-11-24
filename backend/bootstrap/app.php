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
        // nginx terminates the client connection and forwards the real address;
        // without this every request looks like it comes from the proxy, so the
        // login/API rate limits and audit IPs are shared by all users (429).
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
            '::1',
        ]);

        $middleware->throttleAPI('300,1');

        // A guest who opens the admin panel must land on the admin login form,
        // not on the API root (`/` answers JSON) — the latter looked like the
        // panel "kicked" the operator out instead of asking to sign in again.
        $middleware->redirectGuestsTo(fn (\Illuminate\Http\Request $request) => $request->is('admin', 'admin/*')
            ? route('admin.login.form')
            : '/login');

        // Reports the frontend bundle id (X-Veltiq-Bundle) against the pinned
        // release; mismatches are audited, never blocking.
        $middleware->api(append: [\App\Http\Middleware\VerifyBundleIntegrity::class]);

        // Licensing gate (docs/12-licensing.md): blocks the API when there is no
        // usable license. Whitelisted paths (admin, auth, branding, license) and
        // the read-only mode are handled inside the middleware.
        $middleware->api(append: [\App\Http\Middleware\EnsureLicense::class]);

        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'admin.auth' => \App\Http\Middleware\SanitizeAdminAuth::class,
            'query.token' => \App\Http\Middleware\QueryTokenAuth::class,
            'debug' => \App\Http\Middleware\DebugMiddleware::class,
            'one.c' => \App\Http\Middleware\VerifyOneCRequest::class,
            'license' => \App\Http\Middleware\EnsureLicense::class,
            'entitlement' => \App\Http\Middleware\EnsureEntitlement::class,
            'extension.enabled' => \App\Http\Middleware\EnsureExtensionEnabled::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Avoid reporting the same exception twice (e.g. log-and-rethrow).
        $exceptions->dontReportDuplicates();
    })->create(App\Exceptions\Handler::class);
