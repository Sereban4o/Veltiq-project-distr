<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An installed template (docs/13-template-system.md).
 *
 * A template is plain HTML/CSS/JS served by the backend; the React SPA is the
 * built-in one (`is_builtin`). Exactly one template is active: activating a
 * template switches the others off, so `/template/{slug}` never has to guess.
 */
class Template extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'version',
        'author',
        'description',
        'storage_path',
        'main_file',
        'requires_auth',
        'is_active',
        'is_builtin',
        'sort_order',
    ];

    protected $casts = [
        'requires_auth' => 'boolean',
        'is_active' => 'boolean',
        'is_builtin' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected $appends = ['url'];

    protected static function booted(): void
    {
        // Activating one template deactivates all the others. A bulk update does
        // not fire model events, so there is no recursion here.
        static::saved(function (self $template): void {
            if (! $template->is_active) {
                return;
            }

            static::query()
                ->whereKeyNot($template->getKey())
                ->where('is_active', true)
                ->update(['is_active' => false]);
        });
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function getUrlAttribute(): string
    {
        return '/template/' . $this->slug;
    }

    public function isBuiltin(): bool
    {
        return $this->is_builtin;
    }
}
