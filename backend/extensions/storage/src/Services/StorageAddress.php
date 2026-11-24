<?php

namespace App\Extensions\Storage\Services;

use InvalidArgumentException;

/**
 * Parses a storage address such as:
 *   smb://host/share/path
 *   sftp://host/path
 *   ftp://host/path  /  ftps://host/path
 *   webdav://host/dav  /  https://host/dav
 *
 * Credentials are never accepted in the address (they live in separate fields).
 */
final class StorageAddress
{
    /** @var array<string, array{0:string,1:int}> */
    private const SCHEMES = [
        'smb' => ['smb', 445],
        'cifs' => ['smb', 445],
        'sftp' => ['sftp', 22],
        'ftp' => ['ftp', 21],
        'ftps' => ['ftps', 21],
        'webdav' => ['webdav', 80],
        'http' => ['webdav', 80],
        'webdavs' => ['webdavs', 443],
        'https' => ['webdavs', 443],
    ];

    private function __construct(
        private string $scheme,
        private string $protocol,
        private string $host,
        private int $port,
        private bool $explicitPort,
        private string $basePath,
    ) {
    }

    public static function parse(string $address): self
    {
        $address = trim($address);

        if ($address === '') {
            throw new InvalidArgumentException('Укажите адрес файлового хранилища');
        }

        $parts = parse_url($address);

        if ($parts === false || empty($parts['scheme'])) {
            throw new InvalidArgumentException('Укажите адрес в формате smb://сервер/шара, sftp://сервер/путь, ftp://сервер/путь, https://сервер/dav/путь');
        }

        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new InvalidArgumentException('Не указывайте логин и пароль в адресе — заполните отдельные поля ниже.');
        }

        $scheme = strtolower($parts['scheme']);

        if (!isset(self::SCHEMES[$scheme])) {
            throw new InvalidArgumentException('Неподдерживаемый протокол «' . $scheme . '». Поддерживаются: smb, sftp, ftp, ftps, webdav, https.');
        }

        if (empty($parts['host'])) {
            throw new InvalidArgumentException('Не указан сервер в адресе');
        }

        if (isset($parts['query']) || isset($parts['fragment'])) {
            throw new InvalidArgumentException('Уберите параметры из адреса (символы ? и #)');
        }

        [$protocol, $defaultPort] = self::SCHEMES[$scheme];

        if (isset($parts['port'])) {
            if (!ctype_digit((string) $parts['port']) || $parts['port'] < 1 || $parts['port'] > 65535) {
                throw new InvalidArgumentException('Некорректный порт в адресе');
            }
            $port = (int) $parts['port'];
            $explicitPort = true;
        } else {
            $port = $defaultPort;
            $explicitPort = false;
        }

        $basePath = StoragePath::normalize(rawurldecode($parts['path'] ?? ''));

        if ($protocol === 'smb' && $basePath === '') {
            throw new InvalidArgumentException('Укажите имя общей папки, например smb://сервер/сотрудники');
        }

        return new self($scheme, $protocol, $parts['host'], $port, $explicitPort, $basePath);
    }

    public function scheme(): string
    {
        return $this->scheme;
    }

    public function protocol(): string
    {
        return $this->protocol;
    }

    public function host(): string
    {
        return $this->host;
    }

    public function port(): int
    {
        return $this->port;
    }

    public function basePath(): string
    {
        return $this->basePath;
    }

    /**
     * Credential-free label for display in the API/admin.
     */
    public function display(): string
    {
        $authority = $this->host . ($this->explicitPort ? ':' . $this->port : '');
        $suffix = $this->basePath !== '' ? '/' . $this->basePath : '';

        return $this->scheme . '://' . $authority . $suffix;
    }
}
