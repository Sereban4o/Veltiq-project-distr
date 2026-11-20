<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ChatMember extends Model
{
    use HasFactory;

    protected $table = 'chat_members';

    protected $fillable = [
        'chat_id', 'user_id', 'role', 'last_read_at', 'last_read_message_id',
    ];

    protected $casts = [
        'last_read_at' => 'datetime',
        'last_read_message_id' => 'integer',
    ];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
