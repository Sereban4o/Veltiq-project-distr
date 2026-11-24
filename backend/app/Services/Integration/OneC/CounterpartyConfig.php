<?php

namespace App\Services\Integration\OneC;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Effective configuration of the 1С CounterpartiesExchange SOAP service.
 *
 * Values are resolved from the `settings` table (configured in the admin panel)
 * and fall back to the `one_c.counterparties.*` env defaults.
 */
final class CounterpartyConfig
{
    public const CACHE_KEY = 'one_c.counterparty_config';

    private const CACHE_TTL = 300;

    private function __construct(
        private ?string $url,
        private ?string $login,
        private ?string $password,
        private bool $verify,
    ) {
    }

    public static function fromSettings(): self
    {
        return Cache::remember(self::CACHE_KEY, self::CACHE_TTL, static fn (): self => self::resolve());
    }

    /** Invalidate the cached configuration (call after admin changes the settings). */
    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    private static function resolve(): self
    {
        $verify = Setting::get('one_c_counterparties_verify');

        return new self(
            self::nonEmpty(Setting::get('one_c_counterparties_url')) ?? self::configString('url'),
            self::nonEmpty(Setting::get('one_c_counterparties_login')) ?? self::configString('login'),
            self::nonEmpty(Setting::getEncrypted('one_c_counterparties_password')) ?? self::configString('password'),
            $verify !== null ? $verify === '1' : (bool) config('one_c.counterparties.verify', true),
        );
    }

    public function url(): ?string
    {
        return $this->url;
    }

    public function login(): string
    {
        return (string) ($this->login ?? '');
    }

    public function password(): string
    {
        return (string) ($this->password ?? '');
    }

    public function verify(): bool
    {
        return $this->verify;
    }

    public function namespace(): string
    {
        return (string) config('one_c.counterparties.namespace', 'urn:gertorg:exchange:counterparties');
    }

    public function portion(): int
    {
        return max(1, (int) config('one_c.counterparties.portion', 500));
    }

    public function maxPages(): int
    {
        return max(1, (int) config('one_c.counterparties.max_pages', 100));
    }

    public function timeout(): int
    {
        return (int) config('one_c.counterparties.timeout', 60);
    }

    public function configured(): bool
    {
        return (bool) config('one_c.counterparties.enabled', true)
            && $this->url !== null
            && trim($this->url) !== '';
    }

    private static function configString(string $key): ?string
    {
        return self::nonEmpty(config("one_c.counterparties.{$key}"));
    }

    private static function nonEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
