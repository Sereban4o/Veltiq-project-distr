<?php

namespace App\Services\Permissions;

use App\Models\Project;
use App\Models\ScopePermission;
use App\Models\Space;
use App\Models\User;
use App\Services\Core\CoreClient;
use App\Support\Permissions\PermissionCatalogue;
use Illuminate\Support\Facades\DB;

/**
 * Resolves whether a user holds a permission inside a given scope.
 *
 * Resolution order (first match wins):
 *   1. global admin bypass;
 *   2. scope access gate (membership in project / space);
 *   3. explicit override at the requested scope (deny before allow);
 *   4. explicit override at the global scope (deny before allow;
 *      a more specific scope therefore wins over a broader one);
 *   5. membership baseline role inside the scope;
 *   6. global role permissions (role_has_permissions).
 */
class PermissionService
{
    public function __construct(private readonly CoreClient $core)
    {
    }

    /** @var array<string, bool> */
    private array $permissionMemo = [];

    /** @var array<string, string|null> */
    private array $roleMemo = [];

    public function can(User $user, string $permission, ?Scope $scope = null): bool
    {
        $scope ??= Scope::global();

        $key = $this->cacheKey($user, $permission, $scope);
        if (array_key_exists($key, $this->permissionMemo)) {
            return $this->permissionMemo[$key];
        }

        return $this->permissionMemo[$key] = $this->resolve($user, $permission, $scope);
    }

    /**
     * @param  array<int, string>  $permissions
     */
    public function canAny(User $user, array $permissions, ?Scope $scope = null): bool
    {
        foreach ($permissions as $permission) {
            if ($this->can($user, $permission, $scope)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Scopes in which the user actually holds the permission.
     *
     * @return array<int, Scope>
     */
    public function scopesFor(User $user, string $permission): array
    {
        $scopes = [];

        if ($this->can($user, $permission, Scope::global())) {
            $scopes[] = Scope::global();
        }

        if (PermissionCatalogue::allowsScope($permission, 'project')) {
            Project::query()
                ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->each(function (Project $project) use ($user, $permission, &$scopes) {
                    if ($this->can($user, $permission, Scope::project($project))) {
                        $scopes[] = Scope::project($project);
                    }
                });
        }

        if (PermissionCatalogue::allowsScope($permission, 'space')) {
            Space::query()
                ->whereHas('members', fn ($q) => $q->where('user_id', $user->id))
                ->orderBy('name')
                ->get(['id', 'name'])
                ->each(function (Space $space) use ($user, $permission, &$scopes) {
                    if ($this->can($user, $permission, Scope::space($space))) {
                        $scopes[] = Scope::space($space);
                    }
                });
        }

        return $scopes;
    }

    /**
     * Permissions the user effectively holds in the given scope.
     *
     * @return array<int, string>
     */
    public function effective(User $user, ?Scope $scope = null): array
    {
        $scope ??= Scope::global();

        return array_values(array_filter(
            PermissionCatalogue::names(),
            fn (string $permission) => $this->can($user, $permission, $scope),
        ));
    }

    public function clearCache(?User $user = null): void
    {
        if ($user === null) {
            $this->permissionMemo = [];
            $this->roleMemo = [];

            return;
        }

        $prefix = $user->getKey() . ':';
        foreach (array_keys($this->permissionMemo) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->permissionMemo[$key]);
            }
        }
        foreach (array_keys($this->roleMemo) as $key) {
            if (str_starts_with($key, $prefix)) {
                unset($this->roleMemo[$key]);
            }
        }
    }

    private function resolve(User $user, string $permission, Scope $scope): bool
    {
        $globalRole = $this->globalRole($user);
        $scopeRole = $this->scopeRole($user, $scope);

        $scopeOverride = $scope->isGlobal() ? null : $this->override($user, $permission, $scope, $scopeRole);
        $globalOverride = $scope->isGlobal() ? null : $this->override($user, $permission, Scope::global(), null);

        // The decision order lives in the compiled service when it is available.
        $requested = $scope->isGlobal() ? $this->override($user, $permission, $scope, $scopeRole) : $scopeOverride;

        $decision = $this->core->permissionDecision([
            'permission' => $permission,
            'is_global_scope' => $scope->isGlobal(),
            'global_role' => $globalRole,
            'scope_role' => $scopeRole,
            // At the global scope the requested-scope overrides ARE the global ones.
            'scope_denies' => $requested === false,
            'scope_allows' => $requested === true,
            'global_denies' => $globalOverride === false,
            'global_allows' => $globalOverride === true,
            'baseline' => $scope->isGlobal() ? [] : PermissionCatalogue::roleBaseline($scope->type, (string) $scopeRole),
            'role_permissions' => $this->globalRolePermissions($user),
        ]);

        if ($decision !== null) {
            return $decision['allow'];
        }

        return $this->resolveLocally($user, $permission, $scope, $globalRole, $scopeRole, $scopeOverride, $globalOverride);
    }

    /**
     * @return array<int, string>
     */
    private function globalRolePermissions(User $user): array
    {
        $roleId = $user->getAttribute('role_id');
        if ($roleId === null) {
            return [];
        }

        return DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $roleId)
            ->pluck('permissions.name')
            ->all();
    }

