<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Board extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'name', 'type', 'created_by',
        'filter_query', 'swimlanes', 'swimlane_by', 'wip_enforcement', 'order',
    ];

    protected $casts = [
        'filter_query' => 'array',
        'swimlanes' => 'array',
        'wip_enforcement' => 'boolean',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function columns()
    {
        return $this->hasMany(BoardColumn::class)->orderBy('order');
    }

    public function sprints()
    {
        return $this->hasMany(Sprint::class);
    }
}
