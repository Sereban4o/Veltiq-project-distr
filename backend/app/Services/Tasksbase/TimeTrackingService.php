<?php

namespace App\Services\Tasksbase;

use App\Models\Issue;
use App\Models\IssueWorklog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

class TimeTrackingService
{
    /**
     * Start a timer for the user, returning the existing one when already running.
     */
    public function start(Issue $issue, User $user): IssueWorklog
    {
        $running = $this->runningFor($issue, $user);

        if ($running !== null) {
            return $running;
        }

        return IssueWorklog::create([
            'issue_id' => $issue->id,
            'user_id' => $user->id,
            'seconds' => 0,
            'started_at' => Carbon::now(),
            'is_running' => true,
        ]);
    }

    public function stop(IssueWorklog $worklog): IssueWorklog
    {
        if (! $worklog->is_running) {
            return $worklog;
        }

        $endedAt = Carbon::now();
        $elapsed = $worklog->started_at
            ? max(0, $endedAt->diffInSeconds($worklog->started_at))
            : 0;

        $worklog->update([
            'seconds' => $worklog->seconds + $elapsed,
            'ended_at' => $endedAt,
            'is_running' => false,
        ]);

        $this->recalculateSpent($worklog->issue);

        return $worklog->fresh();
    }

    /**
     * Log a completed interval manually.
     */
    public function logManual(
        Issue $issue,
        User $user,
        float $hours,
        ?string $comment = null,
        ?CarbonInterface $startedAt = null,
    ): IssueWorklog {
        $seconds = (int) round($hours * 3600);
        $start = $startedAt ? Carbon::parse($startedAt) : Carbon::now()->subSeconds($seconds);

        $worklog = IssueWorklog::create([
            'issue_id' => $issue->id,
            'user_id' => $user->id,
            'seconds' => $seconds,
            'started_at' => $start,
            'ended_at' => $start->copy()->addSeconds($seconds),
            'is_running' => false,
            'comment' => $comment,
        ]);

        $this->recalculateSpent($issue);

        return $worklog;
    }

    public function runningFor(Issue $issue, User $user): ?IssueWorklog
    {
        return IssueWorklog::where('issue_id', $issue->id)
            ->where('user_id', $user->id)
            ->where('is_running', true)
            ->first();
    }

    /**
     * Roll the sum of all worklogs into issues.time_spent_hours.
     */
    public function recalculateSpent(Issue $issue): void
    {
        $seconds = (int) IssueWorklog::where('issue_id', $issue->id)
            ->where('is_running', false)
            ->sum('seconds');

        $issue->forceFill([
            'time_spent_hours' => round($seconds / 3600, 2),
        ])->save();
    }
}
