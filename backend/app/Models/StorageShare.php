<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Public link to a storage entry. Only the token hash and (optionally) the
 * password hash are stored; the raw token is shown to the owner once.
 */
class StorageShare extends Model
{
    protected $table = 'storage_shares';

    protected $fillable = [
        'token_hash',
        'entry_id',
        'disk',
        'path',
        'created_by',
        'password_hash',
        'can_download',
        'max_downloads',
        'downloads_count',
        'failed_attempts',
        'locked_until',
        'expires_at',
        'revoked_at',
        'last_accessed_at',
    ];

    protected $hidden = ['token_hash', 'password_hash'];

    protected function casts(): array
    {
        return [
            'can_download' => 'boolean',
            'max_downloads' => 'integer',
            'downloads_count' => 'integer',
            'failed_attempts' => 'integer',
            'locked_until' => 'datetime',
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_accessed_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(StorageMeta::class, 'entry_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isExpired(): bool
    {
        return $this->revoked_at !== null
            || ($this->expires_at !== null && $this->expires_at->isPast());
    }

    public function hasReachedDownloadLimit(): bool
    {
        return $this->max_downloads !== null && $this->downloads_count >= $this->max_downloads;
    }

    public function downloadsLeft(): ?int
    {
        if ($this->max_downloads === null) {
            return null;
        }

        return max(0, $this->max_downloads - $this->downloads_count);
    }
}
