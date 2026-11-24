<?php

namespace App\Extensions;

use App\Services\Extensions\ExtensionManifest;
use Illuminate\Support\ServiceProvider;
use ReflectionClass;

/**
 * Base class for extension service providers.
 *
 * A extension never touches core code: it contributes migrations, routes under
 * `extensions/<id>` and menu/permission metadata declared in its manifest.
 */
abstract class ExtensionServiceProvider extends ServiceProvider
{
    private ?ExtensionManifest $manifest = null;

    public function boot(): void
    {
        $manifest = $this->manifest();

        if ($manifest->migrations !== null) {
            $path = $manifest->path . '/' . ltrim($manifest->migrations, '/');
            if (is_dir($path)) {
                $this->loadMigrationsFrom($path);
            }
        }

        if ($manifest->routes !== null) {
            $path = $manifest->path . '/' . ltrim($manifest->routes, '/');
            if (is_file($path)) {
                // The extension owns its own paths/prefixes and gates itself with
                // `extension.enabled:<id>` (docs/11-extensions.md).
                $this->loadRoutesFrom($path);
            }
        }
    }

    /** The manifest of the extension this provider belongs to. */
    protected function manifest(): ExtensionManifest
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $file = (new ReflectionClass(static::class))->getFileName();
        $path = dirname((string) $file, 2);

        /** @var array<string, mixed> $raw */
        $raw = json_decode((string) file_get_contents($path . '/extension.json'), true, 512, JSON_THROW_ON_ERROR);

        return $this->manifest = ExtensionManifest::fromArray($raw, $path);
    }
}
