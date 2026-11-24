<?php

namespace App\Extensions\Storage\Services;

use RuntimeException;

/**
 * Raised when a metadata-tracked entry (version, share, trash item) is gone.
 * Controllers map this to HTTP 404.
 */
class StorageNotFoundException extends RuntimeException
{
    public static function make(): self
    {
        return new self('Запись не найдена');
    }
}
