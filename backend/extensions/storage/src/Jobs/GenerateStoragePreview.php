<?php

namespace App\Extensions\Storage\Jobs;

use App\Models\StorageMeta;
use App\Extensions\Storage\Services\StoragePreviewService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class GenerateStoragePreview implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public int $timeout = 120;

    public int $backoff = 10;

    public function __construct(public int $entryId)
    {
    }

    public function handle(StoragePreviewService $previews): void
    {
        $entry = StorageMeta::query()->find($this->entryId);
        if ($entry === null) {
            return;
        }

        $previews->generate($entry);
    }
}
