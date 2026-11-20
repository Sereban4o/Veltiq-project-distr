<?php

namespace App\Http\Controllers\Api\Tasksbase;

use App\Http\Controllers\Controller;
use App\Jobs\ResyncProjectSearchIndex;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ProjectController extends Controller
{
    public function index(Request $request)
    {
        $query = Project::with(['creator', 'members'])
            ->where(function ($q) use ($request) {
                $q->where('visibility', 'public')
                  ->orWhere('visibility', 'team')
                  ->orWhereHas('members', function ($q) {
                      $q->where('user_id', Auth::id());
                  });
            });

        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('name', 'ilike', "%{$request->search}%")
                  ->orWhere('key', 'ilike', "%{$request->search}%");
            });
        }

        $projects = $query->latest()->paginate(20);

        return response()->json($projects);
    }

    public function show(Request $request, string $projectKey)
    {
        $project = Project::where('key', $projectKey)
            ->with(['creator', 'members'])
            ->firstOrFail();

        // Check access
        if ($project->visibility === 'private' && !$project->isMember(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        return response()->json($project);
    }

    /**
     * Issues with dates, for the project timeline / roadmap view.
     */
    public function timeline(Request $request, string $projectKey)
    {
        $project = Project::where('key', $projectKey)->firstOrFail();
        $user = Auth::user();

        if (! $user->isAdmin() && ! $project->canView($user)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $issues = Issue::query()
            ->where('project_id', $project->id)
            ->where(fn ($query) => $query->whereNotNull('start_date')->orWhereNotNull('due_date'))
            ->with([
                'status:id,name,category,color',
                'assignee:id,first_name,last_name,avatar',
                'epic:id,key,summary',
            ])
            ->orderByRaw('coalesce(start_date, due_date) asc')
            ->limit(300)
            ->get([
                'id', 'key', 'summary', 'status_id', 'assigned_to', 'epic_id',
                'priority', 'start_date', 'due_date', 'story_points',
            ]);

        $blockedCounts = IssueLink::query()
            ->where('link_type', 'is_blocked_by')
            ->whereIn('source_issue_id', $issues->pluck('id'))
            ->selectRaw('source_issue_id, count(*) as links_count')
            ->groupBy('source_issue_id')
            ->pluck('links_count', 'source_issue_id');

        $data = $issues->map(fn (Issue $issue) => [
            'id' => (int) $issue->id,
            'key' => $issue->key,
            'summary' => $issue->summary,
            'priority' => $issue->priority,
            'story_points' => $issue->story_points,
            'start_date' => optional($issue->start_date)->toDateString(),
            'due_date' => optional($issue->due_date)->toDateString(),
            'status' => $issue->status ? [
                'id' => (int) $issue->status->id,
                'name' => $issue->status->name,
                'category' => $issue->status->category,
                'color' => $issue->status->color,
            ] : null,
            'assignee' => $issue->assignee ? [
                'id' => (int) $issue->assignee->id,
                'name' => trim(($issue->assignee->first_name ?? '') . ' ' . ($issue->assignee->last_name ?? '')),
                'avatar' => $issue->assignee->avatar,
            ] : null,
            'epic' => $issue->epic ? [
                'id' => (int) $issue->epic->id,
                'key' => $issue->epic->key,
                'summary' => $issue->epic->summary,
            ] : null,
            'blocked_by_count' => (int) ($blockedCounts[$issue->id] ?? 0),
        ])->values();

        return response()->json([
            'data' => [
                'project' => ['key' => $project->key, 'name' => $project->name],
                'issues' => $data,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'key' => 'required|string|max:10|unique:projects,key',
            'description' => 'nullable|string',
            'type' => 'required|in:scrum,kanban,basic',
            'visibility' => 'sometimes|in:private,public,team',
        ]);

        $project = Project::create([
            'created_by' => Auth::id(),
            'key' => strtoupper($validated['key']),
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'type' => $validated['type'],
            'visibility' => $validated['visibility'] ?? 'team',
        ]);

        // Add creator as admin
        $project->members()->attach(Auth::id(), ['role' => 'admin']);

        return response()->json($project, 201);
    }

    public function update(Request $request, string $projectKey)
    {
        $project = Project::where('key', $projectKey)->firstOrFail();

        if (!$project->isMember(Auth::user()) || Auth::id() !== $project->created_by) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'description' => 'nullable|string',
            'type' => 'sometimes|in:scrum,kanban,basic',
            'visibility' => 'sometimes|in:private,public,team',
        ]);

        $project->update($validated);

        if (array_key_exists('visibility', $validated)) {
            // Search documents carry the project's visibility snapshot.
            ResyncProjectSearchIndex::dispatch($project->id);
        }

        return response()->json($project);
    }

    public function destroy(Request $request, string $projectKey)
    {
        $project = Project::where('key', $projectKey)->firstOrFail();

        if (Auth::id() !== $project->created_by) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $project->delete();

        return response()->json(['message' => 'Project deleted']);
    }

    public function members(Request $request, string $projectKey)
    {
        $project = Project::where('key', $projectKey)->firstOrFail();

        $members = $project->members()->get();

        return response()->json($members);
    }

    public function addMember(Request $request, string $projectKey)
    {
        $project = Project::where('key', $projectKey)->firstOrFail();

        if (!$project->isMember(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'role' => 'required|in:admin,member,viewer',
        ]);

        $project->members()->attach($validated['user_id'], ['role' => $validated['role']]);

        ResyncProjectSearchIndex::dispatch($project->id);

        return response()->json(['message' => 'Member added'], 201);
    }

    public function removeMember(Request $request, string $projectKey, string $userId)
    {
        $project = Project::where('key', $projectKey)->firstOrFail();

        if (Auth::id() !== (int) $userId && Auth::id() !== $project->created_by) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $project->members()->detach($userId);

        ResyncProjectSearchIndex::dispatch($project->id);

        return response()->json(['message' => 'Member removed']);
    }
}
