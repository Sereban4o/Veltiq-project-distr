<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Admin-defined extra field for an entity (currently issues and pages).
 */
class CustomField extends Model
{
    use HasFactory;

    public const ENTITIES = ['issue', 'page'];

    public const TYPES = ['text', 'textarea', 'number', 'date', 'checkbox', 'select'];

    protected $fillable = [
        'entity', 'key', 'label', 'type', 'options',
        'required', 'position', 'is_active',
    ];

    protected $casts = [
        'options' => 'array',
        'required' => 'boolean',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeForEntity($query, string $entity)
    {
        return $query->where('entity', $entity);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('position')->orderBy('id');
    }
}
