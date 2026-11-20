<?php

namespace App\Services\Storage;

use App\Models\StorageMeta;
use App\Models\StorageTrash;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Trash / recycle bin. Deleted entries are copied to the application disk
 * (preserving their relative path), removed from the driver, and their
 * metadata rows are soft-deleted. Restore copies the bytes back.
 */
class StorageTrashService
{
    public function __construct(
        private StorageScope $scope,
        private StorageMetadataService $metadata,
        private StorageAuditService $audit,
        private StorageDriverFactory $factory,
        private StorageQuotaService $quota,
    ) {
    }

    /**
     * Move a live entry (file or directory) into the trash.
     */
    public function trash(StorageMeta $entry, ?User $user): StorageTrash
    {
        $this->assertWithinLimits($entry, $user);

        $trashKey = (string) Str::uuid();
        $localRoot = $this->scope->trashDir($trashKey);
        $target = $localRoot . '/' . StoragePath::name($entry->path);

        [$bytes, $items] = $this->copyFromDriver($entry->path, $target);

        $this->factory->make()->delete($entry->path);

        $trash = DB::transaction(function () use ($entry, $trashKey, $user, $bytes, $items) {
            $trash = StorageTrash::query()->create([
                'entry_id' => $entry->getKey(),
                'disk' => $entry->disk,
                'original_path' => $entry->path,
                'original_path_hash' => StoragePath::hash($entry->path),
                'parent_path' => StoragePath::parent($entry->path),
                'type' => $entry->type,
                'size' => $bytes,
                'items_count' => $items,
                'owner_id' => $entry->owner_id,
                'deleted_by' => $user?->getKey(),
                'trash_key' => $trashKey,
                'expires_at' => now()->addDays((int) config('storage_browser.trash_retention_days', 30)),
            ]);

            $this->metadata->markDeleted($entry);

            return $trash;
        });

        $this->audit->record('file.trash', entry: $entry, payload: ['trash_id' => $trash->getKey(), 'items' => $items], size: $bytes);
        $this->quota->forget($user ?? $entry->owner);

        return $trash;
    }

    public function list(?User $user, array $filters = [], int $perPage = 50)
    {
        $query = StorageTrash::query()->whereNull('purged_at');

        if ($user !== null && !$user->isAdmin()) {
            $query->where('owner_id', $user->getKey());
        } elseif (!empty($filters['owner'])) {
            $query->where('owner_id', (int) $filters['owner']);
        }

        if (!empty($filters['q'])) {
            $query->where('original_path', 'ilike', '%' . $filters['q'] . '%');
        }

        if (!empty($filters['from'])) {
            $query->where('created_at', '>=', $filters['from']);
        }

        if (!empty($filters['to'])) {
            $query->where('created_at', '<=', $filters['to']);
        }

        return $query->orderByDesc('created_at')->paginate(min(100, max(1, $perPage)));
    }

    public function byId(int $id): StorageTrash
    {
        $trash = StorageTrash::query()->whereNull('purged_at')->find($id);

        if ($trash === null) {
            throw StorageNotFoundException::make();
        }

        return $trash;
    }

    /**
     * Restore a trashed entry.
     *
     * @return array{entry: StorageMeta, path: string}
     */
    public function restore(StorageTrash $trash, string $strategy = 'rename'): array
    {
        $entry = StorageMeta::query()->find($trash->entry_id);
        if ($entry === null) {
            throw StorageNotFoundException::make();
        }

        $driver = $this->factory->make();
        $target = $trash->original_path;

        $exists = $driver->stat($target) !== null;

        if ($exists) {
            if ($strategy === 'fail') {
                throw StorageConflictException::at($target);
            }

            if ($strategy === 'overwrite') {
                $existing = $this->metadata->find($target);
                if ($existing !== null) {
                    $driver->delete($target);
                    $this->metadata->markDeleted($existing);
                }
            } else {
                $target = $this->uniqueRestorePath($driver, $target);
            }
        }

        $this->ensureDriverDirectories($driver, StoragePath::parent($target));

        $source = $this->scope->trashDir($trash->trash_key) . '/' . StoragePath::name($trash->original_path);
        $this->copyToDriver($source, $target, $driver);

        DB::transaction(function () use ($entry, $trash, $target) {
            if ($target !== $entry->path) {
                $this->metadata->renameSubtree($entry, $target);
            }

            $this->metadata->restoreSubtree($entry);
            $trash->delete();
        });

        $this->audit->record('file.restore', entry: $entry->refresh(), payload: ['from_trash' => $trash->getKey()]);
        $this->quota->forget($entry->owner);

        @$this->removeDirectory($trash->trash_key);

        return ['entry' => $entry->refresh(), 'path' => $target];
    }

    public function purge(StorageTrash $trash): void
    {
        $entry = StorageMeta::query()->find($trash->entry_id);

        $this->removeDirectory($trash->trash_key);

        DB::transaction(function () use ($trash, $entry) {
            if ($entry !== null) {
                $this->metadata->purgeSubtree($entry);
            }

            $trash->forceFill(['purged_at' => now()])->save();
            $trash->delete();
        });

        $this->audit->record('file.purge', entry: $entry, path: $trash->original_path, payload: ['trash_id' => $trash->getKey()]);
        $this->quota->forget($trash->owner);
    }

