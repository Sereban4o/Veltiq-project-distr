<?php

namespace App\Models;

use App\Services\Knowledgebase\PageAcl;
use App\Support\SearchText;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Auth;
use Laravel\Scout\Searchable;

class Page extends Model
{
    use HasFactory;
    use SoftDeletes;
    use Searchable;

    protected $fillable = [
        'space_id', 'parent_id', 'position', 'author_id', 'last_edited_by',
        'title', 'body', 'status', 'version', 'labels', 'metadata',
    ];

    protected $casts = [
        'labels' => 'array',
        'metadata' => 'array',
    ];

    /** @return BelongsTo<Space, $this> */
    public function space(): BelongsTo
    {
        return $this->belongsTo(Space::class);
    }

    public function parent()
    {
        return $this->belongsTo(Page::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Page::class, 'parent_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function lastEditor()
    {
        return $this->belongsTo(User::class, 'last_edited_by');
    }

    public function versions()
    {
        return $this->hasMany(PageVersion::class)->orderBy('version', 'desc');
    }

    public function comments()
    {
        return $this->hasMany(PageComment::class);
    }

    public function attachments()
    {
        return $this->hasMany(PageAttachment::class);
    }

    public function labels()
    {
        return $this->hasMany(PageLabel::class);
    }

    public function permissions()
    {
        return $this->hasMany(PagePermission::class);
    }

    /**
     * Public read-only share links.
     *
     * @return HasMany<PageShare, $this>
     */
    public function shares(): HasMany
    {
        return $this->hasMany(PageShare::class);
    }

    /**
     * Users watching this page for updates.
     *
     * @return HasMany<PageFollower, $this>
     */
    public function followers(): HasMany
    {
        return $this->hasMany(PageFollower::class);
    }

    public function tasksbaseLinks()
    {
        return $this->hasMany(IssueKnowledgebaseLink::class);
    }

    // ─── Scopes ───

    public function scopePublished($query)
    {
        return $query->where('status', 'published');
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('title', 'ilike', "%{$search}%")
              ->orWhere('body', 'ilike', "%{$search}%");
        });
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('position')->orderBy('id');
    }

    // ─── Permissions ───

    public function canView(?User $user = null): bool
    {
        $user ??= Auth::user();

        if (! $user || ! $this->space) {
            return false;
        }

        if (! $this->space->canView($user)) {
            return false;
        }

        return PageAcl::for($this->space, $user)->canView($this);
    }

    public function canEdit(?User $user = null): bool
    {
        $user ??= Auth::user();

        if (! $user || ! $this->space) {
            return false;
        }

        return PageAcl::for($this->space, $user)->canEdit($this);
    }

    // ─── Search (Laravel Scout / Meilisearch) ───

    public function searchableAs(): string
    {
        return 'kb_pages';
    }

    public function toSearchableArray(): array
    {
        $space = $this->relationLoaded('space') ? $this->space : $this->space()->first();

        return [
            'id' => (int) $this->id,
            'title' => (string) $this->title,
            'body_text' => Page::plainText($this->body),
            'space_id' => (int) $this->space_id,
            'space_key' => $space?->key,
            'space_name' => $space?->name,
            'space_visibility' => $space?->visibility,
            'space_member_ids' => $space && $space->relationLoaded('members')
                ? $space->members->pluck('id')->map(fn ($id) => (int) $id)->all()
                : ($space ? $space->members()->pluck('users.id')->map(fn ($id) => (int) $id)->all() : []),
            'status' => $this->status,
            'labels' => $this->labels ?? [],
            'updated_at' => optional($this->updated_at)->timestamp,
        ];
    }

    public function shouldBeSearchable(): bool
    {
        return $this->deleted_at === null && $this->status === 'published';
    }

    public static function makeAllSearchableUsing($query)
    {
        return $query->with('space.members');
    }

    /**
     * Reduce rich-text HTML to a plain-text string for indexing / previews.
     */
    public static function plainText(?string $html): string
    {
        return SearchText::plain($html);
    }
}
