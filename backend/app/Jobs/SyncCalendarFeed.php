<?php

namespace App\Jobs;

use App\Models\CalendarFeed;
use App\Services\Calendar\FeedSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class SyncCalendarFeed implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 60;

    public function __construct(public int $feedId)
    {
    }

    public function handle(FeedSyncService $service): void
    {
        $feed = CalendarFeed::find($this->feedId);

        if ($feed === null || ! $feed->is_active) {
            return;
        }

        try {
            $service->sync($feed);
        } catch (Throwable $e) {
            // FeedSyncService already stored last_error; avoid failed-jobs noise.
            Log::warning('Calendar feed sync failed', [
                'feed_id' => $this->feedId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
