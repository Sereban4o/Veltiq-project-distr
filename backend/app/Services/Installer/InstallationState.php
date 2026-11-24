<?php

namespace App\Services\Installer;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Tracks whether the product has been installed.
 *
 * The installer is available only while the installation looks fresh; once it
 * succeeds the lock file is written and `/install` redirects to the site.
 *
 * A missing lock is not enough to call the installation fresh: deploying a new
 * distribution over an existing database (schema + users) must not open the
 * wizard and tempt an operator into re-seeding a live install. In that case the
 * state is detected from the database and the lock is recreated.
 */
class InstallationState
{
    private ?bool $installed = null;

    public function lockPath(): string
    {
        return (string) config('install.lock_path', storage_path('app/installed.lock'));
    }

    public function isInstalled(): bool
    {
        if ($this->installed !== null) {
            return $this->installed;
        }

        if ((bool) config('install.force', false)) {
            return $this->installed = false;
        }

        if (is_file($this->lockPath())) {
            return $this->installed = true;
        }

        return $this->installed = $this->detectExistingInstallation();
    }

    public function lock(): void
    {
        $this->writeLock();
        $this->installed = true;
    }

    /**
     * An existing database means an existing installation: migrate + seed have
     * already run and at least one account exists.
     */
    private function detectExistingInstallation(): bool
    {
        try {
            if (! Schema::hasTable('migrations') || ! Schema::hasTable('users')) {
                return false;
            }

            if (! DB::table('users')->exists()) {
                return false;
            }

            $this->writeLock();

            return true;
        } catch (Throwable) {
            // Database unreachable: never push the installer over an unknown
            // state — a wrongly opened wizard on a live install is worse.
            return true;
        }
    }

    private function writeLock(): void
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
