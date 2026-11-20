<?php

namespace App\Models;

use App\Support\SearchText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

class Issue extends Model
{
    use HasFactory;
    use SoftDeletes;
    use Searchable;

    protected $fillable = [
        'project_id', 'issue_type_id', 'status_id', 'created_by',
        'assigned_to', 'reporter_id', 'parent_id', 'epic_id',
        'summary', 'description', 'key', 'sequence', 'custom_fields',
        'priority', 'start_date', 'due_date', 'story_points',
        'original_estimate_hours', 'time_spent_hours', 'remaining_hours',
    ];

    protected $casts = [
        'custom_fields' => 'array',
        'start_date' => 'date',
        'due_date' => 'date',
        'story_points' => 'integer',
    ];

    // ─── Relationships ───

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function type()
    {
        return $this->belongsTo(IssueType::class, 'issue_type_id');
    }

    /** @return BelongsTo<IssueStatus, $this> */
    public function status(): BelongsTo
    {
        return $this->belongsTo(IssueStatus::class, 'status_id');
    }

    public function parent()
    {
        return $this->belongsTo(Issue::class, 'parent_id');
    }

    public function subtasks()
    {
        return $this->hasMany(Issue::class, 'parent_id');
    }

    /** @return BelongsTo<Issue, $this> */
    public function epic(): BelongsTo
    {
        return $this->belongsTo(Issue::class, 'epic_id');
    }

    public function epicIssues()
    {
        return $this->hasMany(Issue::class, 'epic_id');
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function reporter()
    {
        return $this->belongsTo(User::class, 'reporter_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function comments()
    {
        return $this->hasMany(IssueComment::class);
    }

    public function attachments()
    {
        return $this->hasMany(IssueAttachment::class);
    }

    public function checklists()
    {
        return $this->hasMany(IssueChecklist::class)->orderBy('position')->orderBy('id');
    }

    public function worklogs()
    {
        return $this->hasMany(IssueWorklog::class)->orderByDesc('started_at');
    }

    public function links()
    {
        return $this->hasMany(IssueLink::class, 'source_issue_id');
    }

    public function incomingLinks()
    {
        return $this->hasMany(IssueLink::class, 'target_issue_id');
    }

    public function sprintIssues()
    {
        return $this->hasMany(SprintIssue::class);
    }

    public function knowledgebaseLinks()
    {
        return $this->hasMany(IssueKnowledgebaseLink::class);
    }

    public function calendarEventLinks()
    {
        return $this->hasMany(CalendarEventLink::class, 'issue_id');
    }

    public function calendarEvents()
    {
        return $this->belongsToMany(CalendarEvent::class, 'calendar_event_links', 'issue_id', 'event_id')
            ->withTimestamps();
    }

    // ─── Accessors ───

    public function getSubtasksCompletedAttribute(): int
    {
        if (!$this->relationLoaded('subtasks')) {
            return $this->subtasks()
                ->whereHas('status', function ($query) {
                    $query->where('category', 'done');
                })
                ->count();
        }

        return $this->getRelationValue('subtasks')
            ->filter(function ($subtask) {
                return $subtask->status && $subtask->status->category === 'done';
            })
            ->count();
    }

    public function getProgressAttribute(): float
    {
        $total = $this->subtasks()->count();
        if ($total === 0) return 100;

        return round(($this->subtasks_completed / $total) * 100);
    }

    // ─── Scopes ───

    public function scopeByStatus($query, $statusId)
    {
        return $query->where('status_id', $statusId);
    }

    public function scopeByType($query, $typeId)
    {
        return $query->where('issue_type_id', $typeId);
    }

    public function scopeByAssignee($query, $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function scopeByPriority($query, $priority)
    {
        return $query->where('priority', $priority);
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('summary', 'ilike', "%{$search}%")
              ->orWhere('description', 'ilike', "%{$search}%");
        });
    }

    // ─── Search (Laravel Scout / Meilisearch) ───

    public function searchableAs(): string
    {
        return 'issues';
    }

    public function toSearchableArray(): array
    {
        $project = $this->relationLoaded('project') ? $this->project : $this->project()->first();
        $memberIds = [];

        if ($project) {
            $memberIds = $project->relationLoaded('members')
                ? $project->members->pluck('id')->map(fn ($id) => (int) $id)->all()
                : $project->members()->pluck('users.id')->map(fn ($id) => (int) $id)->all();
        }

        return [
            'id' => (int) $this->id,
            'key' => (string) $this->key,
            'summary' => (string) $this->summary,
            'description' => SearchText::plain($this->description),
            'project_id' => (int) $this->project_id,
            'visibility' => $project?->visibility,
            'member_ids' => $memberIds,
            'status_id' => $this->status_id ? (int) $this->status_id : null,
            'assigned_to' => $this->assigned_to ? (int) $this->assigned_to : null,
            'priority' => $this->priority,
            'updated_at' => optional($this->updated_at)->timestamp,
            'created_at' => optional($this->created_at)->timestamp,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->deleted_at === null && $this->project_id !== null;
    }

    public static function makeAllSearchableUsing($query)
    {
        return $query->with('project.members');
    }
}
