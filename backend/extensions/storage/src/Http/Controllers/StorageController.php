<?php

namespace App\Extensions\Storage\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Extensions\Storage\Services\FileBrowserService;
use App\Extensions\Storage\Services\StorageAccess;
use App\Extensions\Storage\Services\StorageConflictException;
use App\Extensions\Storage\Services\StorageNotFoundException;
use App\Extensions\Storage\Services\StorageTrashService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

class StorageController extends Controller
{
    public function __construct(
        private FileBrowserService $files,
        private StorageTrashService $trash,
        private StorageAccess $access,
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if (!$this->files->isConfigured()) {
            return response()->json([
                'data' => [
                    'configured' => false,
                    'path' => '',
                    'root' => null,
                    'writable' => false,
                    'entries' => [],
                    'message' => 'Файловое хранилище не настроено. Обратитесь к администратору.',
                ],
            ]);
        }

        return $this->handle(function () use ($request) {
            $path = $this->files->normalize((string) $request->query('path', ''));
            $describe = $this->files->describe();

            return response()->json([
                'data' => [
                    'configured' => true,
                    'protocol' => $describe['protocol'],
                    'address' => $describe['address'],
                    'path' => $path,
                    'root' => $this->files->root(),
                    'writable' => $this->files->isWritable(),
                    'entries' => $this->files->list($path),
                ],
            ]);
        });
    }

    public function show(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            $path = (string) $request->query('path', '');

            return response()->json(['data' => $this->files->read($path)]);
        });
    }

    public function save(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'required|string|max:4096',
            'content' => 'present|string',
        ]);

        return $this->handle(function () use ($validated) {
            return response()->json([
                'data' => $this->files->write($validated['path'], $validated['content']),
            ]);
        });
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'required|string|max:4096',
            'type' => 'required|in:file,dir',
            'content' => 'sometimes|nullable|string',
        ]);

        return $this->handle(function () use ($validated) {
            if ($validated['type'] === 'dir') {
                $this->files->makeDirectory($validated['path']);
            } else {
                $this->files->write($validated['path'], $validated['content'] ?? '');
            }

            return response()->json(['message' => 'Создано'], 201);
        });
    }

    public function move(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'from' => 'required|string|max:4096',
            'to' => 'required|string|max:4096',
            'overwrite' => 'sometimes|boolean',
        ]);

        return $this->handle(function () use ($validated, $request) {
            $path = $this->files->move(
                $validated['from'],
                $validated['to'],
                (bool) ($validated['overwrite'] ?? false),
                $request->user(),
            );

            return response()->json(['data' => ['path' => $path]]);
        });
    }

    public function destroy(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'path' => 'required|string|max:4096',
            'permanent' => 'sometimes|boolean',
        ]);

        return $this->handle(function () use ($validated, $request) {
            $path = $this->files->normalize($validated['path']);

            if ($path === '') {
                throw new InvalidArgumentException('Нельзя удалить корневой каталог');
            }

            $user = $request->user();
            $entry = $this->files->ensureMeta($path);
            $this->access->assertCanModify($user, $entry);

            if ((bool) ($validated['permanent'] ?? false)) {
                if ($user === null || !$user->isAdmin()) {
                    throw new AuthorizationException('Недостаточно прав для этой операции');
                }

                $trash = $this->trash->trash($entry, $user);
                $this->trash->purge($trash);

                return response()->json(['message' => 'Удалено безвозвратно']);
            }

            $trash = $this->trash->trash($entry, $user);

            return response()->json([
                'data' => [
                    'trashed' => true,
                    'trash_id' => $trash->getKey(),
                ],
                'message' => 'Перемещено в корзину',
            ]);
        });
    }

    public function download(Request $request): Response
    {
        return $this->handle(function () use ($request) {
            return $this->files->downloadResponse((string) $request->query('path', ''));
        });
    }

    public function raw(Request $request): Response
    {
        return $this->handle(function () use ($request) {
            return $this->files->rawResponse((string) $request->query('path', ''));
        });
    }

    public function preview(Request $request): Response
    {
        return $this->handle(function () use ($request) {
            $info = $this->files->preview(
                (string) $request->query('path', ''),
                (string) $request->query('size', 'thumb'),
            );

            if (($info['status'] ?? '') === 'ready' && isset($info['path'])) {
                $contents = @file_get_contents($info['path']);

                if ($contents !== false) {
                    return response($contents, 200, [
                        'Content-Type' => $info['mime'] ?? 'image/jpeg',
                        'Cache-Control' => 'private, max-age=3600',
                        'ETag' => (string) ($info['etag'] ?? ''),
                    ]);
                }
            }

            return response()->json(['status' => $info['status'] ?? 'none'], 202);
        });
    }

    public function archive(Request $request): JsonResponse
    {
        return $this->handle(function () use ($request) {
            return response()->json([
                'data' => $this->files->archive((string) $request->query('path', '')),
            ]);
        });
    }

    /**
     * Run a handler and translate storage errors into JSON responses.
     */
    private function handle(callable $callback)
    {
        try {
            return $callback();
        } catch (StorageConflictException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'conflict',
                'data' => $e->payload(),
            ], 409);
        } catch (StorageNotFoundException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'not_found'], 404);
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'forbidden'], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'invalid_path'], 422);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }
}
