<?php

namespace App\Jobs;

use App\Models\File;
use App\Models\Space;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Re-indexes pages (and their attachments) after space membership/visibility
 * changes, so global search filters see the updated member ids.
 */
class ResyncSpaceSearchIndex implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $spaceId)
    {
    }

    public function handle(): void
    {
        $space = Space::find($this->spaceId);

        if ($space === null) {
            return;
        }

        $space->pages()
            ->with('space.members')
            ->chunkById(200, function ($pages) {
                $pages->each->searchable();
            });

        $fileIds = DB::table('page_attachments')
            ->join('pages', 'pages.id', '=', 'page_attachments.page_id')
            ->where('pages.space_id', $space->id)
            ->pluck('page_attachments.file_id')
            ->unique()
            ->values();

        if ($fileIds->isNotEmpty()) {
            File::whereIn('id', $fileIds)
                ->chunkById(200, fn ($files) => $files->each->searchable());
        }
    }
}
