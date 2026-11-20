<?php

namespace App\Console\Commands;

use App\Models\Notification;
use App\Models\Setting;
use Illuminate\Console\Command;

class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune
        {--days= : Override retention days}
        {--dry-run : Show what would be deleted without touching data}';

    protected $description = 'Delete notifications older than the configured retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days')
            ?: Setting::get('notification_retention_days')
            ?: config('notifications.retention.notifications_days', 90));

        $cutoff = now()->subDays($days);
        $query = Notification::where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $count = (clone $query)->count();
            $this->table(['Would delete', 'Older than'], [[$count, $cutoff->toDateTimeString()]]);
            $this->info('Dry run complete (no changes applied).');

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->table(['Deleted', 'Older than'], [[$deleted, $cutoff->toDateTimeString()]]);
        $this->info('Notification pruning complete.');

        return self::SUCCESS;
    }
}
