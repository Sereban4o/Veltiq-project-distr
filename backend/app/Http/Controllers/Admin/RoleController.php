<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Services\Permissions\PermissionService;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * Replace the global permissions attached to a role.
     */
    public function syncPermissions(Request $request, Role $role)
    {
        $validated = $request->validate([
            'permission_ids' => 'sometimes|array',
            'permission_ids.*' => 'integer|exists:permissions,id',
        ]);

        $role->permissions()->sync($validated['permission_ids'] ?? []);

        app(PermissionService::class)->clearCache();

        return redirect()->route('admin.roles.index')->with('success', 'Права роли обновлены');
    }
}
