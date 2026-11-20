<?php

namespace App\Services\Storage;

use App\Models\StorageMeta;
use App\Models\StorageTrash;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * Authorization rules for the shared file storage.
 *
 * Every authenticated user can browse the storage. An entry is managed by its
 * owner (whoever created/uploaded it) or any admin; entries without an owner
 * are shared and writable by anyone, with ownership assigned on first write.
 */
class StorageAccess
{
    public function canView(?User $user, ?StorageMeta $entry): bool
    {
        return $user !== null;
    }

    public function canModify(?User $user, ?StorageMeta $entry): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin() || $entry === null) {
            return true;
        }

        return $entry->owner_id === null || (int) $entry->owner_id === (int) $user->getKey();
    }

    public function assertCanModify(?User $user, ?StorageMeta $entry): void
    {
        if (!$this->canModify($user, $entry)) {
            throw new AuthorizationException('Недостаточно прав для этой операции');
        }
    }

    public function canManageTrash(?User $user, StorageTrash $trash): bool
    {
        if ($user === null) {
            return false;
        }

        if ($user->isAdmin()) {
            return true;
        }

        return (int) $trash->owner_id === (int) $user->getKey()
            || (int) $trash->deleted_by === (int) $user->getKey();
    }
}
