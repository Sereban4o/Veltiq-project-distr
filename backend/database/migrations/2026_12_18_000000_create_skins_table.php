<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration {
    public function up(): void
    {
        // Named branding presets ("skins") a customer can switch between without
        // rebuilding the compiled web client. The active skin's config is copied
        // into the runtime branding setting (see BrandingService).
        Schema::create('skins', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->text('description')->nullable();
            $table->json('config');
            $table->string('logo_path', 2048)->nullable();
            $table->string('favicon_path', 2048)->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skins');
    }
};
