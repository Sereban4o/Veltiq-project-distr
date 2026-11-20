<?php

namespace App\Http\Controllers\Api\Crm;

use App\Http\Controllers\Controller;
use App\Models\Crm\Deal;
use App\Models\Crm\PipelineStage;
use App\Services\Crm\CrmAutomationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class DealController extends Controller
{
    public function __construct(
        private CrmAutomationService $automation,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 50), 1), 200);

        $query = Deal::query()
            ->with(['company:id,name', 'contact:id,first_name,last_name', 'stage:id,name,color,is_won,is_lost', 'owner:id,first_name,last_name'])
            ->orderByDesc('updated_at');

        if ($stageId = $request->input('stage_id')) {
            $query->where('stage_id', $stageId);
        }
        if ($status = $request->input('status')) {
            $query->where('status', $status);
        }
        if ($search = trim((string) $request->input('search'))) {
            $query->where('title', 'ilike', "%{$search}%");
        }

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validateData($request);
        $validated['owner_id'] = Auth::id();
        $validated['status'] = $this->statusForStage($validated['stage_id'] ?? null);

        $deal = Deal::create($validated);

        $this->automation->handle('deal_created', $deal);
        if ($deal->status === Deal::STATUS_WON) {
            $this->automation->handle('deal_won', $deal);
        }

        return response()->json(['data' => $deal->load(['company:id,name', 'contact:id,first_name,last_name', 'stage:id,name,color'])], 201);
    }

    public function show(Deal $deal): JsonResponse
    {
        return response()->json([
            'data' => $deal->load([
                'company:id,name',
                'contact:id,first_name,last_name',
                'stage:id,name,color',
                'activities' => fn ($q) => $q->latest(),
                'owner:id,first_name,last_name',
            ]),
        ]);
    }

    public function update(Request $request, Deal $deal): JsonResponse
    {
        $validated = $this->validateData($request, partial: true);

        $previousStage = $deal->stage_id;

        if (array_key_exists('stage_id', $validated)) {
            $validated['status'] = $this->statusForStage($validated['stage_id']);
        }

        $deal->update($validated);

        if (array_key_exists('stage_id', $validated) && (int) $previousStage !== (int) $deal->stage_id) {
            $this->automation->handle('stage_changed', $deal, [
                'from_stage_id' => $previousStage,
                'to_stage_id' => (int) $deal->stage_id,
            ]);
        }
        if ($deal->status === Deal::STATUS_WON) {
            $this->automation->handle('deal_won', $deal);
        }

        return response()->json(['data' => $deal->fresh(['company:id,name', 'contact:id,first_name,last_name', 'stage:id,name,color'])]);
    }

    public function moveStage(Request $request, Deal $deal): JsonResponse
    {
        $validated = $request->validate([
            'stage_id' => 'required|exists:crm_pipeline_stages,id',
        ]);

        $previousStage = $deal->stage_id;

        $deal->update([
            'stage_id' => $validated['stage_id'],
            'status' => $this->statusForStage($validated['stage_id']),
        ]);

        $this->automation->handle('stage_changed', $deal, [
            'from_stage_id' => $previousStage,
            'to_stage_id' => (int) $validated['stage_id'],
        ]);
        if ($deal->status === Deal::STATUS_WON) {
            $this->automation->handle('deal_won', $deal);
        }

        return response()->json(['data' => $deal->fresh('stage:id,name,color,is_won,is_lost')]);
    }

    public function destroy(Deal $deal): JsonResponse
    {
        $deal->delete();

        return response()->json(['message' => 'Сделка удалена']);
    }

    public function stats(): JsonResponse
    {
        $open = Deal::where('status', Deal::STATUS_OPEN);

        return response()->json([
            'data' => [
                'open_count' => (clone $open)->count(),
                'open_amount' => (float) (clone $open)->sum('amount'),
                'won_count' => Deal::where('status', Deal::STATUS_WON)->count(),
                'won_amount' => (float) Deal::where('status', Deal::STATUS_WON)->sum('amount'),
                'lost_count' => Deal::where('status', Deal::STATUS_LOST)->count(),
                'by_stage' => PipelineStage::orderBy('sort_order')
                    ->withCount('deals')
                    ->withSum(['deals as deals_sum' => fn ($q) => $q->where('status', Deal::STATUS_OPEN)], 'amount')
                    ->get()
                    ->map(fn ($stage) => [
                        'id' => $stage->id,
                        'name' => $stage->name,
                        'color' => $stage->color,
                        'deals_count' => $stage->deals_count,
                        'amount' => (float) ($stage->deals_sum ?? 0),
                    ]),
            ],
        ]);
    }

    private function statusForStage(?int $stageId): string
    {
        if ($stageId === null) {
            return Deal::STATUS_OPEN;
        }

        $stage = PipelineStage::find($stageId);
        if ($stage === null) {
            return Deal::STATUS_OPEN;
        }

        return match (true) {
            $stage->is_won => Deal::STATUS_WON,
            $stage->is_lost => Deal::STATUS_LOST,
            default => Deal::STATUS_OPEN,
        };
    }

    private function validateData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'title' => "{$required}|string|max:255",
            'company_id' => 'nullable|exists:crm_companies,id',
            'contact_id' => 'nullable|exists:crm_contacts,id',
            'stage_id' => 'nullable|exists:crm_pipeline_stages,id',
            'amount' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:8',
            'probability' => 'nullable|integer|min:0|max:100',
            'expected_close_date' => 'nullable|date',
            'description' => 'nullable|string|max:65000',
        ]);
    }
}
