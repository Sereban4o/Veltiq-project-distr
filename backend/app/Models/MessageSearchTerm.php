<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Blind index row: HMAC of one normalised word of an encrypted message body.
 */
class MessageSearchTerm extends Model
{
    public $timestamps = false;

    protected $fillable = ['message_id', 'chat_id', 'term'];
}
