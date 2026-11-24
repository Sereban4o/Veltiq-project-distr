<?php

namespace App\Services\Installer;

/**
 * Tracks whether the product has been installed.
 *
 * The installer is available only while the lock file is absent; once
 * installation succeeds the file is written and `/install` redirects to the site.
 */
class InstallationState
{
    public function lockPath(): string
    {
        return (string) config('install.lock_path', storage_path('app/installed.lock'));
    }

    public function isInstalled(): bool
    {
        return is_file($this->lockPath());
    }

    public function lock(): void
    {
        $directory = dirname($this->lockPath());
        if (! is_dir($directory)) {
            @mkdir($directory, 0o775, true);
        }

        @file_put_contents($this->lockPath(), json_encode([
            'installed_at' => now()->toIso8601String(),
            'app_version' => (string) config('app.version', '1.0'),
        ]));
    }
}
