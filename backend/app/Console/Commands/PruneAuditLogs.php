<?php

namespace App\Console\Commands;

use App\Models\ActivityLog;
use Illuminate\Console\Command;

class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune
        {--days= : Override retention days}
        {--dry-run : Show what would be deleted without touching data}';

    protected $description = 'Delete activity-log entries older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('audit.retention_days', 365));
        $cutoff = now()->subDays($days);

        $query = ActivityLog::where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $count = (clone $query)->count();
            $this->table(['Would delete', 'Older than'], [[$count, $cutoff->toDateTimeString()]]);
            $this->info('Dry run complete (no changes applied).');

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->table(['Deleted', 'Older than'], [[$deleted, $cutoff->toDateTimeString()]]);
        $this->info('Audit log pruning complete.');

        return self::SUCCESS;
    }
}
