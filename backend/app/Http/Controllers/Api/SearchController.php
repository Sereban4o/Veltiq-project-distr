<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\Project;
use App\Services\Search\GlobalSearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class SearchController extends Controller
{
    public function index(Request $request, GlobalSearchService $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => 'required|string|min:2|max:100',
            'types' => 'nullable|string',
            'per_page' => 'nullable|integer|min:1|max:50',
            'page' => 'nullable|integer|min:1',
        ]);

        $types = $this->types($validated['types'] ?? null);
        $perPage = (int) ($validated['per_page'] ?? 10);
        $page = (int) ($validated['page'] ?? 1);

        $groups = $search->search(Auth::user(), $validated['q'], $types, $perPage, $page);

        return response()->json([
            'query' => $validated['q'],
            'types' => $types,
            'page' => $page,
            'per_page' => $perPage,
            'groups' => $groups,
        ]);
    }

    /**
     * @return list<string>
     */
    private function types(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return GlobalSearchService::TYPES;
        }

        $requested = array_filter(array_map('trim', explode(',', $raw)));

        $types = array_values(array_intersect($requested, GlobalSearchService::TYPES));

        return $types === [] ? GlobalSearchService::TYPES : $types;
    }

    public function jqlExecute(Request $request)
    {
        $validated = $request->validate([
            'jql' => 'required|string',
        ]);

        $jql = $validated['jql'];
        $query = Issue::with(['project', 'type', 'status', 'assignee']);

        // Parse JQL
        if (preg_match('/project\s*=\s*(\w+)/', $jql, $matches)) {
            $project = Project::where('key', $matches[1])->first();
            if ($project) $query->where('project_id', $project->id);
        }

        if (preg_match('/status\s*=\s*"([^"]+)"/', $jql, $matches)) {
            $query->whereHas('status', function ($q) use ($matches) {
                $q->where('name', $matches[1]);
            });
        }

        if (preg_match('/assignee\s*=\s*currentUser\(\)/', $jql)) {
            $query->where('assigned_to', auth()->id());
        }

        if (preg_match('/reporter\s*=\s*currentUser\(\)/', $jql)) {
            $query->where('reporter_id', auth()->id());
        }

        if (preg_match('/priority\s*=\s*(\w+)/', $jql, $matches)) {
            $query->where('priority', $matches[1]);
        }

        if (preg_match('/order\s+by\s+(\w+)(\s+(asc|desc))?/', $jql, $matches)) {
            $query->orderBy($matches[1], $matches[3] ?? 'desc');
        }

        if (preg_match('/created\s*>=\s*(-?\d+d?)/', $jql, $matches)) {
            $query->where('created_at', '>=', now()->parse($matches[1]));
        }

        $issues = $query->limit(100)->get();

        return response()->json($issues);
    }
}
