<?php

namespace App\Http\Controllers\Api\Tasksbase;

use App\Http\Controllers\Controller;
use App\Models\IssueWorklog;
use App\Models\User;
use App\Services\Core\CoreClient;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Weekly timesheet: worklogs of a user grouped by day and issue, plus a CSV
 * export. A user may only read their own timesheet; admins may read anyone's.
 */
class TimesheetController extends Controller
{
    public function __construct(private readonly CoreClient $core)
    {
    }

    public function index(Request $request): JsonResponse
    {
        [$from, $to, $user] = $this->resolve($request);

        $worklogs = $this->query($from, $to, $user->id);

        $report = $this->core->timesheet($worklogs->map(function (IssueWorklog $worklog): array {
            return [
                'id' => (int) $worklog->id,
                'issue_id' => (int) $worklog->issue_id,
                'issue_key' => $worklog->issue?->key,
                'summary' => $worklog->issue?->summary,
                'project_key' => $worklog->issue?->project?->key,
                'seconds' => (int) $worklog->seconds,
                'comment' => $worklog->comment,
                'is_running' => (bool) $worklog->is_running,
                'started_at' => optional($worklog->started_at)->toIso8601String(),
                'date' => optional($worklog->started_at)->toDateString()
                    ?? optional($worklog->created_at)->toDateString()
                    ?? '',
            ];
        })->all());

        if ($report === null) {
            $report = $this->aggregateLocally($worklogs);
        }

        return response()->json([
            'data' => [
                'from' => $from->toDateString(),
                'to' => $to->toDateString(),
                'user' => [
                    'id' => (int) $user->id,
                    'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                ],
                'total_seconds' => $report['total_seconds'],
                'total_hours' => $report['total_hours'],
                'days' => $report['days'],
            ],
        ]);
    }

    /**
     * Reference aggregation used when the core service is off.
     *
     * @param  \Illuminate\Database\Eloquent\Collection<int, IssueWorklog>  $worklogs
     * @return array{total_seconds:int,total_hours:float,days:array<int,array<string,mixed>>}
     */
    private function aggregateLocally($worklogs): array
    {
        $days = [];
        $total = 0;

        foreach ($worklogs as $worklog) {
            $date = optional($worklog->started_at)->toDateString() ?? optional($worklog->created_at)->toDateString();
            if ($date === null) {
                continue;
            }

            $days[$date] ??= ['date' => $date, 'seconds' => 0, 'entries' => []];
            $seconds = (int) $worklog->seconds;
            $days[$date]['seconds'] += $seconds;
            $total += $seconds;

            $days[$date]['entries'][] = [
                'id' => (int) $worklog->id,
                'issue_id' => (int) $worklog->issue_id,
                'issue_key' => $worklog->issue?->key,
                'summary' => $worklog->issue?->summary,
                'project_key' => $worklog->issue?->project?->key,
                'seconds' => $seconds,
                'hours' => round($seconds / 3600, 2),
                'comment' => $worklog->comment,
                'is_running' => (bool) $worklog->is_running,
            ];
        }

        ksort($days);

        return [
            'total_seconds' => $total,
            'total_hours' => round($total / 3600, 2),
            'days' => array_values($days),
        ];
    }

    public function export(Request $request): StreamedResponse
    {
        [$from, $to, $user] = $this->resolve($request);
        $worklogs = $this->query($from, $to, $user->id);

        return response()->streamDownload(function () use ($worklogs): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['date', 'project', 'issue', 'summary', 'hours', 'comment']);

            foreach ($worklogs as $worklog) {
                fputcsv($out, [
                    optional($worklog->started_at)->toDateString(),
                    $worklog->issue?->project?->key,
                    $worklog->issue?->key,
                    $worklog->issue?->summary,
                    round(((int) $worklog->seconds) / 3600, 2),
                    $worklog->comment,
                ]);
            }

            fclose($out);
        }, "timesheet-{$from->toDateString()}-{$to->toDateString()}.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: User}
     */
    private function resolve(Request $request): array
    {
        $validated = $request->validate([
            'from' => 'sometimes|date_format:Y-m-d',
            'to' => 'sometimes|date_format:Y-m-d',
            'user_id' => 'sometimes|integer|exists:users,id',
        ]);

        $from = isset($validated['from'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['from'])->startOfDay()
            : CarbonImmutable::now()->startOfWeek();
        $to = isset($validated['to'])
            ? CarbonImmutable::createFromFormat('Y-m-d', $validated['to'])->endOfDay()
            : $from->endOfWeek();

        if ($to->lessThan($from)) {
            abort(422, 'to must be after from');
        }

        $userId = isset($validated['user_id']) ? (int) $validated['user_id'] : (int) Auth::id();
        if ($userId !== (int) Auth::id() && ! Auth::user()->isAdmin()) {
            abort(403, 'Можно смотреть только свой табель');
        }

        return [$from, $to, User::findOrFail($userId)];
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<int, IssueWorklog>
     */
    private function query(CarbonImmutable $from, CarbonImmutable $to, int $userId)
    {
        return IssueWorklog::query()
            ->with(['issue:id,key,summary,project_id', 'issue.project:id,key,name'])
            ->where('user_id', $userId)
            ->whereBetween('started_at', [$from, $to])
            ->orderBy('started_at')
            ->orderBy('id')
            ->get();
    }
}
