<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PageTemplate extends Model
{
    use HasFactory;

    protected $fillable = ['space_id', 'created_by', 'name', 'description', 'body', 'labels'];

    protected $casts = [
        'labels' => 'array',
    ];

    public function space()
    {
        return $this->belongsTo(Space::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Built-in global templates available in every space.
     */
    public function scopeGlobal($query)
    {
        return $query->whereNull('space_id');
    }
}
