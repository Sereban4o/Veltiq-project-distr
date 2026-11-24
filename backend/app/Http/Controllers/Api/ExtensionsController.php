<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Extensions\ExtensionRegistry;
use Illuminate\Http\JsonResponse;

/**
 * Extension list for the SPA.
 *
 * Returns every declared extension with its `enabled` flag and the menu entries it
 * contributes. The frontend derives which menu items/routes to hide from this
 * payload, so a new extension (or a disabled one) is gated without code changes.
 * `known=false` means the core did not answer and nothing is cached: the SPA must
 * fail open instead of hiding everything.
 */
class ExtensionsController extends Controller
{
    public function __construct(private readonly ExtensionRegistry $registry)
    {
    }

    public function index(): JsonResponse
    {
        $extensions = $this->registry->list();

        return response()->json([
            'extensions' => array_map(static fn (array $module): array => [
                'id' => $module['id'],
                'name' => $module['name'],
                'version' => $module['version'],
                'enabled' => (bool) $module['enabled'],
                'menu' => $module['menu'],
            ], $extensions),
            'known' => $this->registry->isKnown(),
        ]);
    }
}
