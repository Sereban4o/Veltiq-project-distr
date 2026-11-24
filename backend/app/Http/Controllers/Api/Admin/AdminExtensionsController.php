<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Services\Core\EntitlementClient;
use App\Services\Extensions\ExtensionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Extension administration: list, enable/disable, and the developer-mode warning
 * for unsigned (third-party) extensions.
 */
class AdminExtensionsController extends Controller
{
    public function __construct(
        private readonly ExtensionRegistry $registry,
        private readonly EntitlementClient $entitlement,
    ) {
    }

    public function index(): JsonResponse
    {
        return response()->json([
            'extensions' => $this->registry->list(),
            'allow_unsigned' => (bool) config('extensions.allow_unsigned', false),
            'can_install' => $this->entitlement->canInstallExtensions(),
            'enforced' => (bool) config('license.enforce', false),
            'known' => $this->registry->isKnown(),
        ]);
    }

    public function toggle(Request $request, string $id): JsonResponse
    {
        $validated = $request->validate(['enabled' => ['required', 'boolean']]);

        if (! $this->registry->setEnabled($id, (bool) $validated['enabled'])) {
            return response()->json(['error' => 'extension_change_rejected'], 409);
        }

        return response()->json(['extensions' => $this->registry->list()]);
    }
}
