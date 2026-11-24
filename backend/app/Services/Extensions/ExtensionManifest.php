<?php

namespace App\Services\Extensions;

use InvalidArgumentException;

/**
 * Validated `module.json` manifest (docs/11-extensions.md).
 *
 * A manifest is the whole contract between a extension and the platform: identity,
 * compatibility, entitlement, what it contributes and how it is uninstalled.
 */
final class ExtensionManifest
{
    /**
     * @param  array<int, string>  $permissions
     * @param  array<int, array<string, mixed>>  $menu
     * @param  array<string, string>  $psr4
     */
    private function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $version,
        public readonly string $vendor,
        public readonly string $tier,
        public readonly string $provider,
        public readonly ?string $entitlement,
        public readonly array $psr4,
        public readonly ?string $migrations,
        public readonly ?string $routes,
        public readonly array $permissions,
        public readonly array $menu,
        public readonly string $uninstall,
        public readonly string $path,
        public readonly bool $enabled,
    ) {
    }

    /**
     * @param  array<string, mixed>  $raw
     *
     * @throws InvalidArgumentException when the manifest is not usable
     */
    public static function fromArray(array $raw, string $path): self
    {
        $id = trim((string) ($raw['id'] ?? ''));

        if ($id === '' || ! preg_match('/^[a-z0-9][a-z0-9._-]*$/', $id)) {
            throw new InvalidArgumentException('manifest: invalid id');
        }

        $version = trim((string) ($raw['version'] ?? ''));
        if ($version === '') {
            throw new InvalidArgumentException("manifest {$id}: version is required");
        }

        $tier = (string) ($raw['tier'] ?? 'third_party');
        if (! in_array($tier, ['vendor', 'third_party'], true)) {
            throw new InvalidArgumentException("manifest {$id}: invalid tier {$tier}");
        }

        $provider = trim((string) ($raw['provider'] ?? ''));
        if ($provider === '' || ! str_contains($provider, '\\')) {
            throw new InvalidArgumentException("manifest {$id}: provider must be a PSR-4 class");
        }

        $psr4 = [];
        foreach ((array) ($raw['psr4'] ?? []) as $prefix => $dir) {
            if (is_string($prefix) && is_string($dir) && $prefix !== '' && $dir !== '') {
                $psr4[$prefix] = $dir;
            }
        }

        if ($psr4 === []) {
            throw new InvalidArgumentException("manifest {$id}: psr4 map is required");
        }

        $permissions = array_values(array_filter((array) ($raw['permissions'] ?? []), 'is_string'));
        $menu = array_values(array_filter((array) ($raw['menu'] ?? []), 'is_array'));

        $uninstall = (string) ($raw['uninstall'] ?? 'retain');
        if (! in_array($uninstall, ['retain', 'purge'], true)) {
            throw new InvalidArgumentException("manifest {$id}: uninstall must be retain or purge");
        }

        return new self(
            id: $id,
            name: (string) ($raw['name'] ?? $id),
            version: $version,
            vendor: (string) ($raw['vendor'] ?? ''),
            tier: $tier,
            provider: $provider,
            entitlement: ($raw['entitlement'] ?? null) !== null ? (string) $raw['entitlement'] : null,
            psr4: $psr4,
            migrations: ($raw['migrations'] ?? null) !== null ? (string) $raw['migrations'] : null,
            routes: ($raw['routes'] ?? null) !== null ? (string) $raw['routes'] : null,
            permissions: $permissions,
            menu: $menu,
            uninstall: $uninstall,
            path: $path,
            enabled: (bool) ($raw['enabled'] ?? false),
        );
    }

    /**
     * Payload sent to the core registry (GET/POST /v1/extensions).
     *
     * @return array<string, mixed>
     */
    /**
     * Payload sent to the core registry (POST /v1/extensions/sync).
     *
     * `enabled` is deliberately left false: the current state is owned by the
     * core (changed through the admin toggle). Only `default_enabled` — the
     * manifest's own default — is applied, and only the first time the core sees
     * the module.
     *
     * @return array<string, mixed>
     */
    public function toCorePayload(): array
    {
        return [
            'id' => $this->id,
            'tier' => $this->tier,
            'entitlement' => $this->entitlement ?? '',
            'default_enabled' => $this->enabled,
        ];
    }
}
