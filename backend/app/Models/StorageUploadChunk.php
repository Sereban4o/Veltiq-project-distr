<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StorageUploadChunk extends Model
{
    public $timestamps = false;

    public $incrementing = true;

    protected $table = 'storage_upload_chunks';

    protected $fillable = [
        'upload_id',
        'chunk_index',
        'size',
        'checksum',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'chunk_index' => 'integer',
            'size' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function upload(): BelongsTo
    {
        return $this->belongsTo(StorageUpload::class, 'upload_id');
    }
}