    /**
     * Reference resolution used when the core service is off.
     */
    private function resolveLocally(
        User $user,
        string $permission,
        Scope $scope,
        ?string $globalRole,
        ?string $scopeRole,
        ?bool $scopeOverride,
        ?bool $globalOverride,
    ): bool {
        // 1. Global admin bypass.
        if ($globalRole === 'admin') {
            return true;
        }

        // 2. Scope access gate.
        if (! $scope->isGlobal() && $scopeRole === null) {
            return false;
        }

        // 3. Requested scope overrides (deny wins inside the level).
        $override = $scope->isGlobal()
            ? $this->override($user, $permission, $scope, $scopeRole)
            : $scopeOverride;

        if ($override !== null) {
            return $override;
        }

        // 4. Global overrides, unless we are already at the global scope.
        if (! $scope->isGlobal() && $globalOverride !== null) {
            return $globalOverride;
        }

        // 5. Membership baseline inside the scope.
        if (! $scope->isGlobal()) {
            return in_array(
                $permission,
                PermissionCatalogue::roleBaseline($scope->type, (string) $scopeRole),
                true,
            );
        }

        // 6. Global role permissions.
        return $this->hasGlobalRolePermission($user, $permission);
    }

    private function override(User $user, string $permission, Scope $scope, ?string $scopeRole): ?bool
    {
        $roleNames = array_values(array_filter([$this->globalRole($user), $scopeRole]));

        $rows = ScopePermission::query()
            ->forScope($scope->type, $scope->id)
            ->where('permission', $permission)
            ->where(function ($query) use ($user, $roleNames) {
                $query->where(function ($q) use ($user) {
                    $q->where('target_type', 'user')
                        ->where('target_value', (string) $user->getKey());
                });

                if ($roleNames !== []) {
                    $query->orWhere(function ($q) use ($roleNames) {
                        $q->where('target_type', 'role')
                            ->whereIn('target_value', $roleNames);
                    });
                }
            })
            ->get(['effect']);

        if ($rows->contains('effect', 'deny')) {
            return false;
        }

        if ($rows->contains('effect', 'allow')) {
            return true;
        }

        return null;
    }

    private function hasGlobalRolePermission(User $user, string $permission): bool
    {
        $roleId = $user->getAttribute('role_id');
        if ($roleId === null) {
            return false;
        }

        return DB::table('role_has_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_has_permissions.permission_id')
            ->where('role_has_permissions.role_id', $roleId)
            ->where('permissions.name', $permission)
            ->exists();
    }

    private function globalRole(User $user): ?string
    {
        $key = $user->getKey() . ':global-role';
        if (array_key_exists($key, $this->roleMemo)) {
            return $this->roleMemo[$key];
        }

        return $this->roleMemo[$key] = $user->role?->name;
    }

    private function scopeRole(User $user, Scope $scope): ?string
    {
        $key = $user->getKey() . ':' . $scope->key();
        if (array_key_exists($key, $this->roleMemo)) {
            return $this->roleMemo[$key];
        }

        $role = match ($scope->type) {
            'project' => DB::table('project_members')
                ->where('project_id', $scope->id)
                ->where('user_id', $user->getKey())
                ->value('role'),
            'space' => DB::table('space_members')
                ->where('space_id', $scope->id)
                ->where('user_id', $user->getKey())
                ->value('role'),
            default => null,
        };

        return $this->roleMemo[$key] = $role !== null ? (string) $role : null;
    }

    private function cacheKey(User $user, string $permission, Scope $scope): string
    {
        $roleVersion = $user->getAttribute('role_id')
            . ':' . ($user->role?->updated_at?->timestamp ?? '0');

        return $user->getKey() . ':' . $permission . ':' . $scope->key() . ':' . $roleVersion;
    }
}
