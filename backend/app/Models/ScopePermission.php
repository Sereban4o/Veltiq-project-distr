<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScopePermission extends Model
{
    use HasFactory;

    protected $fillable = [
        'scope_type', 'scope_id', 'target_type', 'target_value',
        'effect', 'permission', 'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeForScope($query, string $type, ?int $id)
    {
        return $query->where('scope_type', $type)
            ->where(function ($q) use ($id) {
                $id === null
                    ? $q->whereNull('scope_id')
                    : $q->where('scope_id', $id);
            });
    }
}
