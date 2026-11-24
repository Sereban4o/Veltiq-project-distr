<?php

namespace App\Extensions\Storage\Jobs;

use App\Models\StorageAuditLog;
use App\Extensions\Storage\Services\StorageSettings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PruneStorageAudit implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 300;

    public function handle(StorageSettings $settings): void
    {
        $days = $settings->int('audit_retention_days', 180);

        StorageAuditLog::query()
            ->where('created_at', '<', now()->subDays(max(1, $days)))
            ->delete();
    }
}
