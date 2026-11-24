<?php

namespace App\Extensions\Storage\Http\Controllers\Admin;

use App\Extensions\Storage\Http\Controllers\AuditController;
use App\Extensions\Storage\Http\Controllers\Concerns\MapsStorageErrors;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Extensions\Storage\Services\OfficeSettings;
use App\Extensions\Storage\Services\StorageAuditService;
use App\Extensions\Storage\Services\StorageQuotaService;
use App\Extensions\Storage\Services\StorageSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StorageAdminController extends Controller
{
    use MapsStorageErrors;

    public function __construct(
        private StorageQuotaService $quota,
        private StorageSettings $settings,
        private StorageAuditService $audit,
        private OfficeSettings $office,
    ) {
    }

    /** Users and their storage usage, for the admin quota table. */
    public function quotas(Request $request): JsonResponse
    {
        return $this->storage(function () use ($request) {
            $search = trim((string) $request->query('search', ''));
            $perPage = min(100, max(5, (int) $request->query('per_page', 25)));

            $page = User::query()
                ->when($search !== '', function ($query) use ($search) {
                    $like = '%' . $search . '%';
                    $query->where(fn ($q) => $q
                        ->where('email', 'ilike', $like)
                        ->orWhere('first_name', 'ilike', $like)
                        ->orWhere('last_name', 'ilike', $like));
                })
                ->orderBy('id')
                ->paginate($perPage);

            $items = collect($page->items())->map(function (User $user) {
                $summary = $this->quota->summary($user);

                return [
                    'id' => $user->getKey(),
                    'email' => $user->email,
                    'name' => $user->full_name,
                    'quota_bytes' => $user->storage_quota_bytes,
                    'used' => $summary['used'],
                    'used_trash' => $summary['used_trash'],
                    'used_versions' => $summary['used_versions'],
                    'limit' => $summary['limit'],
                    'percent' => $summary['percent'],
                ];
            })->all();

            return response()->json([
                'data' => $items,
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
                'default_quota_bytes' => $this->settings->int('default_quota_bytes'),
            ]);
        });
    }

    public function updateQuota(Request $request, int $userId): JsonResponse
    {
        $validated = $request->validate([
            // null = inherit the global default, -1 = unlimited.
            'quota_bytes' => 'present|nullable|integer|min:-1',
        ]);

        return $this->storage(function () use ($validated, $userId, $request) {
            $user = User::query()->find($userId);
            if ($user === null) {
                abort(404, 'Пользователь не найден');
            }

            $user->forceFill(['storage_quota_bytes' => $validated['quota_bytes']])->save();
            $this->quota->forget($user);

            $this->audit->record('admin.quota.update', payload: [
                'target_user_id' => $user->getKey(),
                'quota_bytes' => $validated['quota_bytes'],
                'by' => $request->user()?->getKey(),
            ]);

            return response()->json(['data' => [
                'id' => $user->getKey(),
                'quota_bytes' => $user->storage_quota_bytes,
                'limit' => $this->quota->limitFor($user),
            ]]);
        });
    }

    public function settings(): JsonResponse
    {
        return $this->storage(fn () => response()->json(['data' => $this->settingsPayload()]));
    }

    public function updateSettings(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'default_quota_bytes' => 'sometimes|integer|min:0',
            'version_max_per_file' => 'sometimes|integer|min:1|max:200',
            'version_retention_days' => 'sometimes|integer|min:1|max:3650',
            'trash_retention_days' => 'sometimes|integer|min:1|max:3650',
            'audit_retention_days' => 'sometimes|integer|min:1|max:3650',
            'previews_enabled' => 'sometimes|boolean',
            'office_autosave_enabled' => 'sometimes|boolean',
        ]);

        return $this->storage(function () use ($validated, $request) {
            $changes = $validated;

            if (array_key_exists('office_autosave_enabled', $validated)) {
                $this->office->putAutosave((bool) $validated['office_autosave_enabled']);
                unset($validated['office_autosave_enabled']);
            }

            foreach ($validated as $key => $value) {
                $this->settings->put($key, is_bool($value) ? ($value ? '1' : '0') : $value);
            }

            $this->audit->record('admin.settings.update', payload: [
                'changes' => $changes,
                'by' => $request->user()?->getKey(),
            ]);

            return response()->json(['data' => $this->settingsPayload()]);
        });
    }

    /** @return array<string, mixed> */
    private function settingsPayload(): array
    {
        return [
            ...$this->settings->all(),
            'office_autosave_enabled' => $this->office->autosaveEnabled(),
        ];
    }

    public function audit(Request $request): JsonResponse
    {
        return $this->storage(fn () => response()->json(app(AuditController::class)->page($request, admin: true)));
    }

    public function recalculate(Request $request): JsonResponse
    {
        return $this->storage(function () use ($request) {
            $only = $request->filled('owner')
                ? User::query()->find((int) $request->query('owner'))
                : null;

            return response()->json(['data' => ['users_updated' => $this->quota->recalculate($only)]]);
        });
    }
}
