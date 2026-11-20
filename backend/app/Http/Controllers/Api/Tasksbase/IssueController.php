<?php

namespace App\Http\Controllers\Api\Tasksbase;

use App\Events\BoardUpdated;
use App\Events\IssueUpdated;
use App\Events\NewComment;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreIssueCommentRequest;
use App\Http\Requests\StoreIssueRequest;
use App\Http\Requests\UpdateIssueRequest;
use App\Models\ActivityLog;
use App\Models\Board;
use App\Models\BoardColumn;
use App\Models\Issue;
use App\Models\IssueAttachment;
use App\Models\IssueComment;
use App\Models\IssueLink;
use App\Models\IssueStatus;
use App\Models\IssueType;
use App\Models\Project;
use App\Models\User;
use App\Services\Tasksbase\NotificationService;
use App\Services\Tasksbase\TaskAutomationEngine;
use App\Support\HtmlSanitizer;
use App\Support\Tasksbase\AuditWriter;
use App\Support\Tasksbase\MentionParser;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @OA\Tag(
 *     name="Issues",
 *     description="API Endpoints for Issues"
 * )
 */

class IssueController extends Controller
{
    public function __construct(
        private NotificationService $notifications,
        private TaskAutomationEngine $automations,
    ) {
    }

    public function index(Request $request)
    {
        $perPage = $request->input('per_page', 20);

        $query = Issue::with(['project', 'type', 'status', 'assignee', 'epic']);

        $user = Auth::user();
        if (!$user->isAdmin()) {
            $query->whereHas('project', function ($q) use ($user) {
                $q->visibleTo($user);
            });
        }

        // Filter by project
        if ($request->has('project_id')) {
            $query->where('project_id', $request->project_id);
        }

        // Filter by status
        if ($request->has('status_id')) {
            $query->where('status_id', $request->status_id);
        }

        // Filter by assignee
        if ($request->has('assigned_to')) {
            $query->where('assigned_to', $request->assigned_to);
        }

        // Filter by priority
        if ($request->has('priority')) {
            $query->where('priority', $request->priority);
        }

        // Filter by exact key
        if ($request->filled('key')) {
            $query->where('key', $request->key);
        }

        // Search
        if ($request->has('search')) {
            $query->where(function ($q) use ($request) {
                $q->where('summary', 'ilike', "%{$request->search}%")
                  ->orWhere('description', 'ilike', "%{$request->search}%");
            });
        }

        return $query->latest()->paginate($perPage);
    }

