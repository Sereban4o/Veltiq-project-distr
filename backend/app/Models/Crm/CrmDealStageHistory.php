<?php

namespace App\Models\Crm;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CrmDealStageHistory extends Model
{
    protected $table = 'crm_deal_stage_history';

    protected $fillable = ['deal_id', 'from_stage_id', 'to_stage_id', 'changed_by'];

    public function deal()
    {
        return $this->belongsTo(Deal::class, 'deal_id');
    }

    public function fromStage()
    {
        return $this->belongsTo(PipelineStage::class, 'from_stage_id');
    }

    public function toStage()
    {
        return $this->belongsTo(PipelineStage::class, 'to_stage_id');
    }

    public function changer()
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
