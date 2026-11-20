<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CalendarFeed extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'name', 'url', 'color', 'sync_interval_minutes',
        'is_active', 'etag', 'last_modified', 'last_synced_at', 'last_error',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'sync_interval_minutes' => 'integer',
        'last_synced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function feedEvents(): HasMany
    {
        return $this->hasMany(CalendarFeedEvent::class, 'feed_id');
    }

    public function isOwnedBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id;
    }
}
