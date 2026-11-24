<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Core\CoreClient;
use App\Services\Core\EntitlementClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * License administration. Always reachable — activating a license must work when
 * the product itself is blocked (docs/12-licensing.md).
 */
class AdminLicenseController extends Controller
{
    public function __construct(
        private readonly CoreClient $core,
        private readonly EntitlementClient $entitlement,
    ) {
    }

    public function show(): JsonResponse
    {
        $core = $this->core->licenseStatus();

        return response()->json([
            'app' => $this->entitlement->state(),
            'core' => $core['ok'] ? $core['data'] : null,
            'core_reachable' => $core['ok'],
            'enforced' => (bool) config('license.enforce', false),
            'enabled' => (bool) config('license.enabled', true),
        ]);
    }

    /** Offline activation request code (fingerprint + nonce). */
    public function request(): JsonResponse
    {
        $response = $this->core->licenseRequest();
        if (! $response['ok']) {
            return response()->json(['error' => 'core_unreachable'], 502);
        }

        return response()->json($response['data']);
    }

    /** Upload a signed license file (online or offline activation). */
    public function activate(Request $request): JsonResponse
    {
        $contents = $this->licenseFile($request);
        if ($contents === null) {
            return response()->json(['error' => 'license_file_required'], 422);
        }

        $response = $this->core->licenseActivate($contents);
        $this->forgetCaches();

        if (! $response['ok']) {
            return response()->json([
                'error' => $response['data']['error'] ?? 'activation_failed',
            ], $response['status'] > 0 ? 422 : 502);
        }

        return response()->json(['status' => $response['data']['status'] ?? []]);
    }

    /** Upload a signed revocation list. */
    public function crl(Request $request): JsonResponse
    {
        $contents = $this->licenseFile($request, 'crl');
        if ($contents === null) {
            return response()->json(['error' => 'crl_file_required'], 422);
        }

        $response = $this->core->licenseCrl($contents);
        $this->forgetCaches();

        if (! $response['ok']) {
            return response()->json([
                'error' => $response['data']['error'] ?? 'crl_failed',
            ], $response['status'] > 0 ? 422 : 502);
        }

        return response()->json(['status' => 'ok']);
    }

    private function licenseFile(Request $request, string $field = 'license'): ?string
    {
        if ($request->hasFile($field)) {
            $file = $request->file($field);

            return $file !== null ? (string) file_get_contents($file->getRealPath()) : null;
        }

        $inline = $request->string($field);

        return $inline->isNotEmpty() ? $inline->toString() : null;
    }

    private function forgetCaches(): void
    {
        Cache::forget('license:ticket');
        Cache::forget('license:state');
    }
}
