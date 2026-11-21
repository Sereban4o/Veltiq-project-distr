<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sprint extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id', 'board_id', 'name',
        'start_date', 'end_date', 'status', 'sequence',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    /** @return BelongsTo<Project, $this> */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    /** @return BelongsTo<Board, $this> */
    public function board(): BelongsTo
    {
        return $this->belongsTo(Board::class);
    }

    /** @return BelongsToMany<Issue, $this> */
    public function issues(): BelongsToMany
    {
        return $this->belongsToMany(Issue::class, 'sprint_issues')
            ->withPivot('rank')
            ->orderByPivot('rank')
            ->withTimestamps();
    }

    /** @return HasMany<SprintSnapshot, $this> */
    public function snapshots(): HasMany
    {
        return $this->hasMany(SprintSnapshot::class)->orderBy('snapshot_date');
    }
}
