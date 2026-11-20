<?php

namespace App\Listeners;

use App\Models\User;
use App\Services\Tasksbase\NotificationService;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Alerts admins (in-app + push) when a queued job fails.
 *
 * Alerts are de-duplicated per job+message for a short window so a retrying
 * job cannot flood everyone's notifications.
 */
class NotifyFailedJob
{
    private const DEDUPE_MINUTES = 10;

    public function __construct(
        private NotificationService $notifications,
    ) {
    }

    public function handle(JobFailed $event): void
    {
        try {
            $jobName = 'unknown';

            try {
                $jobName = $event->job?->resolveName() ?: 'unknown';
            } catch (Throwable) {
                // Malformed payload: fall back to a generic name.
            }

            $message = Str::limit($event->exception?->getMessage() ?? 'unknown error', 200);

            $key = 'queue-failure-alert:' . sha1($jobName . '|' . $message);
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

            $summary = sprintf('Задача %s упала: %s', class_basename($jobName), $message);

            $this->notifications->broadcast(
                $adminIds,
                'system',
                ['summary' => $summary],
                null,
                'Ошибка очереди',
                $summary,
            );
        } catch (Throwable $e) {
            Log::warning('Failed to send queue failure alert', ['error' => $e->getMessage()]);
        }
    }
}
