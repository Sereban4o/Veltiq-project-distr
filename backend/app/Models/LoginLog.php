<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LoginLog extends Model
{
    use HasFactory;

    public const EVENTS = [
        'login', 'logout', 'failed', 'password_changed', 'token_refresh', 'register',
    ];

    protected $fillable = [
        'user_id', 'email', 'event', 'channel', 'guard',
        'successful', 'reason', 'ip_address', 'user_agent', 'meta',
    ];

    protected $casts = [
        'successful' => 'boolean',
        'meta' => 'array',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeEvent($query, ?string $event)
    {
        return $event ? $query->where('event', $event) : $query;
    }
}
