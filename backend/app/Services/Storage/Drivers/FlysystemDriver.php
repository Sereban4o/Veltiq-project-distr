<?php

namespace App\Services\Storage\Drivers;

use App\Services\Storage\Contracts\StorageDriver;
use App\Services\Storage\StorageAddress;
use App\Services\Storage\StorageConfig;
use App\Services\Storage\StorageException;
use App\Services\Storage\StoragePath;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\StorageAttributes;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use Throwable;

/**
 * Shared implementation for protocols backed by a Flysystem v3 adapter
 * (SFTP, FTP/FTPS).
 */
abstract class FlysystemDriver implements StorageDriver
{
    protected Filesystem $fs;

    protected StorageAddress $address;

    protected StorageConfig $config;

    private ExtensionMimeTypeDetector $mime;

    private ?bool $writable = null;

    public function __construct(StorageAddress $address, StorageConfig $config)
    {
        $this->address = $address;
        $this->config = $config;
        $this->mime = new ExtensionMimeTypeDetector();
        $this->fs = $this->createFilesystem();
    }

    abstract protected function createFilesystem(): Filesystem;

    public function list(string $path): array
    {
        $path = StoragePath::normalize($path);

        $entries = $this->wrap('list', function () use ($path) {
            $entries = [];
            foreach ($this->fs->listContents($path, false) as $item) {
                if ($item instanceof StorageAttributes) {
                    $entries[] = $this->entryFromAttributes($item);
                }
            }

            return $entries;
        });

        usort($entries, function (array $a, array $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $entries;
    }

    public function stat(string $path): ?array
    {
        $path = StoragePath::normalize($path);

        if ($path === '') {
            return $this->rootEntry();
        }

        return $this->wrap('stat', function () use ($path) {
            $parent = StoragePath::parent($path);

            foreach ($this->fs->listContents($parent, false) as $item) {
                if ($item instanceof StorageAttributes && StoragePath::normalize($item->path()) === $path) {
                    return $this->entryFromAttributes($item);
                }
            }

            return null;
        });
    }

    public function read(string $path): string
    {
        $path = StoragePath::normalize($path);

        return $this->wrap('read', fn () => $this->fs->read($path));
    }

    public function readStream(string $path)
    {
        $path = StoragePath::normalize($path);

        return $this->wrap('read', fn () => $this->fs->readStream($path));
    }

    public function readHead(string $path, int $bytes): ?string
    {
        try {
            $stream = $this->readStream($path);
        } catch (Throwable) {
            return null;
        }

        if (!is_resource($stream)) {
            return null;
        }

        $chunk = fread($stream, $bytes) ?: '';
        fclose($stream);

        return $chunk;
    }

    public function write(string $path, string $contents): void
    {
        $path = StoragePath::normalize($path);

        $this->wrap('write', fn () => $this->fs->write($path, $contents));
    }

    public function writeStream(string $path, $stream): void
    {
        $path = StoragePath::normalize($path);

        $this->wrap('write', fn () => $this->fs->writeStream($path, $stream));
    }

    public function createDirectory(string $path): void
    {
        $path = StoragePath::normalize($path);

        $this->wrap('mkdir', fn () => $this->fs->createDirectory($path));
    }

    public function delete(string $path): void
    {
        $path = StoragePath::normalize($path);

        $this->wrap('delete', function () use ($path) {
            if ($this->fs->directoryExists($path)) {
                $this->fs->deleteDirectory($path);
            } else {
                $this->fs->delete($path);
            }
        });
    }

    public function isWritable(): bool
    {
        if ($this->writable !== null) {
            return $this->writable;
        }

        return $this->writable = (function () {
            $probe = '.tasksbase-write-' . bin2hex(random_bytes(6));

            try {
                $this->fs->write($probe, 'ok');
                $this->fs->delete($probe);

                return true;
            } catch (Throwable) {
                return false;
            }
        })();
    }

    public function displayRoot(): string
    {
        return $this->address->display();
    }

    /**
     * @return array{path:string,name:string,type:'dir'|'file',size:?int,created:?int,modified:?int,mime:?string}
     */
    private function rootEntry(): array
    {
        return [
            'path' => '',
            'name' => '',
            'type' => 'dir',
            'size' => null,
            'created' => null,
            'modified' => null,
            'mime' => null,
        ];
    }

    private function entryFromAttributes(StorageAttributes $item): array
    {
        $isDir = $item->isDir();
        $path = StoragePath::normalize($item->path());

        return [
            'path' => $path,
            'name' => StoragePath::name($path),
            'type' => $isDir ? 'dir' : 'file',
            'size' => $isDir ? null : (int) ($item instanceof FileAttributes ? ($item->fileSize() ?? 0) : 0),
            'created' => $item->lastModified(),
            'modified' => $item->lastModified(),
            'mime' => $isDir ? null : ($this->mime->detectMimeTypeFromPath($path) ?? ($item instanceof FileAttributes ? $item->mimeType() : null)),
        ];
    }

    protected function wrap(string $action, callable $callback)
    {
        try {
            return $callback();
        } catch (StorageException $e) {
            throw $e;
        } catch (Throwable) {
            throw StorageException::operationFailed($this->address->protocol(), $this->address->host());
        }
    }
}
