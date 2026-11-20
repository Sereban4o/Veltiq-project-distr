<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IssueChecklistItem extends Model
{
    use HasFactory;

    protected $fillable = ['checklist_id', 'body', 'done', 'assignee_id', 'position'];

    protected $casts = [
        'done' => 'boolean',
        'position' => 'integer',
    ];

    public function checklist()
    {
        return $this->belongsTo(IssueChecklist::class, 'checklist_id');
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }
}
