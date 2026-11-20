<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NotificationSetting extends Model
{
    use HasFactory;

    public const FREQUENCIES = ['off', 'daily', 'weekly'];

    protected $fillable = ['user_id', 'digest_frequency', 'last_digest_sent_at'];

    protected $casts = [
        'last_digest_sent_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
