<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class SprintSnapshot extends Model
{
    use HasFactory;

    protected $fillable = [
        'sprint_id', 'snapshot_date', 'issues_total', 'issues_done', 'points_total', 'points_done',
    ];

    protected $casts = [
        'snapshot_date' => 'date',
        'issues_total' => 'integer',
        'issues_done' => 'integer',
        'points_total' => 'float',
        'points_done' => 'float',
    ];

    public function sprint()
    {
        return $this->belongsTo(Sprint::class);
    }
}
