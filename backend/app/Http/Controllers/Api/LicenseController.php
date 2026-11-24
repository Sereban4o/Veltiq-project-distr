<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Core\EntitlementClient;
use Illuminate\Http\JsonResponse;

/**
 * Licensing state for the SPA.
 *
 * Reachable even without a license: the frontend needs it to render the
 * "license missing" screen instead of a generic error (docs/12-licensing.md).
 */
class LicenseController extends Controller
{
    public function __construct(private readonly EntitlementClient $entitlement)
    {
    }

    public function status(): JsonResponse
    {
        $state = $this->entitlement->state();

        return response()->json([
            'state' => $state['state'],
            'access' => $state['access'],
            'features' => $state['features'],
            'modules' => $state['modules'],
            'expires_at' => $state['expires_at'],
            'enforced' => (bool) config('license.enforce', false),
            'message' => $this->message($state['access']),
        ]);
    }

    private function message(string $access): ?string
    {
        return match ($access) {
            'blocked' => (string) config('license.messages.blocked'),
            'read_only' => (string) config('license.messages.read_only'),
            default => null,
        };
    }
}
