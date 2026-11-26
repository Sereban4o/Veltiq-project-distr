<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Audit trail of whole-system updates applied through the central update
 * service (update.veltiq.ru).
 */
class SystemUpdateLog extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'from_version',
        'to_version',
        'status',
        'message',
        'user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @param Builder<SystemUpdateLog> $query */
    public function scopeRunning(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING)
            ->where('created_at', '>=', now()->subHour());
    }
}
