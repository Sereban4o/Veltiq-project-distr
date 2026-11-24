<?php

namespace App\Services\Extensions;

use App\Services\Core\CoreClient;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Installs and removes third-party modules (docs/11-extensions.md).
 *
 * Security model: a third-party module is ordinary PHP with full access to data
 * and files — there is no sandbox. Installation therefore requires developer
 * mode, defends against archive traversal, validates the manifest and records an
 * audit entry in the core. Signed (vendor) modules cannot be installed here: they
 * ship inside the image.
 */
class ExtensionInstaller
{
    private const MAX_FILES = 2000;

    private const MAX_UNCOMPRESSED_BYTES = 64 * 1024 * 1024;

    public function __construct(
        private readonly CoreClient $core,
        private readonly ExtensionRegistry $registry,
    ) {
    }

    /**
     * Install a module from a .zip archive and return its manifest.
     *
     * @throws RuntimeException when the archive or the manifest is not acceptable
     */
    public function install(string $archivePath, bool $force = false, string $actor = ''): ExtensionManifest
    {
        if (! is_file($archivePath)) {
            throw new RuntimeException('архив расширения не найден');
        }

        $workspace = $this->makeWorkspace();

        try {
            $this->extractSafely($archivePath, $workspace);

            $moduleRoot = $this->locateModuleRoot($workspace);
            $manifestFile = $moduleRoot . '/extension.json';

            $raw = json_decode((string) file_get_contents($manifestFile), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($raw)) {
                throw new RuntimeException('module.json должен быть объектом');
            }

            $raw = $this->verifyManifest($raw);
            $signer = (string) ($raw['_signer'] ?? 'unsigned');
            $manifest = ExtensionManifest::fromArray($raw, $moduleRoot);

            if ($manifest->tier === 'vendor') {
                throw new RuntimeException('вендорские расширения поставляются в образе и не устанавливаются вручную');
            }

            if (! (bool) config('extensions.allow_unsigned', false)) {
                throw new RuntimeException('установка сторонних расширений запрещена: включите developer mode (EXTENSIONS_ALLOW_UNSIGNED)');
            }

            $this->assertPathsInside($manifest);

            $target = $this->thirdPartyPath() . '/' . $manifest->id;
            if (is_dir($target)) {
                if (! $force) {
                    throw new RuntimeException("расширение {$manifest->id} уже установлен (используйте --force)");
                }

                $this->removeDirectory($target);
            }

            if (! is_dir($this->thirdPartyPath()) && ! mkdir($this->thirdPartyPath(), 0o755, true) && ! is_dir($this->thirdPartyPath())) {
                throw new RuntimeException('не удалось создать каталог расширений');
            }

            if (! rename($moduleRoot, $target)) {
                $this->copyDirectory($moduleRoot, $target);
                $this->removeDirectory($moduleRoot);
            }

            $this->audit('extension.install', $manifest->id . ' ' . $manifest->version . ' signer=' . $signer, $actor);
            $this->registry->refresh();

            return ExtensionManifest::fromArray($raw, $target);
        } finally {
            $this->removeDirectory($workspace);
        }
    }

    /**
     * Remove an installed third-party module.
     *
     * `retain` keeps data (only code is deleted); `purge` is recorded in the audit
     * trail for manual cleanup of the module's tables.
     */
    public function uninstall(string $id, string $actor = ''): ExtensionManifest
    {
        $manifests = $this->registry->discover();
        $manifest = $manifests[$id] ?? null;

        if (! $manifest instanceof ExtensionManifest) {
            throw new RuntimeException("расширение {$id} не найден");
        }

        if ($manifest->tier === 'vendor') {
            throw new RuntimeException('вендорский расширение входит в образ и не удаляется');
        }

        if (! $this->isInside($manifest->path, $this->thirdPartyPath())) {
            throw new RuntimeException('расширение установлен вне каталога сторонних расширений');
        }

        $this->registry->setEnabled($id, false);
        $this->removeDirectory($manifest->path);

        $this->audit(
            'extension.uninstall',
            $id . ' uninstall=' . $manifest->uninstall,
            $actor,
        );

        $this->registry->refresh();

        return $manifest;
    }

    /** @param array<string, mixed> $raw
     *  @return array<string, mixed>
     */
    private function verifyManifest(array $raw): array
    {
        if (! is_array($raw['signature'] ?? null)) {
            // Unsigned manifests are only meaningful for third-party modules and
            // the developer-mode check happens in install().
            return $raw;
        }

        $keys = ExtensionSignature::trustedKeys(
            (string) config('license.public_key', ''),
            (string) config('extensions.trusted_keys', ''),
        );

        if ($keys === []) {
            throw new RuntimeException('не задан ни один доверенный ключ подписи (LICENSE_PUBLIC_KEY / EXTENSIONS_TRUSTED_KEYS)');
        }

        try {
            return ExtensionSignature::verify($raw, $keys);
        } catch (Throwable $e) {
            throw new RuntimeException('подпись манифеста не прошла проверку: ' . $e->getMessage());
        }
    }

