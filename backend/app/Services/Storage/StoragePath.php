<?php

namespace App\Services\Storage;

use InvalidArgumentException;

/**
 * Helpers for connection-relative storage paths.
 *
 * Remote filesystems have no realpath(), so this class is the only traversal
 * defence: every path coming from the client must go through normalize().
 */
final class StoragePath
{
    public static function normalize(string $path, bool $allowEmpty = true): string
    {
        $path = str_replace('\\', '/', $path);

        if (str_contains($path, "\0")) {
            throw new InvalidArgumentException('Недопустимый путь');
        }

        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                throw new InvalidArgumentException('Недопустимый путь');
            }
            $segments[] = $segment;
        }

        $normalized = implode('/', $segments);

        if ($normalized === '' && !$allowEmpty) {
            throw new InvalidArgumentException('Укажите путь');
        }

        return $normalized;
    }

    public static function join(string $dir, string $name): string
    {
        $dir = self::normalize($dir);
        $name = self::normalize($name, allowEmpty: false);

        return $dir === '' ? $name : $dir . '/' . $name;
    }

    public static function parent(string $path): string
    {
        $path = self::normalize($path);
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);
        array_pop($segments);

        return implode('/', $segments);
    }

    public static function name(string $path): string
    {
        $path = self::normalize($path);
        if ($path === '') {
            return '';
        }

        $segments = explode('/', $path);

        return (string) end($segments);
    }

    /**
     * Lowercase file extension of a path, or null when it has none.
     */
    public static function extension(string $path): ?string
    {
        $name = self::name($path);
        if ($name === '') {
            return null;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));

        return $extension === '' ? null : $extension;
    }

    /**
     * Stable 64-char hash of a path, used for the (disk, path) lookup column.
     */
    public static function hash(string $path): string
    {
        return hash('sha256', self::normalize($path));
    }

    /**
     * Escape LIKE wildcards for queries using "... LIKE ? ESCAPE '!'".
     */
    public static function escapeLike(string $value): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $value);
    }

    public static function assertSafeName(string $name): void
    {
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..' || strlen($name) > 255) {
            throw new InvalidArgumentException('Недопустимое имя файла или папки');
        }

        if (preg_match('/[<>:"|?*\\\\\/\x00-\x1f]/u', $name)) {
            throw new InvalidArgumentException('Недопустимое имя файла или папки');
        }
    }
}
