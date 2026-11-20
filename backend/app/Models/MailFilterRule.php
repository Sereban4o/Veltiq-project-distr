<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailFilterRule extends Model
{
    protected $fillable = [
        'user_id', 'name', 'is_active', 'position', 'action_target_folder_id', 'conditions',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'position' => 'integer',
        'conditions' => 'array',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function targetFolder()
    {
        return $this->belongsTo(MailFolder::class, 'action_target_folder_id');
    }
}
