<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        // Some installs already created this table with an earlier preview of
        // the feature; keep their data instead of failing the migration.
        if (Schema::hasTable('system_update_logs')) {
            return;
        }

        Schema::create('system_update_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('from_version', 40)->nullable();
            $table->string('to_version', 40)->nullable();
            $table->string('status', 16)->default('pending');
            $table->text('message')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_update_logs');
    }
};
