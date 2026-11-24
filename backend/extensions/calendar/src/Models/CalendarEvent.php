<?php

namespace App\Extensions\Calendar\Models;

use App\Models\Issue;
use App\Models\User;

use App\Extensions\Crm\Models\Deal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarEvent extends Model
{
    use HasFactory;

    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITY_PUBLIC = 'public';

    public const SOURCE_LOCAL = 'local';

    public const SOURCE_IMPORT = 'import';

    public const SOURCE_FEED = 'feed';

    protected $fillable = [
        'user_id', 'organizer_id', 'title', 'description', 'location',
        'event_date', 'starts_at', 'ends_at', 'all_day', 'timezone',
        'rrule', 'exdates', 'recurrence_until', 'color', 'visibility',
        'source', 'external_uid', 'external_sequence',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'all_day' => 'boolean',
        'exdates' => 'array',
        'recurrence_until' => 'datetime',
        'external_sequence' => 'integer',
    ];

    protected $appends = ['event_date'];

    // ─── Relationships ───

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function organizer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organizer_id');
    }

    public function attendees(): HasMany
    {
        return $this->hasMany(CalendarEventAttendee::class, 'event_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(CalendarEventLink::class, 'event_id');
    }

    public function issues(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class, 'calendar_event_links', 'event_id', 'issue_id')
            ->withTimestamps();
    }

    public function deals(): BelongsToMany
    {
        return $this->belongsToMany(Deal::class, 'calendar_event_links', 'event_id', 'deal_id')
            ->withTimestamps();
    }

    // ─── Accessors / mutators ───

    /**
     * Legacy virtual column kept for the aggregate payload and the event form.
     */
    public function getEventDateAttribute(): ?string
    {
        return $this->starts_at?->format('Y-m-d');
    }

    /**
     * Accept the legacy `event_date` field by mapping it onto the timeline.
     */
    public function setEventDateAttribute(mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        $date = $value instanceof \DateTimeInterface
            ? CarbonImmutable::instance($value)
            : CarbonImmutable::parse($value);

        $this->attributes['starts_at'] = $date;

        if (! array_key_exists('all_day', $this->attributes)) {
            $this->attributes['all_day'] = $value instanceof \DateTimeInterface
                || preg_match('/\d{1,2}:\d{2}/', (string) $value) !== 1;
        }
    }

    // ─── Helpers ───

    public function isOrganizer(User $user): bool
    {
        return (int) ($this->organizer_id ?? $this->user_id) === (int) $user->id;
    }

    public function canView(User $user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        if ($this->visibility === self::VISIBILITY_PUBLIC) {
            return true;
        }

        if ((int) $this->user_id === (int) $user->id || $this->isOrganizer($user)) {
            return true;
        }

        return $this->attendees()->where('user_id', $user->id)->exists();
    }

    public function isRecurring(): bool
    {
        return ! empty($this->rrule);
    }

    public function timezoneName(): string
    {
        return $this->timezone ?: config('app.timezone', 'UTC');
    }

    /**
     * Scope a query to the events the given user is allowed to see.
     */
    public function scopeVisibleTo($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('user_id', $user->id)
                ->orWhere('organizer_id', $user->id)
                ->orWhere('visibility', self::VISIBILITY_PUBLIC)
                ->orWhereHas('attendees', fn ($a) => $a->where('user_id', $user->id));
        });
    }
}
