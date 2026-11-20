<?php

namespace App\Jobs;

use App\Models\File;
use App\Models\Project;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Re-indexes everything whose ACL fields depend on project membership.
 *
 * Scout has no model events for pivot changes, so member add/remove (and a
 * visibility change) must explicitly refresh the denormalised member ids used
 * by the global search filters.
 */
class ResyncProjectSearchIndex implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public int $projectId)
    {
    }

    public function handle(): void
    {
        $project = Project::find($this->projectId);

        if ($project === null) {
            return;
        }

        $project->issues()
            ->with('project.members')
            ->chunkById(200, function ($issues) {
                $issues->each->searchable();
            });

        $fileIds = DB::table('issue_attachments')
            ->join('issues', 'issues.id', '=', 'issue_attachments.issue_id')
            ->where('issues.project_id', $project->id)
            ->pluck('issue_attachments.file_id')
            ->unique()
            ->values();

        if ($fileIds->isNotEmpty()) {
            File::whereIn('id', $fileIds)
                ->chunkById(200, fn ($files) => $files->each->searchable());
        }
    }
}
