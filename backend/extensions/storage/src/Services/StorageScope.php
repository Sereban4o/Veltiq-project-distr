<?php

namespace App\Extensions\Storage\Services;

use RuntimeException;

/**
 * Resolves the configured storage identity (`disk`) and the local directory
 * tree where version/trash/preview/upload bytes are kept.
 *
 * Keeping these bytes on the application disk (never inside the user's SMB/
 * FTP share) avoids leaking service folders into the file manager listing and
 * keeps remote-driver support simple.
 */
class StorageScope
{
    public function __construct(private StorageDriverFactory $factory)
    {
    }

    public function disk(): string
    {
        return $this->factory->scopeKey();
    }

    public function root(): string
    {
        return rtrim((string) (config('storage_browser.meta_path') ?: storage_path('app/storage-meta')), '/\\');
    }

    public function versionsDir(int $entryId): string
    {
        return $this->ensure($this->root() . '/versions/' . $entryId);
    }

    public function trashDir(string $trashKey): string
    {
        return $this->ensure($this->root() . '/trash/' . $trashKey);
    }

    public function previewsDir(int $entryId): string
    {
        return $this->ensure($this->root() . '/previews/' . $entryId);
    }

    public function uploadsDir(string $uploadId): string
    {
        return $this->ensure($this->root() . '/uploads/' . $uploadId);
    }

    public function tmpDir(): string
    {
        return $this->ensure($this->root() . '/tmp');
    }

    public function ensure(string $directory): string
    {
        if (!is_dir($directory) && !@mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Не удалось подготовить хранилище метаданных');
        }

        return $directory;
    }
}
