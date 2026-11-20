<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MailSignature extends Model
{
    protected $fillable = [
        'user_id', 'name', 'body_html', 'is_default', 'position',
    ];

    protected $casts = [
        'is_default' => 'boolean',
        'position' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
