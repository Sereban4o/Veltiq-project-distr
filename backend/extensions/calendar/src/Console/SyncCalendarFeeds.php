<?php

namespace App\Extensions\Calendar\Console;

use App\Extensions\Calendar\Jobs\SyncCalendarFeed;
use App\Extensions\Calendar\Models\CalendarFeed;
use App\Services\Extensions\ExtensionRegistry;
use Illuminate\Console\Command;

class SyncCalendarFeeds extends Command
{
    protected $signature = 'calendar:sync-feeds';

    protected $description = 'Queue sync jobs for external calendar feeds that are due';

    public function handle(ExtensionRegistry $registry): int
    {
        // The command belongs to the `calendar` module: a disabled module must
        // not schedule work (the scheduler also skips it).
        if (! $registry->isEnabled('calendar')) {
            $this->info('Модуль calendar выключен: синхронизация пропущена');

            return self::SUCCESS;
        }

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
