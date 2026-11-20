<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarFeedEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'feed_id', 'external_uid', 'recurrence_id', 'summary', 'description',
        'location', 'starts_at', 'ends_at', 'all_day', 'timezone', 'rrule',
        'exdates', 'is_cancelled', 'sequence', 'raw',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'all_day' => 'boolean',
        'exdates' => 'array',
        'is_cancelled' => 'boolean',
        'sequence' => 'integer',
        'raw' => 'array',
    ];

    public function feed(): BelongsTo
    {
        return $this->belongsTo(CalendarFeed::class, 'feed_id');
    }

    public function isRecurring(): bool
    {
        return ! empty($this->rrule);
    }

    public function timezoneName(): string
    {
        return $this->timezone ?: config('app.timezone', 'UTC');
    }
}
