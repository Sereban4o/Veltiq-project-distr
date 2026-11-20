<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Widgets\WidgetService;
use Illuminate\Http\JsonResponse;

/**
 * Embeddable iframe panels configured by an admin.
 */
class WidgetsController extends Controller
{
    public function __construct(private readonly WidgetService $widgets)
    {
    }

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->widgets->all()]);
    }
}
