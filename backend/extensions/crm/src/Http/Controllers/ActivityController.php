<?php

namespace App\Extensions\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Extensions\Crm\Models\Activity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ActivityController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = min(max((int) $request->input('per_page', 50), 1), 200);

        $query = Activity::query()
            ->with(['deal:id,title', 'contact:id,first_name,last_name', 'company:id,name', 'owner:id,first_name,last_name'])
            ->orderByRaw('is_completed asc')
            ->orderByRaw('due_at is null')
            ->orderBy('due_at');

        foreach (['deal_id', 'contact_id', 'company_id'] as $field) {
            if ($value = $request->input($field)) {
                $query->where($field, $value);
            }
        }

        match ($request->input('filter')) {
            'open' => $query->where('is_completed', false),
            'completed' => $query->where('is_completed', true),
            'overdue' => $query->where('is_completed', false)->whereNotNull('due_at')->where('due_at', '<', now()),
            'today' => $query->whereDate('due_at', now()->toDateString()),
            default => null,
        };

        return response()->json($query->paginate($perPage));
    }

    public function store(Request $request): JsonResponse
    {
        $activity = Activity::create($this->validateData($request) + ['owner_id' => Auth::id()]);

        return response()->json(['data' => $activity], 201);
    }

    public function update(Request $request, Activity $activity): JsonResponse
    {
        $activity->update($this->validateData($request, partial: true));

        return response()->json(['data' => $activity->fresh()]);
    }

    public function toggle(Activity $activity): JsonResponse
    {
        $activity->update(['is_completed' => !$activity->is_completed]);

        return response()->json(['data' => $activity->fresh()]);
    }

    public function destroy(Activity $activity): JsonResponse
    {
        $activity->delete();

        return response()->json(['message' => 'Задача удалена']);
    }

    private function validateData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'type' => 'sometimes|in:' . implode(',', Activity::TYPES),
            'subject' => "{$required}|string|max:255",
            'description' => 'nullable|string|max:65000',
            'due_at' => 'nullable|date',
            'is_completed' => 'sometimes|boolean',
            'deal_id' => 'nullable|exists:crm_deals,id',
            'contact_id' => 'nullable|exists:crm_contacts,id',
            'company_id' => 'nullable|exists:crm_companies,id',
            'direction' => 'sometimes|nullable|in:inbound,outbound',
            'outcome' => 'sometimes|nullable|in:connected,no_answer,busy,callback',
            'duration_seconds' => 'sometimes|nullable|integer|min:0',
            'phone' => 'sometimes|nullable|string|max:64',
        ]);
    }
}
