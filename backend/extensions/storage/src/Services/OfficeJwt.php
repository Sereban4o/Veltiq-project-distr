<?php

namespace App\Extensions\Storage\Services;

use RuntimeException;

/**
 * Minimal HS256 JWT implementation for ONLYOFFICE.
 *
 * The Document Server validates the editor config token with the shared
 * secret, and our download/callback links carry a token signed the same way.
 */
class OfficeJwt
{
    public function __construct(
        private ?string $secret = null,
    ) {
        $this->secret ??= (string) config('onlyoffice.jwt_secret');
    }

    public function isConfigured(): bool
    {
        return (string) $this->secret !== '';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function encode(array $payload): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('ONLYOFFICE_JWT_SECRET не задан');
        }

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $segments = $this->base64UrlEncode($this->json($header))
            . '.' . $this->base64UrlEncode($this->json($payload));

        $signature = hash_hmac('sha256', $segments, (string) $this->secret, true);

        return $segments . '.' . $this->base64UrlEncode($signature);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function decode(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3 || !$this->isConfigured()) {
            return null;
        }

        [$header, $payload, $signature] = $parts;

        $expected = $this->base64UrlEncode(
            hash_hmac('sha256', $header . '.' . $payload, (string) $this->secret, true),
        );

        if (!hash_equals($expected, $signature)) {
            return null;
        }

        $data = json_decode($this->base64UrlDecode($payload), true);
        if (!is_array($data)) {
            return null;
        }

        if (isset($data['exp']) && (int) $data['exp'] < time()) {
            return null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function signWithTtl(array $payload, ?int $ttl = null): string
    {
        $ttl ??= (int) config('onlyoffice.token_ttl', 86400);
        $payload['iat'] = $payload['iat'] ?? time();
        $payload['exp'] = $payload['exp'] ?? (time() + max(60, $ttl));

        return $this->encode($payload);
    }

    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padded = str_pad(strtr($value, '-_', '+/'), (int) (ceil(strlen($value) / 4) * 4), '=');

        return (string) base64_decode($padded, true);
    }
}
