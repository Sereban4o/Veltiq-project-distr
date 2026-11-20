<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageUpload extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $table = 'storage_uploads';

    protected $fillable = [
        'id',
        'user_id',
        'disk',
        'target_path',
        'file_name',
        'total_size',
        'chunk_size',
        'total_chunks',
        'checksum',
        'status',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'total_size' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chunks(): HasMany
    {
        return $this->hasMany(StorageUploadChunk::class, 'upload_id')->orderBy('chunk_index');
    }
}
