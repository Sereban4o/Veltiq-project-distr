<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Automation rule for tasks (issues). A rule pairs a trigger
 * (`issue_created` / `status_changed`) with optional conditions and a list of
 * actions executed by {@see \App\Services\Tasksbase\TaskAutomationEngine}.
 */
class TaskAutomationRule extends Model
{
    public const TRIGGERS = ['issue_created', 'status_changed'];

    public const ACTIONS = ['assign', 'set_priority', 'notify'];

    protected $fillable = [
        'name', 'project_id', 'trigger', 'conditions', 'actions',
        'is_active', 'position', 'created_by',
    ];

    protected $casts = [
        'conditions' => 'array',
        'actions' => 'array',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
