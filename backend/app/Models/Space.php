<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Space extends Model
{
    use HasFactory;

    protected $fillable = [
        'created_by', 'key', 'name', 'description',
        'type', 'visibility', 'avatar', 'properties',
    ];

    protected $casts = [
        'properties' => 'array',
    ];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsToMany<User, $this> */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'space_members')
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * Global admins and the space creator are always considered space admins.
     */
    public function isAdmin(User $user): bool
    {
        return $user->isAdmin()
            || (int) $this->created_by === (int) $user->id
            || app(\App\Services\Permissions\PermissionService::class)
                ->can($user, 'edit_spaces', \App\Services\Permissions\Scope::space($this));
    }

    /**
     * Users allowed to create/edit/delete pages in this space.
     */
    public function canEdit(User $user): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return app(\App\Services\Permissions\PermissionService::class)
            ->can($user, 'edit_pages', \App\Services\Permissions\Scope::space($this));
    }

    /**
     * Users allowed to view the space and its pages.
     */
    public function canView(User $user): bool
    {
        if ($this->visibility !== 'private' || $this->isAdmin($user)) {
            return true;
        }

        return app(\App\Services\Permissions\PermissionService::class)
            ->can($user, 'view_pages', \App\Services\Permissions\Scope::space($this));
    }

    public function pages()
    {
        return $this->hasMany(Page::class);
    }

    /**
     * Scope a query to spaces visible to the given user.
     *
     * Mirrors {@see self::canView()} for use in bulk candidate filtering; final
     * access is still decided per page by {@see \App\Services\Knowledgebase\PageAcl}.
     */
    public function scopeVisibleTo($query, User $user)
    {
        return $query->where(function ($q) use ($user) {
            $q->where('visibility', '!=', 'private')
              ->orWhere('created_by', $user->id)
              ->orWhereHas('members', function ($m) use ($user) {
                  $m->where('user_id', $user->id);
              });
        });
    }

    public function permissions()
    {
        return $this->hasMany(SpacePermission::class);
    }

    public function templates()
    {
        return $this->hasMany(PageTemplate::class);
    }
}
