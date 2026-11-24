<?php

namespace App\Services\Extensions;

use RuntimeException;

/**
 * Detached Ed25519 signature for a extension manifest.
 *
 * The manifest carries an optional `signature` object:
 *
 *   "signature": { "kid": "acme", "payload": "<base64 canonical manifest>", "sig": "<base64>" }
 *
 * `payload` is the authoritative manifest once verified, so a signed module
 * cannot be edited in place — the signature would stop matching. `kid` selects
 * one of the trusted author keys; when absent, all trusted keys are tried (the
 * vendor key is always trusted).
 */
final class ExtensionSignature
{
    /** Key id under which the vendor key is always trusted. */
    public const VENDOR_KEY_ID = 'vendor';

    /**
     * Canonical form: the manifest without the `signature` key, JSON encoded
     * with sorted keys and no pretty printing. `tools/sign_module.php` uses the
     * same routine, so signatures always match.
     *
     * @param  array<string, mixed>  $manifest
     */
    public static function canonical(array $manifest): string
    {
        unset($manifest['signature']);
        $manifest = self::ksortRecursive($manifest);

        return (string) json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Seal a manifest (vendor tooling / tests).
     *
     * @param  array<string, mixed>  $manifest
     * @return array<string, mixed>
     */
    public static function seal(array $manifest, string $privateKeyRaw, string $kid = ''): array
    {
        $payload = self::canonical($manifest);
        $signature = sodium_crypto_sign_detached($payload, $privateKeyRaw);

        $manifest['signature'] = array_filter([
            'kid' => $kid !== '' ? $kid : null,
            'payload' => base64_encode($payload),
            'sig' => base64_encode($signature),
        ], static fn ($value): bool => $value !== null);

        return $manifest;
    }

    /**
     * Verify and return the authoritative manifest.
     *
     * @param  array<string, mixed>  $raw
     * @param  array<string, string>|string  $publicKeys  map kid => raw key, or a single raw key (treated as the vendor key)
     * @return array<string, mixed>
     *
     * @throws RuntimeException on a malformed or invalid signature
     */
    public static function verify(array $raw, array|string $publicKeys): array
    {
        $keys = is_string($publicKeys) ? [self::VENDOR_KEY_ID => $publicKeys] : $publicKeys;

        $envelope = $raw['signature'] ?? null;
        if (! is_array($envelope)) {
            throw new RuntimeException('manifest has no signature');
        }

        $payload = base64_decode((string) ($envelope['payload'] ?? ''), true);
        $signature = base64_decode((string) ($envelope['sig'] ?? ''), true);

        if ($payload === false || $signature === false || $payload === '' || $signature === '') {
            throw new RuntimeException('manifest signature is malformed');
        }

        $kid = (string) ($envelope['kid'] ?? '');
        $candidates = self::selectKeys($keys, $kid);

        foreach ($candidates as $keyId => $publicKey) {
            if (self::verifyDetached($payload, $signature, $publicKey)) {
                $decoded = json_decode($payload, true);
                if (! is_array($decoded)) {
                    throw new RuntimeException('signed manifest payload is not an object');
                }

                // The signer is reported to the operator and written to the audit.
                $decoded['_signer'] = $keyId;

                return $decoded;
            }
        }

        throw new RuntimeException($kid === ''
            ? 'manifest signature does not match any trusted key'
            : "manifest signature does not match trusted key \"{$kid}\"");
    }

    /**
     * Parse `kid=base64key,kid2=base64key2` into raw keys, adding the vendor key.
     *
     * @return array<string, string>
     */
    public static function trustedKeys(string $vendorPublicKeyBase64, string $trustedList): array
    {
        $keys = [];

        $vendor = base64_decode(trim($vendorPublicKeyBase64), true);
        if (is_string($vendor) && $vendor !== '') {
            $keys[self::VENDOR_KEY_ID] = $vendor;
        }

        foreach (explode(',', $trustedList) as $pair) {
            $pair = trim($pair);
            if ($pair === '' || ! str_contains($pair, '=')) {
                continue;
            }

            [$kid, $encoded] = explode('=', $pair, 2);
            $kid = trim($kid);
            $raw = base64_decode(trim($encoded), true);

            if ($kid === '' || ! is_string($raw) || $raw === '') {
                continue;
            }

            $keys[$kid] = $raw;
        }

        return $keys;
    }

    /** Human-readable fingerprint of a raw public key (for the admin UI). */
    public static function fingerprint(string $publicKeyRaw): string
    {
        return 'sk_'.substr(hash('sha256', $publicKeyRaw), 0, 12);
    }

    public static function verifyDetached(string $payload, string $signature, string $publicKeyRaw): bool
    {
        if (function_exists('sodium_crypto_sign_verify_detached')) {
            try {
                return sodium_crypto_sign_verify_detached($signature, $payload, $publicKeyRaw);
            } catch (\Throwable) {
                return false;
            }
        }

        if (function_exists('openssl_verify')) {
            return openssl_verify($payload, $signature, $publicKeyRaw, OPENSSL_ALGO_ED25519) === 1;
        }

        return false;
    }

    /**
     * @param  array<string, string>  $keys
     * @return array<string, string>
     *
     * @throws RuntimeException when an explicit key id is not trusted
     */
    private static function selectKeys(array $keys, string $kid): array
    {
        if ($kid === '') {
            if ($keys === []) {
                throw new RuntimeException('no trusted signing keys configured');
            }

            return $keys;
        }

        if (! isset($keys[$kid])) {
            throw new RuntimeException("unknown signing key \"{$kid}\"");
        }

        return [$kid => $keys[$kid]];
    }

    /**
     * @param  array<string, mixed>  $value
     * @return array<string, mixed>
     */
    private static function ksortRecursive(array $value): array
    {
        ksort($value);

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $isList = array_is_list($item);
                $value[$key] = self::ksortRecursive($item);

                if ($isList) {
                    $value[$key] = array_values($value[$key]);
                }
            }
        }

        return $value;
    }
}
