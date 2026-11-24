<?php

namespace App\Services\Installer;

use RuntimeException;

/**
 * Writes installation values into the `.env` file.
 *
 * Existing keys are replaced in place, new keys are appended, and unrelated
 * lines are preserved. The path is injectable so tests never touch the real file.
 */
class EnvWriter
{
    public function __construct(private readonly ?string $path = null)
    {
    }

    public function path(): string
    {
        return $this->path ?? base_path('.env');
    }

    /** Read a value from the env file (null when the key is absent). */
    public function read(string $key, ?string $default = null): ?string
    {
        $path = $this->path();

        if (! is_file($path)) {
            return $default;
        }

        foreach (explode("\n", (string) file_get_contents($path)) as $line) {
            if (preg_match('/^' . preg_quote($key, '/') . '=(.*)$/', trim($line), $m) === 1) {
                $value = trim($m[1]);

                if (strlen($value) >= 2 && $value[0] === '"' && str_ends_with($value, '"')) {
                    $value = substr($value, 1, -1);
                }

                return $value;
            }
        }

        return $default;
    }

    /**
     * @param  array<string, string>  $values
     */
    public function write(array $values): void
    {
        $path = $this->path();
        $contents = is_file($path) ? (string) file_get_contents($path) : '';

        $lines = $contents === '' ? [] : explode("\n", rtrim($contents, "\n"));
        $index = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/^([A-Z0-9_]+)=/', $line, $m) === 1) {
                $index[$m[1]] = $i;
            }
        }

        foreach ($values as $key => $value) {
            $line = $key . '=' . $this->encode((string) $value);
            if (isset($index[$key])) {
                $lines[$index[$key]] = $line;
            } else {
                $lines[] = $line;
            }
        }

        $directory = dirname($path);
        if (! is_dir($directory) && ! @mkdir($directory, 0o775, true) && ! is_dir($directory)) {
            throw new RuntimeException('не удалось создать каталог для .env');
        }

        if (@file_put_contents($path, implode("\n", $lines) . "\n") === false) {
            throw new RuntimeException('не удалось записать .env (проверьте права на каталог)');
        }

        @chmod($path, 0o640);
    }

    /** Quotes values that contain spaces or shell-special characters. */
    private function encode(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^[A-Za-z0-9_\-\.:\/@=+~]+$/', $value) === 1) {
            return $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $value) . '"';
    }
}
