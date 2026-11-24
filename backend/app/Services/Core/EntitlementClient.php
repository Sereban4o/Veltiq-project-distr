<?php

namespace App\Services\Core;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifies the short-lived entitlement ticket signed by the compiled core.
 *
 * The point is that PHP checks a signature instead of trusting a flag: a patched
 * `if` cannot forge a ticket without the core's private key (docs/12-licensing.md).
 * Failures degrade to `unknown` (read-only once enforcement is on), never to a
 * 500, and the product keeps running when the core is unreachable.
 */
class EntitlementClient
{
    private const TICKET_CACHE_KEY = 'license:ticket';

    private const STATE_CACHE_KEY = 'license:state';

    public function __construct(private readonly CoreClient $core)
    {
    }

    /**
     * Current licensing state as seen by the application.
     *
     * @return array{state: string, access: string, features: array<int, string>, modules: array<int, string>, expires_at: string|null, fingerprint: string|null, clock_tamper: bool, source: string}
     */
    public function state(): array
    {
        if (! (bool) config('license.enabled', true)) {
            return $this->buildState('grandfathered', 'full', source: 'disabled');
        }

        $cached = Cache::get(self::STATE_CACHE_KEY);
        if (is_array($cached) && ($cached['expires_at_ts'] ?? 0) > time()) {
            unset($cached['expires_at_ts']);

            return $cached;
        }

        $token = $this->token();
        if ($token === null) {
            // Core unreachable: fall back to the last verified ticket if it is
            // still within its TTL, otherwise report `unknown`.
            return $this->buildState('unknown', 'read_only', source: 'unreachable');
        }

        $payload = $this->decodeTicket($token);
        if ($payload === null) {
            return $this->buildState('unknown', 'read_only', source: 'invalid_ticket');
        }

        $result = $this->buildState(
            (string) ($payload['state'] ?? 'unknown'),
            (string) ($payload['access'] ?? 'read_only'),
            features: $this->strings($payload['features'] ?? []),
            modules: $this->strings($payload['modules'] ?? []),
            expiresAt: $this->string($payload['license_expires_at'] ?? null),
            source: 'core',
        );

        $fingerprint = (string) (config('license.fingerprint') ?? '');
        if ($fingerprint !== '') {
            $result['fingerprint'] = $fingerprint;
        }

        Cache::put(self::STATE_CACHE_KEY, $result + ['expires_at_ts' => time() + $this->ttl()], $this->ttl());

        return $result;
    }

    /** Does the license grant the given feature or module? */
    public function allows(string $id): bool
    {
        $state = $this->state();

        return in_array($id, $state['features'], true) || in_array($id, $state['modules'], true);
    }

    public function isBlocked(): bool
    {
        return $this->state()['access'] === 'blocked';
    }

    public function isReadOnly(): bool
    {
        return $this->state()['access'] === 'read_only';
    }

    public function canInstallExtensions(): bool
    {
        return in_array($this->state()['state'], ['valid', 'grandfathered'], true);
    }

    /** Fetch (and cache) a fresh token from the core. */
    private function token(): ?string
    {
        $token = Cache::get(self::TICKET_CACHE_KEY);
        if (is_string($token) && $token !== '') {
            return $token;
        }

        $response = $this->core->entitlementToken();
        if (! $response['ok']) {
            return null;
        }

        $token = $response['data']['token'] ?? null;
        if (! is_string($token) || $token === '') {
            return null;
        }

        Cache::put(self::TICKET_CACHE_KEY, $token, $this->ttl());

        return $token;
    }

    /**
     * Verify the envelope signature with the pinned core key.
     *
     * @return array<string, mixed>|null
     */
    private function decodeTicket(string $token): ?array
    {
        $publicKey = $this->corePublicKey();
        if ($publicKey === null) {
            Log::warning('Entitlement ticket cannot be verified: no core public key');

            return null;
        }

        try {
            $envelope = json_decode(base64_decode($token, true) ?: '', true, 512, JSON_THROW_ON_ERROR);
            $payload = base64_decode((string) ($envelope['payload'] ?? ''), true);
            $signature = base64_decode((string) ($envelope['sig'] ?? ''), true);
        } catch (Throwable $e) {
            Log::warning('Entitlement ticket is malformed', ['error' => $e->getMessage()]);

            return null;
        }

        if ($payload === false || $signature === false || $payload === '' || $signature === '') {
            return null;
        }

        if (! $this->verifySignature($payload, $signature, $publicKey)) {
            Log::warning('Entitlement ticket signature is invalid');

            return null;
        }

        $decoded = json_decode($payload, true);

        return is_array($decoded) ? $decoded : null;
    }

    /** Ed25519 verification via sodium, falling back to openssl. */
    private function verifySignature(string $payload, string $signature, string $publicKey): bool
    {
        if (function_exists('sodium_crypto_sign_verify_detached')) {
            try {
                return sodium_crypto_sign_verify_detached($signature, $payload, $publicKey);
            } catch (Throwable) {
                return false;
            }
        }

        if (function_exists('openssl_verify')) {
            if (! defined('OPENSSL_ALGO_ED25519')) {
                return false;
            }

            return openssl_verify($payload, $signature, $publicKey, (int) constant('OPENSSL_ALGO_ED25519')) === 1;
        }

        Log::error('No Ed25519 implementation available (sodium/openssl)');

        return false;
    }

    /**
     * Pin the core's public key: explicit config wins, otherwise it is fetched
     * once from the core and cached in `Setting`.
     */
    private function corePublicKey(): ?string
    {
        $configured = (string) config('license.core_public_key', '');
        if ($configured !== '') {
            $pinned = base64_decode($configured, true);

            return is_string($pinned) && $pinned !== '' ? $pinned : null;
        }

        $pinned = Setting::get('license.core_public_key');
        if (is_string($pinned) && $pinned !== '') {
            $decoded = base64_decode($pinned, true);

            if (is_string($decoded) && $decoded !== '') {
                return $decoded;
            }
        }

        $response = $this->core->entitlementPublicKey();
        $key = $response['ok'] ? ($response['data']['public_key'] ?? null) : null;
        if (! is_string($key) || $key === '') {
            return null;
        }

        Setting::put('license.core_public_key', $key);

        $decoded = base64_decode($key, true);

        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    private function ttl(): int
    {
        return max(5, (int) config('license.ticket_cache_seconds', 60));
    }

    /**
     * @param  array<int, string>  $features
     * @param  array<int, string>  $modules
     * @return array{state: string, access: string, features: array<int, string>, modules: array<int, string>, expires_at: string|null, fingerprint: string|null, clock_tamper: bool, source: string}
     */
    private function buildState(
        string $state,
        string $access,
        array $features = [],
        array $modules = [],
        ?string $expiresAt = null,
        string $source = 'core',
    ): array {
        return [
            'state' => $state,
            'access' => $access,
            'features' => $features,
            'modules' => $modules,
            'expires_at' => $expiresAt,
            'fingerprint' => null,
            'clock_tamper' => false,
            'source' => $source,
        ];
    }

    /** @return array<int, string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, 'is_string'));
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }
}
