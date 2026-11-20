<?php

namespace App\Http\Controllers\Api\Storage;

use App\Http\Controllers\Api\Storage\Concerns\MapsStorageErrors;
use App\Http\Controllers\Controller;
use App\Services\Storage\StorageAccess;
use App\Services\Storage\StorageTrashService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TrashController extends Controller
{
    use MapsStorageErrors;

    public function __construct(
        private StorageTrashService $trash,
        private StorageAccess $access,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        return $this->storage(function () use ($request) {
            $filters = $request->only(['q', 'owner', 'from', 'to']);
            $perPage = (int) $request->query('per_page', 50);

            $page = $this->trash->list($request->user(), $filters, $perPage);

            return response()->json([
                'data' => collect($page->items())->map(fn ($item) => [
                    'id' => $item->getKey(),
                    'path' => $item->original_path,
                    'name' => basename($item->original_path),
                    'type' => $item->type,
                    'size' => (int) $item->size,
                    'items_count' => (int) $item->items_count,
                    'owner_id' => $item->owner_id,
                    'deleted_by' => $item->deleted_by,
                    'deleted_at' => $item->created_at?->toIso8601String(),
                    'expires_at' => $item->expires_at?->toIso8601String(),
                ])->all(),
                'meta' => [
                    'current_page' => $page->currentPage(),
                    'last_page' => $page->lastPage(),
                    'per_page' => $page->perPage(),
                    'total' => $page->total(),
                ],
            ]);
        });
    }

    public function restore(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'strategy' => 'sometimes|in:rename,overwrite,fail',
        ]);

        return $this->storage(function () use ($request, $id, $validated) {
            $trash = $this->trash->byId($id);
            $this->assertCanManage($request, $trash);

            $result = $this->trash->restore($trash, $validated['strategy'] ?? 'rename');

            return response()->json(['data' => [
                'id' => $result['entry']->getKey(),
                'path' => $result['path'],
                'type' => $result['entry']->type,
                'size' => (int) $result['entry']->size,
            ]]);
        });
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        return $this->storage(function () use ($request, $id) {
            $trash = $this->trash->byId($id);
            $this->assertCanManage($request, $trash);

            $this->trash->purge($trash);

            return response()->json(['message' => 'Удалено безвозвратно']);
        });
    }

    public function empty(Request $request): JsonResponse
    {
        return $this->storage(function () use ($request) {
            $purged = $this->trash->empty($request->user());

            return response()->json(['data' => ['purged' => $purged]]);
        });
    }

    private function assertCanManage(Request $request, $trash): void
    {
        if (!$this->access->canManageTrash($request->user(), $trash)) {
            throw new AuthorizationException('Недостаточно прав для этой операции');
        }
    }
}
