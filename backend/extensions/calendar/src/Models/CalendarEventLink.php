<?php

namespace App\Extensions\Calendar\Models;

use App\Models\Issue;
use App\Models\User;

use App\Extensions\Crm\Models\Deal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CalendarEventLink extends Model
{
    use HasFactory;

    protected $fillable = ['event_id', 'issue_id', 'deal_id', 'created_by'];

    public function event(): BelongsTo
    {
        return $this->belongsTo(CalendarEvent::class, 'event_id');
    }

    public function issue(): BelongsTo
    {
        return $this->belongsTo(Issue::class);
    }

    public function deal(): BelongsTo
    {
        return $this->belongsTo(Deal::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
