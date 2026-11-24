<?php

namespace App\Extensions\Storage\Services;

use RuntimeException;

/**
 * Raised when a target path already exists and the operation forbids
 * overwriting. Controllers map this to HTTP 409.
 */
class StorageConflictException extends RuntimeException
{
    public function __construct(
        public readonly string $target,
        string $message = 'Объект с таким именем уже существует',
        public readonly string $conflictCode = 'conflict',
    ) {
        parent::__construct($message);
    }

    public static function at(string $path): self
    {
        return new self($path);
    }

    /**
     * Raised when the trash cannot accept another entry (item/byte limits).
     */
    public static function trashFull(string $message): self
    {
        return new self('', $message, 'trash_full');
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return $this->target === '' ? [] : ['path' => $this->target];
    }
}
