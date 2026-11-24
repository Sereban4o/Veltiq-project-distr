<?php

namespace App\Extensions\Storage\Services;

use RuntimeException;

/**
 * User-facing storage error. Extends RuntimeException so the API controller
 * maps it to HTTP 422. Messages must never contain credentials or raw vendor
 * exception text.
 */
class StorageException extends RuntimeException
{
    public static function connection(string $protocol, string $host): self
    {
        $label = strtoupper($protocol);

        return new self("Не удалось подключиться к {$label}-серверу {$host}. Проверьте адрес, логин и пароль.");
    }

    public static function operationFailed(string $protocol, string $host): self
    {
        $label = strtoupper($protocol);

        return new self("Не удалось выполнить операцию на {$label}-сервере {$host}. Проверьте адрес, права доступа и доступность сервера.");
    }

    public static function notFound(): self
    {
        return new self('Файл или каталог не найден');
    }

    public static function notADirectory(): self
    {
        return new self('Это не каталог');
    }

    public static function notAFile(): self
    {
        return new self('Это не файл');
    }

    public static function notWritable(): self
    {
        return new self('Хранилище недоступно для записи');
    }
}
