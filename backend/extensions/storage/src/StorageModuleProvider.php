<?php

namespace App\Extensions\Storage;

use App\Extensions\ExtensionServiceProvider;
use App\Extensions\Storage\Console\StoragePreviews;

/**
 * File storage module: browser API, uploads, versions, trash, shares,
 * ONLYOFFICE integration and the public file view.
 *
 * Data models (File, Storage*) intentionally stay in App\Models: they are the
 * shared attachment contract used by mail, chat, KB, CRM and avatars.
 */
class StorageModuleProvider extends ExtensionServiceProvider
{
    public function register(): void
    {
        $this->commands([
            StoragePreviews::class,
        ]);
    }
}