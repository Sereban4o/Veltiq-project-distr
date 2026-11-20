<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Public read-only link to a knowledgebase page. Only the token hash and the
 * optional password hash are stored; the raw token is shown to the owner once.
 */
class PageShare extends Model
{
    protected $fillable = [
        'page_id',
        'created_by',
        'token_hash',
        'password_hash',
        'expires_at',
        'revoked_at',
        'last_accessed_at',
    ];

    protected $hidden = ['token_hash', 'password_hash'];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
            'last_accessed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()));
    }

    public function isExpired(): bool
    {
        $expiresAt = $this->expires_at;

        return $this->revoked_at !== null
            || ($expiresAt !== null && \Illuminate\Support\Carbon::parse($expiresAt)->isPast());
    }

    public function hasPassword(): bool
    {
        return $this->password_hash !== null;
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
