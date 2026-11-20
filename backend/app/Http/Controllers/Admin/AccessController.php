<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Role;
use App\Models\ScopePermission;
use App\Models\Space;
use App\Models\User;
use App\Services\Permissions\PermissionService;
use App\Support\Permissions\PermissionCatalogue;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AccessController extends Controller
{
    public function projects(Request $request)
    {
        return $this->render('project');
    }

    public function spaces(Request $request)
    {
        return $this->render('space');
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'scope_type' => 'required|in:project,space',
                'scope_id' => 'required|integer',
                'target' => ['required', 'string', 'max:255', 'regex:/^(user|role):.+$/'],
                'effect' => 'required|in:allow,deny',
                'permission' => 'required|exists:permissions,name',
            ]);

            [$targetType, $targetValue] = explode(':', $validated['target'], 2);

            $this->assertScopeExists($validated['scope_type'], (int) $validated['scope_id']);
            $this->assertTargetExists($targetType, $targetValue);
            $this->assertPermissionAllowed($validated['permission'], $validated['scope_type']);
        } catch (ValidationException $e) {
            throw $this->invalid($e);
        }

        ScopePermission::updateOrCreate(
            [
                'scope_type' => $validated['scope_type'],
                'scope_id' => (int) $validated['scope_id'],
                'target_type' => $targetType,
                'target_value' => $targetValue,
                'permission' => $validated['permission'],
            ],
            [
                'effect' => $validated['effect'],
                'created_by' => $request->user()?->id,
            ],
        );

        app(PermissionService::class)->clearCache();

        $route = $validated['scope_type'] === 'project' ? 'admin.access.projects' : 'admin.access.spaces';

        return redirect()->route($route)->with('success', 'Правило доступа сохранено');
    }

    public function destroy(Request $request, int $id)
    {
        $override = ScopePermission::findOrFail($id);
        $scopeType = $override->scope_type;
        $override->delete();

        app(PermissionService::class)->clearCache();

        $route = $scopeType === 'project' ? 'admin.access.projects' : 'admin.access.spaces';

        return redirect()->route($route)->with('success', 'Правило доступа удалено');
    }

    private function render(string $scopeType)
    {
        $overrides = ScopePermission::query()
            ->forScope($scopeType, null)
            ->whereNotNull('scope_id')
            ->with('creator')
            ->orderBy('scope_id')
            ->orderBy('permission')
            ->get();

        $scopes = $scopeType === 'project'
            ? Project::orderBy('name')->get(['id', 'name', 'key'])
            : Space::orderBy('name')->get(['id', 'name', 'key']);

        $scopeNames = $scopes->pluck('name', 'id');

        $users = User::orderBy('last_name')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'email']);
        $roles = Role::orderBy('name')->get(['id', 'name']);
        $catalogue = PermissionCatalogue::grouped();

        $view = $scopeType === 'project' ? 'admin.access.projects' : 'admin.access.spaces';

        return view($view, compact('overrides', 'scopes', 'scopeNames', 'users', 'roles', 'catalogue', 'scopeType'));
    }

    /**
     * A rejected rule is answered with 422 (instead of a redirect back) so that
     * programmatic callers receive a machine-readable validation error.
     */
    private function invalid(ValidationException $exception): ValidationException
    {
        return new ValidationException(
            $exception->validator,
            response()->json([
                'message' => 'Правило доступа отклонено',
                'errors' => $exception->errors(),
            ], 422),
        );
    }

    private function assertScopeExists(string $scopeType, int $scopeId): void
    {
        $exists = $scopeType === 'project'
            ? Project::whereKey($scopeId)->exists()
            : Space::whereKey($scopeId)->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['scope_id' => 'Объект не найден']);
        }
    }

    private function assertTargetExists(string $targetType, string $targetValue): void
    {
        $exists = $targetType === 'user'
            ? User::whereKey((int) $targetValue)->exists()
            : Role::where('name', $targetValue)->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['target_value' => 'Пользователь или роль не найдены']);
        }
    }

    private function assertPermissionAllowed(string $permission, string $scopeType): void
    {
        if (! PermissionCatalogue::allowsScope($permission, $scopeType)) {
            throw ValidationException::withMessages([
                'permission' => 'Это право нельзя выдать в выбранной области',
            ]);
        }
    }
}
