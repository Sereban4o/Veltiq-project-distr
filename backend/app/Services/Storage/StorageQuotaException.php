<?php

namespace App\Services\Storage;

use RuntimeException;

/**
 * Raised when a write would exceed the user's storage quota. Controllers map
 * this to HTTP 413 with a machine-readable payload.
 */
class StorageQuotaException extends RuntimeException
{
    public function __construct(
        public readonly int $used,
        public readonly ?int $limit,
        public readonly int $required = 0,
    ) {
        parent::__construct('Недостаточно места в хранилище: превышена квота.');
    }

    public static function for(int $used, ?int $limit, int $required = 0): self
    {
        return new self($used, $limit, $required);
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return ['used' => $this->used, 'limit' => $this->limit, 'required' => $this->required];
    }
}
