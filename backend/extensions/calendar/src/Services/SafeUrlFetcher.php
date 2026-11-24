<?php

namespace App\Extensions\Calendar\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Fetches user-submitted ICS URLs while protecting against SSRF:
 * only http(s), only allowed ports, DNS results must be public IPs, the
 * validated IP is pinned for the actual request (defeats DNS rebinding),
 * redirects are followed manually and re-validated, responses are size-capped.
 */
class SafeUrlFetcher
{
    public function __construct(
        private HostResolver $resolver,
    ) {
    }

    /**
     * Validate a URL before it is stored (no request is performed): scheme,
     * credentials, port and every resolved IP are checked with the same rules
     * the real fetch uses.
     *
     * @throws UnsafeUrlException
     */
    public function assertSafeUrl(string $url): void
    {
        $this->resolveTarget($url);
    }

    /**
     * @return array{body: string, etag: ?string, last_modified: ?string, status: int}
     */
    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): array
    {
        $maxRedirects = (int) config('calendar.feed.max_redirects', 3);
        $maxBytes = (int) config('calendar.feed.max_bytes', 5 * 1024 * 1024);
        $current = $url;

        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            [$host, $port, $ip] = $this->resolveTarget($current);

            $headers = [];
            if ($etag) {
                $headers['If-None-Match'] = $etag;
            }
            if ($lastModified) {
                $headers['If-Modified-Since'] = $lastModified;
            }

            $options = [
                'allow_redirects' => false,
                'connect_timeout' => (int) config('calendar.feed.connect_timeout', 5),
                'curl' => [
                    CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"],
                ],
            ];

            $response = Http::withOptions($options)
                ->withHeaders($headers)
                ->timeout((int) config('calendar.feed.timeout', 15))
                ->get($current);

            $status = $response->status();

            if (in_array($status, [301, 302, 303, 307, 308], true)) {
                $location = $response->header('Location');
                if (! $location) {
                    throw new UnsafeUrlException('Редирект без заголовка Location');
                }
                $current = $this->absoluteUrl($current, $location);

                continue;
            }

            if ($status === 304) {
                return [
                    'body' => '',
                    'etag' => $response->header('ETag') ?: $etag,
                    'last_modified' => $response->header('Last-Modified') ?: $lastModified,
                    'status' => 304,
                ];
            }

            if ($status < 200 || $status >= 300) {
                throw new RuntimeException("Источник календаря вернул HTTP {$status}");
            }

            $body = $response->body();
            if (strlen($body) > $maxBytes) {
                throw new RuntimeException('Ответ источника превышает допустимый размер');
            }

            return [
                'body' => $body,
                'etag' => $response->header('ETag') ?: null,
                'last_modified' => $response->header('Last-Modified') ?: null,
                'status' => $status,
            ];
        }

        throw new UnsafeUrlException('Превышено максимальное количество редиректов');
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    private function resolveTarget(string $url): array
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            throw new UnsafeUrlException('Некорректный URL');
        }

        $scheme = strtolower($parts['scheme'] ?? '');
        if (! in_array($scheme, ['http', 'https'], true)) {
            throw new UnsafeUrlException('Разрешены только http и https');
        }

        if (isset($parts['user']) || isset($parts['pass'])) {
            throw new UnsafeUrlException('URL не должен содержать учётные данные');
        }

        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        $allowedPorts = (array) config('calendar.feed.allowed_ports', [80, 443]);
        if (! in_array($port, $allowedPorts, true)) {
            throw new UnsafeUrlException('Порт URL не разрешён');
        }

        $host = $parts['host'];
        $bareHost = trim($host, '[]');

        if (filter_var($bareHost, FILTER_VALIDATE_IP) !== false) {
            $ips = [$bareHost];
        } else {
            if (! preg_match('/^[a-z0-9.\-]+$/i', $host)) {
                throw new UnsafeUrlException('Некорректное имя хоста');
            }
            $ips = $this->resolver->resolve($host);
        }

        if ($ips === []) {
            throw new UnsafeUrlException('Не удалось разрешить имя хоста');
        }

        foreach ($ips as $ip) {
            if (! self::isPublicIp($ip)) {
                throw new UnsafeUrlException('URL указывает на приватный или зарезервированный адрес');
            }
        }

        return [$host, $port, $ips[0]];
    }

    private function absoluteUrl(string $base, string $location): string
    {
        $location = trim($location);

        if (preg_match('#^https?://#i', $location)) {
            return $location;
        }

        $parts = parse_url($base);
        if ($parts === false || empty($parts['host'])) {
            throw new UnsafeUrlException('Некорректный URL');
        }

        $scheme = $parts['scheme'] ?? 'https';
        $origin = $scheme . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');

        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }

        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }

        $path = $parts['path'] ?? '/';
        $directory = substr($path, 0, (int) strrpos($path, '/') + 1) ?: '/';

        return $origin . $directory . $location;
    }

    /**
     * Public-IP check used both at runtime and by unit tests.
     */
    public static function isPublicIp(string $ip): bool
    {
        $ip = trim($ip, '[]');

        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        if (str_contains($ip, ':')) {
            $lower = strtolower($ip);

            if ($lower === '::' || $lower === '::1') {
                return false;
            }

            // IPv4-mapped IPv6 (::ffff:127.0.0.1) — validate the embedded v4.
            if (str_starts_with($lower, '::ffff:')) {
                $embedded = substr($lower, 7);
                if (filter_var($embedded, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
                    return self::isPublicIp($embedded);
                }
            }

            foreach (['fc00::/7', 'fe80::/10', 'ff00::/8', '2001:db8::/32'] as $cidr) {
                if (self::ipInCidr($ip, $cidr)) {
                    return false;
                }
            }

            return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
        }

        $blocked = [
            '0.0.0.0/8',
            '10.0.0.0/8',
            '100.64.0.0/10',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '172.16.0.0/12',
            '192.0.0.0/24',
            '192.0.2.0/24',
            '192.168.0.0/16',
            '198.18.0.0/15',
            '198.51.100.0/24',
            '203.0.113.0/24',
            '224.0.0.0/4',
            '240.0.0.0/4',
        ];

        foreach ($blocked as $cidr) {
            if (self::ipInCidr($ip, $cidr)) {
                return false;
            }
        }

        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        if ($bits === null) {
            return false;
        }

        $ipBinary = @inet_pton($ip);
        $subnetBinary = @inet_pton($subnet);
        if ($ipBinary === false || $subnetBinary === false || strlen($ipBinary) !== strlen($subnetBinary)) {
            return false;
        }

        $bits = (int) $bits;
        $fullBytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($fullBytes > 0 && substr($ipBinary, 0, $fullBytes) !== substr($subnetBinary, 0, $fullBytes)) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = chr((0xFF << (8 - $remainder)) & 0xFF);

        return (substr($ipBinary, $fullBytes, 1) & $mask) === (substr($subnetBinary, $fullBytes, 1) & $mask);
    }
}
