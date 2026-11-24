<?php

namespace App\Extensions\Storage\Services;

use App\Models\StorageMeta;
use App\Models\StorageVersion;
use App\Models\User;
use Throwable;

/**
 * File version history.
 *
 * Snapshots are taken before an existing file is overwritten (edit, upload,
 * ONLYOFFICE save, restore). Bytes live on the application disk, never in the
 * user's share, so remote drivers need no special support.
 */
class StorageVersionService
{
    public function __construct(
        private StorageScope $scope,
        private StorageMetadataService $metadata,
        private StorageAuditService $audit,
        private StorageDriverFactory $factory,
    ) {
    }

    /**
     * Snapshot the current content of $entry before it is overwritten.
     * Returns the created version, or null when there was nothing to snapshot.
     */
    public function snapshot(StorageMeta $entry, ?User $author, string $source = 'edit', ?string $officeKey = null): ?StorageVersion
    {
        if ($entry->type !== 'file' || $entry->size <= 0) {
            return null;
        }

        $next = ((int) $entry->version) + 1;
        $maxBytes = (int) config('storage_browser.version_max_bytes', 100 * 1024 * 1024);

        if ($entry->size > $maxBytes) {
            $entry->forceFill(['version' => $next])->save();

            return StorageVersion::query()->create([
                'entry_id' => $entry->getKey(),
                'disk' => $entry->disk,
                'path' => $entry->path,
                'version' => $next,
                'size' => (int) $entry->size,
                'mime' => $entry->mime,
                'source' => $source,
                'author_id' => $author?->getKey(),
                'office_key' => $officeKey,
                'status' => 'skipped',
                'error' => 'Файл слишком большой для хранения версии',
                'created_at' => now(),
            ]);
        }

        $target = $this->scope->versionsDir($entry->getKey()) . '/' . $next . '.bin';

        $checksum = $this->writeDriverContentToFile($entry, $target);

        if ($checksum === null) {
            return null;
        }

        $latest = StorageVersion::query()
            ->where('entry_id', $entry->getKey())
            ->where('status', 'ready')
            ->orderByDesc('version')
            ->first();

        if ($latest !== null && $latest->checksum === $checksum) {
            @unlink($target);
            $entry->forceFill(['version' => $next])->save();

            return null;
        }

        $entry->forceFill(['version' => $next])->save();

        $version = StorageVersion::query()->create([
            'entry_id' => $entry->getKey(),
            'disk' => $entry->disk,
            'path' => $entry->path,
            'version' => $next,
            'size' => (int) $entry->size,
            'mime' => $entry->mime,
            'checksum' => $checksum,
            'source' => $source,
            'author_id' => $author?->getKey(),
            'office_key' => $officeKey,
            'status' => 'ready',
            'created_at' => now(),
        ]);

        $this->audit->record('version.create', entry: $entry, payload: ['version' => $next, 'source' => $source], size: (int) $entry->size);
        $this->prune($entry);

        return $version;
    }

    /** @return \Illuminate\Support\Collection<int, StorageVersion> */
    public function list(StorageMeta $entry)
    {
        return StorageVersion::query()
            ->where('entry_id', $entry->getKey())
            ->orderByDesc('version')
            ->get();
    }

    public function byId(StorageMeta $entry, int $versionId): StorageVersion
    {
        $version = StorageVersion::query()
            ->where('entry_id', $entry->getKey())
            ->where('version', $versionId)
            ->first()
            ?? StorageVersion::query()->where('entry_id', $entry->getKey())->whereKey($versionId)->first();

        if ($version === null) {
            throw StorageNotFoundException::make();
        }

        return $version;
    }

    /** @return resource */
    public function contentStream(StorageVersion $version)
    {
        $path = $this->scope->versionsDir((int) $version->entry_id) . '/' . $version->version . '.bin';

        if ($version->status !== 'ready' || !is_file($path)) {
            throw new StorageNotFoundException();
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw StorageException::notFound();
        }

        return $handle;
    }

    /** Restore a snapshot, first archiving the current content as a new version. */
    public function restore(StorageMeta $entry, StorageVersion $version, ?User $author): StorageMeta
    {
        if ($version->status !== 'ready') {
            throw new StorageNotFoundException();
        }

        $stream = $this->contentStream($version);
        $size = 0;

        try {
            $driver = $this->factory->make();
            $driver->writeStream($entry->path, $stream);
            $size = (int) $version->size;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        // The just-restored content becomes the entry's current body; snapshot
        // the previous content afterwards so history stays linear.
        $this->metadata->updateAfterWrite($entry, $size, $version->mime, $author?->getKey());
        $entry->forceFill(['preview_status' => 'pending', 'preview_at' => null])->save();

        $this->audit->record('version.restore', entry: $entry, payload: ['version' => $version->version], size: $size);

        return $entry->refresh();
    }

    public function delete(StorageMeta $entry, StorageVersion $version): void
    {
        $path = $this->scope->versionsDir($entry->getKey()) . '/' . $version->version . '.bin';
        if (is_file($path)) {
            @unlink($path);
        }

        $version->delete();
        $this->audit->record('version.delete', entry: $entry, payload: ['version' => $version->version]);
    }

    /** Enforce per-file count/age limits. */
    public function prune(StorageMeta $entry): int
    {
        $maxPerFile = (int) config('storage_browser.version_max_per_file', 20);
        $retentionDays = (int) config('storage_browser.version_retention_days', 30);
        $removed = 0;

        $stale = StorageVersion::query()
            ->where('entry_id', $entry->getKey())
            ->where('status', '!=', 'skipped')
            ->where('created_at', '<', now()->subDays($retentionDays))
            ->get();

        foreach ($stale as $version) {
            $this->delete($entry, $version);
            $removed++;
        }

        $remaining = StorageVersion::query()
            ->where('entry_id', $entry->getKey())
            ->orderByDesc('version')
            ->pluck('id')
            ->slice($maxPerFile);

        foreach ($remaining as $id) {
            $version = StorageVersion::query()->find($id);
            if ($version !== null) {
                $this->delete($entry, $version);
                $removed++;
            }
        }

        return $removed;
    }

    /** Stream the current driver content into a local file, returning its sha256. */
    private function writeDriverContentToFile(StorageMeta $entry, string $target): ?string
    {
        try {
            $stream = $this->factory->make()->readStream($entry->path);
        } catch (Throwable) {
            return null;
        }

        $out = @fopen($target, 'wb');
        if ($out === false) {
            if (is_resource($stream)) {
                fclose($stream);
            }

            return null;
        }

        $context = hash_init('sha256');

        try {
            while (!feof($stream)) {
                $chunk = fread($stream, 262144);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                hash_update($context, $chunk);
                fwrite($out, $chunk);
            }
        } catch (Throwable) {
            @unlink($target);

            return null;
        } finally {
            fclose($out);
            if (is_resource($stream)) {
                fclose($stream);
            }
        }

        return hash_final($context);
    }
}
