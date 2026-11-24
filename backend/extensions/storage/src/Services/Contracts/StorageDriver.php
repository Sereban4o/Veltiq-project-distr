<?php

namespace App\Extensions\Storage\Services\Contracts;

/**
 * A single configured file storage. All paths are connection-relative,
 * root-scoped, normalized POSIX strings ('' = configured root).
 */
interface StorageDriver
{
    /**
     * @return array<int, array{path:string,name:string,type:'dir'|'file',size:?int,created:?int,modified:?int,mime:?string}>
     */
    public function list(string $path): array;

    /**
     * @return array{path:string,name:string,type:'dir'|'file',size:?int,created:?int,modified:?int,mime:?string}|null
     */
    public function stat(string $path): ?array;

    public function read(string $path): string;

    /**
     * @return resource
     */
    public function readStream(string $path);

    /**
     * First $bytes of the file, or null when unsupported.
     */
    public function readHead(string $path, int $bytes): ?string;

    public function write(string $path, string $contents): void;

    /**
     * Write from a stream (used for uploads of arbitrary size).
     *
     * @param  resource|string  $stream
     */
    public function writeStream(string $path, $stream): void;

    public function createDirectory(string $path): void;

    public function delete(string $path): void;

    public function isWritable(): bool;

    /**
     * Credential-free label for the API/admin (e.g. "smb://host/share").
     */
    public function displayRoot(): string;
}
