<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Immutable snapshot of a file's contents taken before it is overwritten.
 */
class StorageVersion extends Model
{
    public const SOURCES = ['upload', 'edit', 'office', 'restore', 'move'];

    public $timestamps = false;

    protected $fillable = [
        'entry_id',
        'disk',
        'path',
        'version',
        'size',
        'mime',
        'checksum',
        'source',
        'author_id',
        'office_key',
        'status',
        'error',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'version' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(StorageMeta::class, 'entry_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }
}
