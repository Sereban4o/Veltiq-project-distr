<?php

namespace App\Extensions\Storage\Jobs;

use App\Extensions\Storage\Services\StorageTrashService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PruneStorageTrash implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public function handle(StorageTrashService $trash): void
    {
        $trash->prune();
    }
}
