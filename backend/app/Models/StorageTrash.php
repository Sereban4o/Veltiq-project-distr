<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageTrash extends Model
{
    protected $table = 'storage_trash';

    protected $fillable = [
        'entry_id',
        'disk',
        'original_path',
        'original_path_hash',
        'parent_path',
        'type',
        'size',
        'items_count',
        'owner_id',
        'deleted_by',
        'trash_key',
        'expires_at',
        'purged_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'items_count' => 'integer',
            'expires_at' => 'datetime',
            'purged_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(StorageMeta::class, 'entry_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function deletedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'deleted_by');
    }
}
