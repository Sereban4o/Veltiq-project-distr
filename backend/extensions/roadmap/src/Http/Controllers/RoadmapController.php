<?php

namespace App\Extensions\Roadmap\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\IssueLink;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Project roadmap (timeline) endpoint, moved out of ProjectController so the
 * roadmap module owns it and can be switched off (docs/11-extensions.md).
 */
class RoadmapController extends Controller
{
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
}
