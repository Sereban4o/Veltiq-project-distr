<?php

namespace App\Console\Commands;

use App\Models\LoginLog;
use Illuminate\Console\Command;

class PruneLoginLogs extends Command
{
    protected $signature = 'login-logs:prune
        {--days= : Override retention days}
        {--dry-run : Show what would be deleted without touching data}';

    protected $description = 'Delete login-journal entries older than the retention window';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?: config('audit.login_retention_days', 180));
        $cutoff = now()->subDays($days);

        $query = LoginLog::where('created_at', '<', $cutoff);

        if ($this->option('dry-run')) {
            $count = (clone $query)->count();
            $this->table(['Would delete', 'Older than'], [[$count, $cutoff->toDateTimeString()]]);
            $this->info('Dry run complete (no changes applied).');

            return self::SUCCESS;
        }

        $deleted = $query->delete();
        $this->table(['Deleted', 'Older than'], [[$deleted, $cutoff->toDateTimeString()]]);
        $this->info('Login journal pruning complete.');

        return self::SUCCESS;
    }
}
