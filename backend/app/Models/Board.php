<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<BoardColumn, $this> */
    public function columns(): HasMany
    {
        return $this->hasMany(BoardColumn::class)->orderBy('order');
    }

    /** @return HasMany<Sprint, $this> */
    public function sprints(): HasMany
    {
        return $this->hasMany(Sprint::class);
    }
}
