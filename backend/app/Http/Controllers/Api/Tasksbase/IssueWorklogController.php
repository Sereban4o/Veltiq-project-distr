<?php

namespace App\Http\Controllers\Api\Tasksbase;

use App\Http\Controllers\Controller;
use App\Models\Issue;
use App\Models\IssueWorklog;
use App\Services\Tasksbase\TimeTrackingService;
use App\Support\Tasksbase\AuditWriter;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class IssueWorklogController extends Controller
{
    public function __construct(
        private TimeTrackingService $timeTracking,
    ) {
    }

    public function index(Request $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);

        if ($denied = $this->denyUnlessVisible($issue)) {
            return $denied;
        }

        $worklogs = IssueWorklog::where('issue_id', $issue->id)
            ->with('user')
            ->orderByDesc('started_at')
            ->orderByDesc('id')
            ->get();

        $running = $this->timeTracking->runningFor($issue, Auth::user());

        return response()->json([
            'data' => $worklogs->map(fn (IssueWorklog $worklog) => $this->present($worklog))->values(),
            'total_seconds' => (int) $worklogs->where('is_running', false)->sum('seconds'),
            'running' => $running ? $this->present($running) : null,
        ]);
    }

    public function store(Request $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);

        if ($denied = $this->denyUnlessEditable($issue)) {
            return $denied;
        }

        $validated = $request->validate([
            'hours' => 'required|numeric|min:0.01|max:24',
            'comment' => 'nullable|string|max:2000',
            'started_at' => 'nullable|date',
        ]);

        $worklog = $this->timeTracking->logManual(
            $issue,
            Auth::user(),
            (float) $validated['hours'],
            $validated['comment'] ?? null,
            isset($validated['started_at']) ? Carbon::parse($validated['started_at']) : null,
        );

        AuditWriter::record($issue, 'worklog_added', [], [
            'worklog_id' => $worklog->id,
            'seconds' => $worklog->seconds,
        ]);

        return response()->json($this->present($worklog->load('user')), 201);
    }

    public function update(Request $request, string $issueId, string $worklogId)
    {
        $issue = Issue::findOrFail($issueId);
        $worklog = IssueWorklog::where('issue_id', $issue->id)->findOrFail($worklogId);

        if (! $this->canManage($issue, $worklog)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $validated = $request->validate([
            'hours' => 'sometimes|numeric|min:0|max:24',
            'comment' => 'sometimes|nullable|string|max:2000',
        ]);

        if (array_key_exists('hours', $validated)) {
            $validated['seconds'] = (int) round(((float) $validated['hours']) * 3600);
            unset($validated['hours']);
        }

        $worklog->update($validated);
        $this->timeTracking->recalculateSpent($issue);

        return response()->json($this->present($worklog->fresh('user')));
    }

    public function destroy(Request $request, string $issueId, string $worklogId)
    {
        $issue = Issue::findOrFail($issueId);
        $worklog = IssueWorklog::where('issue_id', $issue->id)->findOrFail($worklogId);

        if (! $this->canManage($issue, $worklog)) {
            return response()->json(['message' => 'Permission denied'], 403);
        }

        $worklog->delete();
        $this->timeTracking->recalculateSpent($issue);

        AuditWriter::record($issue, 'worklog_removed', [], [
            'worklog_id' => (int) $worklogId,
        ]);

        return response()->json(['message' => 'Запись удалена']);
    }

    public function running(Request $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);

        if ($denied = $this->denyUnlessVisible($issue)) {
            return $denied;
        }

        $running = $this->timeTracking->runningFor($issue, Auth::user());

        return response()->json([
            'running' => $running ? $this->present($running->load('user')) : null,
        ]);
    }

    public function startTimer(Request $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);

        if ($denied = $this->denyUnlessEditable($issue)) {
            return $denied;
        }

        $existing = $this->timeTracking->runningFor($issue, Auth::user());
        if ($existing !== null) {
            return response()->json([
                'message' => 'Таймер уже запущен',
                'data' => $this->present($existing->load('user')),
            ], 409);
        }

        $worklog = $this->timeTracking->start($issue, Auth::user());

        return response()->json($this->present($worklog->load('user')), 201);
    }

    public function stopTimer(Request $request, string $issueId)
    {
        $issue = Issue::findOrFail($issueId);

        if ($denied = $this->denyUnlessEditable($issue)) {
            return $denied;
        }

        $running = $this->timeTracking->runningFor($issue, Auth::user());
        if ($running === null) {
            return response()->json(['message' => 'Таймер не запущен'], 404);
        }

        $worklog = $this->timeTracking->stop($running);

        if ($worklog->seconds > 0) {
            AuditWriter::record($issue, 'worklog_added', [], [
                'worklog_id' => $worklog->id,
                'seconds' => $worklog->seconds,
            ]);
        }

        return response()->json($this->present($worklog->load('user')));
    }

    private function canManage(Issue $issue, IssueWorklog $worklog): bool
    {
        $user = Auth::user();

        if ($user->isAdmin() || (int) $worklog->user_id === (int) $user->id) {
            return true;
        }

        return $issue->project?->hasPermission($user, 'edit_issues') ?? false;
    }

    private function denyUnlessVisible(Issue $issue)
    {
        if (Auth::user()->isAdmin() || $issue->project?->canView(Auth::user())) {
            return null;
        }

        return response()->json(['message' => 'Permission denied'], 403);
    }

    private function denyUnlessEditable(Issue $issue)
    {
        $user = Auth::user();

        if ($user->isAdmin() || $issue->project?->hasPermission($user, 'edit_issues')) {
            return null;
        }

        return response()->json(['message' => 'Permission denied'], 403);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(IssueWorklog $worklog): array
    {
        return [
            'id' => $worklog->id,
            'issue_id' => $worklog->issue_id,
            'user_id' => $worklog->user_id,
            'seconds' => $worklog->seconds,
            'hours' => round($worklog->seconds / 3600, 2),
            'comment' => $worklog->comment,
            'started_at' => $worklog->started_at,
            'ended_at' => $worklog->ended_at,
            'is_running' => $worklog->is_running,
            'user' => $worklog->user ? [
                'id' => $worklog->user->id,
                'full_name' => $worklog->user->full_name,
                'initials' => $worklog->user->initials,
                'avatar' => $worklog->user->avatar,
            ] : null,
        ];
    }
}
