<?php

namespace App\Services\Storage;

use App\Models\StorageMeta;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Owns the `storage_meta` table: lazily mirrors driver entries, and keeps the
 * path/parent bookkeeping consistent on writes, moves and deletes.
 *
 * The storage driver stays the source of truth — rows here only add identity,
 * ownership, version counters and preview state to entries it reports.
 */
class StorageMetadataService
{
    public function __construct(private StorageScope $scope)
    {
    }

    public function disk(): string
    {
        return $this->scope->disk();
    }

    /**
     * Mirror a directory listing, returning live metadata keyed by path.
     *
     * @param  array<int, array{path:string,type:string,size:?int,mime:?string}>  $entries
     * @return array<string, StorageMeta>
     */
    public function syncListing(string $parentPath, array $entries, ?int $userId): array
    {
        $disk = $this->disk();
        $parentPath = StoragePath::normalize($parentPath);

        $existing = StorageMeta::query()
            ->where('disk', $disk)
            ->where('parent_path', $parentPath)
            ->whereNull('deleted_at')
            ->get()
            ->keyBy('path_hash');

        $result = [];

        foreach ($entries as $entry) {
            $path = StoragePath::normalize((string) $entry['path']);
            $this->assertPathLength($path);
            $hash = StoragePath::hash($path);
            $type = $entry['type'] === 'dir' ? 'dir' : 'file';
            $size = $type === 'dir' ? 0 : (int) ($entry['size'] ?? 0);
            $mime = $type === 'dir' ? null : ($entry['mime'] ?? null);

            $meta = $existing->get($hash);

            if ($meta instanceof StorageMeta) {
                if ($meta->size !== $size || $meta->mime !== $mime || $meta->type !== $type) {
                    $meta->forceFill(['size' => $size, 'mime' => $mime, 'type' => $type])->save();
                }
            } else {
                $meta = $this->create($disk, $path, $type, $size, $mime, $userId);
            }

            $result[$path] = $meta;
        }

        return $result;
    }

    /**
     * Return the live metadata row for a path, creating it when absent.
     */
    public function ensureEntry(string $path, string $type, ?int $size, ?string $mime, ?int $userId): StorageMeta
    {
        $path = StoragePath::normalize($path);
        $this->assertPathLength($path);

        $existing = $this->find($path);
        if ($existing instanceof StorageMeta) {
            return $existing;
        }

        $this->ensureAncestors($path, $userId);

        return $this->create(
            $this->disk(),
            $path,
            $type === 'dir' ? 'dir' : 'file',
            $type === 'dir' ? 0 : (int) ($size ?? 0),
            $type === 'dir' ? null : $mime,
            $userId,
        );
    }

    /**
     * Materialise the ancestor directory rows of a path (root itself has none).
     */
    public function ensureAncestors(string $path, ?int $userId): void
    {
        $segments = explode('/', StoragePath::normalize($path));
        array_pop($segments);

        $current = '';
        foreach ($segments as $segment) {
            $current = $current === '' ? $segment : $current . '/' . $segment;

            if ($this->find($current) === null) {
                $this->create($this->disk(), $current, 'dir', 0, null, $userId);
            }
        }
    }

    public function find(string $path): ?StorageMeta
    {
        return StorageMeta::query()
            ->where('disk', $this->disk())
            ->where('path_hash', StoragePath::hash($path))
            ->whereNull('deleted_at')
            ->first();
    }

    /** Find even a trashed row (used when restoring). */
    public function findIncludingDeleted(string $path): ?StorageMeta
    {
        return StorageMeta::query()
            ->where('disk', $this->disk())
            ->where('path_hash', StoragePath::hash($path))
            ->first();
    }

    public function require(string $path): StorageMeta
    {
        $meta = $this->find($path);
        if ($meta === null) {
            throw StorageException::notFound();
        }

        return $meta;
    }

    public function byId(int $id): StorageMeta
    {
        $meta = StorageMeta::query()->find($id);
        if ($meta === null) {
            throw StorageNotFoundException::make();
        }

        return $meta;
    }

