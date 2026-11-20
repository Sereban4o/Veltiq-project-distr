<?php

namespace App\Models\Crm;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PipelineStage extends Model
{
    use HasFactory;

    protected $table = 'crm_pipeline_stages';

    protected $fillable = ['name', 'sort_order', 'color', 'is_won', 'is_lost'];

    protected $casts = [
        'sort_order' => 'integer',
        'is_won' => 'boolean',
        'is_lost' => 'boolean',
    ];

    public function deals()
    {
        return $this->hasMany(Deal::class, 'stage_id');
    }
}