    public function show(Request $request, string $issueId)
    {
        $issue = Issue::with([
            'project', 'type', 'status', 'assignee', 'reporter', 'creator',
            'subtasks', 'epic', 'comments.user', 'attachments.file',
            'links.source', 'links.target', 'links.creator',
            'incomingLinks.source', 'incomingLinks.target', 'incomingLinks.creator',
        ])->findOrFail($issueId);

        if (!Auth::user()->isAdmin() && !$issue->project?->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        return response()->json($issue);
    }

    public function store(StoreIssueRequest $request)
    {
        $validated = $request->validated();

        $project = Project::where('key', $validated['project_key'])->firstOrFail();

        $user = Auth::user();
        if (!$user->isAdmin() && !$project->hasPermission($user, 'create_issues')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        return DB::transaction(function () use ($validated, $project) {
            // Generate issue key using max sequence for this project
            $key = $this->generateIssueKey($project);

            $issue = Issue::create([
                'project_id' => $project->id,
                'issue_type_id' => $validated['issue_type_id']
                    ?? IssueType::query()->orderBy('id')->value('id'),
                'status_id' => $validated['status_id']
                    ?? IssueStatus::query()->orderBy('sort_order')->orderBy('id')->value('id'),
                'created_by' => Auth::id(),
                'assigned_to' => $validated['assignee_id'] ?? null,
                'reporter_id' => Auth::id(),
                'parent_id' => $validated['parent_id'] ?? null,
                'epic_id' => $validated['epic_id'] ?? null,
                'summary' => $validated['summary'],
                'description' => HtmlSanitizer::clean($validated['description'] ?? null),
                'key' => $key,
                'priority' => $validated['priority'] ?? 'medium',
                'start_date' => $validated['start_date'] ?? null,
                'due_date' => $validated['due_date'] ?? null,
                'story_points' => $validated['story_points'] ?? null,
                'custom_fields' => $validated['custom_fields'] ?? null,
            ]);

            AuditWriter::record($issue, 'created', [], [
                'key' => $key,
                'summary' => $validated['summary'],
            ]);

            $this->automations->issueCreated($issue);

            return response()->json($issue->load([
                'project', 'type', 'status', 'assignee', 'reporter', 'creator',
            ]), 201);
        });
    }

    public function moveInBoard(Request $request, string $issueId)
    {
        $validated = $request->validate([
            'column_id' => 'nullable|exists:board_columns,id',
            'status_id' => 'nullable|exists:issue_statuses,id',
            'order' => 'nullable|integer',
        ]);

        $issue = Issue::findOrFail($issueId);

        // If status_id is provided directly (virtual columns like Review)
        if (!empty($validated['status_id'])) {
            $oldStatusId = $issue->status_id;

            if ((int) $oldStatusId !== (int) $validated['status_id']) {
                $board = Board::where('project_id', $issue->project_id)->orderBy('id')->first();
                if ($board && ($violation = $this->enforceWipLimit($board, (int) $validated['status_id'], $issue->id)) !== null) {
                    return response()->json($violation, 409);
                }
            }

            $issue->update([
                'status_id' => $validated['status_id'],
            ]);
        } else {
            $column = BoardColumn::findOrFail($validated['column_id']);
            $board = $column->board;

            $oldStatusId = $issue->status_id;

            if ((int) $oldStatusId !== (int) $column->status_id
                && ($violation = $this->enforceWipLimit($board, (int) $column->status_id, $issue->id)) !== null) {
                return response()->json($violation, 409);
            }

            // Update issue status and sequence
            $issue->update([
                'status_id' => $column->status_id,
                'sequence' => $validated['order'],
            ]);

            // Broadcast board update
            event(new BoardUpdated($board, 'issue_moved', $validated['column_id'], $issue->id, $validated['order']));
        }

        if ((int) $oldStatusId !== (int) $issue->status_id) {
            AuditWriter::record($issue, 'status_changed', [
                'status_id' => ['old' => $oldStatusId, 'new' => $issue->status_id],
            ]);

            $this->automations->statusChanged($issue, (int) $oldStatusId, (int) $issue->status_id);
        }

        // Broadcast issue update
        event(new IssueUpdated($issue, 'status_changed', $board->id ?? 0));

        return response()->json($issue->load(['project', 'type', 'status', 'assignee']));
    }

    public function update(UpdateIssueRequest $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);

        $user = Auth::user();
        if (!$user->isAdmin() && !$issue->project->hasPermission($user, 'edit_issues')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validated();

        if (array_key_exists('description', $validated)) {
            $validated['description'] = HtmlSanitizer::clean($validated['description']);
        }

        // Map assignee_id to assigned_to
        if (isset($validated['assignee_id'])) {
            $validated['assigned_to'] = $validated['assignee_id'];
            unset($validated['assignee_id']);
        }

        $changes = AuditWriter::diff($issue->getAttributes(), $validated);

        $oldStatusId = $issue->status_id;
        $issue->update($validated);

        if (!empty($changes)) {
            AuditWriter::record($issue, 'updated', $changes);
        }

        if ((int) $oldStatusId !== (int) $issue->status_id) {
            $this->automations->statusChanged($issue, (int) $oldStatusId, (int) $issue->status_id);
        }

        return response()->json($issue->load([
            'project', 'type', 'status', 'assignee', 'reporter', 'creator',
        ]));
    }

    public function destroy(Request $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);

        if (!$issue->project->hasPermission(Auth::user(), 'delete_issues')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $issue->delete();

        AuditWriter::record($issue, 'deleted', [], [
            'key' => $issue->key,
            'summary' => $issue->summary,
        ]);

        return response()->json(['message' => 'Issue deleted successfully']);
    }

    public function comments(Request $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);
        if (!Auth::user()->isAdmin() && !$issue->project?->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $comments = IssueComment::where('issue_id', $issueId)
            ->with(['user', 'attachments.file'])
            ->latest()
            ->get();

        $project = $issue->project;

        return response()->json(
            $comments->map(fn (IssueComment $comment) => $this->presentComment($comment, $project)),
        );
    }

    public function activity(Request $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);
        if (!Auth::user()->isAdmin() && !$issue->project?->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $perPage = min(100, max(1, (int) $request->input('per_page', 50)));

        $logs = ActivityLog::where('subject_type', $issue->getMorphClass())
            ->where('subject_id', $issue->id)
            ->with('user')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($logs->items())->map(fn (ActivityLog $log) => $this->presentActivity($log))->values(),
            'meta' => [
                'current_page' => $logs->currentPage(),
                'last_page' => $logs->lastPage(),
                'per_page' => $logs->perPage(),
                'total' => $logs->total(),
            ],
        ]);
    }

