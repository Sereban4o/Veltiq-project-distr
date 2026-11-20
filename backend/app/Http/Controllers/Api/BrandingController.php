<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Branding\BrandingService;
use Illuminate\Http\JsonResponse;

/**
 * Public runtime branding/theme for the web client. Contains no secrets and is
 * cached briefly by clients.
 */
class BrandingController extends Controller
{
    public function __construct(private readonly BrandingService $branding)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->branding->get()])
            ->header('Cache-Control', 'public, max-age=300');
    }
}
