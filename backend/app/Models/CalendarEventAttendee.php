<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEventAttendee extends Model
{
    use HasFactory;

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_DECLINED = 'declined';

    public const STATUS_TENTATIVE = 'tentative';

    public const STATUS_NEEDS_ACTION = 'needs_action';

    public const ROLE_ORGANIZER = 'organizer';

    public const ROLE_REQUIRED = 'required';

    public const ROLE_OPTIONAL = 'optional';

    public const STATUSES = [
        self::STATUS_ACCEPTED,
        self::STATUS_DECLINED,
        self::STATUS_TENTATIVE,
        self::STATUS_NEEDS_ACTION,
    ];

    public const ROLES = [
        self::ROLE_ORGANIZER,
        self::ROLE_REQUIRED,
        self::ROLE_OPTIONAL,
    ];

    protected $fillable = [
        'event_id', 'user_id', 'email', 'name', 'status', 'role',
        'invited_by', 'responded_at',
    ];

    protected $casts = [
        'responded_at' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }
}
