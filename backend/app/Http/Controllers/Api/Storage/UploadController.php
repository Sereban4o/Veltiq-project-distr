<?php

namespace App\Http\Controllers\Api\Storage;

use App\Http\Controllers\Api\Storage\Concerns\MapsStorageErrors;
use App\Http\Controllers\Controller;
use App\Services\Storage\StorageUploadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class UploadController extends Controller
{
    use MapsStorageErrors;

    public function __construct(private StorageUploadService $uploads)
    {
    }

    /** Direct (single request) upload. */
    public function store(Request $request): JsonResponse
    {
        $maxKilobytes = (int) floor((int) config('storage_browser.max_upload_size', 512 * 1024 * 1024) / 1024);

        $validated = $request->validate([
            'path' => 'sometimes|nullable|string|max:512',
            'relative_path' => 'sometimes|nullable|string|max:512',
            'overwrite' => 'sometimes|boolean',
            'file' => 'required|file|max:' . $maxKilobytes,
        ]);

        return $this->storage(function () use ($request, $validated) {
            $result = $this->uploads->upload(
                $request->file('file'),
                (string) ($validated['path'] ?? ''),
                $validated['relative_path'] ?? null,
                $request->user(),
                (bool) ($validated['overwrite'] ?? false),
            );

            return response()->json(['data' => $this->entryPayload($result['entry'])], 201);
        });
    }

    /** Start a resumable upload session. */
    public function createSession(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'sometimes|nullable|string|max:512',
            'name' => 'required|string|max:512',
            'size' => 'required|integer|min:1',
            'checksum' => 'sometimes|nullable|string|size:64',
        ]);

        return $this->storage(function () use ($request, $validated) {
            $session = $this->uploads->createSession(
                $request->user(),
                (string) ($validated['path'] ?? ''),
                $validated['name'],
                (int) $validated['size'],
                $validated['checksum'] ?? null,
            );

            return response()->json(['data' => $session], 201);
        });
    }

    /** Store one chunk (raw octet-stream body). */
    public function putChunk(Request $request, string $uploadId, int $index): Response|JsonResponse
    {
        return $this->storage(function () use ($request, $uploadId, $index) {
            $this->uploads->putChunk(
                $request->user(),
                $uploadId,
                $index,
                $request->getContent(),
                $request->header('X-Chunk-Checksum'),
            );

            return response()->noContent();
        });
    }

    public function status(Request $request, string $uploadId): JsonResponse
    {
        return $this->storage(function () use ($request, $uploadId) {
            return response()->json(['data' => $this->uploads->status($request->user(), $uploadId)]);
        });
    }

    public function complete(Request $request, string $uploadId): JsonResponse
    {
        $validated = $request->validate([
            'checksum' => 'sometimes|nullable|string|size:64',
        ]);

        return $this->storage(function () use ($request, $uploadId, $validated) {
            $result = $this->uploads->complete($request->user(), $uploadId, $validated['checksum'] ?? null);

            return response()->json(['data' => $this->entryPayload($result['entry'])], 201);
        });
    }

    public function cancel(Request $request, string $uploadId): Response|JsonResponse
    {
        return $this->storage(function () use ($request, $uploadId) {
            $this->uploads->cancel($request->user(), $uploadId);

            return response()->noContent();
        });
    }

    /** @return array<string, mixed> */
    private function entryPayload($entry): array
    {
        return [
            'id' => $entry->getKey(),
            'path' => $entry->path,
            'name' => basename($entry->path),
            'size' => (int) $entry->size,
            'version' => (int) $entry->version,
        ];
    }
}
