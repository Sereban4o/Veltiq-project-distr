<?php

namespace App\Console\Commands;

use App\Models\Sprint;
use App\Services\Tasksbase\SprintMetricsService;
use Illuminate\Console\Command;

class CaptureSprintSnapshots extends Command
{
    protected $signature = 'sprints:snapshot';

    protected $description = 'Снимает ежедневный снимок активных спринтов для burndown-графика';

    public function handle(SprintMetricsService $metrics): int
    {
        $count = 0;

        Sprint::where('status', 'active')->each(function (Sprint $sprint) use ($metrics, &$count) {
            $metrics->capture($sprint);
            $count++;
        });

        $this->info("Снимков спринтов: {$count}");

        return self::SUCCESS;
    }
}
