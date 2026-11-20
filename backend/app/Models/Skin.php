<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Named branding preset ("skin"). Applying a skin copies its config into the
 * runtime branding setting, so the compiled web client picks it up without a
 * rebuild.
 */
class Skin extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'config',
        'logo_path',
        'favicon_path',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'config' => 'array',
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
