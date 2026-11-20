<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class IssueChecklist extends Model
{
    use HasFactory;

    protected $fillable = ['issue_id', 'title', 'position', 'created_by'];

    protected $casts = [
        'position' => 'integer',
    ];

    public function issue()
    {
        return $this->belongsTo(Issue::class);
    }

    public function items()
    {
        return $this->hasMany(IssueChecklistItem::class, 'checklist_id')->orderBy('position')->orderBy('id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
