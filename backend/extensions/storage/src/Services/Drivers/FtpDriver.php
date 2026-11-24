<?php

namespace App\Extensions\Storage\Services\Drivers;

use App\Extensions\Storage\Services\StoragePath;
use League\Flysystem\Filesystem;
use League\Flysystem\Ftp\FtpAdapter;
use League\Flysystem\Ftp\FtpConnectionOptions;

class FtpDriver extends FlysystemDriver
{
    protected function createFilesystem(): Filesystem
    {
        $options = FtpConnectionOptions::fromArray([
            'host' => $this->address->host(),
            'root' => StoragePath::normalize($this->address->basePath()),
            'username' => (string) $this->config->username(),
            'password' => (string) $this->config->password(),
            'port' => $this->address->port(),
            'ssl' => $this->address->protocol() === 'ftps',
            'timeout' => max(1, $this->config->readTimeout()),
            'utf8' => true,
            'passive' => true,
            'ignorePassiveAddress' => true,
        ]);

        return new Filesystem(new FtpAdapter($options));
    }
}
