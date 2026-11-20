<?php

namespace App\Services\Updates;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Client that talks to the central update service (another resource) to
 * discover the latest version of the whole system.
 */
class SystemUpdateClient
{
    private const CACHE_KEY = 'system-update:manifest';

    public function currentVersion(): string
    {
        return (string) config('update_service.current_version', '1.0.0');
    }

    public function channel(): string
    {
        return (string) config('update_service.channel', 'stable');
    }

    public function serviceUrl(): string
    {
        return rtrim((string) config('update_service.url', ''), '/');
    }

    public function isConfigured(): bool
    {
        return config('update_service.mode') === 'client' && $this->serviceUrl() !== '';
    }

    /**
     * @return array{
     *     configured: bool,
     *     service_url: string,
     *     channel: string,
     *     current_version: string,
     *     latest: array<string, mixed>|null,
     *     update_available: bool,
     *     check_error: string|null,
     *     checked_at: string|null
     * }
     */
    public function status(bool $fresh = false): array
    {
        $base = [
            'configured' => $this->isConfigured(),
            'service_url' => $this->serviceUrl(),
            'channel' => $this->channel(),
            'current_version' => $this->currentVersion(),
            'latest' => null,
            'update_available' => false,
            'check_error' => null,
            'checked_at' => null,
        ];

        if (!$this->isConfigured()) {
            return $base;
        }

        if ($fresh) {
            Cache::forget(self::CACHE_KEY);
        }

        $ttl = (int) config('update_service.cache_ttl', 600);

        $cached = Cache::remember(self::CACHE_KEY, $ttl, fn () => $this->fetch());

        $base['latest'] = $cached['latest'];
        $base['check_error'] = $cached['error'];
        $base['checked_at'] = $cached['checked_at'];
        $base['update_available'] = $cached['latest'] !== null
            && $this->isNewer((string) $cached['latest']['version'], $this->currentVersion());

        return $base;
    }

    public function isNewer(string $remote, string $current): bool
    {
        if ($remote === '') {
            return false;
        }

        return version_compare($remote, $current, '>');
    }

    /**
     * @return array{latest: array<string, mixed>|null, error: string|null, checked_at: string}
     */
    private function fetch(): array
    {
        $url = $this->serviceUrl() . '/system-updates/api/latest';

        try {
            $request = Http::timeout((int) config('update_service.timeout', 10))->acceptJson();

            if ($token = (string) config('update_service.token')) {
                $request = $request->withToken($token);
            }

            $response = $request->get($url, ['channel' => $this->channel()]);

            if (!$response->successful()) {
                return ['latest' => null, 'error' => 'HTTP ' . $response->status(), 'checked_at' => now()->toIso8601String()];
            }

            $latest = $response->json('data');

            return [
                'latest' => is_array($latest) ? $latest : null,
                'error' => null,
                'checked_at' => now()->toIso8601String(),
            ];
        } catch (Throwable $e) {
            return ['latest' => null, 'error' => $e->getMessage(), 'checked_at' => now()->toIso8601String()];
        }
    }
}
