<?php

namespace App\Http\Middleware;

use App\Services\Core\EntitlementClient;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Blocks the application API when there is no usable license.
 *
 * Rules (docs/12-licensing.md):
 *  - Rescue paths always pass: the admin panel (license activation), login,
 *    branding and the license endpoints themselves.
 *  - `blocked`  → 403 `license_required` for everything else.
 *  - `read_only` → writes are refused with 403 `license_read_only`, reads pass.
 *
 * The gate only acts when `LICENSE_ENFORCE` is on, so enabling licensing does not
 * surprise an existing installation.
 */
class EnsureLicense
{
    public function __construct(private readonly EntitlementClient $entitlement)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        if (! (bool) config('license.enforce', false)) {
            return $next($request);
        }

        if ($this->isWhitelisted($request)) {
            return $next($request);
        }

        $state = $this->entitlement->state();

        if ($state['access'] === 'blocked') {
            return new JsonResponse([
                'code' => 'license_required',
                'message' => (string) config('license.messages.blocked'),
                'state' => $state['state'],
            ], Response::HTTP_FORBIDDEN);
        }

        if ($state['access'] === 'read_only' && ! $request->isMethodSafe()) {
            return new JsonResponse([
                'code' => 'license_read_only',
                'message' => (string) config('license.messages.read_only'),
                'state' => $state['state'],
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }

    private function isWhitelisted(Request $request): bool
    {
        $path = ltrim($request->path(), '/');
        $patterns = (array) config('license.whitelist', []);

        foreach ($patterns as $pattern) {
            if (Str::is($pattern, $path)) {
                return true;
            }
        }

        return false;
    }
}
