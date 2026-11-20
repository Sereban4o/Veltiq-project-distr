<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Append-only access journal for the file storage.
 */
class StorageAuditLog extends Model
{
    public $timestamps = false;

    protected $table = 'storage_audit_logs';

    protected $fillable = [
        'user_id',
        'action',
        'disk',
        'path',
        'path_hash',
        'entry_id',
        'size',
        'status',
        'ip',
        'user_agent',
        'request_id',
        'payload',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'status' => 'integer',
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