    public function addComment(StoreIssueCommentRequest $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);
        if (!Auth::user()->isAdmin() && !$issue->project?->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validated();
        $body = HtmlSanitizer::clean($validated['body']) ?? $validated['body'];

        $comment = DB::transaction(function () use ($issue, $validated, $body) {
            $comment = IssueComment::create([
                'issue_id' => $issue->id,
                'user_id' => Auth::id(),
                'parent_comment_id' => $validated['parent_comment_id'] ?? null,
                'body' => $body,
                'time_spent_hours' => $validated['time_spent_hours'] ?? null,
            ]);

            foreach (array_unique($validated['attachment_file_ids'] ?? []) as $fileId) {
                IssueAttachment::create([
                    'issue_id' => $issue->id,
                    'comment_id' => $comment->id,
                    'file_id' => $fileId,
                    'uploaded_by' => Auth::id(),
                ]);
            }

            return $comment;
        });

        event(new NewComment($comment, 'issue'));

        AuditWriter::record($issue, 'comment_added', [], [
            'comment_id' => $comment->id,
            'attachments' => count($validated['attachment_file_ids'] ?? []),
        ]);

        $excerpt = Str::limit(trim((string) preg_replace('/\s+/', ' ', strip_tags($body))), 140);
        $mentioned = MentionParser::stripSelf(
            MentionParser::resolve(MentionParser::extract($body), $issue->project),
            Auth::id(),
        );

        foreach ($mentioned as $user) {
            $this->notifications->notify(
                $user->id,
                'issue_mention',
                $issue,
                [
                    'issue_id' => $issue->id,
                    'issue_key' => $issue->key,
                    'summary' => $issue->summary,
                    'comment_id' => $comment->id,
                    'excerpt' => $excerpt,
                    'url' => '/issues/' . $issue->id,
                ],
                Auth::id(),
                "Упоминание в {$issue->key}",
                $excerpt !== '' ? $excerpt : 'Вас упомянули в комментарии',
            );
        }

        return response()->json(
            $this->presentComment($comment->load(['user', 'attachments.file']), $issue->project),
            201,
        );
    }

