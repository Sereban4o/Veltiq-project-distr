<?php

namespace App\Extensions\Storage\Services;

use App\Extensions\Storage\Services\Contracts\StorageDriver;
use App\Extensions\Storage\Services\Drivers\FtpDriver;
use App\Extensions\Storage\Services\Drivers\LocalDriver;
use App\Extensions\Storage\Services\Drivers\SftpDriver;
use App\Extensions\Storage\Services\Drivers\SmbDriver;
use App\Extensions\Storage\Services\Drivers\WebdavDriver;

class StorageDriverFactory
{
    private ?StorageDriver $driver = null;

    public function __construct(private StorageConfig $config)
    {
    }

    public function config(): StorageConfig
    {
        return $this->config;
    }

    public function isConfigured(): bool
    {
        return $this->config->isConfigured();
    }

    /**
     * Stable identifier of the configured storage target, used as the `disk`
     * discriminator for metadata/versions/trash rows so that switching targets
     * (or replaying tests) starts a fresh namespace.
     */
    public function scopeKey(): string
    {
        $describe = $this->describe();
        $identity = (string) ($describe['address'] ?? $describe['root'] ?? $describe['protocol'] ?? 'storage');

        return 'storage:' . substr(hash('sha256', $identity), 0, 40);
    }

    public function make(): StorageDriver
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        $address = $this->config->address();

        if ($address !== null) {
            return $this->driver = match ($address->protocol()) {
                'smb' => new SmbDriver($address, $this->config),
                'sftp' => new SftpDriver($address, $this->config),
                'ftp', 'ftps' => new FtpDriver($address, $this->config),
                'webdav', 'webdavs' => new WebdavDriver($address, $this->config),
                default => throw new StorageNotConfiguredException(),
            };
        }

        $legacy = $this->config->legacyPath();
        if ($legacy !== null && $legacy !== '' && is_dir($legacy)) {
            return $this->driver = new LocalDriver($legacy);
        }

        throw new StorageNotConfiguredException();
    }

    /**
     * @return array{configured:bool,protocol:?string,address:?string,root:?string}
     */
    public function describe(): array
    {
        $address = $this->config->address();

        if ($address !== null) {
            return [
                'configured' => true,
                'protocol' => $address->protocol(),
                'address' => $address->display(),
                'root' => $address->display(),
            ];
        }

        $legacy = $this->config->legacyPath();
        if ($legacy !== null && $legacy !== '' && is_dir($legacy)) {
            $real = realpath($legacy) ?: $legacy;

            return [
                'configured' => true,
                'protocol' => 'local',
                'address' => null,
                'root' => $real,
            ];
        }

        return [
            'configured' => false,
            'protocol' => null,
            'address' => null,
            'root' => null,
        ];
    }
}
