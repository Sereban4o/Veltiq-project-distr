<?php

namespace App\Services\Storage\Drivers;

use App\Services\Storage\Contracts\StorageDriver;
use App\Services\Storage\StorageAddress;
use App\Services\Storage\StorageConfig;
use App\Services\Storage\StorageException;
use App\Services\Storage\StoragePath;
use Icewind\SMB\AnonymousAuth;
use Icewind\SMB\BasicAuth;
use Icewind\SMB\IFileInfo;
use Icewind\SMB\IShare;
use Icewind\SMB\Options;
use Icewind\SMB\ServerFactory;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use Throwable;

/**
 * SMB/CIFS driver backed by the icewind/smb "Wrapped" backend, which shells
 * out to the `smbclient` binary (no php-smbclient extension required).
 */
class SmbDriver implements StorageDriver
{
    private IShare $share;

    private string $prefix;

    private string $displayRoot;

    private string $host;

    private ExtensionMimeTypeDetector $mime;

    private ?bool $writable = null;

    public function __construct(StorageAddress $address, StorageConfig $config)
    {
        $base = StoragePath::normalize($address->basePath());
        $segments = explode('/', $base);
        $shareName = (string) array_shift($segments);

        $this->prefix = implode('/', $segments);
        $this->displayRoot = $address->display();
        $this->host = $address->host();
        $this->mime = new ExtensionMimeTypeDetector();

        $auth = $config->username()
            ? new BasicAuth($config->username(), $config->domain() ?: null, (string) $config->password())
            : new AnonymousAuth();

        $options = new Options();
        if ($min = config('storage_browser.smb_min_protocol')) {
            $options->setMinProtocol($min);
        }
        if ($max = config('storage_browser.smb_max_protocol')) {
            $options->setMaxProtocol($max);
        }

        try {
            $server = (new ServerFactory($options))->createServer($address->host(), $auth);
            $this->share = $server->getShare($shareName);
        } catch (Throwable) {
            throw new StorageException('SMB-клиент недоступен: не удалось инициализировать smbclient. Убедитесь, что пакет smbclient установлен в контейнере.');
        }
    }

    public function list(string $path): array
    {
        $path = StoragePath::normalize($path);
        $remote = $this->remote($path);

        $infos = $this->run(fn () => $this->share->dir($remote));

        $entries = [];
        foreach ($infos as $info) {
            if (!$info instanceof IFileInfo) {
                continue;
            }

            $name = $info->getName();
            if ($name === '.' || $name === '..' || $name === '') {
                continue;
            }

            $entries[] = $this->entryFromInfo($info, StoragePath::join($path, $name));
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
        $path = StoragePath::normalize($path);

        if ($path === '') {
            return $this->rootEntry();
        }

        try {
            $info = $this->share->stat($this->remote($path));
        } catch (Throwable) {
            return null;
        }

        return $this->entryFromInfo($info, $path);
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);
        $content = stream_get_contents($stream);
        fclose($stream);

        return $content === false ? '' : $content;
    }

    public function readStream(string $path)
    {
        $remote = $this->remote(StoragePath::normalize($path));

        return $this->run(fn () => $this->share->read($remote));
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
        $remote = $this->remote(StoragePath::normalize($path));

        $tmp = tempnam(sys_get_temp_dir(), 'smb');
        if ($tmp === false) {
            throw StorageException::operationFailed('smb', $this->host);
        }

        try {
            file_put_contents($tmp, $contents);
            $this->run(fn () => $this->share->put($tmp, $remote));
        } finally {
            @unlink($tmp);
        }
    }

    public function writeStream(string $path, $stream): void
    {
        $remote = $this->remote(StoragePath::normalize($path));

        $tmp = tempnam(sys_get_temp_dir(), 'smb');
        if ($tmp === false) {
            throw StorageException::operationFailed('smb', $this->host);
        }

        try {
            $handle = @fopen($tmp, 'wb');
            if ($handle === false) {
                throw StorageException::operationFailed('smb', $this->host);
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

            $this->run(fn () => $this->share->put($tmp, $remote));
        } finally {
            @unlink($tmp);
        }
    }

    public function createDirectory(string $path): void
    {
        $remote = $this->remote(StoragePath::normalize($path));

        $this->run(fn () => $this->share->mkdir($remote));
    }

    public function delete(string $path): void
    {
        $path = StoragePath::normalize($path);
        $remote = $this->remote($path);

        try {
            $info = $this->share->stat($remote);
            if ($info->isDirectory()) {
                $this->deleteTree($remote);

                return;
            }
        } catch (Throwable) {
            // Fall through to a plain file delete.
        }

        $this->run(fn () => $this->share->del($remote));
    }

    public function isWritable(): bool
    {
        if ($this->writable !== null) {
            return $this->writable;
        }

        return $this->writable = (function () {
            $probe = '.tasksbase-write-' . bin2hex(random_bytes(6));

            try {
                $this->write($probe, 'ok');
                $this->delete($probe);

                return true;
            } catch (Throwable) {
                return false;
            }
        })();
    }

    public function displayRoot(): string
    {
        return $this->displayRoot;
    }

    private function remote(string $path): string
    {
        $path = StoragePath::normalize($path);

        if ($this->prefix === '') {
            return $path;
        }

        return $path === '' ? $this->prefix : $this->prefix . '/' . $path;
    }

    private function deleteTree(string $remote): void
    {
        $infos = $this->run(fn () => $this->share->dir($remote));

        foreach ($infos as $info) {
            if (!$info instanceof IFileInfo) {
                continue;
            }

            $name = $info->getName();
            if ($name === '.' || $name === '..' || $name === '') {
                continue;
            }

            $child = $remote === '' ? $name : $remote . '/' . $name;
            if ($info->isDirectory()) {
                $this->deleteTree($child);
            } else {
                $this->run(fn () => $this->share->del($child));
            }
        }

        $this->run(fn () => $this->share->rmdir($remote));
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

    private function entryFromInfo(IFileInfo $info, string $relative): array
    {
        $isDir = $info->isDirectory();
        $path = StoragePath::normalize($relative);

        return [
            'path' => $path,
            'name' => StoragePath::name($path),
            'type' => $isDir ? 'dir' : 'file',
            'size' => $isDir ? null : $info->getSize(),
            'created' => $info->getMTime(),
            'modified' => $info->getMTime(),
            'mime' => $isDir ? null : $this->mime->detectMimeTypeFromPath($path),
        ];
    }

    private function run(callable $callback)
    {
        try {
            return $callback();
        } catch (StorageException $e) {
            throw $e;
        } catch (Throwable) {
            throw StorageException::operationFailed('smb', $this->host);
        }
    }
}
