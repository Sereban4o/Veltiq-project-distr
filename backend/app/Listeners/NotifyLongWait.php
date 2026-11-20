<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Tasksbase\NotificationService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Events\LongWaitDetected;
use Throwable;

/**
 * Alerts admins (in-app + push) when a queue's wait time crosses the threshold
 * configured in `config/horizon.php` (`waits`). Horizon only fires the event;
 * without this listener the thresholds would be silent.
 *
 * Alerts are de-duplicated per connection+queue for a short window so a busy
 * queue cannot spam admins on every Horizon loop.
 */
class NotifyLongWait
{
    private const DEDUPE_MINUTES = 15;

    public function __construct(
        private NotificationService $notifications,
    ) {
    }

    public function handle(LongWaitDetected $event): void
    {
        try {
            $key = 'horizon-long-wait:' . sha1($event->connection . '|' . $event->queue);
            if (! Cache::add($key, true, now()->addMinutes(self::DEDUPE_MINUTES))) {
                return;
            }

            $adminIds = User::query()
                ->where('status', 'active')
                ->whereHas('role', fn ($q) => $q->where('name', 'admin'))
                ->pluck('id')
                ->all();

            if ($adminIds === []) {
                return;
            }

            $summary = sprintf(
                'Очередь %s:%s ждёт %d c',
                $event->connection,
                $event->queue,
                (int) $event->seconds,
            );

            $this->notifications->broadcast(
                $adminIds,
                'system',
                ['summary' => $summary],
                null,
                'Долгое ожидание в очереди',
                $summary,
            );
        } catch (Throwable $e) {
            Log::warning('Failed to send long wait alert', ['error' => $e->getMessage()]);
        }
    }
}
