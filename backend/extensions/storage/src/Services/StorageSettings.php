<?php

namespace App\Extensions\Storage\Services;

use App\Models\Setting;

/**
 * Admin-tunable storage settings: values stored in the `settings` table
 * override the `storage_browser` config defaults at runtime.
 */
class StorageSettings
{
    /** Keys that the admin API may read and write. */
    public const TUNABLE = [
        'default_quota_bytes',
        'version_max_per_file',
        'version_retention_days',
        'trash_retention_days',
        'audit_retention_days',
        'previews_enabled',
    ];

    public function int(string $key, ?int $default = null): int
    {
        $value = Setting::get($this->settingKey($key));

        if ($value !== null) {
            return (int) $value;
        }

        return $default ?? (int) config('storage_browser.' . $key, 0);
    }

    public function bool(string $key, ?bool $default = null): bool
    {
        $value = Setting::get($this->settingKey($key));

        if ($value !== null) {
            return $value === '1' || $value === 'true';
        }

        return $default ?? (bool) config('storage_browser.' . $key, false);
    }

    public function put(string $key, mixed $value): void
    {
        if (!in_array($key, self::TUNABLE, true)) {
            throw new \InvalidArgumentException('Неизвестная настройка хранилища: ' . $key);
        }

        Setting::put($this->settingKey($key), $value === null ? null : (string) $value);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return [
            'default_quota_bytes' => $this->int('default_quota_bytes'),
            'version_max_per_file' => $this->int('version_max_per_file', (int) config('storage_browser.version_max_per_file', 20)),
            'version_retention_days' => $this->int('version_retention_days', (int) config('storage_browser.version_retention_days', 30)),
            'trash_retention_days' => $this->int('trash_retention_days', (int) config('storage_browser.trash_retention_days', 30)),
            'audit_retention_days' => $this->int('audit_retention_days', (int) config('storage_browser.audit_retention_days', 180)),
            'previews_enabled' => $this->bool('previews_enabled', true),
        ];
    }

    private function settingKey(string $key): string
    {
        return 'storage_' . $key;
    }
}
