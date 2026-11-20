<?php

namespace App\Services\Storage;

/**
 * HMAC-signed short-lived tokens for public share links (unlock cookies and
 * anonymous access links). Deliberately separate from the ONLYOFFICE secret.
 */
class StorageShareToken
{
    public function sign(int $shareId, ?int $entryId, int $ttl, string $purpose = 'access'): string
    {
        $payload = $this->base64UrlEncode((string) json_encode([
            'purpose' => $purpose,
            'share' => $shareId,
            'entry' => $entryId,
            'exp' => time() + max(60, $ttl),
        ]));

        return $payload . '.' . $this->signature($payload);
    }

    /**
     * @return array{purpose:string,share:int,entry:?int,exp:int}|null
     */
    public function verify(string $token, ?int $shareId = null, ?string $purpose = null): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$payload, $signature] = $parts;

        if (!hash_equals($this->signature($payload), $signature)) {
            return null;
        }

        $decoded = json_decode((string) $this->base64UrlDecode($payload), true);
        if (!is_array($decoded)) {
            return null;
        }

        if (($decoded['exp'] ?? 0) < time()) {
            return null;
        }

        if ($shareId !== null && (int) ($decoded['share'] ?? 0) !== $shareId) {
            return null;
        }

        if ($purpose !== null && ($decoded['purpose'] ?? '') !== $purpose) {
            return null;
        }

        return $decoded;
    }

    private function signature(string $payload): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->secret(), true));
    }

    private function secret(): string
    {
        $key = (string) config('app.key');
        if (str_starts_with($key, 'base64:')) {
            $key = (string) base64_decode(substr($key, 7));
        }

        return $key . '|storage-share';
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }
}
