<?php

namespace App\Jobs;

use App\Services\Integration\OneC\CounterpartySyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Bulk import of the 1С counterparties catalogue, executed on the queue so the
 * admin request never blocks on a large SOAP export (avoids 504 timeouts).
 */
class SyncCounterpartiesFrom1C implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Hard cap for the whole import, seconds. */
    public int $timeout = 900;

    /** A single attempt: a partial import is safe (upsert by ref_1c). */
    public int $tries = 1;

    public function __construct(public ?int $ownerId = null)
    {
    }

    public function handle(CounterpartySyncService $service): void
    {
        $service->syncAll($this->ownerId);
    }
}
