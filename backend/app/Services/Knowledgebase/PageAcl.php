<?php

namespace App\Services\Knowledgebase;

use App\Models\Page;
use App\Models\PagePermission;
use App\Models\Space;
use App\Models\User;

/**
 * Resolves effective page permissions for a single user within one space.
 *
 * Rules:
 *  - Space admins always have full access.
 *  - Explicit page permissions ({@see PagePermission}) are inherited by subpages.
 *    The nearest ancestor that carries any explicit grant wins; if that ancestor
 *    lists no grant for the user, access is denied (a restriction acts as a cutoff).
 *  - When no ancestor is restricted, space-level permissions apply.
 *  - Non-published pages are visible only to editors and their own author.
 *
 * Instances memoise the space page tree and grants, so filter whole collections
 * with a single instance instead of calling {@see Page::canView()} per page.
 */
class PageAcl
{
    /** @var array<int, array{parent_id: ?int, status: string, author_id: int}> */
    private array $pages;

    /** @var array<int, array<int, string>> page_id => [user_id => permission] */
    private array $grants;

    public function __construct(private Space $space, private User $user)
    {
        $this->pages = [];
        foreach (
            $space->pages()->withTrashed(false)->get(['id', 'parent_id', 'status', 'author_id']) as $page
        ) {
            $this->pages[$page->id] = [
                'parent_id' => $page->parent_id !== null ? (int) $page->parent_id : null,
                'status' => $page->status,
                'author_id' => (int) $page->author_id,
            ];
        }

        $this->grants = [];
        foreach (
            PagePermission::whereIn('page_id', array_keys($this->pages))->get() as $grant
        ) {
            $this->grants[$grant->page_id][$grant->user_id] = $grant->permission;
        }
    }

    public static function for(Space $space, User $user): self
    {
        return new self($space, $user);
    }

    /**
     * Effective permission of this user for the given page.
     *
     * @return 'admin'|'editor'|'viewer'|'none'
     */
    public function effective(Page $page): string
    {
        if ($this->space->isAdmin($this->user)) {
            return 'admin';
        }

        $restricted = $this->nearestRestrictedAncestor($page);

        if ($restricted !== null) {
            $grant = $this->grants[$restricted][$this->user->id] ?? null;

            return $grant ?? 'none';
        }

        if ($this->space->canEdit($this->user)) {
            return 'editor';
        }

        if ($this->space->canView($this->user)) {
            return 'viewer';
        }

        return 'none';
    }

    public function canView(Page $page): bool
    {
        if ((int) $page->author_id === (int) $this->user->id) {
            return true;
        }

        $effective = $this->effective($page);

        if (in_array($effective, ['admin', 'editor'], true)) {
            return true;
        }

        if ($effective === 'none') {
            return false;
        }

        // Viewers only ever see published pages.
        return $page->status === 'published';
    }

    public function canEdit(Page $page): bool
    {
        return in_array($this->effective($page), ['admin', 'editor'], true);
    }

    /**
     * Only space admins or explicitly listed editors may change a page's ACL.
     */
    public function canManagePermissions(Page $page): bool
    {
        if ($this->space->isAdmin($this->user)) {
            return true;
        }

        return ($this->grants[$page->id][$this->user->id] ?? null) === 'editor';
    }

    /**
     * Returns the id of the nearest ancestor (including the page itself) that
     * has any explicit grant, or null when the whole chain is unrestricted.
     */
    private function nearestRestrictedAncestor(Page $page): ?int
    {
        $current = (int) $page->id;
        $guard = 0;

        while ($current !== 0 && $guard++ < 500) {
            if (! empty($this->grants[$current])) {
                return $current;
            }

            $parent = $this->pages[$current]['parent_id'] ?? null;

            if ($parent === null) {
                return null;
            }

            $current = (int) $parent;
        }

        return null;
    }
}
