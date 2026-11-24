<?php

namespace App\Extensions\Tasksbase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\Project;
use App\Models\SavedFilter;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class FilterController extends Controller
{
    /**
     * Personal saved filters of the current user.
     */
    public function index(Request $request)
    {
        $query = SavedFilter::where('user_id', Auth::id())->with('project');

        if ($request->filled('project_id')) {
            $query->where(function ($q) use ($request) {
                $q->where('project_id', $request->project_id)->orWhereNull('project_id');
            });
        }

        return response()->json($query->latest()->get());
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'jql_query' => 'nullable|string',
            'filters' => 'nullable|array',
            'project_id' => 'nullable|exists:projects,id',
        ]);

        $filter = SavedFilter::create([
            'user_id' => Auth::id(),
            'name' => $validated['name'],
            'jql_query' => $validated['jql_query'] ?? '',
            'filters' => $validated['filters'] ?? null,
            'project_id' => $validated['project_id'] ?? null,
            'is_global' => false,
        ]);

        return response()->json($filter, 201);
    }

    public function show(Request $request, string $filterId)
    {
        return response()->json($this->findOwn($filterId));
    }

    public function update(Request $request, string $filterId)
    {
        $filter = $this->findOwn($filterId);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'jql_query' => 'sometimes|nullable|string',
            'filters' => 'sometimes|nullable|array',
        ]);

        $filter->update($validated);

        return response()->json($filter->fresh());
    }

    public function destroy(Request $request, string $filterId)
    {
        $this->findOwn($filterId)->delete();

        return response()->json(['message' => 'Filter deleted']);
    }

    public function execute(Request $request, string $filterId)
    {
        $filter = $this->findOwn($filterId);

        $query = Issue::with(['project', 'type', 'status', 'assignee']);

        if (! $filter->is_global && $filter->project_id) {
            $query->where('project_id', $filter->project_id);
        }

        $filters = $filter->filters ?? [];

        if (! empty($filters['status_id'])) {
            $query->where('status_id', $filters['status_id']);
        }

        if (! empty($filters['assigned_to'])) {
            $query->where('assigned_to', $filters['assigned_to']);
        }

        if (! empty($filters['priority'])) {
            $query->where('priority', $filters['priority']);
        }

        if (! empty($filters['priority_in']) && is_array($filters['priority_in'])) {
            $query->whereIn('priority', $filters['priority_in']);
        }

        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('summary', 'ilike', "%{$search}%")
                  ->orWhere('description', 'ilike', "%{$search}%");
            });
        }

        // Fallback to the legacy simple JQL parser when no structured filters are stored.
        if (empty($filters) && $filter->jql_query) {
            $this->applyJql($query, $filter->jql_query);
        }

        return response()->json($query->limit(200)->get());
    }

    private function findOwn(string $filterId): SavedFilter
    {
        return SavedFilter::where('user_id', Auth::id())->findOrFail($filterId);
    }

    private function applyJql($query, string $jql): void
    {
        if (preg_match('/project\s*=\s*(\w+)/', $jql, $matches)) {
            $project = Project::where('key', $matches[1])->first();
            if ($project) {
                $query->where('project_id', $project->id);
            }
        }

        if (preg_match('/status\s*=\s*"([^"]+)"/', $jql, $matches)) {
            $query->whereHas('status', fn ($q) => $q->where('name', $matches[1]));
        }

        if (preg_match('/assignee\s*=\s*currentUser\(\)/', $jql)) {
            $query->where('assigned_to', Auth::id());
        }

        if (preg_match('/assignee\s*=\s*(\w+)/', $jql, $matches)) {
            $user = User::where('email', $matches[1])->first();
            if ($user) {
                $query->where('assigned_to', $user->id);
            }
        }

        if (preg_match('/priority\s*=\s*(\w+)/', $jql, $matches)) {
            $query->where('priority', $matches[1]);
        }
    }
}
