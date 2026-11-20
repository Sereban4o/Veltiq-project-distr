<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Grants a user access to a mailbox owned by somebody else (team inbox).
 */
class MailAccountShare extends Model
{
    use HasFactory;

    protected $fillable = [
        'mail_account_id', 'user_id', 'can_send',
    ];

    protected $casts = [
        'can_send' => 'boolean',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
