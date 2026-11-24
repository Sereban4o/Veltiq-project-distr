<?php

namespace App\Extensions\Storage\Http\Controllers;

use App\Extensions\Storage\Http\Controllers\Concerns\MapsStorageErrors;
use App\Http\Controllers\Controller;
use App\Extensions\Storage\Services\FileBrowserService;
use App\Extensions\Storage\Services\StorageAccess;
use App\Extensions\Storage\Services\StorageShareService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ShareController extends Controller
{
    use MapsStorageErrors;

    public function __construct(
        private FileBrowserService $files,
        private StorageShareService $shares,
        private StorageAccess $access,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['path' => 'required|string|max:512']);

        return $this->storage(function () use ($validated, $request) {
            $entry = $this->files->ensureMeta($validated['path']);
            $this->access->assertCanModify($request->user(), $entry);

            $items = $this->shares->listFor($entry, $request->user())->map(
                fn ($share) => $this->shares->payload($share),
            );

            return response()->json(['data' => $items]);
        });
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'required|string|max:512',
            'password' => 'sometimes|nullable|string|min:4|max:100',
            'expires_in' => 'sometimes|nullable|integer|min:1|max:365',
            'max_downloads' => 'sometimes|nullable|integer|min:1',
            'can_download' => 'sometimes|boolean',
        ]);

        return $this->storage(function () use ($validated, $request) {
            $entry = $this->files->ensureMeta($validated['path']);
            $this->access->assertCanModify($request->user(), $entry);

            $payload = $this->shares->create($entry, $request->user(), [
                'password' => $validated['password'] ?? null,
                'expires_in' => $validated['expires_in'] ?? null,
                'max_downloads' => $validated['max_downloads'] ?? null,
                'can_download' => (bool) ($validated['can_download'] ?? true),
            ]);

            return response()->json(['data' => $payload], 201);
        });
    }

    public function destroy(Request $request, int $id): Response|JsonResponse
    {
        return $this->storage(function () use ($request, $id) {
            $share = $this->shares->byId($id);

            if (!$request->user()->isAdmin() && (int) $share->created_by !== (int) $request->user()->getKey()) {
                abort(403, 'Недостаточно прав для этой операции');
            }

            $this->shares->revoke($share);

            return response()->noContent();
        });
    }
}
