<?php

namespace App\Services\Storage\Drivers;

use App\Services\Storage\Contracts\StorageDriver;
use App\Services\Storage\StorageException;
use App\Services\Storage\StorageNotConfiguredException;
use App\Services\Storage\StoragePath;
use InvalidArgumentException;

/**
 * Filesystem browser confined to a single local root directory.
 *
 * Kept for backward compatibility with the legacy `storage_path` setting.
 * Unlike remote drivers it keeps realpath()/root confinement (symlink guard).
 */
class LocalDriver implements StorageDriver
{
    public function __construct(private string $root)
    {
    }

    public function list(string $path): array
    {
        $dir = $this->resolve($path);
        if (!is_dir($dir)) {
            throw StorageException::notADirectory();
        }

        $root = $this->root();
        $entries = [];

        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }

            $absolute = $dir . '/' . $name;
            $real = realpath($absolute);
            if ($real === false) {
                continue;
            }

            try {
                $this->assertWithinRoot($real, $root);
            } catch (InvalidArgumentException) {
                continue;
            }

            $entries[] = $this->entryFromAbsolute($real, $name, StoragePath::join($path, $name));
        }

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
        try {
            $absolute = $this->resolve($path);
        } catch (StorageException | InvalidArgumentException) {
            // Missing (or unreachable) paths are reported as absent, matching
            // the contract the other drivers and the trash/restore flows expect.
            return null;
        }

        if (!file_exists($absolute)) {
            return null;
        }

        return $this->entryFromAbsolute($absolute, basename($absolute) ?: $path, StoragePath::normalize($path));
    }

    public function read(string $path): string
    {
        $file = $this->resolve($path);
        if (!is_file($file)) {
            throw StorageException::notAFile();
        }

        $content = @file_get_contents($file);
        if ($content === false) {
            throw StorageException::operationFailed('local', 'локальный диск');
        }

        return $content;
    }

    public function readStream(string $path)
    {
        $file = $this->resolve($path);
        if (!is_file($file)) {
            throw StorageException::notAFile();
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            throw StorageException::operationFailed('local', 'локальный диск');
        }

        return $handle;
    }

    public function readHead(string $path, int $bytes): ?string
    {
        $file = $this->resolve($path);
        if (!is_file($file)) {
            return null;
        }

        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return null;
        }

        $chunk = fread($handle, $bytes) ?: '';
        fclose($handle);

        return $chunk;
    }

    public function write(string $path, string $contents): void
    {
        $file = $this->resolve($path, allowMissing: true);

        if (is_dir($file)) {
            throw new InvalidArgumentException('Нельзя записать поверх каталога');
        }

        $parent = dirname($file);
        if (!is_dir($parent)) {
            throw new InvalidArgumentException('Родительский каталог не найден');
        }
        $this->assertWithinRoot(realpath($parent) ?: $parent, $this->root());

        if (@file_put_contents($file, $contents) === false) {
            throw StorageException::operationFailed('local', 'локальный диск');
        }
    }

    public function writeStream(string $path, $stream): void
    {
        $file = $this->resolve($path, allowMissing: true);

        if (is_dir($file)) {
            throw new InvalidArgumentException('Нельзя записать поверх каталога');
        }

        $parent = dirname($file);
        if (!is_dir($parent)) {
            throw new InvalidArgumentException('Родительский каталог не найден');
        }
        $this->assertWithinRoot(realpath($parent) ?: $parent, $this->root());

        $handle = @fopen($file, 'wb');
        if ($handle === false) {
            throw StorageException::operationFailed('local', 'локальный диск');
        }

        try {
            if (is_resource($stream)) {
                stream_copy_to_stream($stream, $handle);
            } else {
                fwrite($handle, (string) $stream);
            }
        } finally {
            fclose($handle);
        }
    }

    public function createDirectory(string $path): void
    {
        $dir = $this->resolve($path, allowMissing: true);
        if (file_exists($dir)) {
            throw new InvalidArgumentException('Уже существует');
        }
        if (!@mkdir($dir, 0775)) {
            throw StorageException::operationFailed('local', 'локальный диск');
        }
    }

    public function delete(string $path): void
    {
        $target = $this->resolve($path);
        if ($target === $this->root()) {
            throw new InvalidArgumentException('Нельзя удалить корневой каталог');
        }

        if (is_dir($target)) {
            $this->deleteDirectory($target);
        } elseif (!@unlink($target)) {
            throw StorageException::operationFailed('local', 'локальный диск');
        }
    }

    public function isWritable(): bool
    {
        return is_writable($this->root());
    }

    public function displayRoot(): string
    {
        return $this->root();
    }

    private function entryFromAbsolute(string $absolute, string $name, string $relative): array
    {
        $isDir = is_dir($absolute);
        $extension = $isDir ? null : (strtolower(pathinfo($name, PATHINFO_EXTENSION)) ?: null);

        return [
            'path' => StoragePath::normalize($relative),
            'name' => $name,
            'type' => $isDir ? 'dir' : 'file',
            'size' => $isDir ? null : (int) (filesize($absolute) ?: 0),
            'created' => (int) (@filectime($absolute) ?: (@filemtime($absolute) ?: time())),
            'modified' => (int) (@filemtime($absolute) ?: time()),
            'mime' => $isDir ? null : $this->mime($absolute),
        ];
    }

    private function mime(string $file): string
    {
        if (function_exists('mime_content_type')) {
            $mime = @mime_content_type($file);
            if (is_string($mime) && $mime !== '') {
                return $mime;
            }
        }

        return 'application/octet-stream';
    }

    private function resolve(string $relative, bool $allowMissing = false): string
    {
        $relative = StoragePath::normalize($relative);
        $root = $this->root();
        $candidate = $root . ($relative !== '' ? '/' . $relative : '');

        if (file_exists($candidate) || is_link($candidate)) {
            $real = realpath($candidate);
            if ($real === false) {
                throw new InvalidArgumentException('Путь недоступен');
            }
            $this->assertWithinRoot($real, $root);

            return $real;
        }

        if (!$allowMissing) {
            throw StorageException::notFound();
        }

        $parent = realpath(dirname($candidate));
        if ($parent === false) {
            throw new InvalidArgumentException('Родительский каталог не найден');
        }
        $this->assertWithinRoot($parent, $root);

        return $parent . '/' . basename($candidate);
    }

    private function root(): string
    {
        if ($this->root === '' || !is_dir($this->root)) {
            throw new StorageNotConfiguredException();
        }

        $real = realpath($this->root);
        if ($real === false) {
            throw new StorageNotConfiguredException();
        }

        return $real;
    }

    private function assertWithinRoot(string $path, string $root): void
    {
        if ($path !== $root && !str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            throw new InvalidArgumentException('Путь вне хранилища');
        }
    }

    private function deleteDirectory(string $dir): void
    {
        $root = $this->root();
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $path = $dir . '/' . $name;
            $real = realpath($path);
            if ($real === false || $real === $root) {
                continue;
            }
            if (is_dir($real) && !is_link($real)) {
                $this->deleteDirectory($real);
            } else {
                @unlink($real);
            }
        }
        @rmdir($dir);
    }
}
