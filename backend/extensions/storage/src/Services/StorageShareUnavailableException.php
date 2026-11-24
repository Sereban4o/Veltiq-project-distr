<?php

namespace App\Extensions\Storage\Services;

use RuntimeException;

/**
 * Raised when a public share link is no longer usable (expired, revoked or
 * download limit reached). Controllers map this to HTTP 410.
 */
class StorageShareUnavailableException extends RuntimeException
{
    public static function gone(string $reason): self
    {
        return new self($reason);
    }
}
