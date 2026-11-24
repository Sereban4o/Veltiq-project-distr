<?php

namespace App\Extensions\Tasksbase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\Issue;
use App\Models\Sprint;
use App\Models\SprintIssue;
use App\Services\Tasksbase\SprintMetricsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class SprintController extends Controller
{
    public function __construct(
        private SprintMetricsService $metrics,
    ) {
    }

    public function index(Request $request, string $boardId)
    {
        $sprints = Sprint::where('board_id', $boardId)
            ->with(['issues' => function ($query) {
                $query->with(['type', 'status', 'assignee']);
            }])
            ->orderBy('sequence', 'desc')
            ->get();

        return response()->json($sprints);
    }

    public function store(Request $request, string $boardId)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
        ]);

        $maxSequence = Sprint::where('board_id', $boardId)->max('sequence') ?? 0;
        $board = Board::findOrFail($boardId);

        $sprint = Sprint::create([
            'project_id' => $board->project_id,
            'board_id' => $boardId,
            'name' => $validated['name'],
            'start_date' => $validated['start_date'] ?? null,
            'end_date' => $validated['end_date'] ?? null,
            'status' => 'planning',
            'sequence' => $maxSequence + 1,
        ]);

        return response()->json($sprint, 201);
    }

    public function show(Request $request, string $sprintId)
    {
        $sprint = Sprint::with(['issues' => function ($query) {
            $query->with(['type', 'status', 'assignee', 'project']);
        }])->findOrFail($sprintId);

        return response()->json($sprint);
    }

    public function update(Request $request, string $sprintId)
    {
        $sprint = Sprint::findOrFail($sprintId);

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after:start_date',
        ]);

        $sprint->update($validated);

        return response()->json($sprint);
    }

    public function destroy(Request $request, string $sprintId)
    {
        $sprint = Sprint::findOrFail($sprintId);

        DB::transaction(function () use ($sprint) {
            // Detach issues first so they stay in the project backlog.
            SprintIssue::where('sprint_id', $sprint->id)->delete();
            $sprint->delete();
        });

        return response()->json(['message' => 'Спринт удалён']);
    }

    public function start(Request $request, string $sprintId)
    {
        $sprint = Sprint::findOrFail($sprintId);

        // Complete other active sprints
        Sprint::where('board_id', $sprint->board_id)
            ->where('status', 'active')
            ->update(['status' => 'completed']);

        $sprint->update([
            'status' => 'active',
            'start_date' => now()->toDateString(),
        ]);

        return response()->json($sprint->load(['issues.type', 'issues.status', 'issues.assignee']));
    }

    public function complete(Request $request, string $sprintId)
    {
        $sprint = Sprint::findOrFail($sprintId);

        $validated = $request->validate([
            'carryover_sprint_id' => 'nullable|exists:sprints,id',
        ]);

        $sprint = DB::transaction(function () use ($sprint, $validated) {
            // Capture the final state before anything is moved out.
            $this->metrics->capture($sprint);

            if (!empty($validated['carryover_sprint_id'])) {
                $target = Sprint::where('board_id', $sprint->board_id)
                    ->findOrFail($validated['carryover_sprint_id']);

                $rank = (float) (SprintIssue::where('sprint_id', $target->id)->max('rank') ?? 0);

                $unfinished = $sprint->issues()->with('status')->get()
                    ->filter(fn (Issue $issue) => $issue->status?->category !== 'done');

                foreach ($unfinished as $issue) {
                    $rank += 1;
                    SprintIssue::updateOrCreate(
                        ['sprint_id' => $target->id, 'issue_id' => $issue->id],
                        ['rank' => $rank],
                    );
                    SprintIssue::where('sprint_id', $sprint->id)
                        ->where('issue_id', $issue->id)
                        ->delete();
                }
            }

            $sprint->update([
                'status' => 'completed',
                'end_date' => now()->toDateString(),
            ]);

            return $sprint;
        });

        return response()->json($sprint);
    }

    public function metrics(Request $request, string $sprintId)
    {
        $sprint = Sprint::findOrFail($sprintId);

        return response()->json([
            'sprint' => [
                'id' => $sprint->id,
                'name' => $sprint->name,
                'status' => $sprint->status,
                'start_date' => $sprint->start_date,
                'end_date' => $sprint->end_date,
            ],
            'stats' => $this->metrics->stats($sprint),
            'burndown' => $this->metrics->burndown($sprint),
            'velocity' => $this->metrics->velocity($sprint),
        ]);
    }

    public function burndown(Request $request, string $sprintId)
    {
        return response()->json($this->metrics->burndown(Sprint::findOrFail($sprintId)));
    }

    public function velocity(Request $request, string $sprintId)
    {
        return response()->json($this->metrics->velocity(Sprint::findOrFail($sprintId)));
    }

    public function snapshot(Request $request, string $sprintId)
    {
        $sprint = Sprint::findOrFail($sprintId);

        if (!Auth::user()->isAdmin() && !$sprint->project?->hasPermission(Auth::user(), 'edit_boards')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        return response()->json($this->metrics->capture($sprint), 201);
    }

    public function addIssue(Request $request, string $sprintId)
    {
        $validated = $request->validate([
            'issue_id' => 'required|exists:issues,id',
            'rank' => 'nullable|numeric',
        ]);

        $sprint = Sprint::findOrFail($sprintId);

        // Check issue belongs to same project
        $issue = Issue::findOrFail($validated['issue_id']);
        if ($issue->project_id !== $sprint->project_id) {
            return response()->json(['message' => 'Issue does not belong to this sprint\'s project'], 422);
        }

        $rank = $validated['rank'] ?? (SprintIssue::where('sprint_id', $sprintId)->max('rank') ?? 0) + 1;

        $sprintIssue = SprintIssue::updateOrCreate(
            ['sprint_id' => $sprintId, 'issue_id' => $validated['issue_id']],
            ['rank' => $rank],
        );

        return response()->json($sprintIssue, 201);
    }

    public function removeIssue(Request $request, string $sprintId, string $issueId)
    {
        SprintIssue::where('sprint_id', $sprintId)
            ->where('issue_id', $issueId)
            ->delete();

        return response()->json(['message' => 'Issue removed from sprint']);
    }
}
