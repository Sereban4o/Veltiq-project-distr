<?php

namespace App\Jobs;

use App\Models\StorageMeta;
use App\Services\Storage\StorageVersionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PruneStorageVersions implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 600;

    public function handle(StorageVersionService $versions): void
    {
        StorageMeta::query()
            ->where('version', '>', 0)
            ->orderBy('id')
            ->chunkById(200, function ($entries) use ($versions) {
                foreach ($entries as $entry) {
                    $versions->prune($entry);
                }
            });
    }
}
