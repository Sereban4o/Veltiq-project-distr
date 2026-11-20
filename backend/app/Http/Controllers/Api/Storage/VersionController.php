<?php

namespace App\Http\Controllers\Api\Storage;

use App\Http\Controllers\Api\Storage\Concerns\MapsStorageErrors;
use App\Http\Controllers\Controller;
use App\Models\StorageMeta;
use App\Services\Storage\FileBrowserService;
use App\Services\Storage\StorageAccess;
use App\Services\Storage\StorageVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VersionController extends Controller
{
    use MapsStorageErrors;

    public function __construct(
        private FileBrowserService $files,
        private StorageVersionService $versions,
        private StorageAccess $access,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['path' => 'required|string|max:512']);

        return $this->storage(function () use ($validated, $request) {
            $entry = $this->entry($validated['path']);
            $this->access->assertCanModify($request->user(), $entry);

            $versions = $this->versions->list($entry)->map(fn ($version) => [
                'id' => $version->getKey(),
                'version' => (int) $version->version,
                'size' => (int) $version->size,
                'mime' => $version->mime,
                'source' => $version->source,
                'status' => $version->status,
                'author_id' => $version->author_id,
                'created_at' => $version->created_at?->toIso8601String(),
            ]);

            return response()->json(['data' => $versions]);
        });
    }

    public function restore(Request $request, int $version): JsonResponse
    {
        $validated = $request->validate(['path' => 'required|string|max:512']);

        return $this->storage(function () use ($validated, $version, $request) {
            $entry = $this->entry($validated['path']);
            $this->access->assertCanModify($request->user(), $entry);

            $snapshot = $this->versions->byId($entry, $version);
            $restored = $this->versions->restore($entry, $snapshot, $request->user());

            return response()->json(['data' => [
                'id' => $restored->getKey(),
                'path' => $restored->path,
                'size' => (int) $restored->size,
                'version' => (int) $restored->version,
            ]]);
        });
    }

    public function download(Request $request, int $version): StreamedResponse|JsonResponse
    {
        $validated = $request->validate(['path' => 'required|string|max:512']);

        return $this->storage(function () use ($validated, $version, $request) {
            $entry = $this->entry($validated['path']);
            $this->access->assertCanModify($request->user(), $entry);

            $snapshot = $this->versions->byId($entry, $version);
            $stream = $this->versions->contentStream($snapshot);

            return response()->streamDownload(function () use ($stream) {
                if (is_resource($stream)) {
                    fpassthru($stream);
                    fclose($stream);
                }
            }, basename($entry->path) . '.v' . $snapshot->version);
        });
    }

    public function destroy(Request $request, int $version): JsonResponse
    {
        $validated = $request->validate(['path' => 'required|string|max:512']);

        return $this->storage(function () use ($validated, $version, $request) {
            $entry = $this->entry($validated['path']);
            $this->access->assertCanModify($request->user(), $entry);

            $snapshot = $this->versions->byId($entry, $version);
            $this->versions->delete($entry, $snapshot);

            return response()->json(['message' => 'Версия удалена']);
        });
    }

    private function entry(string $path): StorageMeta
    {
        return $this->files->ensureMeta($path, 'file');
    }
}
