<?php

namespace App\Services\Storage\Drivers;

use App\Services\Storage\Contracts\StorageDriver;
use App\Services\Storage\StorageAddress;
use App\Services\Storage\StorageConfig;
use App\Services\Storage\StorageException;
use App\Services\Storage\StoragePath;
use GuzzleHttp\Client;
use League\MimeTypeDetection\ExtensionMimeTypeDetector;
use Psr\Http\Message\ResponseInterface;
use SimpleXMLElement;
use Throwable;

/**
 * WebDAV driver built on Guzzle (already a project dependency). Supports
 * plain and TLS WebDAV, streaming downloads and recursive deletes.
 */
class WebdavDriver implements StorageDriver
{
    private const PROPFIND_BODY = <<<'XML'
<?xml version="1.0" encoding="utf-8"?>
<d:propfind xmlns:d="DAV:">
  <d:prop>
    <d:resourcetype/>
    <d:getcontentlength/>
    <d:getlastmodified/>
  </d:prop>
</d:propfind>
XML;

    private Client $http;

    private string $pathPrefix;

    private string $displayRoot;

    private string $host;

    private string $protocol;

    private ExtensionMimeTypeDetector $mime;

    private ?bool $writable = null;

    public function __construct(StorageAddress $address, StorageConfig $config)
    {
        $this->host = $address->host();
        $this->protocol = $address->protocol();
        $this->displayRoot = $address->display();
        $this->mime = new ExtensionMimeTypeDetector();
        $this->pathPrefix = '/' . trim($address->basePath(), '/');

        $scheme = in_array($address->scheme(), ['http', 'https'], true)
            ? $address->scheme()
            : ($address->protocol() === 'webdavs' ? 'https' : 'http');

        $baseUri = rtrim($scheme . '://' . $address->host() . ':' . $address->port() . $this->pathPrefix, '/') . '/';

        $this->http = new Client([
            'base_uri' => $baseUri,
            'auth' => [(string) $config->username(), (string) $config->password()],
            'connect_timeout' => max(1, $config->connectTimeout()),
            'timeout' => max(1, $config->readTimeout()),
            'verify' => $config->verifyTls() ? ($config->caBundle() ?: true) : false,
            'http_errors' => false,
            'allow_redirects' => false,
        ]);
    }

