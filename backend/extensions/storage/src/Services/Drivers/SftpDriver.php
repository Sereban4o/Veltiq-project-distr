<?php

namespace App\Extensions\Storage\Services\Drivers;

use App\Extensions\Storage\Services\StoragePath;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;

class SftpDriver extends FlysystemDriver
{
    protected function createFilesystem(): Filesystem
    {
        $provider = new SftpConnectionProvider(
            host: $this->address->host(),
            username: (string) $this->config->username(),
            password: $this->config->password(),
            port: $this->address->port(),
            timeout: max(1, $this->config->connectTimeout()),
        );

        $adapter = new SftpAdapter(
            $provider,
            StoragePath::normalize($this->address->basePath()),
        );

        return new Filesystem($adapter);
    }
}
