<?php

namespace App\Extensions\Storage\Services;

use App\Models\Setting;
use InvalidArgumentException;

/**
 * Effective file-storage configuration resolved from settings / config,
 * or from raw admin form input (for "test connection" without persisting).
 */
final class StorageConfig
{
    private function __construct(
        private ?string $address,
        private ?string $username,
        private ?string $password,
        private ?string $domain,
        private ?string $legacyPath,
        private int $connectTimeout,
        private int $readTimeout,
        private bool $verifyTls,
        private ?string $caBundle,
    ) {
    }

    public static function fromSettings(): self
    {
        $connectTimeout = Setting::get('storage_connect_timeout');
        $readTimeout = Setting::get('storage_read_timeout');
        $verifyTls = Setting::get('storage_verify_tls');

        return new self(
            Setting::get('storage_address') ?: config('storage_browser.address'),
            Setting::get('storage_username') ?: config('storage_browser.username'),
            Setting::getEncrypted('storage_password') ?: config('storage_browser.password'),
            Setting::get('storage_domain') ?: config('storage_browser.domain'),
            Setting::get('storage_path') ?: config('storage_browser.path'),
            $connectTimeout !== null ? (int) $connectTimeout : (int) config('storage_browser.connect_timeout', 10),
            $readTimeout !== null ? (int) $readTimeout : (int) config('storage_browser.read_timeout', 60),
            $verifyTls !== null ? $verifyTls === '1' : (bool) config('storage_browser.verify_tls', true),
            config('storage_browser.ca_bundle'),
        );
    }

    /**
     * Build a config from admin form input, falling back to the given stored
     * config for blank/absent values (e.g. password left blank to keep it).
     *
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input, ?self $stored = null): self
    {
        $stored ??= self::fromSettings();

        $password = trim((string) ($input['password'] ?? '')) !== ''
            ? (string) $input['password']
            : $stored->password();

        return new self(
            trim((string) ($input['address'] ?? '')) ?: null,
            trim((string) ($input['username'] ?? '')) ?: null,
            $password,
            trim((string) ($input['domain'] ?? '')) ?: null,
            $stored->legacyPath(),
            (int) ($input['connect_timeout'] ?? $stored->connectTimeout()),
            (int) ($input['read_timeout'] ?? $stored->readTimeout()),
            (bool) ($input['verify_tls'] ?? $stored->verifyTls()),
            $stored->caBundle(),
        );
    }

    public function address(): ?StorageAddress
    {
        if ($this->address === null || trim($this->address) === '') {
            return null;
        }

        try {
            return StorageAddress::parse($this->address);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    public function rawAddress(): ?string
    {
        return $this->address;
    }

    public function username(): ?string
    {
        return $this->username;
    }

    public function password(): ?string
    {
        return $this->password;
    }

    public function domain(): ?string
    {
        return $this->domain;
    }

    public function legacyPath(): ?string
    {
        return $this->legacyPath;
    }

    public function connectTimeout(): int
    {
        return $this->connectTimeout;
    }

    public function readTimeout(): int
    {
        return $this->readTimeout;
    }

    public function verifyTls(): bool
    {
        return $this->verifyTls;
    }

    public function caBundle(): ?string
    {
        return $this->caBundle;
    }

    public function isConfigured(): bool
    {
        if ($this->address() !== null) {
            return true;
        }

        return $this->legacyPath !== null && $this->legacyPath !== '' && is_dir($this->legacyPath);
    }
}
