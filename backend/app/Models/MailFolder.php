<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailFolder extends Model
{
    protected $fillable = ['user_id', 'name', 'position'];

    protected $casts = [
        'position' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function messages()
    {
        return $this->hasMany(MailMessage::class);
    }

    public function rules()
    {
        return $this->hasMany(MailFilterRule::class, 'action_target_folder_id');
    }
}
