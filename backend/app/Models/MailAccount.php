<?php

namespace App\Models;

use App\Casts\EncryptedOrPlain;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MailAccount extends Model
{
    use HasFactory;

    public const PROTOCOL_IMAP = 'imap';

    public const PROTOCOL_POP = 'pop';

    protected $fillable = [
        'user_id', 'email', 'display_name', 'incoming_protocol',
        'imap_host', 'imap_port', 'imap_encryption', 'imap_username', 'imap_password',
        'pop_host', 'pop_port', 'pop_encryption', 'pop_username', 'pop_password',
        'smtp_host', 'smtp_port', 'smtp_encryption', 'smtp_username', 'smtp_password',
        'is_active', 'last_synced_at', 'last_sync_error',
    ];

    protected $hidden = [
        'imap_password', 'pop_password', 'smtp_password',
    ];

    protected $casts = [
        'imap_password' => EncryptedOrPlain::class,
        'pop_password' => EncryptedOrPlain::class,
        'smtp_password' => EncryptedOrPlain::class,
        'imap_port' => 'integer',
        'pop_port' => 'integer',
        'smtp_port' => 'integer',
        'is_active' => 'boolean',
        'last_synced_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(MailMessage::class);
    }

    /** Explicit share rows (team inbox members). */
    public function shares(): HasMany
    {
        return $this->hasMany(MailAccountShare::class);
    }

    /** Members who may access this mailbox (excluding the owner). */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'mail_account_shares')
            ->withPivot('can_send')
            ->withTimestamps();
    }

    public function sharedWith(int $userId): bool
    {
        return $this->shares()->where('user_id', $userId)->exists();
    }

    public function canSendAs(int $userId): bool
    {
        if ((int) $this->user_id === $userId) {
            return true;
        }

        return $this->shares()
            ->where('user_id', $userId)
            ->where('can_send', true)
            ->exists();
    }

    public function incomingHost(): ?string
    {
        return $this->incoming_protocol === self::PROTOCOL_POP ? $this->pop_host : $this->imap_host;
    }

    public function incomingPort(): ?int
    {
        return $this->incoming_protocol === self::PROTOCOL_POP ? $this->pop_port : $this->imap_port;
    }

    public function incomingEncryption(): string
    {
        $value = $this->incoming_protocol === self::PROTOCOL_POP ? $this->pop_encryption : $this->imap_encryption;

        return $value ?: 'ssl';
    }

    public function incomingUsername(): ?string
    {
        return ($this->incoming_protocol === self::PROTOCOL_POP ? $this->pop_username : $this->imap_username)
            ?: $this->email;
    }

    public function incomingPassword(): ?string
    {
        return $this->incoming_protocol === self::PROTOCOL_POP ? $this->pop_password : $this->imap_password;
    }

    public function smtpUsernameValue(): ?string
    {
        return $this->smtp_username ?: $this->email;
    }
}
