<?php

namespace App\Models;

use App\Casts\EncryptedOrPlain;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Message extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'chat_id', 'sender_id', 'parent_id', 'client_id', 'body', 'type', 'metadata',
        'edit_count', 'edited_at', 'deleted_at',
    ];

    protected $casts = [
        'body' => EncryptedOrPlain::class,
        'metadata' => 'array',
        'edited_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /** @return BelongsTo<User, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }

    /** @return BelongsTo<Message, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'parent_id');
    }

    /** @return HasMany<Message, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'parent_id');
    }

    /** @return HasMany<MessageFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(MessageFile::class);
    }

    /** @return HasMany<MessageReaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(MessageReaction::class);
    }

    public function isDeleted(): bool
    {
        return $this->deleted_at !== null;
    }

    public function markAsDeleted(): void
    {
        $this->update([
            'deleted_at' => now(),
            'body' => '[Удалено]',
        ]);
    }
}
