<?php

namespace App\Http\Controllers\Api\Tasksbase;

use App\Http\Controllers\Controller;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Issue;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class BoardController extends Controller
{
    private const PRIORITY_LABELS = [
        'lowest' => 'Низший',
        'low' => 'Низкий',
        'medium' => 'Средний',
        'high' => 'Высокий',
        'highest' => 'Высший',
    ];

    public function index(Request $request)
    {
        $user = Auth::user();

        $query = Board::select('id', 'project_id', 'name', 'type', 'created_by', 'order')
            ->orderBy('created_at', 'desc');

        if (!$user->isAdmin()) {
            $projectIds = Project::visibleTo($user)->pluck('id');
            $query->whereIn('project_id', $projectIds);
        }

        return response()->json($query->get());
    }

    public function indexByProject(Request $request, string $projectKey)
    {
        $project = \App\Models\Project::where('key', $projectKey)->firstOrFail();
        $boards = $project->boards()->with('columns')->get();

        return response()->json($boards);
    }

    public function store(Request $request, string $projectKey)
    {
        $project = \App\Models\Project::where('key', $projectKey)->firstOrFail();
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'type' => 'required|in:kanban,scrum',
        ]);

        $board = $project->boards()->create([
            'name' => $validated['name'],
            'type' => $validated['type'],
            'created_by' => Auth::id(),
        ]);

        return response()->json($board->load('columns'), 201);
    }

    public function show(Request $request, string $boardId)
    {
        $board = Board::with(['columns.status', 'project', 'creator'])->findOrFail($boardId);

        if (!$this->userCanAccessBoard($board)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $board->setRelation('columns', $board->columns->sortBy(function ($column) {
            return sprintf('%010d-%010d', $column->status?->sort_order ?? 999999999, $column->order ?? 0);
        })->values());

        return response()->json($board);
    }

    public function update(Request $request, string $boardId)
    {
        $board = Board::findOrFail($boardId);

        if (!$this->userCanAccessBoard($board)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'filter_query' => 'nullable|array',
            'swimlanes' => 'nullable|array',
            'swimlane_by' => 'sometimes|string|in:none,assignee,priority,epic',
            'wip_enforcement' => 'sometimes|boolean',
        ]);
        $board->update($validated);

        return response()->json($board);
    }

    /**
     * Aggregated board payload: columns, all issues and swimlane descriptors.
     */
    public function view(Request $request, string $boardId)
    {
        $board = Board::with(['columns.status', 'project'])->findOrFail($boardId);

        if (!$this->userCanAccessBoard($board)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $columns = $board->columns->sortBy(function ($column) {
            return sprintf('%010d-%010d', $column->status?->sort_order ?? 999999999, $column->order ?? 0);
        })->values();

        $statusIds = $columns->pluck('status_id')->filter()->unique()->values();

        $issues = Issue::with(['type', 'status', 'assignee', 'epic'])
            ->where('project_id', $board->project_id)
            ->when($statusIds->isNotEmpty(), fn ($q) => $q->whereIn('status_id', $statusIds))
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        $swimlaneBy = $board->swimlane_by ?: 'none';

        return response()->json([
            'board' => [
                'id' => $board->id,
                'project_id' => $board->project_id,
                'name' => $board->name,
                'type' => $board->type,
                'swimlane_by' => $swimlaneBy,
                'wip_enforcement' => (bool) $board->wip_enforcement,
            ],
            'columns' => $columns->map(fn (BoardColumn $column) => [
                'id' => $column->id,
                'name' => $column->name,
                'status_id' => $column->status_id,
                'wip_limit' => $column->wip_limit,
                'order' => $column->order,
                'status' => $column->status ? [
                    'id' => $column->status->id,
                    'name' => $column->status->name,
                    'category' => $column->status->category,
                    'color' => $column->status->color,
                ] : null,
            ])->values(),
            'issues' => $issues->map(fn (Issue $issue) => $this->presentViewIssue($issue, $swimlaneBy))->values(),
            'swimlanes' => $this->swimlaneSummary($issues, $swimlaneBy),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentViewIssue(Issue $issue, string $swimlaneBy): array
    {
        [$laneKey, $laneLabel] = $this->swimlaneFor($issue, $swimlaneBy);

        return [
            'id' => $issue->id,
            'key' => $issue->key,
            'summary' => $issue->summary,
            'project_id' => $issue->project_id,
            'status_id' => $issue->status_id,
            'assigned_to' => $issue->assigned_to,
            'priority' => $issue->priority,
            'epic_id' => $issue->epic_id,
            'sequence' => $issue->sequence,
            'swimlane_key' => $laneKey,
            'swimlane_label' => $laneLabel,
            'assignee' => $issue->assignee ? [
                'id' => $issue->assignee->id,
                'full_name' => $issue->assignee->full_name,
                'initials' => $issue->assignee->initials,
            ] : null,
            'type' => $issue->type ? [
                'id' => $issue->type->id,
                'name' => $issue->type->name,
                'color' => $issue->type->color,
            ] : null,
            'status' => $issue->status ? [
                'id' => $issue->status->id,
                'name' => $issue->status->name,
                'category' => $issue->status->category,
                'color' => $issue->status->color,
            ] : null,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function swimlaneFor(Issue $issue, string $swimlaneBy): array
    {
        return match ($swimlaneBy) {
            'assignee' => $issue->assigned_to
                ? ['user:' . $issue->assigned_to, $issue->assignee?->full_name ?? ('#' . $issue->assigned_to)]
                : ['unassigned', 'Без исполнителя'],
            'priority' => ['priority:' . $issue->priority, self::PRIORITY_LABELS[$issue->priority] ?? (string) $issue->priority],
            'epic' => $issue->epic_id
                ? ['epic:' . $issue->epic_id, $issue->epic?->key ?? ('Эпик #' . $issue->epic_id)]
                : ['no_epic', 'Без эпика'],
            default => ['all', 'Все задачи'],
        };
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Issue>  $issues
     * @return array<int, array{key: string, label: string, count: int}>
     */
    private function swimlaneSummary($issues, string $swimlaneBy): array
    {
        $lanes = [];

        foreach ($issues as $issue) {
            [$key, $label] = $this->swimlaneFor($issue, $swimlaneBy);

            if (! isset($lanes[$key])) {
                $lanes[$key] = ['key' => $key, 'label' => $label, 'count' => 0];
            }

            $lanes[$key]['count']++;
        }

        return array_values($lanes);
    }

    public function destroy(Request $request, string $boardId)
    {
        $board = Board::findOrFail($boardId);

        if (!$this->userCanAccessBoard($board)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $board->delete();

        return response()->json(['message' => 'Board deleted']);
    }

    public function columns(Request $request, string $boardId)
    {
        $board = Board::findOrFail($boardId);

        if (!$this->userCanAccessBoard($board)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $columns = BoardColumn::where('board_columns.board_id', $boardId)
            ->leftJoin('issue_statuses', 'issue_statuses.id', '=', 'board_columns.status_id')
            ->orderByRaw('issue_statuses.sort_order IS NULL')
            ->orderBy('issue_statuses.sort_order')
            ->orderBy('board_columns.order')
            ->select('board_columns.*')
            ->with('status')
            ->get();

        return response()->json($columns);
    }

    public function addColumn(Request $request, string $boardId)
    {
        $board = Board::findOrFail($boardId);

        if (!$this->userCanAccessBoard($board)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'status_id' => 'nullable|exists:issue_statuses,id',
            'wip_limit' => 'nullable|integer|min:1',
        ]);

        $maxOrder = BoardColumn::where('board_id', $boardId)->max('order') ?? 0;

        $column = BoardColumn::create([
            'board_id' => $boardId,
            'name' => $validated['name'],
            'status_id' => $validated['status_id'] ?? null,
            'order' => $maxOrder + 1,
            'wip_limit' => $validated['wip_limit'] ?? null,
        ]);

        return response()->json($column, 201);
    }

    public function updateColumn(Request $request, string $boardId, string $columnId)
    {
        $column = BoardColumn::where('board_id', $boardId)->findOrFail($columnId);

        if (!$this->userCanAccessBoard($column->board)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'name' => 'sometimes|string|max:255',
            'status_id' => 'sometimes|nullable|exists:issue_statuses,id',
            'wip_limit' => 'sometimes|nullable|integer|min:1',
        ]);
        $column->update($validated);

        return response()->json($column);
    }

    public function deleteColumn(Request $request, string $boardId, string $columnId)
    {
        $column = BoardColumn::where('board_id', $boardId)->findOrFail($columnId);

        if (!$this->userCanAccessBoard($column->board)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $column->delete();

        return response()->json(['message' => 'Column deleted']);
    }

    public function reorderIssues(Request $request, string $boardId)
    {
        $board = Board::findOrFail($boardId);

        if (!$this->userCanAccessBoard($board)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'column_issues' => 'required|array',
            'column_issues.*.issue_id' => 'required|exists:issues,id',
            'column_issues.*.order' => 'required|integer',
        ]);

        foreach ($validated['column_issues'] as $columnData) {
            Issue::where('id', $columnData['issue_id'])->update([
                'sequence' => $columnData['order'],
            ]);
        }

        return response()->json(['message' => 'Issues reordered']);
    }

    private function userCanAccessBoard(Board $board): bool
    {
        $user = Auth::user();

        if (!$user) {
            return false;
        }

        return $user->isAdmin()
            || ($board->project && $board->project->canView($user));
    }
}
