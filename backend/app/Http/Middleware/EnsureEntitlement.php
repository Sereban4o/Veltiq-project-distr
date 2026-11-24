<?php

namespace App\Http\Middleware;

use App\Services\Core\EntitlementClient;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a premium route group behind a licensed feature or module.
 *
 * Usage in routes: `->middleware('entitlement:crm')`. Verification goes through
 * the signed ticket, so the check cannot be reduced to a single editable branch.
 * Enforcement only applies when `LICENSE_ENFORCE` is on.
 */
class EnsureEntitlement
{
    public function __construct(private readonly EntitlementClient $entitlement)
    {
    }

    public function handle(Request $request, Closure $next, string $feature): Response
    {
        if (! (bool) config('license.enforce', false)) {
            return $next($request);
        }

        if ($this->entitlement->allows($feature)) {
            return $next($request);
        }

        return new JsonResponse([
            'code' => 'entitlement_required',
            'feature' => $feature,
            'state' => $this->entitlement->state()['state'],
            'message' => 'Функция не входит в вашу лицензию.',
        ], Response::HTTP_FORBIDDEN);
    }
}
