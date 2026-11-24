<?php

namespace App\Services\Extensions;

use App\Services\Core\CoreClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Discovers extension manifests and mirrors their state to/from the compiled core.
 *
 * The core is the authority on what is allowed (license) and what is enabled;
 * this registry only reads the filesystem, validates manifests and caches the
 * core's answer so the request path never blocks on the core.
 */
class ExtensionRegistry
{
    private const ENABLED_CACHE_KEY = 'extensions:enabled';

    private const CACHE_SECONDS = 60;

    public function __construct(private readonly CoreClient $core)
    {
    }

    /** Root directory that holds vendor extensions (inside the image). */
    public function vendorPath(): string
    {
        return base_path('extensions');
    }

    /** Root directory for third-party extensions (a writable volume). */
    public function thirdPartyPath(): string
    {
        return (string) (config('extensions.third_party_path') ?: storage_path('extensions'));
    }

    /**
     * All discoverable manifests keyed by id.
     *
     * @return array<string, ExtensionManifest>
     */
    public function discover(): array
    {
        $manifests = [];

        foreach ([$this->vendorPath(), $this->thirdPartyPath()] as $root) {
            if (! is_dir($root)) {
                continue;
            }

            foreach (glob($root . '/*/extension.json') ?: [] as $file) {
                try {
                    $raw = json_decode((string) file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
                    $manifest = ExtensionManifest::fromArray((array) $raw, dirname($file));
                    $manifests[$manifest->id] = $manifest;
                } catch (Throwable $e) {
                    Log::warning('Extension manifest is invalid', ['file' => $file, 'error' => $e->getMessage()]);
                }
            }

            // Backwards compatibility: extensions shipped before the rename used
            // `module.json`; honour them for one release.
            foreach (glob($root . '/*/module.json') ?: [] as $legacy) {
                $id = basename(dirname($legacy));
                if (isset($manifests[$id])) {
                    continue;
                }

                try {
                    $raw = json_decode((string) file_get_contents($legacy), true, 512, JSON_THROW_ON_ERROR);
                    $manifest = ExtensionManifest::fromArray((array) $raw, dirname($legacy));
                    $manifests[$manifest->id] = $manifest;
                } catch (Throwable $e) {
                    Log::warning('Legacy extension manifest is invalid', ['file' => $legacy, 'error' => $e->getMessage()]);
                }
            }
        }

        return $manifests;
    }

    /**
     * Enabled extension ids as decided by the core (cached).
     *
     * @return array<int, string>
     */
    public function enabledIds(): array
    {
        return $this->enabledIdsOrNull() ?? [];
    }

    /**
     * Enabled ids, or null when the core cannot be reached and nothing is cached.
     *
     * @return array<int, string>|null
     */
    public function enabledIdsOrNull(): ?array
    {
        $cached = Cache::get(self::ENABLED_CACHE_KEY);
        if (is_array($cached)) {
            return array_values(array_filter($cached, 'is_string'));
        }

        return $this->refresh();
    }

    /**
     * Fail-open: a extension is considered available unless the core explicitly says
     * it is disabled. A core outage must not take the whole product down.
     */
    public function isEnabled(string $id): bool
    {
        $ids = $this->enabledIdsOrNull();

        return $ids === null || in_array($id, $ids, true);
    }

    /**
     * Enabled ids from the cache only — safe to call during application boot
     * (never talks to the core).
     *
     * @return array<int, string>
     */
    public function cachedEnabledIds(): array
    {
        $cached = Cache::get(self::ENABLED_CACHE_KEY);

        return is_array($cached) ? array_values(array_filter($cached, 'is_string')) : [];
    }

    /**
     * Register PSR-4 autoloading for a manifest. Extensions are not composer
     * packages, so the customer never runs `composer dump-autoload`.
     */
    public function registerAutoload(ExtensionManifest $manifest): void
    {
        foreach ($manifest->psr4 as $prefix => $dir) {
            $base = $manifest->path . '/' . trim((string) $dir, '/');

            spl_autoload_register(static function (string $class) use ($prefix, $base): void {
                if (! str_starts_with($class, $prefix)) {
                    return;
                }

                $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
                $file = $base . '/' . $relative . '.php';

                if (is_file($file)) {
                    require $file;
                }
            });
        }
    }

    /**
     * Mount extension providers: register autoloading and `register()` each one.
     * `null` mounts every discovered extension (licensing not enforced); an array
     * mounts exactly those ids.
     *
     * @param  \Illuminate\Contracts\Foundation\Application  $app
     * @param  array<int, string>|null  $ids
     * @return array<int, string> ids actually mounted
     */
    public function mount(\Illuminate\Contracts\Foundation\Application $app, ?array $ids = null): array
    {
        $mounted = [];

        foreach ($this->discover() as $id => $manifest) {
            if ($ids !== null && ! in_array($id, $ids, true)) {
                continue;
            }

            $this->registerAutoload($manifest);

            if (! class_exists($manifest->provider)) {
                Log::warning('Extension provider class is missing', ['module' => $id, 'provider' => $manifest->provider]);

                continue;
            }

            $app->register($manifest->provider);
            $mounted[] = $id;
        }

        return $mounted;
    }

    /**
     * Push the discovered manifests to the core and cache the core's verdict.
     *
     * @return array<int, string>|null null when the core is unreachable
     */
    public function refresh(): ?array
    {
        $payload = [];

        foreach ($this->discover() as $manifest) {
            $payload[] = $manifest->toCorePayload();
        }

        $response = $this->core->syncExtensions($payload);
        if (! $response['ok']) {
            // The core is the authority; without it we do not guess. Existing
            // callers fall back to the previous cache or fail open.
            return null;
        }

        $enabled = array_values(array_filter((array) ($response['data']['enabled'] ?? []), 'is_string'));
        Cache::put(self::ENABLED_CACHE_KEY, $enabled, self::CACHE_SECONDS);

        return $enabled;
    }

    /**
     * Rich extension list for the admin UI and the SPA: manifest + verdict.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        // Cache-first: when the core is down we still know what was enabled a
        // moment ago, and we must not turn a control-plane outage into mass
        // disabling of extensions.
        $knownIds = $this->enabledIdsOrNull();
        $verdicts = $this->verdicts();

        $rows = [];
        foreach ($this->discover() as $id => $manifest) {
            $verdict = $verdicts[$id] ?? [];
            $hasVerdict = $verdict !== [];

            $rows[] = [
                'id' => $id,
                'name' => $manifest->name,
                'version' => $manifest->version,
                'vendor' => $manifest->vendor,
                'tier' => $manifest->tier,
                'entitlement' => $manifest->entitlement,
                'uninstall' => $manifest->uninstall,
                'permissions' => $manifest->permissions,
                'menu' => $manifest->menu,
                'allowed' => $hasVerdict ? (bool) ($verdict['allowed'] ?? false) : true,
                'enabled' => $hasVerdict
                    ? (bool) ($verdict['enabled'] ?? false)
                    : in_array($id, $knownIds ?? [], true),
                'reason' => (string) ($verdict['reason'] ?? ''),
                'known' => $hasVerdict || $knownIds !== null,
            ];
        }

        usort($rows, static fn (array $a, array $b) => $a['id'] <=> $b['id']);

        return $rows;
    }

    /**
     * Whether the extension state is authoritative (the core answered or the cache
     * is warm). `false` means "unknown" and callers must fail open.
     */
    public function isKnown(): bool
    {
        return $this->enabledIdsOrNull() !== null;
    }

    /**
     * Raw verdicts from the core keyed by extension id.
     *
     * @return array<string, array<string, mixed>>
     */
    public function verdicts(): array
    {
        $response = $this->core->extensions();
        if (! $response['ok']) {
            return [];
        }

        $verdicts = [];
        foreach ((array) ($response['data']['modules'] ?? []) as $module) {
            if (is_array($module) && isset($module['id'])) {
                $verdicts[(string) $module['id']] = $module;
            }
        }

        return $verdicts;
    }

    /** Enable/disable through the core (the authority), then refresh the cache. */
    public function setEnabled(string $id, bool $enabled): bool
    {
        $response = $this->core->setExtension($id, $enabled);
        if (! $response['ok']) {
            return false;
        }

        Cache::forget(self::ENABLED_CACHE_KEY);

        return true;
    }
}
