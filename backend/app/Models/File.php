<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Laravel\Scout\Searchable;

class File extends Model
{
    use HasFactory;
    use Searchable;

    protected $fillable = [
        'uploaded_by', 'original_name', 'stored_name',
        'mime_type', 'size', 'disk', 'path',
        'preview_path', 'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
        'size' => 'integer',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function issueAttachments()
    {
        return $this->hasMany(IssueAttachment::class, 'file_id');
    }

    public function pageAttachments()
    {
        return $this->hasMany(PageAttachment::class, 'file_id');
    }

    public function messageFiles()
    {
        return $this->hasMany(MessageFile::class, 'file_id');
    }

    // ─── Search (Laravel Scout / Meilisearch) ───

    public function searchableAs(): string
    {
        return 'files';
    }

    public function toSearchableArray(): array
    {
        return [
            'id' => (int) $this->id,
            'original_name' => (string) $this->original_name,
            'mime_type' => $this->mime_type,
            'uploader_id' => $this->uploaded_by ? (int) $this->uploaded_by : null,
            'project_member_ids' => $this->collectMemberIds(
                $this->relationLoaded('issueAttachments')
                    ? $this->issueAttachments->map->issue
                    : $this->issueAttachments()->with('issue.project.members')->get()->map->issue,
                fn (Issue $issue) => $issue->project?->relationLoaded('members')
                    ? $issue->project->members
                    : $issue->project?->members()->get(),
            ),
            'space_member_ids' => $this->collectMemberIds(
                $this->relationLoaded('pageAttachments')
                    ? $this->pageAttachments->map->page
                    : $this->pageAttachments()->with('page.space.members')->get()->map->page,
                fn (Page $page) => $page->space?->relationLoaded('members')
                    ? $page->space->members
                    : $page->space?->members()->get(),
            ),
            'chat_member_ids' => $this->collectMemberIds(
                $this->relationLoaded('messageFiles')
                    ? $this->messageFiles->map->message
                    : $this->messageFiles()->with('message.chat.members')->get()->map->message,
                fn (Message $message) => $message->chat?->relationLoaded('members')
                    ? $message->chat->members
                    : $message->chat?->members()->get(),
            ),
            'created_at' => optional($this->created_at)->timestamp,
        ];
    }

    public static function makeAllSearchableUsing($query)
    {
        return $query->with([
            'issueAttachments.issue.project.members',
            'pageAttachments.page.space.members',
            'messageFiles.message.chat.members',
        ]);
    }

    /**
     * Flatten the member ids of the containers a file is attached to.
     *
     * @param  iterable<mixed>  $containers
     * @param  callable(mixed): mixed  $members
     * @return list<int>
     */
    private function collectMemberIds(iterable $containers, callable $members): array
    {
        $ids = [];

        foreach ($containers as $container) {
            if (! $container) {
                continue;
            }

            foreach ($members($container) ?? [] as $member) {
                $ids[(int) $member->id] = (int) $member->id;
            }
        }

        return array_values($ids);
    }
}