    public function updateComment(StoreIssueCommentRequest $request, string $issueId, string $commentId)
    {
        $issue = Issue::findOrFail($issueId);
        $comment = IssueComment::where('issue_id', $issueId)->findOrFail($commentId);

        if (!$this->canManageComment($issue, $comment)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $body = HtmlSanitizer::clean($request->validated()['body']) ?? $request->validated()['body'];
        $oldBody = $comment->body;
        $comment->update(['body' => $body]);

        if ($oldBody !== $body) {
            AuditWriter::record($issue, 'comment_updated', [
                'body' => [
                    'old' => Str::limit((string) $oldBody, 500),
                    'new' => Str::limit((string) $body, 500),
                ],
            ], ['comment_id' => $comment->id]);
        }

        return response()->json(
            $this->presentComment($comment->fresh(['user', 'attachments.file']), $issue->project),
        );
    }

    public function deleteComment(Request $request, string $issueId, string $commentId)
    {
        $issue = Issue::findOrFail($issueId);
        $comment = IssueComment::where('issue_id', $issueId)->findOrFail($commentId);

        if (!$this->canManageComment($issue, $comment)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        DB::transaction(function () use ($comment) {
            IssueAttachment::where('comment_id', $comment->id)->delete();
            $comment->delete();
        });

        AuditWriter::record($issue, 'comment_deleted', [], ['comment_id' => (int) $commentId]);

        return response()->json(['message' => 'Комментарий удалён']);
    }

    public function addCommentAttachment(Request $request, string $issueId, string $commentId)
    {
        $issue = Issue::findOrFail($issueId);
        if (!Auth::user()->isAdmin() && !$issue->project?->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'file_id' => 'required|exists:files,id',
        ]);

        $comment = IssueComment::where('issue_id', $issueId)->findOrFail($commentId);

        $attachment = IssueAttachment::create([
            'issue_id' => $issue->id,
            'comment_id' => $comment->id,
            'file_id' => $validated['file_id'],
            'uploaded_by' => Auth::id(),
        ]);

        AuditWriter::record($issue, 'attachment_added', [], [
            'file_id' => (int) $validated['file_id'],
            'comment_id' => $comment->id,
        ]);

        return response()->json($attachment->load('file'), 201);
    }

    public function mentions(Request $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);
        if (!Auth::user()->isAdmin() && !$issue->project?->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $q = trim((string) $request->input('q', ''));
        $project = $issue->project;

        $users = User::query()
            ->where('status', 'active')
            ->where('id', '!=', Auth::id())
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('email', 'ilike', "%{$q}%")
                        ->orWhere('first_name', 'ilike', "%{$q}%")
                        ->orWhere('last_name', 'ilike', "%{$q}%");
                });
            })
            ->orderBy('first_name')
            ->limit(10)
            ->get();

        if ($project !== null) {
            $users = $users->filter(fn (User $user) => $project->canView($user))->values();
        }

        return response()->json(
            $users->map(fn (User $user) => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
            ]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function presentActivity(ActivityLog $log): array
    {
        $changes = [];

        foreach (($log->changes ?? []) as $field => $diff) {
            if (is_array($diff) && array_key_exists('old', $diff) && array_key_exists('new', $diff)) {
                $changes[] = [
                    'field' => $field,
                    'old' => $diff['old'],
                    'new' => $diff['new'],
                ];
            }
        }

        return [
            'id' => $log->id,
            'action' => $log->action,
            'description' => $log->description,
            'changes' => $changes,
            'properties' => $log->properties,
            'created_at' => $log->created_at,
            'user' => $log->user ? [
                'id' => $log->user->id,
                'full_name' => $log->user->full_name,
                'initials' => $log->user->initials,
                'avatar' => $log->user->avatar,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentComment(IssueComment $comment, ?Project $project): array
    {
        $mentioned = MentionParser::stripSelf(
            MentionParser::resolve(MentionParser::extract($comment->body), $project),
            $comment->user_id,
        );

        return [
            'id' => $comment->id,
            'issue_id' => $comment->issue_id,
            'parent_comment_id' => $comment->parent_comment_id,
            'body' => $comment->body,
            'rendered_body' => $comment->rendered_body,
            'time_spent_hours' => $comment->time_spent_hours,
            'created_at' => $comment->created_at,
            'updated_at' => $comment->updated_at,
            'user' => $comment->user ? [
                'id' => $comment->user->id,
                'full_name' => $comment->user->full_name,
                'initials' => $comment->user->initials,
                'avatar' => $comment->user->avatar,
            ] : null,
            'attachments' => $comment->attachments->map(fn (IssueAttachment $attachment) => [
                'id' => $attachment->id,
                'file_id' => $attachment->file_id,
                'file' => $attachment->file ? [
                    'id' => $attachment->file->id,
                    'original_name' => $attachment->file->original_name,
                    'mime_type' => $attachment->file->mime_type,
                    'size' => $attachment->file->size,
                ] : null,
            ])->values(),
            'mentioned_users' => $mentioned->map(fn (User $user) => [
                'id' => $user->id,
                'full_name' => $user->full_name,
                'email' => $user->email,
            ])->values(),
        ];
    }

    private function canManageComment(Issue $issue, IssueComment $comment): bool
    {
        $user = Auth::user();

        if ((int) $comment->user_id === (int) $user->id || $user->isAdmin()) {
            return true;
        }

        return $issue->project?->hasPermission($user, 'edit_issues') ?? false;
    }

    public function addAttachment(Request $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);
        if (!Auth::user()->isAdmin() && !$issue->project?->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'file_id' => 'required|exists:files,id',
        ]);

        $attachment = IssueAttachment::create([
            'issue_id' => $issueId,
            'file_id' => $validated['file_id'],
            'uploaded_by' => Auth::id(),
        ]);

        AuditWriter::record($issue, 'attachment_added', [], [
            'file_id' => (int) $validated['file_id'],
            'attachment_id' => $attachment->id,
        ]);

        return response()->json($attachment, 201);
    }

    public function subtasks(Request $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);
        if (!Auth::user()->isAdmin() && !$issue->project?->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $subtasks = $issue->subtasks()
            ->with(['type', 'status', 'assignee'])
            ->orderBy('sequence')
            ->orderBy('id')
            ->get();

        return response()->json($subtasks);
    }

    public function storeSubtask(Request $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);
        $user = Auth::user();

        if (!$user->isAdmin() && !$issue->project?->hasPermission($user, 'edit_issues')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'summary' => 'required|string|max:500',
            'description' => 'nullable|string',
            'assignee_id' => 'nullable|exists:users,id',
            'status_id' => 'nullable|exists:issue_statuses,id',
            'issue_type_id' => 'nullable|exists:issue_types,id',
            'priority' => 'nullable|in:lowest,low,medium,high,highest',
        ]);

        $subtask = DB::transaction(function () use ($issue, $validated) {
            $subtask = Issue::create([
                'project_id' => $issue->project_id,
                'issue_type_id' => $validated['issue_type_id']
                    ?? IssueType::query()->orderBy('id')->value('id'),
                'status_id' => $validated['status_id'] ?? $issue->status_id,
                'created_by' => Auth::id(),
                'assigned_to' => $validated['assignee_id'] ?? null,
                'reporter_id' => Auth::id(),
                'parent_id' => $issue->id,
                'summary' => $validated['summary'],
                'description' => HtmlSanitizer::clean($validated['description'] ?? null),
                'priority' => $validated['priority'] ?? 'medium',
                'key' => $this->generateIssueKey($issue->project),
            ]);

            AuditWriter::record($subtask, 'created', [], [
                'key' => $subtask->key,
                'summary' => $subtask->summary,
                'parent_id' => $issue->id,
            ]);

            return $subtask;
        });

        if ($subtask->assigned_to && (int) $subtask->assigned_to !== (int) Auth::id()) {
            $this->notifications->notify(
                $subtask->assigned_to,
                'issue_subtask_assigned',
                $subtask,
                [
                    'issue_id' => $subtask->id,
                    'issue_key' => $subtask->key,
                    'summary' => $subtask->summary,
                    'parent_id' => $issue->id,
                    'parent_key' => $issue->key,
                    'url' => '/issues/' . $subtask->id,
                ],
                Auth::id(),
                "Новая подзадача {$subtask->key}",
                $subtask->summary,
            );
        }

        return response()->json($subtask->load(['type', 'status', 'assignee']), 201);
    }

    private function enforceWipLimit(Board $board, int $statusId, int $movingIssueId): ?array
    {
        if (! $board->wip_enforcement) {
            return null;
        }

        $column = BoardColumn::where('board_id', $board->id)
            ->where('status_id', $statusId)
            ->first();

        if (! $column || $column->wip_limit === null) {
            return null;
        }

        $count = Issue::where('project_id', $board->project_id)
            ->where('status_id', $statusId)
            ->where('id', '!=', $movingIssueId)
            ->count();

        if ($count >= $column->wip_limit) {
            return [
                'message' => "Лимит WIP для этапа «{$column->name}» превышен",
                'wip_limit' => $column->wip_limit,
                'count' => $count,
            ];
        }

        return null;
    }

    private function generateIssueKey(Project $project): string
    {
        $lastIssue = Issue::where('project_id', $project->id)->orderByDesc('id')->first();

        if ($lastIssue) {
            $parts = explode('-', $lastIssue->key);
            $sequence = (int) (end($parts)) + 1;
        } else {
            $sequence = 1;
        }

        return "{$project->key}-{$sequence}";
    }

    public function moveIssue(Request $request, string $issueId)
    {
        $validated = $request->validate([
            'status_id' => 'nullable|exists:issue_statuses,id',
            'column_id' => 'nullable|integer',
            'order' => 'nullable|integer',
        ]);

        $issue = Issue::findOrFail($issueId);

        if (!Auth::user()->isAdmin() && !$issue->project?->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        if (isset($validated['status_id'])) {
            $oldStatusId = $issue->status_id;

            if ((int) $oldStatusId !== (int) $validated['status_id']) {
                $board = Board::where('project_id', $issue->project_id)->orderBy('id')->first();
                if ($board && ($violation = $this->enforceWipLimit($board, (int) $validated['status_id'], $issue->id)) !== null) {
                    return response()->json($violation, 409);
                }
            }

            $issue->update(['status_id' => $validated['status_id']]);

            if ((int) $oldStatusId !== (int) $issue->status_id) {
                AuditWriter::record($issue, 'status_changed', [
                    'status_id' => ['old' => $oldStatusId, 'new' => $issue->status_id],
                ]);
            }
        }

        event(new IssueUpdated($issue, 'status_changed'));

        return response()->json($issue->load(['project', 'type', 'status', 'assignee']));
    }

    public function bulkUpdate(Request $request)
    {
        $validated = $request->validate([
            'issue_ids' => 'required|array',
            'issue_ids.*' => 'exists:issues,id',
            'updates' => 'required|array',
        ]);

        $count = Issue::whereIn('id', $validated['issue_ids'])->update($validated['updates']);

        return response()->json(['message' => "{$count} issues updated"]);
    }

    public function transition(Request $request, string $issueId)
    {
        $validated = $request->validate([
            'comment_id' => 'nullable|exists:issue_comments,id',
        ]);

        $query = IssueComment::where('issue_id', $issueId);

        if (!empty($validated['comment_id'])) {
            $query->where(function ($q) use ($validated) {
                $q->where('id', $validated['comment_id'])
                  ->orWhere('parent_comment_id', $validated['comment_id']);
            });
        }

        $query->where(function ($q) {
            $q->where('user_id', Auth::id())
              ->orWhereHas('issue', function ($query) {
                  $query->whereHas('project', function ($query) {
                      $query->whereHas('members', function ($query) {
                          $query->where('user_id', Auth::id())
                                ->where('role', 'admin');
                      });
                  });
              });
        });

        $comment = $query->firstOrFail();
        $comment->delete();

        return response()->json(['message' => 'Comment deleted']);
    }

    private const LINK_INVERSE = [
        'blocks' => 'is_blocked_by',
        'is_blocked_by' => 'blocks',
        'relates_to' => 'relates_to',
        'is_related_to' => 'is_related_to',
        'duplicates' => 'is_duplicated_by',
        'is_duplicated_by' => 'duplicates',
        'clones' => 'is_cloned_by',
        'is_cloned_by' => 'clones',
    ];

    public function links(Request $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);
        if (!Auth::user()->isAdmin() && !$issue->project?->canView(Auth::user())) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $links = IssueLink::with([
            'source.type', 'source.status', 'target.type', 'target.status', 'creator',
        ])
            ->where(function ($q) use ($issue) {
                $q->where('source_issue_id', $issue->id)
                  ->orWhere('target_issue_id', $issue->id);
            })
            ->get();

        return response()->json(
            $links->map(fn (IssueLink $link) => $this->presentLink($link, $issue))->values(),
        );
    }

    public function addLink(Request $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);
        $user = Auth::user();

        if (!$user->isAdmin() && !$issue->project?->hasPermission($user, 'edit_issues')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'target_issue_id' => 'required|exists:issues,id',
            'link_type' => 'required|string|in:blocks,is_blocked_by,relates_to,is_related_to,duplicates,is_duplicated_by,clones,is_cloned_by',
        ]);

        $target = Issue::findOrFail($validated['target_issue_id']);

        if ((int) $target->id === (int) $issue->id) {
            return response()->json(['message' => 'Нельзя связать задачу с самой собой'], 422);
        }

        if ((int) $target->project_id !== (int) $issue->project_id) {
            return response()->json(['message' => 'Можно связывать только задачи одного проекта'], 422);
        }

        $type = $validated['link_type'];
        $inverse = self::LINK_INVERSE[$type] ?? null;

        $exists = IssueLink::where('source_issue_id', $issue->id)
            ->where('target_issue_id', $target->id)
            ->where('link_type', $type)
            ->exists()
            || ($inverse !== null && IssueLink::where('source_issue_id', $target->id)
                ->where('target_issue_id', $issue->id)
                ->where('link_type', $inverse)
                ->exists());

        if ($exists) {
            return response()->json(['message' => 'Связь уже существует'], 409);
        }

        $link = IssueLink::create([
            'source_issue_id' => $issue->id,
            'target_issue_id' => $target->id,
            'link_type' => $type,
            'created_by' => Auth::id(),
        ]);

        AuditWriter::record($issue, 'link_added', [], [
            'link_id' => $link->id,
            'link_type' => $type,
            'target_issue_id' => $target->id,
            'target_key' => $target->key,
        ]);

        $recipient = $target->assigned_to ?? $target->reporter_id;
        if ($recipient && (int) $recipient !== (int) Auth::id()) {
            $this->notifications->notify(
                $recipient,
                'issue_linked',
                $target,
                [
                    'issue_id' => $target->id,
                    'issue_key' => $target->key,
                    'summary' => $target->summary,
                    'linked_issue_id' => $issue->id,
                    'linked_issue_key' => $issue->key,
                    'link_type' => $type,
                    'url' => '/issues/' . $target->id,
                ],
                Auth::id(),
                "Связь с {$issue->key}",
                "Задача {$issue->key} связана с {$target->key}",
            );
        }

        return response()->json(
            $this->presentLink($link->load(['source.type', 'source.status', 'target.type', 'target.status']), $issue),
            201,
        );
    }

    public function removeLink(Request $request, string $issueId, string $linkId)
    {
        $issue = Issue::findOrFail($issueId);
        $user = Auth::user();

        if (!$user->isAdmin() && !$issue->project?->hasPermission($user, 'edit_issues')) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $link = IssueLink::where('id', $linkId)
            ->where(function ($q) use ($issue) {
                $q->where('source_issue_id', $issue->id)
                  ->orWhere('target_issue_id', $issue->id);
            })
            ->firstOrFail();

        $link->delete();

        AuditWriter::record($issue, 'link_removed', [], [
            'link_id' => (int) $linkId,
            'link_type' => $link->link_type,
        ]);

        return response()->json(['message' => 'Связь удалена']);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentLink(IssueLink $link, Issue $issue): array
    {
        $outgoing = (int) $link->source_issue_id === (int) $issue->id;
        $other = $outgoing ? $link->target : $link->source;
        $type = $outgoing
            ? $link->link_type
            : (self::LINK_INVERSE[$link->link_type] ?? $link->link_type);

        return [
            'id' => $link->id,
            'link_type' => $link->link_type,
            'type' => $type,
            'direction' => $outgoing ? 'outgoing' : 'incoming',
            'created_by' => $link->created_by,
            'created_at' => $link->created_at,
            'issue' => $other ? [
                'id' => $other->id,
                'key' => $other->key,
                'summary' => $other->summary,
                'status' => $other->status ? [
                    'id' => $other->status->id,
                    'name' => $other->status->name,
                    'category' => $other->status->category,
                    'color' => $other->status->color,
                ] : null,
                'type' => $other->type ? [
                    'id' => $other->type->id,
                    'name' => $other->type->name,
                    'color' => $other->type->color,
                ] : null,
            ] : null,
        ];
    }
}
