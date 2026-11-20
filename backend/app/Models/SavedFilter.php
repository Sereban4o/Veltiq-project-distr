<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SavedFilter extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'project_id', 'name', 'jql_query', 'filters', 'is_global',
    ];

    protected $casts = [
        'filters' => 'array',
        'is_global' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }
}
