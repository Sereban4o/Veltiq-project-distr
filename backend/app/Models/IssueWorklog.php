<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IssueWorklog extends Model
{
    use HasFactory;

    protected $fillable = [
        'issue_id', 'user_id', 'seconds', 'started_at', 'ended_at', 'is_running', 'comment',
    ];

    protected $casts = [
        'seconds' => 'integer',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
        'is_running' => 'boolean',
    ];

    /** @return BelongsTo<Issue, $this> */
    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function getHoursAttribute(): float
    {
        return round($this->seconds / 3600, 2);
    }
}
