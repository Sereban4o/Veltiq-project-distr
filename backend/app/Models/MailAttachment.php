<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class MailAttachment extends Model
{
    use HasFactory;

    protected $fillable = [
        'mail_message_id', 'filename', 'mime_type', 'size', 'path',
    ];

    protected $casts = [
        'size' => 'integer',
    ];

    public function message()
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }
}
