<?php

namespace App\Extensions\Crm\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class CrmAutomationRule extends Model
{
    protected $table = 'crm_automation_rules';

    public const TRIGGERS = ['deal_created', 'stage_changed', 'deal_won'];

    public const ACTIONS = ['create_task', 'send_notification'];

    protected $fillable = [
        'name', 'trigger', 'conditions', 'action', 'action_payload',
        'is_active', 'position', 'created_by',
    ];

    protected $casts = [
        'conditions' => 'array',
        'action_payload' => 'array',
        'is_active' => 'boolean',
        'position' => 'integer',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