    public function list(string $path): array
    {
        $relative = StoragePath::normalize($path);
        $response = $this->propfind($relative, 1);

        $entries = [];
        foreach ($this->parseMultiStatus((string) $response->getBody()) as $entry) {
            if ($entry['path'] === $relative) {
                continue;
            }
            $entries[] = $entry;
        }

        usort($entries, function (array $a, array $b) {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $entries;
    }

    public function stat(string $path): ?array
    {
        $relative = StoragePath::normalize($path);

        if ($relative === '') {
            return [
                'path' => '', 'name' => '', 'type' => 'dir',
                'size' => null, 'created' => null, 'modified' => null, 'mime' => null,
            ];
        }

        try {
            $response = $this->propfind($relative, 0);
        } catch (StorageException $e) {
            if ($e->getMessage() === StorageException::notFound()->getMessage()) {
                return null;
            }

            throw $e;
        }

        foreach ($this->parseMultiStatus((string) $response->getBody()) as $entry) {
            if ($entry['path'] === $relative) {
                return $entry;
            }
        }

        return null;
    }

    public function read(string $path): string
    {
        $response = $this->request('GET', StoragePath::normalize($path));
        $this->expect($response, [200]);

        return (string) $response->getBody();
    }

    public function readStream(string $path)
    {
        $response = $this->request('GET', StoragePath::normalize($path), ['stream' => true]);
        $this->expect($response, [200]);

        return $response->getBody()->detach();
    }

    public function readHead(string $path, int $bytes): ?string
    {
        return null;
    }

    public function write(string $path, string $contents): void
    {
        $response = $this->request('PUT', StoragePath::normalize($path), ['body' => $contents]);
        $this->expect($response, [200, 201, 204]);
    }

    public function writeStream(string $path, $stream): void
    {
        $body = is_resource($stream) ? $stream : (string) $stream;

        $response = $this->request('PUT', StoragePath::normalize($path), ['body' => $body]);
        $this->expect($response, [200, 201, 204]);
    }

    public function createDirectory(string $path): void
    {
        $response = $this->request('MKCOL', StoragePath::normalize($path));
        $this->expect($response, [201]);
    }

    public function delete(string $path): void
    {
        $relative = StoragePath::normalize($path);

        $entry = $this->stat($relative);
        if ($entry === null) {
            throw StorageException::notFound();
        }

        if ($entry['type'] === 'dir') {
            foreach ($this->list($relative) as $child) {
                $this->delete($child['path']);
            }
        }

        $response = $this->request('DELETE', $relative);
        $this->expect($response, [200, 204]);
    }

    public function isWritable(): bool
    {
        if ($this->writable !== null) {
            return $this->writable;
        }

        return $this->writable = (function () {
            $probe = '.tasksbase-write-' . bin2hex(random_bytes(6));

            try {
                $this->write($probe, 'ok');
                $this->delete($probe);

                return true;
            } catch (Throwable) {
                return false;
            }
        })();
    }

    public function displayRoot(): string
    {
        return $this->displayRoot;
    }

    private function propfind(string $relative, int $depth): ResponseInterface
    {
        $response = $this->request('PROPFIND', $relative, [
            'headers' => ['Depth' => (string) $depth, 'Content-Type' => 'application/xml; charset=utf-8'],
            'body' => self::PROPFIND_BODY,
        ]);

        $this->expect($response, [207]);

        return $response;
    }

    private function request(string $method, string $relative, array $options = []): ResponseInterface
    {
        try {
            return $this->http->request($method, $this->url($relative), $options);
        } catch (Throwable) {
            throw StorageException::operationFailed($this->protocol, $this->host);
        }
    }

    private function url(string $relative): string
    {
        if ($relative === '') {
            return '';
        }

        return implode('/', array_map('rawurlencode', explode('/', $relative)));
    }

    private function expect(ResponseInterface $response, array $ok): void
    {
        $status = $response->getStatusCode();

        if (in_array($status, $ok, true)) {
            return;
        }

        if ($status === 401 || $status === 403) {
            throw new StorageException('Неверный логин или пароль для WebDAV-сервера');
        }

        if ($status === 404) {
            throw StorageException::notFound();
        }

        throw StorageException::operationFailed($this->protocol, $this->host);
    }

    /**
     * @return array<int, array{path:string,name:string,type:'dir'|'file',size:?int,created:?int,modified:?int,mime:?string}>
     */
    private function parseMultiStatus(string $body): array
    {
        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($body);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (!$xml instanceof SimpleXMLElement) {
            throw StorageException::operationFailed($this->protocol, $this->host);
        }

        $xml->registerXPathNamespace('d', 'DAV:');
        $responses = $xml->xpath('//d:response') ?: [];

        $entries = [];
        foreach ($responses as $response) {
            $response->registerXPathNamespace('d', 'DAV:');

            $hrefNodes = $response->xpath('d:href');
            if (empty($hrefNodes)) {
                continue;
            }

            $relative = $this->relativeFromHref((string) $hrefNodes[0]);
            if ($relative === null) {
                continue;
            }

            $prop = $this->propFor($response);
            $isDir = $prop !== null && !empty($prop->xpath('d:resourcetype/d:collection'));

            $size = null;
            if (!$isDir && $prop !== null) {
                $sizeNodes = $prop->xpath('d:getcontentlength');
                $size = !empty($sizeNodes) ? (int) (string) $sizeNodes[0] : null;
            }

            $modified = null;
            if ($prop !== null) {
                $modifiedNodes = $prop->xpath('d:getlastmodified');
                if (!empty($modifiedNodes)) {
                    $ts = strtotime((string) $modifiedNodes[0]);
                    $modified = $ts !== false ? $ts : null;
                }
            }

            $mime = $isDir ? null : $this->mime->detectMimeTypeFromPath($relative);

            $entries[] = [
                'path' => $relative,
                'name' => StoragePath::name($relative),
                'type' => $isDir ? 'dir' : 'file',
                'size' => $size,
                'created' => $modified,
                'modified' => $modified,
                'mime' => $mime,
            ];
        }

        return $entries;
    }

    private function propFor(SimpleXMLElement $response): ?SimpleXMLElement
    {
        foreach ($response->xpath('d:propstat') as $propstat) {
            $propstat->registerXPathNamespace('d', 'DAV:');
            $statusNodes = $propstat->xpath('d:status');
            $status = !empty($statusNodes) ? (string) $statusNodes[0] : '';

            if (!str_contains($status, '200')) {
                continue;
            }

            $propNodes = $propstat->xpath('d:prop');
            if (!empty($propNodes)) {
                return $propNodes[0];
            }
        }

        return null;
    }

    private function relativeFromHref(string $href): ?string
    {
        $path = parse_url($href, PHP_URL_PATH);
        if (!is_string($path) || $path === '') {
            $path = $href;
        }

        $path = rtrim(rawurldecode($path), '/');

        if ($this->pathPrefix !== '' && $this->pathPrefix !== '/' && str_starts_with($path, $this->pathPrefix)) {
            $path = substr($path, strlen($this->pathPrefix));
        }

        return StoragePath::normalize(ltrim($path, '/'));
    }
}
