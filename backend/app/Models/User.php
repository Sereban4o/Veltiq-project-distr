<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use Notifiable;

    protected $fillable = [
        'email',
        'password',
        'first_name',
        'last_name',
        'avatar',
        'status',
        'role_id',
        'email_verified_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** Two-factor authentication is enforced only after the first code is confirmed. */
    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->two_factor_secret !== null;
    }

    // ─── Relationships ───

    public function ownedProjects()
    {
        return $this->hasMany(\App\Models\Project::class, 'created_by');
    }

    public function projectMemberships()
    {
        return $this->belongsToMany(\App\Models\Project::class, 'project_members')
            ->withColumn('role')
            ->withTimestamps();
    }

    public function assignedIssues()
    {
        return $this->hasMany(Issue::class, 'assigned_to');
    }

    public function createdIssues()
    {
        return $this->hasMany(Issue::class, 'created_by');
    }

    public function createdPages()
    {
        return $this->hasMany(Page::class, 'author_id');
    }

    public function notifications()
    {
        return $this->hasMany(Notification::class)->latest();
    }

    public function notificationPreferences()
    {
        return $this->hasMany(NotificationPreference::class);
    }

    public function activityLog()
    {
        return $this->hasMany(ActivityLog::class);
    }

    public function role()
    {
        return $this->belongsTo(Role::class);
    }

    public function mailAccount()
    {
        return $this->hasOne(MailAccount::class);
    }

    public function mailMessages()
    {
        return $this->hasMany(MailMessage::class);
    }

    public function hasRole(string $role): bool
    {
        return $this->role && $this->role->name === $role;
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    // ─── Accessors ───

    public function getFullNameAttribute(): string
    {
        return "{$this->first_name} {$this->last_name}";
    }

    public function getInitialsAttribute(): string
    {
        return $this->nameInitial($this->first_name) . $this->nameInitial($this->last_name);
    }

    /**
     * First letter of a name, multibyte-safe.
     *
     * Byte-based substr() splits a Cyrillic (2-byte) character in half and
     * yields invalid UTF-8, which then breaks json_encode() on every response
     * that carries the initials.
     */
    private function nameInitial(?string $name): string
    {
        $name = trim((string) $name);

        if ($name === '') {
            return '';
        }

        if (function_exists('mb_substr')) {
            return mb_strtoupper(mb_substr($name, 0, 1, 'UTF-8'), 'UTF-8');
        }

        preg_match('/./u', $name, $match);

        return strtoupper($match[0] ?? '');
    }

    // ─── Scopes ───

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
