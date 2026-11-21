<?php

namespace App\Services\Tasksbase;

use App\Models\Sprint;
use App\Models\SprintSnapshot;
use App\Services\Core\CoreClient;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SprintMetricsService
{
    public function __construct(private readonly CoreClient $core)
    {
    }

    /**
     * @return array{total: int, done: int, points_total: float, points_done: float}
     */
    public function stats(Sprint $sprint): array
    {
        $issues = $sprint->issues()->with('status')->get();

        $done = $issues->filter(fn ($issue) => $issue->status?->category === 'done');

        return [
            'total' => $issues->count(),
            'done' => $done->count(),
            'points_total' => round((float) $issues->sum('story_points'), 2),
            'points_done' => round((float) $done->sum('story_points'), 2),
        ];
    }

    public function capture(Sprint $sprint): SprintSnapshot
    {
        $stats = $this->stats($sprint);

        return SprintSnapshot::updateOrCreate(
            [
                'sprint_id' => $sprint->id,
                'snapshot_date' => Carbon::now()->toDateString(),
            ],
            [
                'issues_total' => $stats['total'],
                'issues_done' => $stats['done'],
                'points_total' => $stats['points_total'],
                'points_done' => $stats['points_done'],
            ],
        );
    }

    /**
     * Burndown series: ideal line from the sprint's starting scope down to zero,
     * plus actual remaining points on days that have a snapshot.
     *
     * @return array<string, mixed>
     */
    public function burndown(Sprint $sprint): array
    {
        $start = ($sprint->start_date ?? $sprint->created_at ?? Carbon::now())->copy()->startOfDay();
        $end = ($sprint->end_date ?? Carbon::now())->copy()->startOfDay();

        if ($end->lt($start)) {
            $end = $start->copy();
        }

        $snapshots = $sprint->snapshots()->get()->keyBy(
            fn (SprintSnapshot $snapshot) => $snapshot->snapshot_date->toDateString(),
        );

        $firstSnapshot = $snapshots->sortBy('snapshot_date')->first();
        $totalPoints = $firstSnapshot
            ? (float) $firstSnapshot->points_total
            : $this->stats($sprint)['points_total'];

        $remote = $this->core->sprintBurndown(
            $start->toDateString(),
            $end->toDateString(),
            $totalPoints,
            $snapshots->map(fn (SprintSnapshot $snapshot) => [
                'date' => $snapshot->snapshot_date->toDateString(),
                'points_total' => (float) $snapshot->points_total,
                'points_done' => (float) $snapshot->points_done,
                'issues_total' => $snapshot->issues_total,
                'issues_done' => $snapshot->issues_done,
            ])->values()->all(),
        );

        $series = $remote !== null ? $remote['series'] : $this->localSeries($snapshots, $start, $end, $totalPoints);
        $totalPoints = $remote !== null ? $remote['total_points'] : round($totalPoints, 2);

        return [
            'sprint_id' => $sprint->id,
            'name' => $sprint->name,
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'total_points' => $totalPoints,
            'series' => $series,
            'stats' => $this->stats($sprint),
        ];
    }

    /**
     * Local burndown series, used when the core service is unavailable.
     *
     * @param  Collection<string, SprintSnapshot>  $snapshots
     * @return array<int, array<string, mixed>>
     */
    private function localSeries(Collection $snapshots, CarbonInterface $start, CarbonInterface $end, float $totalPoints): array
    {
        $dayCount = max(1, (int) $start->diffInDays($end));
        $series = [];

        $cursor = $start->copy();
        $index = 0;

        while ($cursor->lte($end) && $index <= 400) {
            $date = $cursor->toDateString();
            $snapshot = $snapshots->get($date);

            $series[] = [
                'date' => $date,
                'ideal' => round(max(0, $totalPoints - ($totalPoints / $dayCount) * $index), 2),
                'remaining' => $snapshot
                    ? round(max(0, (float) $snapshot->points_total - (float) $snapshot->points_done), 2)
                    : null,
                'issues_total' => $snapshot?->issues_total,
                'issues_done' => $snapshot?->issues_done,
            ];

            $cursor->addDay();
            $index++;
        }

        return $series;
    }

    /**
     * Velocity across completed sprints of the same board.
     *
     * @return array<string, mixed>
     */
    public function velocity(Sprint $sprint): array
    {
        $completed = Sprint::where('board_id', $sprint->board_id)
            ->where('status', 'completed')
            ->orderBy('sequence')
            ->get();

        $items = [];
        $completedPoints = [];

        foreach ($completed as $completedSprint) {
            $snapshot = $completedSprint->snapshots()->orderByDesc('snapshot_date')->first();
            $points = $snapshot
                ? (float) $snapshot->points_done
                : $this->stats($completedSprint)['points_done'];

            $completedPoints[] = round($points, 2);

            $items[] = [
                'sprint_id' => $completedSprint->id,
                'name' => $completedSprint->name,
                'sequence' => $completedSprint->sequence,
                'completed_points' => round($points, 2),
            ];
        }

        $average = $this->core->sprintVelocityAverage($completedPoints)
            ?? ($items === []
                ? 0.0
                : round(array_sum($completedPoints) / count($completedPoints), 2));

        return [
            'sprints' => $items,
            'average' => $average,
        ];
    }
}
