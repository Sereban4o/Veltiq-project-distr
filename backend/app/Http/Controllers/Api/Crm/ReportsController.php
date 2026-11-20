<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Services\Crm\CrmReportsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportsController extends Controller
{
    public function __construct(
        private CrmReportsService $reports,
    ) {
    }

    public function funnel(): JsonResponse
    {
        return response()->json(['data' => $this->reports->funnel()]);
    }

    public function conversion(): JsonResponse
    {
        return response()->json(['data' => $this->reports->conversion()]);
    }

    public function revenue(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'period' => 'nullable|in:month,quarter,year',
            'from' => 'nullable|date',
            'to' => 'nullable|date',
        ]);

        return response()->json(['data' => $this->reports->revenue(
            $validated['period'] ?? 'month',
            $validated['from'] ?? null,
            $validated['to'] ?? null,
        )]);
    }

    public function summary(): JsonResponse
    {
        return response()->json(['data' => $this->reports->summary()]);
    }
}