    /**
     * Move a subtree (entry + descendants) to a new path, preserving the tail.
     */
    public function renameSubtree(StorageMeta $entry, string $newPath): void
    {
        $newPath = StoragePath::normalize($newPath);
        $this->assertPathLength($newPath);

        $oldPath = $entry->path;
        $oldPrefix = StoragePath::escapeLike($oldPath);
        $disk = $entry->disk;

        DB::transaction(function () use ($entry, $oldPath, $newPath, $oldPrefix, $disk) {
            $entry->forceFill([
                'path' => $newPath,
                'path_hash' => StoragePath::hash($newPath),
                'parent_path' => StoragePath::parent($newPath),
            ])->save();

            StorageMeta::query()
                ->where('disk', $disk)
                ->where('id', '!=', $entry->id)
                ->where(function ($query) use ($oldPath, $oldPrefix) {
                    $query->where('path', $oldPath)
                        ->orWhereRaw("path LIKE ? ESCAPE '!'", [$oldPrefix . '/%']);
                })
                ->get()
                ->each(function (StorageMeta $child) use ($oldPath, $newPath, $oldPrefix) {
                    $tail = substr($child->path, strlen($oldPath));
                    $moved = $newPath . $tail;

                    $child->forceFill([
                        'path' => $moved,
                        'path_hash' => StoragePath::hash($moved),
                        'parent_path' => StoragePath::parent($moved),
                    ])->save();
                });
        });
    }

    /** Soft-delete an entry and all descendants. */
    public function markDeleted(StorageMeta $entry): int
    {
        return $this->subtree($entry)->update(['deleted_at' => now(), 'updated_at' => now()]);
    }

    /** Undo a soft delete for an entry and its descendants. */
    public function restoreSubtree(StorageMeta $entry): int
    {
        return $this->subtree($entry, includeTrashed: true)->update(['deleted_at' => null, 'updated_at' => now()]);
    }

    /** Hard-delete an entry and all descendants (cascades to versions/shares). */
    public function purgeSubtree(StorageMeta $entry): int
    {
        return $this->subtree($entry, includeTrashed: true)->delete();
    }

    public function updateAfterWrite(StorageMeta $entry, int $size, ?string $mime, ?int $userId): void
    {
        $attributes = [
            'size' => $size,
            'mime' => $mime,
            'type' => 'file',
        ];

        if ($entry->owner_id === null && $userId !== null) {
            $attributes['owner_id'] = $userId;
        }

        $entry->forceFill($attributes)->save();
    }

    /**
     * Query builder for an entry's subtree (self + descendants), including
     * trashed rows when requested.
     */
    private function subtree(StorageMeta $entry, bool $includeTrashed = false)
    {
        $prefix = StoragePath::escapeLike($entry->path);

        $query = StorageMeta::query()
            ->where('disk', $entry->disk)
            ->where(function ($query) use ($entry, $prefix) {
                $query->where('path', $entry->path)
                    ->orWhereRaw("path LIKE ? ESCAPE '!'", [$prefix . '/%']);
            });

        return $includeTrashed ? $query : $query->whereNull('deleted_at');
    }

    private function create(string $disk, string $path, string $type, int $size, ?string $mime, ?int $userId): StorageMeta
    {
        $attributes = [
            'disk' => $disk,
            'path' => $path,
            'path_hash' => StoragePath::hash($path),
            'parent_path' => StoragePath::parent($path),
            'type' => $type,
            'size' => $size,
            'mime' => $mime,
            'owner_id' => $userId,
            'created_by' => $userId,
        ];

        try {
            return StorageMeta::query()->create($attributes);
        } catch (QueryException $e) {
            // Concurrent discovery of the same path: return the winning row.
            $existing = $this->find($path);
            if ($existing instanceof StorageMeta) {
                return $existing;
            }

            throw $e;
        }
    }

    private function assertPathLength(string $path): void
    {
        $max = (int) config('storage_browser.max_path_length', 512);
        if (strlen($path) > $max) {
            throw new \InvalidArgumentException('Путь слишком длинный');
        }
    }
}
