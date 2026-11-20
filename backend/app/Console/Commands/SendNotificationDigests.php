<?php

namespace App\Console\Commands;

use App\Services\Tasksbase\NotificationDigestService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class SendNotificationDigests extends Command
{
    protected $signature = 'notifications:send-digests
        {--frequency= : daily|weekly (default: both)}
        {--dry-run : Report what would be sent without sending or advancing windows}';

    protected $description = 'Queue email digests of unread notifications for subscribed users';

    public function handle(NotificationDigestService $service): int
    {
        if (! config('notifications.digest.enabled', true)) {
            $this->warn('Notification digests are disabled (NOTIFICATIONS_DIGEST_ENABLED=false).');

            return self::SUCCESS;
        }

        $frequencies = $this->option('frequency')
            ? [(string) $this->option('frequency')]
            : ['daily', 'weekly'];

        foreach ($frequencies as $frequency) {
            if (! in_array($frequency, ['daily', 'weekly'], true)) {
                $this->error("Unknown frequency: {$frequency}. Allowed: daily, weekly.");

                return self::FAILURE;
            }

            $lock = Cache::lock("notifications.digest.{$frequency}", 600);

            if (! $lock->get()) {
                $this->warn("Digest run for '{$frequency}' is already in progress, skipping.");

                continue;
            }

            try {
                $report = $service->send($frequency, (bool) $this->option('dry-run'));

                $this->table(
                    ['Frequency', 'Users', 'Notifications'],
                    [[$frequency, $report['users'], $report['notifications']]],
                );
            } finally {
                $lock->release();
            }
        }

        $this->info($this->option('dry-run') ? 'Dry run complete.' : 'Digests queued.');

        return self::SUCCESS;
    }
}
