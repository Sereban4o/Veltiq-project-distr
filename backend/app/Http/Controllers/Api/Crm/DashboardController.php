<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\Activity;
use App\Models\Crm\Company;
use App\Models\Crm\Contact;
use App\Models\Crm\Deal;
use App\Models\Crm\PipelineStage;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        $open = Deal::where('status', Deal::STATUS_OPEN);

        return response()->json([
            'data' => [
                'companies' => Company::count(),
                'contacts' => Contact::count(),
                'open_deals' => (clone $open)->count(),
                'pipeline_amount' => (float) (clone $open)->sum('amount'),
                'won_amount' => (float) Deal::where('status', Deal::STATUS_WON)->sum('amount'),
                'won_this_month' => Deal::where('status', Deal::STATUS_WON)
                    ->where('updated_at', '>=', now()->startOfMonth())
                    ->count(),
                'open_tasks' => Activity::where('is_completed', false)->count(),
                'overdue_tasks' => Activity::where('is_completed', false)
                    ->whereNotNull('due_at')
                    ->where('due_at', '<', now())
                    ->count(),
                'by_stage' => PipelineStage::orderBy('sort_order')
                    ->withCount(['deals' => fn ($q) => $q->where('status', Deal::STATUS_OPEN)])
                    ->withSum(['deals as deals_sum' => fn ($q) => $q->where('status', Deal::STATUS_OPEN)], 'amount')
                    ->get()
                    ->map(fn ($stage) => [
                        'id' => $stage->id,
                        'name' => $stage->name,
                        'color' => $stage->color,
                        'deals_count' => $stage->deals_count,
                        'amount' => (float) ($stage->deals_sum ?? 0),
                    ]),
                'recent_activities' => Activity::with(['deal:id,title', 'contact:id,first_name,last_name'])
                    ->latest()
                    ->limit(8)
                    ->get(),
            ],
        ]);
    }
}