    /** Purge every trash item (of a user, or all when $user is an admin). */
    public function empty(?User $user): int
    {
        $query = StorageTrash::query()->whereNull('purged_at');

        if ($user !== null && !$user->isAdmin()) {
            $query->where('owner_id', $user->getKey());
        }

        $count = 0;
        foreach ($query->get() as $trash) {
            $this->purge($trash);
            $count++;
        }

        return $count;
    }

    /** Purge items past their retention date. */
    public function prune(): int
    {
        $count = 0;
        foreach (StorageTrash::query()->whereNull('purged_at')->where('expires_at', '<', now())->get() as $trash) {
            $this->purge($trash);
            $count++;
        }

        return $count;
    }

    private function assertWithinLimits(StorageMeta $entry, ?User $user): void
    {
        $ownerId = $entry->owner_id;

        $items = StorageTrash::query()->whereNull('purged_at')
            ->when($ownerId !== null, fn ($q) => $q->where('owner_id', $ownerId))
            ->count();

        if ($items >= (int) config('storage_browser.trash_max_items', 5000)) {
            throw StorageConflictException::trashFull('Корзина заполнена: удалите записи безвозвратно.');
        }

        $bytes = (int) StorageTrash::query()->whereNull('purged_at')
            ->when($ownerId !== null, fn ($q) => $q->where('owner_id', $ownerId))
            ->sum('size');

        if ($bytes + (int) $entry->size > (int) config('storage_browser.trash_max_bytes', 1024 * 1024 * 1024)) {
            throw StorageConflictException::trashFull('Корзина превышает допустимый размер: удалите записи безвозвратно.');
        }
    }

    private function uniqueRestorePath($driver, string $path): string
    {
        $parent = StoragePath::parent($path);
        $name = StoragePath::name($path);
        $extension = StoragePath::extension($path);
        $base = $extension === null ? $name : substr($name, 0, -(strlen($extension) + 1));
        $suffix = $extension === null ? '' : '.' . $extension;

        for ($i = 1; $i <= 500; $i++) {
            $candidate = StoragePath::join($parent, $base . ' (восстановлено ' . $i . ')' . $suffix);
            if ($driver->stat($candidate) === null) {
                return $candidate;
            }
        }

        return StoragePath::join($parent, $base . ' (восстановлено ' . Str::random(6) . ')' . $suffix);
    }

    /** @return array{0:int,1:int} bytes, items */
    private function copyFromDriver(string $path, string $localTarget): array
    {
        $driver = $this->factory->make();
        $entry = $driver->stat($path);

        if ($entry === null) {
            throw StorageException::notFound();
        }

        if ($entry['type'] === 'dir') {
            $this->scope->ensure($localTarget);
            $bytes = 0;
            $items = 0;

            foreach ($driver->list($path) as $child) {
                [$childBytes, $childItems] = $this->copyFromDriver($child['path'], $localTarget . '/' . $child['name']);
                $bytes += $childBytes;
                $items += $childItems;
            }

            return [$bytes, $items];
        }

        $stream = $driver->readStream($path);
        $out = @fopen($localTarget, 'wb');
        if ($out === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            throw new StorageException('Не удалось сохранить файл в корзину');
        }

        $bytes = 0;

        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 262144);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $bytes += strlen($chunk);
                fwrite($out, $chunk);
            }
        } finally {
            fclose($out);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return [$bytes, 1];
    }

    private function copyToDriver(string $localSource, string $target, $driver): void
    {
        if (is_dir($localSource)) {
            $driver->createDirectory($target);

            foreach (scandir($localSource) ?: [] as $name) {
                if ($name === '.' || $name === '..') {
                    continue;
                }
                $this->copyToDriver($localSource . '/' . $name, StoragePath::join($target, $name), $driver);
            }

            return;
        }

        $stream = fopen($localSource, 'rb');
        if ($stream === false) {
            throw new StorageException('Файл в корзине не найден');
        }

        try {
            $driver->writeStream($target, $stream);
        } finally {
            fclose($stream);
        }
    }

    private function ensureDriverDirectories($driver, string $path): void
    {
        $path = StoragePath::normalize($path);
        if ($path === '') {
            return;
        }

        $segments = explode('/', $path);
        $current = '';
        foreach ($segments as $segment) {
            $current = $current === '' ? $segment : $current . '/' . $segment;

            if ($driver->stat($current) === null) {
                try {
                    $driver->createDirectory($current);
                } catch (Throwable) {
                    // Already created by a concurrent request.
                }
            }
        }
    }

    private function removeDirectory(string $trashKey): void
    {
        $dir = $this->scope->root() . '/trash/' . $trashKey;
        if (!is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) ? $this->removeDirectoryPath($path) : @unlink($path);
        }

        @rmdir($dir);
    }

    private function removeDirectoryPath(string $dir): void
    {
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            is_dir($path) ? $this->removeDirectoryPath($path) : @unlink($path);
        }

        @rmdir($dir);
    }
}
