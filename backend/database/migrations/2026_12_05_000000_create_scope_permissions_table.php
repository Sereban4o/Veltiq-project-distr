<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        Schema::create('scope_permissions', function (Blueprint $table) {
            $table->id();
            $table->enum('scope_type', ['global', 'project', 'space']);
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->enum('target_type', ['role', 'user', 'group']);
            $table->string('target_value');
            $table->enum('effect', ['allow', 'deny'])->default('allow');
            $table->string('permission');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['scope_type', 'scope_id']);
            $table->index(['target_type', 'target_value']);
            $table->index('permission');
        });

        // Global rows use scope_id = NULL, and SQL engines treat NULLs as distinct
        // in unique indexes, so enforce uniqueness with a COALESCE expression index.
        $driver = DB::connection()->getDriverName();
        if (in_array($driver, ['pgsql', 'sqlite'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX scope_permissions_unique ON scope_permissions '
                . '(scope_type, COALESCE(scope_id, 0), target_type, target_value, permission)',
            );
        } else {
            DB::statement(
                'ALTER TABLE scope_permissions ADD CONSTRAINT scope_permissions_unique '
                . 'UNIQUE (scope_type, scope_id, target_type, target_value, permission)',
            );
        }

        // Backfill the legacy space_permissions rows into the unified table.
        if (Schema::hasTable('space_permissions')) {
            DB::statement(
                'INSERT INTO scope_permissions '
                . '(scope_type, scope_id, target_type, target_value, effect, permission, created_at, updated_at) '
                . 'SELECT \'space\', space_id, target_type, target_value, \'allow\', permission, now(), now() '
                . 'FROM space_permissions',
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scope_permissions');
    }
};
