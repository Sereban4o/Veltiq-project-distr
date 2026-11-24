<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\Middleware\Authenticate as Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class Authenticate extends Middleware
{
    protected function redirectTo(Request $request): ?string
    {
        if ($request->expectsJson()) {
            return null;
        }

        return $request->is('admin', 'admin/*')
            ? route('admin.login.form')
            : route('login');
    }

    /**
     * An admin session that silently disappears is hard to diagnose from the
     * outside: record what the request actually carried before redirecting.
     *
     * @param  array<int, string|null>  $guards
     */
    protected function unauthenticated($request, array $guards)
    {
        if ($request->is('admin', 'admin/*')) {
            Log::warning('Admin authentication lost', [
                'path' => $request->path(),
                'method' => $request->method(),
                'cookies' => array_keys($request->cookies->all()),
                'session' => $request->hasSession() ? $request->session()->getId() : null,
                'secure' => $request->isSecure(),
            ]);
        }

        parent::unauthenticated($request, $guards);
    }

    /**
     * Authenticate the request and additionally reject users whose account is
     * not active (suspended/pending). This covers existing tokens: a blocked
     * user cannot use the API, WebSocket auth or Blade admin.
     */
    public function handle($request, Closure $next, ...$guards)
    {
        $this->authenticate($request, $guards);

        $user = $request->user();

        if ($user && $user->status !== 'active') {
            if ($request->expectsJson() || $request->is('api/*') || $request->is('broadcasting/*')) {
                return response()->json(['message' => 'Account is not active'], 403);
            }

            abort(403, 'Account is not active');
        }

        return $next($request);
    }

    /**
     * Guards memoise the resolved user. In long-lived processes (tests, queue
     * workers, Octane) that leaks the previous request's identity into the
     * next one. Once the response has been sent there is nothing left to
     * authenticate for this request, so drop the cached guards. Doing it here
     * (after the response) keeps identities that were set up between requests
     * (e.g. `actingAs` in tests) intact for the request that follows.
     */
    public function terminate(Request $request, $response): void
    {
        Auth::forgetGuards();
    }
}
