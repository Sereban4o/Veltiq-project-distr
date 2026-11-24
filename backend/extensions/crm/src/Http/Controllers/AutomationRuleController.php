<?php

namespace App\Extensions\Crm\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Extensions\Crm\Models\CrmAutomationRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AutomationRuleController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => CrmAutomationRule::orderBy('position')->orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $rule = CrmAutomationRule::create($this->validateData($request) + [
            'created_by' => Auth::id(),
        ]);

        return response()->json(['data' => $rule], 201);
    }

    public function update(Request $request, CrmAutomationRule $rule): JsonResponse
    {
        $rule->update($this->validateData($request, partial: true));

        return response()->json(['data' => $rule->fresh()]);
    }

    public function destroy(CrmAutomationRule $rule): JsonResponse
    {
        $rule->delete();

        return response()->json(['message' => 'Правило удалено']);
    }

    private function validateData(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => "{$required}|string|max:150",
            'trigger' => [$partial ? 'sometimes' : 'required', Rule::in(CrmAutomationRule::TRIGGERS)],
            'conditions' => 'nullable|array',
            'action' => [$partial ? 'sometimes' : 'required', Rule::in(CrmAutomationRule::ACTIONS)],
            'action_payload' => 'nullable|array',
            'is_active' => 'sometimes|boolean',
            'position' => 'sometimes|integer|min:0',
        ]);
    }
}
