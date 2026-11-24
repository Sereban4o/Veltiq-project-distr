<?php

namespace App\Extensions\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Extensions\Crm\Models\Deal;
use App\Extensions\Crm\Models\PipelineStage;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;

class PipelineStageController extends Controller
{
    public const CACHE_KEY = 'crm.pipeline_stages';

    public function index(): JsonResponse
    {
        $stages = Cache::remember(self::CACHE_KEY, 300, fn () => PipelineStage::orderBy('sort_order')
            ->withCount(['deals' => fn ($q) => $q->where('status', Deal::STATUS_OPEN)])
            ->get());

        return response()->json(['data' => $stages]);
    }
}
