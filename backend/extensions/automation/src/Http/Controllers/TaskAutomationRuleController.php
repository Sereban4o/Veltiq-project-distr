<?php

namespace App\Extensions\Automation\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Extensions\Automation\Models\TaskAutomationRule;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * CRUD for task automation rules (per project, or global when project_id is null).
 */
class TaskAutomationRuleController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TaskAutomationRule::query()->orderBy('position')->orderBy('id');

        if ($request->filled('project_id')) {
            $projectId = (int) $request->input('project_id');
            $this->authorizeProject($projectId, 'view');

            $query->where(fn ($q) => $q->where('project_id', $projectId)->orWhereNull('project_id'));
        } elseif (! Auth::user()->isAdmin()) {
            abort(403, 'Недостаточно прав');
        }

        return response()->json(['data' => $query->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $this->validated($request);
        $this->authorizeManage($validated['project_id'] ?? null);

        $rule = TaskAutomationRule::create($validated + ['created_by' => Auth::id()]);

        return response()->json(['data' => $rule], 201);
    }

    public function update(Request $request, TaskAutomationRule $rule): JsonResponse
    {
        $validated = $this->validated($request, partial: true);
        $this->authorizeManage($validated['project_id'] ?? $rule->project_id);

        $rule->update($validated);

        return response()->json(['data' => $rule->fresh()]);
    }

    public function destroy(TaskAutomationRule $rule): JsonResponse
    {
        $this->authorizeManage($rule->project_id);

        $rule->delete();

        return response()->json(['message' => 'Правило удалено']);
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return $request->validate([
            'name' => "$required|string|max:255",
            'project_id' => 'sometimes|nullable|integer|exists:projects,id',
            'trigger' => "$required|in:" . implode(',', TaskAutomationRule::TRIGGERS),
            'conditions' => 'sometimes|nullable|array',
            'conditions.to_status_id' => 'sometimes|nullable|integer|exists:issue_statuses,id',
            'conditions.priority' => 'sometimes|nullable|in:lowest,low,medium,high,highest',
            'actions' => "$required|array|min:1|max:10",
            'actions.*.type' => 'required|in:' . implode(',', TaskAutomationRule::ACTIONS),
            'actions.*.user_id' => 'sometimes|nullable|integer|exists:users,id',
            'actions.*.priority' => 'sometimes|nullable|in:lowest,low,medium,high,highest',
            'actions.*.message' => 'sometimes|nullable|string|max:500',
            'actions.*.title' => 'sometimes|nullable|string|max:120',
            'is_active' => 'sometimes|boolean',
            'position' => 'sometimes|integer|min:0',
        ]);
    }

    private function authorizeManage(?int $projectId): void
    {
        if ($projectId === null) {
            if (! Auth::user()->isAdmin()) {
                abort(403, 'Глобальные правила может менять только администратор');
            }

            return;
        }

        $this->authorizeProject($projectId, 'edit');
    }

    private function authorizeProject(int $projectId, string $mode): void
    {
        $user = Auth::user();
        if ($user->isAdmin()) {
            return;
        }

        $project = Project::findOrFail($projectId);
        $allowed = $mode === 'edit'
            ? $project->hasPermission($user, 'edit_issues')
            : $project->canView($user);

        if (! $allowed) {
            abort(403, 'Недостаточно прав');
        }
    }
}
