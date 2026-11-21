<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

return new class() extends Migration {
    public function up(): void
    {
        // Branding presets ("skins") were removed in favour of a single runtime
        // branding configuration. Drop the table plus its uploaded assets.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS skins_active_unique');
        }

        Schema::dropIfExists('skins');

        Storage::disk('public')->deleteDirectory('skins');
    }

    public function down(): void
    {
        // Nothing to restore: the feature is gone.
    }
};
