<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Outbound webhook endpoint. The signing secret is stored encrypted and hidden
 * from serialisation.
 */
class Webhook extends Model
{
    use HasFactory;

    protected $fillable = [
        'name', 'url', 'secret', 'events', 'is_active',
        'created_by', 'last_status', 'last_called_at', 'failures',
    ];

    protected $hidden = ['secret'];

    protected $casts = [
        'events' => 'array',
        'is_active' => 'boolean',
        'secret' => 'encrypted',
        'last_status' => 'integer',
        'last_called_at' => 'datetime',
        'failures' => 'integer',
    ];

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function subscribesTo(string $event): bool
    {
        $events = $this->events ?? [];

        return in_array($event, $events, true) || in_array('*', $events, true);
    }
}