    private function assertPathsInside(ExtensionManifest $manifest): void
    {
        $paths = array_values($manifest->psr4);
        if ($manifest->migrations !== null) {
            $paths[] = $manifest->migrations;
        }

        if ($manifest->routes !== null) {
            $paths[] = $manifest->routes;
        }

        foreach ($paths as $path) {
            $path = (string) $path;
            if (str_starts_with($path, '/') || str_contains($path, '..') || preg_match('/^[A-Za-z]:/', $path) === 1) {
                throw new RuntimeException('манифест ссылается за пределы каталога расширения: ' . $path);
            }
        }
    }

    private function thirdPartyPath(): string
    {
        return rtrim($this->registry->thirdPartyPath(), '/');
    }

    private function makeWorkspace(): string
    {
        $path = sys_get_temp_dir() . '/velt-module-' . bin2hex(random_bytes(6));

        if (! mkdir($path, 0o700, true) && ! is_dir($path)) {
            throw new RuntimeException('не удалось создать временный каталог');
        }

        return $path;
    }

    /** Rejects traversal, absolute paths, links and oversized archives. */
    private function extractSafely(string $archivePath, string $target): void
    {
        $zip = new ZipArchive();
        if ($zip->open($archivePath) !== true) {
            throw new RuntimeException('не удалось открыть архив расширения');
        }

        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw new RuntimeException('в архиве слишком много файлов');
            }

            $bytes = 0;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat === false) {
                    continue;
                }

                $name = (string) $stat['name'];
                $bytes += (int) $stat['size'];

                $normalized = str_replace('\\', '/', $name);

                if (str_contains($normalized, "\0")
                    || str_starts_with($normalized, '/')
                    || preg_match('/^[A-Za-z]:/', $normalized) === 1
                    || in_array('..', explode('/', $normalized), true)
                ) {
                    throw new RuntimeException('архив содержит недопустимый путь: ' . $name);
                }

                $zip->getExternalAttributesIndex($i, $operatingSystem, $attributes);
                $mode = $attributes >> 16;
                if (($mode & 0o170000) === 0o120000) {
                    throw new RuntimeException('архив содержит символическую ссылку: ' . $name);
                }
            }

            if ($bytes > self::MAX_UNCOMPRESSED_BYTES) {
                throw new RuntimeException('распакованный расширение слишком большой');
            }

            if (! $zip->extractTo($target)) {
                throw new RuntimeException('не удалось распаковать архив расширения');
            }
        } finally {
            $zip->close();
        }
    }

    private function locateModuleRoot(string $workspace): string
    {
        if (is_file($workspace . '/extension.json')) {
            return $workspace;
        }

        $candidates = glob($workspace . '/*/module.json') ?: [];
        if (count($candidates) !== 1) {
            throw new RuntimeException('в архиве должен быть ровно один каталог с module.json');
        }

        return dirname($candidates[0]);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }

    /** Fallback for cross-filesystem moves (temp dir → module volume). */
    private function copyDirectory(string $from, string $to): void
    {
        if (! mkdir($to, 0o755, true) && ! is_dir($to)) {
            throw new RuntimeException('не удалось создать каталог расширения');
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($from, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($items as $item) {
            /** @var \SplFileInfo $item */
            $relative = substr($item->getPathname(), strlen($from) + 1);
            $destination = $to . '/' . $relative;

            if ($item->isDir()) {
                if (! is_dir($destination)) {
                    mkdir($destination, 0o755, true);
                }

                continue;
            }

            if (! copy($item->getPathname(), $destination)) {
                throw new RuntimeException('не удалось скопировать ' . $relative);
            }
        }
    }

    private function isInside(string $path, string $root): bool
    {
        $pathReal = realpath($path);
        $rootReal = realpath($root);

        if ($pathReal === false || $rootReal === false) {
            return false;
        }

        return str_starts_with($pathReal, rtrim($rootReal, '/') . '/');
    }

    private function audit(string $action, string $detail, string $actor): void
    {
        $response = $this->core->audit($action, $detail, $actor);
        if (! $response['ok']) {
            Log::warning('Module audit entry was not recorded in the core', [
                'action' => $action,
                'detail' => $detail,
            ]);
        }
    }
}
