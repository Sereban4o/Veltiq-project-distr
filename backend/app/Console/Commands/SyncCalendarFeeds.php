<?php

namespace App\Console\Commands;

use App\Jobs\SyncCalendarFeed;
use App\Models\CalendarFeed;
use Illuminate\Console\Command;

class SyncCalendarFeeds extends Command
{
    protected $signature = 'calendar:sync-feeds';

    protected $description = 'Queue sync jobs for external calendar feeds that are due';

    public function handle(): int
    {
        $queued = 0;

        CalendarFeed::query()
            ->where('is_active', true)
            ->get()
            ->each(function (CalendarFeed $feed) use (&$queued) {
                $due = $feed->last_synced_at === null
                    || $feed->last_synced_at->lte(now()->subMinutes($feed->sync_interval_minutes));

                if ($due) {
                    SyncCalendarFeed::dispatch($feed->id);
                    $queued++;
                }
            });

        $this->info("Queued {$queued} calendar feed(s) for sync");

        return self::SUCCESS;
    }
}
