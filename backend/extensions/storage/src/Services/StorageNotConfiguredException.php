<?php

namespace App\Extensions\Storage\Services;

use RuntimeException;

/**
 * Thrown when the file storage root has not been configured in the admin panel.
 */
class StorageNotConfiguredException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Файловое хранилище не настроено. Обратитесь к администратору.');
    }
}
