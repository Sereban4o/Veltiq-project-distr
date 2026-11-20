<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Metadata row for one storage entry (file or directory).
 *
 * Keyed by (disk, path) because the storage driver remains the source of
 * truth: rows are created lazily for entries discovered through listings.
 */
class StorageMeta extends Model
{
    protected $table = 'storage_meta';

    protected $fillable = [
        'disk',
        'path',
        'path_hash',
        'parent_path',
        'type',
        'size',
        'mime',
        'owner_id',
        'created_by',
        'version',
        'preview_status',
        'preview_at',
        'last_office_key',
        'deleted_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'version' => 'integer',
            'deleted_at' => 'datetime',
            'preview_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(StorageVersion::class, 'entry_id')->orderByDesc('version');
    }

    public function shares(): HasMany
    {
        return $this->hasMany(StorageShare::class, 'entry_id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereNull('deleted_at');
    }

    public function isDirectory(): bool
    {
        return $this->type === 'dir';
    }
}
