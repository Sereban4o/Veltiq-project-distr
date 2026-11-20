<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PinnedMessage extends Model
{
    protected $fillable = ['chat_id', 'message_id', 'pinned_by'];

    public function chat()
    {
        return $this->belongsTo(Chat::class);
    }

    public function message()
    {
        return $this->belongsTo(Message::class);
    }

    public function pinnedBy()
    {
        return $this->belongsTo(User::class, 'pinned_by');
    }
}
