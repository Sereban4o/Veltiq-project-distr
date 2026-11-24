<?php

namespace App\Extensions\Storage\Services;

use App\Models\Setting;

/**
 * Admin-tunable ONLYOFFICE editor settings. Values stored in the `settings`
 * table override the code defaults at runtime.
 */
class OfficeSettings
{
    public const KEY_AUTOSAVE = 'office_autosave_enabled';

    /** Whether the editor saves the document automatically while typing. */
    public function autosaveEnabled(): bool
    {
        $value = Setting::get(self::KEY_AUTOSAVE);

        if ($value === null) {
            return true;
        }

        return $value === '1' || $value === 'true';
    }

    public function putAutosave(bool $enabled): void
    {
        Setting::put(self::KEY_AUTOSAVE, $enabled ? '1' : '0');
    }
}
