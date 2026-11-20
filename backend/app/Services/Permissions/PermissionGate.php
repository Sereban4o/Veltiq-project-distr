<?php

namespace App\Services\Permissions;

use App\Models\User;
use App\Support\Permissions\PermissionCatalogue;
use Illuminate\Support\Facades\Gate;

/**
 * Exposes every catalogued permission as a Laravel Gate ability resolved through
 * {@see PermissionService}.
 *
 * Abilities accept an optional scope model as their second argument, so
 * `Gate::allows('edit_pages', $space)` and `@can('delete_projects', $project)`
 * answer exactly the same question as a direct PermissionService call.
 */
final class PermissionGate
{
    public static function register(): void
    {
        foreach (PermissionCatalogue::names() as $permission) {
            Gate::define($permission, function (User $user, mixed $scope = null) use ($permission) {
                return app(PermissionService::class)
                    ->can($user, $permission, Scope::fromModel($scope));
            });
        }
    }
}
