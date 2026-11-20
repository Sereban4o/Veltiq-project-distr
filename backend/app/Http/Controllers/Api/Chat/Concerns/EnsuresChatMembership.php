<?php

namespace App\Http\Controllers\Api\Chat\Concerns;

use App\Models\Chat;
use Illuminate\Support\Facades\Auth;

/**
 * Shared membership/role checks for chat endpoints (the module has no Policy
 * layer, so every controller verifies access the same way).
 */
trait EnsuresChatMembership
{
    protected function ensureUserIsMember(Chat $chat): void
    {
        if (!$chat->members()->where('users.id', Auth::id())->exists()) {
            abort(403, 'Вы не являетесь участником этого чата');
        }
    }

    protected function memberRole(Chat $chat): ?string
    {
        $role = $chat->members()->where('users.id', Auth::id())->first()?->pivot?->role;

        return $role !== null ? (string) $role : null;
    }

    protected function isChatAdmin(Chat $chat): bool
    {
        $role = $this->memberRole($chat);
        if ($role !== null) {
            return $role === 'admin';
        }

        return (int) $chat->created_by === (int) Auth::id();
    }
}
